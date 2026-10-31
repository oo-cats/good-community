<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;
final class Upgrade {
    public static function apply(Db $db): void {
        foreach(['lgc_tasks'=>['is_pinned'=>'TINYINT UNSIGNED NOT NULL DEFAULT 0'],'lgc_task_versions'=>['reward_access'=>'JSON NULL'],'lgc_achievement_versions'=>['reward_access'=>'JSON NULL']] as $table=>$columns) foreach($columns as $name=>$definition) {
            if(!$db->scalar('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$db->prefix.$table,$name])) { $db->run('ALTER TABLE '.$table.' ADD '.$name.' '.$definition); if($table==='lgc_tasks' && $name==='is_pinned') $db->run("UPDATE lgc_tasks SET is_pinned=1 WHERE status='active'"); }
        }
        $sql=file_get_contents(dirname(__DIR__,2).'/resources/schema.sql');
        foreach(['lgc_permission_groups','lgc_access_sources','lgc_access_memberships'] as $table) if(!$db->scalar('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$db->prefix.$table])) {
            preg_match('/CREATE TABLE '.$table.' \([\s\S]*?\) ENGINE=[^;]+;/',$sql,$match); $db->run($match[0]);
        }
        foreach(['lgc_tasks'=>['pinned_tasks','(status,is_pinned,id)'],'lgc_entries'=>['wallet_activity','(user_id,transaction_id,line_no)'],'lgc_transactions'=>['post_tips','(related_post_id,kind,id)'],'lgc_achievement_grants'=>['achievement_population','(achievement_id,active_sources,user_id)']] as $table=>[$index,$columns]) if(!$db->scalar('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',[$db->prefix.$table,$index])) $db->run('ALTER TABLE '.$table.' ADD KEY '.$index.' '.$columns);
        OptimizeSchema::apply($db);
    }
}
