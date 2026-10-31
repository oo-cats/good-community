<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Rating;

use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Outbox};

final class RatingService
{
    public function __construct(private Db $db, private Outbox $outbox) {}
    public static function score(mixed $input): int
    {
        if (!is_string($input) && !is_int($input) && !is_float($input)) throw new DomainError('invalid_score');
        if (!preg_match('/^(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,2}))?$/D',(string)$input,$match)) throw new DomainError('invalid_score');
        $value = (int)$match[1]*100+(int)str_pad($match[2] ?? '',2,'0');
        if ($value > 10000) throw new DomainError('score_out_of_range');
        return $value;
    }
    public function post(int $id, bool $lock=false): array
    {
        $row = $this->db->one('SELECT p.*,d.first_post_id,d.hidden_at discussion_hidden FROM '.$this->db->native('posts').' p JOIN '.$this->db->native('discussions').' d ON d.id=p.discussion_id WHERE p.id=?'.($lock ? ' LOCK IN SHARE MODE' : ''),[$id]);
        if (!$row || $row['type'] !== 'comment' || $row['hidden_at'] !== null || $row['discussion_hidden'] !== null) throw new DomainError('post_not_available',404);
        return $row;
    }
    public function state(array $post): array
    {
        $existing=$this->db->one('SELECT * FROM lgc_post_state WHERE post_id=?',[$post['id']]);
        if ($existing) return $existing;
        $hash = hash('sha256',(string)$post['content']);
        $this->db->run('INSERT IGNORE INTO lgc_post_state(post_id,content_hash,updated_at) VALUES(?,?,?)',[$post['id'],$hash,Data::now()]);
        return $this->db->one('SELECT * FROM lgc_post_state WHERE post_id=?',[$post['id']]);
    }
    public function allowed(Actor $actor, array $post): bool
    {
        if (!$actor->id || $actor->id === (int)$post['user_id']) return false;
        $state = $this->db->one('SELECT rating_policy_id FROM lgc_post_state WHERE post_id=?',[$post['id']]);
        $policies = $this->db->all("SELECT * FROM lgc_rating_policies WHERE state='active' AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP()) ORDER BY priority DESC");
        $override = $state['rating_policy_id'] ?? null;
        foreach ($policies as $policy) {
            if ($override && (int)$policy['id'] !== (int)$override) continue;
            $scope = Data::decode($policy['scope_spec']);
            if (!$override && isset($scope['discussion_ids']) && !in_array((int)$post['discussion_id'],array_map('intval',$scope['discussion_ids']),true)) continue;
            if (!$override && !empty($scope['tag_ids'])) {
                if (!$this->db->scalar("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?",[$this->db->prefix.'discussion_tag'])) continue;
                $tags = array_column($this->db->all('SELECT tag_id FROM '.$this->db->native('discussion_tag').' WHERE discussion_id=?',[$post['discussion_id']]),'tag_id');
                if (!array_intersect(array_map('intval',$scope['tag_ids']),array_map('intval',$tags))) continue;
            }
            return $policy['voter_kind'] === 'registered' || (bool)array_intersect($actor->groups,array_map('intval',Data::decode($policy['group_ids']))) || $actor->can('rating.rate');
        }
        return !$override && $actor->can('rating.rate');
    }
    public function read(Actor $actor, int $id): array
    {
        $post = $this->post($id); $state = $this->state($post);
        $vote = $this->db->one('SELECT * FROM lgc_post_votes WHERE post_id=? AND user_id=?',[$id,$actor->id]);
        return ['post_id'=>$id,'count'=>(int)$state['rating_count'],'content_hash'=>hash('sha256',(string)$post['content']),'content_version'=>(string)$state['content_version'],'average'=>$state['rating_count'] ? number_format((int)$state['rating_sum_hundredths']/(100*(int)$state['rating_count']),2,'.','') : null,'can_rate'=>$this->allowed($actor,$post),'own_score'=>$vote && $vote['state'] === 'active' ? number_format((int)$vote['score_hundredths']/100,2,'.','') : null,'own_version'=>(int)($vote['version'] ?? 0),'needs_review'=>$vote && $vote['state'] === 'active' && ((int)$vote['rated_content_version'] < (int)$state['content_version'] || $state['content_hash']!==hash('sha256',(string)$post['content']))];
    }
    public function vote(Actor $actor, int $id, array $input): array
    {
        if (!$actor->id) throw new DomainError('permission_denied',403);
        $request = strtolower((string)($input['request_id'] ?? '')); Data::bin($request);
        $withdraw = ($input['action'] ?? 'rate') === 'withdraw';
        $score = $withdraw ? 0 : self::score($input['score'] ?? null);
        $reason = (string)($input['reason'] ?? '');
        if (mb_strlen($reason)>1000) throw new DomainError('reason_too_long');
        $hash = Data::hash([$id,$actor->id,$withdraw,$score,$reason,$input['expected_content_hash'] ?? null]);
        $operation = hash('sha256','vote:'.$actor->id.':'.$request);
        $this->state($this->post($id)); // Empty aggregate initialization precedes the write transaction.
        return $this->db->transaction(function () use ($actor,$id,$input,$withdraw,$score,$reason,$hash,$operation) {
            $old = $this->db->one('SELECT payload_hash,receipt FROM lgc_vote_history WHERE operation_key=?',[$operation]);
            if ($old) { if ($old['payload_hash'] !== $hash) throw new DomainError('operation_conflict',409); return Data::decode($old['receipt']); }
            $post = $this->post($id,true); $this->state($post);
            $state = $this->db->one('SELECT * FROM lgc_post_state WHERE post_id=? FOR UPDATE',[$id]);
            $vote = $this->db->one('SELECT * FROM lgc_post_votes WHERE post_id=? AND user_id=? FOR UPDATE',[$id,$actor->id]);
            // Recheck idempotency after waiting on the aggregate row.
            $old = $this->db->one('SELECT payload_hash,receipt FROM lgc_vote_history WHERE operation_key=? LOCK IN SHARE MODE',[$operation]);
            if ($old) { if ($old['payload_hash'] !== $hash) throw new DomainError('operation_conflict',409); return Data::decode($old['receipt']); }
            if (!$withdraw && !$this->allowed($actor,$post)) throw new DomainError('permission_denied',403);
            $contentHash=hash('sha256',(string)$post['content']);
            if (!$withdraw && isset($input['expected_content_hash']) && $input['expected_content_hash']!==$contentHash) throw new DomainError('content_changed',409);
            if ($state['content_hash']!==$contentHash) {
                $this->db->run('UPDATE lgc_post_state SET content_hash=?,content_version=content_version+1 WHERE post_id=?',[$contentHash,$id]); $state['content_version']++;
                $source=$this->db->source('posts',(int)$post['user_id'],true);
                $this->outbox->emit('post.changed','native-post:'.$id,$source,['post_id'=>$id,'discussion_id'=>(int)$post['discussion_id'],'edited'=>true,'source_version'=>$source],(int)$post['user_id']);
            }
            if ($withdraw && !$vote) throw new DomainError('vote_not_found',404);
            if ((int)($input['expected_version'] ?? 0) !== (int)($vote['version'] ?? 0)) throw new DomainError('version_conflict',409);
            $previous = $vote && $vote['state'] === 'active';
            $count = (int)$state['rating_count']-($previous ? 1 : 0)+($withdraw ? 0 : 1);
            $sum = (int)$state['rating_sum_hundredths']-($previous ? (int)$vote['score_hundredths'] : 0)+($withdraw ? 0 : $score);
            $version = (int)($vote['version'] ?? 0)+1; $now=Data::now(); $active=$withdraw ? 'withdrawn' : 'active';
            $this->db->run('INSERT INTO lgc_post_votes(post_id,user_id,score_hundredths,state,version,rated_content_version,reason,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score_hundredths=VALUES(score_hundredths),state=VALUES(state),version=VALUES(version),rated_content_version=VALUES(rated_content_version),reason=VALUES(reason),updated_at=VALUES(updated_at)',[$id,$actor->id,$score,$active,$version,$state['content_version'],$reason,$now,$now]);
            $this->db->run('UPDATE lgc_post_state SET rating_count=?,rating_sum_hundredths=?,rating_version=rating_version+1,updated_at=? WHERE post_id=?',[$count,$sum,$now,$id]);
            $receipt = ['post_id'=>$id,'own_version'=>$version,'own_score'=>$withdraw ? null : number_format($score/100,2,'.',''),'count'=>$count,'average'=>$count ? number_format($sum/(100*$count),2,'.','') : null];
            $this->db->run('INSERT INTO lgc_vote_history(post_id,user_id,version,operation_key,payload_hash,actor_id,action,score_hundredths,state,rated_content_version,reason,receipt,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$actor->id,$version,$operation,$hash,$actor->id,$withdraw ? 'withdraw' : 'rate',$score,$active,$state['content_version'],$reason,Data::json($receipt),$now]);
            $sourceVersion=$this->db->source('posts',(int)$post['user_id'],true);
            $this->outbox->emit('rating.changed','post-vote:'.$id.':'.$actor->id,$version,['post_id'=>$id,'rater_id'=>$actor->id,'action'=>$withdraw ? 'withdraw' : 'rate','score'=>$receipt['own_score'],'reason'=>$reason,'source_version'=>$sourceVersion],(int)$post['user_id']);
            return $receipt;
        });
    }
    public function policy(Actor $actor, array $input): array
    {
        $actor->assert('rating.manage');
        $scope = $input['scope'] ?? [];
        if (array_diff(array_keys($scope),['discussion_ids','tag_ids']) || strlen(Data::json($scope)) > 16384) throw new DomainError('invalid_scope');
        $kind = $input['voter_kind'] ?? 'groups';
        if (!in_array($kind,['groups','registered'],true)) throw new DomainError('invalid_voter_kind');
        $groups = array_values(array_unique(array_map('intval',$input['group_ids'] ?? [])));
        if ($kind === 'groups' && !$groups) throw new DomainError('groups_required');
        $id = $input['id'] ?? Data::uuid(); $name = Data::name($input['name'] ?? ''); $now=Data::now();
        foreach (['discussion_ids','tag_ids'] as $key) if (isset($scope[$key])) { if (!is_array($scope[$key]) || count($scope[$key])>100) throw new DomainError('invalid_scope'); foreach ($scope[$key] as $value) if (!filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) throw new DomainError('invalid_scope'); $scope[$key]=array_values(array_unique(array_map('intval',$scope[$key]))); if (!$scope[$key]) unset($scope[$key]); }
        $priority=(int)Data::integer($input['priority'] ?? '0',true,10);
        if ($priority< -2147483648 || $priority>2147483647) throw new DomainError('invalid_priority');
        $old=$this->db->one('SELECT * FROM lgc_rating_policies WHERE public_id=?',[Data::bin($id)]);
        if (isset($input['id']) && !$old) throw new DomainError('policy_not_found',404);
        if ($old) {
            if ((int)($input['expected_version'] ?? 0)!==(int)$old['row_version']) throw new DomainError('version_conflict',409);
            $changed=$this->db->run('UPDATE lgc_rating_policies SET name=?,name_key=?,scope_spec=?,voter_kind=?,group_ids=?,priority=?,starts_at=?,ends_at=?,row_version=row_version+1,updated_at=? WHERE id=? AND row_version=?',[$name,Data::nameKey($name),Data::json($scope),$kind,Data::json($groups),$priority,Data::date($input['start_at'] ?? null),Data::date($input['stop_at'] ?? null),$now,$old['id'],$old['row_version']])->rowCount();
            if (!$changed) throw new DomainError('version_conflict',409);
        } else $this->db->run("INSERT INTO lgc_rating_policies(public_id,name,name_key,scope_spec,voter_kind,group_ids,priority,starts_at,ends_at,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,'active',?,?)",[Data::bin($id),$name,Data::nameKey($name),Data::json($scope),$kind,Data::json($groups),$priority,Data::date($input['start_at'] ?? null),Data::date($input['stop_at'] ?? null),$now,$now]);
        return ['id'=>$id];
    }
}
