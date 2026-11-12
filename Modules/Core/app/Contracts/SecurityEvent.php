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
    ) {
        if (! in_array($type, [
            'login', 'logout', 'password.failed', 'password.locked', 'authentication.unlocked',
            'pin.failed', 'pin.locked', 'pin.changed', 'pin.disabled', 'password.changed',
            'session.revoked', 'token.revoked', 'session.revocation_requested', 'sessions.revoked',
            'session.expired', 'user.deactivated', 'user.reactivated', 'user.deleted',
            'roles.changed', 'permissions.changed', 'administrator.provisioned',
        ], true) || ! in_array($result, ['success', 'failure', 'blocked'], true)) {
            throw new \InvalidArgumentException('Evento de seguridad no permitido.');
        }
        foreach ([$actorId, $subjectId, $recordId] as $id) {
            if ($id !== null && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/i', $id) !== 1) {
                throw new \InvalidArgumentException('La referencia de auditoría debe ser un ULID.');
            }
        }
    }
}
