<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;

use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Outbox};

/** Display-only projections. Financial paths never update or consult these totals. */
final class Statistics
{
    public function __construct(private Db $db, private TaskService $tasks, private Outbox $outbox) {}
    public function get(Actor $actor, string $uuid, array $options=[]): array
    {
        $task=$this->tasks->find($uuid);
        if (!$this->tasks->visible($actor,$task)) throw new DomainError('task_not_found',404);
        $scope=$options['scope'] ?? 'task'; $bounds=null;
        if ($scope==='current') { $bounds=$this->tasks->occurrence($task); $scope='period'; }
        elseif ($scope==='period') $bounds=$this->tasks->occurrence($task,(int)Data::integer($options['occurrence'] ?? null,false,10));
        elseif ($scope!=='task') throw new DomainError('invalid_stats_scope');
        $key=$scope==='task' ? 'task' : 'period:'.$bounds['occurrence'];
        $row=$this->db->one('SELECT * FROM lgc_task_statistics WHERE task_id=? AND scope_key=? AND generation=?',[$task['id'],$key,$task['statistics_generation']]);
        return ['task_id'=>$uuid,'name'=>$task['name'],'status'=>$task['status'],'scope'=>['kind'=>$scope]+($bounds ?? []),'calculation_state'=>$row ? 'ready' : 'preparing','completed_users'=>$row ? (string)$row['completed_users'] : null,'successful_claims'=>$row ? (string)$row['successful_claims'] : null,'active_claims'=>$row ? (string)$row['active_claims'] : null,'as_of'=>$row['as_of'] ?? null,'version'=>$row ? (string)$row['version'] : null];
    }
    public function batch(Actor $actor, array $items): array
    {
        if (count($items)>50) throw new DomainError('stats_batch_limit');
        $valid=[]; foreach ($items as $item) { try { $valid[$item['task_id']]=Data::bin($item['task_id']); } catch (\Throwable) {} }
        $tasks=[]; $rows=[];
        if ($valid) {
            $effective="(SELECT iv.version FROM lgc_task_versions iv WHERE iv.task_id=t.id AND iv.state='published' AND iv.effective_from_occurrence<=IF(t.period_seconds=0 OR t.start_at>UTC_TIMESTAMP(),0,TIMESTAMPDIFF(SECOND,t.start_at,UTC_TIMESTAMP()) DIV t.period_seconds) ORDER BY iv.effective_from_occurrence DESC,iv.version DESC LIMIT 1)";
            foreach ($this->db->all('SELECT t.*,v.audience FROM lgc_tasks t JOIN lgc_task_versions v ON v.task_id=t.id AND v.version='.$effective.' WHERE t.public_id IN ('.implode(',',array_fill(0,count($valid),'?')).')',array_values($valid)) as $task) $tasks[Data::id($task['public_id'])]=$task;
            $scopes=['task'=>true];
            foreach ($items as $item) { try { if (isset($tasks[$item['task_id']]) && in_array($item['scope'] ?? 'task',['current','period'],true)) { $bounds=$this->tasks->occurrence($tasks[$item['task_id']],($item['scope'] ?? '')==='current' ? null : (int)Data::integer($item['occurrence'] ?? null,false,10)); $scopes['period:'.$bounds['occurrence']]=true; } } catch (DomainError) {} }
            if ($tasks) foreach ($this->db->all('SELECT s.* FROM lgc_task_statistics s JOIN lgc_tasks t ON t.id=s.task_id AND t.statistics_generation=s.generation WHERE s.task_id IN ('.implode(',',array_fill(0,count($tasks),'?')).') AND s.scope_key IN ('.implode(',',array_fill(0,count($scopes),'?')).')',[...array_column($tasks,'id'),...array_keys($scopes)]) as $row) $rows[$row['task_id']][$row['scope_key']]=$row;
        }
        $result=[];
        foreach ($items as $item) {
            try {
                $uuid=$item['task_id'] ?? ''; $task=$tasks[$uuid] ?? null;
                if (!$task || !$this->tasks->visible($actor,$task,$task)) throw new DomainError('task_not_found',404);
                $scope=$item['scope'] ?? 'task'; $bounds=null;
                if ($scope==='current') { $bounds=$this->tasks->occurrence($task); $scope='period'; }
                elseif ($scope==='period') $bounds=$this->tasks->occurrence($task,(int)Data::integer($item['occurrence'] ?? null,false,10));
                elseif ($scope!=='task') throw new DomainError('invalid_stats_scope');
                $row=$rows[$task['id']][$scope==='task' ? 'task' : 'period:'.$bounds['occurrence']] ?? null;
                $result[]=['task_id'=>$uuid,'name'=>$task['name'],'status'=>$task['status'],'scope'=>['kind'=>$scope]+($bounds ?? []),'calculation_state'=>$row ? 'ready' : 'preparing','completed_users'=>$row ? (string)$row['completed_users'] : null,'successful_claims'=>$row ? (string)$row['successful_claims'] : null,'active_claims'=>$row ? (string)$row['active_claims'] : null,'as_of'=>$row['as_of'] ?? null,'version'=>$row ? (string)$row['version'] : null];
            }
            catch (DomainError $e) { $result[]=['task_id'=>$item['task_id'] ?? null,'error'=>$e->reason]; }
        }
        return $result;
    }
    public function initialize(int $task, int $generation): void
    {
        $this->db->run("INSERT IGNORE INTO lgc_task_statistics(task_id,scope_key,generation,as_of) VALUES(?,'task',?,?)",[$task,$generation,Data::now()]);
    }
    public function project(int $claimId, ?int $onlyGeneration=null): void
    {
        $this->db->transaction(function () use ($claimId,$onlyGeneration) {
            $source=$this->db->one('SELECT task_id FROM lgc_task_claims WHERE id=?',[$claimId]); if (!$source) return;
            $task=$this->db->one('SELECT * FROM lgc_tasks WHERE id=? LOCK IN SHARE MODE',[$source['task_id']]);
            $claim=$this->db->one('SELECT c.*,p.occurrence FROM lgc_task_claims c JOIN lgc_task_periods p ON p.id=c.period_id WHERE c.id=? FOR UPDATE',[$claimId]);
            $allowed=array_values(array_unique(array_filter([(int)$task['statistics_generation'],(int)$task['statistics_building_generation']])));
            if($onlyGeneration!==null && !in_array($onlyGeneration,$allowed,true)) return;
            $generations=$onlyGeneration ? [$onlyGeneration] : $allowed;
            $snapshots=Data::decode($claim['stats_snapshot']);
            $snapshots=array_intersect_key($snapshots,array_flip($allowed));
            foreach ($generations as $generation) {
                $old=$snapshots[$generation] ?? [];
                if ((int)($old['version'] ?? 0)===(int)$claim['validity_version']) continue;
                $successDelta=$old ? 0 : 1; $activeDelta=(int)$claim['active']-(int)($old['active'] ?? 0);
                foreach (['task','period:'.$claim['occurrence']] as $scope) {
                    $args=[$task['id'],$scope,$generation,$claim['user_id']];
                    $this->db->run('INSERT IGNORE INTO lgc_task_stat_members(task_id,scope_key,generation,user_id,updated_at) VALUES(?,?,?,?,?)',[...$args,Data::now()]);
                    $member=$this->db->one('SELECT * FROM lgc_task_stat_members WHERE task_id=? AND scope_key=? AND generation=? AND user_id=? FOR UPDATE',$args);
                    $peopleDelta=(int)((int)$member['active_claims']+$activeDelta>0)-(int)((int)$member['active_claims']>0);
                    $this->db->run('UPDATE lgc_task_stat_members SET active_claims=active_claims+?,updated_at=? WHERE task_id=? AND scope_key=? AND generation=? AND user_id=?',[$activeDelta,Data::now(),...$args]);
                    $this->db->run('INSERT IGNORE INTO lgc_task_statistics(task_id,scope_key,generation,as_of) VALUES(?,?,?,?)',[$task['id'],$scope,$generation,Data::now()]);
                    $this->db->run('UPDATE lgc_task_statistics SET completed_users=completed_users+?,successful_claims=successful_claims+?,active_claims=active_claims+?,version=version+1,as_of=? WHERE task_id=? AND scope_key=? AND generation=?',[$peopleDelta,$successDelta,$activeDelta,Data::now(),$task['id'],$scope,$generation]);
                }
                $snapshots[$generation]=['active'=>(int)$claim['active'],'version'=>(int)$claim['validity_version']];
            }
            $this->db->run('UPDATE lgc_task_claims SET stats_snapshot=? WHERE id=?',[Data::json($snapshots),$claimId]);
        });
    }
    public function rebuild(Actor $actor, string $uuid): array
    {
        $actor->assert('tasks.manage');
        return $this->db->transaction(function () use ($uuid) {
            $task=$this->db->one('SELECT * FROM lgc_tasks WHERE public_id=? FOR UPDATE',[Data::bin($uuid)]);
            if (!$task) throw new DomainError('task_not_found',404);
            $generation=(int)($task['statistics_building_generation'] ?: $task['statistics_generation']+1);
            $this->db->run('UPDATE lgc_tasks SET statistics_building_generation=? WHERE id=?',[$generation,$task['id']]);
            $this->initialize((int)$task['id'],$generation);
            $this->outbox->enqueue('stats.rebuild',['task_id'=>(int)$task['id'],'generation'=>$generation,'after'=>0],'rebuild:'.$task['id'].':'.$generation,3);
            return ['task_id'=>$uuid,'state'=>'preparing'];
        });
    }
    public function rebuildStep(array $payload): void
    {
        $task=(int)$payload['task_id']; $generation=(int)$payload['generation']; $after=(int)$payload['after'];
        if((int)$this->db->scalar('SELECT statistics_building_generation FROM lgc_tasks WHERE id=?',[$task])!==$generation) return;
        $claims=$this->db->all('SELECT id FROM lgc_task_claims WHERE task_id=? AND id>? ORDER BY id LIMIT 200',[$task,$after]);
        foreach ($claims as $claim) $this->project((int)$claim['id'],$generation);
        if (count($claims)===200) { $last=(int)end($claims)['id']; $this->outbox->enqueue('stats.rebuild',['task_id'=>$task,'generation'=>$generation,'after'=>$last],'rebuild:'.$task.':'.$generation.':'.$last,3); return; }
        // Task X is a commit barrier: all claim/validity paths take Task S.
        $this->db->transaction(function () use ($task,$generation) {
            $row=$this->db->one('SELECT * FROM lgc_tasks WHERE id=? FOR UPDATE',[$task]);
            if ((int)$row['statistics_building_generation']!==$generation) return;
            $jsonPath='$."'.$generation.'".version';
            $pending=$this->db->all('SELECT id FROM lgc_task_claims WHERE task_id=? AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(stats_snapshot,?)) AS UNSIGNED),0)<>validity_version ORDER BY id LIMIT 200',[$task,$jsonPath]);
            if ($pending) { foreach ($pending as $claim) $this->outbox->enqueue('stats',['claim_id'=>(int)$claim['id']],'rebuild-catchup:'.$generation.':'.$claim['id'],3); $this->outbox->enqueue('stats.rebuild',['task_id'=>$task,'generation'=>$generation,'after'=>0],'rebuild-retry:'.$task.':'.$generation.':'.Data::uuid(),3); return; }
            $this->db->run('UPDATE lgc_tasks SET statistics_generation=?,statistics_building_generation=NULL WHERE id=?',[$generation,$task]);
        });
        $this->prune($task);
    }
    /** Retired generations are rebuildable; financial and claim identities are untouched. */
    public function prune(int $task,int $after=0): void
    {
        $this->db->transaction(function() use($task,$after) {
            $row=$this->db->one('SELECT statistics_generation,statistics_building_generation FROM lgc_tasks WHERE id=? LOCK IN SHARE MODE',[$task]);
            if(!$row) return;
            $allowed=array_values(array_filter([(int)$row['statistics_generation'],(int)$row['statistics_building_generation']]));
            $marks=implode(',',array_fill(0,count($allowed),'?')); $more=false;
            foreach(['lgc_task_stat_members','lgc_task_statistics'] as $table) $more=($this->db->run('DELETE FROM '.$table.' WHERE task_id=? AND generation NOT IN ('.$marks.') LIMIT 200',[$task,...$allowed])->rowCount()===200) || $more;
            $claims=$this->db->all('SELECT id,stats_snapshot FROM lgc_task_claims WHERE task_id=? AND id>? ORDER BY id LIMIT 200 FOR UPDATE',[$task,$after]);
            foreach($claims as $claim) {
                $snapshot=Data::decode($claim['stats_snapshot']); $kept=array_intersect_key($snapshot,array_flip($allowed));
                if($kept!==$snapshot) $this->db->run('UPDATE lgc_task_claims SET stats_snapshot=? WHERE id=?',[Data::json($kept),$claim['id']]);
            }
            if(count($claims)===200 || $more) {
                $next=$claims ? (int)end($claims)['id'] : $after;
                $this->outbox->enqueue('stats.prune',['task_id'=>$task,'after'=>$next],'stats-prune:'.$task.':'.$next.':'.Data::uuid(),3);
            }
        });
    }
}
