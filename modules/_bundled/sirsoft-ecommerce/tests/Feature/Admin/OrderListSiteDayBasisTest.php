<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 대시보드 「오늘」 배지로 연 주문 목록의 하루 기준 (7.1.0 전수 실측 · 결정 2026-10-08)
 *
 * 배지 숫자는 사이트 표준시의 하루(`EcommerceDashboardService::siteToday()`)로 센다. 배지를 눌러 여는 주문 목록이
 * 목록 원칙대로 보는 사람의 하루로 거르면, 관리자 계정 시간대가 사이트와 다를 때 배지 「결제완료 1건」 → 목록 0건이
 * 된다. 결정: 배지로 연 목록만 배지와 같은 사이트 하루로 거른다(`date_basis=site`). 인자가 없으면 종전대로
 * 보는 사람의 하루다.
 *
 * 공통 데이터: 보는 사람(관리자 계정) 시간대 UTC, 사이트 표준시 Asia/Seoul.
 * - 안쪽 주문: UTC 2026-10-07 15:30 = 한국 2026-10-08 00:30 (사이트 10-08)
 * - 바깥 주문: UTC 2026-10-07 14:30 = 한국 2026-10-07 23:30 (사이트 10-07)
 * 둘 다 보는 사람(UTC)에게는 10-07 이다.
 *
 * 시나리오 SSoT: tests/scenarios/admin-order-list-site-day-basis.yaml
 */
class OrderListSiteDayBasisTest extends ModuleTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-ecommerce/admin/orders';

    private User $admin;

    private Order $inside;

    private Order $outside;

    /**
     * 계정 시간대 UTC 관리자와 사이트 하루 경계 양쪽의 주문 두 건을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.default_user_timezone' => 'Asia/Seoul']);

        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();

        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $this->admin->forceFill(['timezone' => 'UTC'])->save();

        $this->inside = OrderFactory::new()->create(['ordered_at' => '2026-10-07 15:30:00']);
        $this->outside = OrderFactory::new()->create(['ordered_at' => '2026-10-07 14:30:00']);
    }

    /**
     * date_basis=site 이면 시작·종료일을 사이트 표준시의 하루로 풀어 거른다 — 배지가 센 주문이 목록에 그대로 나온다.
     *
     * @scenario entry=dashboard_badge, basis=site
     *
     * @effects order_list_site_basis_filters_by_site_day
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function site_basis_filters_by_site_day_for_viewer_in_other_timezone(): void
    {
        $response = $this->actingAs($this->admin)->getJson(self::ENDPOINT.'?'.http_build_query([
            'date_type' => 'ordered_at',
            'start_date' => '2026-10-08',
            'end_date' => '2026-10-08',
            'date_basis' => 'site',
        ]));

        $response->assertOk();
        $this->assertSame([$this->inside->id], $this->ids($response->json('data.data')));
    }

    /**
     * 인자가 없으면 종전대로 보는 사람(UTC)의 하루로 거른다 — 목록 날짜 필터 원칙은 그대로다.
     *
     * @scenario entry=list_date_filter, basis=viewer
     *
     * @effects order_list_default_basis_stays_viewer_day
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function without_basis_list_keeps_viewer_day(): void
    {
        $siteDay = $this->actingAs($this->admin)->getJson(self::ENDPOINT.'?'.http_build_query([
            'date_type' => 'ordered_at',
            'start_date' => '2026-10-08',
            'end_date' => '2026-10-08',
        ]));
        $siteDay->assertOk();
        $this->assertSame([], $this->ids($siteDay->json('data.data')), '보는 사람(UTC) 10-08 에는 두 주문 모두 없다');

        $viewerDay = $this->actingAs($this->admin)->getJson(self::ENDPOINT.'?'.http_build_query([
            'date_type' => 'ordered_at',
            'start_date' => '2026-10-07',
            'end_date' => '2026-10-07',
        ]));
        $viewerDay->assertOk();
        $this->assertEqualsCanonicalizing(
            [$this->inside->id, $this->outside->id],
            $this->ids($viewerDay->json('data.data')),
            '보는 사람(UTC) 10-07 에는 두 주문이 모두 있다'
        );
    }

    /**
     * 정해진 값(site) 밖의 기준은 422 로 거절한다 — 조용히 무시하면 다른 기준으로 거른 목록을 그 기준인 줄 알고 본다.
     *
     * @scenario entry=dashboard_badge, basis=invalid
     *
     * @effects order_list_rejects_unknown_date_basis
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unknown_basis_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->getJson(self::ENDPOINT.'?'.http_build_query([
            'date_type' => 'ordered_at',
            'start_date' => '2026-10-08',
            'end_date' => '2026-10-08',
            'date_basis' => 'utc',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['date_basis']);
    }

    /**
     * 응답 목록 행의 주문 ID 를 뽑습니다.
     *
     * @param  array|null  $rows  응답 data.data
     * @return array<int, int> 주문 ID 목록
     */
    private function ids(?array $rows): array
    {
        return array_values(array_map(fn (array $row) => (int) $row['id'], $rows ?? []));
    }
}
