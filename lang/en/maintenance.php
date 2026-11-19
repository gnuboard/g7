<?php

return [
    'title' => 'Under Maintenance',
    'message' => 'The system is currently being updated.',
    'description' => 'Please try again later. We apologize for the inconvenience.',
    'admin_access' => [
        'title' => 'Administrators: continue the core update',
        'hint' => 'A core update started from the admin screen is in progress, rolling back, or needs recovery. Confirm an administrator account with core update permission to enter the admin screen in this browser and continue from the Update tab.',
        'email' => 'Administrator email',
        'password' => 'Password',
        'submit' => 'Confirm and open the admin screen',
        'failed' => 'Could not confirm. Enter the email and password of an administrator account with core update permission.',
        'throttled' => 'Too many attempts. Please try again in 10 minutes.',
    ],
];
