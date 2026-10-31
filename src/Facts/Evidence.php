<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Facts;

use Local\GoodCommunity\Infrastructure\DomainError;

final class Evidence
{
    /** Deterministic shared-coverage greedy policy, separate from Boolean optimization. */
    public static function choose(array $requirements, int $budget=1000): array
    {
        if(!$requirements) return [];
        if(count($requirements)===1) {
            $requirement=reset($requirements); $required=(int)$requirement['required'];
            if($required>$budget) throw new DomainError('evidence_budget_exceeded');
            if(count($requirement['ids'])<$required) throw new DomainError('evidence_changed',409);
            $ids=$requirement['ids']; sort($ids,SORT_NUMERIC);
            return array_slice($ids,0,$required);
        }
        $remaining=[]; $covers=[];
        foreach ($requirements as $key=>$requirement) {
            $remaining[$key]=$requirement['required'];
            foreach ($requirement['ids'] as $id) $covers[$id][]=$key;
        }
        $heap=new \SplPriorityQueue(); $heap->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        foreach ($covers as $id=>$keys) $heap->insert($id,[count($keys),-$id]);
        $selected=[];
        while (array_sum($remaining)>0) {
            if ($heap->isEmpty()) throw new DomainError('evidence_changed',409);
            $entry=$heap->extract(); $best=$entry['data'];
            $benefit=count(array_filter($covers[$best],fn ($key)=>$remaining[$key]>0));
            if (!$benefit) continue;
            if ($benefit<$entry['priority'][0]) { $heap->insert($best,[$benefit,-$best]); continue; }
            $selected[$best]=true;
            foreach ($covers[$best] as $key) if ($remaining[$key]>0) $remaining[$key]--;
            if (count($selected)>$budget) throw new DomainError('evidence_budget_exceeded');
        }
        return array_keys($selected);
    }
}
