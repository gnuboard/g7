<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Controllers\Api\Admin\ActivityLogController;
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
 */
class MemberEmailExposureGateTest extends TestCase
{
    /**
     * 게이트를 밖에서 호출할 수 있게 감싼 시험용 리소스.
     */
    private function gate(): object
    {
        return new class(null) extends BaseApiResource
        {
            public function expose(Request $request, ?int $ownerId = null): bool
            {
                return $this->canExposeMemberEmail($request, $ownerId);
            }
        };
    }

    /**
     * 지정한 컨트롤러로 라우팅된 요청을 만듭니다.
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

    public function test_admin_controller_request_may_expose_email(): void
    {
        $this->assertTrue(
            $this->gate()->expose($this->requestFor(ActivityLogController::class)),
            '관리자 컨트롤러 응답에서 이메일이 막혔다'
        );
    }

    public function test_public_controller_request_may_not_expose_email(): void
    {
        $this->assertFalse(
            $this->gate()->expose($this->requestFor(LocaleController::class)),
            '공개 컨트롤러 응답에 이메일이 허용됐다'
        );
    }

    public function test_request_without_route_may_not_expose_email(): void
    {
        $this->assertFalse(
            $this->gate()->expose($this->requestFor(null)),
            '라우트를 특정할 수 없는 요청에서 이메일이 허용됐다 — 판정 불가는 차단이어야 한다'
        );
    }

    public function test_owner_may_see_own_email_on_public_route(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->assertTrue(
            $this->gate()->expose($this->requestFor(LocaleController::class), $user->id),
            '공개 경로에서 본인 이메일이 가려졌다'
        );
    }

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
}
