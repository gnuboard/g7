<?php

namespace Tests\Unit\Extension;

use App\Extension\HookManager;
use Tests\TestCase;

/**
 * 표준 이름으로 옮긴 훅의 하위호환 발행 검증.
 *
 * 이름이 표준(`core.{대상}.{동작}_validation_rules`)과 어긋난 채 공개된 훅을 표준 이름으로 옮길 때,
 * 구 이름을 구독 중인 제3자 확장이 조용히 멈추면 안 된다. 두 이름이 함께 발행되고 값이 순서대로
 * 이어지는지, 구독이 없는 이름은 무해하게 지나가는지 고정한다.
 *
 * @since 7.0.6
 */
class HookManagerLegacyNameTest extends TestCase
{
    private const STANDARD = 'core.probe.action_validation_rules';

    private const LEGACY = 'core.probe.action_rules';

    protected function tearDown(): void
    {
        HookManager::clearFilter(self::STANDARD);
        HookManager::clearFilter(self::LEGACY);

        parent::tearDown();
    }

    public function test_standard_name_subscriber_receives_value(): void
    {
        HookManager::addFilter(self::STANDARD, fn (array $rules) => $rules + ['standard' => true]);

        $result = HookManager::applyFiltersWithLegacyName(self::STANDARD, self::LEGACY, ['base' => true]);

        $this->assertSame(['base' => true, 'standard' => true], $result);
    }

    public function test_legacy_name_subscriber_still_receives_value(): void
    {
        HookManager::addFilter(self::LEGACY, fn (array $rules) => $rules + ['legacy' => true]);

        $result = HookManager::applyFiltersWithLegacyName(self::STANDARD, self::LEGACY, ['base' => true]);

        $this->assertSame(
            ['base' => true, 'legacy' => true],
            $result,
            '구 이름을 구독 중인 확장이 개명으로 조용히 멈춰서는 안 된다',
        );
    }

    public function test_both_names_apply_in_order(): void
    {
        HookManager::addFilter(self::STANDARD, fn (array $r) => [...$r, 'standard']);
        HookManager::addFilter(self::LEGACY, fn (array $r) => [...$r, 'legacy']);

        $result = HookManager::applyFiltersWithLegacyName(self::STANDARD, self::LEGACY, ['base']);

        $this->assertSame(['base', 'standard', 'legacy'], $result, '표준 이름 결과에 구 이름이 이어 적용된다');
    }

    public function test_no_subscriber_returns_value_unchanged(): void
    {
        $result = HookManager::applyFiltersWithLegacyName(self::STANDARD, self::LEGACY, ['base' => true]);

        $this->assertSame(['base' => true], $result);
    }

    public function test_extra_arguments_reach_both_names(): void
    {
        $seen = [];
        HookManager::addFilter(self::STANDARD, function (array $r, string $id) use (&$seen) {
            $seen['standard'] = $id;

            return $r;
        });
        HookManager::addFilter(self::LEGACY, function (array $r, string $id) use (&$seen) {
            $seen['legacy'] = $id;

            return $r;
        });

        HookManager::applyFiltersWithLegacyName(self::STANDARD, self::LEGACY, [], 'probe-plugin');

        $this->assertSame(['standard' => 'probe-plugin', 'legacy' => 'probe-plugin'], $seen);
    }
}
