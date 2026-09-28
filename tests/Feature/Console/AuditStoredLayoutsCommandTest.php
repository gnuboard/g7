<?php

namespace Tests\Feature\Console;

use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Support\TrustedScriptHosts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `layout:audit-expressions` — 강화 이전에 저장된 레이아웃을 같은 게이트로 재검사한다 (KVE-2026-2350).
 *
 * 게이트를 강화해도 이미 저장된 content(레이아웃 행·버전 이력)는 그대로 남아 렌더·복원된다.
 * 커맨드는 보고만 하고 값을 바꾸지 않는다. 스키마를 새로 만들지 않는다(DatabaseTransactions) —
 * 테스트 DB 의 기존 행과 공존하므로 이 테스트가 만든 행의 id 로 판정한다.
 */
class AuditStoredLayoutsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private Template $template;

    /**
     * 검사 대상 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::firstOrCreate(
            ['identifier' => 'sirsoft-admin_basic'],
            Template::factory()->make(['identifier' => 'sirsoft-admin_basic', 'type' => 'admin', 'status' => ExtensionStatus::Active->value])->getAttributes(),
        );
    }

    /**
     * 테스트 간 신뢰 호스트 훅을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(TrustedScriptHosts::FILTER_HOOK);
        parent::tearDown();
    }

    /**
     * 저장된 탈출 payload(레이아웃 행·버전 이력)를 보고하고 값은 바꾸지 않는지 검증합니다.
     *
     * @scenario case=host_graph_escape
     *
     * @effects stored_layouts_are_reaudited_report_only
     *
     * @return void 반환값 없음
     */
    public function test_reports_stored_payload_in_layout_and_version_without_modifying(): void
    {
        $content = [
            'layout_name' => 'audit_probe_'.uniqid(),
            'version' => '1.0.0',
            'extends' => '_admin_base',
            'slots' => ['content' => [[
                'type' => 'basic',
                'name' => 'Img',
                'actions' => [['type' => 'error', 'handler' => 'setState', 'params' => [
                    'target' => 'local',
                    'probe' => "{{\$event.target.ownerDocument.defaultView.localStorage.getItem('auth_token')}}",
                ]]],
            ]]],
        ];

        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => $content['layout_name'],
            'content' => $content,
        ]);
        $version = TemplateLayoutVersion::create([
            'layout_id' => $layout->id,
            'version' => 1,
            'content' => $content,
        ]);

        $exit = Artisan::call('layout:audit-expressions', ['--source' => 'db', '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $targets = collect($report['findings'])->map(fn (array $f) => $f['source'].$f['target'])->all();
        $this->assertContains('template_layouts#'.$layout->id, $targets);
        $this->assertContains('template_layout_versions#'.$version->id, $targets);

        // 보고 전용 — 값은 그대로다
        $this->assertSame($content, $layout->fresh()->content);
    }

    /**
     * 번들·작업본 레이아웃 파일은 위반 0건으로 통과하는지(정상 회귀) 검증합니다.
     *
     * @effects stored_layouts_are_reaudited_report_only, bundled_layouts_pass_strengthened_rules
     *
     * @return void 반환값 없음
     */
    public function test_layout_files_pass_and_exit_zero(): void
    {
        // 확장 manifest 가 선언하는 신뢰 스크립트 호스트(우편번호 검색) — 테스트 DB 에는 확장 활성 이력이 없다
        HookManager::addFilter(
            TrustedScriptHosts::FILTER_HOOK,
            fn (array $hosts) => array_merge($hosts, ['t1.daumcdn.net']),
            priority: 1
        );

        $exit = Artisan::call('layout:audit-expressions', ['--source' => 'files', '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame([], $report['findings']);
        $this->assertGreaterThan(500, $report['scanned']);
        $this->assertSame(0, $exit);
    }

    /**
     * 잘못된 --source 값은 종료 코드 2 로 거부되는지 검증합니다.
     *
     * @effects stored_layouts_are_reaudited_report_only
     *
     * @return void 반환값 없음
     */
    public function test_invalid_source_option_is_rejected(): void
    {
        $this->assertSame(2, Artisan::call('layout:audit-expressions', ['--source' => 'nope']));
    }
}
