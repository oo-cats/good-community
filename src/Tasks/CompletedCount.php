<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;

use Local\GoodCommunity\Infrastructure\{Data,Db,DomainError};

/** Lifetime valid receipts, not periods. No duplicate progress table. */
final class CompletedCount
{
    public function __construct(private Db $db,private Lifecycle $lifecycle) {}
    public function count(array $params,array $context): int
    {
        $task=$this->db->scalar('SELECT id FROM lgc_tasks WHERE public_id=?',[Data::bin($params['task_id'])]);
        if(!$task) throw new DomainError('task_unavailable',202);
        $args=[$context['user'],$task];
        if(empty($context['lock'])) return (int)$this->db->scalar('SELECT COUNT(*) FROM lgc_task_claims WHERE user_id=? AND task_id=? AND active=1',$args);
        // Current policies may invalidate receipts before asynchronous reconciliation runs.
        // Stable retained completions need only a locked receipt; mutable rules are verified.
        $after=0; $valid=0; $checked=0;
        do {
            // Bound receipts before joining definitions. MySQL 5.7 otherwise may start
            // from user_tasks and sort the entire history on every keyset page.
            $rows=$this->db->all('SELECT * FROM lgc_task_claims FORCE INDEX (completed_claim_history) WHERE user_id=? AND task_id=? AND id>? ORDER BY id LIMIT 201 LOCK IN SHARE MODE',[...$args,$after]);
            $more=count($rows)>200;
            $batch=array_slice($rows,0,200); $instances=array_values(array_unique(array_column($batch,'user_task_id'))); $rules=[];
            if($instances) foreach($this->db->all('SELECT u.id,p.starts_at,p.ends_at,v.goal,v.policies FROM lgc_user_tasks u JOIN lgc_task_periods p ON p.id=u.period_id JOIN lgc_task_versions v ON v.task_id=p.task_id AND v.version=p.version WHERE u.id IN ('.implode(',',array_fill(0,count($instances),'?')).') LOCK IN SHARE MODE',$instances) as $rule) $rules[$rule['id']]=$rule;
            foreach($batch as $row) {
                if(++$checked>10000) throw new DomainError('completion_count_budget',202);
                if(!isset($rules[$row['user_task_id']])) throw new DomainError('completion_rule_unavailable',202);
                $row+=$rules[$row['user_task_id']];
                $retained=Data::decode($row['policies'])['completion']==='retain';
                if($retained ? (bool)$row['active'] : $this->lifecycle->isValidCompletion($row)) $valid++;
                $after=(int)$row['id'];
            }
        } while($more);
        return $valid;
    }
}
