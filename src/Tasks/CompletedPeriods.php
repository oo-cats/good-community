<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;

use Local\GoodCommunity\Infrastructure\{Data,Db,DomainError};

/** Derived period count; no additional progress is persisted. */
final class CompletedPeriods
{
    public function __construct(private Db $db) {}
    public function count(array $params,array $context): int
    {
        $lock=!empty($context['lock']);
        if ($lock) $this->db->sourceLock('task-completions',(int)$context['user']);
        if(empty($context['start']) || empty($context['end'])) throw new DomainError('period_window_required',202);
        $task=$this->db->one('SELECT id,start_at,stop_at,period_seconds FROM lgc_tasks WHERE public_id=?'.($lock ? ' LOCK IN SHARE MODE' : ''),[Data::bin($params['task_id'])]);
        if(!$task || !(int)$task['period_seconds']) throw new DomainError('periodic_task_required',202);
        $origin=strtotime($task['start_at'].' UTC'); $duration=(int)$task['period_seconds'];
        $start=strtotime($context['start'].' UTC'); $end=strtotime($context['end'].' UTC');
        if($task['stop_at']) $end=min($end,strtotime($task['stop_at'].' UTC'));
        // Only complete source periods within the UTC half-open window count.
        $first=$start<=$origin ? 0 : intdiv($start-$origin+$duration-1,$duration);
        $last=$end<=$origin ? -1 : intdiv($end-$origin,$duration)-1;
        if($last<$first) return 0;
        if($last-$first+1>366) throw new DomainError('period_window_budget',202);
        $from=gmdate('Y-m-d H:i:s',$origin+$first*$duration); $to=gmdate('Y-m-d H:i:s',$origin+($last+1)*$duration);
        $rows=$this->db->all('SELECT c.id FROM lgc_task_periods p FORCE INDEX(task_occurrence) STRAIGHT_JOIN lgc_task_claims c FORCE INDEX(user_period) ON c.period_id=p.id AND c.user_id=? WHERE p.task_id=? AND p.occurrence BETWEEN ? AND ? AND c.active=1 AND p.starts_at>=? AND p.ends_at<=? AND TIMESTAMPDIFF(SECOND,p.starts_at,p.ends_at)=?'.($lock ? ' LOCK IN SHARE MODE' : ''),[$context['user'],$task['id'],$first,$last,$from,$to,$duration]);
        return count($rows);
    }
}
