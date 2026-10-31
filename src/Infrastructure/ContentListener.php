<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

final class ContentListener
{
    public function __construct(private Db $db, private Outbox $outbox, private \Psr\Log\LoggerInterface $logger) {}
    public function post($event): void
    {
        if (!isset($event->post) || !$event->post instanceof \Flarum\Post\CommentPost) return;
        $post=$event->post;
        try {
            $this->db->transaction(function () use ($post) {
                $state=$this->db->one('SELECT * FROM lgc_post_state WHERE post_id=? FOR UPDATE',[(int)$post->id]);
                $hash=hash('sha256',(string)$post->parsed_content); $edited=$state && $state['content_hash']!==$hash;
                if ($edited) $this->db->run('UPDATE lgc_post_state SET content_hash=?,content_version=content_version+1,updated_at=? WHERE post_id=?',[$hash,Data::now(),(int)$post->id]);
                $version=$this->db->source('posts',(int)$post->user_id,true);
                $this->outbox->emit('post.changed','native-post:'.$post->id,$version,['post_id'=>(int)$post->id,'discussion_id'=>(int)$post->discussion_id,'edited'=>(bool)$edited,'source_version'=>$version],(int)$post->user_id);
            });
        } catch (\Throwable $error) { $this->logger->error('Community source capture requires reconciliation',['error_type'=>get_class($error)]); }
    }
    public function discussion($event): void
    {
        if (!isset($event->discussion)) return;
        $trigger=Data::uuid();
        $this->outbox->enqueue('discussion.scan',['discussion_id'=>(int)$event->discussion->id,'after'=>0,'trigger'=>$trigger],'discussion:'.$trigger,0);
    }
    public function avatar($event): void
    {
        $user=$event->user;
        $this->db->transaction(function () use ($user) {
            $version=$this->db->source('profile',(int)$user->id,true);
            $coverage=$this->db->scalar("SELECT coverage_from FROM lgc_source_states WHERE source_key='avatar-coverage' AND user_id=0");
            if ($user->avatar_url && $coverage && $user->joined_at && $user->joined_at->format('Y-m-d H:i:s')>=$coverage) $this->db->run("INSERT IGNORE INTO lgc_user_facts(user_id,fact_key,occurred_at,source_event_key,evidence) VALUES(?,'avatar.first',?,?,?)",[(int)$user->id,Data::now(),Data::hash(['avatar',$user->id,$version]),Data::json(['coverage'=>'lifetime'])]);
            $this->outbox->emit('profile.changed','profile:'.$user->id,$version,['avatar_present'=>(bool)$user->avatar_url],(int)$user->id);
        });
    }
}
