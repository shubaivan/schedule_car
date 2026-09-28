<?php

namespace App\Tests\Warehouse;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Політику конфіденційності Google відкриває без входу — інакше застосунок для
 * Диска не опублікувати. І вона мусить казати, що ми просимо лише drive.file.
 */
class PrivacyPolicyTest extends WebTestCase
{
    public function testIsPublicAndStatesDriveScope(): void
    {
        $client = self::createClient();
        $client->request('GET', '/privacy-policy');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'drive.file');
        self::assertSelectorTextContains('body', 'Limited Use');
    }
}
