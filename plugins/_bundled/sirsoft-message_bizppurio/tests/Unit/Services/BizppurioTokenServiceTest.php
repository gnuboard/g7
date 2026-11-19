<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Contracts\Extension\CacheInterface;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTokenService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioTokenService — 토큰 발급·캐시 재사용·재발급 검증.
 *
 * @scenario credential_state=filled
 */
class BizppurioTokenServiceTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * 메모리 배열로 remember/forget 을 재현하는 CacheInterface mock.
     *
     * @param  array<string, mixed>  $store  참조로 전달되는 캐시 저장소
     * @return CacheInterface remember, forget, put 을 배열로 흉내 내는 캐시 mock
     */
    private function makeCache(array &$store): CacheInterface
    {
        $mock = Mockery::mock(CacheInterface::class);

        $mock->shouldReceive('remember')->andReturnUsing(
            function (string $key, callable $callback) use (&$store) {
                if (! array_key_exists($key, $store)) {
                    $store[$key] = $callback();
                }

                return $store[$key];
            }
        );

        $mock->shouldReceive('forget')->andReturnUsing(
            function (string $key) use (&$store) {
                unset($store[$key]);

                return true;
            }
        );

        $mock->shouldReceive('put')->andReturnUsing(
            function (string $key, $value) use (&$store) {
                $store[$key] = $value;

                return true;
            }
        );

        return $mock;
    }

    /**
     * bizppurio_id/password/is_test_mode 를 반환하는 설정 mock.
     *
     * @param  array<string, mixed>  $settings  get 호출이 돌려줄 플러그인 설정 값
     * @return PluginSettingsService 설정 서비스 mock
     */
    private function makeSettings(array $settings): PluginSettingsService
    {
        $mock = Mockery::mock(PluginSettingsService::class);
        $mock->shouldReceive('get')->with(self::IDENTIFIER)->andReturn($settings);

        return $mock;
    }

    /**
     * 처음 발급한 토큰을 두 번째 getToken 이 캐시에서 꺼내 HTTP 호출이 한 번뿐인지 확인한다.
     *
     * @scenario credential_state=filled, test_mode=on
     *
     * @effects token_service_reuses_cached_token_with_single_http_call
     *
     * @return void 반환값 없음
     */
    public function test_토큰_발급_후_캐시에서_재사용된다(): void
    {
        Http::fake([
            '*/v1/token' => Http::response(['accesstoken' => 'TOKEN_A', 'type' => 'Bearer'], 200),
        ]);

        $store = [];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => true]),
        );

        $this->assertSame('TOKEN_A', $service->getToken());
        // 두 번째 호출은 캐시에서 → HTTP 1회만
        $this->assertSame('TOKEN_A', $service->getToken());
        Http::assertSentCount(1);
    }

    /**
     * refreshToken 이 캐시를 지우고 새 토큰을 다시 발급해 HTTP 호출이 두 번 나가는지 확인한다.
     *
     * @scenario credential_state=filled, test_mode=on
     *
     * @effects token_refresh_forgets_cache_and_reissues
     *
     * @return void 반환값 없음
     */
    public function test_refresh는_forget_후_재발급한다(): void
    {
        $tokens = ['TOKEN_A', 'TOKEN_B'];
        Http::fake([
            '*/v1/token' => Http::sequence()
                ->push(['accesstoken' => 'TOKEN_A', 'type' => 'Bearer'], 200)
                ->push(['accesstoken' => 'TOKEN_B', 'type' => 'Bearer'], 200),
        ]);

        $store = [];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => true]),
        );

        $this->assertSame('TOKEN_A', $service->getToken());
        // refresh → 캐시 무효화 후 새 토큰
        $this->assertSame('TOKEN_B', $service->refreshToken());
        Http::assertSentCount(2);
    }

    /**
     * 검수 모드가 켜져 있으면 토큰 요청이 dev-api.bizppurio.com 으로 나가는지 확인한다.
     *
     * @scenario credential_state=filled, test_mode=on
     *
     * @effects test_mode_off_selects_live_domain_test_mode_on_selects_dev_domain
     *
     * @return void 반환값 없음
     */
    public function test_검수_모드는_dev_도메인을_호출한다(): void
    {
        Http::fake([
            'dev-api.bizppurio.com/*' => Http::response(['accesstoken' => 'T', 'type' => 'Bearer'], 200),
        ]);

        $store = [];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => true]),
        );

        $service->getToken();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'dev-api.bizppurio.com'));
    }

    /**
     * 검수 모드가 꺼져 있으면 토큰 요청이 dev 가 아닌 api.bizppurio.com 으로 나가는지 확인한다.
     *
     * @scenario credential_state=filled, test_mode=off
     *
     * @effects test_mode_off_selects_live_domain_test_mode_on_selects_dev_domain
     *
     * @return void 반환값 없음
     */
    public function test_운영_모드는_live_도메인을_호출한다(): void
    {
        Http::fake([
            'api.bizppurio.com/*' => Http::response(['accesstoken' => 'T', 'type' => 'Bearer'], 200),
        ]);

        $store = [];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => false]),
        );

        $service->getToken();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.bizppurio.com')
            && ! str_contains($request->url(), 'dev-api.bizppurio.com'));
    }

    /**
     * 아이디와 비밀번호가 비어 있으면 getToken 이 BizppurioApiException 을 던지는지 확인한다.
     *
     * @scenario credential_state=empty
     *
     * @effects token_issue_throws_when_credentials_missing
     *
     * @return void 반환값 없음
     */
    public function test_자격증명_미설정시_예외(): void
    {
        $store = [];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => '', 'password' => '']),
        );

        $this->expectException(BizppurioApiException::class);
        $service->getToken();
    }

    /**
     * 토큰 발급 응답이 500 이면 getToken 이 BizppurioApiException 을 던지는지 확인한다.
     *
     * @scenario credential_state=filled, test_mode=on
     *
     * @effects token_issue_throws_on_http_failure
     *
     * @return void 반환값 없음
     */
    public function test_http_실패시_예외(): void
    {
        Http::fake(['*/v1/token' => Http::response([], 500)]);

        $store = [];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => true]),
        );

        $this->expectException(BizppurioApiException::class);
        $service->getToken();
    }

    /**
     * verifyCredentials 가 캐시에 옛 토큰이 있어도 바로 재발급하고 새 토큰으로 캐시를 덮어쓰는지 확인한다.
     *
     * @scenario credential_state=filled, connection_check_result=valid_credentials
     *
     * @effects verify_credentials_bypasses_cache_and_stores_fresh_token
     *
     * @return void 반환값 없음
     */
    public function test_verify_credentials는_캐시를_거치지_않고_재발급한다(): void
    {
        Http::fake([
            '*/v1/token' => Http::response(['accesstoken' => 'FRESH_TOKEN', 'type' => 'Bearer'], 200),
        ]);

        $store = ['bizppurio:token' => 'STALE_TOKEN'];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => true]),
        );

        $this->assertSame('FRESH_TOKEN', $service->verifyCredentials());
        Http::assertSentCount(1);
        $this->assertSame('FRESH_TOKEN', $store['bizppurio:token']);
    }

    /**
     * verifyCredentials 가 발급 실패 시 예외를 던지고 기존 캐시 토큰은 그대로 두는지 확인한다.
     *
     * @scenario credential_state=filled, connection_check_result=invalid_credentials
     *
     * @effects verify_credentials_failure_propagates_and_keeps_cached_token
     *
     * @return void 반환값 없음
     */
    public function test_verify_credentials_실패시_예외를_전파하고_캐시를_건드리지_않는다(): void
    {
        Http::fake(['*/v1/token' => Http::response([], 500)]);

        $store = ['bizppurio:token' => 'EXISTING_TOKEN'];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => true]),
        );

        try {
            $service->verifyCredentials();
            $this->fail('예외가 발생해야 합니다.');
        } catch (BizppurioApiException $e) {
            // 실패 시 캐시는 그대로 보존 — 발송이 기존 토큰(만료 전까지)을 계속 사용
            $this->assertSame('EXISTING_TOKEN', $store['bizppurio:token']);
        }
    }

    /**
     * 비즈뿌리오가 실패 사유와 코드를 주면 예외 메시지에 그 사유가, getResultCode 에 그 코드가 담기는지 확인한다.
     *
     * @scenario credential_state=filled, connection_check_result=invalid_credentials
     *
     * @effects token_issue_failure_message_includes_bizppurio_reason_and_code
     *
     * @return void 반환값 없음
     */
    public function test_토큰_발급_실패시_비즈뿌리오_사유가_메시지에_포함된다(): void
    {
        Http::fake([
            '*/v1/token' => Http::response(['code' => '3007', 'description' => 'invalid password'], 401),
        ]);

        $store = [];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => true]),
        );

        try {
            $service->getToken();
            $this->fail('예외가 발생해야 합니다.');
        } catch (BizppurioApiException $e) {
            $this->assertStringContainsString('invalid password', $e->getMessage());
            $this->assertSame('3007', $e->getResultCode());
        }
    }

    /**
     * 실패 응답에 사유가 없으면 예외 메시지가 token_issue_failed 번역 문구이고 결과코드는 null 인지 확인한다.
     *
     * @scenario credential_state=filled, connection_check_result=invalid_credentials
     *
     * @effects token_issue_failure_without_reason_falls_back_to_fixed_message
     *
     * @return void 반환값 없음
     */
    public function test_토큰_발급_실패시_사유_없으면_고정_메시지로_폴백한다(): void
    {
        Http::fake(['*/v1/token' => Http::response([], 500)]);

        $store = [];
        $service = new BizppurioTokenService(
            $this->makeCache($store),
            $this->makeSettings(['bizppurio_id' => 'acct', 'password' => 'pw', 'is_test_mode' => true]),
        );

        try {
            $service->getToken();
            $this->fail('예외가 발생해야 합니다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame(
                __('sirsoft-message_bizppurio::messages.error.token_issue_failed'),
                $e->getMessage()
            );
            $this->assertNull($e->getResultCode());
        }
    }

    /**
     * Mockery 기대치를 닫은 뒤 부모 정리를 이어서 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }
}
