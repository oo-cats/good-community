<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Notifications;
final class RatingActivity extends Activity { public static function getType() { return 'communityRating'; } }
