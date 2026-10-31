<?php

namespace Tests\Feature\LayoutMarkup;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 확장 재활성화는 붙을 자리가 있는 활성 템플릿의 행만 되살린다 (#725 W7 · 결정 D4).
 *
 * 종전 복원(`restoreBySource`)은 출처의 soft delete 행을 템플릿을 가리지 않고 전부 되살렸다. 그래서 대상 부재로
 * 일부러 숨겨 둔 오등록 행(관리자 조각 ↔ 사용자 템플릿)이 재활성화마다 되살았다가 뒤따르는 등록에 다시 숨겨졌고
 * (`deleted_at` 이력이 회차마다 흔들림), 꺼진 템플릿의 행은 정리하는 경로 없이 살아남았다.
 */
class RestoreApplicableFragmentRowsTest extends TestCase
{
    use DatabaseTransactions;

    private const HIDDEN_AT = '2026-10-01 00:00:00';

    private string $source;

    private Template $admin;

    private Template $user;

    private Template $inactiveAdmin;

    /**
     * 관리자(대상 있음)·사용자(대상 없음)·꺼진 관리자 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $this->source = 'probe-restore-'.$suffix;

        $this->admin = Template::factory()->create(['identifier' => 'probe-radmin-'.$suffix, 'type' => 'admin', 'status' => ExtensionStatus::Active->value]);
        $this->user = Template::factory()->create(['identifier' => 'probe-ruser-'.$suffix, 'type' => 'user', 'status' => ExtensionStatus::Active->value]);
        $this->inactiveAdmin = Template::factory()->create(['identifier' => 'probe-roff-'.$suffix, 'type' => 'admin', 'status' => ExtensionStatus::Inactive->value]);

        TemplateLayout::factory()->create(['template_id' => $this->admin->id, 'name' => 'admin_user_form']);
        TemplateLayout::factory()->create(['template_id' => $this->user->id, 'name' => 'auth/register']);
        TemplateLayout::factory()->create(['template_id' => $this->inactiveAdmin->id, 'name' => 'admin_user_form']);
    }

    /**
     * 비활성화 → 재활성화 복원 뒤, 관리자 행만 살아나고 나머지는 숨긴 시각 그대로다. 운영자 수정본은 그대로 남는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects reactivation_restores_only_rows_with_host_on_active_templates
     *
     * @return void 반환값 없음
     */
    public function test_reactivation_restores_only_rows_with_host_on_active_templates(): void
    {
        $edited = ['target_layout' => 'admin_user_form', 'priority' => 100, 'injections' => [['target_id' => 'user_form', 'position' => 'append_child', 'components' => [['type' => 'basic', 'name' => 'Span', 'text' => '운영자 수정']]]]];

        $adminRow = $this->row($this->admin, $edited);
        $userRow = $this->row($this->user);
        $userRow->delete();
        LayoutExtension::withTrashed()->whereKey($userRow->id)->update(['deleted_at' => self::HIDDEN_AT]);
        $offRow = $this->row($this->inactiveAdmin);

        $service = app(LayoutExtensionService::class);
        $service->unregisterBySource(LayoutSourceType::Plugin, $this->source);
        $restored = $service->restoreBySource(LayoutSourceType::Plugin, $this->source, ['admin-user-field']);

        $this->assertSame(1, $restored, '붙을 자리가 있는 활성 템플릿의 행 하나만 되살아야 한다');
        $this->assertNotSoftDeleted($adminRow);
        $this->assertSame($edited, LayoutExtension::find($adminRow->id)->content, '운영자 수정본은 복원으로 바뀌지 않는다');
        $this->assertSame(
            self::HIDDEN_AT,
            (string) LayoutExtension::withTrashed()->find($userRow->id)->deleted_at,
            '대상 없는 사용자 템플릿 행이 되살았거나 숨긴 시각이 바뀌었다 — 재활성화마다 이력이 흔들린다'
        );
        $this->assertSoftDeleted($offRow, [], null, '꺼진 템플릿의 행은 복원하지 않는다');
    }

    /**
     * 이 출처의 조각 행을 만듭니다.
     *
     * @param  Template  $template  템플릿
     * @param  array<string, mixed>|null  $content  본문 (없으면 기본 overlay)
     * @return LayoutExtension 만든 행
     */
    private function row(Template $template, ?array $content = null): LayoutExtension
    {
        return LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_user_form',
            'fragment_key' => 'admin-user-field',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => $this->source,
            'content' => $content ?? ['target_layout' => 'admin_user_form', 'priority' => 100, 'injections' => []],
            'priority' => 100,
            'is_active' => true,
        ]);
    }
}
