<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use App\Extension\ModuleManager;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 혜택 훅이 모듈의 공개 훅 계약(`getHooks()`)에 선언돼 있는지 잠근다.
 *
 * 모집단은 손으로 적지 않는다 — 1.4.1 에서 들어온 회원 알림 정의가 구독하는 훅을 정의에서 꺼내고,
 * 쿠폰마다 따로 있는 발급 알림 훅은 자리표시자 이름(`{쿠폰ID}`)으로 대조한다.
 */
class MemberBenefitHookDeclarationTest extends ModuleTestCase
{
    /**
     * 1.4.1 회원 알림 정의 — 이 정의들이 구독하는 훅이 모집단이다.
     *
     * @var array<int, string>
     */
    private const MEMBER_BENEFIT_TYPES = [
        'coupon_expiring_soon',
        'mileage_earned',
        'mileage_deducted',
        'order_partially_cancelled',
        'review_replied',
    ];

    /**
     * 회원 알림 정의가 구독하는 훅과 쿠폰별 발급 알림 훅이 모두 getHooks() 에 있고, 설명이 ko·en 둘 다 있다.
     *
     * @scenario case=member_benefit_hooks_declared
     *
     * @effects hooks_declared
     *
     * @return void 반환값 없음
     */
    public function test_member_benefit_hooks_are_declared_with_descriptions(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module);

        $subscribed = [];
        foreach ($module->getNotificationDefinitions() as $definition) {
            if (in_array($definition['type'] ?? null, self::MEMBER_BENEFIT_TYPES, true)) {
                array_push($subscribed, ...($definition['hooks'] ?? []));
            }
        }
        $subscribed[] = 'sirsoft-ecommerce.coupon.notify_issued.{쿠폰ID}';
        $subscribed = array_values(array_unique($subscribed));

        // 모집단이 비면 단언이 조용히 통과하므로 먼저 잰다 — 정의 5종이 각자 훅을 하나 이상 구독한다
        $this->assertGreaterThanOrEqual(count(self::MEMBER_BENEFIT_TYPES), count($subscribed) - 1);

        $declared = [];
        foreach ($module->getHooks() as $hook) {
            $declared[$hook['name']] = $hook;
        }

        foreach ($subscribed as $name) {
            $this->assertArrayHasKey($name, $declared, "{$name} 가 getHooks() 에 선언돼 있지 않다");
            $this->assertNotEmpty($declared[$name]['description']['ko'] ?? null, "{$name} ko 설명 없음");
            $this->assertNotEmpty($declared[$name]['description']['en'] ?? null, "{$name} en 설명 없음");
        }
    }

    /**
     * 부분 취소·전체 취소 훅 계약에 1.4.1 에서 추가된 4번째 인자(취소 기록)가 적혀 있다.
     *
     * @scenario case=member_benefit_hooks_declared
     *
     * @effects hooks_declared
     *
     * @return void 반환값 없음
     */
    public function test_order_cancel_hooks_declare_fourth_argument(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $declared = collect($module->getHooks())->keyBy('name');

        foreach (['sirsoft-ecommerce.order.after_partial_cancel', 'sirsoft-ecommerce.order.after_cancel'] as $name) {
            $parameters = $declared->get($name)['parameters'] ?? [];
            $this->assertSame(['order', 'cancel', 'snapshot', 'orderCancel'], array_keys($parameters), $name);
        }
    }
}
