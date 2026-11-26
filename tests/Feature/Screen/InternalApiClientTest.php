<?php

namespace Tests\Feature\Screen;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Screen\InternalApiClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\LoadsCustomRoutes;
use Tests\TestCase;

/**
 * 내부 API 도구(InternalApiClient) 테스트
 *
 * 개발자 화면이 같은 프로세스에서 API 를 비회원·로그인 회원 자격으로 부르고, 상태 코드를 그대로 돌려받으며,
 * 세션을 여는 인증 API·재호출·비 API 경로는 거부하고, 끝나면 가드·로케일·`request()` 가 원래대로인지 잠근다.
 */
class InternalApiClientTest extends TestCase
{
    use DatabaseTransactions;
    use LoadsCustomRoutes;

    private const PASSWORD = 'Screen!pass792';

    /**
     * 시험용 custom 라우트와 파일 세션을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'file']);
        $this->freshRequestState();
        $this->withCredentials();

        $this->loadCustomRoutesSource(<<<'PHP'
<?php
use App\Services\Screen\InternalApiClient;
use Illuminate\Support\Facades\Route;
Route::get('/api-probe', function (InternalApiClient $api) {
    $path = request()->query('path', '/api/auth/user');
    $before = ['guard' => auth()->getDefaultDriver(), 'locale' => app()->getLocale(), 'request' => spl_object_id(request()), 'sanctum' => config('sanctum.guard')];
    $res = $api->get($path);
    $after = ['guard' => auth()->getDefaultDriver(), 'locale' => app()->getLocale(), 'request' => spl_object_id(request()), 'sanctum' => config('sanctum.guard')];
    return response()->json(['status' => $res->status(), 'id' => $res->json('data.id'), 'body' => $res->json(), 'restored' => $before === $after, 'route' => request()->route()?->uri()]);
});
Route::get('/api-password', function (InternalApiClient $api) {
    $res = $api->put('/api/me/password', request()->only(['current_password', 'password', 'password_confirmation']));
    return response()->json(['status' => $res->status(), 'body' => $res->json()]);
});
PHP);
    }

    /**
     * 비회원은 공개 API 200, 인증 API 401 을 그대로 받고, 호출 뒤 가드·로케일·request()·sanctum.guard 가 원래대로입니다.
     *
     * @scenario case=guest
     *
     * @effects guest_public_ok, guest_auth_401, state_restored
     *
     * @return void 반환값 없음
     */
    public function test_guest_calls_and_state_restored(): void
    {
        $public = $this->getJson('/api-probe?path='.rawurlencode('/api/locales/active'))->assertOk()->json();
        $this->assertSame(200, $public['status']);
        $this->assertTrue($public['restored']);
        $this->assertSame('api-probe', $public['route'], '바깥 요청의 현재 라우트가 되돌아온다');

        $this->freshRequestState();
        $this->assertSame(401, $this->getJson('/api-probe')->assertOk()->json('status'));
    }

