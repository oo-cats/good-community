<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Notifications;

/** Native notification grouping uses subject_id. Each event is a distinct subject. */
final class EventSubject extends \Flarum\Database\AbstractModel
{
    protected $table='lgc_events';
    public $timestamps=false;
}
