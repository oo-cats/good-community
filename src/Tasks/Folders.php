<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;
use Local\GoodCommunity\Infrastructure\{Actor,Data,Db,DomainError};

final class Folders
{
    public function __construct(private Db $db) {}
    public function list(Actor $actor): array
    {
        $actor->assert('tasks.manage');
        return array_map(fn ($r)=>['id'=>Data::id($r['public_id']),'name'=>$r['name'],'parent_id'=>$r['parent_uuid'] ? Data::id($r['parent_uuid']) : null,'depth'=>(int)$r['depth'],'version'=>(int)$r['row_version']],$this->db->all('SELECT f.*,p.public_id parent_uuid FROM lgc_folders f LEFT JOIN lgc_folders p ON p.id=f.parent_id AND p.id<>1 WHERE f.id<>1 ORDER BY f.path,f.sort_order,f.id'));
    }
    public function save(Actor $actor, array $input): array
    {
        $actor->assert('tasks.manage');
        return $this->db->transaction(function () use ($input) {
            $this->db->one('SELECT id FROM lgc_folders WHERE id=1 FOR UPDATE');
            $id=$input['id'] ?? Data::uuid(); $old=$this->db->one('SELECT * FROM lgc_folders WHERE public_id=?',[Data::bin($id)]);
            if ($old && ((int)$old['id']===1 || (int)($input['expected_version'] ?? 0)!==(int)$old['row_version'])) throw new DomainError('version_conflict',409);
            $parent=empty($input['parent_id']) ? $this->db->one('SELECT * FROM lgc_folders WHERE id=1') : $this->db->one('SELECT * FROM lgc_folders WHERE public_id=?',[Data::bin($input['parent_id'])]);
            if (!$parent || ($old && str_starts_with($parent['path'],$old['path']))) throw new DomainError('folder_cycle');
            $depth=(int)$parent['depth']+1;
            $maxChildDepth=$old ? (int)$this->db->scalar('SELECT MAX(depth) FROM lgc_folders WHERE path LIKE ?',[$old['path'].'%']) : 0;
            if ($depth+($old ? $maxChildDepth-(int)$old['depth'] : 0)>8) throw new DomainError('folder_depth_exceeded');
            $name=Data::name($input['name'] ?? '');
            if (!$old) {
                $this->db->run('INSERT INTO lgc_folders(public_id,parent_id,name,name_key,path,depth) VALUES(?,?,?,?,?,?)',[Data::bin($id),$parent['id'],$name,Data::nameKey($name),'pending',$depth]);
                $internal=(int)$this->db->pdo->lastInsertId(); $this->db->run('UPDATE lgc_folders SET path=? WHERE id=?',[$parent['path'].$internal.'/',$internal]);
            } else {
                $newPath=$parent['path'].$old['id'].'/';
                $this->db->run('UPDATE lgc_folders SET path=CONCAT(?,SUBSTRING(path,?)),depth=depth+?,row_version=row_version+1 WHERE path LIKE ?',[$newPath,strlen($old['path'])+1,$depth-(int)$old['depth'],$old['path'].'%']);
                $this->db->run('UPDATE lgc_folders SET parent_id=?,name=?,name_key=? WHERE id=?',[$parent['id'],$name,Data::nameKey($name),$old['id']]);
            }
            return ['id'=>$id];
        });
    }
    public function delete(Actor $actor, string $id): array
    {
        $actor->assert('tasks.manage');
        return $this->db->transaction(function () use ($id) { $this->db->one('SELECT id FROM lgc_folders WHERE id=1 FOR UPDATE'); $folder=$this->db->one('SELECT id FROM lgc_folders WHERE public_id=?',[Data::bin($id)]); if (!$folder || (int)$folder['id']===1) throw new DomainError('folder_not_found',404); if ($this->db->scalar('SELECT id FROM lgc_folders WHERE parent_id=? LIMIT 1',[$folder['id']]) || $this->db->scalar('SELECT id FROM lgc_tasks WHERE folder_id=? LIMIT 1',[$folder['id']])) throw new DomainError('folder_not_empty',409); $this->db->run('DELETE FROM lgc_folders WHERE id=?',[$folder['id']]); return ['deleted'=>true]; });
    }
}
