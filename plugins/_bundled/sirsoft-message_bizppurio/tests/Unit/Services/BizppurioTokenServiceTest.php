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
 */
class BizppurioTokenServiceTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * 메모리 배열로 remember/forget 을 재현하는 CacheInterface mock.
     *
     * @param  array<string, mixed>  $store  참조로 전달되는 캐시 저장소
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
     * @param  array<string, mixed>  $settings
     */
    private function makeSettings(array $settings): PluginSettingsService
    {
        $mock = Mockery::mock(PluginSettingsService::class);
        $mock->shouldReceive('get')->with(self::IDENTIFIER)->andReturn($settings);

        return $mock;
    }

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

    public function test_verifyCredentials는_캐시를_거치지_않고_재발급한다(): void
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

    public function test_verifyCredentials_실패시_예외를_전파하고_캐시를_건드리지_않는다(): void
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
