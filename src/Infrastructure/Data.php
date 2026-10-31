<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

final class Data
{
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        return self::id($bytes);
    }
    public static function bin(string $uuid): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $uuid)) throw new DomainError('invalid_id');
        return hex2bin(str_replace('-', '', $uuid));
    }
    public static function id(string $bytes): string
    {
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
    public static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    public static function decode(?string $value, mixed $default = []): mixed { return $value === null ? $default : json_decode($value, true, 64, JSON_THROW_ON_ERROR); }
    public static function canonical(mixed $value): string
    {
        $sort = static function ($item) use (&$sort) { if (!is_array($item)) return $item; if (!array_is_list($item)) ksort($item); return array_map($sort, $item); };
        return self::json($sort($value));
    }
    public static function hash(mixed $value): string { return hash('sha256', self::canonical($value)); }
    public static function name(string $value): string
    {
        $value = trim(\Normalizer::normalize($value, \Normalizer::FORM_C));
        if ($value === '' || mb_strlen($value) > 150) throw new DomainError('invalid_name');
        return $value;
    }
    public static function nameKey(string $value): string { return mb_strtolower(self::name($value), 'UTF-8'); }
    public static function date(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        try { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
        catch (\Throwable) { throw new DomainError('invalid_date'); }
    }
    public static function now(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
    public static function integer(mixed $value, bool $signed = false, int $digits = 12): string
    {
        if (!is_string($value) && !is_int($value)) throw new DomainError('integer_required');
        $text = (string) $value;
        if (!preg_match($signed ? '/^-?(?:0|[1-9][0-9]*)$/D' : '/^(?:0|[1-9][0-9]*)$/D', $text) || strlen(ltrim($text, '-')) > $digits) throw new DomainError('integer_required');
        return $text === '-0' ? '0' : $text;
    }
}
