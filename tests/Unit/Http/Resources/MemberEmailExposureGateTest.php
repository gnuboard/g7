<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Controllers\Api\Admin\ActivityLogController;
use App\Http\Controllers\Api\Auth\ProfileController;
use App\Http\Controllers\Api\Public\LocaleController;
use App\Http\Resources\BaseApiResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Tests\TestCase;

/**
 * 회원 이메일 노출 게이트 (`BaseApiResource::canExposeMemberEmail`) 단위 테스트
 *
 * 판정 근거는 "요청이 도달한 컨트롤러가 관리자 컨트롤러인가" 와 "본인인가" 둘뿐이며,
 * 클라이언트가 보내는 값(헤더·쿼리)은 판정에 관여하지 않는다. 이 판정기 자신이
 * 여러 리소스가 공유하는 SSoT 이므로 여기서 고정한다.
 *
 * 관리자 내부 메모 게이트(`canExposeAdminNote`)도 같은 판정 근거를 쓰되 본인 예외가 없다 —
 * 두 게이트의 차이를 이 파일에서 함께 고정한다.
 */
class MemberEmailExposureGateTest extends TestCase
{
    /**
     * 게이트를 밖에서 호출할 수 있게 감싼 시험용 리소스를 만듭니다.
     *
     * @return object 두 게이트를 공개 메서드로 노출하는 익명 리소스
     */
    private function gate(): object
    {
        return new class(null) extends BaseApiResource
        {
            /**
             * 이메일 게이트 판정을 돌려줍니다.
             *
             * @param  Request  $request  판정할 요청
             * @param  int|null  $ownerId  이메일 주인 사용자 ID
             * @return bool 이메일 노출 허용 여부
             */
            public function expose(Request $request, ?int $ownerId = null): bool
            {
                return $this->canExposeMemberEmail($request, $ownerId);
            }

            /**
             * 관리자 내부 메모 게이트 판정을 돌려줍니다.
             *
             * @param  Request  $request  판정할 요청
             * @return bool 관리자 메모 노출 허용 여부
             */
            public function exposeAdminNote(Request $request): bool
            {
                return $this->canExposeAdminNote($request);
            }
        };
    }

