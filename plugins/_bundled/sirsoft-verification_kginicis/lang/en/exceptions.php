<?php

declare(strict_types=1);

return [
    'invalid_auth_url' => 'Invalid authentication response. Not an Inicis standard domain.',
    'decrypt_failed' => 'Failed to decrypt identity verification data. Please try again.',
    'remote_call_failed' => 'Failed to communicate with Inicis verification server. Please try again later.',
    'not_found' => 'Identity verification record not found. Please verify again.',
    'already_consumed' => 'This identity verification has already been processed.',
    'duplicate_register' => 'An account already exists for the verified identity. Please use login or password recovery.',
    'binding_mismatch' => 'The verified identity does not match the target account.',
    'not_adult' => 'This service requires adult verification. Only users aged 19 or older can use it.',
    'incomplete_identity' => 'The identity verification data is incomplete. Please try again with a different verification method.',
    'storage_failed' => 'Failed to save the identity verification data. Please try again later.',
];
