<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

use Local\GoodCommunity\Facts\{FactEngine,PostSource,QueryEngine};

/** Run with every application/worker process quiesced and a verified backup. */
final class OptimizeSchema
{
    public const DROPS=[
        'wallets'=>['version'],'transactions'=>['status','created_at'],
        'user_tasks'=>['calculation_state','progress','dependencies','evaluated_at','row_version'],
        'task_versions'=>['goal_hash'],'atoms'=>['created_by'],'bindings'=>['definition_hash'],
        'source_states'=>['generation','state'],'counter_windows'=>['phase','next_check_at'],
        'counter_contributions'=>['truth','source_version'],'post_fields'=>['permission_key'],
        'post_state'=>['author_id'],'achievements'=>['draft_version'],
        'achievement_versions'=>['goal_hash'],'task_stat_members'=>['successful_claims'],
    ];
    public static function apply(Db $db): void
    {
        $columns=[]; foreach($db->all('SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE()') as $row) $columns[$row['table_name']][$row['column_name']]=true;
        $indexes=[]; foreach($db->all('SELECT table_name,index_name FROM information_schema.statistics WHERE table_schema=DATABASE()') as $row) $indexes[$row['table_name']][$row['index_name']]=true;
        if(isset($columns[$db->prefix.'lgc_counter_contributions']['truth'])) {
            do { $deleted=$db->run('DELETE FROM lgc_counter_contributions WHERE truth=0 LIMIT 1000')->rowCount(); } while($deleted===1000);
            // Rebuild rather than carrying potentially stale legacy cached totals.
            $db->run("UPDATE lgc_counter_windows SET state='preparing',cursor_id=0,generation=generation+1");
        }
        $dropIndexes=['counter_contributions'=>['eligible_objects'],'counter_windows'=>['due_counters'],'user_tasks'=>['user_task_list'],'post_state'=>['author_post_state']];
        foreach(self::DROPS as $table=>$names) {
            $full=$db->prefix.'lgc_'.$table; $changes=[];
            foreach($dropIndexes[$table] ?? [] as $index) if(isset($indexes[$full][$index])) $changes[]='DROP INDEX '.$index;
            foreach($names as $name) if(isset($columns[$full][$name])) $changes[]='DROP COLUMN '.$name;
            if($changes) $db->run('ALTER TABLE lgc_'.$table.' '.implode(',',$changes));
        }
        if(!isset($indexes[$db->prefix.'lgc_task_claims']['completed_claim_history'])) $db->run('ALTER TABLE lgc_task_claims ADD KEY completed_claim_history (user_id,task_id,id)');
        // Existing automatic definitions gain the same dependency index as newly published ones.
        $facts=new FactEngine($db,new QueryEngine($db,new PostSource($db),new Outbox($db)));
        $after=0;
        do {
            $rows=$db->all("SELECT a.id,a.latest_version,v.goal FROM lgc_achievements a JOIN lgc_achievement_versions v ON v.achievement_id=a.id AND v.version=a.latest_version WHERE a.id>? AND v.mode='automatic' ORDER BY a.id LIMIT 200",[$after]);
            foreach($rows as $row) { $facts->bind('achievement',(int)$row['id'],(int)$row['latest_version'],Data::decode($row['goal'])); $after=(int)$row['id']; }
        } while(count($rows)===200);
    }
}
