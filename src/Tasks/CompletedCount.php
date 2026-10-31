<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Tasks;
use Local\GoodCommunity\Infrastructure\{Data,Db,DomainError};
final class CompletedCount
{
    public function __construct(private Db $db) {}
    public function count(array $params,array $context): int
    {
        $lock=!empty($context['lock']);
        if ($lock) $this->db->sourceLock('task-completions',(int)$context['user']);
        $task=$this->db->scalar('SELECT id FROM lgc_tasks WHERE public_id=?',[Data::bin($params['task_id'])]);
        if (!$task) throw new DomainError('task_unavailable',202);
        // The writer fence stabilizes membership; locking SELECT avoids an old MVCC view.
        return (int)$this->db->scalar('SELECT COUNT(*) FROM lgc_task_claims FORCE INDEX(completed_task_lookup) WHERE task_id=? AND user_id=? AND active=1'.($lock ? ' LOCK IN SHARE MODE' : ''),[$task,$context['user']]);
    }
}
