<?php

namespace Tests\Feature\Extension;

use App\Extension\HookManager;
use Tests\TestCase;

/**
 * 공개 훅 옛 이름 병행 발화 — 이름을 옮긴 공개 훅을 옛 이름으로 구독한 확장이 깨지지 않게 한다.
 *
 * 공개 훅의 이름은 마이너 버전에서 바꾸지 않는다. 옮겨야 하면 표준 이름을 먼저 발화하고, 옛 이름은
 * 구독자가 있을 때만 같은 인자로 한 번 더 발화한다(docs/extension/hooks.md 「공개 훅 호환 원칙」).
 */
class HookManagerLegacyNameTest extends TestCase
{
    /**
     * 훅 등록 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();

        parent::tearDown();
    }

    /**
     * 액션 옛 이름 병행 발화 — 표준 이름은 늘 발화하고, 옛 이름은 구독자가 있을 때만 같은 인자로 발화한다.
     *
     * @scenario case=legacy_action_alias
     *
     * @effects legacy_action_name_fires_only_with_subscribers
     *
     * @return void 반환값 없음
     */
    public function test_do_action_with_legacy_name_fires_legacy_only_when_subscribed(): void
    {
        $calls = [];
        HookManager::addAction('core.demo.after_new', function ($a) use (&$calls) {
            $calls[] = ['new', $a];
        });

        HookManager::doActionWithLegacyName('core.demo.after_new', 'core.demo.old', 1);
        $this->assertSame([['new', 1]], $calls);

        HookManager::addAction('core.demo.old', function ($a) use (&$calls) {
            $calls[] = ['old', $a];
        });
        HookManager::doActionWithLegacyName('core.demo.after_new', 'core.demo.old', 2);

        $this->assertSame([['new', 1], ['new', 2], ['old', 2]], $calls);
    }

    /**
     * 필터 옛 이름 병행 적용 — 표준 이름 결과에 옛 이름 구독자를 이어 적용하고, 구독자가 없으면 표준 결과 그대로다.
     *
     * @scenario case=legacy_filter_alias
     *
     * @effects legacy_filter_name_applies_only_with_subscribers
     *
     * @return void 반환값 없음
     */
    public function test_apply_filters_with_legacy_name_chains_legacy_only_when_subscribed(): void
    {
        HookManager::addFilter('core.demo.filter_new', fn ($v) => $v.'+new');

        $this->assertSame('v+new', HookManager::applyFiltersWithLegacyName('core.demo.filter_new', 'core.demo.filter_old', 'v'));

        HookManager::addFilter('core.demo.filter_old', fn ($v) => $v.'+old');

        $this->assertSame('v+new+old', HookManager::applyFiltersWithLegacyName('core.demo.filter_new', 'core.demo.filter_old', 'v'));
    }
}
