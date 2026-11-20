<?php

namespace Modules\Tickets\Contracts;

/** Comprobante independiente de HTML, sin modelos, referencias bancarias ni credenciales. */
final readonly class ReceiptData implements \JsonSerializable
{
    /** @param list<array{name:string,quantity:int,unit_price:string,line_total:string,note:?string}> $items
     * @param  list<array{method_code:string,method_name:string,applied_amount:string,received_cash:?string,change_cash:?string}>  $payments
     * @param  array<string,string>  $establishment
     */
    public function __construct(public string $saleId, public string $folio, public string $closedAt,
        public string $localClosedAt, public string $timezone, public string $saleType, public array $items,
        public string $subtotal, public string $discountAmount, public ?array $discount, public string $total,
        public array $payments, public ?string $note, public array $establishment, public ?string $employee,
        public ?array $correction, public string $revision) {}

    public function jsonSerialize(): array
    {
        return ['sale_id' => $this->saleId, 'folio' => $this->folio, 'closed_at' => $this->closedAt,
            'local_closed_at' => $this->localClosedAt, 'timezone' => $this->timezone, 'sale_type' => $this->saleType,
            'items' => $this->items, 'subtotal' => $this->subtotal, 'discount_amount' => $this->discountAmount,
            'discount' => $this->discount, 'total' => $this->total, 'payments' => $this->payments, 'note' => $this->note,
            'establishment' => $this->establishment === [] ? null : $this->establishment, 'employee' => $this->employee, 'correction' => $this->correction,
            'revision' => $this->revision];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['sale_id'], $data['folio'], $data['closed_at'], $data['local_closed_at'], $data['timezone'],
            $data['sale_type'], $data['items'], $data['subtotal'], $data['discount_amount'], $data['discount'], $data['total'],
            $data['payments'], $data['note'], $data['establishment'] ?? [], $data['employee'], $data['correction'], $data['revision']);
    }
}
