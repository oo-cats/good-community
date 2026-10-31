<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;
final class FormatterConfig
{
    public function __invoke(\s9e\TextFormatter\Configurator $configurator): void
    {
        $tag=$configurator->Preg->replace('~\[community-task id="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})" scope="(task|current|period)"(?: occurrence="([0-9]{1,10})")?\]~i','<span class="Community-taskEmbed" data-task-id="$1" data-task-scope="$2" data-task-occurrence="$3">任务信息</span>','COMMUNITY_TASK');
        $tag->attributes['_3']->required=false;
    }
}
