<?php

namespace App\Traits;

/**
 * Provides audit logging for models. Writes immutable audit records.
 */
trait Auditable
{
    /**
     * Log an audit record for this entity.
     */
    public function logAudit(string $action, int $actorId, string $role, ?string $reason = null, ?array $priorValues = null, ?array $newValues = null): void
    {
        $auditTable = $this->getAuditTable();
        $foreignKey = $this->getAuditForeignKey();

        \Illuminate\Support\Facades\DB::table($auditTable)->insert([
            'actor_id' => $actorId,
            'role' => $role,
            'action' => $action,
            'prior_value_hash' => $priorValues ? hash('sha256', json_encode($priorValues)) : null,
            'new_value_hash' => $newValues ? hash('sha256', json_encode($newValues)) : null,
            'reason' => $reason,
            'timestamp' => now(),
            $foreignKey => $this->id,
        ]);
    }

    /**
     * Get audit trail for this entity.
     */
    public function getAuditTrail(): \Illuminate\Support\Collection
    {
        $auditTable = $this->getAuditTable();
        $foreignKey = $this->getAuditForeignKey();

        return \Illuminate\Support\Facades\DB::table($auditTable)
            ->where($foreignKey, $this->id)
            ->orderBy('timestamp', 'asc')
            ->get();
    }

    abstract protected function getAuditTable(): string;
    abstract protected function getAuditForeignKey(): string;
}
