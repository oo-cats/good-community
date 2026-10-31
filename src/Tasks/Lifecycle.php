<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;

use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,Outbox};
use Local\GoodCommunity\Facts\{FactEngine,QueryEngine};
use Local\GoodCommunity\Currency\Ledger;

final class Lifecycle
{
    private array $checking=[];
    public function __construct(private Db $db, private FactEngine $facts, private QueryEngine $queries, private Ledger $ledger, private Outbox $outbox,private ?\Local\GoodCommunity\Access\Rewards $access=null) {}
    public function affected(int $user, ?int $post=null, int $after=0): array
    {
        if ($post!==null) return array_map('intval',array_column($this->db->all("SELECT DISTINCT claim_id id FROM lgc_task_progress_uses WHERE source_key='posts' AND object_id=? AND user_id=? AND claim_id>? ORDER BY claim_id LIMIT 200",[$post,$user,$after]),'id'));
        return array_map('intval',array_column($this->db->all('SELECT id FROM lgc_task_claims WHERE user_id=? AND id>? ORDER BY id LIMIT 200',[$user,$after]),'id'));
    }
    /** Payouts may not trust an upstream receipt while asynchronous repair is pending. */
    public function completed(array $params, array $context): bool
    {
        $task=$this->db->one('SELECT id,period_seconds FROM lgc_tasks WHERE public_id=?',[Data::bin($params['task_id'])]);
        $where='c.user_id=? AND c.task_id=? AND c.active=1'; $args=[$context['user'],$task['id']];
        if ((int)$task['period_seconds']>0 || ($params['scope'] ?? '')==='current_period') {
            if ($context['start']) { $where.=' AND c.claimed_at>=?'; $args[]=$context['start']; }
            if ($context['end']) { $where.=' AND c.claimed_at<?'; $args[]=$context['end']; }
        }
        $claims=$this->db->all('SELECT c.*,p.starts_at,p.ends_at,v.goal,v.policies FROM lgc_task_claims c JOIN lgc_user_tasks u ON u.id=c.user_task_id JOIN lgc_task_periods p ON p.id=u.period_id JOIN lgc_task_versions v ON v.task_id=c.task_id AND v.version=p.version WHERE '.$where.' ORDER BY c.id DESC LIMIT 201 LOCK IN SHARE MODE',$args);
        foreach (array_slice($claims,0,200) as $claim) {
            if($this->isValidCompletion($claim)) return true;
        }
        if (count($claims)>200) throw new \Local\GoodCommunity\Infrastructure\DomainError('prerequisite_verification_budget',202);
        return false;
    }
    private function valid(array $claim, bool $authoritative): bool
    {
        if (Data::decode($claim['policies'])['completion']==='retain') return true;
        $evidence=Data::decode($claim['evidence']); $goal=Data::decode($claim['goal']);
        $context=['user'=>(int)$claim['user_id'],'start'=>$claim['starts_at'],'end'=>$claim['ends_at'],'now'=>Data::now(),'lock'=>$authoritative];
        $clause=$goal[$evidence['clause']]; $atoms=$this->facts->atoms([$clause]); $remaining=[]; $verified=[];
        $count=function(array $query) use(&$verified,$context,$evidence): int {
            $key=Data::hash($query);
            return $verified[$key] ??= count($this->queries->verify($query,$context,$evidence['post_ids']));
        };
        foreach($clause as $term) {
            $atom=$atoms[$term['atom_id']]; $required=$this->facts->consumable($atom,$term['negated']);
            // A current, locked set of N witnesses proves >=N; it does not prove an exact total.
            if($required!==null) { if($count($atom['params']['left']['params']['query'])<$required) return false; }
            else $remaining[]=$term;
        }
        if($remaining && !$this->facts->evaluate([$remaining],$context,null,$authoritative)['truth']) return false;
        foreach ($evidence['requirements'] as $requirement) if($count($requirement['query'])<$requirement['required']) return false;
        return true;
    }
    /** @internal Rows must come from the database, never from API request data. */
    public function isValidCompletion(array $claim): bool
    {
        if(isset($this->checking[$claim['id']])) throw new \Local\GoodCommunity\Infrastructure\DomainError('dependency_recursion',202);
        $this->checking[$claim['id']]=true;
        try { return $this->valid($claim,true); }
        finally { unset($this->checking[$claim['id']]); }
    }
    public function reconcile(int $claimId): void
    {
        $source=$this->db->one('SELECT task_id FROM lgc_task_claims WHERE id=?',[$claimId]); if (!$source) return;
        $this->db->transaction(function () use ($claimId,$source) {
            $this->db->one('SELECT id FROM lgc_tasks WHERE id=? LOCK IN SHARE MODE',[$source['task_id']]);
            $claim=$this->db->one('SELECT c.*,p.starts_at,p.ends_at,v.goal,v.policies FROM lgc_task_claims c JOIN lgc_user_tasks u ON u.id=c.user_task_id JOIN lgc_task_periods p ON p.id=u.period_id JOIN lgc_task_versions v ON v.task_id=p.task_id AND v.version=p.version WHERE c.id=? FOR UPDATE',[$claimId]);
            $policies=Data::decode($claim['policies']);
            if ($policies['completion']==='retain') return;
            $valid=$this->valid($claim,true);
            if ($valid===(bool)$claim['active']) return;
            // Once a reward has been reversed, restoring facts does not issue money again.
            if (!$valid && $policies['reward']==='reverse') {
                if($claim['transaction_id']) { $transaction=$this->db->scalar('SELECT public_id FROM lgc_transactions WHERE id=?',[$claim['transaction_id']]); $this->ledger->reverse(Actor::automation(),Data::id($transaction)); }
                $this->access?->revoke((int)$claim['user_id'],'claim:'.$claimId);
            }
            $delta=(int)$valid-(int)$claim['active'];
            $this->db->run('UPDATE lgc_task_claims SET active=?,validity_version=validity_version+1 WHERE id=?',[(int)$valid,$claimId]);
            $this->db->run('UPDATE lgc_user_tasks SET active_claim_count=active_claim_count+? WHERE id=?',[$delta,$claim['user_task_id']]);
            $this->outbox->emit('task.completion.changed','claim:'.$claimId,(int)$claim['validity_version']+1,['claim_id'=>$claimId,'task_id'=>(int)$claim['task_id']],(int)$claim['user_id']);
        });
    }
}
