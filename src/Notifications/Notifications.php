<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Notifications;

use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,Outbox};
use Local\GoodCommunity\Tasks\TaskService;
use Flarum\User\User;
use Flarum\Post\CommentPost;

final class Notifications
{
    public function __construct(private Db $db, private TaskService $tasks, private Outbox $outbox) {}
    public function deliver(int $event, int $userId, string $type, array $data): void
    {
        $user=User::find($userId); if (!$user) return;
        $sent=$this->db->transaction(function () use ($event,$userId,$type,$data,$user) {
            $this->db->run("INSERT IGNORE INTO lgc_notification_deliveries(event_id,user_id,notification_type,channel,status) VALUES(?,?,?,'alert','pending')",[$event,$userId,$type]);
            $delivery=$this->db->one("SELECT * FROM lgc_notification_deliveries WHERE event_id=? AND user_id=? AND notification_type=? AND channel='alert' FOR UPDATE",[$event,$userId,$type]);
            if ($delivery['status']!=='pending') return false;
            $allowed=$user->shouldAlert($type);
            if (isset($data['post_id'])) $allowed=$allowed && CommentPost::whereVisibleTo($user)->where('id',$data['post_id'])->exists();
            if (isset($data['task_id'])) { try { $allowed=$allowed && $this->tasks->visible(Actor::fromUser($user),$this->tasks->find($data['task_id'])); } catch (\Throwable) { $allowed=false; } }
            $native=null;
            if ($allowed) {
                // Native notification + unique delivery receipt use the same connection/transaction.
                $this->db->run('INSERT INTO '.$this->db->native('notifications').'(user_id,from_user_id,type,subject_id,data,created_at) VALUES(?,NULL,?,?,?,?)',[$userId,$type,$event,Data::json($data+['recipient_id'=>$userId,'event_id'=>$event]),Data::now()]);
                $native=(int)$this->db->pdo->lastInsertId();
            }
            $this->db->run("UPDATE lgc_notification_deliveries SET status=?,native_notification_id=?,delivered_at=? WHERE event_id=? AND user_id=? AND notification_type=? AND channel='alert'",[$allowed ? 'sent' : 'skipped',$native,Data::now(),$event,$userId,$type]);
            return $allowed;
        });
        if ($sent) { $cache=resolve('cache.store'); $cache->forget('user.'.$userId.'.unread_notification_count'); $cache->forget('user.'.$userId.'.new_notification_count'); }
    }
    public function fanout(array $payload): void
    {
        $event=$this->db->one('SELECT * FROM lgc_events WHERE id=?',[$payload['event_id']]); if (!$event) return;
        $data=Data::decode($event['payload']); $after=(int)($payload['after'] ?? 0);
        if ($event['event_type']==='task.published') {
            $task=$this->db->one('SELECT * FROM lgc_tasks WHERE id=?',[$data['task_id']]);
            if (!$task || $task['status']!=='active' || ($task['stop_at'] && $task['stop_at']<=Data::now())) return;
            $ids=array_column($this->db->all('SELECT id FROM '.$this->db->native('users').' WHERE id>? ORDER BY id LIMIT 200',[$after]),'id');
            foreach ($ids as $id) $this->deliver((int)$event['id'],(int)$id,'communityTask',['task_id'=>Data::id($task['public_id']),'title'=>'新任务：'.$task['name'],'url'=>'/community/tasks/'.Data::id($task['public_id'])]);
        } elseif ($event['event_type']==='post.changed' && !empty($data['edited'])) {
            $ids=array_column($this->db->all("SELECT user_id FROM lgc_post_votes WHERE post_id=? AND state='active' AND user_id>? ORDER BY user_id LIMIT 200",[$data['post_id'],$after]),'user_id');
            $post=$this->postContext((int)$data['post_id']);
            foreach ($ids as $id) if($post) $this->deliver((int)$event['id'],(int)$id,'communityRating',['post_id'=>$data['post_id'],'title'=>'你评分的'.$post['label'].'正文已修改，评分保留，可以复核或撤销','url'=>'/d/'.$post['discussion_id'].'/'.$post['number']]);
        } else return;
        if (count($ids)===200) $this->outbox->enqueue('notify.fanout',['event_id'=>(int)$event['id'],'after'=>(int)end($ids)],'notify:'.$event['id'].':'.end($ids),2);
    }
    public function event(array $event): void
    {
        $data=Data::decode($event['payload']); $type=$event['event_type']; $user=(int)$event['subject_user_id'];
        if ($type==='task.published' || ($type==='post.changed' && !empty($data['edited']))) { $this->fanout(['event_id'=>(int)$event['id']]); return; }
        if ($type==='rating.changed') {
            $post=$this->postContext((int)$data['post_id']); $rater=$this->db->scalar('SELECT username FROM '.$this->db->native('users').' WHERE id=?',[$data['rater_id']]);
            if ($post) {
                $action=($data['action'] ?? '')==='withdraw' ? '撤销了对'.$post['label'].'的评分' : '为'.$post['label'].'评分 '.($data['score'] ?? '（已更新）');
                $this->deliver((int)$event['id'],$user,'communityRating',['post_id'=>$data['post_id'],'title'=>($rater ?: '用户').' '.$action,'reason'=>$data['reason'] ?? '','url'=>'/d/'.$post['discussion_id'].'/'.$post['number']]);
            }
        }
        if ($type==='money.posted') {
            $rows=$this->db->all('SELECT DISTINCT user_id FROM lgc_entries WHERE transaction_id=? AND delta>0',[$data['transaction_id']]);
            $tx=$this->db->one('SELECT t.reason,u.username FROM lgc_transactions t LEFT JOIN '.$this->db->native('users').' u ON u.id=t.actor_id WHERE t.id=?',[$data['transaction_id']]);
            foreach ($rows as $row) {
                $lines=$this->db->all('SELECT e.delta,c.name FROM lgc_entries e JOIN lgc_currencies c ON c.id=e.currency_id WHERE e.transaction_id=? AND e.user_id=? AND e.delta>0',[$data['transaction_id'],$row['user_id']]);
                $amount=implode('、',array_map(fn($line)=>(string)$line['delta'].' '.$line['name'],$lines));
                $notice=['title'=>($tx['reason'] ?: '资源到账').'：'.$amount,'reason'=>$tx['reason'],'url'=>'/community/wallet'];
                if($data['kind']==='tip' && !empty($data['post_id']) && ($post=$this->postContext((int)$data['post_id']))) $notice=['title'=>($tx['username'] ?: '用户').' 打赏了你的'.$post['label'].'：'.$amount,'reason'=>$tx['reason'],'post_id'=>$data['post_id'],'url'=>'/d/'.$post['discussion_id'].'/'.$post['number']];
                $this->deliver((int)$event['id'],(int)$row['user_id'],'communityMoney',$notice);
            }
        }
    }
    private function postContext(int $id): ?array {
        $post=$this->db->one('SELECT p.number,p.discussion_id,d.title FROM '.$this->db->native('posts').' p JOIN '.$this->db->native('discussions').' d ON d.id=p.discussion_id WHERE p.id=?',[$id]);
        if($post) $post['label']='《'.$post['title'].'》第'.$post['number'].'楼'; return $post;
    }
}
