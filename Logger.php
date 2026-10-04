<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Line-delimited JSON file logger. One file per day, per channel.
 */
final class Logger
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o775, true);
        }
    }

    public function info(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->write('INFO', $message, $context, $channel);
    }

    public function warning(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->write('WARNING', $message, $context, $channel);
    }

    public function error(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->write('ERROR', $message, $context, $channel);
    }

    public function exception(\Throwable $exception, array $context = []): void
    {
        $this->write('ERROR', $exception->getMessage(), $context + [
            'exception' => $exception::class,
            'file' => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
        ], 'error');
    }

    private function write(string $level, string $message, array $context, string $channel): void
    {
        $entry = json_encode([
            'timestamp' => date('c'),
            'level' => $level,
            'channel' => $channel,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $file = sprintf('%s/%s-%s.log', $this->directory, $channel, date('Y-m-d'));

        // LOGGING MUST NEVER TAKE DOWN THE REQUEST.
        //
        // Found by running under Apache with a storage directory the web server
        // could not write: the exception handler called the logger, the logger
        // threw, and the client received a completely empty response — no JSON,
        // no envelope, nothing to parse. A full disk or a permissions slip
        // during a deployment would do the same to every error in the system,
        // and the resulting "the app returns blank pages" is far harder to
        // diagnose than the original fault.
        //
        // The write is attempted, and on failure the entry falls back to the
        // server's own error log, which is where an operator will look next.
        // The error is swallowed deliberately: there is nowhere left to report
        // it that would not have the same problem.
        set_error_handler(static fn (): bool => true);

        try {
            $written = file_put_contents($file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            $written = false;
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            error_log('[spice-commerce] ' . $entry);
        }
    }
}
