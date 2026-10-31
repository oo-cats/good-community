<?php
declare(strict_types=1);
use Flarum\Extend;
use Local\GoodCommunity\{ServiceProvider,Api\Controller,Infrastructure\ContentListener,Infrastructure\WorkCommand,Infrastructure\Actor};
use Local\GoodCommunity\Notifications\{Activity,RatingActivity,MoneyActivity};

$routes=new Extend\Routes('api');
$definitions=[
    ['GET','/meta'],['GET','/tasks'],['POST','/tasks'],['GET','/tasks/{id}'],['PATCH','/tasks/{id}'],['DELETE','/tasks/{id}'],
    ['POST','/tasks/{id}/pin'],['POST','/tasks/{id}/check'],['POST','/tasks/{id}/claim'],['POST','/tasks/{id}/publish'],['POST','/tasks/{id}/close'],['POST','/tasks/{id}/copy'],['GET','/tasks/{id}/stats'],['POST','/tasks/{id}/rebuild-stats'],['POST','/task-stats/query'],
    ['GET','/folders'],['POST','/folders'],['DELETE','/folders/{id}'],['GET','/resource-history'],['GET','/posts/{id}/tips'],['GET','/achievement-catalog'],['GET','/resources'],['POST','/resources'],['POST','/money'],['POST','/money/reverse'],['GET','/fields'],['POST','/fields'],['GET','/rating-policies'],['POST','/rating-policies'],
    ['GET','/posts/{id}/rating'],['POST','/posts/{id}/rating'],['GET','/posts/{id}/fields'],['POST','/posts/{id}/fields'],['POST','/posts/{id}/tip'],['GET','/post-summaries'],['GET','/achievements'],['POST','/achievements'],['POST','/achievements/{id}/grant'],['GET','/achievements/{id}/sources'],['POST','/achievements/{id}/archive'],['POST','/query-preview'],['POST','/work'],
];
foreach ($definitions as [$method,$path]) $routes->{strtolower($method)}('/community'.$path,'local-good-community.'.strtolower($method).'.'.str_replace(['/','{','}'],['.','',''],$path),Controller::class);
$events=new Extend\Event();
$events->listen(\Flarum\Approval\Event\PostWasApproved::class,[ContentListener::class,'post']);
foreach ([\Flarum\Post\Event\Posted::class,\Flarum\Post\Event\Revised::class,\Flarum\Post\Event\Hidden::class,\Flarum\Post\Event\Restored::class,\Flarum\Post\Event\Deleted::class,\Flarum\Likes\Event\PostWasLiked::class,\Flarum\Likes\Event\PostWasUnliked::class] as $event) $events->listen($event,[ContentListener::class,'post']);
foreach ([\Flarum\Discussion\Event\Hidden::class,\Flarum\Discussion\Event\Restored::class,\Flarum\Discussion\Event\Deleted::class,\Flarum\Tags\Event\DiscussionWasTagged::class] as $event) $events->listen($event,[ContentListener::class,'discussion']);
$events->listen(\Flarum\User\Event\GroupsChanged::class,[\Local\GoodCommunity\Access\Rewards::class,'groupsChanged']);
$events->listen(\Flarum\User\Event\AvatarChanged::class,[ContentListener::class,'avatar']);
$events->listen(\Local\CustomProfile\Event\ProfilePublished::class,[\Local\GoodCommunity\Integrations\CustomProfile::class,'published']);
return [
    (new Extend\User())->permissionGroups(fn ($user,$groups)=>resolve(\Local\GoodCommunity\Access\Rewards::class)->permissionGroups($user,$groups)),
    (new Extend\ServiceProvider())->register(ServiceProvider::class),$routes,$events,
    (new Extend\Console())->command(WorkCommand::class),
    (new Extend\Formatter())->configure(\Local\GoodCommunity\Infrastructure\FormatterConfig::class),
    (new Extend\Notification())->type(Activity::class,\Local\GoodCommunity\Notifications\EventSubjectSerializer::class,['alert'])->type(RatingActivity::class,\Local\GoodCommunity\Notifications\EventSubjectSerializer::class,['alert'])->type(MoneyActivity::class,\Local\GoodCommunity\Notifications\EventSubjectSerializer::class,['alert']),
    (new Extend\Frontend('forum'))->js(__DIR__.'/js/dist/forum.js')->css(__DIR__.'/js/less/forum.less')->route('/community/achievements','community.catalog')->route('/u/{username}/community-resources','community.resources')->route('/community/tasks','community.tasks')->route('/community/tasks/{id}','community.task')->route('/community/manage','community.manage')->route('/community/wallet','community.wallet')->route('/u/{username}/community-achievements','community.achievements'),
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js')->css(__DIR__.'/js/less/admin.less'),
    (new Extend\ApiSerializer(\Flarum\Api\Serializer\PostSerializer::class))->attribute('communityContentHash',fn ($serializer,$post)=>hash('sha256',(string)$post->parsed_content)),
    (new Extend\ApiSerializer(\Flarum\Api\Serializer\ForumSerializer::class))->attribute('communityPermissions',function ($serializer) { $actor=Actor::fromUser($serializer->getActor()); return [
    'tasksManage'=>$actor->can('tasks.manage'),'manage'=>$actor->can('tasks.manage') || $actor->can('rating.manage') || $actor->can('fields.manage') || $actor->can('currency.manage') || $actor->can('currency.issue') || $actor->can('currency.deduct') || $actor->can('experience.issue') || $actor->can('achievements.manage') || $actor->can('achievements.grant') || $actor->can('achievements.revoke')]; }),
];
