<?php

namespace App\Security;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Обмеження спроб входу за паролем (security.yaml → login_throttling.limiter):
 * - IP: 25 спроб за 15 хв на будь-які логіни (як стандартний «глобальний»);
 * - людина + IP: 5 за 15 хв (стандартний «локальний»);
 * - людина без IP: 20 за годину — підбір з багатьох IP теж упирається в стелю.
 *
 * «Людина» — нормалізований логін (LoginIdentity): усі записи одного номера
 * чи email у різному регістрі — один лічильник. Ключі хешуються з APP_SECRET:
 * ні IP, ні логіни не лежать у кеші відкритим текстом.
 */
final class PlanLoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.plan_login_ip')]
        private readonly RateLimiterFactoryInterface $ip,
        #[Autowire(service: 'limiter.plan_login_local')]
        private readonly RateLimiterFactoryInterface $local,
        #[Autowire(service: 'limiter.plan_login_account')]
        private readonly RateLimiterFactoryInterface $account,
        #[Autowire('%kernel.secret%')]
        #[SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    /** Успішний вхід знімає лише «людина + IP»: стелі IP і людини переживають чужий вдалий вхід. */
    public function reset(Request $request): void
    {
        $this->localLimiter($request)->reset();
    }

    protected function getLimiters(Request $request): array
    {
        return [
            $this->ip->create($this->hash('ip:' . $request->getClientIp())),
            $this->localLimiter($request),
            $this->account->create($this->hash('acc:' . self::identity($request))),
        ];
    }

    private function localLimiter(Request $request): LimiterInterface
    {
        return $this->local->create($this->hash('loc:' . self::identity($request) . '-' . $request->getClientIp()));
    }

    /** UserBadge уже несе нормалізований логін; повторна нормалізація — на випадок іншого автентифікатора. */
    private static function identity(Request $request): string
    {
        return LoginIdentity::normalize((string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, ''));
    }

    private function hash(string $data): string
    {
        return strtr(substr(base64_encode(hash_hmac('sha256', $data, $this->secret, true)), 0, 12), '/+', '._');
    }
}
