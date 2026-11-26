<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\EnsureActiveUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 공통 회원 상태 관문 `EnsureActiveUser`
 *
 * api 그룹 전체에 붙어 인증 미들웨어 뒤에서 돈다 — 확장(모듈·플러그인) 라우트가 회원 상태 검사를 따로 붙이지 않아도 비활성 회원을 막는다.
 * 인증 필수 라우트는 403, 선택 인증 라우트는 손님으로 낮춰 200, `check.user_status` 가 있는 라우트는 그 판정 1회, 본인인증 경로는 통과.
 */
class EnsureActiveUserTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /** `check.user_status` 호출 횟수 */
    public static int $checkCalls = 0;

    /**
     * 시험 라우트를 api 그룹으로 등록합니다 (확장 라우트와 같은 조건).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])->post('/api/modules/test-k14/write', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'optional.sanctum'])->get('/api/modules/test-k14/list', fn () => response()->json(['user' => request()->user()?->id]));
        Route::middleware(['api', 'auth:sanctum', 'check.user_status'])->post('/api/modules/test-k14/checked', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'optional.sanctum', 'allow.inactive_user'])->post('/api/modules/test-k14/identity', fn () => response()->json(['user' => request()->user()?->id]));
        Route::middleware(['api'])->get('/api/modules/test-k14/public', fn () => response()->json(['ok' => true]));
        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * 상태별 회원과 토큰을 만듭니다.
     *
     * @param  string  $status  회원 상태
     * @return array{0: User, 1: string} 회원과 토큰
     */
    private function memberWithToken(string $status): array
    {
        $user = $this->makeMember(['email' => 'k14.'.Str::lower(Str::random(8)).'@example.test', 'status' => $status]);

        return [$user, $this->issueToken($user)];
    }

    /**
     * 인증 필수 확장 라우트는 가입 후 인증 대기 회원을 403 으로 막고 활성 회원은 통과시킨다.
     *
     * @scenario route=auth_required, status=pending_verification|active
     *
     * @effects required_route_blocks_inactive, active_passes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 인증_필수_라우트는_비활성을_막는다(): void
    {
        [, $pending] = $this->memberWithToken('pending_verification');
        $this->withToken($pending)->postJson('/api/modules/test-k14/write')
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.account_pending_verification'));

        $this->app['auth']->forgetGuards();
        [, $active] = $this->memberWithToken('active');
        $this->withToken($active)->postJson('/api/modules/test-k14/write')->assertOk();
    }

    /**
     * 선택 인증 라우트는 휴면 회원을 손님으로 낮춰 200 이다.
     *
     * @scenario route=optional, status=dormant
     *
     * @effects optional_route_downgrades_to_guest
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 선택_인증_라우트는_손님으로_낮춘다(): void
    {
        [, $token] = $this->memberWithToken('dormant');

        $this->withToken($token)->getJson('/api/modules/test-k14/list')
            ->assertOk()
            ->assertJsonPath('user', null);
    }

    /**
     * `check.user_status` 가 있는 라우트는 그 미들웨어가 한 번만 판정한다.
     *
     * @scenario route=check_user_status
     *
     * @effects check_user_status_judges_once
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 기존_상태_검사는_한_번만(): void
    {
        self::$checkCalls = 0;
        $this->app->bind(CheckUserStatus::class, fn () => new class extends CheckUserStatus
        {
            /** {@inheritDoc} */
            public function handle(\Illuminate\Http\Request $request, \Closure $next, string ...$readPermissions): \Symfony\Component\HttpFoundation\Response
            {
                EnsureActiveUserTest::$checkCalls++;

                return parent::handle($request, $next, ...$readPermissions);
            }
        });

        [, $token] = $this->memberWithToken('blocked');
        $this->withToken($token)->postJson('/api/modules/test-k14/checked')
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.account_blocked'));

        $this->assertSame(1, self::$checkCalls);
    }

    /**
     * 표식이 있는 본인인증 경로는 대기 회원을 통과시키고, 실제 본인인증 라우트에 표식이 붙어 있다.
     *
     * @scenario route=identity
     *
     * @effects identity_routes_allow_inactive
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 본인인증_경로는_통과한다(): void
    {
        [$user, $token] = $this->memberWithToken('pending_verification');

        $this->withToken($token)->postJson('/api/modules/test-k14/identity')
            ->assertOk()
            ->assertJsonPath('user', $user->id);

        foreach (['api.identity.challenges.request', 'api.identity.challenges.verify', 'api.identity.challenges.show'] as $name) {
            $this->assertContains(EnsureActiveUser::ALLOW_INACTIVE_ALIAS, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    /**
     * 손님과 인증 미들웨어가 없는 라우트는 판정하지 않는다.
     *
     * @scenario user=guest, route=public
     *
     * @effects guest_passes, unauthenticated_route_untouched
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 손님과_공개_라우트는_그대로다(): void
    {
        $this->getJson('/api/modules/test-k14/list')->assertOk()->assertJsonPath('user', null);
        $this->postJson('/api/modules/test-k14/write')->assertUnauthorized();

        [, $token] = $this->memberWithToken('blocked');
        $this->withToken($token)->getJson('/api/modules/test-k14/public')->assertOk();
    }
}
