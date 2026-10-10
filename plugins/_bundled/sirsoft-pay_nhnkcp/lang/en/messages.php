<?php

declare(strict_types=1);

return [
    'claim' => [
        'invalid_callback' => 'The callback parameters required to approve the claim payment are missing.',
        'window_failed' => 'The payment window authentication did not complete.',
        'currency_not_supported' => 'NHN KCP claim payments support KRW only.',
        'deposit_not_supported' => 'Claim additional payments do not support deposit-pending methods (virtual account).',
        'compensation_cancel_reason' => 'Automatically cancelled after the claim payment approval was rejected',
        'amount_mismatch' => 'The payment amount does not match the charge, or the charge could not be determined, so the payment was not confirmed (any approved amount is cancelled). Please start the payment again from the claim.',
    ],
    'refund' => [
        'missing_tid' => 'Cannot process refund: transaction number (tno) is missing.',
        'default_reason' => 'Buyer refund request',
        'in_progress' => 'NHN KCP refund is already in progress.',
        'vbank_requires_bank_transfer' => 'A settled virtual account payment cannot be refunded automatically through NHN KCP. Change the refund method to bank transfer and send the money to the customer account directly.',
    ],
    'escrow' => [
        'invoice_required' => 'Please enter the tracking number.',
        'courier_required' => 'Please select a courier.',
    ],
    'errors' => [
        'payment_failed' => 'Payment could not be completed. Please try again in a moment.',
        'vbank_issuance_incomplete' => 'The virtual account issuance details are missing, so the issuance could not be confirmed. Please try the payment again.',
        'wsdl_missing' => 'KCP WSDL file not found: :file',
        'approval_key_error' => 'KCP approval key error [:code]: :message',
        'soap_error' => 'KCP SOAP integration error: :message',
        'cli_binary_missing' => 'KCP CLI binary missing: :path',
        'cli_binary_not_executable' => 'KCP CLI binary is not executable. Operator action required (sudo chmod 755 :path)',
    ],
    // Rows of the settings "System check" card (item name, result, remediation) and the payment-server IP source — text built by the server
    'health' => [
        'checked' => 'System check completed.',
        'source' => [
            'public_outbound' => 'Public outbound IP',
            'server_variable' => 'Server IP candidate',
            'unavailable' => 'Needs checking',
        ],
        'exec' => [
            'label' => 'PHP exec() function',
            'ok' => 'CLI can be called',
            'disabled' => 'exec is blocked by disable_functions in php.ini.',
            'disabled_remediation' => "Remove 'exec' from the disable_functions list in php.ini, then restart PHP-FPM (or Apache).\nExample: sudo sed -i 's/disable_functions = .*exec.*/disable_functions =/' /etc/php/8.x/fpm/php.ini && sudo systemctl restart php8.x-fpm",
        ],
        'bin_dir' => [
            'label' => 'CLI binary directory',
            'ok' => 'Directory exists',
            'missing' => 'The bin directory does not exist: :path',
            'missing_remediation' => 'Reinstall the plugin, or copy the CLI binaries provided by KCP into bin/.',
        ],
        'cli' => [
            'label' => 'CLI binary (:arch) — :filename',
            'missing' => 'File not found: :path',
            'missing_remediation' => 'Copy the KCP CLI binary for the current OS (:arch) into bin/. (Bundled with the plugin for the test environment.)',
            'windows' => 'Windows binary — execute permission check skipped',
            'executable' => 'Execute permission OK (:mode)',
            'fixed' => 'Execute permission restored automatically: :before → 0755',
            'not_executable' => 'No execute permission (:before) — automatic fix failed',
            'not_executable_no_permission' => 'No execute permission (:before) — automatic fix skipped because you do not have permission to change plugin settings',
            'not_executable_remediation' => "Grant execute permission to this file:\n  chmod 755 :path\nPrefix it with sudo if you are not the file owner.",
        ],
        'pub_key' => [
            'label' => 'KCP public key (pub.key)',
            'ok' => 'File exists',
            'missing' => 'pub.key file not found: :path',
            'missing_remediation' => 'Copy the pub.key file provided by KCP into bin/. (Bundled with the plugin for the test environment.)',
        ],
        'soap' => [
            'label' => 'PHP SOAP extension',
            'ok' => 'SoapClient available',
            'missing' => 'Mobile payment (SmartPhone Pay) will not work.',
            'missing_remediation' => "Install the PHP SOAP extension and restart PHP-FPM:\nUbuntu/Debian: sudo apt install php8.x-soap && sudo systemctl restart php8.x-fpm\nRHEL/CentOS:   sudo yum install php-soap && sudo systemctl restart php-fpm\nCheck: php -m | grep soap",
        ],
        'wsdl' => [
            'label' => 'WSDL files',
            'ok' => 'Both test and live WSDL files exist',
            'missing' => 'Missing: :files',
            'missing_remediation' => 'Copy the WSDL files provided by KCP into bin/. (One each for test and live.)',
        ],
    ],
    'settings' => [
        'redirect_url_format' => 'The post-payment redirect address must start with {shopBase}, / or http(s):// and cannot contain spaces. Protocol-relative //addresses and javascript: are not allowed.',
    ],
];
