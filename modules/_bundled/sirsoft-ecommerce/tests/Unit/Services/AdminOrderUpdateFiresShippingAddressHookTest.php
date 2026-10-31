<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionClass;

/**
 * 관리자 주문 수정 경로의 배송지 변경 로그 (S2-D6 · #599 통합실측 세션2)
 *
 * `OrderActivityLogListener` 는 `order.after_update_shipping_address` 를 구독해
 * **「배송지 변경 (:order_number)」** 줄과 전/후값을 남긴다. 회원·비회원 경로
 * (`OrderService::updateShippingAddress()`)는 그 훅을 발화한다.
 *
 * 그러나 **관리자 주문상세의 배송지 저장은 `OrderService::update()` 를 탄다.**
 * 이 메서드는 수취인 필드를 분리해 `$shippingAddress->update()` 로 직접 쓰고,
 * 끝에 `order.after_update` 만 발화한다 — 그런데 거기 실리는 스냅샷은
 * `$order->toArray()` 라서 **주소 컬럼이 처음부터 들어 있지 않다.**
 * `ChangeDetector` 가 볼 것이 없으니 배송지 변경이 로그에서 통째로 사라진다.
 *
 * 실측(주문 517 · 2026-09-15 · 관리자 주문상세에서 수취인명·상세주소 저장):
 *   `ecommerce_order_addresses` 는 정상 반영 (`MEAS599-S2-R2` / `MEAS599-S2 11층`)
 *   그런데 `activity_logs` 최신 행은 `order.update` 하나뿐이고
 *   그 `changes` 는 `[{"field":"paid_at","old":"…","new":"…"}]` — **old == new 인 무의미한 한 건**
 *   `order.update_shipping_address` 행 **0건**
 *
 * 즉 관리자가 남의 주문 배송지를 바꿔도 **누가 무엇을 무엇으로 바꿨는지 추적할 수 없다.**
 *
 * 이 테스트는 서비스가 훅을 **발화하는지** 만 본다. 훅을 받은 뒤의 로그 모양은
 * `Unit/Listeners/OrderActivityLogListenerTest` 가 이미 잠그고 있으므로 중복하지 않는다.
 */
class AdminOrderUpdateFiresShippingAddressHookTest extends ModuleTestCase
{
    /**
     * `OrderService::update()` 의 본문을 소스에서 읽어 온다.
     *
     * 이 결함은 **훅 배선 누락**이라 DB 왕복 없이 배선 자체를 확인하는 것이
     * 가장 곧다(로컬 MySQL 의 migrate:fresh DDL 부담도 피한다).
     */
    private function updateMethodBody(): string
    {
        $reflection = new ReflectionClass(OrderService::class);
        $method = $reflection->getMethod('update');

        $source = (string) file_get_contents((string) $reflection->getFileName());
        $lines = explode("\n", $source);

        return implode("\n", array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    /**
     * 전제 확인 — 관리자 수정 경로가 배송지를 직접 쓴다.
     *
     * 이 전제가 깨지면(배송지 쓰기가 `updateShippingAddress()` 로 옮겨졌다면)
     * 아래 테스트의 성격도 달라지므로 함께 잠근다.
     *
     * @scenario admin_order_update=writes_address_inline
     *
     * @effects admin_update_writes_shipping_address_directly
     */
    public function test_admin_update_writes_the_shipping_address_directly(): void
    {
        $body = $this->updateMethodBody();

        $this->assertStringContainsString(
            '$shippingAddress->update($addressData)',
            $body,
            'OrderService::update() 가 더 이상 배송지를 직접 쓰지 않습니다 — 이 테스트의 전제가 깨졌습니다.'
        );
    }

    /**
     * 배송지를 직접 쓴 경로도 전용 훅을 발화해야 한다.
     *
     * 발화하지 않으면 활동 로그에 **「배송지 변경」 줄이 아예 생기지 않는다.**
     * `after_update` 로는 대체되지 않는다 — 그쪽 스냅샷은 Order 것이라
     * 주소 필드를 담고 있지 않기 때문이다.
     *
     * @scenario admin_order_update=fires_address_hook
     *
     * @effects admin_shipping_address_change_is_logged
     */
    public function test_admin_update_fires_the_shipping_address_hook(): void
    {
        $body = $this->updateMethodBody();

        $this->assertStringContainsString(
            'sirsoft-ecommerce.order.after_update_shipping_address',
            $body,
            '관리자 주문 수정이 배송지를 쓰고도 order.after_update_shipping_address 를 발화하지 않습니다. '
                .'그 결과 활동 로그에 「배송지 변경」 줄이 남지 않아 누가 무엇을 바꿨는지 추적할 수 없습니다. '
                .'실측: 주문 517 · 주소는 반영됐으나 activity_logs 에 order.update_shipping_address 0건.'
        );
    }

    /**
     * 훅에 넘기는 스냅샷은 **주소 스냅샷**이어야 한다.
     *
     * 리스너는 `ChangeDetector::detect($address, $snapshot)` 로 전/후를 만든다.
     * 여기에 Order 스냅샷(`$snapshot`)을 넘기면 전/후가 통째로 어긋나므로,
     * 주소 전용 스냅샷을 따로 떠서 넘기는 것까지 잠근다.
     *
     * @scenario admin_order_update=passes_address_snapshot
     *
     * @effects admin_shipping_address_change_carries_before_values
     */
    public function test_admin_update_passes_an_address_snapshot_to_the_hook(): void
    {
        $body = $this->updateMethodBody();

        $this->assertMatchesRegularExpression(
            '/addressSnapshot\s*=.*?\$shippingAddress->toArray\(\)/s',
            $body,
            '배송지 변경 전 스냅샷을 뜨지 않습니다 — 훅은 발화해도 전/후값이 비어 '
                .'「무엇이 무엇으로 바뀌었는지」가 로그에 남지 않습니다.'
        );

        $this->assertMatchesRegularExpression(
            '/after_update_shipping_address.*?\$addressSnapshot/s',
            $body,
            '전용 훅에 주소 스냅샷 대신 다른 값을 넘기고 있습니다. '
                .'ChangeDetector 가 OrderAddress 와 대조할 수 있는 것은 주소 스냅샷뿐입니다.'
        );
    }
}
