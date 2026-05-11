<?php

namespace Tests\Feature\Exceptions;

use App\Exceptions\IdentityVerificationRequiredException;
use App\Helpers\ResponseHelper;
use Tests\TestCase;

/**
 * IdentityVerificationRequiredException → HTTP 428 매핑 검증.
 *
 * Exception → ResponseHelper::identityRequired() 경로의 페이로드 구조를 직접 검증.
 * bootstrap/app.php 의 render 등록이 이 구조를 그대로 반환하도록 보장합니다.
 */
class IdentityRequiredResponseTest extends TestCase
{
    public function test_response_helper_returns_428_with_verification_payload(): void
    {
        $exception = new IdentityVerificationRequiredException(
            policyKey: 'core.profile.password_change',
            purpose: 'sensitive_action',
            providerId: 'g7:core.mail',
            renderHint: 'text_code',
            returnRequest: [
                'method' => 'PUT',
                'url' => '/api/me/password',
            ],
        );

        $response = ResponseHelper::identityRequired($exception->getPayload());

        $this->assertSame(428, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);

        $this->assertFalse($body['success']);
        $this->assertSame('identity_verification_required', $body['error_code']);
        $this->assertSame('core.profile.password_change', $body['verification']['policy_key']);
        $this->assertSame('sensitive_action', $body['verification']['purpose']);
        $this->assertSame('g7:core.mail', $body['verification']['provider_id']);
        $this->assertSame('text_code', $body['verification']['render_hint']);
        $this->assertSame('PUT', $body['verification']['return_request']['method']);
    }

    public function test_exception_preserves_all_fields(): void
    {
        $exception = new IdentityVerificationRequiredException(
            policyKey: 'core.account.withdraw',
            purpose: 'sensitive_action',
        );

        $this->assertSame('core.account.withdraw', $exception->policyKey);
        $this->assertSame('sensitive_action', $exception->purpose);
        $this->assertNull($exception->providerId);
        $this->assertNull($exception->renderHint);

        $payload = $exception->getPayload();
        $this->assertSame('/api/identity/challenges', $payload['challenge_start_url']);
    }
}
