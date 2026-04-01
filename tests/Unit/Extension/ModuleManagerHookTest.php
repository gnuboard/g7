<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleManager 훅 리스너 등록 테스트
 *
 * Filter/Action 훅 타입 지원을 테스트합니다.
 */
class ModuleManagerHookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 각 테스트 전에 훅 초기화
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        // 테스트 후 훅 정리
        HookManager::resetAll();

        parent::tearDown();
    }

    /**
     * Filter 타입 훅이 반환값을 올바르게 전달하는지 테스트합니다.
     */
    public function test_filter_hook_returns_value(): void
    {
        // Filter 훅 등록
        HookManager::addFilter('test.filter', function ($value) {
            return $value * 2;
        });

        // Filter 적용
        $result = HookManager::applyFilters('test.filter', 5);

        $this->assertEquals(10, $result);
    }

    /**
     * Filter 훅이 여러 개일 때 체이닝이 올바르게 동작하는지 테스트합니다.
     */
    public function test_filter_hook_chains_multiple_filters(): void
    {
        // 첫 번째 필터 (우선순위 10)
        HookManager::addFilter('test.chain', function ($value) {
            return $value + 10;
        }, 10);

        // 두 번째 필터 (우선순위 20)
        HookManager::addFilter('test.chain', function ($value) {
            return $value * 2;
        }, 20);

        // Filter 적용: (5 + 10) * 2 = 30
        $result = HookManager::applyFilters('test.chain', 5);

        $this->assertEquals(30, $result);
    }

    /**
     * Action 타입 훅이 반환값을 무시하는지 테스트합니다.
     */
    public function test_action_hook_ignores_return_value(): void
    {
        $executed = false;

        // Action 훅 등록
        HookManager::addAction('test.action', function () use (&$executed) {
            $executed = true;

            return 'should be ignored';
        });

        // Action 실행
        HookManager::doAction('test.action');

        $this->assertTrue($executed);
    }

    /**
     * getSubscribedHooks()에서 type 필드가 생략되면 기본값 'action'이 적용되는지 테스트합니다.
     */
    public function test_default_type_is_action(): void
    {
        $executed = false;

        // 기본 타입(action)으로 훅 등록
        HookManager::addAction('test.default', function () use (&$executed) {
            $executed = true;
        });

        // 훅 실행
        HookManager::doAction('test.default');

        // 등록된 훅이 실행되었는지 확인
        $this->assertTrue($executed);
    }

    /**
     * Filter 훅이 추가 인수를 올바르게 전달하는지 테스트합니다.
     */
    public function test_filter_hook_passes_additional_arguments(): void
    {
        HookManager::addFilter('test.args', function ($value, $multiplier, $addition) {
            return ($value * $multiplier) + $addition;
        });

        // (10 * 3) + 5 = 35
        $result = HookManager::applyFilters('test.args', 10, 3, 5);

        $this->assertEquals(35, $result);
    }
}
