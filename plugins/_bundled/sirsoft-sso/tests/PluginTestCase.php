<?php

namespace Plugins\Sirsoft\Sso\Tests;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Sso\Console\Commands\MockCommand;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Plugin;
use Plugins\Sirsoft\Sso\Providers\SsoServiceProvider;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\Support\BundledVendorLoader;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

// 플러그인 자체 composer 의존성은 _bundled vendor 에서 읽는다 — 설치본이 있어도 _bundled 가 이기도록 맨 앞에 건다(BundledVendorLoader).
require_once __DIR__.'/Support/BundledVendorLoader.php';
BundledVendorLoader::register(dirname(__DIR__));

/**
 * SSO 연동 플러그인 테스트 베이스.
 *
 * 테스트 앱은 `plugins` 테이블의 활성 행으로 확장을 고르지 않으므로 서비스 프로바이더, 라우트, 미들웨어 선언, 훅 리스너를 여기서 직접
 * 등록한다. 라우트 접두·이름은 `PluginRouteServiceProvider` 와 같게 맞춘다. 데이터는 `DatabaseTransactions` 로 되돌린다(시험 DB 에
 * 플러그인 마이그레이션을 미리 적용해 둔다 — `php artisan migrate --env=testing --path=plugins/_bundled/sirsoft-sso/database/migrations`).
 *
 * 모의 OIDC IdP 는 같은 앱의 컨트롤러를 IdP HTTP 응답으로 돌려준다(`fakeMockIdp()`) — 시험이 바깥 네트워크를 쓰지 않는다.
 *
 * @since 1.0.0
 */
