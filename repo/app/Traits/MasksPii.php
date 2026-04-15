<?php

namespace App\Traits;

use App\Models\MaskingRule;

/**
 * Masks PII fields in API responses based on requesting user role.
 */
trait MasksPii
{
    public function maskForRole(?string $role): array
    {
        $data = $this->toArray();
        $piiFields = $this->getPiiFields();

        foreach ($piiFields as $field) {
            if (!isset($data[$field]) || $data[$field] === null) {
                continue;
            }

            // Decrypt value first
            $decryptedValue = $this->getDecryptedAttribute($field);

            if ($this->canViewUnmasked($field, $role)) {
                $data[$field] = $decryptedValue;
            } else {
                $data[$field] = $this->applyMask($field, $decryptedValue);
            }
        }

        return $data;
    }

    protected function canViewUnmasked(string $field, ?string $role): bool
    {
        if ($role === null) {
            return false;
        }

        // Check DB-backed masking rules first
        $rule = MaskingRule::where('field_name', $field)->where('active', true)->first();
        if ($rule) {
            $visibleRoles = $rule->visible_roles;
            return in_array($role, $visibleRoles);
        }

        // Fall back to config-based rules
        $configRules = config("pii.fields.{$field}.visible_roles", []);
        return in_array($role, $configRules);
    }

    protected function applyMask(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Get mask type from DB or config
        $rule = MaskingRule::where('field_name', $field)->where('active', true)->first();
        $maskType = $rule ? $rule->mask_type : config("pii.fields.{$field}.mask_type", 'redact');

        return match ($maskType) {
            'first_initial' => mb_substr($value, 0, 1) . '.',
            'last_four' => str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4),
            'partial_email' => $this->maskEmail($value),
            'year_only' => substr($value, 0, 4) . '-**-**',
            'redact' => '***REDACTED***',
            default => '***REDACTED***',
        };
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***@***.***';
        }
        $local = $parts[0];
        $domain = $parts[1];
        $maskedLocal = mb_substr($local, 0, 1) . str_repeat('*', max(0, strlen($local) - 1));
        return $maskedLocal . '@' . $domain;
    }
}
