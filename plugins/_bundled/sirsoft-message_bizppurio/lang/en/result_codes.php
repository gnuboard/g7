<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bizppurio result code → reason (English)
|--------------------------------------------------------------------------
|
| Source: Bizppurio official response-code docs (https://bizppurio.github.io/response-codes/)
| As of: 2026-07-27 (aligned to the latest official docs; legacy manual wording corrected)
| Scope: common(2000~9071) + SMS(4100~4443) + LMS(6600~6641) + Alimtalk(7000~7523).
|        RCS(8000s)/NaverTalkTalk(part of 5000s) are out of scope (follow-up D15).
|
*/

return [
    // ── Common / send response ───────────────────────────
    '1000' => 'Success',
    '2000' => 'Message is invalid',
    '3001' => 'Invalid authentication (Basic)',
    '3002' => 'Invalid token (expired/revoked)',
    '3003' => 'Invalid IP',
    '3004' => 'Account is invalid',
    '3005' => 'Invalid authentication (Bearer)',
    '3006' => 'Account does not exist',
    '3007' => 'Invalid account password',
    '3009' => 'Account suspended',
    '3010' => 'IP not in allowlist',
    '3011' => 'Unknown error (Bizppurio)',
    '3013' => 'Message not completed',
    '5002' => 'Too many requests',
    '5003' => 'Temporary delivery error',
    '5004' => 'Temporary delivery error',
    '5005' => 'Temporary delivery error',
    '9000' => 'Temporary system error',
    '9070' => 'Insufficient balance (SMS)',
    '9071' => 'Postpaid limit exceeded',

    // ── SMS report ───────────────────────────────────────
    '4100' => 'Success',
    '4400' => 'Out of service area',
    '4401' => 'Power off',
    '4402' => 'Storage full',
    '4410' => 'Invalid number',
    '4414' => 'Disconnected/suspended number',
    '4420' => 'Other device error',
    '4430' => 'Spam',
    '4431' => 'Delivery-restricted opt-out (spam)',
    '4443' => 'Spam blocked',

    // ── LMS report ───────────────────────────────────────
    '6600' => 'Success',
    '6603' => 'Out of service area',
    '6604' => 'Power off',
    '6606' => 'Invalid number',
    '6621' => 'Message length exceeded',
    '6641' => 'Spam blocked',

    // ── Alimtalk report ──────────────────────────────────
    '7000' => 'Success',
    '7103' => 'Invalid sender profile key',
    '7106' => 'Deleted sender key',
    '7107' => 'Blocked sender key',
    '7204' => 'Message content does not match template',
    '7206' => 'Serial number format mismatch',
    '7306' => 'Kakao system error',
    '7307' => 'Processing delayed',
    '7308' => 'Phone number error',
    '7320' => 'Receiver blocked',
    '7325' => 'Variable length exceeded',
    '7421' => 'Timeout',
    '7436' => 'Insufficient wallet balance (Alimtalk)',
    '7437' => 'Message request failed',
    '7523' => '080 opt-out (spam)',
];
