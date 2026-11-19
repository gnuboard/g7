<?php

namespace Tests\Feature\Analytics;

use App\Analytics\TrackingRequestFactory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 방문 수집 요청의 관리자 표지
 *
 * 「관리자 제외」 토글은 수집 요청의 관리자 표지로 관리자 방문을 뺀다. 최고관리자는 코어 역할이 아니라
 * `users.is_super` 표지로 판정한다 — 코어에 없는 역할 이름으로 물으면 관리자 역할이 없는 최고관리자가 일반 회원으로 셈된다.
 */
class TrackingRequestAdminFlagTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 주어진 회원으로 인증된 수집 요청을 만듭니다.
     *
     * @param  User|null  $user  인증된 회원 (null 이면 게스트)
     * @return bool 수집 요청의 관리자 표지
     */
    private function adminFlagFor(?User $user): bool
    {
        $request = Request::create('/shop/products', 'GET');
        $request->setUserResolver(fn () => $user);

        return TrackingRequestFactory::fromHttp($request, [], 'server')->isAdmin;
    }

    /**
     * 관리자 역할이 없는 최고관리자도 관리자 방문으로 센다.
     *
     * @scenario role=super_flag_only
     *
     * @effects super_admin_counted_as_admin_traffic
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_역할이_없는_최고관리자도_관리자_방문이다(): void
    {
        $super = $this->makeMember(['is_super' => true]);

        $this->assertTrue($this->adminFlagFor($super));
    }

    /**
     * 관리자 역할 회원은 관리자 방문이다 (회귀 대조).
     *
     * @scenario role=admin
     *
     * @effects admin_role_counted_as_admin_traffic
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_역할_회원은_관리자_방문이다(): void
    {
        $this->assertTrue($this->adminFlagFor($this->makeAdmin()));
    }

    /**
     * 일반 회원은 관리자 방문이 아니다 (회귀 대조).
     *
     * @scenario role=member
     *
     * @effects member_and_guest_not_admin_traffic
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 일반_회원은_관리자_방문이_아니다(): void
    {
        $this->assertFalse($this->adminFlagFor($this->makeMember()));
    }

    /**
     * 게스트는 관리자 방문이 아니다 (회귀 대조).
     *
     * @scenario role=guest
     *
     * @effects member_and_guest_not_admin_traffic
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 게스트는_관리자_방문이_아니다(): void
    {
        $this->assertFalse($this->adminFlagFor(null));
    }
}
