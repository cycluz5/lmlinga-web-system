<?php

/*
 | UI-phase demo staff accounts (session UiRole only).
 | Credentials come from environment — never commit real passwords.
 | Not production authentication.
 */

return [
    'staff_accounts' => [
        [
            'email' => env('DEMO_STAFF_ADMIN_EMAIL', 'admin@example.test'),
            'password' => env('DEMO_STAFF_ADMIN_PASSWORD', 'change-me-locally'),
            'shell_role' => env('DEMO_STAFF_ADMIN_ROLE', 'admin'),
            'display_name' => env('DEMO_STAFF_ADMIN_NAME', 'Demo Admin'),
            'identities' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'DEMO_STAFF_ADMIN_IDENTITIES',
                    env('DEMO_STAFF_ADMIN_EMAIL', 'admin@example.test')
                ))
            ))),
        ],
        [
            'email' => env('DEMO_STAFF_WORKER_EMAIL', 'worker@example.test'),
            'password' => env('DEMO_STAFF_WORKER_PASSWORD', 'change-me-locally'),
            'shell_role' => env('DEMO_STAFF_WORKER_ROLE', 'bhw'),
            'display_name' => env('DEMO_STAFF_WORKER_NAME', 'Demo Worker'),
            'identities' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'DEMO_STAFF_WORKER_IDENTITIES',
                    env('DEMO_STAFF_WORKER_EMAIL', 'worker@example.test')
                ))
            ))),
        ],
    ],
];
