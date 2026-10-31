<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Integrations;

use Local\GoodCommunity\Facts\{FactEngine,PostSource,QueryEngine,QuerySpec,FieldCatalog};
use Local\GoodCommunity\Infrastructure\{Db,Data,DomainError,Outbox};

/** Consumer of the source's public contract; no ACGN table names or private DTOs. */
final class AcgnDatabase
{
    public const RECORDS='acgn.records.count.v1';
    public const FIRST='acgn.first_completed.count.v1';
    private int $relationBudget=10000;
    private const FIELDS=[
        'subject.id'=>['作品编号','integer'], 'subject.type'=>['作品类型','string'],
        'subject.release_date'=>['发行日期','instant'], 'record.status'=>['我的收藏状态','string'],
        'record.score'=>['我的作品评分（1–10）','integer'], 'record.progress'=>['我的进度','integer'],
        'record.favorite'=>['已收藏','boolean'], 'completion.observed_at'=>['首次完成记录时间','instant'],
        'completion.origin'=>['首次完成来源','string'],
    ];
    public function __construct(private Db $db,private Outbox $outbox,private PostSource $posts,private QueryEngine $queries,private \Closure $available,private \Closure $source,private \Closure $owner) {}

    public function register(FactEngine $facts): void
    {
        $facts->sourceGuard('source:acgn',function($context) {
            $this->db->sourceLock('acgn-catalog',0); $this->db->sourceLock('acgn-records',(int)$context['user']);
        });
        foreach ([self::RECORDS=>'当前收藏作品数',self::FIRST=>'首次标记完成作品数'] as $method=>$title) {
            $first=$method===self::FIRST;
            $facts->register($method,['title'=>$title,'type'=>'integer','temporal'=>$first ? 'action' : 'state','editor'=>'acgn','query_fields'=>$this->fieldDefinitions(),'dependencies'=>['source:acgn'],
                'help'=>$first ? '本人首次标记完成且当前仍完成；导入、重看和删除重建不产生新首次。' : '按当前收藏判断；导入可计入。周期任务表示每期保持条件。'],
                fn($p)=>$this->normalize($p,$first),fn($p,$c)=>(string)$this->count($p,$c,$first),
                ['dependencies'=>fn($p)=>$this->dependencies($p), 'assertPeriodic'=>function($p)use($first){ if($first && $p['time']['mode']!=='inherit') throw new DomainError('period_requires_current_window',422); }]);
        }
        $this->posts->registerRelation('acgn',fn($p)=>$this->normalize($p,false,false),fn($rows,$p,$lock)=>$this->matchPosts($rows,$p,$lock),
            function($user,$lock) { $this->relationBudget=10000; $this->reader($user,$lock); });
    }
    public function fieldDefinitions(): array
    {
        $result=[]; foreach(self::FIELDS as $key=>[$title,$type]) $result[$key]=['title'=>$title,'type'=>$type];
        $result['record.status']['choices']=[['planned','计划'],['current','进行中'],['completed','已完成'],['on_hold','搁置'],['dropped','放弃']];
        $result['subject.type']['choices']=[['anime','动画'],['manga','漫画'],['game','游戏'],['novel','小说']];
        $result['completion.origin']['choices']=[['self','本人首次完成'],['import','导入或历史观察']];
        return $result;
    }
    private function types(): array { return array_map(fn($f)=>$f[1],self::FIELDS); }
    public function normalize(array $params,bool $first=false,bool $allowPosts=true): array
    {
        if (array_diff(array_keys($params),['subjects','where','time','related_posts'])) throw new DomainError('invalid_acgn_parameter',422);
        $subjects=$params['subjects'] ?? [];
        if (!is_array($subjects) || array_diff(array_keys($subjects),['types','ids'])) throw new DomainError('invalid_acgn_subject_filter',422);
        $types=$subjects['types'] ?? [];
        if (!is_array($types) || array_diff($types,['anime','manga','game','novel'])) throw new DomainError('invalid_acgn_type',422);
        $ids=$subjects['ids'] ?? [];
        if (!is_array($ids) || count($ids)>100) throw new DomainError('acgn_subject_limit',422);
        $ids=array_map(fn($id)=>(int)Data::integer($id,false,10),$ids);
        if (in_array(0,$ids,true)) throw new DomainError('invalid_acgn_subject',422);
        $ids=array_values(array_unique($ids)); sort($ids); $types=array_values(array_unique($types)); sort($types);
        $where=$params['where'] ?? [];
        $filter=QuerySpec::normalize(['unfiltered'=>!$where,'where'=>$where],[],$this->types());
        $time=$params['time'] ?? ['mode'=>'inherit'];
        if (!$first && isset($params['time'])) throw new DomainError('acgn_state_has_no_window',422);
        if (!in_array($time['mode'] ?? '',['inherit','fixed','all'],true)) throw new DomainError('invalid_time_mode',422);
        if ($time['mode']==='fixed') {
            $time=['mode'=>'fixed','start'=>Data::date($time['start'] ?? null),'end'=>Data::date($time['end'] ?? null)];
            if ($time['start'] && $time['end'] && $time['start']>=$time['end']) throw new DomainError('invalid_interval',422);
        } else $time=['mode'=>$time['mode']];
        $result=['subjects'=>['types'=>$types,'ids'=>$ids],'where'=>$filter['where']];
        if ($first) $result['time']=$time;
        if (isset($params['related_posts']) && (!is_array($params['related_posts']) || array_diff(array_keys($params['related_posts']),['required','association','query']))) throw new DomainError('invalid_acgn_relation',422);
        if (!empty($params['related_posts']['required'])) {
            if (!$allowPosts || ($params['related_posts']['association'] ?? 'single_subject')!=='single_subject') throw new DomainError('invalid_acgn_relation',422);
            $query=$params['related_posts']['query'] ?? ['post_type'=>'topic','unfiltered'=>true];
            if (!empty($query['relations']) || ($query['time']['mode'] ?? 'inherit')!=='inherit') throw new DomainError('acgn_relation_window_or_recursion',422);
            $result['related_posts']=['required'=>true,'association'=>'single_subject','query'=>$this->queries->normalize($query)];
        }
        return $result;
    }
    private function dependencies(array $params): array
    {
        if (empty($params['related_posts'])) return [];
        $query=$params['related_posts']['query'];
        return ['source:posts','source:rating',...array_map(fn($id)=>'field:'.$id,PostSource::fieldIds($query))];
    }
    private function reader(int $user,bool $lock): array
    {
        if (!($this->available)()) throw new DomainError('acgn_source_unavailable',202);
        if ($lock) { $this->db->sourceLock('acgn-catalog',0); $this->db->sourceLock('acgn-records',$user); }
        $source=($this->source)(); $cap=$source->capabilities();
        if (($cap['version'] ?? 0)!==1 || empty($cap['available'])) throw new DomainError('acgn_source_unavailable',202);
        $owner=($this->owner)($user); if (!$owner) throw new DomainError('acgn_owner_unavailable',202);
        $pdo=$lock ? $this->db->pdo : null;
        $this->call(fn()=>$source->filterSubjects($owner,[],[],[], $pdo));
        return [$source,$owner,$pdo];
    }
    private function call(callable $read): array
    {
        try { $result=$read(); }
        catch (\Local\AcgnDatabase\Facts\SourceUnavailableV1 $e) { throw new DomainError('acgn_source_unavailable',202); }
        if (($result['version'] ?? null)!==1 || !isset($result['items'],$result['complete'])) throw new DomainError('acgn_contract_unavailable',202);
        return $result;
    }
    private function filter(array $params,bool $first,array $context): array
    {
        $filter=['mode'=>$first ? 'first_completed' : 'records'];
        foreach (['types','ids'] as $key) if ($params['subjects'][$key]) $filter[$key]=$params['subjects'][$key];
        if ($first) {
            $mode=$params['time']['mode'];
            $filter['from']=$mode==='all' ? null : ($mode==='fixed' ? $params['time']['start'] : $context['start']);
            $filter['until']=$mode==='all' ? null : ($mode==='fixed' ? $params['time']['end'] : $context['end']);
        }
        return $filter;
    }
    private function fields(array $params): array
    {
        return array_values(array_unique(['subject.id','subject.type',...FieldCatalog::referenced(['where'=>$params['where']])]));
    }
    private function matches(array $params,array $values): bool
    {
        if ($params['subjects']['ids'] && !in_array((int)($values['subject.id'] ?? 0),$params['subjects']['ids'],true)) return false;
        if ($params['subjects']['types'] && !in_array($values['subject.type'] ?? '',$params['subjects']['types'],true)) return false;
        foreach ($values as $key=>&$value) if ($value!==null && (self::FIELDS[$key][1] ?? '')==='instant') {
            $date=new \DateTimeImmutable($value,new \DateTimeZone('UTC'));
            $value=(string)((int)$date->format('U')*1000+(int)$date->format('v'));
        }
        unset($value);
        return QuerySpec::matches(['requires_rating'=>false,'admin_tags'=>[],'unfiltered'=>!$params['where'],'where'=>$params['where']],$values,$this->types());
    }
    public function count(array $params,array $context,bool $first): int
    {
        [$source,$owner,$pdo]=$this->reader((int)$context['user'],!empty($context['lock']));
        $filter=$this->filter($params,$first,$context); $reviewed=null;
        if (!empty($params['related_posts'])) {
            $window=$first ? ['start'=>$filter['from'],'end'=>$filter['until']] : ['start'=>null,'end'=>null];
            $reviewed=$this->reviewedSubjects($params['related_posts']['query'],array_replace($context,$window),$source,$owner,$pdo);
        }
        $after=null; $count=0; $scanned=0;
        do {
            $page=$this->call(fn()=>$source->recordsPage($owner,$filter,$this->fields($params),$after,200,$pdo));
            $scanned+=count($page['items']); if ($scanned>10000) throw new DomainError('acgn_query_budget',202,'作品统计范围过大，请缩小条件');
            foreach ($page['items'] as $row) if (($reviewed===null || isset($reviewed[$row['subject_id']])) && $this->matches($params,$row['values'])) $count++;
            $next=$page['next_after'] ?? null;
            if (!$page['complete'] && (!is_int($next) || $next<=(int)$after)) throw new DomainError('acgn_cursor_invalid',202);
            $after=$next;
        } while (!$page['complete']);
        return $count;
    }
    private function links($source,$owner,?\PDO $pdo,array $discussions,array $fields,?int &$remaining=null): array
    {
        $remaining ??= 10000;
        $after=null; $items=[]; $totals=[];
        do {
            $page=$this->call(fn()=>$source->linksForDiscussions($owner,$discussions,$fields,$after,200,$pdo));
            array_push($items,...$page['items']); $totals=$page['totals'];
            $remaining-=count($page['items']);
            if ($remaining<0) throw new DomainError('acgn_relation_budget',202);
            $next=$page['next_after'] ?? null;
            if (!$page['complete'] && (!$next || $next===$after)) throw new DomainError('acgn_cursor_invalid',202);
            $after=$next;
        } while (!$page['complete']);
        return [$items,$totals];
    }
    private function reviewedSubjects(array $query,array $context,$source,$owner,?\PDO $pdo): array
    {
        $after=0; $scanned=0; $subjects=[]; $edgeBudget=10000;
        do {
            $rows=$this->posts->page((int)$context['user'],$context['start'],$context['end'],$after,201,$pdo!==null,null,$query);
            $more=count($rows)>200; $batch=array_slice($rows,0,200); $scanned+=count($batch);
            if ($scanned>10000) throw new DomainError('acgn_review_budget',202);
            $matches=$this->posts->evaluate($batch,$query,$pdo!==null); $discussions=[];
            foreach ($batch as $row) if ($matches[$row['id']]['truth']) $discussions[(int)$row['discussion_id']]=true;
            if ($discussions) {
                [$links,$totals]=$this->links($source,$owner,$pdo,array_keys($discussions),[],$edgeBudget);
                foreach ($links as $link) if ($link['eligible'] && (int)$totals[$link['discussion_id']]===1) $subjects[$link['subject_id']]=true;
            }
            if ($batch) $after=(int)end($batch)['id'];
        } while ($more);
        return $subjects;
    }
    public function matchPosts(array $rows,array $params,bool $lock): array
    {
        if (!$rows) return [];
        [$source,$owner,$pdo]=$this->reader((int)$rows[0]['user_id'],$lock);
        [$links]=$this->links($source,$owner,$pdo,array_values(array_unique(array_map('intval',array_column($rows,'discussion_id')))),$this->fields($params),$this->relationBudget);
        $matches=[];
        foreach ($links as $link) if ($link['eligible'] && $this->matches($params,$link['values'])) $matches[$link['discussion_id']]=true;
        $result=[]; foreach ($rows as $row) $result[$row['id']]=isset($matches[$row['discussion_id']]); return $result;
    }
    private function eventConnection(object $event): void
    {
        if ($event->pdo!==$this->db->pdo || !$this->db->pdo->inTransaction()) throw new \LogicException('ACGN facts require the shared transaction');
    }
    public function changing(object $event): void
    {
        $this->eventConnection($event); $this->db->sourceLock($event->sourceKey,$event->userId,true);
    }
    public function changed(object $event): void
    {
        $this->eventConnection($event);
        $version=$this->db->source($event->sourceKey,$event->userId,true);
        $this->outbox->emit('acgn.changed',$event->sourceKey.':'.$event->userId,$version,
            ['dependencies'=>['source:acgn'],'subject_ids'=>$event->subjectIds,'discussion_ids'=>$event->discussionIds],$event->userId ?: null);
    }
    public function enabled(object $event): void
    {
        if (!in_array($event->extension->getId(),['local-acgn-database','local-good-community'],true)) return;
        // Re-enabling a source may reveal changes made while our listener was absent.
        // This infrequent recovery includes users with zero records and NOT conditions.
        $trigger='source-enabled:'.Data::uuid();
        $this->outbox->enqueue('reconcile.scan',['after_user'=>0,'trigger'=>$trigger],'reconcile-scan:'.$trigger,3);
    }
    /** Bounded targeted fan-out; includes past holders to revoke lost eligibility. */
    public function fanout(array $payload): void
    {
        if (!($this->available)()) throw new DomainError('acgn_source_unavailable',202);
        $source=($this->source)();
        if (empty($source->capabilities()['available'])) throw new DomainError('acgn_source_unavailable',202);
        $after=(int)($payload['after'] ?? 0);
        $page=$source->affectedUsersPage($payload['subject_ids'] ?? [],$payload['discussion_ids'] ?? [],$after,200);
        if (($page['version'] ?? 0)!==1 || !isset($page['user_ids'],$page['complete'])) throw new DomainError('acgn_contract_unavailable',202);
        $holders=$this->db->all("SELECT DISTINCT h.user_id FROM lgc_achievement_grants h JOIN lgc_bindings d ON d.owner_kind='achievement' AND d.owner_id=h.achievement_id AND d.dependency_key='source:acgn' WHERE h.user_id>? ORDER BY h.user_id LIMIT 201",[$after]);
        $users=array_values(array_unique([...array_map('intval',$page['user_ids']),...array_map('intval',array_column($holders,'user_id'))])); sort($users);
        $batch=array_slice($users,0,200);
        foreach ($batch as $user) $this->outbox->enqueue('achievement.user',['user_id'=>$user,'dependencies'=>['source:acgn']],'acgn-user:'.$payload['event_id'].':'.$user,1);
        if ((!$page['complete'] || count($users)>200 || count($holders)>200) && $batch) {
            $next=(int)end($batch);
            if ($next<=$after) throw new DomainError('acgn_cursor_invalid',202);
            $this->outbox->enqueue('acgn.scan',array_replace($payload,['after'=>$next]),'acgn-scan:'.$payload['event_id'].':'.$next,1);
        }
    }
}
