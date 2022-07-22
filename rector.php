<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->skip([
        __DIR__ . '/vendor',
        __DIR__ . '/node_modules',
        __DIR__ . '/symfony'
    ]);

    $rectorConfig->paths([
        __DIR__
    ]);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_81
    ]);
};
