<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationIntentTest extends TestCase
{
    public function test_uuid_v4_and_canonical_hash_preserve_meaningful_types_and_order(): void
    {
        $context = OperationIntent::context('01arz3ndektsv4rrffq69g5fav');
        $key = (string) Str::uuid();
        $a = new OperationIntent($context, $key, 'sales.open', 'POST', null, ['type' => 'counter', 'nested' => ['z' => 1, 'a' => 'texto']]);
        $b = new OperationIntent($context, strtoupper($key), 'sales.open', 'POST', null, ['nested' => ['a' => 'texto', 'z' => 1], 'type' => 'counter']);
        $this->assertSame($a->hash, $b->hash);
        $this->assertSame($key, $b->key);
        $this->assertNotSame($a->hash, (new OperationIntent($context, $key, 'sales.open', 'POST', null, ['nested' => ['a' => 'texto', 'z' => '1'], 'type' => 'counter']))->hash);
        $this->assertNotSame($a->hash, (new OperationIntent($context, $key, 'sales.open', 'PATCH', null, ['type' => 'counter']))->hash);
    }

    public static function invalidKeys(): array
    {
        return [[''], ['not-a-uuid'], ['01arz3ndektsv4rrffq69g5fav'], ['3d6f0a97-2b10-1f71-8b3d-9e98c7b78e62'], ['3d6f0a97-2b10-4f71-7b3d-9e98c7b78e62'], [' 3d6f0a97-2b10-4f71-8b3d-9e98c7b78e62']];
    }

    #[DataProvider('invalidKeys')]
    public function test_only_uuid_v4_is_accepted(string $key): void
    {
        try {
            OperationIntent::key($key);
            $this->fail('Debe rechazar la clave.');
        } catch (OperationRejected $exception) {
            $this->assertSame(ApiCode::BAD_REQUEST, $exception->apiCode);
        }
    }
}
