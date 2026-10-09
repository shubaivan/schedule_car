<?php

namespace App\Tests\Telegram;

use App\Entity\TelegramUser;
use App\EventSubscriber\RequestSubscriber;
use App\Service\TeamChat;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Fake;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Робоча група з темами: бот пише туди події, але сам у групі мовчить.
 *
 * 09.10.2026 бота додали в групу АртБетона — і апдейт «додано учасника»
 * записав id групи як особистий chat_id того, хто додавав. Його власні
 * сповіщення пішли б у групу на очах у всіх.
 */
class TeamChatTest extends KernelTestCase
{
    public function testPostGoesToTheSectionTopic(): void
    {
        $bot = Nutgram::fake();
        $team = new TeamChat($bot, new NullLogger(), '-1004401449773', '3', '4', '7');

        $team->post(TeamChat::WAREHOUSE, 'рух', 'https://t.me/bot?start=wm-1');

        $sent = $this->sent($bot);
        self::assertCount(1, $sent);
        self::assertSame('-1004401449773', (string) $sent[0]['chat_id']);
        self::assertSame(4, (int) $sent[0]['message_thread_id']);
        self::assertStringContainsString('wm-1', json_encode($sent[0]['reply_markup']));
    }

    public function testNoGroupMeansSilence(): void
    {
        $bot = Nutgram::fake();

        (new TeamChat($bot, new NullLogger(), '', '3', '4', '7'))->post(TeamChat::SUPPLY, 'заявка');

        self::assertSame([], $this->sent($bot));
    }

    public function testBrokenTopicFallsBackToGeneral(): void
    {
        $bot = Nutgram::fake();

        (new TeamChat($bot, new NullLogger(), '-100', 'abc', '', ''))->post(TeamChat::SUPPLY, 'заявка');

        $sent = $this->sent($bot);
        self::assertCount(1, $sent);
        self::assertArrayNotHasKey('message_thread_id', array_filter($sent[0], fn ($v) => $v !== null));
    }

    public function testGroupUpdateDoesNotRewritePersonalChat(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->beginTransaction();

        try {
            $user = (new TelegramUser())->setTelegramId('team-' . uniqid())->setFirstName('Іван')->setChatId('555');
            $em->persist($user);
            $em->flush();

            $update = [
                'update_id' => 1,
                'message' => [
                    'message_id' => 2,
                    'from' => ['id' => $user->getTelegramId(), 'is_bot' => false, 'first_name' => 'Іван'],
                    'chat' => ['id' => -1004401449773, 'type' => 'supergroup', 'is_forum' => true],
                    'date' => time(),
                    'new_chat_members' => [['id' => 1, 'is_bot' => true, 'first_name' => 'бот']],
                ],
            ];

            self::getContainer()->get(RequestSubscriber::class)->onKernelRequest(new RequestEvent(
                self::$kernel,
                Request::create('/hook', 'POST', content: json_encode($update)),
                HttpKernelInterface::MAIN_REQUEST,
            ));

            $em->refresh($user);
            self::assertSame('555', $user->getChatId());
        } finally {
            $em->rollback();
        }
    }

    public function testBotKeepsQuietInTheGroup(): void
    {
        $bot = Nutgram::fake();

        (static function (Nutgram $bot): void {
            require dirname(__DIR__, 2) . '/config/telegram.php';
        })($bot);
        $bot->setRunningMode(new Fake());

        // /start у групі без фільтра дійшов би до RequireApproval і StartCommand.
        $bot->hearMessage([
            'message_id' => 1,
            'text' => '/start',
            'entities' => [['type' => 'bot_command', 'offset' => 0, 'length' => 6]],
            'from' => ['id' => 42, 'is_bot' => false, 'first_name' => 'Незнайомець'],
            'chat' => ['id' => -1004401449773, 'type' => 'supergroup'],
            'date' => time(),
        ])->reply()->assertNoReply();
    }

    /** @return list<array<string, mixed>> */
    private function sent(FakeNutgram $bot): array
    {
        $out = [];
        foreach ($bot->getRequestHistory() as $reqRes) {
            [$request] = array_values($reqRes);
            $out[] = FakeNutgram::getActualData($request);
        }

        return $out;
    }
}
