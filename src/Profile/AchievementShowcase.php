<?php
declare(strict_types=1);

namespace Local\GoodCommunity\Profile;

use Flarum\User\{User, UserRepository};
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Local\GoodCommunity\Infrastructure\{Data, DomainError};

/** Public read-only contract v1. No rule evaluation, grant mutation or request-independent cache. */
final class AchievementShowcase
{
    public const SCHEMA_VERSION = 1;
    public const MAX_IDS = 12;
    public const MAX_USER_ID = 4294967295;
    private const COLUMNS = ['a.id', 'a.public_id', 'a.name', 'a.description', 'a.status', 'g.earned_at'];

    public function __construct(private ConnectionInterface $db, private UserRepository $users) {}

    public function forViewer(User $viewer, int $ownerId, array $achievementIds = []): array
    {
        $this->assertReadable($viewer, $ownerId);
        if (!array_is_list($achievementIds) || count($achievementIds) > self::MAX_IDS) throw new DomainError('invalid_achievement_ids');
        $ids = [];
        foreach ($achievementIds as $id) {
            if (!is_string($id)) throw new DomainError('invalid_achievement_ids');
            $binary = Data::bin($id);
            $ids[Data::id($binary)] = $binary; // Lowercase, deduplicate, preserve input order.
        }

        $result = ['schemaVersion' => self::SCHEMA_VERSION, 'userId' => $ownerId];
        if (!$ids) return $result + ['earnedCount' => $this->safeCount($this->held($ownerId)->count()), 'items' => []];

        // One statement gives count and selected details the same MVCC snapshot, including
        // READ COMMITTED installations and callers already inside a transaction. No locks.
        // LEFT JOIN retains the count when none of the selected UUIDs is held/readable.
        $total = $this->held($ownerId)->selectRaw('COUNT(*) AS earned_count');
        // The UUIDs are unique, so this does not truncate valid results. LIMIT also keeps
        // MySQL 5.7 from merging this tiny selection into the outer count/detail join.
        $selected = $this->held($ownerId)->whereIn('a.public_id', array_values($ids))->select(self::COLUMNS)->limit(self::MAX_IDS);
        $rows = $this->db->query()->fromSub($total, 'totals')
            ->leftJoinSub($selected, 'chosen', static fn ($join) => $join->whereRaw('1 = 1'))
            ->get(['totals.earned_count', 'chosen.public_id', 'chosen.name', 'chosen.description', 'chosen.status', 'chosen.earned_at']);
        $items = [];
        foreach ($rows as $row) if ($row->public_id !== null) {
            $item = $this->item($row);
            $items[$item['id']] = $item;
        }
        $ordered = [];
        foreach ($ids as $uuid => $_) if (isset($items[$uuid])) $ordered[] = $items[$uuid];
        return $result + ['earnedCount' => $this->safeCount($rows->first()->earned_count), 'items' => $ordered];
    }

    public function optionsForViewer(User $viewer, int $ownerId, ?string $cursor = null, int $limit = 20, string $search = ''): array
    {
        $this->assertReadable($viewer, $ownerId);
        if ($limit < 1 || $limit > 50) throw new DomainError('invalid_achievement_limit');
        if (!mb_check_encoding($search, 'UTF-8') || mb_strlen($search, 'UTF-8') > 100) throw new DomainError('invalid_achievement_search');
        $search = mb_strtolower(\Normalizer::normalize(trim($search), \Normalizer::FORM_C), 'UTF-8');
        $after = $cursor === null ? '0' : $this->decodeCursor($cursor, $ownerId, $search);
        $query = $this->held($ownerId)->where('g.achievement_id', '>', $after);
        if ($search !== '') {
            $column = $query->getGrammar()->wrap('a.name_key');
            $query->whereRaw($column." LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
        }
        $rows = $query->orderBy('g.achievement_id')->limit($limit + 1)->get(self::COLUMNS);
        $more = $rows->count() > $limit;
        $page = $rows->take($limit);
        return ['schemaVersion' => self::SCHEMA_VERSION, 'userId' => $ownerId,
            'items' => $page->map(fn ($row) => $this->item($row))->values()->all(),
            'nextCursor' => $more ? $this->encodeCursor($ownerId, $search, (string) $page->last()->id) : null];
    }

    private function assertReadable(User $viewer, int $ownerId): void
    {
        if (!$viewer->can('viewForum')) throw new DomainError('permission_denied', 403);
        if ($ownerId < 1 || $ownerId > self::MAX_USER_ID) throw new DomainError('invalid_user_id');
        try { $this->users->findOrFail($ownerId, $viewer); }
        catch (ModelNotFoundException) { throw new DomainError('user_not_found', 404); }
    }

    /** The one visibility/holding predicate for totals, selections and the picker. */
    private function held(int $ownerId): Builder
    {
        // There is no per-achievement privacy model in v1; forum and target-user gates apply.
        return $this->db->table('lgc_achievement_grants as g')
            ->join('lgc_achievements as a', 'a.id', '=', 'g.achievement_id')
            ->where('g.user_id', $ownerId)->where('g.active_sources', '>', 0)
            ->whereIn('a.status', ['active', 'archived']);
    }

    private function item(object $row): array
    {
        return ['id' => Data::id($row->public_id), 'name' => $this->text($row->name, 150),
            'description' => $this->text($row->description, 300), 'status' => $row->status,
            'earnedAt' => $row->earned_at === null ? null : (new \DateTimeImmutable($row->earned_at, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')];
    }

    private function text(string $value, int $limit): string
    {
        $plain = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($value));
        return mb_substr(trim($plain), 0, $limit, 'UTF-8');
    }

    private function safeCount(mixed $count): int
    {
        $value = filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9007199254740991]]);
        if ($value === false) throw new DomainError('achievement_count_out_of_range', 500);
        return $value;
    }

    private function encodeCursor(int $ownerId, string $search, string $after): string
    {
        return rtrim(strtr(base64_encode(Data::json(['v' => 1, 'u' => $ownerId, 'q' => hash('sha256', $search), 'a' => $after])), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor, int $ownerId, string $search): string
    {
        // A cursor is a range position, never an authorization token. Always recheck the viewer.
        if ($cursor === '' || strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) throw new DomainError('invalid_achievement_cursor');
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        try { $value = $json === false ? null : json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new DomainError('invalid_achievement_cursor'); }
        if (!is_array($value) || array_keys($value) !== ['v', 'u', 'q', 'a'] || $value['v'] !== 1 || $value['u'] !== $ownerId
            || $value['q'] !== hash('sha256', $search) || !is_string($value['a']) || !preg_match('/^[1-9][0-9]{0,19}$/D', $value['a'])
            || strlen($value['a']) === 20 && strcmp($value['a'], '18446744073709551615') > 0
            || $this->encodeCursor($ownerId, $search, $value['a']) !== $cursor) throw new DomainError('invalid_achievement_cursor');
        return $value['a'];
    }
}
