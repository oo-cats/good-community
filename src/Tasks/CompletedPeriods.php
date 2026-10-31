<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;

use Local\GoodCommunity\Infrastructure\{Data,Db,DomainError};

/** Derived period count; no additional progress is persisted. */
final class CompletedPeriods
{
    public function __construct(private Db $db,private Lifecycle $lifecycle) {}
    public function count(array $params,array $context): int
    {
        $lock=!empty($context['lock']);
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
        // Drive from the bounded period range rather than the user's lifetime history.
        $periods=$this->db->all('SELECT u.id,p.occurrence,p.starts_at,p.ends_at FROM lgc_task_periods p FORCE INDEX(task_occurrence) STRAIGHT_JOIN lgc_user_tasks u FORCE INDEX(user_period) ON u.period_id=p.id AND u.user_id=? WHERE p.task_id=? AND p.occurrence BETWEEN ? AND ? AND p.starts_at>=? AND p.ends_at<=? AND TIMESTAMPDIFF(SECOND,p.starts_at,p.ends_at)=?'.($lock ? ' AND u.claim_count>0' : ' AND u.active_claim_count>0').' ORDER BY p.occurrence'.($lock ? ' LOCK IN SHARE MODE' : ''),[$context['user'],$task['id'],$first,$last,$from,$to,$duration]);
        if(!$lock || !$periods) return count($periods);
        $pending=[]; foreach($periods as $period) $pending[(int)$period['id']]=$period+['cursor_time'=>$period['starts_at'],'cursor_id'=>0];
        $valid=0; $examined=0;
        while($pending && $examined<200) {
            // One candidate per period in a batch: duplicates in one day cannot crowd out other days.
            $batch=array_slice($pending,0,200-$examined,true); $selects=[]; $args=[];
            foreach($batch as $instance=>$period) {
                $selects[]='(SELECT c.*,p.starts_at,p.ends_at,v.goal,v.policies FROM lgc_task_claims c FORCE INDEX(completed_task_lookup) STRAIGHT_JOIN lgc_user_tasks u ON u.id=c.user_task_id STRAIGHT_JOIN lgc_task_periods p ON p.id=u.period_id STRAIGHT_JOIN lgc_task_versions v ON v.task_id=c.task_id AND v.version=p.version WHERE c.user_id=? AND c.task_id=? AND c.active=1 AND c.claimed_at>=? AND c.claimed_at<? AND c.user_task_id=? AND (c.claimed_at>? OR (c.claimed_at=? AND c.id>?)) ORDER BY c.claimed_at,c.id LIMIT 1 LOCK IN SHARE MODE)';
                array_push($args,$context['user'],$task['id'],$period['starts_at'],$period['ends_at'],$instance,$period['cursor_time'],$period['cursor_time'],$period['cursor_id']);
            }
            $claims=$this->db->all(implode(' UNION ALL ',$selects),$args); $found=[];
            foreach($claims as $claim) {
                $examined++; $instance=(int)$claim['user_task_id']; $found[$instance]=true;
                if($this->lifecycle->isValidCompletion($claim)) { $valid++; unset($pending[$instance]); }
                else { $pending[$instance]['cursor_time']=$claim['claimed_at']; $pending[$instance]['cursor_id']=(int)$claim['id']; }
            }
            foreach($batch as $instance=>$period) if(!isset($found[$instance])) unset($pending[$instance]);
        }
        if($pending) throw new DomainError('prerequisite_verification_budget',202);
        return $valid;
    }
}
