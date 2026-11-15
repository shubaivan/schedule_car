<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;
use Rector\Symfony\Symfony63\Rector\Class_\ParamAndEnvAttributeRector;
use Rector\Symfony\Symfony72\Rector\StmtsAwareInterface\PushRequestToRequestStackConstructorRector;
use Rector\Symfony\Symfony73\Rector\Class_\GetFiltersAndFunctionsToAsTwigAttributeRector;

/*
 * Rector «Плану» (як у chiro, під PHP 8.5 / Symfony 8.1): мовні набори PHP до 8.5 і набори
 * Symfony / Doctrine / PHPUnit за версіями з composer.lock. Лише перевірка в CI
 * (`composer rector`, --dry-run); застосувати: `vendor/bin/rector process`.
 * Правила, що змінили б поведінку чи масово переписали б код без користі, — у withSkip.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/migrations',
    ])
    ->withPhpSets(php85: true)
    ->withComposerBased(symfony: true, doctrine: true, phpunit: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        // readonly на сервісах/сутностях: лінива ініціалізація (Nutgram, проксі Doctrine), тести
        // з підміною сервісів — зміна поведінки, не стиль. Вирішувати поштучно.
        ReadOnlyPropertyRector::class,
        ReadOnlyClassRector::class,
        // Перебудова DI / підписок на атрибути — інша реєстрація сервісів, не косметика.
        ParamAndEnvAttributeRector::class,
        EventSubscriberToAttributeRector::class,
        GetFiltersAndFunctionsToAsTwigAttributeRector::class,
        PushRequestToRequestStackConstructorRector::class,
        // Масова переробка конструкторів і колбеків без користі для читання.
        ClassPropertyAssignToConstructorPromotionRector::class,
        FunctionFirstClassCallableRector::class,
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
    ]);
