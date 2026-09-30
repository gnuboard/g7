<?php

namespace Tests\Feature\Extension;

use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Extension\TemplateManager;
use App\Models\LayoutExtension;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 확장 조각(`template_layout_extensions`) 운영자 수정이 업데이트·다른 확장 활성화에 지워지지 않는지 고정한다 (#727).
 *
 * 결함(수정 전):
 *  - 모듈 keep 업데이트가 refresh **앞에서** 조각을 전략 없이 재등록해 원본 해시까지 파일로 덮어썼다 —
 *    뒤따르는 keep 비교가 늘 "미수정" 이라 확장 조각에는 keep 이 항상 무효였다 (R4).
 *  - 아무 확장이나 활성화하면 전 활성 확장의 조각을 전략 없이 재등록해 다른 모듈 조각 수정까지 지웠다 (R5).
 */
class LayoutExtensionKeepTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 조각이 붙을 호스트 화면 하나를 가진 템플릿 + 조각 두 개를 가진 모듈을 설치합니다.
     *
     * @return ModuleManager 모듈 관리자
     */
    private function installV1(): ModuleManager
    {
        $this->makeFixtureTemplate([
            'merge_host.json' => ['version' => '1.0.0', 'layout_name' => 'merge_host', 'components' => [['id' => 'anchor', 'type' => 'basic', 'name' => 'Div']]],
        ]);
        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, false);

        $manager = $this->makeFixtureModule([], $this->fragmentFiles('FA1', 'FB1'));
        $this->invoke($manager, 'registerLayoutExtensions', $manager->getModule($this->fixtureModule));

        return $manager;
    }

    /**
     * 호스트 화면에 붙는 조각 두 개를 만듭니다.
     *
     * @param  string  $aText  조각 A 문구
     * @param  string  $bText  조각 B 문구
     * @return array<string, array<string, mixed>> 파일명 => JSON
     */
    private function fragmentFiles(string $aText, string $bText): array
    {
        $fragment = fn (string $ref, string $text) => [
            'target_layout' => 'merge_host',
            'priority' => 900,
            'injections' => [[
                'target_id' => 'anchor',
                'position' => 'append_child',
                'components' => [['id' => $ref, 'type' => 'basic', 'name' => 'Div', 'text' => $text]],
            ]],
        ];

        return [
            'frag_a.json' => $fragment('frag_a', $aText),
            'frag_b.json' => $fragment('frag_b', $bText),
        ];
    }

    /**
     * 관리자의 protected 메서드를 실행합니다.
     *
     * @param  ModuleManager  $manager  모듈 관리자
     * @param  string  $method  메서드명
     * @param  mixed  ...$args  인자
     * @return mixed 반환값
     */
    private function invoke(ModuleManager $manager, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(ModuleManager::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($manager, ...$args);
    }

    /**
     * 픽스처 템플릿의 조각 행을 찾습니다.
     *
     * @param  string  $key  조각 식별자
     * @return LayoutExtension 행
     */
    private function fragment(string $key): LayoutExtension
    {
        return LayoutExtension::where('template_id', $this->fixtureTemplateModel->id)
            ->where('source_identifier', $this->fixtureModule)
            ->where('fragment_key', $key)
            ->firstOrFail();
    }

    /**
     * 운영자가 조각 A 문구를 고칩니다.
     *
     * @return LayoutExtension 고친 행
     */
    private function operatorEditsFragmentA(): LayoutExtension
    {
        $row = $this->fragment('frag_a');
        $this->operatorEdits($row, function (array $c) {
            $c['injections'][0]['components'][0]['text'] = '운영자 조각';

            return $c;
        });

        return $row->fresh();
    }

    /**
     * R4 — keep 업데이트 순서(조각 재등록 → 전 템플릿 재등록 → keep refresh)가 조각 수정을 지우지 않고,
     * 운영자가 안 고친 조각에는 새 판을 넣는다.
     *
     * @scenario source=module, path=update_keep, unit=extension_fragment
     *
     * @effects fragment_modification_is_merged_not_lost
     *
     * @return void 반환값 없음
     */
    public function test_keep_update_sequence_preserves_fragment_modification(): void
    {
        $manager = $this->installV1();
        $a = $this->operatorEditsFragmentA();

        $this->writeModuleFiles([], $this->fragmentFiles('FA2', 'FB2'));
        $manager = $this->reloadFixtureModuleManager();

        // updateModule() 의 레이아웃 단계와 같은 순서
        $this->invoke($manager, 'registerLayoutExtensions', $manager->getModule($this->fixtureModule));
        $this->invoke($manager, 'reregisterActiveExtensionsToAllTemplates');
        $manager->refreshModuleLayouts($this->fixtureModule, true);

        $this->assertStringContainsString('운영자 조각', $this->flat($a->fresh()->content), 'keep 업데이트가 확장 조각 수정을 지웠다');
        $this->assertStringContainsString('FB2', $this->flat($this->fragment('frag_b')->content), '운영자가 안 고친 조각 B 에 새 판이 들어와야 한다');
    }

    /**
     * R5 — 다른 확장을 활성화할 때 도는 전 확장 조각 재등록이 이 모듈의 조각 수정을 지우지 않는다.
     *
     * @scenario source=module, path=other_extension_activate, unit=extension_fragment
     *
     * @effects fragment_modification_is_merged_not_lost, activation_preserves_operator_modification
     *
     * @return void 반환값 없음
     */
    public function test_other_extension_activation_preserves_fragment_modification(): void
    {
        $manager = $this->installV1();
        $a = $this->operatorEditsFragmentA();

        $this->invoke($manager, 'reregisterActiveExtensionsToAllTemplates');

        $this->assertStringContainsString('운영자 조각', $this->flat($a->fresh()->content), '다른 확장 활성화가 조각 수정을 지웠다');
    }

    /**
     * R4-b — 결제 플러그인 리스너처럼 확장이 직접 부르는 `registerExtension(…, true)` 호환 경로도 보존한다.
     *
     * @effects fragment_modification_is_merged_not_lost
     *
     * @return void 반환값 없음
     */
    public function test_direct_register_extension_with_preserve_true_keeps_modification(): void
    {
        $this->installV1();
        $a = $this->operatorEditsFragmentA();

        app(LayoutExtensionService::class)->registerExtension(
            $this->fragmentFiles('FA3', 'FB3')['frag_a.json'],
            LayoutSourceType::Module,
            $this->fixtureModule,
            $this->fixtureTemplateModel->id,
            true,
            'frag_a'
        );

        $this->assertStringContainsString('운영자 조각', $this->flat($a->fresh()->content));
    }
}
