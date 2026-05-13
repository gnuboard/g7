<?php

namespace Tests\Feature\Rules;

use App\Rules\ComponentExists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ComponentExistsTest extends TestCase
{
    use RefreshDatabase;

    private string $testTemplateDir;

    private string $testManifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 템플릿 디렉토리 생성
        $this->testTemplateDir = base_path('templates/test-template');
        $this->testManifestPath = $this->testTemplateDir.'/components.json';

        if (! is_dir($this->testTemplateDir)) {
            mkdir($this->testTemplateDir, 0755, true);
        }

        // 테스트용 components.json 생성
        $manifest = [
            'basic' => ['Button', 'Input', 'Textarea', 'Label'],
            'composite' => ['Card', 'Modal', 'Dropdown', 'DataGrid'],
            'layout' => ['Container', 'Section', 'Grid'],
        ];

        file_put_contents($this->testManifestPath, json_encode($manifest, JSON_PRETTY_PRINT));

        // 캐시 클리어
        Cache::flush();
    }

    protected function tearDown(): void
    {
        // 테스트 파일 및 디렉토리 삭제
        if (file_exists($this->testManifestPath)) {
            unlink($this->testManifestPath);
        }

        if (is_dir($this->testTemplateDir)) {
            rmdir($this->testTemplateDir);
        }

        Cache::flush();

        parent::tearDown();
    }

    /**
     * 등록된 컴포넌트는 검증 통과
     */
    public function test_registered_components_pass_validation(): void
    {
        $rule = new ComponentExists;
        $rule->setData(['template_id' => 'test-template']);

        $layoutJson = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => ['label' => 'Click me'],
                ],
                [
                    'id' => 'card-1',
                    'type' => 'composite',
                    'name' => 'Card',
                    'props' => ['title' => 'Test Card'],
                ],
            ],
        ];

        $failed = false;
        $rule->validate('layout_json', $layoutJson, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, '등록된 컴포넌트는 검증을 통과해야 합니다.');
    }

    /**
     * 미등록 컴포넌트는 검증 실패
     */
    public function test_unregistered_component_fails_validation(): void
    {
        $rule = new ComponentExists;
        $rule->setData(['template_id' => 'test-template']);

        $layoutJson = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'malicious-1',
                    'type' => 'basic',
                    'name' => 'MaliciousComponent',
                    'props' => [],
                ],
            ],
        ];

        $failed = false;
        $failMessage = '';
        $rule->validate('layout_json', $layoutJson, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        $this->assertTrue($failed, '미등록 컴포넌트는 검증에 실패해야 합니다.');
        $this->assertStringContainsString('MaliciousComponent', $failMessage);
    }

    /**
     * 중첩된 children 내의 모든 컴포넌트 검증
     */
    public function test_validates_nested_children_components(): void
    {
        $rule = new ComponentExists;
        $rule->setData(['template_id' => 'test-template']);

        $layoutJson = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'container-1',
                    'type' => 'layout',
                    'name' => 'Container',
                    'props' => [],
                    'children' => [
                        [
                            'id' => 'card-1',
                            'type' => 'composite',
                            'name' => 'Card',
                            'props' => [],
                            'children' => [
                                [
                                    'id' => 'button-1',
                                    'type' => 'basic',
                                    'name' => 'Button',
                                    'props' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $rule->validate('layout_json', $layoutJson, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, '중첩된 children의 등록된 컴포넌트는 검증을 통과해야 합니다.');
    }

    /**
     * 중첩된 children에 미등록 컴포넌트가 있으면 실패
     */
    public function test_fails_with_unregistered_component_in_nested_children(): void
    {
        $rule = new ComponentExists;
        $rule->setData(['template_id' => 'test-template']);

        $layoutJson = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'container-1',
                    'type' => 'layout',
                    'name' => 'Container',
                    'props' => [],
                    'children' => [
                        [
                            'id' => 'evil-1',
                            'type' => 'basic',
                            'name' => 'EvilComponent',
                            'props' => [],
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $rule->validate('layout_json', $layoutJson, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, '중첩된 children의 미등록 컴포넌트는 검증에 실패해야 합니다.');
    }

    /**
     * 빈 컴포넌트 이름은 검증 실패
     */
    public function test_empty_component_name_fails_validation(): void
    {
        $rule = new ComponentExists;
        $rule->setData(['template_id' => 'test-template']);

        $layoutJson = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'empty-1',
                    'type' => 'basic',
                    'name' => '',
                    'props' => [],
                ],
            ],
        ];

        $failed = false;
        $rule->validate('layout_json', $layoutJson, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, '빈 컴포넌트 이름은 검증에 실패해야 합니다.');
    }

    /**
     * template_id가 없으면 검증 실패
     */
    public function test_missing_template_id_fails_validation(): void
    {
        $rule = new ComponentExists;
        $rule->setData([]); // template_id 없음

        $layoutJson = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [],
                ],
            ],
        ];

        $failed = false;
        $rule->validate('layout_json', $layoutJson, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'template_id가 없으면 검증에 실패해야 합니다.');
    }

    /**
     * components.json이 없으면 검증 실패
     */
    public function test_missing_manifest_fails_validation(): void
    {
        $rule = new ComponentExists;
        $rule->setData(['template_id' => 'non-existent-template']);

        $layoutJson = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [],
                ],
            ],
        ];

        $failed = false;
        $rule->validate('layout_json', $layoutJson, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'components.json이 없으면 검증에 실패해야 합니다.');
    }

    /**
     * 캐싱이 정상 동작하는지 확인
     */
    public function test_caching_works_correctly(): void
    {
        $rule1 = new ComponentExists;
        $rule1->setData(['template_id' => 'test-template']);

        $layoutJson = [
            'version' => '1.0.0',
            'layout_name' => 'test',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [],
                ],
            ],
        ];

        // 첫 번째 호출 (캐시 미스)
        $failed1 = false;
        $rule1->validate('layout_json', $layoutJson, function () use (&$failed1) {
            $failed1 = true;
        });

        // 두 번째 호출 (캐시 히트)
        $rule2 = new ComponentExists;
        $rule2->setData(['template_id' => 'test-template']);

        $failed2 = false;
        $rule2->validate('layout_json', $layoutJson, function () use (&$failed2) {
            $failed2 = true;
        });

        $this->assertFalse($failed1);
        $this->assertFalse($failed2);

        // 캐시 키가 올바르게 생성되었는지 확인 (CacheInterface 사용 — ComponentExists.php 참조)
        $cache = app(\App\Contracts\Extension\CacheInterface::class);
        $cacheKey = 'template.test-template.components_manifest';
        $this->assertNotNull($cache->get($cacheKey), '캐시가 생성되어야 합니다.');
    }
}
