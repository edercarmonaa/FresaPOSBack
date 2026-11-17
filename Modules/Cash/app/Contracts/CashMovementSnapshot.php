<?php

namespace Modules\Cash\Contracts;

final readonly class CashMovementSnapshot
{
    public function __construct(public string $id, public string $cashSessionId, public string $type, public string $amount, public string $concept, public string $createdBy, public string $occurredAt) {}

    public static function fromArray(array $row): self
    {
        return new self($row['id'], $row['cash_session_id'], $row['type'], $row['amount'], $row['concept'], $row['created_by'], $row['occurred_at']);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'cash_session_id' => $this->cashSessionId, 'type' => $this->type, 'amount' => $this->amount, 'concept' => $this->concept, 'created_by' => $this->createdBy, 'occurred_at' => $this->occurredAt];
    }
}
