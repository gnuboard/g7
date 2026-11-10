<?php

namespace Plugins\Sirsoft\CdnCloudfront\Tests\Feature;

use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Command;
use Aws\Result;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\CdnCloudfront\Models\CloudfrontPurge;
use Plugins\Sirsoft\CdnCloudfront\Tests\PluginTestCase;

/**
 * 설정 화면 보조 API — 무효화 대기 현황·연결 시험의 권한과 응답
 *
 * @scenario-manifest tests/scenarios/cloudfront-admin-api.yaml
 */
class AdminApiTest extends PluginTestCase
{
    private const PREFIX = '/api/plugins/sirsoft-cdn_cloudfront/admin';

    /** 연결 시험 분당 상한 (라우트 throttle 과 같은 값) */
    private const TEST_CONNECTION_PER_MINUTE = 20;

    /**
     * 비회원은 401, 플러그인 조회 권한이 없는 관리자는 403 이다. 연결 시험은 수정 권한이 있어야 한다.
     *
     * @scenario case=permission
     *
     * @effects status_requires_plugins_read, test_requires_plugins_update
     *
     * @return void 반환값 없음
     */
    public function test_permissions(): void
    {
        $this->getJson(self::PREFIX.'/purge-status')->assertUnauthorized();
        $this->postJson(self::PREFIX.'/test-connection')->assertUnauthorized();

        $this->actingAs($this->createAdminUser());
        $this->getJson(self::PREFIX.'/purge-status')->assertForbidden();
        $this->postJson(self::PREFIX.'/test-connection')->assertForbidden();

        $this->actingAs($this->createAdminUser(['core.plugins.read']));
        $this->getJson(self::PREFIX.'/purge-status')->assertOk();
        $this->postJson(self::PREFIX.'/test-connection')->assertForbidden();

        $this->assertSame([], $this->commands);
    }

    /**
     * 코어 설정 저장 API 로 저장할 때 CDN 주소·배포 ID 모양을 검증하고, 가린 자격 값이 돌아오면 저장값을 유지한다.
     * 조회 응답은 자격 값을 가린다.
     *
     * @scenario case=settings_validation
     *
     * @effects settings_shape_validated_and_secrets_masked
     *
     * @return void 반환값 없음
     */
    public function test_settings_validation_and_masking(): void
    {
        $this->registerListeners();
        $this->actingAs($this->createAdminUser(['core.plugins.read', 'core.plugins.update']));
        $url = '/api/admin/plugins/'.self::IDENTIFIER.'/settings';

        $this->putJson($url, ['cdn_base_url' => 'ftp://cdn.example.test'])->assertUnprocessable()->assertJsonValidationErrors('cdn_base_url');
        $this->putJson($url, ['cdn_base_url' => 'https://cdn.example.test/?v=1'])->assertUnprocessable()->assertJsonValidationErrors('cdn_base_url');
        $this->putJson($url, ['distribution_id' => 'e0lower'])->assertUnprocessable()->assertJsonValidationErrors('distribution_id');

        $this->putJson($url, [
            'cdn_base_url' => self::CDN,
            'distribution_id' => self::DISTRIBUTION,
            'access_key' => 'TESTACCESSKEY',
            'secret_key' => 'test-secret-value',
            'purge_enabled' => true,
        ])->assertOk();

        $shown = $this->getJson($url)->assertOk();
        $this->assertStringNotContainsString('test-secret-value', $shown->getContent());
        $this->assertStringNotContainsString('TESTACCESSKEY', $shown->getContent());

        $this->putJson($url, ['access_key' => $shown->json('data.access_key'), 'secret_key' => $shown->json('data.secret_key')])->assertOk();
        $this->assertSame('test-secret-value', plugin_setting(self::IDENTIFIER, 'secret_key'));
        $this->assertSame('TESTACCESSKEY', plugin_setting(self::IDENTIFIER, 'access_key'));
    }

