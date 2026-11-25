<?php

namespace Modules\Core\Contracts;

/** Ventana UTC semiabierta y criterios de lectura; los propietarios validan sus columnas. */
final readonly class ReportQuery
{
    public \DateTimeImmutable $from;

    public \DateTimeImmutable $until;

    public function __construct(public string $fromUtc, public string $untilUtc, public string $timezone,
        public array $filters = [], public int $page = 1, public int $perPage = 25,
        public string $sort = 'occurred_at', public string $direction = 'desc')
    {
        $zone = new \DateTimeZone('UTC');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $fromUtc, $zone);
        $until = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $untilUtc, $zone);
        if (! $from || ! $until || $from->format('Y-m-d H:i:s.u') !== $fromUtc || $until->format('Y-m-d H:i:s.u') !== $untilUtc || $until <= $from || $from->diff($until)->days > 367 || $page < 1 || $page > 1000000
            || ! in_array($perPage, [10, 25, 50, 100, 1001], true) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Ventana o paginación de reporte inválida.');
        }
        $this->from = $from;
        $this->until = $until;
    }
}
