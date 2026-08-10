<?php

declare(strict_types=1);

return [
    'encrypt_failed' => 'Could not prepare the identity verification request. Please try again in a moment.',
    'decrypt_failed' => 'Failed to decrypt the identity verification result. Please try again.',
    'remote_call_failed' => 'Failed to communicate with the NHN KCP verification server. Please try again in a moment.',
    'not_found' => 'Identity verification information was not found. Please start the verification again.',
    'already_consumed' => 'This identity verification has already been processed.',
    'duplicate_register' => 'An account already exists for this verified identity. Please sign in or reset your password.',
    'binding_mismatch' => 'The verified identity does not match this account.',
    'not_adult' => 'This service requires adult verification. Only users aged 19 or older can proceed.',
    'incomplete_identity' => 'The verification result is incomplete. Please try again with another verification method.',
    'storage_failed' => 'Failed to store the identity verification result. Please try again in a moment.',
];
