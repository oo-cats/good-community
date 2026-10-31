<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;
final class WorkCommand extends \Flarum\Console\AbstractCommand
{
    public function __construct(private Worker $worker, private Outbox $outbox, private Db $db, private \Local\GoodCommunity\Facts\QueryEngine $queries) { parent::__construct(); }
    protected function configure() {
        $this->setName('community:work')->setDescription('Process a bounded batch of community work')
            ->addOption('limit',null,\Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,'Maximum jobs',50)
            ->addOption('seconds',null,\Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,'Budget before starting another job, 1-20 seconds (not a job timeout)',20)
            ->addOption('reconcile',null,\Symfony\Component\Console\Input\InputOption::VALUE_NONE,'Schedule a paged current-fact reconciliation')
            ->addOption('prune',null,\Symfony\Component\Console\Input\InputOption::VALUE_NONE,'Remove a bounded batch of expired rebuildable counter projections')
            ->addOption('retry-failed',null,\Symfony\Component\Console\Input\InputOption::VALUE_NONE,'Requeue at most 100 failed jobs without changing business identities');
    }
    protected function fire() {
        $seconds=filter_var($this->input->getOption('seconds'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>20]]);
        if ($seconds===false) { $this->error('The --seconds option must be an integer between 1 and 20.'); return 2; }
        if ($this->input->getOption('reconcile')) { $trigger=Data::uuid(); $this->outbox->enqueue('reconcile.scan',['after_user'=>0,'trigger'=>$trigger],'reconcile-scan:'.$trigger,3); }
        if ($this->input->getOption('retry-failed')) $this->db->run("UPDATE lgc_jobs SET status='pending',attempts=0,available_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE status='failed' ORDER BY id LIMIT 100");
        if ($this->input->getOption('prune')) $this->info('Expired counter windows removed: '.$this->queries->prune());
        $result=$this->worker->run(min(500,max(1,(int)$this->input->getOption('limit'))),$seconds);
        $this->info('['.gmdate('c').'] Processed '.$result['processed'].', deferred/failed '.$result['failed']); return $result['failed'] ? 1 : 0;
    }
}
