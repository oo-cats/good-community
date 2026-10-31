<?php
declare(strict_types=1);
namespace Local\GoodCommunity;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Database\ConnectionInterface;
use Local\GoodCommunity\Infrastructure\{Db,Outbox,Worker};
use Local\GoodCommunity\Facts\{PostSource,QueryEngine,FactEngine,DependencyGraph};
use Local\GoodCommunity\Achievements\Achievements;

final class ServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Db::class,function ($app) { $connection=$app->make(ConnectionInterface::class); return new Db($connection->getPdo(),$connection->getTablePrefix(),$connection); });
        $this->container->singleton(Facts\FieldCatalog::class);
        $this->container->singleton(PostSource::class,fn ($app)=>new PostSource($app->make(Db::class),static fn ($ids)=>\Flarum\Post\CommentPost::whereVisibleTo(new \Flarum\User\Guest())->whereIn('posts.id',$ids)->pluck('posts.id')->all(),fn ($source)=>$app->make(\Flarum\Extension\ExtensionManager::class)->isEnabled('flarum-'.$source),$app->make(Facts\FieldCatalog::class)));
        foreach ([Outbox::class,QueryEngine::class,DependencyGraph::class,Access\Rewards::class,Currency\Ledger::class,Currency\Activity::class,Rating\RatingService::class,PostFields\Fields::class,Tasks\TaskService::class,Tasks\Folders::class,Tasks\Statistics::class,Tasks\Completions::class,Achievements::class,Notifications\Notifications::class,Worker::class] as $service) $this->container->singleton($service);
        $this->container->singleton(Tasks\CompletedPeriods::class);
        $this->container->singleton(Profile\AchievementShowcase::class);
        $this->container->singleton(Integrations\AcgnDatabase::class,fn($app)=>new Integrations\AcgnDatabase(
            $app->make(Db::class),$app->make(Outbox::class),$app->make(PostSource::class),$app->make(QueryEngine::class),
            fn()=>$app->make(\Flarum\Extension\ExtensionManager::class)->isEnabled('local-acgn-database') && class_exists(\Local\AcgnDatabase\Facts\TaskFactsV1::class),
            fn()=>$app->make(\Local\AcgnDatabase\Facts\TaskFactsV1::class),
            fn($id)=>\Flarum\User\User::find($id)
        ));
        $this->container->singleton(Integrations\CustomProfile::class,fn($app)=>new Integrations\CustomProfile(
            $app->make(Db::class),$app->make(Outbox::class),
            fn()=>$app->make(\Flarum\Extension\ExtensionManager::class)->isEnabled('local-custom-profile')
                && class_exists(\Local\CustomProfile\PublicationFacts::class)
                && method_exists(\Local\CustomProfile\PublicationFacts::class,'firstByOwner'),
            fn($user,$lock)=>$app->make(\Local\CustomProfile\PublicationFacts::class)->firstByOwner($user,$lock)
        ));
        $this->container->singleton(FactEngine::class,function ($app) {
            $facts=new FactEngine($app->make(Db::class),$app->make(QueryEngine::class));
            $facts->achievementResolver=fn ($user,$uuid,$context)=>$app->make(Achievements::class)->held($user,$uuid,!empty($context['lock']));
            $facts->completionResolver=fn ($params,$context)=>$app->make(Tasks\Completions::class)->completed($params,$context);
            $facts->completedPeriodsResolver=fn ($params,$context)=>$app->make(Tasks\CompletedPeriods::class)->count($params,$context);
            $facts->completedCountResolver=fn ($params,$context)=>$app->make(Tasks\CompletedCount::class)->count($params,$context);
            $app->make(Integrations\CustomProfile::class)->register($facts);
            $app->make(Integrations\AcgnDatabase::class)->register($facts);
            return $facts;
        });
    }
}
