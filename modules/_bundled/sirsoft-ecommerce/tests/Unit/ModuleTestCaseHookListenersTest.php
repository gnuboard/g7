<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 시험 기반 클래스가 모듈 훅 리스너를 붙이는지 검증
 *
 * 앱 부팅은 DB 에서 「활성」인 모듈의 리스너만 등록한다. 부팅 시점에 이 모듈의 등록 행이 없던 DB 로
 * 시작하면(빈 DB 단독 실행) 리스너가 하나도 붙지 않아, 주문취소 마일리지 환원처럼 훅으로 도는 부수효과를
 * 단언하는 시험이 앞선 시험에 따라 통과하기도 하고 실패하기도 했다. 기반 클래스가 직접 붙이는지 잠근다.
 */
class ModuleTestCaseHookListenersTest extends ModuleTestCase
{
    /**
     * 모듈이 선언한 리스너의 구독 훅마다 HookManager 에 콜백이 하나 이상 붙어 있는지 확인한다.
     *
     * @scenario boot_state=module_row_absent_at_boot
     *
     * @effects module_hook_listeners_attached_by_test_base
     *
     * @return void 반환값 없음
     */
    public function test_every_subscribed_hook_of_module_listeners_has_a_callback(): void
    {
        $ref = new \ReflectionClass(HookManager::class);
        $hooks = $ref->getProperty('hooks');
        $filters = $ref->getProperty('filters');
        $registered = array_merge(array_keys($hooks->getValue()), array_keys($filters->getValue()));

        $missing = [];
        foreach ((new Module)->getHookListeners() as $listenerClass) {
            foreach (array_keys($listenerClass::getSubscribedHooks()) as $hookName) {
                if (! in_array($hookName, $registered, true)) {
                    $missing[] = class_basename($listenerClass).' → '.$hookName;
                }
            }
        }

        $this->assertSame([], $missing, '시험 기반 클래스가 붙이지 않은 모듈 훅 리스너가 있습니다');
    }

    /**
     * 부팅이 이미 등록한 리스너를 기반 클래스가 다시 붙여 같은 훅이 두 번 발화하지 않는지 확인한다.
     *
     * @scenario boot_state=module_row_present_at_boot
     *
     * @effects module_hook_listener_attached_once
     *
     * @return void 반환값 없음
     */
    public function test_mileage_restore_listener_is_attached_once(): void
    {
        $hookName = 'sirsoft-ecommerce.mileage.restore';
        $ref = new \ReflectionClass(HookManager::class);
        $hooks = $ref->getProperty('hooks');

        $attached = 0;
        foreach ($hooks->getValue()[$hookName] ?? [] as $callbacks) {
            $attached += count($callbacks);
        }

        // 이 훅을 구독하는 모듈 리스너 수(마일리지 원장·주문 활동 로그) — 리스너마다 한 번씩만 붙어야 한다
        $subscribers = count(array_filter(
            (new Module)->getHookListeners(),
            fn (string $listenerClass) => array_key_exists($hookName, $listenerClass::getSubscribedHooks()),
        ));

        $this->assertGreaterThan(0, $subscribers);
        $this->assertSame($subscribers, $attached);
    }
}
