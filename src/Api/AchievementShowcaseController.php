<?php
declare(strict_types=1);

namespace Local\GoodCommunity\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\GoodCommunity\Infrastructure\DomainError;
use Local\GoodCommunity\Profile\AchievementShowcase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/** Only validates the HTTP envelope; permissions and projections belong to the shared service. */
final class AchievementShowcaseController implements RequestHandlerInterface
{
    public function __construct(private AchievementShowcase $showcase, private LoggerInterface $logger) {}

    public function handle(ServerRequestInterface $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $query = $request->getQueryParams();
            $options = $request->getAttribute('routeName') === 'local-good-community.achievement-options';
            // Flarum merges route parameters over query parameters. The route attribute is
            // authoritative; a userId query value can never change the target user.
            $ownerId = $this->integer($request->getAttribute('routeParameters')['userId'] ?? null, 1, AchievementShowcase::MAX_USER_ID);
            $allowed = $options ? ['userId', 'cursor', 'limit', 'search'] : ['userId', 'ids'];
            if (array_diff(array_keys($query), $allowed) || (array) $request->getParsedBody()) throw new DomainError('unexpected_showcase_parameters');
            $viewer = RequestUtil::getActor($request);
            if ($options) {
                if (array_key_exists('cursor', $query) && !is_string($query['cursor'])) throw new DomainError('invalid_achievement_cursor');
                if (array_key_exists('search', $query) && !is_string($query['search'])) throw new DomainError('invalid_achievement_search');
                $limit = array_key_exists('limit', $query) ? $this->integer($query['limit'], 1, 50) : 20;
                $result = $this->showcase->optionsForViewer($viewer, $ownerId, $query['cursor'] ?? null, $limit, $query['search'] ?? '');
            } else {
                if (array_key_exists('ids', $query) && !is_array($query['ids'])) throw new DomainError('invalid_achievement_ids');
                $result = $this->showcase->forViewer($viewer, $ownerId, $query['ids'] ?? []);
            }
            return new JsonResponse($result, 200, $headers);
        } catch (DomainError $error) {
            return new JsonResponse(['error' => $error->reason], $error->status, $headers);
        } catch (\Throwable $error) {
            $this->logger->error('Achievement showcase read failed', ['error_type' => get_class($error)]);
            return new JsonResponse(['error' => 'achievement_showcase_unavailable'], 500, $headers);
        }
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) throw new DomainError('invalid_showcase_integer');
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) throw new DomainError('invalid_showcase_integer');
        return $number;
    }
}
