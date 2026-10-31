<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Facts;

use Local\GoodCommunity\Infrastructure\DomainError;

/** Exact decimal/rational comparisons; lengths are bounded at validation. */
final class Compare
{
    public static function test(mixed $left, string $operator, mixed $right = null, bool $numeric = false): bool
    {
        if ($operator === 'is_set') return $left !== null;
        if ($operator === 'is_unset') return $left === null;
        if ($left === null || $right === null) return false;
        if (in_array($operator,['has_any','has_all'],true)) {
            if (!is_array($left) || !is_array($right)) throw new DomainError('set_required');
            return $operator === 'has_any' ? (bool)array_intersect($left,$right) : !array_diff($right,$left);
        }
        $comparison = $numeric ? self::number($left,$right) : ((is_bool($left) || is_bool($right)) ? ((int)$left <=>(int)$right) : strcmp((string)$left,(string)$right));
        return match ($operator) { '='=>$comparison===0,'!='=>$comparison!==0,'>'=>$comparison>0,'>='=>$comparison>=0,'<'=>$comparison<0,'<='=>$comparison<=0, default=>throw new DomainError('invalid_operator') };
    }
    private static function fraction(mixed $value): array
    {
        if (is_array($value) && isset($value['n'],$value['d'])) {
            if (!preg_match('/^[0-9]{1,32}$/D',(string)$value['n']) || !preg_match('/^[1-9][0-9]{0,31}$/D',(string)$value['d'])) throw new DomainError('invalid_rational');
            return [false,(string)$value['n'],(string)$value['d']];
        }
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^(-?)([0-9]{1,30})(?:\.([0-9]{1,9}))?$/D',(string)$value,$match)) throw new DomainError('invalid_number');
        return [$match[1]==='-',ltrim($match[2].($match[3] ?? ''),'0') ?: '0','1'.str_repeat('0',strlen($match[3] ?? ''))];
    }
    public static function number(mixed $left, mixed $right): int
    {
        [$negativeA,$a,$ad]=self::fraction($left); [$negativeB,$b,$bd]=self::fraction($right);
        $negativeA=$negativeA && $a!=='0'; $negativeB=$negativeB && $b!=='0';
        if ($negativeA !== $negativeB) return $negativeA ? -1 : 1;
        $x=self::multiply($a,$bd); $y=self::multiply($b,$ad);
        $result=strlen($x)<=>strlen($y); if (!$result) $result=strcmp($x,$y)<=>0;
        return $negativeA ? -$result : $result;
    }
    private static function multiply(string $a, string $b): string
    {
        $digits=array_fill(0,strlen($a)+strlen($b),0);
        for ($i=strlen($a)-1;$i>=0;$i--) for ($j=strlen($b)-1;$j>=0;$j--) $digits[$i+$j+1]+=(int)$a[$i]*(int)$b[$j];
        for ($i=count($digits)-1;$i>0;$i--) { $digits[$i-1]+=intdiv($digits[$i],10); $digits[$i]%=10; }
        return ltrim(implode('',$digits),'0') ?: '0';
    }
}
