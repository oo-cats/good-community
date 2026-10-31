<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Notifications;

/** No event payload or audit data is exposed through the notification relationship. */
final class EventSubjectSerializer extends \Flarum\Api\Serializer\AbstractSerializer
{
    protected $type='communityEvents';
    protected function getDefaultAttributes($model) { return []; }
}
