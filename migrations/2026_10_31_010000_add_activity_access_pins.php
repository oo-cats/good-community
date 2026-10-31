<?php
use Illuminate\Database\Schema\Builder;
use Local\GoodCommunity\Infrastructure\{Db,Upgrade};
return ['up'=>static function(Builder $schema):void {$c=$schema->getConnection();Upgrade::apply(new Db($c->getPdo(),$c->getTablePrefix(),$c));},'down'=>static function():void {throw new RuntimeException('Access reward history must be archived explicitly');}];
