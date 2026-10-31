<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Facts;

use Local\GoodCommunity\Infrastructure\{Data,Db,DomainError,Outbox};

final class QueryEngine
{
    public function __construct(private Db $db, private PostSource $source, private Outbox $outbox) {}
    public function normalize(array $query): array {
        $referenced=$query; if(($query['unfiltered'] ?? false)===true) $referenced['where']=[];
        $query=QuerySpec::normalize($query,$this->source->fields(PostSource::fieldIds($referenced)),$this->source->catalog()->types());
        $query['requires_rating']=isset($this->source->catalog()->requirements($query)['rating']);
        $this->source->assertAvailable($query); return $query;
    }
    public function systemFields(): array { return $this->source->catalog()->metadata(); }
    public function window(array $query, array $context): array { return $query['time']['mode']==='fixed' ? [$query['time']['start'],$query['time']['end']] : [$context['start'],$context['end']]; }
    public function counter(array $query, array $context): array
    {
        [$start,$end]=$this->window($query,$context); $base=$query; unset($base['time']);
        $hash=Data::hash($base);
        $stored=$this->db->one('SELECT * FROM lgc_queries WHERE definition_hash=?',[$hash]);
        if (!$stored) { $this->db->run('INSERT IGNORE INTO lgc_queries(definition_hash,source_key,spec,created_at) VALUES(?,\'posts\',?,?)',[$hash,Data::canonical($base),Data::now()]); $stored=$this->db->one('SELECT * FROM lgc_queries WHERE definition_hash=?',[$hash]); }
        if (Data::canonical(Data::decode($stored['spec']))!==Data::canonical($base)) throw new DomainError('query_hash_conflict',409);
        $bounds=Data::hash([$start,$end]); $head=$this->db->source('posts',$context['user']);
        $counter=$this->db->one('SELECT * FROM lgc_counter_windows WHERE query_id=? AND user_id=? AND bounds_hash=?',[$stored['id'],$context['user'],$bounds]);
        if (!$counter) { $expires=$end ? max(gmdate('Y-m-d H:i:s',time()+30*86400),gmdate('Y-m-d H:i:s',strtotime($end.' UTC')+90*86400)) : null; $this->db->run("INSERT IGNORE INTO lgc_counter_windows(query_id,user_id,bounds_hash,starts_at,ends_at,state,source_version,expires_at) VALUES(?,?,?,?,?,'preparing',?,?)",[$stored['id'],$context['user'],$bounds,$start,$end,$head,$expires]); $counter=$this->db->one('SELECT * FROM lgc_counter_windows WHERE query_id=? AND user_id=? AND bounds_hash=?',[$stored['id'],$context['user'],$bounds]); }
        if ((int)$counter['source_version']!==$head) {
            $this->db->run("UPDATE lgc_counter_windows SET state='preparing',cursor_id=0,generation=generation+1,source_version=? WHERE id=? AND source_version<>?",[$head,$counter['id'],$head]);
            $counter=$this->db->one('SELECT * FROM lgc_counter_windows WHERE id=?',[$counter['id']]);
        }
        if ($counter['state']!=='ready') $this->step((int)$counter['id']);
        $counter=$this->db->one('SELECT * FROM lgc_counter_windows WHERE id=?',[$counter['id']]);
        if ($counter['state']!=='ready') throw new DomainError('preparing',202,'正在准备统计');
        return $counter;
    }
    public function step(int $id): void
    {
        $this->db->transaction(function () use ($id) {
            $counter=$this->db->one('SELECT * FROM lgc_counter_windows WHERE id=? FOR UPDATE',[$id]);
            if (!$counter || $counter['state']==='ready') return;
            $head=$this->db->source('posts',(int)$counter['user_id']);
            if ((int)$counter['source_version']!==$head) { $this->db->run("UPDATE lgc_counter_windows SET cursor_id=0,generation=generation+1,source_version=? WHERE id=?",[$head,$id]); $counter=$this->db->one('SELECT * FROM lgc_counter_windows WHERE id=?',[$id]); }
            $query=Data::decode($this->db->scalar('SELECT spec FROM lgc_queries WHERE id=?',[$counter['query_id']]));
            $rows=$this->source->page((int)$counter['user_id'],$counter['starts_at'],$counter['ends_at'],(int)$counter['cursor_id'],201,false,null,$query);
            $more=count($rows)>200; $batch=array_slice($rows,0,200);
            $results=array_filter($this->source->evaluate($batch,$query),fn($result)=>$result['truth']);
            if ($results) {
                $params=[];
                foreach ($results as $object=>$result) array_push($params,$id,$object,$counter['generation']);
                $this->db->run('INSERT INTO lgc_counter_contributions(counter_id,object_id,seen_generation) VALUES '.implode(',',array_fill(0,count($results),'(?,?,?)')).' ON DUPLICATE KEY UPDATE seen_generation=VALUES(seen_generation)',$params);
            }
            $cursor=$batch ? (int)end($batch)['id'] : (int)$counter['cursor_id'];
            if (!$more) {
                $this->db->run('DELETE FROM lgc_counter_contributions WHERE counter_id=? AND seen_generation<>?',[$id,$counter['generation']]);
                $count=$this->db->scalar('SELECT COUNT(*) FROM lgc_counter_contributions WHERE counter_id=?',[$id]);
                $this->db->run("UPDATE lgc_counter_windows SET cursor_id=?,matches=?,state='ready',verified_at=? WHERE id=?",[$cursor,$count,Data::now(),$id]);
                $this->outbox->emit('counter.ready','counter:'.$id,(int)$counter['generation'],['counter_id'=>$id],(int)$counter['user_id']);
            } else {
                $this->db->run('UPDATE lgc_counter_windows SET cursor_id=? WHERE id=?',[$cursor,$id]);
                $this->outbox->enqueue('counter',['counter_id'=>$id],'counter:'.$id.':'.$counter['generation'].':'.$cursor,1);
            }
        });
    }
    public function read(array $query, array $context, ?int $task = null): array
    {
        $query=$this->normalize($query);
        [$start,$end]=$this->window($query,$context);
        if (!$this->source->hasPosts($context['user'],$start,$end)) return ['count'=>0,'ids'=>[],'counter_id'=>null,'generation'=>0,'source_version'=>0,'query'=>$query];
        $counter=$this->counter($query,$context); $params=[$counter['id']];
        $exclude='';
        if ($task!==null) { $exclude=" AND NOT EXISTS(SELECT 1 FROM lgc_task_progress_uses u WHERE u.task_id=? AND u.user_id=? AND u.source_key='posts' AND u.object_id=c.object_id)"; $params[]=$task; $params[]=$context['user']; }
        $count=$task===null ? (int)$counter['matches'] : (int)$this->db->scalar('SELECT COUNT(*) FROM lgc_counter_contributions c WHERE c.counter_id=?'.$exclude,$params);
        $ids=array_map('intval',array_column($this->db->all('SELECT c.object_id FROM lgc_counter_contributions c WHERE c.counter_id=?'.$exclude.' ORDER BY c.object_id LIMIT 2000',$params),'object_id'));
        return ['count'=>$count,'ids'=>$ids,'counter_id'=>(int)$counter['id'],'generation'=>(int)$counter['generation'],'source_version'=>(int)$counter['source_version'],'query'=>$query];
    }
    /** Full current-state fallback for non-monotone count gates, before any wallet lock. */
    public function exact(array $query, array $context, ?int $task=null, bool $lock=false): array
    {
        $query=$this->normalize($query);
        [$start,$end]=$this->window($query,$context); $after=0; $ids=[]; $scanned=0;
        do {
            $rows=$this->source->page($context['user'],$start,$end,$after,201,$lock,null,$query);
            $more=count($rows)>200; $batch=array_slice($rows,0,200); $scanned+=count($batch);
            if ($scanned>10000) throw new DomainError('exact_query_budget_exceeded',202,'此精确统计需要专用索引后才能结算');
            $matches=$this->source->evaluate($batch,$query,$lock);
            foreach ($matches as $id=>$result) if ($result['truth']) $ids[$id]=true;
            if ($batch) $after=(int)end($batch)['id'];
        } while ($more);
        if ($task!==null && $ids) foreach(array_chunk(array_keys($ids),1000) as $page) foreach ($this->db->all("SELECT object_id FROM lgc_task_progress_uses WHERE task_id=? AND user_id=? AND source_key='posts' AND object_id IN (".implode(',',array_fill(0,count($page),'?')).')',[$task,$context['user'],...$page]) as $used) unset($ids[(int)$used['object_id']]);
        return ['count'=>count($ids),'ids'=>array_keys($ids),'query'=>$query];
    }
    public function verify(array $query, array $context, array $ids): array
    {
        $query=$this->normalize($query);
        [$start,$end]=$this->window($query,$context);
        $rows=$this->source->page($context['user'],$start,$end,0,2000,true,$ids,$query);
        return array_keys(array_filter($this->source->evaluate($rows,$query,true),fn ($result)=>$result['truth']));
    }
    /** Re-evaluate one object's current state; old payloads never overwrite current values. */
    public function changed(int $user, int $post, ?int $eventVersion=null): void
    {
        $currentPost=$this->db->one('SELECT created_at FROM '.$this->db->native('posts').' WHERE id=? AND user_id=?',[$post,$user]);
        $created=$currentPost['created_at'] ?? null;
        $relevant='((?=1 AND (w.starts_at IS NULL OR w.starts_at<=?) AND (w.ends_at IS NULL OR w.ends_at>?)) OR EXISTS(SELECT 1 FROM lgc_counter_contributions c WHERE c.counter_id=w.id AND c.object_id=?))';
        $where=[(int)($currentPost!==null),$created,$created,$post];
        for($after=0;;) {
        $windows=$this->db->all("SELECT w.id FROM lgc_counter_windows w WHERE w.user_id=? AND w.id>? AND w.state='ready' AND ".$relevant.' ORDER BY w.id LIMIT 200',[$user,$after,...$where]);
        if(!$windows) break;
        $batch=array_column($windows,'id'); $after=(int)end($batch);
        $this->db->transaction(function() use($batch,$user,$post,$eventVersion) {
            $marks=implode(',',array_fill(0,count($batch),'?'));
            $current=$this->db->all("SELECT w.*,q.spec FROM lgc_counter_windows w JOIN lgc_queries q ON q.id=w.query_id WHERE w.id IN ($marks) AND w.state='ready' ORDER BY w.id FOR UPDATE",$batch);
            if(!$current) return;
            $queries=array_map(fn($w)=>Data::decode($w['spec']),$current);
            $rows=$this->source->page($user,null,null,0,2,false,[$post],null,$this->source->requirements($queries));
            $snapshot=$this->source->snapshot($rows,$queries);
            $before=array_fill_keys(array_column($this->db->all("SELECT counter_id FROM lgc_counter_contributions WHERE counter_id IN ($marks) AND object_id=?",[...$batch,$post]),'counter_id'),true);
            $insert=[]; $remove=[]; $counts=[]; $versions=[];
            foreach($current as $index=>$window) {
                $inWindow=$rows && (!$window['starts_at'] || $rows[0]['created_at']>=$window['starts_at']) && (!$window['ends_at'] || $rows[0]['created_at']<$window['ends_at']);
                $truth=$inWindow && ($this->source->evaluate($rows,$queries[$index],false,$snapshot)[$post]['truth'] ?? false);
                $id=(int)$window['id'];
                if($truth) array_push($insert,$id,$post,$window['generation']); elseif(isset($before[$id])) $remove[]=$id;
                $counts[$id]=(int)$window['matches']+(int)$truth-(int)isset($before[$id]);
                // Missing or out-of-order events never certify an unobserved gap.
                $versions[$id]=$eventVersion!==null && $eventVersion===(int)$window['source_version']+1 ? $eventVersion : (int)$window['source_version'];
            }
            if($insert) $this->db->run('INSERT INTO lgc_counter_contributions(counter_id,object_id,seen_generation) VALUES '.implode(',',array_fill(0,intdiv(count($insert),3),'(?,?,?)')).' ON DUPLICATE KEY UPDATE seen_generation=VALUES(seen_generation)',$insert);
            if($remove) $this->db->run('DELETE FROM lgc_counter_contributions WHERE object_id=? AND counter_id IN ('.implode(',',array_fill(0,count($remove),'?')).')',[$post,...$remove]);
            $cases=[]; $args=[];
            foreach(['matches'=>$counts,'source_version'=>$versions] as $column=>$values) {
                $case=$column.'=CASE id'; foreach($values as $id=>$value) { $case.=' WHEN ? THEN ?'; array_push($args,$id,$value); } $cases[]=$case.' END';
            }
            $this->db->run('UPDATE lgc_counter_windows SET '.implode(',',$cases).' WHERE id IN ('.implode(',',array_fill(0,count($counts),'?')).')',[...$args,...array_keys($counts)]);
        });
        if(count($windows)<200) break;
        }
        // Unaffected windows advance in one update. Recheck relevance here so a concurrently
        // completed rebuild cannot be marked current without applying an affected object.
        if($eventVersion!==null) $this->db->run("UPDATE lgc_counter_windows w SET w.source_version=? WHERE w.user_id=? AND w.state='ready' AND w.source_version=? AND NOT ".$relevant,[$eventVersion,$user,$eventVersion-1,...$where]);
    }
    public function invalidateQueries(int $user,array $queries,array $context): void
    {
        if(!$queries) return;
        $select=[]; $params=[$user];
        foreach($queries as $query) {
            [$start,$end]=$this->window($query,$context); $base=$query; unset($base['time']);
            $select[]='(q.definition_hash=? AND w.bounds_hash=?)'; array_push($params,Data::hash($base),Data::hash([$start,$end]));
        }
        $this->resetWindows('SELECT w.id,w.generation FROM lgc_counter_windows w JOIN lgc_queries q ON q.id=w.query_id WHERE w.user_id=? AND ('.implode(' OR ',$select).') FOR UPDATE',$params);
    }
    public function invalidateUser(int $user): void
    {
        $this->resetWindows('SELECT id,generation FROM lgc_counter_windows WHERE user_id=? FOR UPDATE',[$user]);
    }
    private function resetWindows(string $sql,array $params): void
    {
        $this->db->transaction(function () use ($sql,$params) {
            $windows=$this->db->all($sql,$params);
            foreach ($windows as $window) {
                $generation=(int)$window['generation']+1;
                $this->db->run("UPDATE lgc_counter_windows SET state='preparing',cursor_id=0,generation=? WHERE id=?",[$generation,$window['id']]);
                $this->outbox->enqueue('counter',['counter_id'=>(int)$window['id']],'counter-reconcile:'.$window['id'].':'.$generation,1);
            }
        });
    }
    /** Rebuildable projections only. Claims, money, usage identities and delivery receipts survive. */
    public function prune(): int
    {
        return $this->db->transaction(function () {
            $windows=$this->db->all('SELECT id FROM lgc_counter_windows WHERE expires_at<UTC_TIMESTAMP(6) ORDER BY expires_at,id LIMIT 20 FOR UPDATE');
            $deleted=0;
            foreach ($windows as $window) {
                $this->db->run('DELETE FROM lgc_counter_contributions WHERE counter_id=? LIMIT 200',[$window['id']]);
                if (!$this->db->scalar('SELECT 1 FROM lgc_counter_contributions WHERE counter_id=? LIMIT 1',[$window['id']])) $deleted+=$this->db->run('DELETE FROM lgc_counter_windows WHERE id=?',[$window['id']])->rowCount();
            }
            return $deleted;
        });
    }
}
