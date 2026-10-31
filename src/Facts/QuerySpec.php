<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Facts;

use Local\GoodCommunity\Infrastructure\{Data,DomainError};

final class QuerySpec
{
    public static function normalize(array $query, array $fields, array $systemTypes): array
    {
        if (strlen(Data::json($query))>16384 || ($query['kind'] ?? 'post_count') !== 'post_count' || ($query['author']['kind'] ?? 'subject') !== 'subject' || ($query['visibility']['kind'] ?? 'public') !== 'public') throw new DomainError('invalid_query');
        $type=$query['post_type'] ?? 'all'; if (!in_array($type,['all','topic','reply'],true)) throw new DomainError('invalid_post_type');
        $time=$query['time'] ?? ['mode'=>'inherit','field'=>'post.created_at'];
        if (!in_array($time['mode'] ?? '', ['inherit','fixed'],true) || ($time['field'] ?? 'post.created_at') !== 'post.created_at') throw new DomainError('invalid_time_mode');
        $time=['mode'=>$time['mode'],'field'=>'post.created_at']+($time['mode']==='fixed' ? ['start'=>Data::date($time['start'] ?? null),'end'=>Data::date($time['end'] ?? null)] : []);
        if (($time['start'] ?? null) && ($time['end'] ?? null) && $time['start'] >= $time['end']) throw new DomainError('invalid_interval');
        $board=$query['board_tags'] ?? ['ids'=>[],'match'=>'any','include_descendants'=>false];
        if (!in_array($board['match'] ?? 'any',['any','all'],true) || count($board['ids'] ?? [])>100) throw new DomainError('invalid_tags');
        $board=['ids'=>array_values(array_unique(array_map('intval',$board['ids'] ?? []))),'match'=>$board['match'] ?? 'any','include_descendants'=>(bool)($board['include_descendants'] ?? false)]; sort($board['ids']);
        $where=$query['where'] ?? null; $unfiltered=($query['unfiltered'] ?? false) === true;
        if (!$unfiltered && (!is_array($where) || !$where || count($where)>32)) throw new DomainError('query_terms_required');
        $normalized=[]; $requiresRating=false;
        foreach ($unfiltered ? [] : $where as $clause) {
            if (!is_array($clause) || !$clause || count($clause)>32) throw new DomainError('invalid_query_clause');
            $terms=[];
            foreach ($clause as $term) {
                $key=(string)($term['left']['field'] ?? ''); $field=$fields[$key] ?? null;
                $typeLeft=$systemTypes[$key] ?? ($field['value_type'] ?? null);
                if (!$typeLeft || ($field && (!$field['rule_enabled'] || $field['state']!=='active'))) throw new DomainError('field_unavailable',409);
                $operator=$term['operator'] ?? '=';
                if (!in_array($operator,['=','!=','>','>=','<','<=','has_any','has_all','is_set','is_unset'],true)) throw new DomainError('invalid_operator');
                if (in_array($operator,['has_any','has_all'],true) && $typeLeft!=='set') throw new DomainError('set_required');
                $right=$term['right'] ?? ['literal'=>null];
                if (isset($right['field'])) {
                    $rightField=$fields[$right['field']] ?? null;
                    if ($rightField && (!$rightField['rule_enabled'] || $rightField['state']!=='active')) throw new DomainError('field_unavailable',409);
                    $typeRight=$systemTypes[$right['field']] ?? ($rightField['value_type'] ?? null);
                    if (!$typeRight || ($typeRight!==$typeLeft && !in_array($typeRight.':'.$typeLeft,['integer:decimal','decimal:integer'],true))) throw new DomainError('incompatible_fields');
                    if (($field['unit'] ?? null)!==($rightField['unit'] ?? null)) throw new DomainError('incompatible_units');
                } elseif (!in_array($operator,['is_set','is_unset'],true)) {
                    $value=$right['literal'] ?? null; if (is_array($value) && isset($value['id'])) $value=$value['id'];
                    if (in_array($typeLeft,['integer','decimal','duration'],true)) Compare::number($value,'0');
                    if ($typeLeft==='instant') $value=is_string($value) && preg_match('/^-?[0-9]{1,18}$/D',$value) ? $value : (string)(strtotime(Data::date((string)$value).' UTC')*1000);
                    if ($typeLeft==='boolean' && !is_bool($value)) throw new DomainError('boolean_required');
                    if (in_array($typeLeft,['enum','set'],true)) { $ids=$typeLeft==='set' ? $value : [$value]; if (!is_array($ids) || array_diff($ids,array_column($field['options'],'id'))) throw new DomainError('invalid_option'); }
                    $right=['literal'=>$value];
                }
                if (str_starts_with($key,'rating.') || (isset($right['field']) && str_starts_with($right['field'],'rating.'))) $requiresRating=true;
                $terms[]=['left'=>['field'=>$key],'operator'=>$operator,'right'=>$right,'negated'=>(bool)($term['negated'] ?? false)];
            }
            $normalized[]=$terms;
        }
        $admin=$query['admin_tags'] ?? ['option_ids'=>[]];
        if (!empty($admin['option_ids'])) {
            $id=$admin['field_id'] ?? ''; $field=$fields[$id] ?? null;
            if (!$field || $field['value_type']!=='set' || !$field['rule_enabled'] || array_diff($admin['option_ids'],array_column($field['options'],'id'))) throw new DomainError('invalid_admin_tags');
            if (!in_array($admin['match'] ?? 'all',['any','all'],true)) throw new DomainError('invalid_tags');
        }
        return ['format_version'=>1,'kind'=>'post_count','author'=>['kind'=>'subject'],'visibility'=>['kind'=>'public'],'post_type'=>$type,'time'=>$time,'board_tags'=>$board,'admin_tags'=>$admin,'where'=>$normalized,'unfiltered'=>$unfiltered,'requires_rating'=>$requiresRating];
    }
    public static function matches(array $query, array $values, array $types): bool
    {
        if ($query['requires_rating'] && (int)($values['rating.count'] ?? 0)===0) return false;
        $admin=$query['admin_tags'];
        if (!empty($admin['option_ids']) && !Compare::test($values[$admin['field_id']] ?? null,($admin['match'] ?? 'all')==='any' ? 'has_any' : 'has_all',$admin['option_ids'])) return false;
        if ($query['unfiltered']) return true;
        foreach ($query['where'] as $clause) {
            $success=true;
            foreach ($clause as $term) {
                $left=$values[$term['left']['field']] ?? null;
                $right=isset($term['right']['field']) ? ($values[$term['right']['field']] ?? null) : ($term['right']['literal'] ?? null);
                $exists=$left!==null && ($right!==null || in_array($term['operator'],['is_set','is_unset'],true));
                $truth=Compare::test($left,$term['operator'],$right,in_array($types[$term['left']['field']] ?? '',['integer','decimal','instant','duration'],true));
                if ($term['negated']) $truth=in_array($term['operator'],['is_set','is_unset'],true) ? !$truth : $exists && !$truth;
                if (!$truth) { $success=false; break; }
            }
            if ($success) return true;
        }
        return false;
    }
}
