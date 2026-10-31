<?php
declare(strict_types=1);
namespace Local\GoodCommunity\PostFields;

use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError,Outbox};
use Local\GoodCommunity\Rating\RatingService;

final class Fields
{
    public function __construct(private Db $db, private Outbox $outbox, private RatingService $rating) {}
    public function list(): array
    {
        return array_map(fn ($row)=>['id'=>Data::id($row['public_id']),'name'=>$row['name'],'description'=>$row['description'],'value_type'=>$row['value_type'],'unit'=>$row['unit'],'options'=>Data::decode($row['options']),'rule_enabled'=>(bool)$row['rule_enabled'],'state'=>$row['state'],'version'=>(int)$row['row_version']],$this->db->all('SELECT * FROM lgc_post_fields ORDER BY sort_order,id'));
    }
    public function define(Actor $actor, array $input): array
    {
        $actor->assert('fields.manage');
        $type = $input['value_type'] ?? 'enum';
        if (!in_array($type,['integer','decimal','boolean','string','enum','set','instant','duration'],true)) throw new DomainError('invalid_field_type');
        $id = $input['id'] ?? Data::uuid(); $name=Data::name($input['name'] ?? '');
        $old=$this->db->one('SELECT * FROM lgc_post_fields WHERE public_id=?',[Data::bin($id)]);
        if (!$old && isset($input['id'])) throw new DomainError('field_not_found',404);
        $options=[];
        foreach ($input['options'] ?? [] as $option) { $key=$option['id'] ?? Data::uuid(); Data::bin($key); $options[]=['id'=>$key,'name'=>Data::name($option['name'] ?? '')]; }
        if (count(array_unique(array_column($options,'id'))) !== count($options) || count(array_unique(array_map([Data::class,'nameKey'],array_column($options,'name')))) !== count($options)) throw new DomainError('duplicate_option');
        if ($old) {
            if ($old['value_type'] !== $type || ($old['unit'] ?? null) !== ($input['unit'] ?? null)) throw new DomainError('field_type_immutable',409);
            if ((int)($input['expected_version'] ?? 0) !== (int)$old['row_version']) throw new DomainError('version_conflict',409);
            $oldIds=array_column(Data::decode($old['options']),'id');
            if (array_diff($oldIds,array_column($options,'id'))) throw new DomainError('option_identity_immutable');
            $changed=$this->db->run('UPDATE lgc_post_fields SET name=?,name_key=?,description=?,options=?,rule_enabled=?,row_version=row_version+1 WHERE id=? AND row_version=?',[$name,Data::nameKey($name),(string)($input['description'] ?? ''),Data::json($options),(int)(bool)($input['rule_enabled'] ?? true),$old['id'],$old['row_version']])->rowCount();
            if (!$changed) throw new DomainError('version_conflict',409);
        } else $this->db->run("INSERT INTO lgc_post_fields(public_id,name,name_key,description,value_type,unit,options,rule_enabled,state) VALUES(?,?,?,?,?,?,?,?,'active')",[Data::bin($id),$name,Data::nameKey($name),(string)($input['description'] ?? ''),$type,$input['unit'] ?? null,Data::json($options),(int)(bool)($input['rule_enabled'] ?? true)]);
        return ['id'=>$id,'options'=>$options];
    }
    public function read(int $post): array
    {
        $result=[];
        foreach ($this->db->all('SELECT f.public_id,f.value_type,v.* FROM lgc_post_field_values v JOIN lgc_post_fields f ON f.id=v.field_id WHERE v.post_id=?',[$post]) as $row) $result[Data::id($row['public_id'])]=self::value($row);
        $state=$this->db->one('SELECT marks_version FROM lgc_post_state WHERE post_id=?',[$post]);
        return ['values'=>$result,'version'=>(int)($state['marks_version'] ?? 0)];
    }
    public static function value(array $row): mixed
    {
        return match ($row['value_type']) { 'integer','instant','duration'=>(string)$row['integer_value'],'boolean'=>(bool)$row['integer_value'],'decimal'=>$row['decimal_value'],'string'=>$row['text_value'],'enum'=>Data::id($row['reference_value']),'set'=>Data::decode($row['set_value']) };
    }
    public function save(Actor $actor, int $postId, array $input): array
    {
        $actor->assert('fields.edit');
        if (count($input['values'] ?? []) > 200) throw new DomainError('field_budget_exceeded');
        $this->rating->state($this->rating->post($postId));
        return $this->db->transaction(function () use ($actor,$postId,$input) {
            $post=$this->rating->post($postId); $this->rating->state($post);
            $state=$this->db->one('SELECT * FROM lgc_post_state WHERE post_id=? FOR UPDATE',[$postId]);
            if ((int)($input['expected_version'] ?? 0) !== (int)$state['marks_version']) throw new DomainError('version_conflict',409);
            $before=$this->read($postId); $version=(int)$state['marks_version']+1;
            foreach ($input['values'] ?? [] as $uuid=>$value) {
                $field=$this->db->one("SELECT * FROM lgc_post_fields WHERE public_id=? AND state='active'",[Data::bin($uuid)]);
                if (!$field) throw new DomainError('field_not_found',404);
                if ($value === null) { $this->db->run('DELETE FROM lgc_post_field_values WHERE post_id=? AND field_id=?',[$postId,$field['id']]); continue; }
                $slots=[null,null,null,null,null];
                switch ($field['value_type']) {
                    case 'integer': case 'duration': $slots[0]=Data::integer($value,true,18); break;
                    case 'instant': $time=Data::date((string)$value); if (!$time) throw new DomainError('invalid_date'); $slots[0]=(string)(strtotime($time.' UTC')*1000); break;
                    case 'boolean': if (!is_bool($value)) throw new DomainError('boolean_required'); $slots[0]=(int)$value; break;
                    case 'decimal': if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,20})(?:\.[0-9]{1,9})?$/D',$value)) throw new DomainError('decimal_required'); $slots[1]=$value; break;
                    case 'string': if (!is_string($value) || mb_strlen($value)>255) throw new DomainError('string_too_long'); $slots[2]=$value; break;
                    case 'enum': case 'set':
                        $ids=$field['value_type']==='set' ? $value : [$value];
                        if (!is_array($ids) || array_diff($ids,array_column(Data::decode($field['options']),'id'))) throw new DomainError('invalid_option');
                        if ($field['value_type']==='set') { $ids=array_values(array_unique($ids)); sort($ids); $slots[4]=Data::json($ids); } else $slots[3]=Data::bin((string)$value);
                        break;
                }
                $this->db->run('INSERT INTO lgc_post_field_values(post_id,field_id,integer_value,decimal_value,text_value,reference_value,set_value) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE integer_value=VALUES(integer_value),decimal_value=VALUES(decimal_value),text_value=VALUES(text_value),reference_value=VALUES(reference_value),set_value=VALUES(set_value)',[$postId,$field['id'],...$slots]);
            }
            $this->db->run('UPDATE lgc_post_state SET marks_version=?,updated_at=? WHERE post_id=?',[$version,Data::now(),$postId]);
            $after=$this->read($postId);
            $this->db->run("INSERT INTO lgc_post_field_history(post_id,version,operation_key,payload_hash,actor_id,action,snapshot,created_at) VALUES(?,?,?,?,?,'update',?,?)",[$postId,$version,hash('sha256','marks:'.$postId.':'.$version),Data::hash($input),$actor->id,Data::json(['before'=>$before['values'],'after'=>$after['values'],'reason'=>(string)($input['reason'] ?? '')]),Data::now()]);
            $sourceVersion=$this->db->source('posts',(int)$post['user_id'],true);
            $this->outbox->emit('fields.changed','post-marks:'.$postId,$version,['post_id'=>$postId,'source_version'=>$sourceVersion,'field_ids'=>array_keys($input['values'] ?? [])],(int)$post['user_id']);
            return $after;
        });
    }
}
