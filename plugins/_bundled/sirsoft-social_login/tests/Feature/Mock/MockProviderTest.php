<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Mock;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Http\Middleware\MockGateMiddleware;
use Plugins\Sirsoft\SocialLogin\Listeners\RegisterMockProvidersListener;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Mock\MockKeyPair;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 모의 제공자 시험 — CLI 켜기·끄기·상태, 꺼짐 404, 모의 제공자 5종으로 실제 드라이버 왕복.
 *
 * 드라이버가 모의 엔드포인트로 보내는 서버 간 요청(토큰, 사용자 정보, discovery, JWKS)은 `Http::fake()` 콜백이 이 시험 앱으로
 * 그대로 넘긴다 — 네트워크를 타지 않고 모의 컨트롤러를 실제로 밟는다.
 *
 * @since 1.0.0
 */
class MockProviderTest extends PluginTestCase
{
    /**
     * 모의 게이트를 끈 채로 끝냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(MockGate::class)->disable();

        parent::tearDown();
    }

    /**
     * 모의 엔드포인트로 가는 서버 간 요청을 시험 앱으로 넘깁니다.
     *
     * @return void 반환값 없음
     */
    private function routeMockHttpIntoApp(): void
    {
        Http::fake(function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = (string) parse_url($request->url(), PHP_URL_QUERY);
            parse_str($query, $queryParams);

            $server = [];
            if ($request->hasHeader('Authorization')) {
                $server['HTTP_AUTHORIZATION'] = $request->header('Authorization')[0];
            }

            $data = $request->method() === 'GET' ? $queryParams : $request->data();
            $response = $this->call($request->method(), $path, $data, [], [], $server);

            return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
        });
    }

    /**
     * 모의 제공자로 테스트 왕복을 끝까지 돌리고 결과 화면 HTML 을 돌려줍니다.
     *
     * @param  string  $provider  모의 제공자 키
     * @param  string  $variant  변종
     * @param  string  $decision  동의 결정 (approve 또는 deny)
     * @return string 결과 화면 HTML
     */
    private function roundTrip(string $provider, string $variant, string $decision = 'approve'): string
    {
        $authorizeUrl = app(OAuthFlowService::class)->start($provider, AttemptIntent::Test, null, null);
        $this->assertStringStartsWith(url('/plugins/sirsoft-social_login/mock/authorize'), $authorizeUrl);
        parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $authorize);

        $this->get(parse_url($authorizeUrl, PHP_URL_PATH).'?'.parse_url($authorizeUrl, PHP_URL_QUERY))
            ->assertOk()
            ->assertSee('name="variant"', false);

        $consent = $this->post('/plugins/sirsoft-social_login/mock/authorize', $authorize + [
            'variant' => $variant,
            'decision' => $decision,
            'email' => 'meas40.mock@example.test',
            'subject' => 'MockUser01',
        ]);
        $consent->assertStatus(302);
        $callback = (string) $consent->headers->get('Location');
        $this->assertStringStartsWith(OAuthFlowService::callbackUrl($provider), $callback);

        $result = (string) $this->get(parse_url($callback, PHP_URL_PATH).'?'.parse_url($callback, PHP_URL_QUERY))->headers->get('Location');
        $this->assertStringContainsString('/test-result/', $result);

        return (string) $this->get(parse_url($result, PHP_URL_PATH))->assertOk()->getContent();
    }

    /**
     * 모의 제공자 명령의 켜기, 끄기, 상태, 범위 밖 시간 거절을 확인한다.
     *
     * @scenario cli=mock
     *
     * @effects mock_cli_on_off_status
     *
     * @return void 반환값 없음
     */
    public function test_cli_enables_disables_and_reports(): void
    {
        $this->artisan('sirsoft-social_login:mock', ['--status' => true])->assertSuccessful();
        $this->assertFalse(app(MockGate::class)->enabled());

        $this->artisan('sirsoft-social_login:mock', ['--enable' => true, '--minutes' => 999])->assertFailed();
        $this->assertFalse(app(MockGate::class)->enabled(), '최대 240분을 넘기면 켜지 않는다');

        $this->artisan('sirsoft-social_login:mock', ['--enable' => true, '--minutes' => 30])->assertSuccessful();
        $this->assertTrue(app(MockGate::class)->enabled());
        $this->assertEqualsWithDelta(time() + 1800, app(MockGate::class)->until(), 5);

        $this->artisan('sirsoft-social_login:mock', ['--disable' => true])->assertSuccessful();
        $this->assertFalse(app(MockGate::class)->enabled());

        $this->artisan('sirsoft-social_login:mock')->assertFailed();
    }

    /**
     * 캐시 저장소가 만료 시각을 숫자 문자열로 돌려줘도(Redis 는 숫자를 직렬화하지 않고 문자열로 돌려준다) 켜짐을 판정하는지 확인한다.
     *
     * @scenario cli=mock, cache_store=redis
     *
     * @effects mock_gate_accepts_numeric_string_from_cache
     *
     * @return void 반환값 없음
     */
    public function test_gate_reads_expiry_returned_as_numeric_string(): void
    {
        $gate = app(MockGate::class);
        $cache = (new \ReflectionProperty(MockGate::class, 'cache'))->getValue($gate);

        $cache->put('mock_enabled_until', (string) (time() + 600), 600);
        $this->assertTrue($gate->enabled(), '숫자 문자열 만료 시각도 켜짐');
        $this->assertEqualsWithDelta(time() + 600, $gate->until(), 5);

        $cache->put('mock_enabled_until', (string) (time() - 1), 600);
        $this->assertFalse($gate->enabled(), '지난 시각이면 꺼짐');

        $cache->put('mock_enabled_until', 'on', 600);
        $this->assertFalse($gate->enabled(), '숫자가 아닌 값이면 꺼짐');
    }

    /**
     * 모의 제공자가 꺼져 있으면 모의 라우트 전부가 받는 모든 메서드에서 404 인지 확인한다. 모집단은 라우터의 모의 라우트명
     * 서브트리라서, 모의 라우트를 하나 더해도 이 시험이 그 라우트까지 잰다.
     *
     * @scenario gate=off
     *
     * @effects mock_routes_404_when_off
     *
     * @return void 반환값 없음
     */
    public function test_mock_endpoints_are_404_while_off(): void
    {
        $routes = $this->mockRoutes();
        $this->assertGreaterThanOrEqual(6, count($routes), '모의 라우트를 찾지 못했다 — 라우트명이 바뀌었는가');

        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertSame(404, $this->call($method, '/'.$route->uri())->getStatusCode(), $method.' '.$route->uri());
            }
        }

        $this->assertArrayNotHasKey('mock_kakao', app(ProviderRegistry::class)->all());
    }

    /**
     * 모의 라우트군의 게이트는 그룹 한 곳(확장 미들웨어 선언)이 맡는다 — 핸들러마다 게이트를 적으면 새 라우트에서 빠뜨린다
     * (docs/backend/routing.md 「디버그·개발 라우트는 그룹 단위로 게이트한다」). 모든 모의 라우트가 선언의 대상 패턴에 든다.
     *
     * @scenario gate=registration
     *
     * @effects mock_gate_declared_for_route_group
     *
     * @return void 반환값 없음
     */
    public function test_mock_route_group_gate_is_declared_once(): void
    {
        $declarations = array_values(array_filter(
            (new Plugin)->getMiddleware(),
            fn (array $entry) => $entry['class'] === MockGateMiddleware::class,
        ));
        $this->assertCount(1, $declarations);
        $this->assertSame(['web'], $declarations[0]['groups']);

        $routes = $this->mockRoutes();
        $this->assertGreaterThanOrEqual(6, count($routes));
        foreach ($routes as $route) {
            $covered = collect($declarations[0]['targets'])->contains(fn (string $pattern) => Str::is($pattern, (string) $route->getName()));
            $this->assertTrue($covered, (string) $route->getName());
        }
    }

    /**
     * 이 플러그인의 모의 라우트(이름이 `web.plugins.sirsoft-social_login.mock.` 로 시작)를 모읍니다.
     *
     * @return array<int, Route> 라우트
     */
    private function mockRoutes(): array
    {
        return array_values(array_filter(
            iterator_to_array(app('router')->getRoutes()),
            fn ($route) => str_starts_with((string) $route->getName(), 'web.plugins.sirsoft-social_login.mock.'),
        ));
    }

    /**
     * 모의 제공자 표시 이름이 사이트가 지원하는 모든 언어로 만들어지는지 확인한다(일본어 화면에서 한국어 이름이 보이지 않게).
     *
     * @scenario gate=on, locale=ko|en|ja
     *
     * @effects mock_label_per_supported_locale
     *
     * @return void 반환값 없음
     */
    public function test_mock_labels_cover_every_supported_locale(): void
    {
        // 번들 일본어 팩을 쓰는 사이트와 같게 — 시험 환경 기본값은 ko·en 이다
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);
        $locales = ['ko', 'en', 'ja'];

        foreach (RegisterMockProvidersListener::configs() as $key => $config) {
            $this->assertSame($locales, array_keys($config['label']), $key);
            foreach ($locales as $locale) {
                $this->assertSame(__('sirsoft-social_login::messages.mock.label', ['provider' => $config['driver']], $locale), $config['label'][$locale], $key.' '.$locale);
            }
        }
    }

    /**
     * 모의 동의 화면이 이 플러그인 콜백이 아닌 주소로 보내기를 거절하는지 확인한다.
     *
     * @scenario gate=on, redirect=foreign
     *
     * @effects mock_refuses_foreign_redirect
     *
     * @return void 반환값 없음
     */
    public function test_mock_refuses_to_redirect_outside_plugin_callback(): void
    {
        app(MockGate::class)->enable(10);

        $this->get('/plugins/sirsoft-social_login/mock/authorize?redirect_uri='.urlencode('https://evil.example.test/cb'))->assertStatus(400);
    }

    /**
     * 모의 제공자를 껐다 다시 켜면 서명 키가 바뀐다 — 키 ID 도 바뀌어야 캐시된 공개키 목록을 다시 받아 id_token 을 검증한다.
     *
     * @scenario gate=off_then_on, provider=mock_google
     *
     * @effects reenabled_gate_rotates_kid
     *
     * @return void 반환값 없음
     */
    public function test_reenabled_gate_rotates_kid_so_id_token_still_verifies(): void
    {
        app(MockGate::class)->enable(10);
        $this->routeMockHttpIntoApp();
        $this->assertStringContainsString('me*********@example.test', $this->roundTrip('mock_google', 'full_verified'));
        $firstKid = app(MockKeyPair::class)->jwk()['kid'];

        app(MockGate::class)->disable();
        app(MockGate::class)->enable(10);
        app(ProviderRegistry::class)->flush();

        $this->assertNotSame($firstKid, app(MockKeyPair::class)->jwk()['kid']);
        $this->assertStringContainsString('me*********@example.test', $this->roundTrip('mock_google', 'full_verified'));
    }

    /**
     * 모의 제공자 5종 × 대표 변종 — 실제 드라이버가 모의 엔드포인트로 왕복한다.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}> 사례 이름 => 인자 목록
     */
    public static function roundTrips(): array
    {
        return [
            '카카오 완비' => ['mock_kakao', 'full_verified', 'me*********@example.test', 'MockUser01'],
            '카카오 마스킹' => ['mock_kakao', 'masked_email', 'kakao_email_absent', 'MockUser01'],
            '네이버 완비(대소문자 보존)' => ['mock_naver', 'full_verified', 'me*********@example.test', 'MockUser01'],
            '네이버 024 오류' => ['mock_naver', 'error_body', '024', ''],
            '구글 id_token 검증' => ['mock_google', 'full_verified', 'me*********@example.test', 'MockUser01'],
            '페이스북 GET 토큰' => ['mock_facebook', 'no_email', 'Mock User', 'MockUser01'],
            'OIDC discovery·문자열 boolean' => ['mock_oidc', 'string_bool', 'me*********@example.test', 'MockUser01'],
            '거부' => ['mock_kakao', 'deny', 'access_denied', ''],
        ];
    }

    /**
     * 모의 연결을 해제할 때의 권한 회수도 실제 드라이버로 성공한다 — 회수 주소가 토큰 주소를 가리키면(인가 코드 없음 400,
     * 페이스북 DELETE 는 경로 없음) 모의 해제마다 가짜 「회수 실패」 시도가 쌓여 진단·통계의 실패 수가 부풀려진다.
     *
     * @scenario gate=on, provider=mock_kakao|mock_naver|mock_google|mock_facebook, action=revoke
     *
     * @effects mock_revoke_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_mock_revoke_succeeds_through_real_drivers(): void
    {
        app(MockGate::class)->enable(10);
        $this->routeMockHttpIntoApp();
        $registry = app(ProviderRegistry::class);
        $registry->flush();

        foreach (['mock_kakao', 'mock_naver', 'mock_google', 'mock_facebook'] as $key) {
            $driver = $registry->get($key);
            $this->assertTrue((bool) ($driver->supports()['revoke'] ?? false), $key);
            $account = new SocialAccount(['provider_key' => $key, 'provider_user_id' => 'MockUser01']);
            $this->assertTrue($driver->revoke(new TokenSet('mock-access', 'mock-refresh'), $account), $key);
        }
    }

    /**
     * 모의 제공자로 실제 드라이버 왕복을 끝까지 돌려 결과 화면에 이르는지 확인한다.
     *
     * @scenario gate=on, provider=mock_*, variant=catalog
     *
     * @effects mock_round_trip_reaches_result_page
     *
     * @param  string  $provider  데이터 제공자가 준 값
     * @param  string  $variant  데이터 제공자가 준 값
     * @param  string  $expect  데이터 제공자가 준 값
     * @param  string  $alsoExpect  데이터 제공자가 준 값
     * @return void 반환값 없음
     */
    #[DataProvider('roundTrips')]
    public function test_round_trip_through_real_drivers(string $provider, string $variant, string $expect, string $alsoExpect): void
    {
        app(MockGate::class)->enable(10);
        $this->routeMockHttpIntoApp();

        $html = $this->roundTrip($provider, $variant, $variant === 'deny' ? 'deny' : 'approve');

        if ($expect === 'kakao_email_absent') {
            $this->assertStringNotContainsString('ka***@kakao.com', $html, '마스킹 이메일은 결과에 싣지 않는다');
        } else {
            $this->assertStringContainsString($expect, $html);
            $this->assertStringNotContainsString('meas40.mock@example.test', $html, '이메일은 가려서 보인다');
        }

        if ($alsoExpect !== '') {
            $this->assertStringNotContainsString($alsoExpect, $html, '제공자 사용자 ID 는 가려서 보인다');
            $this->assertStringContainsString('Mo******01', $html);
        }
    }
}
