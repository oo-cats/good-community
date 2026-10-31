<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Facts;

use Local\GoodCommunity\Infrastructure\{Data,Db,DomainError};

/** Providers are registered by trusted PHP code. User definitions only supply validated data. */
final class FactEngine
{
    private array $providers=[];
    public ?\Closure $achievementResolver=null;
    public ?\Closure $completionResolver=null;
    public ?\Closure $completedPeriodsResolver=null;
    public ?\Closure $completedCountResolver=null;
    public function __construct(private Db $db, private QueryEngine $queries)
    {
        $this->register('tasks.completed.count.v1',['title'=>'累计有效完成次数','type'=>'integer'],fn($p)=>$this->reference($p,'task_id','lgc_tasks'),function($params,$context) {
            if(!$this->completedCountResolver) throw new DomainError('completion_counter_unavailable',202);
            return (string)($this->completedCountResolver)($params,$context);
        });
        $this->register('tasks.completed_periods.count.v1',['title'=>'已完成周期数','type'=>'integer','requires_window'=>true],function ($params) {
            $params=$this->reference($params,'task_id','lgc_tasks');
            if(!(int)$this->db->scalar('SELECT period_seconds FROM lgc_tasks WHERE public_id=?',[Data::bin($params['task_id'])])) throw new DomainError('periodic_task_required');
            return ['task_id'=>$params['task_id']];
        },function ($params,$context) {
            if(!$this->completedPeriodsResolver) throw new DomainError('period_counter_unavailable',202);
            return (string)($this->completedPeriodsResolver)($params,$context);
        });
        $this->register('profile.avatar_present.v1',['title'=>'已设置头像','type'=>'boolean'],fn ($params)=>[],fn ($params,$context)=>(bool)$this->db->scalar('SELECT avatar_url FROM '.$this->db->native('users').' WHERE id=?'.(!empty($context['lock']) ? ' LOCK IN SHARE MODE' : ''),[$context['user']]));
        $this->register('profile.first_avatar_uploaded.v1',['title'=>'首次上传头像','type'=>'boolean'],fn ($params)=>$params,function ($params,$context) {
            $fact=$this->db->one("SELECT occurred_at FROM lgc_user_facts WHERE user_id=? AND fact_key='avatar.first'",[$context['user']]);
            if (!$fact) {
                $coverage=$this->db->scalar("SELECT coverage_from FROM lgc_source_states WHERE source_key='avatar-coverage' AND user_id=0");
                $joined=$this->db->scalar('SELECT joined_at FROM '.$this->db->native('users').' WHERE id=?',[$context['user']]);
                if ($coverage && $joined && $joined>=$coverage) return false;
                throw new DomainError('first_avatar_history_unknown',202,'首次上传历史尚未得到证明');
            }
            return (!$context['start'] || $fact['occurred_at']>=$context['start']) && (!$context['end'] || $fact['occurred_at']<$context['end']);
        });
        $this->register('achievements.held.v1',['title'=>'具有成就','type'=>'boolean'],fn ($p)=>$this->reference($p,'achievement_id','lgc_achievements'),function ($params,$context) {
            if ($this->achievementResolver) return ($this->achievementResolver)($context['user'],$params['achievement_id'],$context);
            return (bool)$this->db->scalar('SELECT 1 FROM lgc_achievement_grants g JOIN lgc_achievements a ON a.id=g.achievement_id WHERE g.user_id=? AND a.public_id=? AND g.active_sources>0',[$context['user'],Data::bin($params['achievement_id'])]);
        });
        $this->register('tasks.completed.v1',['title'=>'已完成前置任务','type'=>'boolean'],fn ($p)=>$this->reference($p,'task_id','lgc_tasks'),function ($params,$context) {
            if (!empty($context['lock']) && $this->completionResolver) return ($this->completionResolver)($params,$context);
            $task=$this->db->one('SELECT id,period_seconds FROM lgc_tasks WHERE public_id=?',[Data::bin($params['task_id'])]);
            $sql='SELECT 1 FROM lgc_task_claims WHERE user_id=? AND task_id=? AND active=1'; $args=[$context['user'],$task['id']];
            if ((int)$task['period_seconds']>0 || ($params['scope'] ?? '')==='current_period') {
                if ($context['start']) { $sql.=' AND claimed_at>=?'; $args[]=$context['start']; }
                if ($context['end']) { $sql.=' AND claimed_at<?'; $args[]=$context['end']; }
            }
            return (bool)$this->db->scalar($sql.' LIMIT 1'.(!empty($context['lock']) ? ' LOCK IN SHARE MODE' : ''),$args);
        });
        $this->register('date.now.v1',['title'=>'当前日期','type'=>'instant'],fn ($params)=>[],static function ($params,$context) { $date=new \DateTimeImmutable($context['now'],new \DateTimeZone('UTC')); return (string)((int)$date->format('U')*1000+(int)$date->format('v')); });
        $this->register('wallet.balance.v1',['title'=>'货币余额或经验值','type'=>'integer','help'=>'选择一种货币或经验，判断用户当前的数值，例如光玉余额至少100，或经验值至少30。'],fn ($p)=>$this->reference($p,'currency_id','lgc_currencies'),fn ($p,$c)=>(string)($this->db->scalar('SELECT w.balance FROM lgc_wallets w JOIN lgc_currencies r ON r.id=w.currency_id WHERE w.user_id=? AND r.public_id=?'.(!empty($c['lock']) ? ' LOCK IN SHARE MODE' : ''),[$c['user'],Data::bin($p['currency_id'])]) ?: '0'));
    }
    private array $sourceGuards=[];
    public function sourceGuard(string $dependency,callable $guard): void { $this->sourceGuards[$dependency]=$guard; ksort($this->sourceGuards); }
    public function register(string $method, array $schema, callable $validate, callable $read, array $hooks=[]): void
    {
        if (isset($this->providers[$method]) || !preg_match('/^[a-z][a-z0-9_.]+\.v[0-9]+$/D',$method)) throw new \InvalidArgumentException('Invalid or duplicate fact method');
        if(!in_array($schema['type'] ?? '',['integer','decimal','boolean','string','instant','duration'],true)) throw new \InvalidArgumentException('Invalid fact value type');
        foreach($hooks as $key=>$hook) if(!in_array($key,['dependencies','references','assertPeriodic'],true) || !is_callable($hook)) throw new \InvalidArgumentException('Invalid fact hook');
        $this->providers[$method]=['schema'=>$schema,'validate'=>$validate,'read'=>$read,'hooks'=>$hooks];
    }
    public function catalog(): array
    {
        $result=[]; foreach ($this->providers as $method=>$provider) $result[]=['method'=>$method]+$provider['schema'];
        return [['method'=>'query.posts.count.v1','title'=>'符合条件的帖子数','type'=>'integer'],...$result];
    }
    private function reference(array $params, string $key, string $table): array
    {
        $params[$key]=Data::id(Data::bin((string)($params[$key] ?? '')));
        if (!$this->db->scalar('SELECT id FROM '.$table.' WHERE public_id=?',[Data::bin((string)($params[$key] ?? ''))])) throw new DomainError('referenced_object_not_found');
        return $params;
    }
    private function valueSpec(array $value): array
    {
        if (array_key_exists('literal',$value)) return $value;
        $method=$value['method'] ?? ''; $params=$value['params'] ?? [];
        if ($method==='query.posts.count.v1') $params=['query'=>$this->queries->normalize($params['query'] ?? [])];
        elseif (isset($this->providers[$method])) $params=($this->providers[$method]['validate'])($params);
        else throw new DomainError('method_unavailable',409);
        return ['method'=>$method,'params'=>$params];
    }
    private function valueType(array $spec): ?string
    {
        $method=$spec['method'] ?? '';
        return $method==='query.posts.count.v1' ? 'integer' : ($this->providers[$method]['schema']['type'] ?? $spec['value_type'] ?? null);
    }
    private function comparison(array $params): array
    {
        $left=$this->valueSpec($params['left'] ?? []); $right=$this->valueSpec($params['right'] ?? ['literal'=>true]);
        $operator=$params['operator'] ?? '=';
        if(!in_array($operator,['=','!=','>','>=','<','<='],true)) throw new DomainError('invalid_operator');
        $a=$this->valueType($left); $b=$this->valueType($right); $type=$a ?? $b ?? (is_bool($left['literal'] ?? null) ? 'boolean' : (is_int($left['literal'] ?? null) ? 'integer' : 'string'));
        if($a && $b && $a!==$b && !(in_array($a,['integer','decimal'],true) && in_array($b,['integer','decimal'],true))) throw new DomainError('incompatible_values');
        if($type==='boolean' && !in_array($operator,['=','!='],true)) throw new DomainError('boolean_comparison_required');
        foreach([&$left,&$right] as &$spec) if(array_key_exists('literal',$spec)) {
            if($type==='boolean' && (!is_bool($spec['literal']) || !in_array($operator,['=','!='],true))) throw new DomainError('boolean_comparison_required');
            if(in_array($type,['integer','decimal','instant','duration'],true)) {
                if($type==='instant' && !is_numeric($spec['literal'])) $spec['literal']=(string)(strtotime(Data::date($spec['literal']).' UTC')*1000);
                Compare::number($spec['literal'],'0');
            }
            if($type==='string' && !is_string($spec['literal'])) throw new DomainError('string_required');
            $spec['value_type']=$type;
        }
        unset($spec);
        return compact('left','operator','right');
    }
    public function store(array $goal, array $definitions): array
    {
        if (!$goal || count($goal)>32 || strlen(Data::json([$goal,$definitions]))>65536) throw new DomainError('invalid_goal');
        $stored=[];
        foreach ($definitions as $definition) {
            $original=$definition['atom_id'] ?? Data::uuid(); Data::bin($original);
            $method=$definition['atom_method'] ?? 'compare.v1'; $params=$definition['params'] ?? [];
            if ($method==='compare.v1') {
                $params=$this->comparison($params);
            } else {
                if (!isset($this->providers[$method]) || $this->providers[$method]['schema']['type']!=='boolean') throw new DomainError('method_unavailable');
                $params=($this->providers[$method]['validate'])($params);
            }
            if (strlen(Data::json($params))>16384) throw new DomainError('atom_budget_exceeded');
            $hash=Data::hash([$method,$params]);
            $row=$this->db->one('SELECT * FROM lgc_atoms WHERE definition_hash=?',[$hash]);
            if ($row) {
                if ($row['atom_method']!==$method || Data::canonical(Data::decode($row['params']))!==Data::canonical($params)) throw new DomainError('atom_hash_conflict',409);
                $actual=Data::id($row['atom_id']);
            } else {
                $actual=Data::uuid();
                $this->db->run('INSERT INTO lgc_atoms(atom_id,atom_method,params,definition_hash,created_at) VALUES(?,?,?,?,?)',[Data::bin($actual),$method,Data::json($params),$hash,Data::now()]);
            }
            $stored[$original]=$actual;
        }
        foreach ($goal as &$clause) {
            if (!$clause || count($clause)>32) throw new DomainError('invalid_clause');
            foreach ($clause as &$term) {
                $id=$stored[$term['atom_id'] ?? ''] ?? ($term['atom_id'] ?? '');
                if (!$this->db->scalar('SELECT atom_id FROM lgc_atoms WHERE atom_id=?',[Data::bin($id)])) throw new DomainError('atom_not_found');
                $term=['atom_id'=>$id,'negated'=>(bool)($term['negated'] ?? false)];
            } unset($term);
        } unset($clause);
        return $goal;
    }
    public function atoms(array $goal): array
    {
        $ids=[]; foreach ($goal as $clause) foreach ($clause as $term) $ids[$term['atom_id']]=true;
        if (!$ids) return [];
        $rows=$this->db->all('SELECT * FROM lgc_atoms WHERE atom_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_map([Data::class,'bin'],array_keys($ids)));
        $result=[]; foreach ($rows as $row) { $id=Data::id($row['atom_id']); $result[$id]=['atom_id'=>$id,'atom_method'=>$row['atom_method'],'params'=>Data::decode($row['params'])]; }
        if (count($result)!==count($ids)) throw new DomainError('atom_unavailable',202);
        return $result;
    }
    /** Check every operand and every branch, including paths not reached by short circuit. */
    public function assertPeriodic(array $goal): void
    {
        foreach ($this->atoms($goal) as $atom) {
            $specs=$atom['atom_method']==='compare.v1' ? [$atom['params']['left'],$atom['params']['right']] : [['method'=>$atom['atom_method'],'params'=>$atom['params']]];
            foreach ($specs as $spec) {
                $method=$spec['method'] ?? '';
                if ($method==='query.posts.count.v1' && ($spec['params']['query']['time']['mode'] ?? 'inherit')!=='inherit') throw new DomainError('period_requires_current_window',422);
                if ($check=$this->providers[$method]['hooks']['assertPeriodic'] ?? null) $check($spec['params'] ?? []);
                if (($this->providers[$method]['schema']['temporal'] ?? '')==='action' && ($spec['params']['time']['mode'] ?? 'inherit')!=='inherit') throw new DomainError('period_requires_current_window',422);
            }
        }
    }
    public function requiresWindow(array $goal): bool
    {
        foreach($this->atoms($goal) as $atom) {
            $specs=$atom['atom_method']==='compare.v1' ? [$atom['params']['left'],$atom['params']['right']] : [['method'=>$atom['atom_method']]];
            foreach($specs as $spec) if(!empty($this->providers[$spec['method'] ?? '']['schema']['requires_window'])) return true;
        }
        return false;
    }
    private function lowerBound(array $atom, bool $negated=false): ?int
    {
        $p=$atom['params'];
        if ($negated || $atom['atom_method']!=='compare.v1' || ($p['left']['method'] ?? '')!=='query.posts.count.v1' || !in_array($p['operator'],['>=','>'],true)) return null;
        $target=$p['right']['literal'] ?? null;
        if ((!is_string($target) && !is_int($target)) || !preg_match('/^[0-9]{1,4}$/D',(string)$target)) return null;
        $required=(int)$target+($p['operator']==='>' ? 1 : 0);
        if ($required>1000) return null; // Larger thresholds use the bounded exact path.
        return $required>0 ? $required : null;
    }
    private function readValue(array $spec, array $context, ?int $task, array &$cache, bool $exact): mixed
    {
        if (array_key_exists('literal',$spec)) return $spec['literal'];
        $key=Data::hash([$spec,$task,$exact]);
        if (array_key_exists($key,$cache)) return $cache[$key];
        $method=$spec['method'];
        if ($method==='query.posts.count.v1') $value=$exact ? $this->queries->exact($spec['params']['query'],$context,$task,true) : $this->queries->read($spec['params']['query'],$context,$task);
        elseif (isset($this->providers[$method])) $value=($this->providers[$method]['read'])($spec['params'],$context);
        else throw new DomainError('method_unavailable',202);
        return $cache[$key]=$value;
    }
    public function evaluate(array $goal, array $context, ?int $task=null, bool $exact=false, bool $claim=false): array
    {
        $atoms=$this->atoms($goal); $cache=[]; $results=[]; $clauses=[]; $errors=[]; $pending=null;
        // Acquire source fences before any branch takes entity locks. Availability is
        // still evaluated per atom, so an unavailable OR branch cannot block another.
        if (!empty($context['lock']) && $this->sourceGuards) {
            $dependencies=$this->dependencies($goal);
            foreach ($this->sourceGuards as $key=>$guard) if (in_array($key,$dependencies,true)) $guard($context);
        }
        foreach ($goal as $index=>$clause) {
            $truth=true; $unknown=null;
            foreach ($clause as $term) {
                $id=$term['atom_id']; $atom=$atoms[$id]; $required=$claim ? $this->lowerBound($atom,$term['negated']) : null;
                // Polarity changes the proof required for a repeated atom.
                $verified=$exact || $claim;
                $resultKey=$id.':'.(int)$verified;
                try {
                if(isset($errors[$resultKey])) throw $errors[$resultKey];
                if (!isset($cache['atom:'.$resultKey])) {
                    $p=$atom['params'];
                    if ($atom['atom_method']==='compare.v1') {
                        $left=$required!==null ? $this->queries->atLeast($p['left']['params']['query'],$context,$required) : $this->readValue($p['left'],$context,null,$cache,$verified); $right=$this->readValue($p['right'],$context,null,$cache,$verified);
                        $count=is_array($left) && isset($left['count']); $value=$count ? (string)$left['count'] : $left;
                        if (is_array($right) && isset($right['count'])) $right=(string)$right['count'];
                        $type=$this->valueType($p['left']) ?? $this->valueType($p['right']) ?? (is_int($value) ? 'integer' : 'string');
                        $numeric=in_array($type,['integer','decimal','instant','duration'],true);
                        $ok=Compare::test($value,$p['operator'],$right,$numeric);
                        $results[$id]=['truth'=>$ok,'value'=>$value,'target'=>$right,'query'=>$count ? $left : null];
                    } else $results[$id]=['truth'=>(bool)$this->readValue(['method'=>$atom['atom_method'],'params'=>$p],$context,null,$cache,$verified),'value'=>null,'target'=>true,'query'=>null];
                    $cache['atom:'.$resultKey]=$results[$id];
                }
                $result=$results[$id]=$cache['atom:'.$resultKey];
                } catch(DomainError $error) {
                    if($error->status!==202) throw $error;
                    $errors[$resultKey]=$error; $unknown ??= $error; continue;
                }
                $literal=$term['negated'] ? !$result['truth'] : $result['truth'];
                if (!$literal) { $truth=false; break; }
            }
            $clauses[]=['index'=>$index,'truth'=>$truth && $unknown ? null : $truth];
            if ($truth && !$unknown) return ['truth'=>true,'clause'=>$index,'atoms'=>$results,'clauses'=>$clauses];
            if($truth) $pending ??= $unknown;
        }
        if($pending) throw $pending;
        return ['truth'=>false,'clause'=>null,'atoms'=>$results,'clauses'=>$clauses];
    }
    public function references(array $goal,?array $definitions=null): array
    {
        $references=[];
        $ids=[]; foreach($goal as $clause) foreach($clause as $term) $ids[$term['atom_id']]=true;
        foreach ($definitions===null ? $this->atoms($goal) : array_intersect_key($definitions,$ids) as $atom) {
            $specs=$atom['atom_method']==='compare.v1' ? [$atom['params']['left'],$atom['params']['right']] : [['method'=>$atom['atom_method'],'params'=>$atom['params']]];
            foreach ($specs as $spec) {
                $key=match ($spec['method'] ?? '') { 'tasks.completed.v1','tasks.completed_periods.count.v1','tasks.completed.count.v1'=>'task_id','achievements.held.v1'=>'achievement_id',default=>null };
                if ($key) $references[]=[$key==='task_id' ? 'task' : 'achievement',$spec['params'][$key]];
                if($hook=$this->providers[$spec['method'] ?? '']['hooks']['references'] ?? null) array_push($references,...$hook($spec['params'] ?? []));
            }
        }
        return $references;
    }
    public function dependencies(array $goal): array
    {
        $keys=[]; $atoms=$this->atoms($goal);
        foreach ($this->references($goal,$atoms) as [$kind,$id]) $keys[]=$kind.':'.$id;
        foreach ($atoms as $atom) {
            $specs=$atom['atom_method']==='compare.v1' ? [$atom['params']['left'],$atom['params']['right']] : [['method'=>$atom['atom_method'],'params'=>$atom['params']]];
            foreach ($specs as $spec) {
                $method=$spec['method'] ?? '';
                if ($method==='query.posts.count.v1') {
                    $keys[]='source:posts'; $query=$spec['params']['query'];
                    if (!empty($query['relations']['acgn'])) $keys[]='source:acgn';
                    if ($query['requires_rating']) $keys[]='source:rating';
                    foreach ($query['where'] as $clause) foreach ($clause as $term) foreach ([$term['left']['field'],$term['right']['field'] ?? ''] as $field) {
                        if ($field==='likes.count') $keys[]='source:likes';
                        if (str_contains($field,'-')) $keys[]='field:'.$field;
                    }
                    if (!empty($query['admin_tags']['option_ids'])) $keys[]='field:'.$query['admin_tags']['field_id'];
                }
                if (str_starts_with($method,'profile.')) $keys[]='source:profile';
                if ($method==='wallet.balance.v1') $keys[]='source:wallet';
                foreach ($this->providers[$method]['schema']['dependencies'] ?? [] as $dependency) $keys[]=$dependency;
                if($hook=$this->providers[$method]['hooks']['dependencies'] ?? null) array_push($keys,...$hook($spec['params'] ?? []));
            }
        }
        return array_values(array_unique($keys));
    }
    public function bind(string $kind,int $id,int $version,array $goal): void
    {
        $dependencies=$this->dependencies($goal); if(!$dependencies) return;
        foreach(array_chunk($dependencies,200) as $batch) {
            $args=[]; foreach($batch as $dependency) array_push($args,$kind,$id,$version,$dependency);
            $this->db->run('INSERT IGNORE INTO lgc_bindings(owner_kind,owner_id,owner_version,dependency_key) VALUES '.implode(',',array_fill(0,count($batch),'(?,?,?,?)')),$args);
        }
    }
    public function verifyTime(array $goal, int $clause, array $context): void
    {
        foreach ($this->atoms([$goal[$clause]]) as $atom) {
            if ($atom['atom_method']!=='compare.v1') continue;
            $p=$atom['params'];
            if (($p['left']['method'] ?? '')!=='date.now.v1' && ($p['right']['method'] ?? '')!=='date.now.v1') continue;
            $cache=[];
            $left=$this->readValue($p['left'],$context,null,$cache,false); $right=$this->readValue($p['right'],$context,null,$cache,false);
            $truth=Compare::test($left,$p['operator'],$right,true);
            foreach ($goal[$clause] as $term) if ($term['atom_id']===$atom['atom_id'] && ($term['negated'] ? $truth : !$truth)) throw new DomainError('time_condition_changed',409);
        }
    }
}
