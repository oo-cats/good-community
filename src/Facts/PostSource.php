<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Facts;

use Local\GoodCommunity\Infrastructure\{Data,Db,DomainError};
use Local\GoodCommunity\PostFields\Fields;

/** Native posts are paged once; related values are batch-loaded, never one SQL per post. */
final class PostSource
{
    private FieldCatalog $catalog;
    private array $capabilities=[];
    public function __construct(private Db $db, private ?\Closure $visibleIds = null, private ?\Closure $enabled = null, ?FieldCatalog $catalog=null) { $this->catalog=$catalog ?? new FieldCatalog(); }
    public function catalog(): FieldCatalog { return $this->catalog; }
    public function assertAvailable(array $query): void
    {
        if (isset($this->catalog->requirements($query)['likes']) && ((!$this->exists('post_likes')) || ($this->enabled && !($this->enabled)('likes')))) throw new DomainError('likes_source_unavailable',202);
        if (!empty($query['board_tags']['ids']) && ((!$this->exists('discussion_tag')) || ($this->enabled && !($this->enabled)('tags')))) throw new DomainError('tags_source_unavailable',202);
        foreach($this->catalog->loaders() as $key=>$loader) if(isset($this->catalog->requirements($query)[$key]) && $loader['available'] && !($loader['available'])($this->db)) throw new DomainError('field_source_unavailable',202);
    }
    public function fields(?array $ids=null): array
    {
        if ($ids===[]) return [];
        $result=[];
        $where=$ids===null ? '' : ' WHERE public_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        foreach ($this->db->all('SELECT * FROM lgc_post_fields'.$where,$ids===null ? [] : array_map([Data::class,'bin'],$ids)) as $row) { $row['options']=Data::decode($row['options']); $result[Data::id($row['public_id'])]=$row; }
        return $result;
    }
    public static function fieldIds(array $query): array
    {
        $ids=[];
        foreach ($query['where'] ?? [] as $clause) foreach ($clause as $term) {
            foreach ([$term['left']['field'] ?? '',$term['right']['field'] ?? ''] as $key) if (preg_match('/^[0-9a-f-]{36}$/D',$key)) $ids[$key]=true;
        }
        if (!empty($query['admin_tags']['option_ids'])) $ids[$query['admin_tags']['field_id'] ?? '']=true;
        return array_keys($ids);
    }
    public function page(int $user, ?string $start, ?string $end, int $after=0, int $limit=201, bool $lock=false, ?array $ids=null, ?array $query=null, ?array $needs=null): array
    {
        $params=[$user,$after]; $where='p.user_id=? AND p.id>?';
        if ($start!==null) { $where.=' AND p.created_at>=?'; $params[]=$start; }
        if ($end!==null) { $where.=' AND p.created_at<?'; $params[]=$end; }
        if ($ids!==null) { if (!$ids) return []; $where.=' AND p.id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; array_push($params,...$ids); }
        $limit=max(1,min(10001,$limit));
        $needs ??= $query===null ? ['body'=>true,'number'=>true,'discussion_author'=>true] : $this->catalog->requirements($query);
        $columns='p.id,p.user_id,p.discussion_id,p.created_at,p.type,p.hidden_at,p.is_private,d.first_post_id,d.hidden_at discussion_hidden,d.is_private discussion_private';
        if(isset($needs['body'])) $columns.=',p.content';
        if(isset($needs['number'])) $columns.=',p.number';
        if(isset($needs['discussion_author'])) $columns.=',d.user_id discussion_author_id';
        $rows=$this->db->all('SELECT '.$columns.' FROM '.$this->db->native('posts').' p JOIN '.$this->db->native('discussions').' d ON d.id=p.discussion_id WHERE '.$where.' ORDER BY p.id LIMIT '.$limit.($lock ? ' LOCK IN SHARE MODE' : ''),$params);
        return $rows;
    }
    public function hasPosts(int $user, ?string $start, ?string $end): bool
    {
        $where='user_id=?'; $params=[$user];
        if ($start!==null) { $where.=' AND created_at>=?'; $params[]=$start; }
        if ($end!==null) { $where.=' AND created_at<?'; $params[]=$end; }
        return (bool)$this->db->scalar('SELECT 1 FROM '.$this->db->native('posts').' WHERE '.$where.' LIMIT 1',$params);
    }
    private function exists(string $name): bool { return $this->capabilities[$name] ??= (bool)$this->db->scalar('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$this->db->prefix.$name]); }
    public function requirements(array $queries): array
    {
        $needs=[]; foreach($queries as $query) $needs+=$this->catalog->requirements($query); return $needs;
    }
    /** A bounded operation snapshot. Never cached across worker units or payout transactions. */
    public function snapshot(array $rows,array $queries,bool $lock=false): array
    {
        $this->capabilities=[];
        foreach($queries as $query) $this->assertAvailable($query);
        if(!$rows) return [];
        $ids=array_map('intval',array_column($rows,'id')); $marks=implode(',',array_fill(0,count($ids),'?')); $suffix=$lock ? ' LOCK IN SHARE MODE' : '';
        $needs=$this->requirements($queries);
        $states=isset($needs['rating']) ? array_column($this->db->all('SELECT rating_count,rating_sum_hundredths,rating_version,marks_version,post_id FROM lgc_post_state WHERE post_id IN ('.$marks.')'.$suffix,$ids),null,'post_id') : [];
        $values=[]; $types=$this->catalog->types();
        $fieldKeys=[]; foreach($queries as $query) foreach(self::fieldIds($query) as $key) $fieldKeys[$key]=true;
        $fields=$this->fields(array_keys($fieldKeys)); foreach ($fields as $key=>$field) $types[$key]=$field['value_type'];
        if ($fields) {
            $fieldIds=array_column($fields,'id');
            foreach ($this->db->all('SELECT f.public_id,f.value_type,v.* FROM lgc_post_field_values v JOIN lgc_post_fields f ON f.id=v.field_id WHERE v.post_id IN ('.$marks.') AND v.field_id IN ('.implode(',',array_fill(0,count($fieldIds),'?')).')'.$suffix,[...$ids,...$fieldIds]) as $row) $values[$row['post_id']][Data::id($row['public_id'])]=Fields::value($row);
        }
        $likes=[]; $needsLikes=isset($needs['likes']);
        if ($needsLikes) {
            if (!$this->exists('post_likes')) throw new DomainError('likes_source_unavailable',202);
            // Read individual rows when locking: a GROUP BY aggregate does not prove source-row locks.
            if($lock) foreach ($this->db->all('SELECT post_id,user_id FROM '.$this->db->native('post_likes').' WHERE post_id IN ('.$marks.')'.$suffix,$ids) as $like) $likes[$like['post_id']]=($likes[$like['post_id']] ?? 0)+1;
            else foreach($this->db->all('SELECT post_id,COUNT(*) total FROM '.$this->db->native('post_likes').' WHERE post_id IN ('.$marks.') GROUP BY post_id',$ids) as $like) $likes[$like['post_id']]=(int)$like['total'];
        }
        $tags=[]; $children=[]; $needsTags=false; $descendants=false;
        foreach($queries as $query) if($query['board_tags']['ids']) { $needsTags=true; $descendants=$descendants || $query['board_tags']['include_descendants']; }
        if ($needsTags) {
            if (!$this->exists('discussion_tag')) throw new DomainError('tags_source_unavailable',202);
            if ($descendants) foreach($this->db->all('SELECT id,parent_id FROM '.$this->db->native('tags')) as $tag) $children[(int)$tag['parent_id']][]=(int)$tag['id'];
            $discussions=array_values(array_unique(array_column($rows,'discussion_id')));
            foreach ($this->db->all('SELECT discussion_id,tag_id FROM '.$this->db->native('discussion_tag').' WHERE discussion_id IN ('.implode(',',array_fill(0,count($discussions),'?')).')'.$suffix,$discussions) as $tag) $tags[$tag['discussion_id']][]=(int)$tag['tag_id'];
        }
        $visible=$this->visibleIds ? ($this->visibleIds)($ids) : $ids; $visible=array_fill_keys($visible,true);
        $extra=[];
        foreach($this->catalog->loaders() as $key=>$loader) if(isset($needs[$key])) foreach(($loader['read'])($this->db,$rows,$lock) as $id=>$related) $extra[$id]=($extra[$id] ?? [])+$related;
        return compact('states','values','types','likes','tags','children','visible','extra');
    }
    public function evaluate(array $rows, array $query, bool $lock=false, ?array $snapshot=null): array
    {
        $snapshot ??= $this->snapshot($rows,[$query],$lock);
        if(!$rows) return [];
        ['states'=>$states,'values'=>$values,'types'=>$types,'likes'=>$likes,'tags'=>$tags,'children'=>$children,'visible'=>$visible,'extra'=>$extra]=$snapshot;
        $needs=$this->catalog->requirements($query); $board=$query['board_tags']; $wanted=$board['ids'];
        if($board['include_descendants']) {
            $seen=array_fill_keys($wanted,true); $queue=$wanted;
            for($i=0;$i<count($queue);$i++) foreach($children[$queue[$i]] ?? [] as $child) if(!isset($seen[$child])) { $seen[$child]=true; $queue[]=$child; }
            $wanted=array_keys($seen);
        }
        $result=[]; $fieldKeys=FieldCatalog::referenced($query);
        foreach ($rows as $row) {
            $id=(int)$row['id']; $state=$states[$id] ?? [];
            $v=($values[$id] ?? [])+$this->catalog->values($fieldKeys,$row,$state+['likes'=>$likes[$id] ?? 0]+($extra[$id] ?? []));
            // Unrated exclusions apply even when rating is used only on the right side.
            if(isset($needs['rating'])) $v['rating.count']=(string)($state['rating_count'] ?? 0);
            $valid=isset($visible[$id]) && $row['type']==='comment' && $row['hidden_at']===null && $row['discussion_hidden']===null && !(bool)$row['is_private'] && !(bool)$row['discussion_private'];
            $isFirst=$id===(int)$row['first_post_id'];
            if ($query['post_type']==='topic' && !$isFirst) $valid=false;
            if ($query['post_type']==='reply' && $isFirst) $valid=false;
            if ($wanted) { $in=$tags[$row['discussion_id']] ?? []; if ($board['match']==='any' ? !array_intersect($wanted,$in) : (bool)array_diff($wanted,$in)) $valid=false; }
            $result[$id]=['truth'=>$valid && QuerySpec::matches($query,$v,$types),'created_at'=>$row['created_at'],'rating_version'=>(int)($state['rating_version'] ?? 0),'marks_version'=>(int)($state['marks_version'] ?? 0),'values'=>$v];
        }
        return $result;
    }
}
