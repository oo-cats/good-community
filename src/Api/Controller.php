<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Api;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Worker};
use Local\GoodCommunity\Tasks\{TaskService,Folders,Statistics};
use Local\GoodCommunity\Currency\Ledger;
use Local\GoodCommunity\Rating\RatingService;
use Local\GoodCommunity\PostFields\Fields;
use Local\GoodCommunity\Facts\{FactEngine,QueryEngine,QuerySpec};
use Local\GoodCommunity\Achievements\Achievements;

final class Controller implements RequestHandlerInterface
{
    public function __construct(private Db $db, private TaskService $tasks, private Folders $folders, private Statistics $stats, private Ledger $ledger, private RatingService $rating, private Fields $fields, private FactEngine $facts, private QueryEngine $queries, private Achievements $achievements, private Worker $worker, private \Psr\Log\LoggerInterface $logger) {}
    public function handle(ServerRequestInterface $request): JsonResponse
    {
        try {
            $user=$request->getAttribute('actor'); $actor=Actor::fromUser($user);
            $query=$request->getQueryParams(); $body=(array)$request->getParsedBody();
            if (strlen(Data::json($body))>131072) throw new DomainError('request_too_large',413);
            $path=$request->getUri()->getPath(); $position=strpos($path,'/community'); $path=substr($path,$position===false ? 0 : $position);
            $result=$this->dispatch($actor,$user,$request->getMethod(),$path,(string)($query['id'] ?? ''),$query,$body);
            return new JsonResponse($result,200,['Cache-Control'=>'no-store']);
        } catch (DomainError $error) { return new JsonResponse(['error'=>$error->reason,'message'=>$error->getMessage()],$error->status,['Cache-Control'=>'no-store']); }
        catch (\PDOException $error) {
            $duplicate=(int)($error->errorInfo[1] ?? 0)===1062;
            $this->logger->error('Community database operation failed',['sqlstate'=>$error->getCode(),'driver_code'=>(int)($error->errorInfo[1] ?? 0)]);
            return new JsonResponse(['error'=>$duplicate ? 'name_or_identity_conflict' : 'database_operation_failed'],$duplicate ? 409 : 500);
        } catch (\Throwable $error) { $this->logger->error('Community request failed',['error_type'=>get_class($error)]); return new JsonResponse(['error'=>'operation_failed'],500); }
    }
    private function visiblePost($user, int $id): void
    {
        if (!\Flarum\Post\CommentPost::whereVisibleTo($user)->where('id',$id)->exists()) throw new DomainError('post_not_available',404);
    }
    private function dispatch(Actor $actor, $nativeActor, string $method, string $path, string $id, array $query, array $body): array
    {
        if ($path==='/community/meta') {
            $groups=$this->db->all('SELECT id,name_singular name FROM '.$this->db->native('groups').' WHERE id NOT IN (SELECT group_id FROM lgc_permission_groups) ORDER BY id'); $tags=[];
            if (class_exists(\Flarum\Tags\Tag::class) && $this->db->scalar('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$this->db->prefix.'tags'])) $tags=\Flarum\Tags\Tag::whereVisibleTo($nativeActor)->get(['id','name','parent_id'])->toArray();
            $keys=['tasks.manage','rating.manage','rating.rate','fields.manage','fields.edit','currency.manage','currency.issue','currency.deduct','currency.transfer','currency.viewHistory','experience.issue','achievements.manage','achievements.grant','achievements.revoke'];
            return ['methods'=>$this->facts->catalog(),'system_fields'=>array_map(static fn($field)=>$field['type'],$this->queries->systemFields()), 'system_field_definitions'=>$this->queries->systemFields(),'fields'=>$this->fields->list(),'resources'=>$this->ledger->resources($actor,false),'groups'=>$groups,'board_tags'=>$tags,'achievements'=>$this->achievements->list(null,false),'access_rewards'=>resolve(\Local\GoodCommunity\Access\Rewards::class)->catalog($actor),'permissions'=>array_combine($keys,array_map(fn ($key)=>$actor->can($key),$keys))];
        }
        if ($path==='/community/tasks') return $method==='GET' ? $this->tasks->list($actor,$query) : $this->tasks->save($actor,$body);
        if (preg_match('~^/community/tasks/[^/]+/(pin|claim|check|publish|close|copy|stats|rebuild-stats)$~D',$path,$match)) return match ($match[1]) {
            'pin'=>$this->tasks->pin($actor,$id,(bool)($body['is_pinned'] ?? false),(int)($body['expected_version'] ?? 0)),'claim'=>$this->claim($actor,$id,$body),'check'=>['progress'=>$this->tasks->check($actor,$id)],'publish'=>$this->tasks->publish($actor,$id),'close'=>$this->tasks->close($actor,$id),'copy'=>$this->copy($actor,$id,$body),'stats'=>$this->stats->get($actor,$id,$query),'rebuild-stats'=>$this->stats->rebuild($actor,$id),
        };
        if (preg_match('~^/community/tasks/[^/]+$~D',$path)) return match ($method) { 'GET'=>$this->taskDetail($actor,$nativeActor,$id,!empty($query['manage'])),'PATCH'=>$this->tasks->save($actor,$body,$id),'DELETE'=>$this->tasks->delete($actor,$id) };
        if ($path==='/community/task-stats/query') return ['items'=>$this->stats->batch($actor,$body['items'] ?? [])];
        if ($path==='/community/folders') return $method==='GET' ? ['items'=>$this->folders->list($actor)] : $this->folders->save($actor,$body);
        if (str_starts_with($path,'/community/folders/')) return $this->folders->delete($actor,$id);
        if ($path==='/community/resource-history') {
            $subject=(int)($query['user_id'] ?? $actor->id); if(!$subject) throw new DomainError('login_required',403);
            return resolve(\Local\GoodCommunity\Currency\Activity::class)->history($actor,$subject,$query);
        }
        if ($path==='/community/achievement-catalog') return $this->achievements->catalog($actor,$query);
        if (preg_match('~^/community/posts/([0-9]+)/tips$~D',$path,$tip)) { $this->visiblePost($nativeActor,(int)$tip[1]); return resolve(\Local\GoodCommunity\Currency\Activity::class)->tips((int)$tip[1],$query); }
        if ($path==='/community/resources') return $method==='GET' ? ['items'=>$this->ledger->resources(isset($query['user_id']) && (int)$query['user_id']!==$actor->id ? new Actor((int)$query['user_id']) : $actor)] : ['resource_id'=>Data::id($this->ledger->define($actor,$body)['public_id'])];
        if ($path==='/community/money') {
            if(($body['action'] ?? '')==='tip') throw new DomainError('use_post_tip_endpoint');
            if(isset($body['post_id'])) $this->visiblePost($nativeActor,(int)$body['post_id']);
            return ['receipt'=>$this->ledger->command($actor,$body)];
        }
        if ($path==='/community/money/reverse') return ['receipt'=>$this->ledger->reverse($actor,$body['transaction_id'] ?? '')];
        if ($path==='/community/fields') return $method==='GET' ? ['items'=>$this->fields->list()] : $this->fields->define($actor,$body);
        if ($path==='/community/rating-policies') {
            if ($method==='POST') return $this->rating->policy($actor,$body);
            $actor->assert('rating.manage');
            return ['items'=>array_map(fn ($row)=>['id'=>Data::id($row['public_id']),'name'=>$row['name'],'scope'=>Data::decode($row['scope_spec']),'voter_kind'=>$row['voter_kind'],'group_ids'=>Data::decode($row['group_ids']),'priority'=>(int)$row['priority'],'version'=>(int)$row['row_version']],$this->db->all('SELECT * FROM lgc_rating_policies ORDER BY priority DESC'))];
        }
        if (preg_match('~^/community/posts/[0-9]+/(rating|fields|tip)$~D',$path,$match)) {
            $post=(int)$id; $this->visiblePost($nativeActor,$post);
            if ($match[1]==='rating' && $method==='POST' && ($body['action'] ?? '')!=='withdraw' && !preg_match('/^[a-f0-9]{64}$/D',(string)($body['expected_content_hash'] ?? ''))) throw new DomainError('content_snapshot_required');
            return match ($match[1]) {
                'rating'=>$method==='GET' ? $this->rating->read($actor,$post) : $this->rating->vote($actor,$post,$body),
                'fields'=>$method==='GET' ? $this->fields->read($post) : $this->fields->save($actor,$post,$body),
                'tip'=>['receipt'=>$this->ledger->command($actor,array_replace($body,['action'=>'tip','post_id'=>$post,'user_id'=>(int)$this->rating->post($post)['user_id']]))],
            };
        }
        if ($path==='/community/post-summaries') return $this->postSummaries($actor,$nativeActor,$query);
        if ($path==='/community/achievements') {
            if ($method==='POST') return $this->achievements->define($actor,$body);
            if (!empty($query['manage'])) { $actor->assert('achievements.manage'); return ['items'=>$this->achievements->list(null,true)]; }
            return ['items'=>$this->achievements->list((int)($query['user_id'] ?? $actor->id))];
        }
        if (preg_match('~^/community/achievements/[^/]+/(grant|sources|archive)$~D',$path,$match)) return match ($match[1]) { 'grant'=>$this->achievements->manual($actor,$id,$body),'sources'=>['items'=>$this->achievements->sources($actor,$id,(int)($query['user_id'] ?? 0))],'archive'=>$this->achievements->archive($actor,$id) };
        if ($path==='/community/query-preview') { $actor->assert('tasks.manage'); $context=['user'=>(int)($body['user_id'] ?? $actor->id),'start'=>Data::date($body['start_at'] ?? null),'end'=>Data::date($body['stop_at'] ?? null)]; $result=$this->queries->read($this->queries->normalize($body['query'] ?? []),$context); unset($result['ids']); return $result; }
        if ($path==='/community/work') { $actor->assert('tasks.manage'); return $this->worker->run(20,3); }
        throw new DomainError('route_not_found',404);
    }
    private function claim(Actor $actor, string $id, array $body): array
    {
        if (array_diff(array_keys($body),['request_id','occurrence','rule_version'])) throw new DomainError('unexpected_claim_parameters');
        return ['receipt'=>$this->tasks->claim($actor,$id,$body)];
    }
    private function taskDetail(Actor $actor, $nativeActor, string $id, bool $manage): array
    {
        $result=$this->tasks->detail($actor,$id,true,$manage); $result['reference_names']=[]; $result['reference_periods']=[];
        foreach ($this->facts->references($result['goal']) as [$kind,$uuid]) {
            if ($kind==='achievement') $name=$this->db->scalar('SELECT name FROM lgc_achievements WHERE public_id=?',[Data::bin($uuid)]);
            else { $task=$this->tasks->find($uuid); $name=$this->tasks->visible($actor,$task) ? $task['name'] : null; if($name) $result['reference_periods'][$uuid]=(int)$task['period_seconds']; }
            if ($name) $result['reference_names'][$uuid]=$name;
        }
        if ($result['associate_post']) {
            $post=\Flarum\Post\CommentPost::whereVisibleTo($nativeActor)->where('id',$result['associate_post'])->first(['id','discussion_id','number']);
            if ($post) $result['associate_location']=['discussion_id'=>(int)$post->discussion_id,'number'=>(int)$post->number];
        }
        return $result;
    }
    private function copy(Actor $actor, string $id, array $body): array
    {
        $actor->assert('tasks.manage'); $task=$this->tasks->detail($actor,$id,false,true);
        return $this->tasks->save($actor,array_replace($task,['name'=>$body['name'] ?? $task['name'].' 副本','start_at'=>$body['start_at'] ?? Data::now(),'stop_at'=>$body['stop_at'] ?? null]));
    }
    private function postSummaries(Actor $actor, $nativeActor, array $query): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($query['ids'] ?? ''))))));
        if (count($ids)>100) throw new DomainError('post_batch_limit');
        $ids=\Flarum\Post\CommentPost::whereVisibleTo($nativeActor)->whereIn('posts.id',$ids)->pluck('posts.id')->all();
        if (!$ids) return ['items'=>[]]; $slots=implode(',',array_fill(0,count($ids),'?'));
        $votes=array_column($this->db->all('SELECT post_id,score_hundredths,state FROM lgc_post_votes WHERE user_id=? AND post_id IN ('.$slots.')',[$actor->id,...$ids]),null,'post_id');
        $states=array_column($this->db->all('SELECT post_id,rating_count,rating_sum_hundredths FROM lgc_post_state WHERE post_id IN ('.$slots.')',$ids),null,'post_id');
        $union=implode(' UNION ALL ',array_fill(0,count($ids),"(SELECT id,public_id,actor_id,reason,posted_at,related_post_id FROM lgc_transactions WHERE related_post_id=? AND kind='tip' ORDER BY id DESC LIMIT 3)"));
        $tips=[];
        foreach($this->db->all('SELECT t.*,u.username,e.delta,c.name currency_name,EXISTS(SELECT 1 FROM lgc_transactions r WHERE r.reversal_of=t.id) reversed FROM ('.$union.') t JOIN lgc_entries e ON e.transaction_id=t.id AND e.delta>0 JOIN lgc_currencies c ON c.id=e.currency_id LEFT JOIN '.$this->db->native('users').' u ON u.id=t.actor_id ORDER BY t.id DESC',$ids) as $row) $tips[(int)$row['related_post_id']][]=['id'=>Data::id($row['public_id']),'username'=>$row['username'] ?: '已删除用户','amount'=>(string)$row['delta'],'currency_name'=>$row['currency_name'],'created_at'=>$row['posted_at'],'reversed'=>(bool)$row['reversed']];
        return ['items'=>array_map(function ($id) use ($votes,$states,$tips) { $state=$states[$id] ?? []; $count=(int)($state['rating_count'] ?? 0); return ['post_id'=>(int)$id,'count'=>$count,'average'=>$count ? number_format((int)$state['rating_sum_hundredths']/(100*$count),2,'.','') : null,'rated'=>($votes[$id]['state'] ?? '')==='active','tips'=>$tips[$id] ?? []]; },$ids)];
    }
}
