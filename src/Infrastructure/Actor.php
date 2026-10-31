<?php
declare(strict_types=1);
namespace Local\GoodCommunity\Infrastructure;

final class Actor
{
    public function __construct(public readonly int $id, private array $abilities = [], public readonly array $groups = []) {}
    public function can(string $ability): bool { return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true); }
    public function assert(string $ability): void { if ((!$this->id && !$this->can('system')) || !$this->can($ability)) throw new DomainError('permission_denied', 403); }
    public static function automation(): self { return new self(0,['system','currency.deduct']); }
    public static function fromUser(\Flarum\User\User $user): self
    {
        $keys = ['tasks.manage','rating.manage','rating.rate','fields.manage','fields.edit','currency.manage','currency.issue','currency.deduct','currency.transfer','currency.viewHistory','experience.issue','achievements.manage','achievements.grant','achievements.revoke'];
        $allowed = array_values(array_filter($keys, fn ($key) => $user->can('local-good-community.'.$key)));
        return new self((int) $user->id, $user->isAdmin() ? ['*'] : array_values(array_unique([...$allowed,...$user->getPermissions()])), array_map('intval', $user->groups->pluck('id')->all()));
    }
}
