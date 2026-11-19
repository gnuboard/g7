<?php

return [
    'label' => 'OpenID Connect',
    'config' => [
        'discovery_url' => ['label' => 'Discovery URL', 'hint' => 'The IdP .well-known/openid-configuration address.'],
        'client_id' => ['label' => 'Client ID', 'hint' => 'The value issued when you registered this site as an app in the IdP console.'],
        'client_secret' => ['label' => 'Client secret', 'hint' => 'Hidden after saving. Enter a new value only to change it.'],
        'scopes' => ['label' => 'Scopes', 'hint' => 'Space separated. Add offline_access when periodic checks are on.'],
        'subject_claim' => ['label' => 'Subject claim', 'hint' => 'Default sub. Entra ID issues a different sub per app, so choose oid (same value as SCIM externalId).'],
        'subject_claim_custom' => ['label' => 'Custom claim name', 'hint' => 'Claim used when the subject claim is set to custom.'],
        'claim_email' => ['label' => 'Email claim', 'hint' => 'Default email.'],
        'claim_email_verified' => ['label' => 'Email verified claim', 'hint' => 'Default email_verified. Owned-domain auto-linking requires this to be true.'],
        'claim_name' => ['label' => 'Name claim', 'hint' => 'Default name.'],
        'claim_groups' => ['label' => 'Groups claim', 'hint' => 'Default groups. Accepts an array or a space separated string.'],
        'mfa_acr_values' => ['label' => 'acr values meaning multi-factor', 'hint' => 'List the acr values your IdP sends instead of amr to signal multi-factor.'],
        'use_pkce' => ['label' => 'Use PKCE', 'hint' => 'Recommended (S256).'],
        'token_endpoint_auth' => ['label' => 'Token endpoint authentication', 'hint' => 'client_secret_post or client_secret_basic.'],
        'fetch_userinfo' => ['label' => 'Fetch userinfo', 'hint' => 'Queries userinfo only when the id_token has no email.'],
    ],
    'sp' => [
        'redirect_uri' => 'Redirect URI (sign-in callback)',
        'post_logout_redirect_uri' => 'Post-logout redirect URI',
        'backchannel_logout_uri' => 'Back-channel logout URI',
        'scopes' => 'Requested scopes',
    ],
    'test' => [
        'discovery_ok' => 'Read the discovery document.',
        'discovery_failed' => 'Could not read the discovery document.',
        'metadata_ok' => 'All required metadata keys are present.',
        'jwks_ok' => 'Read the signing keys.',
        'jwks_empty' => 'The signing key list is empty.',
        'jwks_failed' => 'Could not read the signing keys.',
        'redirect_uri' => 'Register the Redirect URI below in the IdP console.',
    ],
    'mock' => [
        'notice' => 'Mock IdP for development and QA. Pick a response variant and approve.',
    ],
];
