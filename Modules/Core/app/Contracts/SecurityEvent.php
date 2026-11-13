<?php

namespace Modules\Core\Contracts;

// Contrato transversal sin payload libre: no admite credenciales ni cuerpos de solicitud.
final readonly class SecurityEvent
{
    public function __construct(
        public string $type,
        public ?string $actorId = null,
        public ?string $subjectId = null,
        public string $result = 'success',
        public ?string $recordId = null,
        public array $changedFields = [],
        public ?string $priceBefore = null,
        public ?string $priceAfter = null,
    ) {
        if (! in_array($type, [
            'login', 'logout', 'password.failed', 'password.locked', 'authentication.unlocked',
            'pin.failed', 'pin.locked', 'pin.changed', 'pin.disabled', 'password.changed',
            'session.revoked', 'token.revoked', 'session.revocation_requested', 'sessions.revoked',
            'session.expired', 'user.deactivated', 'user.reactivated', 'user.deleted',
            'roles.changed', 'permissions.changed', 'administrator.provisioned',
            'catalog.category.created', 'catalog.category.updated', 'catalog.category.activated',
            'catalog.category.deactivated', 'catalog.category.deleted',
            'catalog.product.created', 'catalog.product.updated', 'catalog.product.price_changed',
            'catalog.product.activated', 'catalog.product.deactivated', 'catalog.product.deleted', 'catalog.product.restored',
            'catalog.product.image_added', 'catalog.product.image_replaced', 'catalog.product.image_removed',
            'sales.opened', 'sales.paused', 'sales.resumed', 'sales.item_added', 'sales.quantity_changed',
            'sales.item_note_updated', 'sales.note_updated', 'sales.item_removed', 'sales.cancelled',
            'sales.table.created', 'sales.table.updated',
        ], true) || ! in_array($result, ['success', 'failure', 'blocked'], true)) {
            throw new \InvalidArgumentException('Evento de seguridad no permitido.');
        }
        foreach ([$actorId, $subjectId, $recordId] as $id) {
            if ($id !== null && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/i', $id) !== 1) {
                throw new \InvalidArgumentException('La referencia de auditoría debe ser un ULID.');
            }
        }
        if (! array_is_list($changedFields)) {
            throw new \InvalidArgumentException('Los campos de auditoría deben formar una lista.');
        }
        foreach ($changedFields as $field) {
            if (! is_string($field) || ! in_array($field, ['name', 'description', 'category_id', 'price', 'is_active', 'image', 'display_order', 'deleted_at'], true)) {
                throw new \InvalidArgumentException('Campo de auditoría no permitido.');
            }
        }
        if (count($changedFields) !== count(array_unique($changedFields))
            || (! str_starts_with($type, 'catalog.') && $changedFields !== [])) {
            throw new \InvalidArgumentException('Cambios de auditoría no permitidos.');
        }
        if ($type === 'catalog.product.price_changed') {
            foreach ([$priceBefore, $priceAfter] as $price) {
                if ($price === null || preg_match('/\A(?:0|[1-9][0-9]{0,14})\.[0-9]{4}\z/', $price) !== 1) {
                    throw new \InvalidArgumentException('Los cambios de precio requieren decimales canónicos.');
                }
            }
        } elseif ($priceBefore !== null || $priceAfter !== null) {
            throw new \InvalidArgumentException('Los precios solo corresponden al evento de cambio de precio.');
        }
    }
}
