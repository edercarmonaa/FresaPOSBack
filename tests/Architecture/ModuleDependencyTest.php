<?php

namespace Tests\Architecture;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Tests\Support\ModuleDependencies;

class ModuleDependencyTest extends TestCase
{
    public function test_production_modules_respect_dependency_boundaries_and_have_no_cycles(): void
    {
        $root = dirname(__DIR__, 2);
        $allowed = require $root.'/config/module-dependencies.php';
        $this->assertEqualsCanonicalizing(ModuleDependencies::NAMES, array_keys($allowed));
        $this->assertSame([], $allowed['Core']);
        $graph = array_fill_keys(ModuleDependencies::NAMES, []);
        $errors = [];

        foreach ($allowed as $module => $dependencies) {
            foreach ($dependencies as $target) {
                $this->assertArrayHasKey($target, $allowed);
                $this->assertNotSame($module, $target);
            }
            foreach ((new Filesystem)->allFiles($root.'/Modules/'.$module) as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                if ($file->getExtension() !== 'php' || str_starts_with($relative, 'tests/')) {
                    continue;
                }
                foreach (ModuleDependencies::references($file->getContents()) as $reference) {
                    foreach (ModuleDependencies::violations($module, $reference, $allowed) as $error) {
                        $errors[] = "$module/$relative: $error";
                    }
                    $parts = explode('\\', $reference);
                    if (strcasecmp($parts[0], 'Modules') === 0 && isset($parts[1]) && $parts[1] !== $module) {
                        $graph[$module][] = $parts[1];
                    }
                }
            }
        }

        $this->assertSame([], $errors, implode(PHP_EOL, $errors));
        $this->assertNull(ModuleDependencies::cycle($allowed), 'La matriz permitida contiene un ciclo.');
        $this->assertNull(ModuleDependencies::cycle($graph), 'Las referencias reales contienen un ciclo.');
    }

    public function test_scanner_handles_aliases_grouped_imports_and_fully_qualified_names(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Modules\Sales;
        // use Modules\Users\Models\Ignored;
        use Modules\Core\Contracts\Clock as ModuleClock;
        use Modules\Core\{Contracts\Identifier, Application\Services\ExampleService};
        use function Modules\Core\Contracts\helper;
        use const Modules\Core\Contracts\VALUE;
        $factory = function () use ($value) {};
        new \Modules\Users\Infrastructure\PrivateModel;
        PHP;
        $references = ModuleDependencies::references($code);
        $this->assertEqualsCanonicalizing([
            'Modules\Core\Contracts\Clock', 'Modules\Core\Contracts\Identifier',
            'Modules\Core\Application\Services\ExampleService', 'Modules\Core\Contracts\helper',
            'Modules\Core\Contracts\VALUE', 'Modules\Users\Infrastructure\PrivateModel',
        ], $references);
    }

    public function test_guard_rejects_private_access_core_dependencies_and_undeclared_edges(): void
    {
        $allowed = ['Core' => [], 'Sales' => ['Core'], 'Users' => ['Core']];
        $this->assertSame([], ModuleDependencies::violations('Sales', 'Modules\Core\Contracts\Clock', $allowed));
        $this->assertSame([], ModuleDependencies::violations('Sales', 'Modules\Core\Application\Services\Clock', $allowed));
        foreach ([
            ['Sales', 'Modules\Core\Infrastructure\Clock'],
            ['Sales', 'Modules\Users\Contracts\UserReader'],
            ['Core', 'Modules\Users\Contracts\UserReader'],
            ['Sales', 'Modules\Unknown\Contracts\Something'],
            ['Sales', 'Modules\Core'],
        ] as [$source, $reference]) {
            $this->assertNotEmpty(ModuleDependencies::violations($source, $reference, $allowed), $reference);
        }
    }

    public function test_cycle_detector_rejects_direct_indirect_and_self_cycles(): void
    {
        $this->assertNull(ModuleDependencies::cycle(['Sales' => ['Core'], 'Core' => []]));
        $this->assertNotNull(ModuleDependencies::cycle(['A' => ['B'], 'B' => ['A']]));
        $this->assertNotNull(ModuleDependencies::cycle(['A' => ['B'], 'B' => ['C'], 'C' => ['A']]));
        $this->assertNotNull(ModuleDependencies::cycle(['A' => ['A']]));
    }
}
