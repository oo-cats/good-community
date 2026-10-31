<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Integrations;

use Local\GoodCommunity\Facts\FactEngine;
use Local\GoodCommunity\Infrastructure\{Db,DomainError,Outbox};

/** Optional consumer of Custom Profile's public metadata contract; no access to its tables. */
final class CustomProfile
{
    public const METHOD='custom_profile.first_published.v1';
    public const DEPENDENCY='source:custom-profile';

    public function __construct(private Db $db,private Outbox $outbox,private \Closure $available,private \Closure $firstPublication) {}

    public function register(FactEngine $facts): void
    {
        $facts->register(self::METHOD,[
            'title'=>'首次自定义个人资料页','type'=>'boolean','dependencies'=>[self::DEPENDENCY],
            'help'=>'以本人第一次成功发布的时间为准，须在任务时间范围内；草稿、预览和管理员代改不计。需要启用自定义个人资料插件。',
        ],static fn(array $params): array=>[],fn(array $params,array $context): bool=>$this->matches($context));
    }

    private function read(int $user,bool $lock): ?array
    {
        if(!($this->available)()) throw new DomainError('custom_profile_source_unavailable',202);
        return ($this->firstPublication)($user,$lock);
    }

    public function matches(array $context): bool
    {
        $first=$this->read((int)$context['user'],!empty($context['lock']));
        if(!$first) return false;
        $at=new \DateTimeImmutable($first['occurred_at'],new \DateTimeZone('UTC'));
        // Compare instants, not strings with different fractional-second precision.
        return (!$context['start'] || $at>=new \DateTimeImmutable($context['start'],new \DateTimeZone('UTC')))
            && (!$context['end'] || $at<new \DateTimeImmutable($context['end'],new \DateTimeZone('UTC')));
    }

    /** The source dispatches this only within its successful publication transaction. */
    public function published(object $event): void
    {
        if($event->userId!==$event->actorId) return;
        $this->db->transaction(function() use($event): void {
            $first=$this->read($event->userId,true);
            if(!$first || $first['id']!==$event->auditId) return;
            $this->outbox->emit('custom-profile.first-published','custom-profile:'.$event->userId,1,
                ['dependencies'=>[self::DEPENDENCY],'occurred_at'=>$first['occurred_at'],'source_id'=>$first['id']],$event->userId);
        });
    }
}
