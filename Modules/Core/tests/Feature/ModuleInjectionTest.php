<?php

namespace Modules\Core\Tests\Feature;

use Modules\Core\Tests\Fixtures\Probe;
use Modules\Core\Tests\Fixtures\ProbeConsumer;
use Modules\Core\Tests\Fixtures\ProbeServiceProvider;
use Tests\TestCase;

class ModuleInjectionTest extends TestCase
{
    public function test_module_provider_can_resolve_a_test_contract_through_constructor_injection(): void
    {
        // El binding y las clases de prueba no se descubren ni registran en producción.
        $this->app->register(ProbeServiceProvider::class);
        $consumer = $this->app->make(ProbeConsumer::class);

        $this->assertInstanceOf(Probe::class, $consumer->probe);
        $this->assertSame('Core', $consumer->probe->module());
    }
}
