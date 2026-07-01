<?php

namespace Tests\Feature\Listeners;

use App\Listeners\LayoutEditor\MarkOrphanedCustomTranslations;
use App\Models\Template;
use App\Models\TemplateCustomTranslation;
use App\Models\TemplateLayout;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MarkOrphanedCustomTranslations 리스너 Feature 테스트.
 *
 * 레이아웃 저장(`core.layout.after_update`) 시 좀비(고아) 커스텀 다국어 키
 * 자동 표시/복원, layout_name 격리, auto-discovery 등록, Repository 위임을 검증합니다.
 */
class MarkOrphanedCustomTranslationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 레이아웃 저장 경로가 GDPR 정책 테이블을 조회하므로 포함.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    private LayoutService $layoutService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::factory()->create([
            'type' => 'user',
        ]);
        $this->layoutService = app(LayoutService::class);
    }

    /**
     * 레이아웃 content 헬퍼 — components 안에 주어진 text 노드들을 둔다.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function content(array $components): array
    {
        return ['meta' => ['title' => 'T'], 'components' => $components];
    }

    private function makeLayout(string $name, array $components): TemplateLayout
    {
        return TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $name,
            'content' => $this->content($components),
            'lock_version' => 0,
        ]);
    }

    private function makeKey(string $layoutName, string $key, string $status = 'active'): TemplateCustomTranslation
    {
        return TemplateCustomTranslation::create([
            'template_id' => $this->template->id,
            'layout_name' => $layoutName,
            'translation_key' => $key,
            'values' => ['ko' => 'A', 'en' => 'A'],
            'status' => $status,
            'lock_version' => 0,
        ]);
    }

    public function test_unreferenced_key_is_marked_orphaned_on_save(): void
    {
        $this->makeLayout('home', [['text' => '$t:custom.home.1']]);
        $key = $this->makeKey('home', 'custom.home.1');

        // 키 참조 노드를 제거(평문으로 교체)한 채 저장.
        $this->layoutService->updateLayout($this->template->id, 'home', [
            'content' => $this->content([['text' => 'plain text now']]),
            'expected_lock_version' => 0,
        ]);

        $this->assertDatabaseHas('template_custom_translations', [
            'id' => $key->id,
            'status' => 'orphaned',
        ]);
    }

    public function test_referenced_key_stays_active(): void
    {
        $this->makeLayout('home', [['text' => '$t:custom.home.1']]);
        $key = $this->makeKey('home', 'custom.home.1');

        $this->layoutService->updateLayout($this->template->id, 'home', [
            'content' => $this->content([['text' => '$t:custom.home.1'], ['text' => 'extra']]),
            'expected_lock_version' => 0,
        ]);

        $this->assertDatabaseHas('template_custom_translations', [
            'id' => $key->id,
            'status' => 'active',
        ]);
    }

    public function test_orphaned_key_is_restored_when_reference_returns(): void
    {
        $this->makeLayout('home', [['text' => 'plain']]);
        $key = $this->makeKey('home', 'custom.home.1', 'orphaned');

        // 텍스트를 키 참조로 되돌린 채 저장 → active 복원.
        $this->layoutService->updateLayout($this->template->id, 'home', [
            'content' => $this->content([['text' => '$t:custom.home.1']]),
            'expected_lock_version' => 0,
        ]);

        $this->assertDatabaseHas('template_custom_translations', [
            'id' => $key->id,
            'status' => 'active',
        ]);
    }

    public function test_other_layout_keys_are_not_affected(): void
    {
        $this->makeLayout('home', [['text' => 'plain']]);
        // home 레이아웃 저장 시 board/list 의 키는 건드리지 않아야 한다(layout_name 격리).
        $homeKey = $this->makeKey('home', 'custom.home.1');
        $boardKey = $this->makeKey('board/list', 'custom.board_list.1');

        $this->layoutService->updateLayout($this->template->id, 'home', [
            'content' => $this->content([['text' => 'plain']]),
            'expected_lock_version' => 0,
        ]);

        // home 키는 미참조 → orphaned, board/list 키는 무관 → active 유지.
        $this->assertDatabaseHas('template_custom_translations', ['id' => $homeKey->id, 'status' => 'orphaned']);
        $this->assertDatabaseHas('template_custom_translations', ['id' => $boardKey->id, 'status' => 'active']);
    }

    public function test_listener_is_auto_discovered_and_subscribes_after_update(): void
    {
        $hooks = MarkOrphanedCustomTranslations::getSubscribedHooks();

        $this->assertArrayHasKey('core.layout.after_update', $hooks);
        $this->assertSame('handleLayoutAfterUpdate', $hooks['core.layout.after_update']['method']);
        // 저장 직후 같은 요청에서 좀비 상태가 확정되어야 하므로 동기 실행.
        $this->assertTrue($hooks['core.layout.after_update']['sync'] ?? false);

        // auto-discovery 대상 — HookListenerInterface 구현.
        $this->assertInstanceOf(
            \App\Contracts\Extension\HookListenerInterface::class,
            app(MarkOrphanedCustomTranslations::class),
        );
    }
}
