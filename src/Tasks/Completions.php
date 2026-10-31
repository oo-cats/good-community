<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;
use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Outbox};

/** Successful receipts do not replay their original facts. */
final class Completions
{
    public function __construct(private Db $db, private Outbox $outbox) {}
    public function completed(array $params,array $context): bool
    {
        if (!empty($context['lock'])) $this->db->sourceLock('task-completions',(int)$context['user']);
        $task=$this->db->one('SELECT id,period_seconds FROM lgc_tasks WHERE public_id=?',[Data::bin($params['task_id'])]);
        if (!$task) throw new DomainError('task_unavailable',202);
        $where='task_id=? AND user_id=? AND active=1'; $args=[$task['id'],$context['user']];
        if ((int)$task['period_seconds']>0 || ($params['scope'] ?? '')==='current_period') {
            if ($context['start']) { $where.=' AND claimed_at>=?'; $args[]=$context['start']; }
            if ($context['end']) { $where.=' AND claimed_at<?'; $args[]=$context['end']; }
        }
        return (bool)$this->db->scalar('SELECT id FROM lgc_task_claims WHERE '.$where.' LIMIT 1'.(!empty($context['lock']) ? ' LOCK IN SHARE MODE' : ''),$args);
    }
    public function revoke(Actor $actor,int $claimId,array $input): array
    {
        $actor->assert('*');
        $request=strtolower((string)($input['request_id'] ?? '')); Data::bin($request);
        $reason=trim((string)($input['reason'] ?? ''));
        if ($reason==='' || mb_strlen($reason)>500) throw new DomainError('revocation_reason_required',422);
        return $this->db->transaction(function () use ($actor,$claimId,$request,$reason) {
            $source=$this->db->one('SELECT task_id,user_id FROM lgc_task_claims WHERE id=?',[$claimId]);
            if (!$source) throw new DomainError('claim_not_found',404);
            $this->db->one('SELECT id FROM lgc_tasks WHERE id=? LOCK IN SHARE MODE',[$source['task_id']]);
            $this->db->sourceLock('task-completions',(int)$source['user_id'],true);
            $claim=$this->db->one('SELECT * FROM lgc_task_claims WHERE id=? FOR UPDATE',[$claimId]);
            $aggregate='completion-revoke:'.$actor->id.':'.$request;
            $payload=['claim_id'=>$claimId,'task_id'=>(int)$claim['task_id'],'actor_id'=>$actor->id,'reason'=>$reason];
            $old=$this->db->one('SELECT payload_hash FROM lgc_events WHERE event_key=?',[Data::hash(['task.completion.changed',$aggregate,1])]);
            if ($old && $old['payload_hash']!==Data::hash($payload)) throw new DomainError('operation_conflict',409);
            if ($claim['active']) {
                $this->db->run('UPDATE lgc_task_claims SET active=0,validity_version=validity_version+1 WHERE id=?',[$claimId]);
                $this->db->source('task-completions',(int)$claim['user_id'],true);
            }
            $this->outbox->emit('task.completion.changed',$aggregate,1,$payload,(int)$claim['user_id']);
            return ['claim_id'=>$claimId,'active'=>false,'receipt'=>Data::decode($claim['receipt'])];
        });
    }
    public function listing(Actor $actor,string $task,array $options): array
    {
        $actor->assert('*'); $limit=min(50,max(1,(int)($options['limit'] ?? 20)));
        $rows=$this->db->all('SELECT c.id,c.user_id,u.username,c.active,c.claimed_at,p.occurrence FROM lgc_task_claims c JOIN lgc_tasks t ON t.id=c.task_id JOIN lgc_task_periods p ON p.id=c.period_id LEFT JOIN '.$this->db->native('users').' u ON u.id=c.user_id WHERE t.public_id=? AND c.id>? ORDER BY c.id LIMIT '.($limit+1),[Data::bin($task),max(0,(int)($options['after'] ?? 0))]);
        $items=array_slice($rows,0,$limit);
        return ['items'=>$items,'next'=>count($rows)>$limit ? (string)end($items)['id'] : null];
    }
}
