<?php

/**
 * PII masking configuration.
 * Defines which fields are PII and how to mask them per role.
 */
return [
    'fields' => [
        'last_name' => [
            'mask_type' => 'first_initial',
            'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector'],
        ],
        'phone' => [
            'mask_type' => 'last_four',
            'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector'],
        ],
        'ssn' => [
            'mask_type' => 'last_four',
            'visible_roles' => ['system_admin'],
        ],
        'email' => [
            'mask_type' => 'partial_email',
            'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector', 'employer_manager'],
        ],
        'street' => [
            'mask_type' => 'redact',
            'visible_roles' => ['system_admin', 'compliance_reviewer', 'inspector'],
        ],
        'date_of_birth' => [
            'mask_type' => 'year_only',
            'visible_roles' => ['system_admin', 'compliance_reviewer'],
        ],
    ],
];
