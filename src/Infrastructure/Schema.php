<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

final class Schema
{
    public static function install(Db $db): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2).'/resources/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $db->run(trim($statement));
        $db->run("INSERT INTO lgc_folders(id,public_id,name,name_key,path,depth) VALUES(1,?,'内部根目录','__root__','/1/',0)", [Data::bin(Data::uuid())]);
        $db->run("INSERT INTO lgc_source_states(source_key,user_id,coverage_from,updated_at) VALUES('avatar-coverage',0,?,?)",[Data::now(),Data::now()]);
        foreach (['光玉'=>'currency','经验'=>'experience'] as $name => $kind) {
            $db->run("INSERT INTO lgc_currencies(public_id,name,name_key,resource_kind,state,created_at,updated_at,rate_numerator,rate_denominator) VALUES(?,?,?,?,'active',?,?,?,?)", [Data::bin(Data::uuid()),$name,Data::nameKey($name),$kind,Data::now(),Data::now(),$kind === 'currency' ? 1 : null,$kind === 'currency' ? 1 : null]);
        }
    }
}
