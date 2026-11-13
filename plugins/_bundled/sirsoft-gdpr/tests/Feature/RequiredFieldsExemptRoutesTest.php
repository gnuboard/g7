<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Services\RequiredFieldsChecker;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Gdpr\Listeners\ExemptRequiredFieldsRoutesListener;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 추가 정보를 아직 입력하지 않은 회원에게도 이 플러그인의 쿠키 동의 기록, 동의, 철회, 전체 갱신 요청이 열려 있다.
 *
 * 코어는 비어 있는 필수 회원 항목이 있는 회원의 쓰기 요청을 403 `required_fields_pending` 으로 거부한다. 이 플러그인은 필터
 * `core.required_fields.filter_exempt_routes` 로 자기 라우트 이름을 보태 그 요청들을 통과시킨다. 구독자가 없으면 막히는 것과
 * 구독자가 있으면 필수 항목을 채운 회원과 같은 응답을 받는 것을 대조한다.
 */
class RequiredFieldsExemptRoutesTest extends PluginTestCase
{
    /** 코어가 허용 라우트 이름을 모으는 필터 */
    private const FILTER = 'core.required_fields.filter_exempt_routes';

    /** 운영자가 「기존 회원에게도 입력 요구」를 켠 필수 추가 항목의 키 */
    private const FIELD_KEY = 'rfx_demanded';

    /**
     * 운영자가 기존 회원에게도 요구하는 필수 추가 항목을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        FieldDefinition::create([
            'field_set' => 'core.user', 'key' => self::FIELD_KEY, 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '요구 항목'], 'sort_order' => 900,
            'enabled' => true, 'required' => true, 'require_existing_members' => true,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(self::FILTER);

        parent::tearDown();
    }

    /**
     * 이 플러그인이 열어 두는 요청 목록을 반환합니다.
     *
     * @return array<int, array{0: string, 1: string}> 메서드와 주소
     */
    private function exemptRequests(): array
    {
        return [
            ['POST', '/api/plugins/sirsoft-gdpr/consent/cookie'],
            ['POST', '/api/plugins/sirsoft-gdpr/consent/grant'],
            ['POST', '/api/plugins/sirsoft-gdpr/consent/renew-all'],
            ['POST', '/api/plugins/sirsoft-gdpr/consent/revoke'],
        ];
    }

    /**
     * 회원과 그 인증 헤더를 만듭니다.
     *
     * @param  bool  $filled  필수 추가 항목을 채울지
     * @return array{0: User, 1: array<string, string>} 회원과 인증 헤더
     */
    private function member(bool $filled): array
    {
        $user = User::factory()->create();
        if ($filled) {
            app(FieldValueService::class)->write(
                $user,
                'core.user',
                [self::FIELD_KEY => '채움'],
                FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user),
            );
        }
        $token = $user->fresh()->createToken('member')->plainTextToken;

        return [$user->fresh(), ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }

