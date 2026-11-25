<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Modules\Core\Contracts\ExceptionDiagnostic;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Block05DiagnosticTest extends TestCase
{
    public static function failures(): array
    {
        return [['runtime'], ['wrapped_query'], ['query']];
    }

    #[DataProvider('failures')]
    public function test_serialized_exception_diagnostics_do_not_leak_messages_bindings_or_nested_secrets(string $kind): void
    {
        $query = new QueryException('PRIVATE_CONNECTION', 'SELECT * FROM PRIVATE_TABLE WHERE token=?', ['SECRET_BINDING'], new \PDOException('PRIVATE_DRIVER_MESSAGE'));
        $exception = match ($kind) {
            'query' => $query,
            'wrapped_query' => new \RuntimeException('PRIVATE_EXCEPTION_MESSAGE password=SECRET_PASSWORD', previous: $query),
            default => new \RuntimeException('PRIVATE_EXCEPTION_MESSAGE password=SECRET_PASSWORD')
        };
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $handler = new StreamHandler($stream);
        $handler->setFormatter(new LineFormatter(null, null, true, true));
        $logger = new Logger('block05');
        $logger->pushHandler($handler);
        $logger->error('Controlled failure.', ExceptionDiagnostic::context($exception));
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        foreach (['PRIVATE_CONNECTION', 'PRIVATE_TABLE', 'SECRET_BINDING', 'PRIVATE_DRIVER_MESSAGE', 'PRIVATE_EXCEPTION_MESSAGE', 'SECRET_PASSWORD'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertStringContainsString($exception::class, str_replace('\\\\', '\\', $output));
    }
}
