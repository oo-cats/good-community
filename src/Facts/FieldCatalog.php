<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Facts;

/** Trusted code owns readers; the same metadata drives validation, loading and GUI. */
final class FieldCatalog
{
    private array $fields=[];
    private array $loaders=[];
    public function registerLoader(string $key,callable $batchRead,?callable $available=null): void
    {
        if(!preg_match('/^[a-z][a-z0-9_]*$/D',$key) || isset($this->loaders[$key]) || in_array($key,['body','number','discussion_author','rating','likes'],true)) throw new \InvalidArgumentException('Invalid or duplicate loader');
        $this->loaders[$key]=['read'=>$batchRead,'available'=>$available];
    }
    public function loaders(): array { return $this->loaders; }
    public function __construct()
    {
        $this->register('post.id','帖子编号','integer',[],static fn($p,$r)=>(string)$p['id']);
        $this->register('post.number','楼层','integer',['number'],static fn($p,$r)=>(string)$p['number']);
        $this->register('post.body_length','正文长度','integer',['body'],static fn($p,$r)=>(string)mb_strlen(trim(html_entity_decode(strip_tags((string)$p['content']),ENT_QUOTES|ENT_HTML5,'UTF-8'))));
        $this->register('post.is_first','是主题首帖','boolean',[],static fn($p,$r)=>(int)$p['id']===(int)$p['first_post_id']);
        $this->register('post.created_at','发表时间','instant',[],static fn($p,$r)=>$p['created_at']===null ? null : (string)(strtotime($p['created_at'].' UTC')*1000));
        $this->register('discussion.id','主题编号','integer',[],static fn($p,$r)=>(string)$p['discussion_id']);
        $this->register('discussion.is_own','发表于自己创建的主题','boolean',['discussion_author'],static fn($p,$r)=>$p['discussion_author_id']!==null && (int)$p['discussion_author_id']===(int)$p['user_id']);
        $this->register('rating.count','评分人数','integer',['rating'],static fn($p,$r)=>(string)($r['rating_count'] ?? 0));
        $this->register('rating.average','平均评分','decimal',['rating'],static fn($p,$r)=>empty($r['rating_count']) ? null : ['n'=>(string)$r['rating_sum_hundredths'],'d'=>(string)(100*(int)$r['rating_count'])]);
        $this->register('likes.count','点赞数','integer',['likes'],static fn($p,$r)=>(string)($r['likes'] ?? 0));
    }
    public function register(string $key,string $title,string $type,array $requires,callable $read): void
    {
        if(isset($this->fields[$key]) || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D',$key)
            || !$title || mb_strlen($title)>100 || !in_array($type,['integer','decimal','boolean','string','instant','duration'],true)
            || array_diff($requires,['body','number','discussion_author','rating','likes',...array_keys($this->loaders)])) throw new \InvalidArgumentException('Invalid or duplicate query field');
        $this->fields[$key]=['title'=>$title,'type'=>$type,'requires'=>array_values(array_unique($requires)),'read'=>$read];
    }
    public function metadata(): array
    {
        $result=[]; foreach($this->fields as $key=>$field) { unset($field['read']); $result[$key]=$field; } return $result;
    }
    public function types(): array { $types=[]; foreach($this->fields as $key=>$field) $types[$key]=$field['type']; return $types; }
    public static function referenced(array $query): array
    {
        $keys=[];
        foreach($query['where'] ?? [] as $clause) foreach($clause as $term) foreach([$term['left']['field'] ?? '',$term['right']['field'] ?? ''] as $key) if($key!=='') $keys[$key]=true;
        return array_keys($keys);
    }
    public function requirements(array $query): array
    {
        $needs=[]; foreach(self::referenced($query) as $key) foreach($this->fields[$key]['requires'] ?? [] as $need) $needs[$need]=true;
        return $needs;
    }
    public function values(array $keys,array $post,array $related): array
    {
        $values=[]; foreach($keys as $key) if(isset($this->fields[$key])) $values[$key]=($this->fields[$key]['read'])($post,$related);
        return $values;
    }
}
