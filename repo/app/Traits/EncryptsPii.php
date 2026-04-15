<?php

namespace App\Traits;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Encrypts PII columns at rest using Laravel's built-in Crypt.
 * Define $piiFields on the model to list columns requiring encryption.
 */
trait EncryptsPii
{
    public static function bootEncryptsPii(): void
    {
        static::saving(function ($model) {
            foreach ($model->getPiiFields() as $field) {
                if (isset($model->attributes[$field]) && $model->attributes[$field] !== null) {
                    $value = $model->attributes[$field];
                    // Only encrypt if not already encrypted
                    if (!$model->isEncrypted($value)) {
                        $model->attributes[$field] = Crypt::encryptString($value);
                    }
                }
            }
        });
    }

    public function getPiiFields(): array
    {
        return $this->piiFields ?? [];
    }

    public function getDecryptedAttribute(string $field): ?string
    {
        $value = $this->attributes[$field] ?? null;
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Value may not be encrypted (e.g., in testing with SQLite)
            return $value;
        }
    }

    protected function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);
            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