    /**
     * 로그인 회원 자격으로 부르고(200·같은 회원), 권한 없는 관리자 API 는 403 을 그대로 받습니다.
     *
     * @scenario case=member
     *
     * @effects member_calls_as_self, forbidden_passes_through
     *
     * @return void 반환값 없음
     */
    public function test_member_calls_as_self_and_403_passes_through(): void
    {
        $user = $this->member();
        $this->login($user);

        $me = $this->getJson('/api-probe')->assertOk()->json();
        $this->assertSame(200, $me['status']);
        $this->assertSame($user->uuid, data_get($me['body'], 'data.uuid') ?? data_get($me['body'], 'data.id'), json_encode($me['body'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue($me['restored']);

        $this->freshRequestState();
        $this->assertSame(403, $this->getJson('/api-probe?path='.rawurlencode('/api/admin/auth/user'))->assertOk()->json('status'));
    }

    /**
     * 짝 토큰이 무효가 되면 비회원으로 불러 401 입니다.
     *
     * @scenario case=pair_invalid
     *
     * @effects invalid_pair_calls_as_guest
     *
     * @return void 반환값 없음
     */
    public function test_invalid_pair_calls_as_guest(): void
    {
        $user = $this->member();
        $this->login($user);
        $user->tokens()->delete();
        $this->freshRequestState();

        $this->assertSame(401, $this->getJson('/api-probe')->assertOk()->json('status'));
    }

    /**
     * 비 API 경로·세션을 여는 인증 API·하위 요청 안 재호출은 거부합니다.
     *
     * @scenario case=rejected
     *
     * @effects non_api_rejected, session_auth_api_rejected, nested_call_rejected
     *
     * @return void 반환값 없음
     */
    public function test_rejected_calls(): void
    {
        $client = app(InternalApiClient::class);

        try {
            $client->get('/board/free');
            $this->fail('비 API 경로는 거부되어야 한다');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('/api/', $e->getMessage());
        }

        try {
            $client->post('/api/auth/login', ['email' => 'x@example.com', 'password' => 'x']);
            $this->fail('로그인 API 는 거부되어야 한다');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ScreenAuth::logout()', $e->getMessage());
        }

        Route::middleware('api')->get('/api/t792/nested', fn (InternalApiClient $api) => $api->get('/api/locales/active')->status());
        app('router')->getRoutes()->refreshNameLookups();
        $nested = $client->get('/api/t792/nested');
        $this->assertSame(500, $nested->status(), '하위 요청 안 재호출은 LogicException(500)');
    }

    /**
     * 비밀번호 변경을 내부로 불러도 그 요청의 짝 토큰은 살아 있어 개발자 화면 로그인이 유지됩니다.
     *
     * @scenario case=password_change
     *
     * @effects password_change_keeps_paired_token
     *
     * @return void 반환값 없음
     */
    public function test_password_change_keeps_paired_token(): void
    {
        $user = $this->member();
        $token = $this->login($user);
        $tokenId = (int) explode('|', $token, 2)[0];

        $res = $this->getJson('/api-password?'.http_build_query([
            'current_password' => self::PASSWORD,
            'password' => 'Changed!pass792x',
            'password_confirmation' => 'Changed!pass792x',
        ]))->assertOk()->json();

        $this->assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue(PersonalAccessToken::query()->whereKey($tokenId)->exists(), '짝 토큰은 살아 있다');
        $this->freshRequestState();
        $this->assertSame(200, $this->getJson('/api-probe')->assertOk()->json('status'));
    }

    /**
     * 점검 모드 우회 쿠키만 하위 요청에 넘기고 다른 쿠키는 넘기지 않습니다.
     *
     * @scenario case=cookies
     *
     * @effects only_maintenance_cookie_forwarded
     *
     * @return void 반환값 없음
     */
    public function test_only_maintenance_cookie_is_forwarded(): void
    {
        Route::middleware('api')->get('/api/t792/cookies', fn () => response()->json(array_keys(request()->cookies->all())));
        app('router')->getRoutes()->refreshNameLookups();

        $outer = Request::create('http://localhost/x', 'GET', [], ['laravel_maintenance' => 'bypass', 'g7-session' => 'secret', 'other' => '1']);
        app()->instance('request', $outer);

        $res = app(InternalApiClient::class)->get('/api/t792/cookies');

        $this->assertSame(['laravel_maintenance'], $res->json());
        $this->assertSame($outer, request());
    }

    /**
     * 다음 요청을 새 요청처럼 — 가드·세션 저장소 메모리를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function freshRequestState(): void
    {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /**
     * 로그인 API 로 로그인하고 세션 쿠키를 싣습니다.
     *
     * @param  User  $user  회원
     * @return string 평문 토큰
     */
    private function login(User $user): string
    {
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $cookie = $response->getCookie(config('session.cookie'));
        $this->withCookie(config('session.cookie'), (string) $cookie?->getValue());
        $this->freshRequestState();

        return (string) $response->json('data.token');
    }

    /**
     * 시험 회원을 만듭니다 (내 정보 조회 권한 포함).
     *
     * @return User 회원
     */
    private function member(): User
    {
        $user = User::factory()->create([
            'email' => 'iapi792_'.uniqid().'@example.com',
            'password' => bcrypt(self::PASSWORD),
        ]);
        $ids = [];
        foreach (['core.profile.read', 'core.profile.update'] as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'type' => 'user',
            ])->id;
        }
        $role = Role::create(['identifier' => 'iapi_test_'.uniqid(), 'name' => json_encode(['ko' => '시험', 'en' => 'Test']), 'is_active' => true]);
        $role->permissions()->sync($ids);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }
}
