<?php

declare(strict_types=1);

namespace XInfra\Laravel\Tests\Fixtures;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class TenantTagProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['tags'] = ['tenant' => '12'];

        return $record;
    }
}
