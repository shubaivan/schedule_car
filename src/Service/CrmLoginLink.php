<?php

namespace App\Service;

use App\Entity\LoginToken;
use App\Entity\TelegramUser;
use App\Repository\LoginTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Одноразове посилання для входу в CRM, яке бот надсилає менеджеру.
 *
 * У базі лежить лише SHA-256 від токена — сам токен існує тільки в повідомленні Telegram.
 */
class CrmLoginLink
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoginTokenRepository $repository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param string|null $next куди вести після входу — лише наш шлях у /crm чи /sklad,
     *                          інакше посилання стало б відкритим редиректом
     */
    public function issue(TelegramUser $user, ?string $next = null): string
    {
        $this->repository->deleteExpired();

        $token = bin2hex(random_bytes(32));

        $loginToken = (new LoginToken())
            ->setUser($user)
            ->setTokenHash($this->hash($token));

        $this->em->persist($loginToken);
        $this->em->flush();

        $parameters = ['token' => $token];

        if (self::safeNext($next) !== null) {
            $parameters['next'] = $next;
        }

        return $this->urlGenerator->generate(
            'crm_auth',
            $parameters,
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /** Повертає користувача і одразу гасить токен; null — прострочений, використаний або підроблений. */
    public function consume(string $token): ?TelegramUser
    {
        $loginToken = $this->repository->findByHash($this->hash($token));

        if ($loginToken === null || ! $loginToken->isUsable()) {
            return null;
        }

        $loginToken->markUsed();
        $this->em->flush();

        return $loginToken->getUser();
    }

    /** Шлях після входу, якщо він наш; null — вести на стартову сторінку CRM. */
    public static function safeNext(?string $next): ?string
    {
        if ($next === null || ! preg_match('#^/(crm|sklad)(/[A-Za-z0-9/_-]*)?(\?via=bot)?$#', $next)) {
            return null;
        }

        return $next;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
