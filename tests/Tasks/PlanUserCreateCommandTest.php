<?php

namespace App\Tests\Tasks;

use App\Entity\TelegramUser;
use App\Tasks\Enum\WorkRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * plan:user:create — живий співробітник із паролем на базі, де вже є живі люди
 * (там, де tasks:demo-seed відмовляє). Пароль — лише з stdin або прихованого вводу.
 */
class PlanUserCreateCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $command;
    /** @var string[] */
    private array $emails = [];
    /** @var string[] */
    private array $departments = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->command = new CommandTester((new Application(self::$kernel))->find('plan:user:create'));

        // «Жива» людина з бота вже є — команда все одно має працювати.
        $live = (new TelegramUser())->setTelegramId((string) random_int(100000000, 899999999))->setFirstName('Живий');
        $this->em->persist($live);
        $this->em->flush();
        $this->emails[] = 'live-' . $live->getId();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->emails as $email) {
            if (str_starts_with($email, 'live-')) {
                $conn->executeStatement('DELETE FROM telegram_user WHERE id = ?', [(int) substr($email, 5)]);
            } else {
                $conn->executeStatement('DELETE FROM telegram_user WHERE email = ?', [$email]);
            }
        }
        foreach ($this->departments as $name) {
            $conn->executeStatement('DELETE FROM supply_department WHERE name = ?', [$name]);
        }
        parent::tearDown();
    }

    public function testCreatesUserWithPasswordFromStdin(): void
    {
        $email = $this->email();
        $department = $this->department();
        $this->command->setInputs(['very-secret-pass-1']);

        $code = $this->command->execute([
            '--email' => strtoupper($email), '--name' => 'Іван Петренко', '--role' => 'director',
            '--department' => $department, '--phone' => '+38 (067) ' . random_int(1000000, 9999999),
            '--position' => 'Директор', '--password-stdin' => true,
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $code, $this->command->getDisplay());
        self::assertStringNotContainsString('very-secret-pass-1', $this->command->getDisplay());

        $user = $this->find($email);
        self::assertNotNull($user);
        self::assertSame('Іван', $user->getFirstName());
        self::assertSame('Петренко', $user->getLastName());
        self::assertSame(WorkRole::Director, $user->getWorkRole());
        self::assertSame($department, $user->getDepartment()?->getName());
        self::assertSame('Директор', $user->getPosition());
        self::assertTrue($user->isApproved());
        self::assertStringStartsWith('staff-', (string) $user->getTelegramId());
        self::assertTrue($this->hasher()->isPasswordValid($user, 'very-secret-pass-1'));
    }

    public function testInteractivePasswordAskedTwice(): void
    {
        $email = $this->email();
        $this->command->setInputs(['interactive-pass-1', 'interactive-pass-1']);

        $code = $this->command->execute(['--email' => $email, '--name' => 'Оксана Шевчук', '--role' => 'accountant']);

        self::assertSame(Command::SUCCESS, $code, $this->command->getDisplay());
        self::assertTrue($this->hasher()->isPasswordValid($this->find($email), 'interactive-pass-1'));
    }

    public function testMismatchedOrShortPasswordRefused(): void
    {
        $email = $this->email();
        $this->command->setInputs(['interactive-pass-1', 'other-pass-22']);
        self::assertSame(Command::INVALID, $this->command->execute(['--email' => $email, '--name' => 'А Б', '--role' => 'manager']));

        $this->command->setInputs(['short']);
        self::assertSame(Command::INVALID, $this->command->execute(['--email' => $email, '--name' => 'А Б', '--role' => 'manager', '--password-stdin' => true], ['interactive' => false]));

        self::assertNull($this->find($email));
    }

    public function testNoPasswordOptionInArgv(): void
    {
        $definition = (new Application(self::$kernel))->find('plan:user:create')->getDefinition();
        self::assertFalse($definition->hasOption('password'));
    }

    public function testNonInteractiveWithoutStdinRefused(): void
    {
        $email = $this->email();
        $code = $this->command->execute(['--email' => $email, '--name' => 'А Б', '--role' => 'manager'], ['interactive' => false]);

        self::assertSame(Command::INVALID, $code);
        self::assertNull($this->find($email));
    }

    public function testUpdatesExistingUserAndKeepsPassword(): void
    {
        $email = $this->email();
        $this->command->setInputs(['first-password-1']);
        $this->command->execute(['--email' => $email, '--name' => 'Максим Савчук', '--role' => 'manager', '--password-stdin' => true], ['interactive' => false]);
        $id = $this->find($email)?->getId();

        $code = $this->command->execute(['--email' => $email, '--role' => 'admin', '--keep-password' => true], ['interactive' => false]);
        self::assertSame(Command::SUCCESS, $code, $this->command->getDisplay());
        self::assertStringContainsString('оновлено', $this->command->getDisplay());

        $this->em->clear();
        $user = $this->find($email);
        self::assertSame($id, $user->getId());
        self::assertSame(WorkRole::Admin, $user->getWorkRole());
        self::assertSame('Максим', $user->getFirstName());
        self::assertTrue($this->hasher()->isPasswordValid($user, 'first-password-1'));

        // Новий пароль при оновленні.
        $this->command->setInputs(['second-password-2']);
        self::assertSame(Command::SUCCESS, $this->command->execute(['--email' => $email, '--password-stdin' => true], ['interactive' => false]));
        $this->em->clear();
        self::assertTrue($this->hasher()->isPasswordValid($this->find($email), 'second-password-2'));
    }

    public function testDryRunWritesNothing(): void
    {
        $email = $this->email();
        $department = $this->department();

        $code = $this->command->execute(['--email' => $email, '--name' => 'Тест Тестович', '--role' => 'production', '--department' => $department, '--dry-run' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $code, $this->command->getDisplay());
        self::assertStringContainsString('нічого не записано', $this->command->getDisplay());
        $this->em->clear();
        self::assertNull($this->find($email));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM supply_department WHERE name = ?', [$department]));
    }

    public function testValidation(): void
    {
        self::assertSame(Command::INVALID, $this->command->execute(['--email' => 'not-an-email', '--name' => 'А Б', '--role' => 'manager'], ['interactive' => false]));
        self::assertSame(Command::INVALID, $this->command->execute(['--email' => $this->email(), '--name' => 'А Б', '--role' => 'boss'], ['interactive' => false]));
        self::assertSame(Command::INVALID, $this->command->execute(['--email' => $this->email(), '--role' => 'manager'], ['interactive' => false]));
    }

    public function testDuplicatePhoneRefused(): void
    {
        $phone = '38067' . random_int(1000000, 9999999);
        $first = $this->email();
        $this->command->setInputs(['first-password-1']);
        self::assertSame(Command::SUCCESS, $this->command->execute(['--email' => $first, '--name' => 'А Б', '--role' => 'manager', '--phone' => $phone, '--password-stdin' => true], ['interactive' => false]));

        $second = $this->email();
        $this->command->setInputs(['second-password-2']);
        $code = $this->command->execute(['--email' => $second, '--name' => 'В Г', '--role' => 'manager', '--phone' => $phone, '--password-stdin' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('телефоном уже є', $this->command->getDisplay());
        self::assertNull($this->find($second));
    }

    public function testRefusesDemoUser(): void
    {
        $email = $this->email();
        $demo = (new TelegramUser())->setTelegramId('demo-cli-' . bin2hex(random_bytes(3)))->setFirstName('Демо')->setEmail($email);
        $this->em->persist($demo);
        $this->em->flush();

        $code = $this->command->execute(['--email' => $email, '--role' => 'admin', '--keep-password' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('демо-користувачу', $this->command->getDisplay());
    }

    private function email(): string
    {
        $email = 'plan-user-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->emails[] = $email;

        return $email;
    }

    private function department(): string
    {
        $name = '99. Тестовий ' . bin2hex(random_bytes(3));
        $this->departments[] = $name;

        return $name;
    }

    private function find(string $email): ?TelegramUser
    {
        return $this->em->getRepository(TelegramUser::class)->findOneBy(['email' => $email]);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }
}
