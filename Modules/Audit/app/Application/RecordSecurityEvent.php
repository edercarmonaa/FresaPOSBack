<?php

namespace Modules\Audit\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Contracts\SecurityEvent;

final class RecordSecurityEvent
{
    public function handle(SecurityEvent $event): void
    {
        $request = app()->bound('request') && request()->route() !== null ? request() : null;
        $requestId = $request?->attributes->get('_core_request_id');
        if ($request && ! is_string($requestId)) {
            $requestId = strtolower((string) Str::ulid());
            $request->attributes->set('_core_request_id', $requestId);
        }
        // Lista cerrada de campos. Fallos de persistencia se propagan; no hay éxito sin auditoría.
        $values = [
            'id' => strtolower((string) Str::ulid()),
            'event_type' => $event->type,
            'actor_id' => $event->actorId,
            'subject_id' => $event->subjectId,
            'record_id' => $event->recordId,
            'result' => $event->result,
            'client' => $request ? ($request->is('api', 'api/*') ? 'api' : 'web') : 'console',
            'request_id' => $requestId,
            'occurred_at' => now()->utc(),
        ];
        if ($event->changedFields !== [] || $event->priceBefore !== null) {
            $values['change_data'] = json_encode([
                'changed_fields' => $event->changedFields,
                'price_before' => $event->priceBefore,
                'price_after' => $event->priceAfter,
            ], JSON_THROW_ON_ERROR);
        }
        if ($event->cashMovement !== null) {
            $values['change_data'] = json_encode(['cash_session_id' => $event->cashMovement->cashSessionId,
                'movement_type' => $event->cashMovement->type, 'amount' => $event->cashMovement->amount], JSON_THROW_ON_ERROR);
        }
        if ($event->checkout !== null) {
            $values['change_data'] = json_encode(['cash_session_id' => $event->checkout->cashSessionId, 'folio' => $event->checkout->folio,
                'total' => $event->checkout->total, 'cash_applied' => $event->checkout->cashApplied, 'payment_count' => $event->checkout->paymentCount,
                'sale_closed' => true], JSON_THROW_ON_ERROR);
        }
        if ($event->closing !== null) {
            $values['change_data'] = json_encode(['cash_session_id' => $event->closing->cashSessionId, 'register_id' => $event->closing->registerId,
                'reconciliation_id' => $event->closing->reconciliationId, 'expected_cash' => $event->closing->expectedCash,
                'counted_cash' => $event->closing->countedCash, 'difference' => $event->closing->difference], JSON_THROW_ON_ERROR);
        }
        if ($event->saleOperation !== null) {
            $values['change_data'] = json_encode(['sale_id' => $event->saleOperation->saleId,
                'state_before' => $event->saleOperation->stateBefore, 'state_after' => $event->saleOperation->stateAfter,
                'reason' => $event->saleOperation->reason, 'item' => $event->saleOperation->item], JSON_THROW_ON_ERROR);
        }
        if ($event->reasonChange !== null) {
            $values['change_data'] = json_encode(['before' => $event->reasonChange->before, 'after' => $event->reasonChange->after], JSON_THROW_ON_ERROR);
        }
        if ($event->discountChange !== null) {
            $data = json_decode($values['change_data'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $data['discount_change'] = get_object_vars($event->discountChange);
            $values['change_data'] = json_encode($data, JSON_THROW_ON_ERROR);
        }
        DB::table('audit_security_events')->insert($values);
    }
}
