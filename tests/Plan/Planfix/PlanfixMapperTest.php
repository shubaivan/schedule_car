<?php

namespace App\Tests\Plan\Planfix;

use App\Plan\Planfix\PlanfixMapper;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use PHPUnit\Framework\TestCase;

/** Правила перетворення Planfix → План на синтетичних даних. */
class PlanfixMapperTest extends TestCase
{
    public function testDealIsTemplateFieldOrFunnelStage(): void
    {
        self::assertSame(PlanfixMapper::KIND_DEAL, PlanfixMapper::kind(['status' => 'Новая', 'customData' => ['57554' => 'Не оплачено']]));
        self::assertSame(PlanfixMapper::KIND_DEAL, PlanfixMapper::kind(['status' => 'Ціноутворення']));
        self::assertSame(PlanfixMapper::KIND_TASK, PlanfixMapper::kind(['status' => 'В работе', 'customData' => ['57584' => 'Low']]));
        self::assertSame(PlanfixMapper::KIND_TASK, PlanfixMapper::kind(['status' => 'Новая']));
    }

    public function testStatusMapping(): void
    {
        self::assertSame([TaskStatus::New, null], PlanfixMapper::status('Новая'));
        self::assertSame([TaskStatus::New, null], PlanfixMapper::status('Черновик'));
        self::assertSame([TaskStatus::InWork, null], PlanfixMapper::status('В работе'));
        self::assertSame([TaskStatus::Done, null], PlanfixMapper::status('Выполненная'));
        self::assertSame([TaskStatus::Done, null], PlanfixMapper::status('Завершенная'));
        self::assertSame([TaskStatus::Rejected, null], PlanfixMapper::status('Отмененная'));
        self::assertSame([TaskStatus::InWork, 'Виробництво'], PlanfixMapper::status('Виробництво'));
    }

    public function testHtmlToTextDropsFileTokensAndPlaceholders(): void
    {
        $html = '<p>Привіт,&nbsp;<a class="user-mention" href="/user/3">Тест Тестенко</a></p>'
            . '<p><img src="https://demo.planfix.com/?action=getfile&amp;uniqueid=1&amp;planfixauth=SECRET123"></p>'
            . '<p>Дивись <a href="https://example.com/a">сайт</a> {log26} {emailtemplate2}</p><ul><li>раз</li><li>два</li></ul>';

        $text = PlanfixMapper::text($html);

        self::assertStringNotContainsString('SECRET123', $text);
        self::assertStringNotContainsString('planfixauth', $text);
        self::assertStringNotContainsString('{log26}', $text);
        self::assertStringNotContainsString('{emailtemplate2}', $text);
        self::assertStringContainsString(PlanfixMapper::FILE_NOTE, $text);
        self::assertStringContainsString('Привіт, Тест Тестенко', $text);
        self::assertStringContainsString('сайт (https://example.com/a)', $text);
        self::assertStringContainsString("• раз\n• два", $text);
        self::assertStringNotContainsString('<', $text);
    }

    public function testActionDecisions(): void
    {
        self::assertSame([PlanfixMapper::ACTION_COMMENT, null, 'Готово'], PlanfixMapper::action('Comment', '<p>Готово</p>'));
        self::assertSame(PlanfixMapper::ACTION_SKIP, PlanfixMapper::action('Action', '<p>&nbsp;</p>')[0]);
        self::assertSame(PlanfixMapper::ACTION_SKIP, PlanfixMapper::action('TaskCreated', 'Завдання створене')[0]);
        self::assertSame(PlanfixMapper::ACTION_SKIP, PlanfixMapper::action('StatusChanged', '')[0], 'Статус без деталей — лише з --events=all');
        self::assertSame([PlanfixMapper::ACTION_EVENT, TaskMessage::EVENT_STATUS, 'Planfix: статус змінено'], PlanfixMapper::action('StatusChanged', '', PlanfixMapper::EVENTS_ALL));
        self::assertSame(PlanfixMapper::ACTION_SKIP, PlanfixMapper::action('WorkerEmployed', 'x', PlanfixMapper::EVENTS_NONE)[0]);

        self::assertSame(
            [PlanfixMapper::ACTION_EVENT, TaskMessage::EVENT_MEMBERS, 'Planfix: виконавці + Тест Тестенко, [1. Тестовий відділ]'],
            PlanfixMapper::action('WorkerEmployed', "{event7}: <a href='/user/7' >Тест Тестенко</a>, [1. Тестовий відділ]<br>{event8}: 01-01-2024 10:00"),
        );

        $changed = PlanfixMapper::action('TaskChanged', "{log26}<br>[<i>{log29}</i>]:<br>Нова назва<br/><div class='log-expand'></div><span class='log-extra'>[<i>{log28}</i>]:<br>Стара назва</span>");
        self::assertSame([PlanfixMapper::ACTION_EVENT, TaskMessage::EVENT_UPDATED, 'Planfix: задачу змінено (назва → Нова назва)'], $changed);
        self::assertSame(PlanfixMapper::ACTION_SKIP, PlanfixMapper::action('TaskChanged', '{log26}')[0], '«Змінено» без деталей — шум');
        self::assertSame('Planfix: задачу змінено', PlanfixMapper::action('TaskChanged', '{log26}', PlanfixMapper::EVENTS_ALL)[2]);
        self::assertTrue(PlanfixMapper::isNoise('TaskOverdued'));
    }

    public function testDescriptionAndFields(): void
    {
        $fields = ['57592' => 'ТОВ Тест', '57594' => 'No name', '57554' => 'Оплачено', '57552' => '0', '57650' => '1'];

        self::assertSame('ТОВ Тест', PlanfixMapper::client($fields));
        self::assertNull(PlanfixMapper::client(['57594' => 'No name']));
        self::assertSame(['Компанія: ТОВ Тест', 'Оплата: Оплачено'], PlanfixMapper::fieldLines($fields));

        $description = PlanfixMapper::description(17, PlanfixMapper::KIND_DEAL, 'Тестовий проєкт', 5, '01.02.2024 10:00', ['Оплата: Оплачено'], '<p>Текст</p>');
        self::assertSame("Із Planfix № 17 · угода · проєкт «Тестовий проєкт» · створено 01.02.2024 10:00\n\nПідзадача Planfix № 5\n\nПоля угоди:\nОплата: Оплачено\n\nТекст", $description);
        self::assertSame('Без назви (Planfix № 9)', PlanfixMapper::title('  ', 9));
        self::assertSame('2024-02-01 10:05', PlanfixMapper::date('01-02-2024 10:05')?->format('Y-m-d H:i'));
    }
}
