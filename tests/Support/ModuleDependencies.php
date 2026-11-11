<?php

namespace Tests\Support;

use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

final class ModuleDependencies
{
    public const NAMES = [
        'Core', 'Auth', 'Users', 'Catalog', 'Sales', 'Cash', 'Tickets',
        'Reports', 'Audit', 'Notifications', 'Devices', 'Settings', 'System',
    ];

    /** @return list<string> */
    public static function references(string $code): array
    {
        // PHP-Parser ya forma parte del lock de desarrollo (PHPUnit/Larastan).
        $nodes = (new ParserFactory)->createForHostVersion()->parse($code) ?? [];
        $finder = new NodeFinder;
        $references = [];

        foreach ($finder->findInstanceOf($nodes, Use_::class) as $statement) {
            foreach ($statement->uses as $use) {
                $references[] = $use->name->toString();
            }
        }
        foreach ($finder->findInstanceOf($nodes, GroupUse::class) as $statement) {
            foreach ($statement->uses as $use) {
                $references[] = $statement->prefix->toString().'\\'.$use->name->toString();
            }
        }
        foreach ($finder->findInstanceOf($nodes, FullyQualified::class) as $name) {
            $references[] = $name->toString();
        }

        return array_values(array_unique($references));
    }

    /** @param array<string, list<string>> $allowed
     * @return list<string>
     */
    public static function violations(string $source, string $reference, array $allowed): array
    {
        $parts = explode('\\', $reference);
        if (strcasecmp($parts[0], 'Modules') !== 0) {
            return [];
        }
        $target = $parts[1] ?? '';
        if ($target === $source) {
            return [];
        }

        $errors = [];
        if (! array_key_exists($target, $allowed)) {
            return ["$source: módulo desconocido o namespace no canónico: $reference"];
        }
        if (! in_array($target, $allowed[$source], true)) {
            $errors[] = "$source no puede depender de $target";
        }
        $public = ($parts[2] ?? '') === 'Contracts'
            || (($parts[2] ?? '') === 'Application' && ($parts[3] ?? '') === 'Services');
        if (! $public) {
            $errors[] = "$source accede a una implementación interna: $reference";
        }

        return $errors;
    }

    /** @param array<string, list<string>> $graph */
    public static function cycle(array $graph): ?string
    {
        $visited = [];
        $active = [];
        $visit = function (string $name, array $path) use (&$visit, &$visited, &$active, $graph): ?string {
            if (isset($active[$name])) {
                return implode(' -> ', [...$path, $name]);
            }
            if (isset($visited[$name])) {
                return null;
            }
            $active[$name] = true;
            foreach ($graph[$name] ?? [] as $dependency) {
                if (($cycle = $visit($dependency, [...$path, $name])) !== null) {
                    return $cycle;
                }
            }
            unset($active[$name]);
            $visited[$name] = true;

            return null;
        };
        foreach (array_keys($graph) as $name) {
            if (($cycle = $visit($name, [])) !== null) {
                return $cycle;
            }
        }

        return null;
    }
}
