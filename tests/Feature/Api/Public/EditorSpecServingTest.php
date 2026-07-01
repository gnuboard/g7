<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 모듈/플러그인 editor-spec.json / components.json 서빙 + 활성/비활성 가드
 *
 * 서빙 컨트롤러는 활성 디렉토리(modules/{id}/...)를 읽으므로, 테스트는 고유 식별자의
 * 임시 디렉토리에 파일을 작성하고 tearDown 에서 정리한다. _bundled 원본/실제 확장
 * 디렉토리를 건드리지 않는다.
 */
class EditorSpecServingTest extends TestCase
{
    use RefreshDatabase;

    private string $moduleId = 'testvendor-specmodule';

    private string $pluginId = 'testvendor-specplugin';

    private array $createdDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->createdDirs = [];
        parent::tearDown();
    }

    /** 임시 확장 디렉토리에 파일 작성 (정리 대상 등록) */
    private function writeExtensionFile(string $type, string $identifier, string $file, string $content): void
    {
        $dir = base_path("{$type}/{$identifier}");
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->createdDirs[] = $dir;
        }
        // 분할 형식(editor-spec/{block}.json)은 하위 디렉토리가 필요하다.
        $filePath = "{$dir}/{$file}";
        $fileDir = \dirname($filePath);
        if (! is_dir($fileDir)) {
            mkdir($fileDir, 0777, true);
        }
        file_put_contents($filePath, $content);
    }

    /** 디렉토리 재귀 삭제 */
    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_active_module_editor_spec_returns_spec(): void
    {
        Module::create([
            'identifier' => $this->moduleId,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec.json', json_encode([
            'sampleGlobal' => ['cart' => ['count' => 0]],
            'actionRecipes' => ['pay' => []],
        ]));

        $response = $this->getJson("/api/modules/{$this->moduleId}/editor-spec");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.identifier', $this->moduleId)
            ->assertJsonPath('data.spec.sampleGlobal.cart.count', 0)
            ->assertJsonPath('data.spec.actionRecipes.pay', []);
    }

    public function test_split_module_editor_spec_is_assembled(): void
    {
        // 분할 형식: manifest + `$include` → editor-spec/{block}.json.
        // 엔드포인트가 합본해 단일 spec 으로 응답하는지 검증한다.
        Module::create([
            'identifier' => $this->moduleId,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec.json', json_encode([
            'templateId' => $this->moduleId,
            'darkMode' => ['strategy' => 'ancestor-class'],
            '$include' => [
                'controls' => 'editor-spec/controls.json',
                'sampleData' => 'editor-spec/sampleData.json',
            ],
        ]));
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec/controls.json', json_encode([
            'textAlign' => ['widget' => 'segmented'],
        ]));
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec/sampleData.json', json_encode([
            'byDataSourceId' => ['cart' => ['data' => ['count' => 3]]],
        ]));

        $this->getJson("/api/modules/{$this->moduleId}/editor-spec")
            ->assertStatus(200)
            ->assertJson(['success' => true])
            // include 블록이 top-level 키로 합본됨
            ->assertJsonPath('data.spec.controls.textAlign.widget', 'segmented')
            ->assertJsonPath('data.spec.sampleData.byDataSourceId.cart.data.count', 3)
            // 인라인 메타 보존
            ->assertJsonPath('data.spec.darkMode.strategy', 'ancestor-class')
            // `$include` 키는 합본 결과에 남지 않음
            ->assertJsonMissingPath('data.spec.$include');
    }

    public function test_inactive_module_editor_spec_returns_404(): void
    {
        Module::create([
            'identifier' => $this->moduleId,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
        ]);
        $this->writeExtensionFile('modules', $this->moduleId, 'editor-spec.json', json_encode(['controls' => []]));

        $this->getJson("/api/modules/{$this->moduleId}/editor-spec")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_active_module_without_spec_file_returns_null_spec(): void
    {
        Module::create([
            'identifier' => $this->moduleId,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);
        // editor-spec.json 미작성 — 정상 응답 + spec null
        $this->getJson("/api/modules/{$this->moduleId}/editor-spec")
            ->assertStatus(200)
            ->assertJsonPath('data.spec', null);
    }

    public function test_active_module_components_returns_manifest(): void
    {
        Module::create([
            'identifier' => $this->moduleId,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->writeExtensionFile('modules', $this->moduleId, 'components.json', json_encode([
            'identifier' => $this->moduleId,
            'components' => ['basic' => [], 'composite' => [['name' => 'ProductCard']], 'layout' => []],
        ]));

        $this->getJson("/api/modules/{$this->moduleId}/components.json")
            ->assertStatus(200)
            ->assertJsonPath('components.composite.0.name', 'ProductCard');
    }

    public function test_inactive_module_components_returns_404(): void
    {
        Module::create([
            'identifier' => $this->moduleId,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
        ]);
        $this->getJson("/api/modules/{$this->moduleId}/components.json")->assertStatus(404);
    }

    public function test_active_plugin_editor_spec_returns_spec(): void
    {
        Plugin::create([
            'identifier' => $this->pluginId,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->writeExtensionFile('plugins', $this->pluginId, 'editor-spec.json', json_encode([
            'sampleGlobal' => ['notifications' => ['unread' => 3]],
        ]));

        $this->getJson("/api/plugins/{$this->pluginId}/editor-spec")
            ->assertStatus(200)
            ->assertJsonPath('data.spec.sampleGlobal.notifications.unread', 3);
    }

    public function test_inactive_plugin_editor_spec_returns_404(): void
    {
        Plugin::create([
            'identifier' => $this->pluginId,
            'vendor' => 'testvendor',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
        ]);
        $this->getJson("/api/plugins/{$this->pluginId}/editor-spec")->assertStatus(404);
    }

    public function test_nonexistent_module_editor_spec_returns_404(): void
    {
        $this->getJson('/api/modules/nope-nope/editor-spec')->assertStatus(404);
    }
}
