<?php

namespace App\Tests\Tasks;

use App\Tasks\Search\Snippet;
use App\Tasks\Search\TaskSearch;
use PHPUnit\Framework\TestCase;

class SnippetTest extends TestCase
{
    public function testMarksEveryMatchCaseInsensitively(): void
    {
        self::assertSame([
            ['t' => 'Наземне ', 'm' => false],
            ['t' => 'Укриття', 'm' => true],
            ['t' => ' і ', 'm' => false],
            ['t' => 'укриття', 'm' => true],
        ], Snippet::mark('Наземне Укриття і укриття', ['укрит', 'укриття']));
    }

    public function testCutsAroundFirstMatchOnWordBoundaries(): void
    {
        $text = str_repeat('слово ', 40) . 'плита ПК-60 ' . str_repeat('інше ', 40);
        $parts = Snippet::around($text, ['пк-60'], 30);

        self::assertSame('… ', $parts[0]['t']);
        self::assertSame(' …', end($parts)['t']);
        self::assertSame(['ПК-60'], array_values(array_map(static fn ($p) => $p['t'], array_filter($parts, static fn ($p) => $p['m']))));
        $plain = implode('', array_column($parts, 't'));
        self::assertLessThan(120, mb_strlen($plain));
    }

    public function testTermsAreLowercasedAndLimited(): void
    {
        self::assertSame(['плита', 'пк'], TaskSearch::terms('  Плита   ПК плита '));
        self::assertCount(6, TaskSearch::terms('a b c d e f g h'));
    }

    public function testRegexCharactersInQueryAreSafe(): void
    {
        self::assertSame([['t' => 'a(b', 'm' => true]], Snippet::mark('a(b', ['a(b']));
    }
}