    /**
     * 지정한 컨트롤러로 라우팅된 요청을 만듭니다.
     *
     * @param  string|null  $controller  컨트롤러 FQCN (null 이면 라우트 없는 요청)
     * @return Request 라우트가 연결된 요청
     */
    private function requestFor(?string $controller): Request
    {
        $request = Request::create('/api/probe', 'GET');

        if ($controller === null) {
            return $request;
        }

        // Route::getController() 는 action['uses'] 가 문자열일 때만 컨트롤러를 해석한다.
        $route = new RoutingRoute(['GET'], '/api/probe', ['uses' => $controller.'@index']);
        $route->setContainer($this->app);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    /**
     * 관리자 컨트롤러에 도달한 요청은 회원 이메일을 실을 수 있다.
     *
     * @scenario case=email_admin
     *
     * @effects email_gate_allows_admin_controller
     *
     * @return void 반환값 없음
     */
    public function test_admin_controller_request_may_expose_email(): void
    {
        $this->assertTrue(
            $this->gate()->expose($this->requestFor(ActivityLogController::class)),
            '관리자 컨트롤러 응답에서 이메일이 막혔다'
        );
    }

    /**
     * 공개 컨트롤러에 도달한 요청은 회원 이메일을 실을 수 없다.
     *
     * @scenario case=email_public
     *
     * @effects email_gate_blocks_non_admin_controller
     *
     * @return void 반환값 없음
     */
    public function test_public_controller_request_may_not_expose_email(): void
    {
        $this->assertFalse(
            $this->gate()->expose($this->requestFor(LocaleController::class)),
            '공개 컨트롤러 응답에 이메일이 허용됐다'
        );
    }

    /**
     * 라우트를 특정할 수 없는 요청은 차단으로 판정한다.
     *
     * @scenario case=email_no_route
     *
     * @effects gate_blocks_when_route_unknown
     *
     * @return void 반환값 없음
     */
    public function test_request_without_route_may_not_expose_email(): void
    {
        $this->assertFalse(
            $this->gate()->expose($this->requestFor(null)),
            '라우트를 특정할 수 없는 요청에서 이메일이 허용됐다 — 판정 불가는 차단이어야 한다'
        );
    }

    /**
     * 본인은 공개 경로에서도 자기 이메일을 본다.
     *
     * @scenario case=email_owner
     *
     * @effects email_gate_allows_owner
     *
     * @return void 반환값 없음
     */
    public function test_owner_may_see_own_email_on_public_route(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->assertTrue(
            $this->gate()->expose($this->requestFor(LocaleController::class), $user->id),
            '공개 경로에서 본인 이메일이 가려졌다'
        );
    }

    /**
     * 다른 회원은 공개 경로에서 남의 이메일을 볼 수 없다.
     *
     * @scenario case=email_other
     *
     * @effects email_gate_blocks_non_admin_controller
     *
     * @return void 반환값 없음
     */
    public function test_other_user_may_not_see_someone_elses_email(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create();
        $this->actingAs($viewer, 'sanctum');

        $this->assertFalse(
            $this->gate()->expose($this->requestFor(LocaleController::class), $owner->id),
            '타인의 이메일이 공개 경로에서 허용됐다'
        );
    }

    /**
     * 관리자 컨트롤러에 도달한 요청은 관리자 내부 메모를 실을 수 있다(양성 대조).
     *
     * @scenario case=note_admin
     *
     * @effects admin_note_gate_allows_admin_controller
     *
     * @return void 반환값 없음
     */
    public function test_admin_controller_request_may_expose_admin_note(): void
    {
        $this->assertTrue(
            $this->gate()->exposeAdminNote($this->requestFor(ActivityLogController::class)),
            '관리자 컨트롤러 응답에서 관리자 메모가 막혔다 — 관리자 화면이 메모를 잃는다'
        );
    }

    /**
     * 공개 컨트롤러에 도달한 요청은 관리자 내부 메모를 실을 수 없다.
     *
     * @scenario case=note_public
     *
     * @effects admin_note_gate_blocks_non_admin_controller
     *
     * @return void 반환값 없음
     */
    public function test_public_controller_request_may_not_expose_admin_note(): void
    {
        $this->assertFalse(
            $this->gate()->exposeAdminNote($this->requestFor(LocaleController::class)),
            '공개 컨트롤러 응답에 관리자 메모가 허용됐다'
        );
    }

    /**
     * 회원 전용(인증) 컨트롤러에 도달한 요청도 관리자 내부 메모를 실을 수 없다.
     *
     * @scenario case=note_authenticated
     *
     * @effects admin_note_gate_blocks_non_admin_controller
     *
     * @return void 반환값 없음
     */
    public function test_authenticated_user_controller_request_may_not_expose_admin_note(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->assertFalse(
            $this->gate()->exposeAdminNote($this->requestFor(ProfileController::class)),
            '회원 전용(인증) 컨트롤러 응답에 관리자 메모가 허용됐다'
        );
    }

    /**
     * 라우트를 특정할 수 없는 요청은 관리자 내부 메모도 차단으로 판정한다.
     *
     * @scenario case=note_no_route
     *
     * @effects gate_blocks_when_route_unknown
     *
     * @return void 반환값 없음
     */
    public function test_request_without_route_may_not_expose_admin_note(): void
    {
        $this->assertFalse(
            $this->gate()->exposeAdminNote($this->requestFor(null)),
            '라우트를 특정할 수 없는 요청에서 관리자 메모가 허용됐다 — 판정 불가는 차단이어야 한다'
        );
    }

    /**
     * 본인이어도 관리자 내부 메모는 보이지 않는다 — 이메일 게이트와 다른 점을 대조로 고정한다.
     *
     * @scenario case=note_owner
     *
     * @effects admin_note_gate_has_no_owner_exception, email_gate_allows_owner
     *
     * @return void 반환값 없음
     */
    public function test_owner_may_not_see_admin_note_even_though_owner_may_see_own_email(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        $request = $this->requestFor(ProfileController::class);

        $this->assertTrue(
            $this->gate()->expose($request, $user->id),
            '대조군: 본인 이메일은 회원 경로에서 보여야 한다'
        );
        $this->assertFalse(
            $this->gate()->exposeAdminNote($request),
            '본인 요청에 관리자 내부 메모가 허용됐다 — 관리자 메모 게이트에는 본인 예외가 없어야 한다'
        );
    }
}
