<?php

namespace Tests\Feature\Hooks;

use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\User;
use App\Services\MenuService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 발화처마다 인자 모양이 달랐던 훅 — 새 이름 훅을 정규 모양으로 나란히 발화한다 (#138 후속 11번 ④⑤)
 *
 * 같은 훅 이름이 두 곳에서 서로 다른 모양으로 발화하면, 한쪽 모양을 기대한 리스너는 다른 쪽에서 조용히
 * 아무것도 하지 않는다. 기존 훅은 서드파티 호환을 위해 지금 모양 그대로 두고(deprecated), 모양이 다른
 * 발화처에는 그 모양을 이름에 드러낸 새 훅을 더한다(설계 결정 — 공개 훅 이름·인자 모양은 바꾸지 않는다).
 */
class HookPayloadShapeSplitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 발화 기록 — 훅 이름 → 발화마다의 인자.
     *
     * @var array<string, array<int, array<int, mixed>>>
     */
    private array $fired = [];

    /**
     * 관찰할 훅에 스파이를 답니다.
     *
     * @param  array<int, string>  $hooks  훅 이름 목록
     * @return void 반환값 없음
     */
    private function watchHooks(array $hooks): void
    {
        foreach ($hooks as $hook) {
            HookManager::addAction($hook, function (...$args) use ($hook) {
                $this->fired[$hook][] = $args;
            }, 1);
        }
    }

    /**
     * 템플릿 페이지 목록은 필터 배열을 싣는 *_list_paginated 를 발화하고, 전체 목록은 발화하지 않는다.
     *
     * @scenario case=template_list
     *
     * @effects paginated_list_fires_shape_named_hook
     *
     * @return void 반환값 없음
     */
    public function test_template_paginated_list_fires_list_paginated_hooks(): void
    {
        $this->watchHooks(['core.templates.before_list_paginated', 'core.templates.after_list_paginated', 'core.templates.after_list']);
        $service = app(TemplateService::class);

        $service->getPaginatedTemplates(['type' => 'admin'], 12, 1);

        $this->assertCount(1, $this->fired['core.templates.before_list_paginated'] ?? []);
        $this->assertSame(['type' => 'admin'], $this->fired['core.templates.before_list_paginated'][0][0]);
        $this->assertCount(1, $this->fired['core.templates.after_list_paginated'] ?? []);
        $this->assertIsArray($this->fired['core.templates.after_list_paginated'][0][1]);
        // 기존 훅은 지금 모양 그대로(호환) — deprecated
        $this->assertCount(1, $this->fired['core.templates.after_list'] ?? []);

        $this->fired = [];
        $service->getAllTemplates('admin');

        $this->assertArrayNotHasKey('core.templates.after_list_paginated', $this->fired);
        $this->assertSame('admin', $this->fired['core.templates.after_list'][0][1] ?? null, '전체 목록의 after_list 두 번째 인자는 유형 문자열');
    }

    /**
     * 평탄한 메뉴 순서 변경은 ID 목록을 싣는 after_update_order_flat 을 발화하고, 운영자 수정(order)이 기록된다.
     *
     * 이 경로는 대량 update 라 모델 이벤트가 돌지 않는다 — 기록은 `Menu::where()->update()` 를 가로채는
     * UserOverridesAwareBuilder 가 실제로 순서가 바뀐 메뉴에만 남긴다(#727, 종전 리스너 구독은 제거).
     *
     * @scenario case=menu_order_flat
     *
     * @effects flat_menu_order_fires_shape_named_hook_and_records_override
     *
     * @return void 반환값 없음
     */
    public function test_flat_menu_order_fires_flat_hook_and_records_order_override(): void
    {
        $this->watchHooks(['core.menu.after_update_order_flat']);
        $first = Menu::factory()->create(['order' => 1]);
        $second = Menu::factory()->create(['order' => 2]);

        $this->withoutMiddleware();
        $this->actingAs(User::factory()->create());
        app(MenuService::class)->updateMenuOrder([$second->id, $first->id]);

        $this->assertSame([[[$second->id, $first->id]]], $this->fired['core.menu.after_update_order_flat'] ?? []);
        $this->assertContains('order', $second->fresh()->user_overrides ?? [], '평탄 경로의 순서 변경이 운영자 수정으로 기록되지 않았다');
        $this->assertContains('order', $first->fresh()->user_overrides ?? []);
    }
}
