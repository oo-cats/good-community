<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Access;
use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError};

/** Access rewards have independent sources; native permissions still run through Flarum groups. */
final class Rewards
{
    public function __construct(private Db $db, private ?\Closure $resolveActor=null) {}
    private function issuer(int $id): Actor {
        if ($this->resolveActor) return ($this->resolveActor)($id);
        $user=$this->db->one('SELECT id,is_email_confirmed FROM '.$this->db->native('users').' WHERE id=? LOCK IN SHARE MODE',[$id]);
        if(!$user) throw new DomainError('reward_issuer_unavailable',409);
        $groups=array_map('intval',array_column($this->db->all('SELECT group_id FROM '.$this->db->native('group_user').' WHERE user_id=? LOCK IN SHARE MODE',[$id]),'group_id'));
        if(in_array(1,$groups,true)) return new Actor($id,['*'],$groups);
        $ids=$user['is_email_confirmed'] ? [...$groups,2,3] : [2];
        $virtual=$user['is_email_confirmed'] ? $this->db->all('SELECT g.group_id,g.permission FROM lgc_access_memberships m JOIN lgc_permission_groups g ON g.group_id=m.group_id WHERE m.user_id=? LOCK IN SHARE MODE',[$id]) : [];
        $permissions=$this->db->all('SELECT group_id,permission FROM '.$this->db->native('group_permission').' WHERE group_id IN ('.implode(',',array_fill(0,count($ids)+count($virtual),'?')).') LOCK IN SHARE MODE',[...$ids,...array_column($virtual,'group_id')]);
        $allowed=[]; foreach($permissions as $permission) if(in_array((int)$permission['group_id'],$ids,true)) $allowed[]=$permission['permission'];
        foreach($virtual as $v) { $matching=array_values(array_filter($permissions,fn($p)=>(int)$p['group_id']===(int)$v['group_id'])); if(count($matching)===1 && $matching[0]['permission']===$v['permission']) $allowed[]=$v['permission']; }
        return new Actor($id,$allowed,$groups);
    }
    public function prepare(array $items): void { foreach($items as $item) if($item['kind']==='permission') $this->permissionGroup($item['permission']); }
    public function normalize(Actor $issuer, array $items): array {
        if(count($items)>20) throw new DomainError('access_reward_limit'); $result=[];
        foreach($items as $item) {
            $kind=$item['kind'] ?? ''; $key=$kind==='group' ? (string)(int)($item['group_id'] ?? 0) : (string)($item['permission'] ?? '');
            if($kind==='group') {
                if((int)$key<=3 || !$this->db->scalar('SELECT id FROM '.$this->db->native('groups').' WHERE id=?',[$key]) || $this->db->scalar('SELECT 1 FROM lgc_permission_groups WHERE group_id=?',[$key])) throw new DomainError('group_not_rewardable');
                if(!$issuer->can('*') && !in_array((int)$key,$issuer->groups,true)) throw new DomainError('reward_outside_issuer_authority',403);
                $normalized=['kind'=>'group','group_id'=>(int)$key];
            } elseif($kind==='permission') {
                if(!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,149}$/D',$key) || in_array($key,['administrate','*'],true) || !$this->db->scalar('SELECT 1 FROM '.$this->db->native('group_permission').' WHERE permission=? LIMIT 1',[$key])) throw new DomainError('permission_not_rewardable');
                if(!$issuer->can($key)) throw new DomainError('reward_outside_issuer_authority',403);
                $normalized=['kind'=>'permission','permission'=>$key];
            } else throw new DomainError('invalid_access_reward');
            $result[$kind.':'.$key]=$normalized;
        }
        return array_values($result);
    }
    public function catalog(Actor $actor): array {
        $groups=$this->db->all('SELECT id,name_singular name FROM '.$this->db->native('groups').' WHERE id>3 AND id NOT IN (SELECT group_id FROM lgc_permission_groups) ORDER BY id');
        $groups=array_values(array_filter($groups,fn($group)=>$actor->can('*') || in_array((int)$group['id'],$actor->groups,true)));
        $permissions=array_values(array_filter(array_column($this->db->all('SELECT DISTINCT permission FROM '.$this->db->native('group_permission').' ORDER BY permission'),'permission'),fn($key)=>$key!=='*' && $key!=='administrate' && $actor->can($key)));
        return ['groups'=>$groups,'permissions'=>$permissions];
    }
    public function grant(int $user, int $issuer, array $items, string $source): void {
        if(!$items) return;
        $this->db->transaction(function() use($user,$issuer,$items,$source) {
            $items=$this->normalize($this->issuer($issuer),$items);
            $this->db->one('SELECT id FROM '.$this->db->native('users').' WHERE id=? FOR UPDATE',[$user]);
            foreach($items as $item) {
                $kind=$item['kind']; $key=$kind==='group' ? (string)$item['group_id'] : $item['permission'];
                if($kind==='permission') {
                    $group=$this->permissionGroup($key);
                    $this->db->run('INSERT IGNORE INTO lgc_access_memberships(user_id,group_id,preexisting) VALUES(?,?,0)',[$user,$group]);
                }
                else {
                    $existing=$this->db->scalar('SELECT 1 FROM '.$this->db->native('group_user').' WHERE user_id=? AND group_id=?',[$user,$key]);
                    $this->db->run('INSERT IGNORE INTO lgc_access_memberships(user_id,group_id,preexisting) VALUES(?,?,?)',[$user,$key,(int)(bool)$existing]);
                    $this->db->run('INSERT IGNORE INTO '.$this->db->native('group_user').'(user_id,group_id) VALUES(?,?)',[$user,$key]);
                }
                $this->db->run('INSERT INTO lgc_access_sources(user_id,source_key,kind,target_key,issuer_id,active,granted_at) VALUES(?,?,?,?,?,1,?) ON DUPLICATE KEY UPDATE active=1,revoked_at=NULL',[$user,$source,$kind,$key,$issuer,Data::now()]);
            }
        });
    }
    public function revoke(int $user,string $source): void {
        $this->db->transaction(function() use($user,$source) {
            $this->db->one('SELECT id FROM '.$this->db->native('users').' WHERE id=? FOR UPDATE',[$user]);
            $rows=$this->db->all('SELECT kind,target_key FROM lgc_access_sources WHERE user_id=? AND source_key=? AND active=1 FOR UPDATE',[$user,$source]);
            $this->db->run('UPDATE lgc_access_sources SET active=0,revoked_at=? WHERE user_id=? AND source_key=? AND active=1',[Data::now(),$user,$source]);
            foreach($rows as $row) if(!$this->db->scalar('SELECT 1 FROM lgc_access_sources WHERE user_id=? AND kind=? AND target_key=? AND active=1 LIMIT 1',[$user,$row['kind'],$row['target_key']])) {
                if($row['kind']==='group') {
                    $owned=$this->db->one('SELECT preexisting FROM lgc_access_memberships WHERE user_id=? AND group_id=?',[$user,$row['target_key']]);
                    if($owned && !$owned['preexisting']) $this->db->run('DELETE FROM '.$this->db->native('group_user').' WHERE user_id=? AND group_id=?',[$user,$row['target_key']]);
                    $this->db->run('DELETE FROM lgc_access_memberships WHERE user_id=? AND group_id=?',[$user,$row['target_key']]);
                } else $this->db->run('DELETE m FROM lgc_access_memberships m JOIN lgc_permission_groups g ON g.group_id=m.group_id WHERE m.user_id=? AND g.permission=?',[$user,$row['target_key']]);
            }
        });
    }
    private function permissionGroup(string $key): int {
        $row=$this->db->one('SELECT group_id FROM lgc_permission_groups WHERE permission=?',[$key]); if($row) return (int)$row['group_id'];
        $this->db->one('SELECT id FROM lgc_folders WHERE id=1 FOR UPDATE');
        $row=$this->db->one('SELECT group_id FROM lgc_permission_groups WHERE permission=?',[$key]); if($row) return (int)$row['group_id'];
        $name='权限奖励 '.substr(hash('sha256',$key),0,12);
        $this->db->run('INSERT INTO '.$this->db->native('groups').'(name_singular,name_plural,is_hidden) VALUES(?,?,1)',[$name,$name]); $id=(int)$this->db->pdo->lastInsertId();
        $this->db->run('INSERT INTO '.$this->db->native('group_permission').'(group_id,permission) VALUES(?,?)',[$id,$key]);
        $this->db->run('INSERT INTO lgc_permission_groups(permission,group_id) VALUES(?,?)',[$key,$id]); return $id;
    }
    public function permissionGroups($user,array $groups): array {
        if(!$user->id || !$user->is_email_confirmed) return $groups;
        // Only exact one-permission virtual groups can be included. Admin group is never synthesized.
        // One projection row per distinct grant, independent of lifetime claim/source count.
        $rows=$this->db->all('SELECT g.group_id FROM lgc_access_memberships m JOIN lgc_permission_groups g ON g.group_id=m.group_id WHERE m.user_id=? AND g.group_id>3 AND (SELECT COUNT(*) FROM '.$this->db->native('group_permission').' p WHERE p.group_id=g.group_id)=1 AND EXISTS(SELECT 1 FROM '.$this->db->native('group_permission').' p WHERE p.group_id=g.group_id AND p.permission=g.permission)',[(int)$user->id]);
        return array_values(array_unique([...$groups,...array_map('intval',array_column($rows,'group_id'))]));
    }
    public function groupsChanged($event): void {
        $ids=$event->user->groups()->pluck('groups.id')->all(); $user=(int)$event->user->id;
        foreach($this->db->all('SELECT group_id FROM lgc_access_memberships WHERE user_id=? AND group_id NOT IN (SELECT group_id FROM lgc_permission_groups)',[$user]) as $row) {
            if(in_array((int)$row['group_id'],array_map('intval',$ids),true)) $this->db->run('UPDATE lgc_access_memberships SET preexisting=1 WHERE user_id=? AND group_id=?',[$user,$row['group_id']]);
            else {
                $this->db->run("UPDATE lgc_access_sources SET active=0,revoked_at=? WHERE user_id=? AND kind='group' AND target_key=?",[Data::now(),$user,(string)$row['group_id']]);
                $this->db->run('DELETE FROM lgc_access_memberships WHERE user_id=? AND group_id=?',[$user,$row['group_id']]);
            }
        }
    }
}
