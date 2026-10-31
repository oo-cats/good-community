<?php
use Illuminate\Database\Schema\Builder;
use Local\GoodCommunity\Infrastructure\{Db,OptimizeSchema};
return ['up'=>static function(Builder $schema):void {$c=$schema->getConnection();OptimizeSchema::apply(new Db($c->getPdo(),$c->getTablePrefix(),$c));},'down'=>static function():void {throw new RuntimeException('Restore the pre-upgrade snapshot with the matching application version');}];
