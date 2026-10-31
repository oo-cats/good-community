<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

use Local\GoodCommunity\Facts\QueryEngine;
use Local\GoodCommunity\Achievements\Achievements;
use Local\GoodCommunity\Tasks\Statistics;
use Local\GoodCommunity\Notifications\Notifications;

final class Worker
{
    private int $turn=0;
    public function __construct(private Db $db, private Outbox $outbox, private QueryEngine $queries, private Achievements $achievements, private Statistics $statistics, private ?Notifications $notifications=null, private ?\Local\GoodCommunity\Integrations\AcgnDatabase $acgn=null) {}
    public function run(int $limit=50, float $seconds=10): array
    {
        $result=['processed'=>0,'failed'=>0]; $until=microtime(true)+$seconds;
        while ($result['processed']+$result['failed']<$limit && microtime(true)<$until) {
            $job=$this->take(); if (!$job) break;
            try {
                $this->handle($job['job_type'],Data::decode($job['payload']));
                $this->db->run("UPDATE lgc_jobs SET status='done',lease_token=NULL,lease_until=NULL,updated_at=? WHERE id=? AND lease_token=?",[Data::now(),$job['id'],$job['lease_token']]);
                $result['processed']++;
            } catch (\Throwable $error) {
                $preparing=$error instanceof DomainError && $error->status===202;
                $failed=!$preparing && (int)$job['attempts']>=5;
                $delay=min(60,2**min(6,(int)$job['attempts']));
                $this->db->run('UPDATE lgc_jobs SET status=?,lease_token=NULL,lease_until=NULL,available_at=?,last_error_code=?,updated_at=? WHERE id=? AND lease_token=?',[$failed ? 'failed' : 'pending',gmdate('Y-m-d H:i:s',time()+$delay),$error instanceof DomainError ? $error->reason : substr(str_replace('\\','.',get_class($error)),0,64),Data::now(),$job['id'],$job['lease_token']]);
                $result['failed']++;
            }
        }
        return $result;
    }
    private function take(): ?array
    {
        return $this->db->transaction(function () {
            $this->db->run("UPDATE lgc_jobs SET status='pending',lease_token=NULL,lease_until=NULL WHERE status='running' AND lease_until<UTC_TIMESTAMP(6) LIMIT 20");
            $lanes=[0,1,0,2,3]; $preferred=$lanes[$this->turn++%count($lanes)]; $job=null;
            foreach (array_unique([$preferred,...$lanes]) as $lane) { $job=$this->db->one("SELECT * FROM lgc_jobs WHERE status='pending' AND lane=? AND available_at<=UTC_TIMESTAMP(6) ORDER BY available_at,id LIMIT 1 FOR UPDATE",[$lane]); if ($job) break; }
            if (!$job) return null;
            $token=random_bytes(16);
            $this->db->run("UPDATE lgc_jobs SET status='running',lease_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 SECOND),attempts=attempts+1,updated_at=? WHERE id=?",[$token,Data::now(),$job['id']]);
            return array_replace($job,['lease_token'=>$token,'attempts'=>(int)$job['attempts']+1]);
        });
    }
    private function handle(string $type, array $payload): void
    {
        if ($type==='acgn.scan') {
            if (!$this->acgn) throw new DomainError('acgn_source_unavailable',202);
            $this->acgn->fanout($payload); return;
        }
        if ($type==='reconcile.scan') {
            $users=$this->db->all('SELECT id FROM '.$this->db->native('users').' WHERE id>? ORDER BY id LIMIT 200',[$payload['after_user']]);
            foreach ($users as $user) $this->outbox->enqueue('reconcile.user',['user_id'=>(int)$user['id'],'after'=>0,'trigger'=>$payload['trigger']],'reconcile-user:'.$payload['trigger'].':'.$user['id'],3);
            if (count($users)===200) { $next=(int)end($users)['id']; $this->outbox->enqueue('reconcile.scan',array_replace($payload,['after_user'=>$next]),'reconcile-scan:'.$payload['trigger'].':'.$next,3); }
            return;
        }
        if ($type==='reconcile.user') {
            $user=(int)$payload['user_id'];
            if (!(int)$payload['after']) {
                $this->queries->invalidateUser($user);
                $this->outbox->enqueue('achievement.user',['user_id'=>$user],'reconcile-achievements:'.$payload['trigger'].':'.$user,1);
            }
            return;
        }
        if ($type==='discussion.scan') {
            $rows=$this->db->all('SELECT id,user_id FROM '.$this->db->native('posts').' WHERE discussion_id=? AND id>? ORDER BY id LIMIT 200',[$payload['discussion_id'],$payload['after']]);
            foreach ($rows as $post) { $version=$this->db->source('posts',(int)$post['user_id'],true); $this->outbox->emit('post.changed','discussion-source:'.$payload['trigger'].':'.$post['id'],$version,['post_id'=>(int)$post['id'],'source_version'=>$version,'discussion_id'=>$payload['discussion_id'],'edited'=>false],(int)$post['user_id']); }
            if (count($rows)===200) { $next=(int)end($rows)['id']; $this->outbox->enqueue('discussion.scan',array_replace($payload,['after'=>$next]),'discussion:'.$payload['trigger'].':'.$next,0); }
            return;
        }
        if ($type==='counter') { $this->queries->step((int)$payload['counter_id']); return; }
        if ($type==='stats') { $this->statistics->project((int)$payload['claim_id']); return; }
        if ($type==='stats.rebuild') { $this->statistics->rebuildStep($payload); return; }
        if ($type==='stats.prune') { $this->statistics->prune((int)$payload['task_id'],(int)$payload['after']); return; }
        if ($type==='notify.fanout') { $this->notifications?->fanout($payload); return; }
        if ($type==='notify.event') { $event=$this->db->one('SELECT * FROM lgc_events WHERE id=?',[$payload['event_id']]); if ($event) $this->notifications?->event($event); return; }
        if ($type==='achievement.user') { $this->achievements->refreshUser((int)$payload['user_id'],$payload['dependencies'] ?? null); return; }
        if ($type==='achievement.scan') {
            $users=$this->db->all('SELECT id FROM '.$this->db->native('users').' WHERE id>? ORDER BY id LIMIT 200',[(int)$payload['after']]);
            foreach ($users as $user) $this->outbox->enqueue('achievement.user',['user_id'=>(int)$user['id']],'achievement-user:'.$payload['event_id'].':'.$user['id'],0);
            if (count($users)===200) $this->outbox->enqueue('achievement.scan',['after'=>(int)end($users)['id'],'event_id'=>$payload['event_id']],'achievement-scan:'.$payload['event_id'].':'.end($users)['id'],1);
            return;
        }
        if ($type!=='event') throw new DomainError('unknown_job_type');
        $event=$this->db->one('SELECT * FROM lgc_events WHERE id=?',[$payload['event_id']]); if (!$event) return;
        $data=Data::decode($event['payload']); $name=$event['event_type']; $user=(int)$event['subject_user_id'];
        if ($name==='acgn.changed' && !$user) $this->outbox->enqueue('acgn.scan',$data+['event_id'=>(int)$event['id'],'after'=>0],'acgn-scan:'.$event['id'].':0',1);
        if ($name==='money.posted') foreach ($data['user_ids'] ?? [$user] as $target) {
            $this->outbox->enqueue('achievement.user',['user_id'=>(int)$target,'dependencies'=>['source:wallet']],'money-achievements:'.$event['id'].':'.$target,0);
        }
        if (in_array($name,['rating.changed','fields.changed','post.changed'],true)) $this->queries->changed($user,(int)$data['post_id'],isset($data['source_version']) ? (int)$data['source_version'] : null);
        if (isset($data['claim_id'])) $this->outbox->enqueue('stats',['claim_id'=>(int)$data['claim_id']],'stats-event:'.$event['id'],3);
        if ($name==='achievement.published') $this->outbox->enqueue('achievement.scan',['after'=>0,'event_id'=>(int)$event['id']],'achievement-scan:'.$event['id'],1);
        if ($name==='task.published') {
            $task=$this->db->one('SELECT statistics_generation FROM lgc_tasks WHERE id=?',[$data['task_id']]); $this->statistics->initialize((int)$data['task_id'],(int)$task['statistics_generation']);
        }
        $dependencies=match ($name) { 'rating.changed'=>['source:rating'], 'fields.changed'=>array_map(fn ($id)=>'field:'.$id,$data['field_ids'] ?? []), 'post.changed','counter.ready'=>['source:posts'], 'profile.changed'=>['source:profile'], 'money.posted'=>['source:wallet'], 'task.completed','task.completion.changed'=>['task:'.Data::id($this->db->scalar('SELECT public_id FROM lgc_tasks WHERE id=?',[$data['task_id']]))], 'achievement.holding.changed'=>['achievement:'.Data::id($this->db->scalar('SELECT public_id FROM lgc_achievements WHERE id=?',[$data['achievement_id']]))], default=>$data['dependencies'] ?? [] };
        if ($user && $dependencies && $name!=='money.posted') {
            $this->outbox->enqueue('achievement.user',['user_id'=>$user,'dependencies'=>$dependencies],'refresh-user:'.$event['id'],0);
        }
        $this->outbox->enqueue('notify.event',['event_id'=>(int)$event['id']],'notify-event:'.$event['id'],2);
    }
}
