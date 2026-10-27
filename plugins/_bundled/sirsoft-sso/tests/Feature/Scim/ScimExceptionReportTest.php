<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Scim;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Exceptions;
use Plugins\Sirsoft\Sso\Exceptions\ScimException;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;
use RuntimeException;

/**
 * SCIM 정상 거절(없는 사용자 404, 중복 409 등)은 SCIM 오류 응답으로 나가고 예외 보고기(laravel 로그 ERROR + 스택)에 남지 않는다.
 *
 * `ScimException` 은 `RuntimeException` 이라 자체 `render()` 로 응답을 만들어도 처리기가 먼저 보고했다 — IdP 가 주기적으로 보내는
 * 조회·생성마다 정상 거절이 오류 로그로 쌓인다(코어 정상 거절 예외와 같은 결함군, A1 `ShouldntReport`).
 *
 * @scenario-manifest plugins/_bundled/sirsoft-sso/tests/scenarios/sso-exception-report.yaml
 *
 * @since 1.0.0
 */
class ScimExceptionReportTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 없는 사용자 조회와 같은 사용자 재생성 — 응답은 SCIM 오류 그대로, 보고는 0.
     *
     * @scenario rejection=not_found|uniqueness
     *
     * @effects scim_error_response_unchanged, scim_rejection_not_reported
     *
     * @return void 반환값 없음
     */
    public function test_scim_rejections_are_not_reported(): void
    {
        Exceptions::fake();
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        $this->scim('GET', $idp, '/Users/999999999', $token)
            ->assertStatus(404)
            ->assertHeader('Content-Type', ScimException::CONTENT_TYPE)
            ->assertJsonPath('schemas.0', ScimException::ERROR_SCHEMA)
            ->assertJsonPath('status', '404');

        $body = [
            'schemas' => [ScimSchema::USER],
            'userName' => 'report-001',
            'externalId' => 'E-report-001',
            'emails' => [['value' => $this->uniqueEmail('corp798.test'), 'primary' => true]],
            'active' => true,
        ];
        $this->scim('POST', $idp, '/Users', $token, $body)->assertStatus(201);
        $this->scim('POST', $idp, '/Users', $token, $body)->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');

        Exceptions::assertNotReported(ScimException::class);
    }

    /**
     * 처리기 판정 — SCIM 오류는 보고 대상이 아니고, 대조군 RuntimeException 은 보고 대상이다.
     *
     * @scenario rejection=handler
     *
     * @effects scim_rejection_not_reported, other_errors_still_reported
     *
     * @return void 반환값 없음
     */
    public function test_handler_does_not_report_scim_exception(): void
    {
        $handler = app(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(new ScimException(404, null, 'not found')));
        $this->assertFalse($handler->shouldReport(new ScimException(409, 'uniqueness', 'exists')));
        $this->assertTrue($handler->shouldReport(new RuntimeException('real failure')));
    }
}
