<?php

return [
    'flow' => [
        'expired' => 'The pending signup has expired. Please start the social sign-in again.',
        'mail_failed' => 'We could not send the verification code. Please contact the site administrator.',
        'email_locked' => 'The email cannot be changed for this signup.',
        'email_taken' => 'This email is already registered. Please enter another email.',
        'email_not_verified' => 'Only the email confirmed with a verification code can be used.',
        'challenge_mismatch' => 'The verification request does not match. Please request a new code.',
        'invalid_code' => 'The verification code is wrong or has expired.',
        'name_required' => 'Please enter your name.',
        'existing_account_pending' => 'An account with the same email exists. Choose to link it or to sign up as a new account.',
        'not_existing_step' => 'This is not the existing-account step.',
        'password_not_available' => 'This account has no password. Please confirm with an email code.',
        'password_mismatch' => 'The password is incorrect.',
        'too_many_attempts' => 'Confirmation failed too many times. Please start the social sign-in again.',
        'admin_blocked' => 'Administrator accounts cannot use social sign-in. Please sign in with your password.',
        'already_linked_other_user' => 'This social account is linked to another member.',
        'provider_already_linked' => 'Another account of this provider is already linked. Unlink it first.',
        'provider_disabled' => 'This provider is not available.',
        'provider_unknown' => 'The provider cannot be found.',
        'not_linked' => 'This social account is not linked.',
        'last_method' => 'You have no password and only one link, so it cannot be removed. Set a password or link another account first.',
        'reauth_not_available' => 'This account cannot be confirmed with a social account.',
        'provider_error' => 'We could not reach the social account provider. Please try again later.',
        'reauth_claim_invalid' => 'The confirmation result is missing or already used. Please confirm again.',
    ],
];
