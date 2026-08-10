<?php

return [
    'title' => 'Database Account Configuration Error',
    'message' => 'The site cannot be displayed because of a problem with the database account configuration.',
    'description' => 'While this problem persists, modules, plugins and templates are not loaded. Please correct the configuration using the guidance below.',
    'blocked_reason' => 'Database superuser accounts such as root cannot be used, for security reasons. If such an account is compromised, the entire database is at risk.',
    'empty_reason' => 'The database username is empty. The configuration may be missing or corrupted.',
    'recovery_guide' => 'Change DB_WRITE_USERNAME in the .env file to a database account dedicated to this site, then run: php artisan config:clear',
];
