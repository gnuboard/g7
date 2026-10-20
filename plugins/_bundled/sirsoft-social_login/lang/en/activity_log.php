<?php

return [
    'action' => [
        'account_linked' => 'Social account linked',
        'account_auto_linked' => 'Social account auto-linked',
        'account_unlinked' => 'Social account unlinked',
        'account_unlinked_by_admin' => 'Social account force-unlinked',
        'account_unlinked_by_webhook' => 'Unlinked by provider notice',
        'signup' => 'Social signup',
    ],
    'description' => [
        'account_linked' => 'Member :user_name linked a :provider account.',
        'account_auto_linked' => 'A :provider account with the same provider-verified email was linked to member :user_name automatically.',
        'account_unlinked' => 'The :provider link of member :user_name was removed.',
        'account_unlinked_by_admin' => 'An administrator removed the :provider link of member :user_name.',
        'account_unlinked_by_webhook' => 'The link of member :user_name was removed by a :provider unlink notice.',
        'signup' => 'Member :user_name signed up with a :provider account.',
    ],
];
