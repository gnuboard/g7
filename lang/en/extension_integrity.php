<?php

return [
    // Verification status names (badges, command output)
    'statuses' => [
        'verified' => 'Signature verified',
        'unsigned' => 'Unsigned',
        'invalid' => 'Signature mismatch',
        'untrusted' => 'Untrusted key',
        'bundled' => 'Bundled source',
        'unverifiable' => 'Cannot verify signature',
        'unknown' => 'Not recorded',
    ],

    // Verification reasons (PackageVerification::$reason)
    'reasons' => [
        'unsigned' => 'This package has no signature file.',
        'format_unknown' => 'The signature file format is not recognised (unsupported format or algorithm, or required fields are missing).',
        'type_mismatch' => 'The package type in the signature file (:actual) differs from the type being installed (:expected).',
        'identifier_mismatch' => 'The identifier in the signature file (:actual) differs from the identifier being installed (:expected).',
        'version_mismatch' => 'The version in the signature file (:actual) differs from the version being fetched (:expected).',
        'size_mismatch' => 'The package file size differs from the size in the signature file. The file was changed or not fully downloaded.',
        'digest_mismatch' => 'The SHA-256 of the package file differs from the signature file. The file was changed.',
        'key_unknown' => 'The signing key (:key) is not in the trusted key list.',
        'key_revoked' => 'The signing key has been revoked.',
        'key_id_mismatch' => 'The signing key ID does not match the registered public key.',
        'core_requires_builtin_key' => 'Core packages must be signed with the official key built into the core. A core signed with an author key is not accepted.',
        'vendor_out_of_scope' => 'The author key that signed this package is not registered to trust its vendor (:vendor).',
        'sodium_missing' => 'A signature file is present, but this server has no PHP sodium extension, so the signature cannot be checked.',
        'signature_mismatch' => 'The signature does not match the package contents. The file or the signature was changed.',
        'manifest_identifier_mismatch' => 'The manifest identifier inside the package (:actual) differs from the signed identifier (:expected).',
        'manifest_version_mismatch' => 'The manifest version inside the package (:actual) differs from the signed version (:expected).',
    ],

    'errors' => [
        'package_unsigned' => 'This package is not signed. If you have reviewed it, choose "Continue unsigned" to install it. :reason',
        'package_unverifiable' => 'This server cannot verify the package signature. If you have reviewed it, choose "Continue unsigned" to install it. :reason',
        'package_unsigned_cli' => 'This package is not signed. :reason',
        'package_unverifiable_cli' => 'This server cannot verify the package signature. :reason',
        'package_unsigned_blocked' => 'Unsigned packages cannot be installed because Settings > Security "Block unsigned packages" is on. :reason',
        'package_signature_invalid' => 'Installation stopped because the package signature does not match. :reason',
        'package_signer_untrusted' => 'Installation stopped because the package is signed with an untrusted key. :reason',
        'signature_url_not_public' => 'The signature file URL must be a public http or https address.',
        'signature_download_failed' => 'Could not download the signature file. (HTTP :status)',
        'signature_too_large' => 'The signature file is too large (max 64KB).',
        'signature_file_not_found' => 'Signature file not found: :path',
        'label_invalid' => 'The signer name must be up to 32 letters, digits, _ or -.',
    ],

    // Trust key management (Settings > Security > Extension trust keys, API)
    'trust_keys' => [
        'fetch_success' => 'Loaded the extension trust keys.',
        'created' => 'Trust key added.',
        'deleted' => 'Trust key deleted.',
        'not_found' => 'Trust key not found.',
        'password_invalid' => 'The password is incorrect.',
        'reauth_invalid' => 'Your identity confirmation has expired or is invalid. Please confirm again.',
        'create_failed' => 'Could not add the trust key.',
        'delete_failed' => 'Could not delete the trust key.',
        'fetch_failed' => 'Could not load the trust keys.',
    ],

    // Trust key input validation
    'validation' => [
        'public_key_required' => 'Enter the public key.',
        'public_key_invalid' => 'The public key must be the standard base64 of an Ed25519 public key (32 bytes).',
        'label_required' => 'Enter the signer name.',
        'label_invalid' => 'The signer name must be up to 32 letters, digits, _ or -.',
        'label_reserved' => 'The signer name sirsoft is reserved for the built-in core key.',
        'vendor_scope_required' => 'Specify at least one vendor this key is trusted for.',
        'vendor_invalid' => 'A vendor must be the leading part of an extension identifier, starting with a lowercase letter or digit.',
        'vendor_reserved' => 'The vendor sirsoft is reserved for the built-in core key and cannot be assigned to an author key.',
        'duplicate_key' => 'This key is already registered.',
        'builtin_conflict' => 'A key identical to a built-in core key cannot be registered.',
        'password_required' => 'Enter your password to confirm your identity.',
        'allow_unsigned_boolean' => 'The continue-unsigned flag must be true or false.',
        'integrity_aware_boolean' => 'The package signature screen marker must be true or false.',
        'signature_file' => 'Upload a signature file (.sig.json).',
        'signature_max' => 'The signature file cannot exceed 64KB.',
        'signature_url_invalid' => 'The signature file URL is not valid.',
        'block_unsigned_boolean' => 'Block unsigned packages must be true or false.',
    ],

    'attributes' => [
        'public_key' => 'public key',
        'label' => 'signer name',
        'vendor_scope' => 'trusted vendors',
        'password' => 'password',
        'allow_unsigned' => 'continue unsigned',
        'integrity_aware' => 'package signature screen marker',
        'signature' => 'signature file',
        'signature_url' => 'signature file URL',
        'extension_block_unsigned' => 'block unsigned packages',
    ],

    'commands' => [
        'status_line' => 'Package signature: :status',
        'verified_line' => 'Package signature verified — signer :label (:key)',
        'unsigned_confirm' => 'This package is not signed (:reason). Continue anyway?',
        'unsigned_noninteractive_hint' => 'If you have reviewed it, run again with --allow-unsigned. --force and --yes do not replace the unsigned confirmation.',
        'unsigned_blocked_hint' => 'Even --allow-unsigned cannot proceed until Settings > Security "Block unsigned packages" is turned off.',
        'signature_not_found' => 'Signature file not found: :path',
    ],

    // Package integrity section of php artisan about
    'about' => [
        'section' => 'Package integrity',
        'builtin_keys' => 'Built-in trust keys',
        'sodium' => 'PHP sodium extension',
        'block_unsigned' => 'Block unsigned packages',
        'available' => 'Available',
        'missing' => 'Missing — signatures cannot be verified, packages are treated as unsigned',
        'on' => 'On',
        'off' => 'Off',
        'unknown' => 'Unknown',
    ],
];
