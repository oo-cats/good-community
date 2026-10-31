<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;

use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Outbox};
use Local\GoodCommunity\Facts\{Compare,DependencyGraph,Evidence,FactEngine,QueryEngine};
use Local\GoodCommunity\Currency\Ledger;

final class TaskService
{
    public ?\Closure $afterSettlement = null; // Trusted fault-injection/extension hook, never request data.
    public function __construct(private Db $db, private FactEngine $facts, private QueryEngine $queries, private Ledger $ledger, private Outbox $outbox, private DependencyGraph $graph, private ?\Closure $clock = null, private ?\Local\GoodCommunity\Access\Rewards $access=null) {}
    private function now(): string { return $this->clock ? ($this->clock)() : Data::now(); }
    public function find(string $id): array
    {
        $task=$this->db->one('SELECT * FROM lgc_tasks WHERE public_id=?',[Data::bin($id)]);
        if (!$task) throw new DomainError('task_not_found',404);
        return $task;
    }
    public function visible(Actor $actor, array $task, ?array $version=null): bool
    {
        if ($actor->can('tasks.manage')) return true;
        if ($task['status']==='draft' || !$task['latest_version']) return false;
        if ($version===null) {
            $seconds=(int)$task['period_seconds']; $elapsed=max(0,strtotime($this->now().' UTC')-strtotime($task['start_at'].' UTC')); $occurrence=$seconds ? intdiv($elapsed,$seconds) : 0;
            $version=$this->db->one("SELECT audience FROM lgc_task_versions WHERE task_id=? AND state='published' AND effective_from_occurrence<=? ORDER BY effective_from_occurrence DESC,version DESC LIMIT 1",[$task['id'],$occurrence]);
        }
        if (!$version) return false;
        $audience=Data::decode($version['audience']);
        return ($audience['kind'] ?? '')==='registered' || (bool)array_intersect($actor->groups,array_map('intval',$audience['group_ids'] ?? []));
    }
    public function save(Actor $actor, array $input, ?string $id=null): array
    {
        $actor->assert('tasks.manage');
        return $this->db->transaction(function () use ($actor,$input,$id) {
            $this->db->one('SELECT id FROM lgc_folders WHERE id=1 FOR UPDATE');
            $old=$id ? $this->find($id) : null;
            if ($old && (int)($input['expected_version'] ?? 0)!==(int)$old['row_version']) throw new DomainError('version_conflict',409);
            $name=Data::name($input['name'] ?? $old['name'] ?? ''); $comment=(string)($input['comment'] ?? $old['comment'] ?? '');
            if (mb_strlen($comment)>1000) throw new DomainError('comment_too_long');
            $start=Data::date($input['start_at'] ?? $old['start_at'] ?? $this->now());
            $stopInput=array_key_exists('stop_at',$input) ? $input['stop_at'] : ($old['stop_at'] ?? null);
            $stop=is_numeric($stopInput) && (float)$stopInput<0 ? null : Data::date($stopInput);
            $period=(int)Data::integer($input['period'] ?? (string)($old['period_seconds'] ?? 0),false,10);
            if (!$start || ($stop && $stop<=$start) || $period>4294967295) throw new DomainError('invalid_schedule');
            if ($old && $old['published_at'] && [$start,$stop,$period]!==[$old['start_at'],$old['stop_at'],(int)$old['period_seconds']]) throw new DomainError('published_schedule_immutable',409);
            if ($old && $old['status']==='closed') throw new DomainError('task_closed',409);
            $folder=1;
            if (!empty($input['folder_id'])) $folder=$this->db->scalar('SELECT id FROM lgc_folders WHERE public_id=?',[Data::bin($input['folder_id'])]) ?: throw new DomainError('folder_not_found');
            elseif ($old && !array_key_exists('folder_id',$input)) $folder=$old['folder_id'] ?? 1;
            $associate=array_key_exists('associate_post',$input) ? ($input['associate_post']!==null && $input['associate_post']!=='' ? (int)$input['associate_post'] : null) : ($old['associate_post_id'] ?? null);
            if ($associate && !$this->db->scalar('SELECT id FROM '.$this->db->native('posts').' WHERE id=?',[$associate])) throw new DomainError('associated_post_not_found');
            $uuid=$id ?? Data::uuid();
            if (!$old) {
                $this->db->run("INSERT INTO lgc_tasks(public_id,name,name_key,comment,folder_id,associate_post_id,created_by,created_at,start_at,stop_at,period_seconds,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,'draft')",[Data::bin($uuid),$name,Data::nameKey($name),$comment,$folder,$associate,$actor->id,Data::now(),$start,$stop,$period]);
                $taskId=(int)$this->db->pdo->lastInsertId();
            } else {
                $taskId=(int)$old['id'];
                $this->db->run('UPDATE lgc_tasks SET name=?,name_key=?,comment=?,folder_id=?,associate_post_id=?,start_at=?,stop_at=?,period_seconds=?,row_version=row_version+1 WHERE id=?',[$name,Data::nameKey($name),$comment,$folder,$associate,$start,$stop,$period,$taskId]);
            }
            if (isset($input['goal'])) {
                if ($old && $old['published_at'] && !$period && $this->now()>=$start) throw new DomainError('once_goal_frozen',409);
                $goal=$this->facts->store($input['goal'],$input['atoms'] ?? []); $reward=$this->ledger->rewards($input['reward'] ?? []);
                if(!$period && !$stop && $this->facts->requiresWindow($goal)) throw new DomainError('period_window_required');
                $access=$this->access?->normalize($actor,$input['reward_access'] ?? []) ?? [];
                $this->access?->prepare($access);
                $max=(int)Data::integer($input['max_claims_per_period'] ?? '1',false,10);
                if ($max>4294967295) throw new DomainError('invalid_claim_limit');
                foreach ($reward as $item) {
                    $resource=$this->ledger->get($item['currency_id']);
                    if ((int)$item['delta']<0) $actor->assert('currency.deduct');
                    if ((int)$item['delta']>0 && $resource['resource_kind']==='experience') $actor->assert('experience.issue');
                }
                $audience=$input['audience'] ?? ['kind'=>'registered'];
                if (!in_array($audience['kind'] ?? '',['registered','groups'],true) || ($audience['kind']==='groups' && empty($audience['group_ids']))) throw new DomainError('invalid_audience');
                $policies=$input['policies'] ?? ['completion'=>'retain','reward'=>'retain'];
                if (!in_array($policies['completion'] ?? '',['retain','invalidate'],true) || !in_array($policies['reward'] ?? '',['retain','reverse'],true)) throw new DomainError('invalid_lifecycle_policy');
                $version=(int)$this->db->scalar('SELECT COALESCE(MAX(version),0)+1 FROM lgc_task_versions WHERE task_id=?',[$taskId]);
                $this->db->run("INSERT INTO lgc_task_versions(task_id,version,state,goal,reward,max_claims_per_period,funding_user_id,audience,policies,definition_hash,actor_id,created_at) VALUES(?,?,'draft',?,?,?,?,?,?,?,?,?)",[$taskId,$version,Data::json($goal),Data::json($reward),$max,$actor->id,Data::json($audience),Data::json($policies),Data::hash([$goal,$reward,$max,$actor->id,$audience,$policies]),$actor->id,Data::now()]);
                $this->db->run('UPDATE lgc_tasks SET draft_version=? WHERE id=?',[$version,$taskId]);
                $this->db->run('UPDATE lgc_task_versions SET reward_access=?,definition_hash=? WHERE task_id=? AND version=?',[Data::json($access),Data::hash([$goal,$reward,$access,$max,$actor->id,$audience,$policies]),$taskId,$version]);
            } elseif (!$old) throw new DomainError('goal_required');
            return ['id'=>$uuid];
        });
    }
    public function publish(Actor $actor, string $id): array
    {
        $actor->assert('tasks.manage');
        return $this->db->transaction(function () use ($actor,$id) {
            $this->db->one('SELECT id FROM lgc_folders WHERE id=1 FOR UPDATE');
            $task=$this->db->one('SELECT * FROM lgc_tasks WHERE public_id=? FOR UPDATE',[Data::bin($id)]);
            if (!$task || !$task['draft_version'] || $task['status']==='closed') throw new DomainError('no_publishable_draft',409);
            $rule=$this->db->one('SELECT * FROM lgc_task_versions WHERE task_id=? AND version=?',[$task['id'],$task['draft_version']]);
            if ((int)$rule['funding_user_id']!==$actor->id) throw new DomainError('publisher_must_fund',403);
            $this->access?->normalize($actor,Data::decode($rule['reward_access'] ?? null));
            foreach (Data::decode($rule['reward']) as $item) {
                $resource=$this->ledger->get($item['currency_id']);
                if ((int)$item['delta']<0) $actor->assert('currency.deduct');
                if ((int)$item['delta']>0 && $resource['resource_kind']==='experience') $actor->assert('experience.issue');
            }
            $goal=Data::decode($rule['goal']); $atoms=$this->facts->atoms($goal);
            if(!(int)$task['period_seconds'] && !$task['stop_at'] && $this->facts->requiresWindow($goal)) throw new DomainError('period_window_required');
            if ((int)$rule['max_claims_per_period']!==1) foreach ($goal as $clause) {
                $progress=false; foreach ($clause as $term) if ($this->facts->consumable($atoms[$term['atom_id']],$term['negated'])!==null) $progress=true;
                if (!$progress) throw new DomainError('repeat_path_needs_progress');
            }
            $this->graph->validate('task',$id,$goal);
            $now=$this->now();
            if ($task['stop_at'] && $now>=$task['stop_at']) throw new DomainError('task_expired',409);
            $occurrence=$now<$task['start_at'] ? 0 : ((int)$task['period_seconds'] ? intdiv(strtotime($now.' UTC')-strtotime($task['start_at'].' UTC'),(int)$task['period_seconds']) : 0);
            if ($task['published_at'] && $now>=$task['start_at']) { if (!(int)$task['period_seconds']) throw new DomainError('once_goal_frozen',409); $occurrence++; }
            $this->db->run("UPDATE lgc_task_versions SET state='published',effective_from_occurrence=?,published_at=? WHERE task_id=? AND version=?",[$occurrence,$now,$task['id'],$rule['version']]);
            $this->db->run("UPDATE lgc_tasks SET status='active',latest_version=?,draft_version=NULL,published_at=COALESCE(published_at,?),row_version=row_version+1 WHERE id=?",[$rule['version'],$now,$task['id']]);
            $this->facts->bind('task',(int)$task['id'],(int)$rule['version'],$goal);
            $this->outbox->emit('task.published','task:'.$task['id'],(int)$rule['version'],['task_id'=>(int)$task['id'],'after_user'=>0],$actor->id);
            $this->schedule($this->find($id),$goal);
            return ['id'=>$id,'effective_from_occurrence'=>(string)$occurrence];
        });
    }
    public function close(Actor $actor, string $id): array
    {
        $actor->assert('tasks.manage');
        return $this->db->transaction(function () use ($id) { $task=$this->db->one('SELECT id FROM lgc_tasks WHERE public_id=? FOR UPDATE',[Data::bin($id)]); if (!$task) throw new DomainError('task_not_found',404); $this->db->run("UPDATE lgc_tasks SET status='closed',row_version=row_version+1 WHERE id=?",[$task['id']]); return ['id'=>$id,'status'=>'closed']; });
    }
    public function delete(Actor $actor, string $id): array
    {
        $actor->assert('tasks.manage');
        return $this->db->transaction(function () use ($id) { $task=$this->db->one('SELECT * FROM lgc_tasks WHERE public_id=? FOR UPDATE',[Data::bin($id)]); if (!$task || $task['published_at']) throw new DomainError('only_unpublished_drafts_deletable',409); $this->db->run('DELETE FROM lgc_task_versions WHERE task_id=?',[$task['id']]); $this->db->run('DELETE FROM lgc_tasks WHERE id=?',[$task['id']]); return ['deleted'=>true]; });
    }
    public function occurrence(array $task, ?int $specified=null): array
    {
        $now=$this->now(); $start=strtotime($task['start_at'].' UTC'); $period=(int)$task['period_seconds'];
        if ($specified===null && ($now<$task['start_at'] || ($task['stop_at'] && $now>=$task['stop_at']))) throw new DomainError('outside_task_time',409);
        $k=$specified ?? ($period ? intdiv(strtotime($now.' UTC')-$start,$period) : 0);
        if ($k<0 || (!$period && $k!==0) || $k>100000000 || ($period && $k>intdiv(253402300799-$start,$period))) throw new DomainError('invalid_occurrence');
        $from=gmdate('Y-m-d H:i:s',$start+$k*$period); $to=$period ? gmdate('Y-m-d H:i:s',$start+($k+1)*$period) : $task['stop_at'];
        if ($task['stop_at'] && (!$to || $to>$task['stop_at'])) $to=$task['stop_at'];
        if ($to && $from>=$to) throw new DomainError('invalid_occurrence');
        return ['occurrence'=>$k,'start'=>$from,'end'=>$to];
    }
    public function period(array $task, ?int $specified=null): array
    {
        $bounds=$this->occurrence($task,$specified);
        $old=$this->db->one('SELECT * FROM lgc_task_periods WHERE task_id=? AND occurrence=?',[$task['id'],$bounds['occurrence']]);
        if ($old) return $old;
        return $this->db->transaction(function () use ($task,$bounds) {
            $this->db->one('SELECT id FROM lgc_tasks WHERE id=? FOR UPDATE',[$task['id']]);
            $rule=$this->db->one("SELECT version FROM lgc_task_versions WHERE task_id=? AND state='published' AND effective_from_occurrence<=? ORDER BY effective_from_occurrence DESC,version DESC LIMIT 1",[$task['id'],$bounds['occurrence']]);
            if (!$rule) throw new DomainError('period_unavailable',409);
            $this->db->run('INSERT IGNORE INTO lgc_task_periods(task_id,occurrence,version,starts_at,ends_at,created_at) VALUES(?,?,?,?,?,?)',[$task['id'],$bounds['occurrence'],$rule['version'],$bounds['start'],$bounds['end'],Data::now()]);
            return $this->db->one('SELECT * FROM lgc_task_periods WHERE task_id=? AND occurrence=?',[$task['id'],$bounds['occurrence']]);
        });
    }
    private function rule(array $task, ?array $period=null, bool $draft=false): array
    {
        $version=$period['version'] ?? ($draft ? ($task['draft_version'] ?: $task['latest_version']) : $task['latest_version']);
        return $this->db->one('SELECT * FROM lgc_task_versions WHERE task_id=? AND version=?',[$task['id'],$version]) ?: throw new DomainError('rule_unavailable',409);
    }
    public function detail(Actor $actor, string $id, bool $includeProgress=false, bool $manage=false): array
    {
        $task=$this->find($id); $rule=$this->rule($task,null,$manage && $actor->can('tasks.manage'));
        $period=null;
        try { if ($task['status']==='active') { $period=$this->period($task); if (!$manage || !$actor->can('tasks.manage')) $rule=$this->rule($task,$period); } } catch (DomainError $e) { if ($e->status!==409) throw $e; }
        if (!$this->visible($actor,$task,$rule)) throw new DomainError('task_not_found',404);
        $goal=Data::decode($rule['goal']);
        $result=['id'=>$id,'name'=>$task['name'],'comment'=>$task['comment'],'associate_post'=>$task['associate_post_id'],'created_at'=>$task['created_at'],'start_at'=>$task['start_at'],'stop_at'=>$task['stop_at'],'period'=>(int)$task['period_seconds'],'status'=>$task['status'],'version'=>(int)$task['row_version'],'rule_version'=>(int)$rule['version'],'goal'=>$goal,'atoms'=>array_values($this->facts->atoms($goal)),'reward'=>Data::decode($rule['reward']),'max_claims_per_period'=>(string)$rule['max_claims_per_period'],'occurrence'=>$period ? (string)$period['occurrence'] : null,'period_start'=>$period['starts_at'] ?? null,'period_end'=>$period['ends_at'] ?? null,'audience'=>Data::decode($rule['audience']),'policies'=>Data::decode($rule['policies'])];
        $result['reward_access']=Data::decode($rule['reward_access'] ?? null); $result['is_pinned']=(bool)$task['is_pinned'];
        $result['can_pin']=$actor->can('tasks.manage') && ($actor->can('*') || (int)$task['created_by']===$actor->id);
        $result['folder_id']=$task['folder_id'] && (int)$task['folder_id']!==1 ? Data::id($this->db->scalar('SELECT public_id FROM lgc_folders WHERE id=?',[$task['folder_id']])) : null;
        if ($includeProgress && $actor->id && $period) $result['progress']=$this->check($actor,$id);
        elseif($includeProgress && $actor->id && $task['status']==='active' && $this->now()<$task['start_at']) $result['progress']=['state'=>'scheduled','can_claim'=>false,'claim_count'=>'0'];
        return $result;
    }
    public function list(Actor $actor, array $options=[]): array
    {
        $limit=min(50,max(1,(int)($options['limit'] ?? 20))); $args=[max(0,(int)($options['after'] ?? 0))]; $where='t.id>?';
        if (!$actor->can('tasks.manage') || empty($options['manage'])) $where.=" AND t.status='active' AND (t.stop_at IS NULL OR t.stop_at>UTC_TIMESTAMP())";
        if(!empty($options['pinned'])) $where.=' AND t.is_pinned=1';
        if (!empty($options['search'])) { $where.=' AND t.name_key LIKE ?'; $args[]=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],Data::nameKey($options['search'])).'%'; }
        if (!empty($options['folder_id'])) { $folder=$this->db->one('SELECT path FROM lgc_folders WHERE public_id=?',[Data::bin($options['folder_id'])]); if (!$folder) throw new DomainError('folder_not_found',404); $where.=' AND f.path LIKE ?'; $args[]=$folder['path'].'%'; }
        $effective="COALESCE((SELECT rv.version FROM lgc_task_versions rv WHERE rv.task_id=t.id AND rv.state='published' AND rv.effective_from_occurrence<=GREATEST(0,COALESCE(FLOOR(TIMESTAMPDIFF(SECOND,t.start_at,UTC_TIMESTAMP())/NULLIF(t.period_seconds,0)),0)) ORDER BY rv.effective_from_occurrence DESC,rv.version DESC LIMIT 1),NULLIF(t.latest_version,0))";
        $version=$actor->can('tasks.manage') && !empty($options['manage']) ? 'COALESCE(t.draft_version,'.$effective.')' : $effective;
        $rows=$this->db->all('SELECT t.*,v.audience,v.reward FROM lgc_tasks t JOIN lgc_task_versions v ON v.task_id=t.id AND v.version='.$version.' LEFT JOIN lgc_folders f ON f.id=t.folder_id WHERE '.$where.' ORDER BY t.id LIMIT '.($limit+1),$args);
        $items=[]; $after=null;
        foreach (array_slice($rows,0,$limit) as $row) { $after=(string)$row['id']; if (!$this->visible($actor,$row,$row)) continue; $items[]=['id'=>Data::id($row['public_id']),'name'=>$row['name'],'comment'=>$row['comment'],'status'=>$row['status'],'start_at'=>$row['start_at'],'stop_at'=>$row['stop_at'],'period'=>(int)$row['period_seconds'],'is_pinned'=>(bool)$row['is_pinned'],'can_pin'=>$actor->can('*') || (int)$row['created_by']===$actor->id,'version'=>(int)$row['row_version'],'reward'=>Data::decode($row['reward'])]; }
        return ['items'=>$items,'next'=>count($rows)>$limit ? $after : null];
    }
    public function check(Actor $actor, string $id, bool $background=false): array
    {
        if (!$actor->id) throw new DomainError('login_required',403);
        $task=$this->find($id); $period=$this->period($task); $rule=$this->rule($task,$period);
        if (!$this->visible($actor,$task,$rule)) throw new DomainError('task_not_found',404);
        if ($task['status']!=='active') throw new DomainError('task_closed',409);
        $instance=$this->db->one('SELECT * FROM lgc_user_tasks WHERE user_id=? AND period_id=?',[$actor->id,$period['id']]);
        $count=(int)($instance['claim_count'] ?? 0); $max=(int)$rule['max_claims_per_period'];
        if ($max && $count>=$max) return ['state'=>'exhausted','claim_count'=>(string)$count,'can_claim'=>false];
        try {
            $result=$this->facts->evaluate(Data::decode($rule['goal']),['user'=>$actor->id,'start'=>$period['starts_at'],'end'=>$period['ends_at'],'now'=>$this->now()],(int)$task['id']);
            $summary=$this->summary($result);
            $status=$result['truth'] ? 'claimable' : 'pending';
            if((!$background || $instance || $result['truth']) && (!$instance || $instance['status']!==$status)) $this->db->transaction(function () use ($actor,$task,$period,$result,$count,$status) {
                $this->db->run("INSERT IGNORE INTO lgc_user_tasks(user_id,period_id,status) VALUES(?,?,'pending')",[$actor->id,$period['id']]);
                $changed=$this->db->run('UPDATE lgc_user_tasks SET status=? WHERE user_id=? AND period_id=? AND claim_count=? AND status<>?',[$status,$actor->id,$period['id'],$count,$status])->rowCount();
                if ($changed && $result['truth']) $this->outbox->emit('task.available','ready:'.$task['id'].':'.$actor->id.':'.$period['occurrence'],$count+1,['task_id'=>(int)$task['id'],'occurrence'=>(string)$period['occurrence'],'sequence'=>(string)($count+1)],$actor->id);
            });
            return ['state'=>$result['truth'] ? 'claimable' : 'pending','can_claim'=>$result['truth'],'claim_count'=>(string)$count,'use_revision'=>(string)$this->db->source('task-use:'.$task['id'],$actor->id)]+$summary;
        } catch (DomainError $e) { if ($e->status!==202) throw $e; return ['state'=>'preparing','can_claim'=>false,'claim_count'=>(string)$count,'message'=>$e->getMessage()]; }
    }
    private function summary(array $result): array
    {
        $atoms=[]; foreach ($result['atoms'] as $id=>$value) $atoms[$id]=['truth'=>$value['truth'],'current'=>$value['value'],'target'=>$value['target']];
        return ['clause'=>$result['clause'],'atoms'=>$atoms];
    }
    public function claim(Actor $actor, string $id, array $input): array
    {
        return $this->claimIntent($actor,$id,$input);
    }
    private function claimIntent(Actor $actor, string $id, array $input): array
    {
        if (!$actor->id) throw new DomainError('login_required',403);
        $request=strtolower((string)($input['request_id'] ?? '')); Data::bin($request);
        $occurrence=(int)Data::integer($input['occurrence'] ?? null,false,10);
        $task=$this->find($id);
        $old=$this->db->one('SELECT c.receipt FROM lgc_task_claims c JOIN lgc_user_tasks u ON u.id=c.user_task_id JOIN lgc_task_periods p ON p.id=u.period_id WHERE c.task_id=? AND c.user_id=? AND p.occurrence=? AND c.request_id=?',[$task['id'],$actor->id,$occurrence,Data::bin($request)]);
        if ($old) { $receipt=Data::decode($old['receipt']); if ((int)($input['rule_version'] ?? 0)!==(int)$receipt['rule_version']) throw new DomainError('operation_conflict',409); return $receipt; }
        $period=$this->period($task,$occurrence); $rule=$this->rule($task,$period);
        if (!$this->visible($actor,$task,$rule)) throw new DomainError('task_not_found',404);
        if ((int)($input['rule_version'] ?? 0)!==(int)$rule['version']) throw new DomainError('rule_version_conflict',409);
        $context=['user'=>$actor->id,'start'=>$period['starts_at'],'end'=>$period['ends_at'],'now'=>$this->now()];
        $goal=Data::decode($rule['goal']);
        $this->assertOpen($task,$period,$this->now());
        // Cold scans run here, before acquiring quota or financial locks.
        $prepared=$this->facts->evaluate($goal,$context,(int)$task['id']);
        if (!$prepared['truth']) {
            $queries=$this->facts->postQueries($goal,$prepared['atoms']);
            if($queries) { $this->queries->invalidateQueries($actor->id,$queries,$context); $prepared=$this->facts->evaluate($goal,$context,(int)$task['id']); }
            if(!$prepared['truth']) throw new DomainError('not_qualified',409);
        }
        $this->db->source('task-use:'.$task['id'],$actor->id);
        try { return $this->db->transaction(function () use ($actor,$task,$period,$rule,$goal,$request,$occurrence,$input) {
            $lockedTask=$this->db->one('SELECT * FROM lgc_tasks WHERE id=? LOCK IN SHARE MODE',[$task['id']]);
            $this->db->one('SELECT id FROM lgc_task_periods WHERE id=? LOCK IN SHARE MODE',[$period['id']]);
            $this->db->one('SELECT version FROM lgc_source_states WHERE source_key=? AND user_id=? FOR UPDATE',['task-use:'.$task['id'],$actor->id]);
            $this->db->run("INSERT IGNORE INTO lgc_user_tasks(user_id,period_id,status) VALUES(?,?,'pending')",[$actor->id,$period['id']]);
            $instance=$this->db->one('SELECT * FROM lgc_user_tasks WHERE user_id=? AND period_id=? FOR UPDATE',[$actor->id,$period['id']]);
            $old=$this->db->one('SELECT receipt FROM lgc_task_claims WHERE user_task_id=? AND request_id=?',[$instance['id'],Data::bin($request)]);
            if ($old) { $receipt=Data::decode($old['receipt']); if ((int)($input['rule_version'] ?? 0)!==(int)$receipt['rule_version']) throw new DomainError('operation_conflict',409); return $receipt; }
            $now=$this->now();
            $this->assertOpen($lockedTask,$period,$now);
            if ((int)$rule['max_claims_per_period'] && (int)$instance['claim_count']>=(int)$rule['max_claims_per_period']) throw new DomainError('claim_limit_reached',409);
            $context=['user'=>$actor->id,'start'=>$period['starts_at'],'end'=>$period['ends_at'],'now'=>$now,'lock'=>true];
            $result=$this->facts->evaluate($goal,$context,(int)$task['id'],false,true);
            if (!$result['truth']) throw new DomainError('not_qualified',409);
            // Both operands of every gate were verified; positive progress uses locked witnesses.
            $selected=Evidence::choose($result['requirements']);
            foreach ($result['requirements'] as $requirement) if (count($this->queries->verify($requirement['query'],$context,$selected))<$requirement['required']) throw new DomainError('evidence_changed',409,'帖子条件已变化，请刷新进度');
            $key=Data::hash(['task',$task['id'],$actor->id,$occurrence,$request]);
            $money=$this->ledger->taskSettlement($actor->id,(int)$rule['funding_user_id'],Data::decode($rule['reward']),$key,'task:'.$task['id'].':'.$occurrence.':'.$request,'任务《'.$lockedTask['name'].'》第'.((int)$instance['claim_count']+1).'次领取');
            if ($this->afterSettlement) ($this->afterSettlement)();
            $settledAt=$this->now();
            $this->assertOpen($lockedTask,$period,$settledAt);
            $context['now']=$settledAt;
            $this->facts->verifyTime($goal,(int)$result['clause'],$context);
            $sequence=(int)$instance['claim_count']+1;
            $receipt=['task_id'=>Data::id($task['public_id']),'occurrence'=>(string)$occurrence,'sequence'=>(string)$sequence,'request_id'=>$request,'rule_version'=>(int)$rule['version'],'reward'=>Data::decode($rule['reward']),'transaction_id'=>$money['id'],'completed_at'=>$settledAt];
            $receipt['reward_access']=Data::decode($rule['reward_access'] ?? null);
            $evidence=['clause'=>$result['clause'],'post_ids'=>$selected,'requirements'=>array_map(fn ($r)=>['required'=>$r['required'],'query'=>$r['query']],$result['requirements']),'gates'=>$this->summary($result),'rule_hash'=>$rule['definition_hash']];
            $this->db->run('INSERT INTO lgc_task_claims(user_task_id,user_id,task_id,sequence_no,request_id,operation_key,transaction_id,evidence,receipt,claimed_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$instance['id'],$actor->id,$task['id'],$sequence,Data::bin($request),$key,$money['internal_id'],Data::json($evidence),Data::json($receipt),$settledAt]);
            $claim=(int)$this->db->pdo->lastInsertId();
            $this->access?->grant($actor->id,(int)$rule['funding_user_id'],Data::decode($rule['reward_access'] ?? null),'claim:'.$claim);
            foreach(array_chunk($selected,200) as $batch) {
                $args=[]; foreach($batch as $object) array_push($args,$task['id'],$actor->id,$object,$claim,$settledAt);
                $this->db->run("INSERT INTO lgc_task_progress_uses(task_id,user_id,source_key,object_id,claim_id,used_at) VALUES ".implode(',',array_fill(0,count($batch),"(?,?,'posts',?,?,?)")),$args);
            }
            $this->db->source('task-use:'.$task['id'],$actor->id,true);
            $this->db->run("UPDATE lgc_user_tasks SET claim_count=claim_count+1,active_claim_count=active_claim_count+1,last_completed_at=?,status='pending' WHERE id=?",[$settledAt,$instance['id']]);
            $this->outbox->emit('task.completed','claim:'.$claim,1,['claim_id'=>$claim,'task_id'=>(int)$task['id']],$actor->id);
            return $receipt;
        }); } catch(DomainError $error) {
            if($error->reason==='evidence_changed') $this->queries->invalidateQueries($actor->id,$this->facts->postQueries($goal),$context);
            throw $error;
        }
    }
    private function assertOpen(array $task, array $period, string $now): void
    {
        if ($task['status']!=='active' || $now<$period['starts_at'] || ($period['ends_at'] && $now>=$period['ends_at']) || ($task['stop_at'] && $now>=$task['stop_at'])) throw new DomainError('outside_task_time',409);
    }
    public function pin(Actor $actor,string $uuid,bool $pinned,int $expected): array {
        $actor->assert('tasks.manage');
        return $this->db->transaction(function() use($actor,$uuid,$pinned,$expected) {
            $task=$this->db->one('SELECT * FROM lgc_tasks WHERE public_id=? FOR UPDATE',[Data::bin($uuid)]);
            if(!$task || (!$actor->can('*') && (int)$task['created_by']!==$actor->id)) throw new DomainError('pin_not_allowed',403);
            if((int)$task['row_version']!==$expected) throw new DomainError('version_conflict',409);
            $this->db->run('UPDATE lgc_tasks SET is_pinned=?,row_version=row_version+1 WHERE id=?',[(int)$pinned,$task['id']]); return ['id'=>$uuid,'is_pinned'=>$pinned];
        });
    }
    private function schedule(array $task, array $goal): void
    {
        $now=$this->now(); $times=[];
        if ($task['start_at']>$now) $times[]=$task['start_at'];
        elseif ((int)$task['period_seconds']>0) { try { $times[]=$this->occurrence($task)['end']; } catch (DomainError) {} }
        if ($next=$this->facts->nextCheck($goal,$now)) $times[]=$next;
        foreach (array_filter(array_unique($times)) as $time) if (!$task['stop_at'] || $time<$task['stop_at']) $this->outbox->enqueue('task.wake',['task_id'=>(int)$task['id']],'task-wake:'.$task['id'].':'.$time,0,null,$time);
    }
    public function wake(int $id): void
    {
        $task=$this->db->one('SELECT * FROM lgc_tasks WHERE id=?',[$id]); if (!$task || $task['status']!=='active') return;
        if ($task['stop_at'] && $this->now()>=$task['stop_at']) { $this->db->run("UPDATE lgc_tasks SET status='closed',row_version=row_version+1 WHERE id=?",[$id]); return; }
        $rule=$this->rule($task,$this->period($task));
        $this->outbox->enqueue('tasks.scan',['task_id'=>$id,'after_user'=>0,'trigger'=>Data::uuid()],'task-scan:'.$id.':'.Data::uuid(),1);
        $this->schedule($task,Data::decode($rule['goal']));
    }
    public function affected(Actor $actor, array $dependencies, int $after=0): ?int
    {
        if (!$dependencies) return null;
        $occurrence='IF(t.period_seconds=0,0,GREATEST(0,TIMESTAMPDIFF(SECOND,t.start_at,UTC_TIMESTAMP()) DIV t.period_seconds))';
        $version="COALESCE((SELECT p.version FROM lgc_task_periods p WHERE p.task_id=t.id AND p.occurrence=$occurrence),(SELECT v.version FROM lgc_task_versions v WHERE v.task_id=t.id AND v.state='published' AND v.effective_from_occurrence<=$occurrence ORDER BY v.effective_from_occurrence DESC,v.version DESC LIMIT 1))";
        $tasks=$this->db->all("SELECT DISTINCT t.id,t.public_id FROM lgc_bindings b JOIN lgc_tasks t ON t.id=b.owner_id WHERE b.owner_kind='task' AND b.owner_version=$version AND b.dependency_key IN (".implode(',',array_fill(0,count($dependencies),'?')).") AND t.status='active' AND t.id>? AND t.start_at<=UTC_TIMESTAMP() AND (t.stop_at IS NULL OR t.stop_at>UTC_TIMESTAMP()) ORDER BY t.id LIMIT 200",[...$dependencies,$after]);
        foreach ($tasks as $task) { try { $this->check($actor,Data::id($task['public_id']),true); } catch (DomainError $error) { if (!in_array($error->status,[202,403,404,409],true)) throw $error; } }
        return count($tasks)===200 ? (int)end($tasks)['id'] : null;
    }
    public function candidateAuthors(int $id): bool
    {
        $task=$this->db->one('SELECT * FROM lgc_tasks WHERE id=?',[$id]); if (!$task) return false;
        $period=null; try { $period=$this->period($task); } catch (DomainError $e) { if ($e->status!==409) throw $e; }
        $goal=Data::decode($this->rule($task,$period)['goal']); $atoms=$this->facts->atoms($goal);
        foreach ($goal as $clause) { $positive=false; foreach ($clause as $term) if ($this->facts->consumable($atoms[$term['atom_id']],$term['negated'])!==null) $positive=true; if (!$positive) return false; }
        return true;
    }
    public function reconcileUser(Actor $actor, int $after=0): ?int
    {
        $rows=$this->db->all("SELECT id,public_id FROM lgc_tasks WHERE status='active' AND id>? ORDER BY id LIMIT 200",[$after]);
        foreach ($rows as $row) { try { $this->check($actor,Data::id($row['public_id']),true); } catch (DomainError $error) { if (!in_array($error->status,[202,403,404,409],true)) throw $error; } }
        return count($rows)===200 ? (int)end($rows)['id'] : null;
    }
}
