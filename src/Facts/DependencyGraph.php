<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Facts;

use Local\GoodCommunity\Infrastructure\{Data,Db,DomainError};

final class DependencyGraph
{
    public function __construct(private Db $db, private FactEngine $facts) {}
    /** Caller holds the internal root row through publication and this check. */
    public function validate(string $kind, string $id, array $goal): void
    {
        $id=Data::id(Data::bin($id));
        $edges=[];
        $rows=$this->db->all("SELECT 'task' kind,t.public_id,v.goal FROM lgc_tasks t JOIN lgc_task_versions v ON v.task_id=t.id WHERE v.state='published' AND (v.version=t.latest_version OR EXISTS(SELECT 1 FROM lgc_task_periods p WHERE p.task_id=t.id AND p.version=v.version)) UNION ALL SELECT 'achievement' kind,a.public_id,v.goal FROM lgc_achievements a JOIN lgc_achievement_versions v ON v.achievement_id=a.id AND v.version=a.latest_version WHERE v.goal IS NOT NULL");
        $goals=$goal; foreach($rows as $row) array_push($goals,...Data::decode($row['goal']));
        $atoms=$this->facts->atoms($goals);
        foreach ($rows as $row) {
            $uuid=Data::id($row['public_id']);
            if($kind==='achievement' && $row['kind']===$kind && $uuid===$id) continue;
            $node=$row['kind'].':'.$uuid;
            foreach ($this->facts->references(Data::decode($row['goal']),$atoms) as [$target,$reference]) $edges[$node][]=$target.':'.$reference;
        }
        foreach ($this->facts->references($goal,$atoms) as [$target,$reference]) $edges[$kind.':'.$id][]=$target.':'.$reference;
        $color=[]; $stack=[];
        $visit=function ($node) use (&$visit,&$color,&$stack,$edges) {
            if (($color[$node] ?? 0)===1) throw new DomainError('dependency_cycle',422,'依赖形成循环：'.implode(' → ',[...$stack,$node]));
            if (($color[$node] ?? 0)===2) return;
            $color[$node]=1; $stack[]=$node;
            foreach (array_unique($edges[$node] ?? []) as $next) $visit($next);
            array_pop($stack); $color[$node]=2;
        };
        foreach (array_keys($edges) as $node) $visit($node);
    }
}
