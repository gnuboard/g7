<?php

return [
    'ldap_unavailable' => 'This email signs in with a directory account. The directory server is unreachable right now. Please try again shortly.',
    'site_enforced' => 'This site signs in with company accounts only. Please contact your administrator.',
    'password_set_disabled' => 'Members who sign in with a company account cannot create a password for this site.',
    'cli' => [
        'item' => 'Item',
        'value' => 'Value',
        'on' => 'On',
        'off' => 'Off',
        'enforce_site' => 'Site-wide enforcement',
        'enforce_roles' => 'Role enforcement',
        'enforce_linked' => 'Enforce for all SSO-linked members',
        'enforced_idps' => 'IdPs blocking domain password sign-in',
        'emergency_users' => 'Emergency account member IDs',
        'backup' => 'Values saved before disabling',
        'disabled' => 'All SSO enforcement was turned off. Previous values were saved (use --restore to bring them back).',
        'restored' => 'Saved SSO enforcement was restored.',
        'no_backup' => 'There are no saved values to restore. Enforcement was never turned off with --disable.',
    ],
];
