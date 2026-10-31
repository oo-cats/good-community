<?php
declare(strict_types=1);

namespace Local\GoodCommunity\Infrastructure;

use Flarum\Foundation\Paths;
use Illuminate\Console\Scheduling\Event;
use Psr\Log\LoggerInterface;

final class WorkSchedule
{
    public function __construct(private Paths $paths, private LoggerInterface $logger) {}

    public function __invoke(Event $event): void
    {
        // Foreground execution releases the mutex without requiring schedule:finish.
        $event->everyMinute()->description('Good Community bounded work')
            ->withoutOverlapping(5)
            ->sendOutputTo($this->paths->storage.'/logs/community-work.log')
            ->onFailure(function () use ($event): void {
                $this->logger->warning('Good Community scheduled batch deferred or failed; inspect community-work.log and lgc_jobs.', [
                    'exit_code'=>$event->exitCode,
                ]);
            });
    }
}
