<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Notifications;

class Activity implements \Flarum\Notification\Blueprint\BlueprintInterface
{
    public function __construct(private array $data=[]) {}
    public function getFromUser() { return null; }
    public function getSubject() { return null; }
    public function getData() { return $this->data; }
    public static function getType() { return 'communityTask'; }
    public static function getSubjectModel() { return EventSubject::class; }
}