    /**
     * 현황은 재시도 대기·멈춤 건수와 가장 최근 실패 사유를 낸다.
     *
     * @scenario case=status
     *
     * @effects status_reports_pending_and_last_error
     *
     * @return void 반환값 없음
     */
    public function test_purge_status_reports_queue(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        CloudfrontPurge::query()->create(['paths' => ['/a/*'], 'attempts' => 1, 'last_error' => 'old', 'next_attempt_at' => Carbon::now()->addMinutes(5)]);
        Carbon::setTestNow('2026-11-05 10:01:00');
        CloudfrontPurge::query()->create(['paths' => ['/b/*'], 'attempts' => 5, 'last_error' => 'AccessDenied: denied', 'next_attempt_at' => null]);

        $this->actingAs($this->createAdminUser(['core.plugins.read']))
            ->getJson(self::PREFIX.'/purge-status')
            ->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.abandoned', 1)
            ->assertJsonPath('data.last_error', 'AccessDenied: denied')
            ->assertJsonPath('data.last_success_at', null)
            ->assertJsonPath('data.purge_ready', false);

        Carbon::setTestNow();
    }

    /**
     * 연결 시험은 저장된 배포 ID 로 GetDistribution 을 보내고 상태·도메인·CDN 주소 일치 여부를 낸다.
     * 자격이 비면 API 를 부르지 않고 422, CloudFront 가 거절하면 그 사유를 담아 422 다.
     *
     * @scenario case=test_connection
     *
     * @effects test_connection_reports_distribution
     *
     * @return void 반환값 없음
     */
    public function test_connection_test(): void
    {
        $this->actingAs($this->createAdminUser(['core.plugins.read', 'core.plugins.update']));

        $this->postJson(self::PREFIX.'/test-connection')
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'missing_credentials');
        $this->assertSame([], $this->commands);

        $this->configureReady();
        $this->aws->append(new Result(['Distribution' => [
            'Id' => self::DISTRIBUTION,
            'Status' => 'Deployed',
            'DomainName' => 'd111111abcdef8.cloudfront.net',
            'DistributionConfig' => ['Enabled' => true, 'Aliases' => ['Quantity' => 1, 'Items' => ['cdn.example.test']]],
        ]]));

        $this->postJson(self::PREFIX.'/test-connection')
            ->assertOk()
            ->assertJsonPath('data.status', 'Deployed')
            ->assertJsonPath('data.domain_name', 'd111111abcdef8.cloudfront.net')
            ->assertJsonPath('data.cdn_host_matches', true);
        $this->assertSame('GetDistribution', $this->commands[0]->getName());
        $this->assertSame(self::DISTRIBUTION, $this->commands[0]['Id']);

        $this->aws->append(new CloudFrontException('The specified distribution does not exist.', new Command('GetDistribution'), ['code' => 'NoSuchDistribution', 'message' => 'The specified distribution does not exist.']));

        $response = $this->postJson(self::PREFIX.'/test-connection')
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'request_failed');
        $this->assertStringContainsString('NoSuchDistribution', (string) $response->json('message'));
        $this->assertStringNotContainsString('test-secret-value', $response->getContent());
    }

    /**
     * 연결 시험은 외부 API 를 부르므로 분당 횟수를 제한한다 — 제한에 걸린 요청은 CloudFront 를 부르지 않는다.
     *
     * @scenario case=test_connection
     *
     * @effects test_connection_throttled
     *
     * @return void 반환값 없음
     */
    public function test_connection_test_is_throttled(): void
    {
        $this->actingAs($this->createAdminUser(['core.plugins.read', 'core.plugins.update']));
        $status = null;

        for ($i = 0; $i <= self::TEST_CONNECTION_PER_MINUTE; $i++) {
            $status = $this->postJson(self::PREFIX.'/test-connection')->getStatusCode();
        }

        $this->assertSame(429, $status);
        $this->assertSame([], $this->commands);
    }
}
