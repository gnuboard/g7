<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Plugin;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 레이아웃 편집기 스펙 묶음 엔드포인트(`GET /api/templates/{id}/editor-spec-bundle`) 시험.
 *
 * 묶음 응답이 개별 엔드포인트(`/api/{modules|plugins|templates}/{id}/editor-spec`)와 같은 내용,
 * 같은 활성 판정, 같은 무효화 성질(요청마다 새로 읽기)을 지키는지, 한 항목이 실패해도 나머지를
 * 내는지 확인한다. 서빙은 활성 디렉토리를 읽으므로 고유 식별자의 임시 디렉토리에 파일을 쓰고
 * tearDown 에서 지운다. 템플릿은 설치본 디렉토리를 만들지 않고 이미 설치된 관리자 템플릿을 읽기만 한다.
 */
class EditorSpecBundleTest extends TestCase
{
    use RefreshDatabase;

    private string $moduleId = 'testvendor-bundlemodule';

    private string $secondModuleId = 'testvendor-bundlemodule2';

    private string $pluginId = 'testvendor-bundleplugin';

    private string $templateId = 'sirsoft-admin_basic';

    /** @var array<int, string> 정리할 임시 디렉토리 */
    private array $createdDirs = [];

    /**
     * 임시 확장 디렉토리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->createdDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->createdDirs = [];
        parent::tearDown();
    }

    /**
     * 임시 확장 디렉토리에 파일을 쓴다 (정리 대상 등록).
     *
     * @param  string  $type  확장 종류 디렉토리 (modules 또는 plugins)
     * @param  string  $identifier  확장 식별자
     * @param  string  $file  확장 루트 기준 상대 경로
     * @param  string  $content  파일 내용
     * @return void 반환값 없음
     */
    private function writeExtensionFile(string $type, string $identifier, string $file, string $content): void
    {
        $dir = base_path("{$type}/{$identifier}");
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->createdDirs[] = $dir;
        }
        $filePath = "{$dir}/{$file}";
        if (! is_dir(\dirname($filePath))) {
            mkdir(\dirname($filePath), 0777, true);
        }
        file_put_contents($filePath, $content);
    }

    /**
     * 디렉토리를 재귀로 지운다.
     *
     * @param  string  $dir  디렉토리 절대 경로
     * @return void 반환값 없음
     */
    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * 확장 레코드를 만든다.
     *
     * @param  class-string<Module|Plugin>  $model  모델 클래스
     * @param  string  $identifier  확장 식별자
     * @param  ExtensionStatus  $status  상태
     * @return void 반환값 없음
     */
    private function createExtension(string $model, string $identifier, ExtensionStatus $status): void
    {
        $model::create([
            'identifier' => $identifier,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => $status->value,
        ]);
    }

    /**
     * 묶음 URL 을 만든다.
     *
     * @param  array<int, string>  $modules  모듈 식별자
     * @param  array<int, string>  $plugins  플러그인 식별자
     * @param  string|null  $template  템플릿 식별자 (기본: 관리자 템플릿)
     * @return string 요청 URL
     */
    private function bundleUrl(array $modules, array $plugins, ?string $template = null): string
    {
        $query = http_build_query(['modules' => $modules, 'plugins' => $plugins]);

        return '/api/templates/'.($template ?? $this->templateId).'/editor-spec-bundle'.($query !== '' ? "?{$query}" : '');
    }

    /**
     * 묶음 항목은 모듈 → 플러그인 → 템플릿 순서이고, 각 항목의 스펙은 개별 엔드포인트 응답과 같다.
     *
     * @scenario case=all_kinds_ok
     *
     * @effects editor_spec_bundle_matches_individual_endpoints
     *
     * @return void 반환값 없음
     */
    public function test_bundle_items_match_individual_endpoints_in_merge_order(): void
    {
        $this->createExtension(Module::class, $this->moduleId, ExtensionStatus::Active);
        $this->createExtension(Plugin::class, $this->pluginId, ExtensionStatus::Active);
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec.json', json_encode([
            'sampleGlobal' => ['cart' => ['count' => 0]],
            '$include' => ['controls' => 'editor-spec/controls.json'],
        ]));
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec/controls.json', json_encode([
            'textAlign' => ['widget' => 'segmented'],
        ]));
        $this->writeExtensionFile('plugins', $this->pluginId, 'editor-spec.json', json_encode([
            'actionRecipes' => ['pay' => ['labelKey' => 'x']],
        ]));

        $response = $this->getJson($this->bundleUrl([$this->moduleId], [$this->pluginId]));

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.identifier', $this->templateId)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.kind', 'module')
            ->assertJsonPath('data.items.1.kind', 'plugin')
            ->assertJsonPath('data.items.2.kind', 'template');

        $items = $response->json('data.items');
        $individual = [
            $this->getJson("/api/modules/{$this->moduleId}/editor-spec")->json('data.spec'),
            $this->getJson("/api/plugins/{$this->pluginId}/editor-spec")->json('data.spec'),
            $this->getJson("/api/templates/{$this->templateId}/editor-spec")->json('data.spec'),
        ];

        foreach ($items as $i => $item) {
            $this->assertSame(200, $item['status']);
            $this->assertNull($item['error']);
            $this->assertSame($individual[$i], $item['spec'], "항목 {$i} 의 스펙이 개별 엔드포인트와 다르다");
        }
        // 분할 블록이 합본되고 `$include` 는 남지 않는다(개별 엔드포인트와 같은 합본기).
        $this->assertSame('segmented', $items[0]['spec']['controls']['textAlign']['widget']);
        $this->assertArrayNotHasKey('$include', $items[0]['spec']);
        $this->assertNotNull($items[2]['spec'], '설치된 관리자 템플릿의 스펙이 비어 있다');
    }

    /**
     * 비활성·미존재 확장은 그 항목만 404 로 표시하고, 나머지 항목은 낸다.
     *
     * @scenario case=inactive_or_missing
     *
     * @effects editor_spec_bundle_marks_inactive_item_without_failing_others
     *
     * @return void 반환값 없음
     */
    public function test_inactive_and_missing_extensions_are_marked_per_item(): void
    {
        $this->createExtension(Module::class, $this->moduleId, ExtensionStatus::Inactive);
        $this->createExtension(Module::class, $this->secondModuleId, ExtensionStatus::Active);
        $this->createExtension(Plugin::class, $this->pluginId, ExtensionStatus::Inactive);
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec.json', json_encode(['controls' => ['a' => []]]));
        $this->writeExtensionFile('modules', $this->secondModuleId, 'editor-spec.json', json_encode(['controls' => ['b' => ['widget' => 'text']]]));
        $this->writeExtensionFile('plugins', $this->pluginId, 'editor-spec.json', json_encode(['controls' => ['c' => []]]));

        $response = $this->getJson($this->bundleUrl(
            [$this->moduleId, 'nope-nope', $this->secondModuleId],
            [$this->pluginId],
        ));

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data.items')
            // 비활성 모듈 — 개별 엔드포인트가 404 를 내는 경우와 같은 판정
            ->assertJsonPath('data.items.0.identifier', $this->moduleId)
            ->assertJsonPath('data.items.0.status', 404)
            ->assertJsonPath('data.items.0.spec', null)
            ->assertJsonPath('data.items.0.error', 'module_not_found')
            // 미존재 모듈
            ->assertJsonPath('data.items.1.identifier', 'nope-nope')
            ->assertJsonPath('data.items.1.status', 404)
            // 활성 모듈은 그대로 낸다
            ->assertJsonPath('data.items.2.status', 200)
            ->assertJsonPath('data.items.2.spec.controls.b.widget', 'text')
            // 비활성 플러그인
            ->assertJsonPath('data.items.3.status', 404)
            ->assertJsonPath('data.items.3.error', 'plugin_not_found')
            ->assertJsonPath('data.items.4.kind', 'template')
            ->assertJsonPath('data.items.4.status', 200);

        // 개별 엔드포인트도 같은 항목에 404 를 낸다.
        $this->getJson("/api/modules/{$this->moduleId}/editor-spec")->assertStatus(404);
        $this->getJson("/api/plugins/{$this->pluginId}/editor-spec")->assertStatus(404);
    }

    /**
     * 한 확장의 스펙 판정이 예외로 끝나도 그 항목만 500 으로 표시하고 나머지는 낸다.
     *
     * @scenario case=item_exception
     *
     * @effects editor_spec_bundle_isolates_item_exception
     *
     * @return void 반환값 없음
     */
    public function test_item_exception_is_isolated(): void
    {
        $this->createExtension(Plugin::class, $this->pluginId, ExtensionStatus::Active);
        $this->writeExtensionFile('plugins', $this->pluginId, 'editor-spec.json', json_encode(['controls' => ['p' => ['widget' => 'text']]]));

        $this->mock(ModuleService::class, function ($mock) {
            $mock->shouldReceive('getEditorSpec')->with('broken-module')->andThrow(new RuntimeException('디스크 읽기 실패'));
            $mock->shouldReceive('getEditorSpec')->with('fine-module')->andReturn([
                'success' => true,
                'spec' => ['controls' => ['m' => ['widget' => 'color']]],
                'error' => null,
            ]);
        });

        $this->getJson($this->bundleUrl(['broken-module', 'fine-module'], [$this->pluginId]))
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.status', 500)
            ->assertJsonPath('data.items.0.error', 'spec_load_failed')
            ->assertJsonPath('data.items.0.spec', null)
            ->assertJsonPath('data.items.1.status', 200)
            ->assertJsonPath('data.items.1.spec.controls.m.widget', 'color')
            ->assertJsonPath('data.items.2.spec.controls.p.widget', 'text')
            ->assertJsonPath('data.items.3.kind', 'template');
    }

    /**
     * 확장 활성화·비활성화와 스펙 파일 업데이트는 다음 묶음 요청에 바로 반영된다(개별 엔드포인트와 같다).
     *
     * @scenario case=activation_and_update
     *
     * @effects editor_spec_bundle_reflects_activation_and_update_immediately
     *
     * @return void 반환값 없음
     */
    public function test_activation_and_update_are_reflected_on_next_request(): void
    {
        $this->createExtension(Module::class, $this->moduleId, ExtensionStatus::Inactive);
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec.json', json_encode(['controls' => ['v' => ['widget' => 'v1']]]));
        $url = $this->bundleUrl([$this->moduleId], []);

        $this->getJson($url)->assertJsonPath('data.items.0.status', 404);

        // 활성화
        Module::where('identifier', $this->moduleId)->update(['status' => ExtensionStatus::Active->value]);
        $this->getJson($url)
            ->assertJsonPath('data.items.0.status', 200)
            ->assertJsonPath('data.items.0.spec.controls.v.widget', 'v1');

        // 업데이트 — 설치본 파일이 바뀐다
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec.json', json_encode(['controls' => ['v' => ['widget' => 'v2']]]));
        $this->getJson($url)->assertJsonPath('data.items.0.spec.controls.v.widget', 'v2');
        $this->getJson("/api/modules/{$this->moduleId}/editor-spec")->assertJsonPath('data.spec.controls.v.widget', 'v2');

        // 비활성화
        Module::where('identifier', $this->moduleId)->update(['status' => ExtensionStatus::Inactive->value]);
        $this->getJson($url)->assertJsonPath('data.items.0.status', 404);
    }

    /**
     * 인증 없이 부를 수 있고(개별 엔드포인트와 같은 공개 판정), 템플릿 스펙 파일이 없으면 spec=null 이다.
     *
     * @scenario case=guest_missing_template_spec
     *
     * @effects editor_spec_bundle_is_public_like_individual_endpoints
     *
     * @return void 반환값 없음
     */
    public function test_guest_can_fetch_and_missing_template_spec_is_null(): void
    {
        $this->getJson($this->bundleUrl([], [], 'testvendor-notemplate'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.kind', 'template')
            ->assertJsonPath('data.items.0.status', 200)
            ->assertJsonPath('data.items.0.spec', null);

        $this->getJson('/api/templates/testvendor-notemplate/editor-spec')
            ->assertStatus(200)
            ->assertJsonPath('data.spec', null);
    }

    /**
     * 경로 탈출 표기와 형식 밖 식별자, 상한을 넘는 목록은 422 로 거절한다.
     *
     * @scenario case=invalid_identifier
     *
     * @effects editor_spec_bundle_rejects_unsafe_identifiers
     *
     * @return void 반환값 없음
     */
    public function test_unsafe_identifiers_are_rejected(): void
    {
        $this->getJson($this->bundleUrl(['..'], []))->assertStatus(422);
        $this->getJson($this->bundleUrl(['a/b'], []))->assertStatus(422);
        $this->getJson($this->bundleUrl([], ['x..y']))->assertStatus(422);
        $this->getJson('/api/templates/'.$this->templateId.'/editor-spec-bundle?modules=abc')->assertStatus(422);

        $tooMany = array_map(fn ($i) => "vendor-m{$i}", range(1, 201));
        $this->getJson($this->bundleUrl($tooMany, []))->assertStatus(422);
    }
}
