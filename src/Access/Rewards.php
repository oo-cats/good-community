<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Access;
use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Outbox};

/** Native group rewards require both issuer authority and explicit administrator delegation. */
final class Rewards
{
    public function __construct(private Db $db, private ?\Closure $resolveActor=null) {}
    private function issuer(int $id): Actor
    {
        if ($this->resolveActor) return ($this->resolveActor)($id);
        $user=$this->db->one('SELECT id,is_email_confirmed FROM '.$this->db->native('users').' WHERE id=? LOCK IN SHARE MODE',[$id]);
        if (!$user) throw new DomainError('reward_issuer_unavailable',403);
        $groups=array_map('intval',array_column($this->db->all('SELECT group_id FROM '.$this->db->native('group_user').' WHERE user_id=? LOCK IN SHARE MODE',[$id]),'group_id'));
        if (in_array(1,$groups,true)) return new Actor($id,['*'],$groups);
        $ids=$user['is_email_confirmed'] ? [...$groups,2,3] : [2];
        $permissions=array_column($this->db->all('SELECT permission FROM '.$this->db->native('group_permission').' WHERE group_id IN ('.implode(',',array_fill(0,count($ids),'?')).') LOCK IN SHARE MODE',$ids),'permission');
        // Native Flarum keys use the extension prefix; domain abilities do not.
        $abilities=array_map(fn($p)=>str_starts_with($p,'local-good-community.') ? substr($p,21) : $p,$permissions);
        return new Actor($id,[...$permissions,...$abilities],$groups);
    }
    private function safeGroup(int $id,bool $lock=false): void
    {
        $suffix=$lock ? ' LOCK IN SHARE MODE' : '';
        if ($id<=3 || !$this->db->scalar('SELECT id FROM '.$this->db->native('groups').' WHERE id=?'.$suffix,[$id])) throw new DomainError('group_not_rewardable',422);
        $permissions=$this->db->all('SELECT permission FROM '.$this->db->native('group_permission').' WHERE group_id=?'.$suffix,[$id]);
        foreach ($permissions as $row) if ($row['permission']==='administrate' || str_contains($row['permission'],'*')) throw new DomainError('group_not_rewardable',422);
    }
    public function normalize(Actor $issuer,array $items,bool $lock=false): array
    {
        if (count($items)>20) throw new DomainError('access_reward_limit',422);
        $result=[]; $grantors=$issuer->can('*') ? array_unique([1,...$issuer->groups]) : $issuer->groups;
        foreach ($items as $item) {
            if (($item['kind'] ?? '')!=='group' || array_key_exists('permission',$item)) throw new DomainError('group_rewards_only',422);
            $id=(int)Data::integer($item['group_id'] ?? null,false,10);
            if ($id<=3) throw new DomainError('group_not_rewardable',422);
            if (!$issuer->can('*') && !in_array($id,$issuer->groups,true)) throw new DomainError('reward_outside_issuer_authority',403);
            $result[$id]=['kind'=>'group','group_id'=>$id];
        }
        if (!$result) return [];
        $ids=array_keys($result); sort($ids); $marks=implode(',',array_fill(0,count($ids),'?')); $suffix=$lock ? ' LOCK IN SHARE MODE' : '';
        $groups=$this->db->all('SELECT id FROM '.$this->db->native('groups').' WHERE id IN ('.$marks.') ORDER BY id'.$suffix,$ids);
        if (count($groups)!==count($ids)) throw new DomainError('group_not_rewardable',422);
        foreach ($this->db->all('SELECT permission FROM '.$this->db->native('group_permission').' WHERE group_id IN ('.$marks.') ORDER BY group_id,permission'.$suffix,$ids) as $permission) if ($permission['permission']==='administrate' || str_contains($permission['permission'],'*')) throw new DomainError('group_not_rewardable',422);
        $delegated=$grantors ? array_unique(array_column($this->db->all('SELECT reward_group_id FROM lgc_reward_group_policies WHERE reward_group_id IN ('.$marks.') AND grantor_group_id IN ('.implode(',',array_fill(0,count($grantors),'?')).') ORDER BY grantor_group_id,reward_group_id'.$suffix,[...$ids,...$grantors]),'reward_group_id')) : [];
        if (count($delegated)!==count($ids)) throw new DomainError('reward_group_not_delegated',403,'发起人没有管理员授予的用户组奖励权限');
        ksort($result); return array_values($result);
    }
    public function authorize(int $issuer,array $items,string $ability): void
    {
        if (!$items) return;
        $actor=$this->issuer($issuer); $actor->assert($ability);
        $this->normalize($actor,$items,true);
    }
    public function catalog(Actor $actor): array
    {
        $grantors=$actor->can('*') ? array_unique([1,...$actor->groups]) : $actor->groups;
        if (!$grantors) return ['groups'=>[]];
        $marks=implode(',',array_fill(0,count($grantors),'?'));
        $items=$this->db->all('SELECT g.id,g.name_singular name FROM '.$this->db->native('groups')." g WHERE g.id>3 AND EXISTS(SELECT 1 FROM lgc_reward_group_policies p WHERE p.reward_group_id=g.id AND p.grantor_group_id IN ($marks)) AND NOT EXISTS(SELECT 1 FROM ".$this->db->native('group_permission')." gp WHERE gp.group_id=g.id AND (gp.permission='administrate' OR gp.permission LIKE '%*%')) ORDER BY g.id",$grantors);
        return ['groups'=>array_values(array_filter($items,fn($g)=>$actor->can('*') || in_array((int)$g['id'],$actor->groups,true)))];
    }
    public function policies(Actor $actor): array
    {
        $actor->assert('*');
        return ['items'=>$this->db->all('SELECT p.*,g.name_singular grantor_name,r.name_singular reward_name FROM lgc_reward_group_policies p JOIN '.$this->db->native('groups').' g ON g.id=p.grantor_group_id JOIN '.$this->db->native('groups').' r ON r.id=p.reward_group_id ORDER BY grantor_group_id,reward_group_id')];
    }
    public function setPolicy(Actor $actor,int $grantor,int $reward,array $input,bool $delete=false): array
    {
        $actor->assert('*'); $expected=(int)Data::integer($input['expected_version'] ?? null,false,10);
        return $this->db->transaction(function () use ($actor,$grantor,$reward,$expected,$delete) {
            $this->db->sourceLock('reward-policies',0,true);
            if ($grantor===2 || $grantor===3 || !$this->db->scalar('SELECT id FROM '.$this->db->native('groups').' WHERE id=? LOCK IN SHARE MODE',[$grantor])) throw new DomainError('invalid_grantor_group',422);
            if (!$delete) $this->safeGroup($reward,true);
            $old=$this->db->one('SELECT row_version FROM lgc_reward_group_policies WHERE grantor_group_id=? AND reward_group_id=? FOR UPDATE',[$grantor,$reward]);
            if ((int)($old['row_version'] ?? 0)!==$expected) throw new DomainError('version_conflict',409);
            $version=$this->db->source('reward-policies',0,true); $now=Data::now();
            if ($delete) $this->db->run('DELETE FROM lgc_reward_group_policies WHERE grantor_group_id=? AND reward_group_id=?',[$grantor,$reward]);
            elseif ($old) $this->db->run('UPDATE lgc_reward_group_policies SET updated_at=?,row_version=? WHERE grantor_group_id=? AND reward_group_id=?',[$now,$version,$grantor,$reward]);
            else $this->db->run('INSERT INTO lgc_reward_group_policies(grantor_group_id,reward_group_id,created_by,created_at,updated_at,row_version) VALUES(?,?,?,?,?,?)',[$grantor,$reward,$actor->id,$now,$now,$version]);
            (new Outbox($this->db))->emit('reward-policy.changed','reward-policies',$version,['grantor_group_id'=>$grantor,'reward_group_id'=>$reward,'removed'=>$delete,'actor_id'=>$actor->id]);
            return ['grantor_group_id'=>$grantor,'reward_group_id'=>$reward,'version'=>$version,'removed'=>$delete];
        });
    }
    public function grant(int $user,int $issuer,array $items,string $source,string $ability='achievements.manage'): void
    {
        if (!$items) return;
        $this->db->transaction(function () use ($user,$issuer,$items,$source,$ability) {
            $this->authorize($issuer,$items,$ability);
            $this->db->one('SELECT id FROM '.$this->db->native('users').' WHERE id=? FOR UPDATE',[$user]);
            foreach ($items as $item) {
                $group=(int)$item['group_id'];
                $existing=$this->db->scalar('SELECT 1 FROM '.$this->db->native('group_user').' WHERE user_id=? AND group_id=? LOCK IN SHARE MODE',[$user,$group]);
                $this->db->run('INSERT IGNORE INTO lgc_access_memberships(user_id,group_id,preexisting) VALUES(?,?,?)',[$user,$group,(int)(bool)$existing]);
                $this->db->run('INSERT IGNORE INTO '.$this->db->native('group_user').'(user_id,group_id) VALUES(?,?)',[$user,$group]);
                $this->db->run('INSERT INTO lgc_access_sources(user_id,source_key,group_id,issuer_id,active,granted_at) VALUES(?,?,?,?,1,?) ON DUPLICATE KEY UPDATE active=1,revoked_at=NULL',[$user,$source,$group,$issuer,Data::now()]);
            }
        });
    }
    public function revoke(int $user,string $source): void
    {
        $this->db->transaction(function () use ($user,$source) {
            $this->db->one('SELECT id FROM '.$this->db->native('users').' WHERE id=? FOR UPDATE',[$user]);
            $rows=$this->db->all('SELECT group_id FROM lgc_access_sources WHERE user_id=? AND source_key=? AND active=1 FOR UPDATE',[$user,$source]);
            $this->db->run('UPDATE lgc_access_sources SET active=0,revoked_at=? WHERE user_id=? AND source_key=? AND active=1',[Data::now(),$user,$source]);
            foreach ($rows as $row) if (!$this->db->scalar('SELECT 1 FROM lgc_access_sources WHERE user_id=? AND group_id=? AND active=1 LIMIT 1',[$user,$row['group_id']])) {
                $owned=$this->db->one('SELECT preexisting FROM lgc_access_memberships WHERE user_id=? AND group_id=?',[$user,$row['group_id']]);
                if ($owned && !$owned['preexisting']) $this->db->run('DELETE FROM '.$this->db->native('group_user').' WHERE user_id=? AND group_id=?',[$user,$row['group_id']]);
                $this->db->run('DELETE FROM lgc_access_memberships WHERE user_id=? AND group_id=?',[$user,$row['group_id']]);
            }
        });
    }
    public function groupsChanged($event): void
    {
        $ids=array_map('intval',$event->user->groups()->pluck('groups.id')->all()); $user=(int)$event->user->id;
        foreach ($this->db->all('SELECT group_id FROM lgc_access_memberships WHERE user_id=?',[$user]) as $row) {
            if (in_array((int)$row['group_id'],$ids,true)) $this->db->run('UPDATE lgc_access_memberships SET preexisting=1 WHERE user_id=? AND group_id=?',[$user,$row['group_id']]);
            else {
                $this->db->run('UPDATE lgc_access_sources SET active=0,revoked_at=? WHERE user_id=? AND group_id=?',[Data::now(),$user,$row['group_id']]);
                $this->db->run('DELETE FROM lgc_access_memberships WHERE user_id=? AND group_id=?',[$user,$row['group_id']]);
            }
        }
    }
}