    /**
     * 요청 사이에 인증 상태가 넘어가지 않게 가드를 비우고 요청을 보냅니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  주소
     * @param  array<string, string>  $headers  인증 헤더
     * @return TestResponse 응답
     */
    private function send(string $method, string $uri, array $headers): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, [], $headers);
    }

    /**
     * 이름으로 등록된 라우트를 찾습니다 (이름 조회표가 갱신되지 않은 테스트 앱에서도 찾도록 전부 훑는다).
     *
     * @param  string  $name  라우트 이름
     * @return RoutingRoute|null 라우트
     */
    private function routeNamed(string $name): ?RoutingRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    /**
     * 리스너는 필터로 구독하고 플러그인이 등록하며, 보태는 이름은 모두 인증 미들웨어가 붙은 실제 쓰기 라우트다.
     *
     * @scenario case=declaration
     *
     * @effects listener_subscribes_exempt_routes_filter_as_filter, plugin_registers_exempt_routes_listener, exempt_route_names_exist_with_auth_middleware, listener_appends_without_dropping_others
     *
     * @return void 반환값 없음
     */
    public function test_listener_declares_filter_and_names_real_routes(): void
    {
        $subscription = ExemptRequiredFieldsRoutesListener::getSubscribedHooks()[self::FILTER] ?? null;
        $this->assertIsArray($subscription);
        $this->assertSame('filter', $subscription['type'] ?? null, "'type' => 'filter' 가 없으면 액션으로 등록돼 반환값이 버려진다");
        $this->assertSame('exemptRoutes', $subscription['method'] ?? null);

        $plugin = (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $this->assertContains(ExemptRequiredFieldsRoutesListener::class, $plugin->getHookListeners());

        $this->assertCount(count($this->exemptRequests()), ExemptRequiredFieldsRoutesListener::ROUTES);
        foreach (ExemptRequiredFieldsRoutesListener::ROUTES as $name) {
            $route = $this->routeNamed($name);
            $this->assertNotNull($route, "라우트 {$name} 가 없다");
            $this->assertSame([], array_intersect($route->methods(), ['GET']), "{$name} 는 쓰기 라우트가 아니다");

            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            $this->assertNotEmpty(
                array_filter($middleware, fn (string $entry) => $entry === 'optional.sanctum' || $entry === 'auth:sanctum' || $entry === 'auth'),
                "{$name} 에 인증 미들웨어가 없다 (코어가 판정하지 않는 라우트는 목록에 둘 필요가 없다)",
            );
        }

        $merged = (new ExemptRequiredFieldsRoutesListener)->exemptRoutes(['api.other.route'], request());
        $this->assertSame(['api.other.route', ...ExemptRequiredFieldsRoutesListener::ROUTES], $merged);
        $this->assertSame(ExemptRequiredFieldsRoutesListener::ROUTES, (new ExemptRequiredFieldsRoutesListener)->exemptRoutes(null));
    }

    /**
     * 구독자가 없으면 미입력 회원의 요청은 추가 정보 요구로 막힌다.
     *
     * @scenario case=without_subscriber
     *
     * @effects pending_member_blocked_without_subscriber
     *
     * @return void 반환값 없음
     */
    public function test_pending_member_is_blocked_without_subscriber(): void
    {
        HookManager::clearFilter(self::FILTER);
        [$pending, $headers] = $this->member(false);
        $this->assertSame([self::FIELD_KEY], app(RequiredFieldsChecker::class)->pendingKeys($pending));

        foreach ($this->exemptRequests() as [$method, $uri]) {
            $this->send($method, $uri, $headers)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'required_fields_pending');
        }
    }

    /**
     * 구독자가 있으면 미입력 회원도 필수 항목을 채운 회원과 같은 응답을 받는다.
     *
     * @scenario case=with_subscriber
     *
     * @effects pending_member_gets_original_response_with_subscriber
     *
     * @return void 반환값 없음
     */
    public function test_pending_member_gets_original_response_with_subscriber(): void
    {
        HookManager::clearFilter(self::FILTER);
        HookManager::addFilter(self::FILTER, [new ExemptRequiredFieldsRoutesListener, 'exemptRoutes']);

        foreach ($this->exemptRequests() as [$method, $uri]) {
            [, $filledHeaders] = $this->member(true);
            [$pending, $pendingHeaders] = $this->member(false);
            $this->assertSame([self::FIELD_KEY], app(RequiredFieldsChecker::class)->pendingKeys($pending));

            $expected = $this->send($method, $uri, $filledHeaders);
            $this->assertNotSame('required_fields_pending', $expected->json('error_code'));

            $response = $this->send($method, $uri, $pendingHeaders);
            $this->assertNotSame('required_fields_pending', $response->json('error_code'), "{$method} {$uri} 요청이 추가 정보 요구로 막혔다");
            $this->assertSame($expected->getStatusCode(), $response->getStatusCode(), "{$method} {$uri} 의 응답이 필수 항목을 채운 회원과 다르다");
        }
    }
}
