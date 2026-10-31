<?php
use Illuminate\Database\Schema\Builder;
use Local\GoodCommunity\Infrastructure\{Db,Schema};
return ['up' => static function (Builder $schema): void { $connection = $schema->getConnection(); Schema::install(new Db($connection->getPdo(), $connection->getTablePrefix(), $connection)); }, 'down' => static function (): void { throw new RuntimeException('Community financial history requires an explicit archival plan before uninstall.'); }];
