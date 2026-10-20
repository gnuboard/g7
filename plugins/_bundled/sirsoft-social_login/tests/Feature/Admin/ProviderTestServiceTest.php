<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Admin;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 연결 테스트 — 존재하지 않는 인가 코드로 토큰 주소를 불러 거절 사유를 4종으로 분류하고, 자격증명 누락·discovery·서명 키
 * 실패를 앞 단계에서 잡는다. [브라우저로 로그인 테스트]는 인가 주소를 준다.
 *
 * @since 1.0.0
 */
class ProviderTestServiceTest extends PluginTestCase
{
    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-social_login/admin/providers';

    /**
     * 변경 권한 관리자로 로그인하고 카카오를 자격증명과 함께 둡니다(꺼진 채 — 테스트는 활성과 무관).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableKakao(['enabled' => false]);
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('tester')], [
            'sirsoft-social_login.settings.view', 'sirsoft-social_login.settings.update',
        ]));
    }

    /**
     * 카카오 토큰 주소의 거절 본문별 분류: 코드 오류(KOE320)=정상, 비밀값 오류(KOE010)=클라이언트 오류, 콜백 주소(KOE006)=주소
     * 불일치, 연결 실패·5xx=도달 불가.
     *
     * @scenario token_reply=KOE320|KOE010|KOE006|connection_failed|server_error
     *
     * @effects code_rejected_means_credentials_ok, bad_secret_is_invalid_client, redirect_mismatch_classified, unreachable_classified
     *
     * @return void 반환값 없음
     */
    public function test_token_probe_classification(): void
    {
        $reply = null;
        Http::fake(['kauth.kakao.com/oauth/token' => function () use (&$reply) {
            return $reply();
        }]);

        $cases = [
            'credentials_ok' => fn () => Http::response(['error' => 'invalid_grant', 'error_code' => 'KOE320'], 400),
            'invalid_client' => fn () => Http::response(['error' => 'invalid_client', 'error_code' => 'KOE010'], 401),
            'redirect_mismatch' => fn () => Http::response(['error' => 'invalid_request', 'error_code' => 'KOE006'], 400),
            'unreachable' => fn () => throw new ConnectionException('down'),
        ];

        foreach ($cases as $expected => $stub) {
            $reply = $stub;
            $response = $this->postJson(self::BASE.'/kakao/test')->assertOk();
            $this->assertSame($expected, $response->json('data.result'), $expected);
            $this->assertSame($expected === 'credentials_ok', $response->json('data.ok'));
        }

        $reply = fn () => Http::response(['error' => 'server_error'], 503);
        $this->assertSame('unreachable', $this->postJson(self::BASE.'/kakao/test')->json('data.result'));
    }

    /**
     * 필수 비밀값이 비면 토큰 주소를 부르지 않고 `credentials_missing` 과 빈 칸 이름을 준다.
     *
     * @scenario credentials=missing_secret
     *
     * @effects missing_credentials_short_circuit
     *
     * @return void 반환값 없음
     */
    public function test_missing_credentials_short_circuit(): void
    {
        Http::fake();
        SocialLoginProvider::query()->where('key', 'kakao')->update(['client_secret' => null]);
        app(ProviderRegistry::class)->flush();

        $response = $this->postJson(self::BASE.'/kakao/test')->assertOk();
        $this->assertSame('credentials_missing', $response->json('data.result'));
        $this->assertSame(['client_secret'], $response->json('data.steps.0.fields'));
        Http::assertNothingSent();
    }

    /**
     * 저장된 비밀값을 풀 수 없으면(앱 키 교체) 「비어 있음」이 아니라 `credentials_unreadable` 로 알리고 토큰 주소를 부르지 않는다.
     *
     * @scenario credentials=unreadable
     *
     * @effects unreadable_credentials_reported_by_test
     *
     * @return void 반환값 없음
     */
    public function test_unreadable_secret_is_reported(): void
    {
        Http::fake();
        SocialLoginProvider::query()->where('key', 'kakao')->toBase()->update(['client_secret' => 'not-a-valid-ciphertext']);
        app(ProviderRegistry::class)->flush();

        $response = $this->postJson(self::BASE.'/kakao/test')->assertOk();
        $this->assertSame('credentials_unreadable', $response->json('data.result'));
        $this->assertSame(['client_secret'], $response->json('data.steps.0.fields'));
        Http::assertNothingSent();
    }

    /**
     * 일반 OIDC: discovery 실패와 서명 키 목록 실패를 토큰 요청 전에 잡는다.
     *
     * @scenario oidc=discovery_down|jwks_empty
     *
     * @effects discovery_failure_reported, jwks_failure_reported
     *
     * @return void 반환값 없음
     */
    public function test_oidc_discovery_and_jwks_failures(): void
    {
        SocialLoginProvider::query()->create([
            'key' => 'corp', 'driver' => 'oidc', 'type' => 'custom', 'enabled' => false, 'sort_order' => 9,
            'label' => ['ko' => '사내 계정'], 'client_id' => 'c', 'client_secret' => 's',
            'discovery_url' => 'https://idp.example.test/.well-known/openid-configuration',
            'signup_allowed' => true, 'email_missing_policy' => 'ask', 'email_trust' => 'flag',
        ]);
        app(ProviderRegistry::class)->flush();

        $discovery = null;
        Http::fake([
            'idp.example.test/.well-known/*' => function () use (&$discovery) {
                return $discovery();
            },
            'idp.example.test/jwks' => Http::response(['keys' => []]),
        ]);

        $discovery = fn () => Http::response('', 500);
        $this->assertSame('discovery_failed', $this->postJson(self::BASE.'/corp/test')->assertOk()->json('data.result'));

        $discovery = fn () => Http::response([
            'issuer' => 'https://idp.example.test', 'authorization_endpoint' => 'https://idp.example.test/auth',
            'token_endpoint' => 'https://idp.example.test/token', 'jwks_uri' => 'https://idp.example.test/jwks',
        ]);
        app(ProviderRegistry::class)->flush();
        cache()->flush();
        $this->assertSame('jwks_unreachable', $this->postJson(self::BASE.'/corp/test')->json('data.result'));
    }

    /**
     * [브라우저로 로그인 테스트]는 꺼진 제공자에도 인가 주소를 주고, 없는 제공자는 404 다.
     *
     * @scenario test_login=disabled_provider|unknown_provider
     *
     * @effects test_login_returns_authorize_url, unknown_provider_404
     *
     * @return void 반환값 없음
     */
    public function test_test_login_url(): void
    {
        $url = $this->postJson(self::BASE.'/kakao/test-login')->assertOk()->json('data.authorize_url');
        $this->assertStringStartsWith('https://kauth.kakao.com/oauth/authorize?', (string) $url);

        $this->postJson(self::BASE.'/nothing/test-login')->assertStatus(404);
        $this->postJson(self::BASE.'/nothing/test')->assertStatus(404);
    }
}
