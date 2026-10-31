<?php

return [
    'block_reason' => [
        'deprovisioned' => 'Company account deprovisioned',
        'reactivated' => 'Company account reactivated',
    ],
    'role_mapping' => [
        'super_admin_only' => 'Only a super administrator can change mappings that grant administrator roles, or the role sync settings of an IdP linked to administrators.',
    ],
    'command' => [
        'production_as_of' => '--as-of cannot be used in production.',
        'invalid_as_of' => 'Could not read the --as-of time.',
        'deprovision_check' => 'Periodic check: :idps IdPs, :checked checked, :deprovisioned deprovisioned, :reactivated reactivated, :failed failed',
        'withdraw' => 'Withdraw after deprovision: :withdrawn withdrawn, :skipped_admin admin skipped, :skipped skipped, :failed failed',
        'prune' => 'Retention cleanup: :attempts login attempts, :scim SCIM events, :sessions ended sessions',
        'failed' => ':command failed — check the logs.',
    ],
];