abstract class PluginTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var array{hooks: array, filters: array, dispatching: array}|null 훅 스냅샷 */
    private ?array $hookSnapshot = null;

    /** @var array<string, int> 모의 IdP 주소별 호출 수 */
    protected array $mockCalls = [];

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(SsoServiceProvider::class);
        // 플러그인 사전 — 설치하지 않은 환경에서도 `sirsoft-sso::` 문구가 풀리게 번들 디렉토리 lang 을 건다(다른 번들 플러그인 시험과 같은 방식)
        $this->app['translator']->addNamespace('sirsoft-sso', dirname(__DIR__).'/lang');
        $this->registerPluginRoutes();
        $this->registerPluginMiddleware();

        $this->snapshotHookManager();
        $this->registerPluginHookListeners();
        app(ProtocolRegistry::class)->flush();
    }

    /**
     * 테스트를 정리합니다 — 시험이 더한 훅을 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * 플러그인 웹·API 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginRoutes(): void
    {
        $base = dirname(__DIR__).'/src/routes';

        Route::prefix('plugins/sirsoft-sso')
            ->name('web.plugins.sirsoft-sso.')
            ->middleware('web')
            ->group($base.'/web.php');

        Route::prefix('api/plugins/sirsoft-sso')
            ->name('api.plugins.sirsoft-sso.')
            ->middleware('api')
            ->group($base.'/api.php');

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /**
     * 확장 미들웨어 선언(`getMiddleware()`)이 시험에서도 실제 경로로 붙게 합니다.
     *
     * @return void 반환값 없음
     */
    private function registerPluginMiddleware(): void
    {
        $registry = new class(app(CacheInterface::class)) extends ExtensionMiddlewareRegistry
        {
            /**
             * 시험에 필요한 활성 확장(이 플러그인)만 반환합니다.
             *
             * @return iterable<int, array{0: Plugin, 1: string, 2: string}> 활성 확장
             */
            protected function activeExtensions(): iterable
            {
                yield [new Plugin, 'sirsoft-sso', 'plugin'];
            }
        };
        ExtensionMiddlewareRegistry::flush();
        $this->app->instance(ExtensionMiddlewareRegistryInterface::class, $registry);
    }

    /**
     * plugin.php 의 리스너를 컨테이너로 만들어 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginHookListeners(): void
    {
        foreach ((new Plugin(['identifier' => 'sirsoft-sso', 'vendor' => 'sirsoft', 'version' => '1.0.0']))->getHookListeners() as $listenerClass) {
            foreach ($listenerClass::getSubscribedHooks() as $hookName => $config) {
                $method = $config['method'] ?? 'handle';
                $priority = $config['priority'] ?? 10;
                $callback = fn (...$args) => app($listenerClass)->{$method}(...$args);

                if (($config['type'] ?? 'action') === 'filter') {
                    HookManager::addFilter($hookName, $callback, $priority);
                } else {
                    HookManager::addAction($hookName, $callback, $priority);
                }
            }
        }
    }

    /**
     * HookManager 상태를 저장합니다.
     *
     * @return void 반환값 없음
     */
    private function snapshotHookManager(): void
    {
        $ref = new \ReflectionClass(HookManager::class);
        $this->hookSnapshot = [
            'hooks' => $ref->getProperty('hooks')->getValue(),
            'filters' => $ref->getProperty('filters')->getValue(),
            'dispatching' => $ref->getProperty('dispatching')->getValue(),
        ];
    }

    /**
     * 저장한 시점으로 HookManager 를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    private function restoreHookManager(): void
    {
        if ($this->hookSnapshot === null) {
            return;
        }

        $ref = new \ReflectionClass(HookManager::class);
        $ref->getProperty('hooks')->setValue(null, $this->hookSnapshot['hooks']);
        $ref->getProperty('filters')->setValue(null, $this->hookSnapshot['filters']);
        $ref->getProperty('dispatching')->setValue(null, $this->hookSnapshot['dispatching']);
        $this->hookSnapshot = null;
    }

    /**
     * 모의 OIDC IdP 행을 만들고 게이트를 켭니다.
     *
     * @param  array<string, mixed>  $attributes  더하거나 바꿀 속성 (`config` 는 병합)
     * @param  string  $key  IdP 키 (모의 접두 `mock_`)
     * @return IdentityProvider 만든 모의 IdP
     */
    protected function mockOidcIdp(array $attributes = [], string $key = 'mock_oidc'): IdentityProvider
    {
        app(MockGate::class)->enable(30);
        $this->fakeMockIdp();

        $config = array_merge([
            'discovery_url' => MockOidcController::issuer().'/.well-known/openid-configuration',
            'client_id' => MockCommand::MOCK_CLIENT_ID,
            'scopes' => 'openid email profile',
            'subject_claim' => 'sub',
            'use_pkce' => true,
            'token_endpoint_auth' => 'client_secret_post',
        ], $attributes['config'] ?? []);
        unset($attributes['config']);

        return IdentityProvider::query()->create(array_merge([
            'key' => $key,
            'protocol' => 'oidc',
            'enabled' => true,
            'label' => ['ko' => '모의 회사 계정', 'en' => 'Mock company account'],
            'config' => $config,
            'secrets' => ['client_secret' => 'mock-secret'],
            'owned_domains' => ['corp798.test'],
            'provisioning_mode' => 'jit',
            'require_consent' => true,
        ], $attributes));
    }

    /**
     * 모의 IdP 주소로 가는 서버 간 HTTP 를 같은 앱의 모의 컨트롤러로 돌려줍니다.
     *
     * 커널을 다시 타지 않고 컨트롤러를 직접 부른다 — 바깥 요청(콜백) 처리 도중이라 커널을 다시 돌리면 요청 바인딩이 바뀐다.
     *
     * @return void 반환값 없음
     */
    protected function fakeMockIdp(): void
    {
        $issuer = MockOidcController::issuer();

        Http::fake(function (ClientRequest $request) use ($issuer) {
            $url = $request->url();
            if (! str_starts_with($url, $issuer)) {
                return null;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->mockCalls[$path] = ($this->mockCalls[$path] ?? 0) + 1;
            $controller = app(MockOidcController::class);

            $data = $request->data();
            $server = [];
            if ($request->hasHeader('Authorization')) {
                $server['HTTP_AUTHORIZATION'] = $request->header('Authorization')[0];
            }
            $inner = Request::create($url, $request->method(), is_array($data) ? $data : [], [], [], $server);

            $response = match (true) {
                str_ends_with($path, '/.well-known/openid-configuration') => $controller->discovery(),
                str_ends_with($path, '/jwks') => $controller->jwks(),
                str_ends_with($path, '/token') => $controller->token($inner),
                default => response('', 404),
            };

            return Http::response(json_decode((string) $response->getContent(), true), $response->getStatusCode());
        });
    }

    /**
     * 모의 IdP 인가 화면에서 승인하고 콜백 쿼리(code, state 또는 error)를 받습니다.
     *
     * @param  string  $authorizeUrl  IdP 인가 주소 (시작 응답의 Location)
     * @param  string  $variant  응답 변종
     * @param  array<string, string>  $profile  모의 신원 (email, sub, name)
     * @return array<string, string> 콜백 쿼리
     */
    protected function approveAtMock(string $authorizeUrl, string $variant = 'full', array $profile = []): array
    {
        parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $query);
        $request = Request::create($authorizeUrl, 'POST', array_merge($query, [
            'decision' => 'approve',
            'variant' => $variant,
            'email' => $profile['email'] ?? 'kim798@corp798.test',
            'sub' => $profile['sub'] ?? '',
            'name' => $profile['name'] ?? '김직원',
        ]));

        $response = app(MockOidcController::class)->authorize($request);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $callback);

        return $callback;
    }

    /**
     * 로그인 왕복을 처음부터 콜백까지 돌립니다 — 같은 브라우저(묶음 쿠키)를 유지한다.
     *
     * @param  IdentityProvider  $idp  왕복할 IdP
     * @param  string  $variant  응답 변종
     * @param  array<string, string>  $profile  모의 신원 (email, sub, name)
     * @param  string  $startQuery  시작 쿼리 (`guard=…&redirect=…` 또는 `ticket=…`)
     * @return TestResponse 콜백 응답
     */
    protected function roundTrip(IdentityProvider $idp, string $variant = 'full', array $profile = [], string $startQuery = 'guard=user'): TestResponse
    {
        $start = $this->withBrowserBind()->get('/plugins/sirsoft-sso/login/'.$idp->key.'?'.$startQuery);
        $start->assertStatus(302);
        $callback = $this->approveAtMock((string) $start->headers->get('Location'), $variant, $profile);

        return $this->withBrowserBind()->get('/plugins/sirsoft-sso/oidc/'.$idp->key.'/callback?'.http_build_query($callback));
    }

    /** @var string|null 시험 브라우저의 묶음 쿠키 값 */
    protected ?string $browserBind = null;

    /**
     * 시험 브라우저의 묶음 쿠키를 다음 요청에 싣습니다 (없으면 만든다).
     *
     * @return $this 체이닝
     */
    protected function withBrowserBind(): static
    {
        $this->browserBind ??= bin2hex(random_bytes(32));

        // JSON 요청은 withCredentials() 가 있어야 쿠키를 싣는다(브라우저의 credentials 와 같은 뜻).
        return $this->withCredentials()->withUnencryptedCookie(StateStore::BIND_COOKIE, $this->browserBind);
    }

    /**
     * 쿠키 없는 브라우저로 바꿉니다 — 시험 요청의 쿠키는 테스트 객체에 남으므로 직접 비운다.
     *
     * @return $this 체이닝
     */
    protected function withoutBrowserCookies(): static
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->browserBind = null;

        return $this;
    }

    /**
     * 다른 브라우저로 바꿉니다 (묶음 쿠키 새로).
     *
     * @return void 반환값 없음
     */
    protected function switchBrowser(): void
    {
        $this->browserBind = bin2hex(random_bytes(32));
    }

    /**
     * 302 응답의 이동 주소에서 쿼리를 꺼냅니다.
     *
     * @param  TestResponse|Response  $response  응답
     * @return array<string, string> 쿼리
     */
    protected function redirectQuery(TestResponse|Response $response): array
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $q);

        return $q;
    }

    /**
     * 302 응답의 이동 경로를 꺼냅니다.
     *
     * @param  TestResponse|Response  $response  응답
     * @return string 경로
     */
    protected function redirectPath(TestResponse|Response $response): string
    {
        return (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
    }

    /**
     * 응답이 낸 코어 로그인 인계 코드를 같은 브라우저(인계 묶음 쿠키)로 교환합니다.
     *
     * web 콜백 302 는 이동 주소의 `login_handoff`, api 완료 응답은 `data.handoff_code` 에서 코드를 읽는다.
     *
     * @param  TestResponse  $response  인계 코드를 낸 응답
     * @param  string  $guard  교환 경로 (user, admin)
     * @return array{user: User, context: array<string, mixed>}|null 교환 결과
     */
    protected function redeemHandoff(TestResponse $response, string $guard = 'user'): ?array
    {
        $code = $response->headers->has('Location')
            ? ($this->redirectQuery($response)['login_handoff'] ?? '')
            : (string) $response->json('data.handoff_code');
        $cookie = $response->getCookie(LoginHandoffService::BIND_COOKIE, false);
        $request = Request::create('/api/auth/login/handoff', 'POST', [], $cookie ? [LoginHandoffService::BIND_COOKIE => $cookie->getValue()] : []);

        return app(LoginHandoffService::class)->redeem((string) $code, $guard, $request);
    }

    /**
     * 회원과 IdP 신원 연결을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  IdentityProvider  $idp  왕복할 IdP
     * @param  string  $subject  IdP 안의 고유 식별자
     * @param  array<string, mixed>  $attributes  더할 속성
     * @return SsoAccount 연결
     */
    protected function link(User $user, IdentityProvider $idp, string $subject, array $attributes = []): SsoAccount
    {
        return SsoAccount::query()->create(array_merge([
            'idp_id' => $idp->id,
            'user_id' => $user->id,
            'subject' => $subject,
            'linked_via' => 'admin',
            'active' => true,
            'linked_at' => now(),
        ], $attributes));
    }

    /**
     * 메일 인증번호 요청에 알려진 인증번호를 심습니다.
     *
     * @param  string  $challengeId  본인인증 요청 ID
     * @return string 심은 인증번호
     */
    protected function plantCode(string $challengeId): string
    {
        $log = IdentityVerificationLog::query()->findOrFail($challengeId);
        $metadata = $log->metadata ?? [];
        $metadata['code_hash'] = Hash::make('135790');
        $log->metadata = $metadata;
        $log->save();

        return '135790';
    }

    /**
     * 실행마다 겹치지 않는 시험 이메일을 만듭니다.
     *
     * @param  string  $domain  도메인
     * @return string 이메일
     */
    protected function uniqueEmail(string $domain = 'example.test'): string
    {
        return 'meas798.'.Str::lower(Str::random(10)).'@'.$domain;
    }
}
