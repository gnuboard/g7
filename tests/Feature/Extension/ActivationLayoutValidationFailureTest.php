<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 레이아웃 검증에 실패한 활성화가 아무 상태도 바꾸지 않는지 검증 (#712 후속)
 *
 * 예전 `activateModule()` / `activatePlugin()` 은 확장 상태를 먼저 active 로 쓰고, soft delete 된 레이아웃을
 * 되살린 뒤에야 레이아웃 파일을 검증했다. 검증이 예외를 던지면 그 뒤의 라우트 캐시 재굽기·훅은 건너뛰고
 * 상태만 active 로 남아, 레이아웃이 하나도 없는 활성 확장이 생겼다. 관리자 화면은 실패 토스트를 보여 주지만
 * 목록에는 "활성" 으로 뜬다.
 *
 * 7.0 JSON 문서의 깨진 조각 참조는 이제 관용하지만(#712 W1), 데이터 소스 id 중복·`layouts/` 밖 참조·
 * `.g7.html` 컴파일 오류 같은 엄격한 실패는 그대로라 이 갈래가 남는다. 검증을 상태 쓰기보다 먼저 한다.
 */
class ActivationLayoutValidationFailureTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 테스트 정리 — 디스크 픽스처를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 데이터 소스 id 가 중복된 레이아웃 — 관용 대상이 아닌 엄격한 검증 실패.
     *
     * @return array<string, array<string, mixed>> `resources/layouts` 기준 상대 경로 => JSON
     */
    private function brokenLayouts(): array
    {
        return [
            'admin/broken_screen.json' => [
                'version' => '1.0.0',
                'data_sources' => [
                    ['id' => 'items', 'type' => 'api', 'endpoint' => '/api/a', 'method' => 'GET'],
                    ['id' => 'items', 'type' => 'api', 'endpoint' => '/api/b', 'method' => 'GET'],
                ],
                'components' => [
                    ['type' => 'basic', 'name' => 'Div', 'children' => []],
                ],
            ],
        ];
    }

    /**
     * 이전 비활성화가 soft delete 해 둔 레이아웃 행을 만든다.
     *
     * @param  LayoutSourceType  $sourceType  소스 타입
     * @param  string  $identifier  확장 식별자
     * @return TemplateLayout soft delete 된 행
     */
    private function trashedLayout(LayoutSourceType $sourceType, string $identifier): TemplateLayout
    {
        $row = TemplateLayout::factory()->create([
            'template_id' => $this->fixtureTemplateModel->id,
            'name' => $identifier.'.previous_screen',
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
        ]);
        $row->delete();

        return $row;
    }

    /**
     * 모듈 활성화가 레이아웃 검증에서 실패하면 상태는 비활성 그대로, soft delete 행도 되살리지 않는다.
     *
     * @scenario case=activation_layout_failure_module
     *
     * @effects failed_activation_leaves_extension_inactive_and_layouts_untouched
     *
     * @return void 반환값 없음
     */
    public function test_module_activation_with_invalid_layout_changes_nothing(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixtureModule($this->brokenLayouts());
        Module::where('identifier', $this->fixtureModule)->update(['status' => ExtensionStatus::Inactive->value]);
        $manager = $this->reloadFixtureModuleManager();
        $trashed = $this->trashedLayout(LayoutSourceType::Module, $this->fixtureModule);

        $thrown = null;
        try {
            $manager->activateModule($this->fixtureModule);
        } catch (\Exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, '레이아웃 검증 실패는 예외로 올라와야 합니다.');
        $this->assertStringContainsString('items', $thrown->getMessage());
        $this->assertSame(
            ExtensionStatus::Inactive->value,
            Module::where('identifier', $this->fixtureModule)->value('status'),
            '검증에 실패한 활성화가 상태를 active 로 남겼습니다.',
        );
        $this->assertTrue(TemplateLayout::withTrashed()->find($trashed->id)->trashed(), '실패한 활성화가 soft delete 행을 되살렸습니다.');
        $this->assertSame(0, TemplateLayout::where('source_identifier', $this->fixtureModule)->where('source_type', LayoutSourceType::Module)->count());
    }

    /**
     * 레이아웃 디렉토리가 없는 확장의 활성화 결과에 앞선 검증이 남긴 경고가 섞이지 않는다.
     *
     * 매니저는 싱글턴이고 경고 보관소는 검증에 들어갈 때만 비워진다. 검증을 건너뛰는 확장(레이아웃 없음)의
     * 결과가 보관소를 그대로 꺼내면, 같은 프로세스(큐 워커·CLI 일괄)에서 앞서 검증한 다른 확장의 경고가 실린다.
     *
     * @scenario case=activation_layout_failure_module
     *
     * @effects activation_without_layouts_carries_no_previous_warnings
     *
     * @return void 반환값 없음
     */
    public function test_module_activation_without_layouts_carries_no_previous_warnings(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixtureModule([]);
        Module::where('identifier', $this->fixtureModule)->update(['status' => ExtensionStatus::Inactive->value]);
        $manager = $this->reloadFixtureModuleManager();

        // 다른 확장의 JSON 레이아웃이 깨진 조각 참조로 경고를 남긴 상태를 만든다.
        $other = base_path('storage/framework/testing/activation_warning_leak_'.uniqid());
        File::ensureDirectoryExists($other.'/admin');
        File::put($other.'/admin/host.json', json_encode([
            'version' => '1.0.0',
            'components' => [['partial' => 'partials/does_not_exist.json']],
        ]));

        try {
            $validate = new \ReflectionMethod($manager, 'validateLayoutFiles');
            $validate->setAccessible(true);
            $validate->invoke($manager, $other, 'probe-other', 'module', true);

            $result = $manager->activateModule($this->fixtureModule);
        } finally {
            File::deleteDirectory($other);
        }

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['layout_warnings'], '앞선 검증의 경고가 레이아웃 없는 확장의 결과에 실렸습니다.');
    }

    /**
     * 플러그인 활성화가 레이아웃 검증에서 실패하면 상태는 비활성 그대로, soft delete 행도 되살리지 않는다.
     *
     * @scenario case=activation_layout_failure_plugin
     *
     * @effects failed_activation_leaves_extension_inactive_and_layouts_untouched
     *
     * @return void 반환값 없음
     */
    public function test_plugin_activation_with_invalid_layout_changes_nothing(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixturePlugin($this->brokenLayouts());
        Plugin::where('identifier', $this->fixturePlugin)->update(['status' => ExtensionStatus::Inactive->value]);
        $manager = $this->reloadFixturePluginManager();
        $trashed = $this->trashedLayout(LayoutSourceType::Plugin, $this->fixturePlugin);

        $thrown = null;
        try {
            $manager->activatePlugin($this->fixturePlugin);
        } catch (\Exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, '레이아웃 검증 실패는 예외로 올라와야 합니다.');
        $this->assertStringContainsString('items', $thrown->getMessage());
        $this->assertSame(
            ExtensionStatus::Inactive->value,
            Plugin::where('identifier', $this->fixturePlugin)->value('status'),
            '검증에 실패한 활성화가 상태를 active 로 남겼습니다.',
        );
        $this->assertTrue(TemplateLayout::withTrashed()->find($trashed->id)->trashed(), '실패한 활성화가 soft delete 행을 되살렸습니다.');
        $this->assertSame(0, TemplateLayout::where('source_identifier', $this->fixturePlugin)->where('source_type', LayoutSourceType::Plugin)->count());
    }
}
