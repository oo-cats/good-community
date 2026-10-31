<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Currency;
use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError};

/** Read-only, cursor-paged views over the immutable ledger. */
final class Activity {
    public function __construct(private Db $db) {}
    public function history(Actor $actor,int $user,array $query): array {
        if($actor->id!==$user && !$actor->can('currency.viewHistory')) throw new DomainError('history_private',403);
        $cursor=explode(':',(string)($query['before'] ?? PHP_INT_MAX.':'.PHP_INT_MAX)); $tx=(int)($cursor[0] ?? PHP_INT_MAX); $line=(int)($cursor[1] ?? PHP_INT_MAX);
        $rows=$this->db->all('SELECT e.*,t.public_id,t.kind,t.reason,t.related_post_id,t.posted_at,t.actor_id,u.username,c.name currency_name,p.discussion_id,p.number,d.title FROM lgc_entries e JOIN lgc_transactions t ON t.id=e.transaction_id JOIN lgc_currencies c ON c.id=e.currency_id LEFT JOIN '.$this->db->native('users').' u ON u.id=t.actor_id LEFT JOIN '.$this->db->native('posts').' p ON p.id=t.related_post_id LEFT JOIN '.$this->db->native('discussions').' d ON d.id=p.discussion_id WHERE e.user_id=? AND (e.transaction_id<? OR (e.transaction_id=? AND e.line_no<?)) ORDER BY e.transaction_id DESC,e.line_no DESC LIMIT 21',[$user,$tx,$tx,$line]);
        $items=array_map(fn($r)=>['transaction_id'=>Data::id($r['public_id']),'currency_name'=>$r['currency_name'],'delta'=>(string)$r['delta'],'balance'=>(string)$r['balance_after'],'kind'=>$r['kind'],'reason'=>$r['reason'],'actor'=>$r['username'] ?: '系统','created_at'=>$r['posted_at'],'post_id'=>$r['related_post_id'],'discussion_id'=>$r['discussion_id'],'post_number'=>$r['number'],'post_title'=>$r['title']],array_slice($rows,0,20));
        $last=$rows[min(19,count($rows)-1)] ?? null;
        return ['items'=>$items,'next'=>count($rows)>20 ? $last['transaction_id'].':'.$last['line_no'] : null];
    }
    public function tips(int $post,array $query): array {
        $before=(int)($query['before'] ?? PHP_INT_MAX);
        $rows=$this->db->all("SELECT t.id,t.public_id,t.reason,t.posted_at,t.actor_id,u.username,c.name currency_name,e.delta,EXISTS(SELECT 1 FROM lgc_transactions r WHERE r.reversal_of=t.id) reversed FROM lgc_transactions t JOIN lgc_entries e ON e.transaction_id=t.id AND e.delta>0 JOIN lgc_currencies c ON c.id=e.currency_id LEFT JOIN ".$this->db->native('users')." u ON u.id=t.actor_id WHERE t.related_post_id=? AND t.kind='tip' AND t.id<? ORDER BY t.id DESC LIMIT 21",[$post,$before]);
        $items=array_map(fn($r)=>['id'=>Data::id($r['public_id']),'user_id'=>(int)$r['actor_id'],'username'=>$r['username'] ?: '已删除用户','amount'=>(string)$r['delta'],'currency_name'=>$r['currency_name'],'created_at'=>$r['posted_at'],'reversed'=>(bool)$r['reversed']],array_slice($rows,0,20));
        return ['items'=>$items,'next'=>count($rows)>20 ? (string)$rows[19]['id'] : null];
    }
}
