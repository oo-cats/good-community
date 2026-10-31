<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Achievements;

use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Outbox};
use Local\GoodCommunity\Facts\{DependencyGraph,FactEngine};

final class Achievements
{
    private array $stack=[];
    public function __construct(private Db $db, private FactEngine $facts, private DependencyGraph $graph, private Outbox $outbox,private ?\Local\GoodCommunity\Access\Rewards $access=null) {}
    public function define(Actor $actor, array $input): array
    {
        $actor->assert('achievements.manage');
        return $this->db->transaction(function () use ($actor,$input) {
            $this->db->one('SELECT id FROM lgc_folders WHERE id=1 FOR UPDATE');
            $uuid=$input['id'] ?? Data::uuid(); $name=Data::name($input['name'] ?? ''); $description=(string)($input['description'] ?? '');
            if (mb_strlen($description)>10000) throw new DomainError('description_too_long');
            $old=$this->db->one('SELECT * FROM lgc_achievements WHERE public_id=?',[Data::bin($uuid)]);
            if (!$old && isset($input['id'])) throw new DomainError('achievement_not_found',404);
            if ($old && (int)($input['expected_version'] ?? 0)!==(int)$old['row_version']) throw new DomainError('version_conflict',409);
            $mode=$input['mode'] ?? 'manual'; if (!in_array($mode,['manual','automatic'],true)) throw new DomainError('invalid_achievement_mode');
            $goal=$mode==='automatic' ? $this->facts->store($input['goal'] ?? [],$input['atoms'] ?? []) : null;
            if($goal && $this->facts->requiresWindow($goal)) throw new DomainError('period_window_required');
            $access=$this->access?->normalize($actor,$input['reward_access'] ?? []) ?? [];
            if ($goal) $this->graph->validate('achievement',$uuid,$goal);
            if ($old) { $id=(int)$old['id']; $version=(int)$old['latest_version']+1; $this->db->run('UPDATE lgc_achievements SET name=?,name_key=?,description=?,latest_version=?,row_version=row_version+1 WHERE id=?',[$name,Data::nameKey($name),$description,$version,$id]); }
            else { $this->db->run("INSERT INTO lgc_achievements(public_id,name,name_key,description,status,latest_version,created_at) VALUES(?,?,?,?,'active',1,?)",[Data::bin($uuid),$name,Data::nameKey($name),$description,Data::now()]); $id=(int)$this->db->pdo->lastInsertId(); $version=1; }
            $this->db->run("INSERT INTO lgc_achievement_versions(achievement_id,version,state,mode,goal,policies,actor_id,created_at,published_at) VALUES(?,?,'published',?,?,?,?,?,?)",[$id,$version,$mode,$goal ? Data::json($goal) : null,Data::json(['completion'=>'current']),$actor->id,Data::now(),Data::now()]);
            $this->outbox->emit('achievement.published','achievement:'.$id,$version,['achievement_id'=>$id,'after_user'=>0],$actor->id);
            $this->db->run('UPDATE lgc_achievement_versions SET reward_access=? WHERE achievement_id=? AND version=?',[Data::json($access),$id,$version]);
            if($goal) $this->facts->bind('achievement',$id,$version,$goal);
            return ['id'=>$uuid];
        });
    }
    public function list(?int $user=null, bool $manage=false): array
    {
        $rows=$user===null ? $this->db->all("SELECT a.*,v.mode,v.goal,v.reward_access FROM lgc_achievements a JOIN lgc_achievement_versions v ON v.achievement_id=a.id AND v.version=a.latest_version ORDER BY a.id") : $this->db->all('SELECT a.*,v.mode,v.goal,v.reward_access,g.earned_at FROM lgc_achievement_grants g JOIN lgc_achievements a ON a.id=g.achievement_id JOIN lgc_achievement_versions v ON v.achievement_id=a.id AND v.version=a.latest_version WHERE g.user_id=? AND g.active_sources>0 ORDER BY g.earned_at,a.id',[$user]);
        return array_map(function ($row) use ($manage) {
            $item=['id'=>Data::id($row['public_id']),'name'=>$row['name'],'description'=>$row['description'],'status'=>$row['status'],'mode'=>$row['mode'],'earned_at'=>$row['earned_at'] ?? null,'reward_access'=>Data::decode($row['reward_access'] ?? null),'version'=>(int)$row['row_version']];
            if ($manage) { $goal=Data::decode($row['goal'],null); $item['goal']=$goal; $item['atoms']=$goal ? array_values($this->facts->atoms($goal)) : []; }
            return $item;
        },$rows);
    }
    public function sources(Actor $actor, string $uuid, int $user): array
    {
        if (!$actor->can('achievements.grant') && !$actor->can('achievements.revoke')) throw new DomainError('permission_denied',403);
        return $this->db->all('SELECT s.source_key,s.active,s.version,s.granted_at,s.revoked_at FROM lgc_achievement_sources s JOIN lgc_achievements a ON a.id=s.achievement_id WHERE a.public_id=? AND s.user_id=?',[Data::bin($uuid),$user]);
    }
    public function catalog(Actor $actor,array $query): array {
        $args=[max(0,(int)($query['after'] ?? 0))]; $where="a.status='active' AND a.id>?";
        if(!empty($query['search'])) { $where.=' AND a.name_key LIKE ?'; $args[]=str_replace(['%','_'],['\\%','\\_'],Data::nameKey($query['search'])).'%'; }
        $rows=$this->db->all('SELECT a.id,a.public_id,a.name,a.description,(SELECT COUNT(*) FROM lgc_achievement_grants g WHERE g.achievement_id=a.id AND g.active_sources>0) completed_users,EXISTS(SELECT 1 FROM lgc_achievement_grants me WHERE me.achievement_id=a.id AND me.user_id=? AND me.active_sources>0) earned FROM lgc_achievements a WHERE '.$where.' ORDER BY a.id LIMIT 21',[$actor->id,...$args]);
        return ['items'=>array_map(fn($r)=>['id'=>Data::id($r['public_id']),'name'=>$r['name'],'description'=>$r['description'],'completed_users'=>(string)$r['completed_users'],'earned'=>(bool)$r['earned']],array_slice($rows,0,20)),'next'=>count($rows)>20 ? (string)$rows[19]['id'] : null,'as_of'=>Data::now()];
    }
    public function manual(Actor $actor, string $uuid, array $input): array
    {
        $active=(bool)($input['active'] ?? true); $actor->assert($active ? 'achievements.grant' : 'achievements.revoke');
        $user=(int)($input['user_id'] ?? 0);
        if (!$user || !$this->db->scalar('SELECT id FROM '.$this->db->native('users').' WHERE id=?',[$user])) throw new DomainError('user_not_found',404);
        $source=$input['source_key'] ?? 'manual:'.$actor->id;
        if (!preg_match('/^manual:[0-9]+$/D',$source)) throw new DomainError('manual_source_required');
        $request=(string)($input['request_id'] ?? ''); Data::bin($request);
        $key=Data::hash(['achievement-request',$actor->id,$request]);
        $payload=['achievement_id'=>$uuid,'user_id'=>$user,'source_key'=>$source,'active'=>$active,'reason'=>(string)($input['reason'] ?? '')];
        return $this->db->transaction(function () use ($actor,$uuid,$user,$source,$active,$request,$key,$payload) {
            $old=$this->db->one('SELECT payload FROM lgc_events WHERE event_key=? FOR UPDATE',[$key]);
            if ($old) { if (Data::canonical(Data::decode($old['payload']))!==Data::canonical($payload)) throw new DomainError('operation_conflict',409); return $payload; }
            $achievement=$this->db->one('SELECT * FROM lgc_achievements WHERE public_id=?',[Data::bin($uuid)]);
            if (!$achievement || ($active && $achievement['status']!=='active')) throw new DomainError('achievement_unavailable',404);
            if($active) { $reward=$this->db->scalar('SELECT reward_access FROM lgc_achievement_versions WHERE achievement_id=? AND version=?',[$achievement['id'],$achievement['latest_version']]); $this->access?->normalize($actor,Data::decode($reward)); }
            $this->change($user,(int)$achievement['id'],$source,$active,['request_id'=>$request,'actor_id'=>$actor->id,'reason'=>$payload['reason']],null);
            $this->db->run('INSERT INTO lgc_events(event_key,event_type,aggregate_key,aggregate_version,payload,payload_hash,actor_id,subject_user_id,occurred_at) VALUES(?,\'achievement.manual\',?,1,?,?,?,?,?)',[$key,'request:'.$request,Data::json($payload),Data::hash($payload),$actor->id,$user,Data::now()]);
            return $payload;
        });
    }
    private function change(int $user, int $id, string $source, bool $active, array $evidence, ?int $rule): void
    {
        $this->db->transaction(function () use ($user,$id,$source,$active,$evidence,$rule) {
            if (!$active && !$this->db->one('SELECT active FROM lgc_achievement_sources WHERE user_id=? AND achievement_id=? AND source_key=?',[$user,$id,$source])) return;
            $this->db->run('INSERT IGNORE INTO lgc_achievement_grants(user_id,achievement_id) VALUES(?,?)',[$user,$id]);
            $grant=$this->db->one('SELECT * FROM lgc_achievement_grants WHERE user_id=? AND achievement_id=? FOR UPDATE',[$user,$id]);
            $old=$this->db->one('SELECT * FROM lgc_achievement_sources WHERE user_id=? AND achievement_id=? AND source_key=?',[$user,$id,$source]);
            $oldRule=$old && $old['rule_version']!==null ? (int)$old['rule_version'] : null;
            if ($old && (bool)$old['active']===$active && $oldRule===$rule) return;
            $delta=(int)$active-(int)($old['active'] ?? 0); $version=(int)($old['version'] ?? 0)+1; $now=Data::now();
            $definition=$this->db->one('SELECT v.actor_id,v.reward_access FROM lgc_achievement_versions v JOIN lgc_achievements a ON a.id=v.achievement_id AND a.latest_version=v.version WHERE a.id=?',[$id]);
            // A holding keeps its original access reward until its last source is removed.
            // Editing a definition affects new holdings; removing one source never reissues access.
            if((int)$grant['active_sources']===0 && $delta>0) $this->access?->grant($user,(int)$definition['actor_id'],Data::decode($definition['reward_access'] ?? null),'achievement:'.$id);
            elseif((int)$grant['active_sources']+$delta===0) $this->access?->revoke($user,'achievement:'.$id);
            $this->db->run('INSERT INTO lgc_achievement_sources(user_id,achievement_id,source_key,rule_version,active,version,evidence,granted_at,revoked_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active),version=VALUES(version),rule_version=VALUES(rule_version),evidence=VALUES(evidence),revoked_at=VALUES(revoked_at)',[$user,$id,$source,$rule,(int)$active,$version,Data::json($evidence),$now,$active ? null : $now]);
            $this->db->run('UPDATE lgc_achievement_grants SET active_sources=active_sources+?,state_version=state_version+1,earned_at=COALESCE(earned_at,?) WHERE user_id=? AND achievement_id=?',[$delta,$active ? $now : null,$user,$id]);
            $this->outbox->emit('achievement.holding.changed','holding:'.$user.':'.$id,(int)$grant['state_version']+1,['achievement_id'=>$id],$user);
        });
    }
    public function held(int $user, string $uuid, bool $authoritative=false): bool
    {
        $row=$this->db->one('SELECT a.*,v.mode,v.goal,v.reward_access FROM lgc_achievements a JOIN lgc_achievement_versions v ON v.achievement_id=a.id AND v.version=a.latest_version WHERE a.public_id=?',[Data::bin($uuid)]);
        if (!$row) throw new DomainError('achievement_unavailable',202);
        $manual=(bool)$this->db->scalar("SELECT 1 FROM lgc_achievement_sources WHERE user_id=? AND achievement_id=? AND source_key LIKE 'manual:%' AND active=1 LIMIT 1".($authoritative ? ' LOCK IN SHARE MODE' : ''),[$user,$row['id']]);
        if ($manual) return true;
        if ($row['mode']!=='automatic' || $row['status']!=='active') return (bool)$this->db->scalar('SELECT 1 FROM lgc_achievement_grants WHERE user_id=? AND achievement_id=? AND active_sources>0',[$user,$row['id']]);
        return $this->refresh($user,$row,$authoritative,false);
    }
    private function refresh(int $user, array $row, bool $authoritative=false, bool $persist=true): bool
    {
        $key=$user.':'.$row['id']; if (isset($this->stack[$key]) || count($this->stack)>32) throw new DomainError('dependency_recursion',202);
        $this->stack[$key]=true;
        try {
            $result=$this->facts->evaluate(Data::decode($row['goal']),['user'=>$user,'start'=>null,'end'=>null,'now'=>Data::now(),'lock'=>$authoritative],null,$authoritative);
            if ($persist) $this->change($user,(int)$row['id'],'automatic',$result['truth'],['goal_hash'=>Data::hash(Data::decode($row['goal']))],(int)$row['latest_version']);
            return $result['truth'];
        } finally { unset($this->stack[$key]); }
    }
    public function refreshUser(int $user,?array $dependencies=null): void
    {
        if($dependencies===[]) return;
        $filter=$dependencies===null ? '' : " AND EXISTS(SELECT 1 FROM lgc_bindings b WHERE b.owner_kind='achievement' AND b.owner_id=a.id AND b.owner_version=a.latest_version AND b.dependency_key IN (".implode(',',array_fill(0,count($dependencies),'?')).'))';
        $pending=null;
        foreach ($this->db->all("SELECT a.*,v.goal FROM lgc_achievements a JOIN lgc_achievement_versions v ON v.achievement_id=a.id AND v.version=a.latest_version WHERE a.status='active' AND v.mode='automatic'".$filter,$dependencies ?? []) as $row) {
            try { $this->db->transaction(fn()=>$this->refresh($user,$row,true)); }
            catch(DomainError $error) { if($error->status!==202) throw $error; $pending ??= $error; }
        }
        if($pending) throw $pending;
    }
    public function archive(Actor $actor, string $uuid): array
    {
        $actor->assert('achievements.manage');
        $this->db->run("UPDATE lgc_achievements SET status='archived',row_version=row_version+1 WHERE public_id=?",[Data::bin($uuid)]);
        return ['id'=>$uuid,'status'=>'archived'];
    }
}
