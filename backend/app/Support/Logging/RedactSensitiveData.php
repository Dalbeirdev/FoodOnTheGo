<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Logging "tap": every record written through a tapped channel is redacted and stamped with the environment.
 * The request id, actor and operation are supplied through Laravel's Context and land in `extra`.
 */
final class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $environment = (string) config('app.env');
        $redactor = new SensitiveDataRedactor(config('logging.redact_keys', []), ! in_array($environment, ['local', 'testing'], true));

        $logger->getLogger()->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
            message: $redactor->redactMessage($record->message),
            context: $redactor->redact($record->context),
            extra: $redactor->redact($record->extra) + ['environment' => $environment],
        ));
    }
}
