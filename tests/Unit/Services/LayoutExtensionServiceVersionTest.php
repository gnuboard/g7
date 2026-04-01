<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Module;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * LayoutExtensionService 버전 호환성 테스트
 *
 * 템플릿 오버라이드의 버전 제약 조건(version_constraint) 검사를 테스트합니다.
 */
class LayoutExtensionServiceVersionTest extends TestCase
{
    use RefreshDatabase;

    private LayoutExtensionService $service;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        // 매 테스트마다 새로운 서비스 인스턴스 생성 (캐시 초기화)
        $this->app->forgetInstance(LayoutExtensionService::class);
        $this->service = app(LayoutExtensionService::class);

        // 테스트용 템플릿 생성
        $this->template = Template::factory()->create([
            'identifier' => 'test-admin',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }


    /**
     * version_constraint가 없으면 항상 적용되는지 테스트
     */
    public function test_extension_without_version_constraint_always_applies(): void
    {
        // version_constraint가 없는 템플릿 오버라이드
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
            'override_target' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'test_layout',
                // version_constraint 없음
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'injected_component'],
                        ],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'target_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 버전 제약이 없으므로 적용됨
        $componentIds = array_column($result['components'], 'id');
        $this->assertContains('injected_component', $componentIds);
        $this->assertEmpty($this->service->getIncompatibleOverrides());
    }

    /**
     * 버전 제약 조건이 만족되면 적용되는지 테스트
     */
    public function test_extension_with_satisfied_version_constraint_applies(): void
    {
        // 모듈 DB에 버전 저장
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '1.5.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // ^1.0 제약 조건을 가진 오버라이드 (1.5.0은 만족)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
            'override_target' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '^1.0',
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'compatible_component'],
                        ],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'target_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 버전 제약 만족 (^1.0 + 1.5.0)
        $componentIds = array_column($result['components'], 'id');
        $this->assertContains('compatible_component', $componentIds);
        $this->assertEmpty($this->service->getIncompatibleOverrides());
    }

    /**
     * 버전 제약 조건이 불만족되면 스킵되고 경고가 수집되는지 테스트
     */
    public function test_extension_with_unsatisfied_version_constraint_skipped(): void
    {
        // 모듈 DB에 버전 저장 (2.0.0 - 메이저 버전 변경)
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '2.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // ^1.0 제약 조건을 가진 오버라이드 (2.0.0은 불만족)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
            'override_target' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '^1.0',
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'incompatible_component'],
                        ],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'target_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 버전 제약 불만족 (^1.0 + 2.0.0) → 스킵됨
        $componentIds = array_column($result['components'], 'id');
        $this->assertNotContains('incompatible_component', $componentIds);

        // 비호환 오버라이드 목록에 추가됨
        $incompatible = $this->service->getIncompatibleOverrides();
        $this->assertCount(1, $incompatible);
        $this->assertEquals('test-admin', $incompatible[0]['source']);
        $this->assertEquals('sirsoft-ecommerce', $incompatible[0]['target']);
        $this->assertEquals('^1.0', $incompatible[0]['constraint']);
        $this->assertEquals('2.0.0', $incompatible[0]['current_version']);
    }

    /**
     * 버전 정보가 없으면 적용되는지 테스트 (하위 호환성)
     */
    public function test_extension_applies_when_module_version_not_found(): void
    {
        // 모듈이 활성화되어 있지만 버전 정보가 빈 문자열인 경우
        // (예: 버전 정보를 입력하지 않은 레거시 데이터)
        Module::create([
            'identifier' => 'legacy-module',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '레거시 모듈'],
            'version' => '',  // 버전 정보 빈 문자열
            'status' => ExtensionStatus::Active->value,
        ]);

        // version_constraint가 있지만 모듈 버전을 찾을 수 없음
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
            'override_target' => 'legacy-module',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '^1.0',
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'fallback_component'],
                        ],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'target_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 버전 정보 없으면 하위 호환성으로 적용
        $componentIds = array_column($result['components'], 'id');
        $this->assertContains('fallback_component', $componentIds);
        $this->assertEmpty($this->service->getIncompatibleOverrides());
    }

    /**
     * 여러 오버라이드 중 호환되는 것만 적용되는지 테스트
     */
    public function test_mixed_compatible_incompatible_extensions(): void
    {
        // 모듈 버전 설정
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '2.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // 호환되는 오버라이드 (>=2.0)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
            'override_target' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '>=2.0',
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'v2_compatible'],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 비호환 오버라이드 (^1.0)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'other-template',
            'override_target' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '^1.0',
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'v1_only'],
                        ],
                    ],
                ],
            ],
            'priority' => 200,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'target_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $componentIds = array_column($result['components'], 'id');

        // 호환되는 것만 적용
        $this->assertContains('v2_compatible', $componentIds);
        $this->assertNotContains('v1_only', $componentIds);

        // 비호환 목록에 하나만
        $incompatible = $this->service->getIncompatibleOverrides();
        $this->assertCount(1, $incompatible);
        $this->assertEquals('other-template', $incompatible[0]['source']);
    }

    /**
     * 비호환 경고가 warnings 필드에 추가되는지 테스트
     */
    public function test_incompatible_warning_added_to_warnings_field(): void
    {
        // 모듈 버전 설정
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '2.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // 비호환 오버라이드
        $extension = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
            'override_target' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '^1.0',
                'injections' => [],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'original_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // warnings 필드가 존재함
        $this->assertArrayHasKey('warnings', $result);
        $this->assertCount(1, $result['warnings']);

        // warnings 필드의 형식 확인
        $warning = $result['warnings'][0];
        $this->assertEquals('compatibility_'.$extension->id, $warning['id']);
        $this->assertEquals('compatibility', $warning['type']);
        $this->assertEquals('warning', $warning['level']);
        $this->assertArrayHasKey('message', $warning);
        $this->assertEquals('test-admin', $warning['source']);
        $this->assertEquals('sirsoft-ecommerce', $warning['target']);
        $this->assertEquals('^1.0', $warning['constraint']);
        $this->assertEquals('2.0.0', $warning['current_version']);

        // 원래 컴포넌트는 그대로
        $this->assertEquals('original_component', $result['components'][0]['id']);
    }

    /**
     * 다양한 버전 제약 문법 테스트
     *     */
    #[DataProvider('versionConstraintProvider')]
    public function test_various_version_constraint_syntaxes(string $version, string $constraint, bool $expected): void
    {
        // 모듈 생성
        Module::create([
            'identifier' => 'sirsoft-test-module',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '테스트'],
            'version' => $version,
            'status' => ExtensionStatus::Active->value,
        ]);

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-template',
            'override_target' => 'sirsoft-test-module',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => $constraint,
                'injections' => [
                    [
                        'target_id' => 'target',
                        'position' => 'append',
                        'components' => [['id' => 'injected']],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [['id' => 'target']],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $componentIds = array_column($result['components'], 'id');
        $isApplied = in_array('injected', $componentIds, true);

        $this->assertEquals(
            $expected,
            $isApplied,
            "version={$version}, constraint={$constraint} should be ".($expected ? 'compatible' : 'incompatible')
        );
    }

    /**
     * 버전 제약 조건 테스트 데이터 제공
     *
     * @return array<array{string, string, bool}>
     */
    public static function versionConstraintProvider(): array
    {
        return [
            // ^ (caret) - 호환되는 변경 허용
            'caret 1.0 with 1.5.0' => ['1.5.0', '^1.0', true],
            'caret 1.0 with 2.0.0' => ['2.0.0', '^1.0', false],

            // ~ (tilde) - 패치 레벨 변경 허용
            // ~1.5.0은 >=1.5.0 <1.6.0, ~1.5는 >=1.5.0 <2.0.0
            'tilde 1.5.0 with 1.5.5' => ['1.5.5', '~1.5.0', true],
            'tilde 1.5.0 with 1.6.0' => ['1.6.0', '~1.5.0', false],

            // 범위 연산자
            'range >=1.0 <2.0 with 1.5.0' => ['1.5.0', '>=1.0 <2.0', true],
            'range >=1.0 <2.0 with 2.0.0' => ['2.0.0', '>=1.0 <2.0', false],

            // 하이픈 범위
            'hyphen range with 1.9.9' => ['1.9.9', '1.0.0 - 1.9.9', true],
            'hyphen range with 2.0.0' => ['2.0.0', '1.0.0 - 1.9.9', false],

            // 정확한 버전 일치
            'exact version match' => ['1.2.3', '1.2.3', true],
            'exact version mismatch' => ['1.2.4', '1.2.3', false],
        ];
    }

    /**
     * 모듈이 아닌 확장은 버전 검사를 건너뛰는지 테스트
     */
    public function test_non_template_override_skips_version_check(): void
    {
        // 모듈에서 직접 등록된 확장 (템플릿 오버라이드가 아님)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,  // 템플릿이 아닌 모듈
            'source_identifier' => 'sirsoft-ecommerce',
            'override_target' => null,  // 오버라이드 대상 없음
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '^999.0',  // 절대 만족 안 되는 버전
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [['id' => 'module_component']],
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [['id' => 'target_component']],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        // 모듈 확장은 버전 검사 없이 적용됨
        $componentIds = array_column($result['components'], 'id');
        $this->assertContains('module_component', $componentIds);
        $this->assertEmpty($this->service->getIncompatibleOverrides());
    }

    /**
     * 버전 비호환 템플릿 오버라이드가 있을 때 원본 모듈 UI로 fallback하는지 테스트
     *
     * 시나리오:
     * 1. 모듈이 원본 동적 UI를 등록
     * 2. 템플릿이 해당 모듈의 UI를 오버라이드 (하지만 버전 비호환)
     * 3. 결과: 템플릿 오버라이드 대신 원본 모듈 UI가 사용되어야 함
     */
    public function test_incompatible_override_fallback_to_original_module_ui(): void
    {
        // 모듈 버전 설정 (2.0.0)
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '2.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // 모듈이 등록한 원본 동적 UI
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'override_target' => null,
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'original_module_ui'],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 템플릿이 등록한 오버라이드 (버전 비호환: ^1.0은 2.0.0과 호환 안 됨)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
            'override_target' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '^1.0',  // 2.0.0과 비호환
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'template_override_ui'],
                        ],
                    ],
                ],
            ],
            'priority' => 200,  // 오버라이드라서 더 높은 우선순위
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'target_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $componentIds = array_column($result['components'], 'id');

        // 원본 모듈 UI가 사용되어야 함
        $this->assertContains('original_module_ui', $componentIds);
        // 템플릿 오버라이드 UI는 사용되면 안 됨
        $this->assertNotContains('template_override_ui', $componentIds);

        // 비호환 경고가 있어야 함
        $incompatible = $this->service->getIncompatibleOverrides();
        $this->assertCount(1, $incompatible);
        $this->assertEquals('test-admin', $incompatible[0]['source']);
        $this->assertEquals('sirsoft-ecommerce', $incompatible[0]['target']);
    }

    /**
     * 호환되는 템플릿 오버라이드가 있을 때 원본 모듈 UI 대신 사용되는지 테스트
     *
     * 시나리오:
     * 1. 모듈이 원본 동적 UI를 등록
     * 2. 템플릿이 해당 모듈의 UI를 오버라이드 (버전 호환)
     * 3. 결과: 원본 모듈 UI 대신 템플릿 오버라이드가 사용되어야 함
     */
    public function test_compatible_override_replaces_original_module_ui(): void
    {
        // 모듈 버전 설정 (2.0.0)
        Module::create([
            'identifier' => 'sirsoft-ecommerce',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'version' => '2.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // 모듈이 등록한 원본 동적 UI
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-ecommerce',
            'override_target' => null,
            'content' => [
                'target_layout' => 'test_layout',
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'original_module_ui'],
                        ],
                    ],
                ],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        // 템플릿이 등록한 오버라이드 (버전 호환: >=2.0은 2.0.0과 호환)
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'test_layout',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
            'override_target' => 'sirsoft-ecommerce',
            'content' => [
                'target_layout' => 'test_layout',
                'version_constraint' => '>=2.0',  // 2.0.0과 호환
                'injections' => [
                    [
                        'target_id' => 'target_component',
                        'position' => 'append',
                        'components' => [
                            ['id' => 'template_override_ui'],
                        ],
                    ],
                ],
            ],
            'priority' => 200,
            'is_active' => true,
        ]);

        $layout = [
            'layout_name' => 'test_layout',
            'components' => [
                ['id' => 'target_component'],
            ],
            'data_sources' => [],
        ];

        $result = $this->service->applyExtensions($layout, $this->template->id);

        $componentIds = array_column($result['components'], 'id');

        // 템플릿 오버라이드 UI가 사용되어야 함
        $this->assertContains('template_override_ui', $componentIds);
        // 원본 모듈 UI는 사용되면 안 됨 (오버라이드됨)
        $this->assertNotContains('original_module_ui', $componentIds);

        // 비호환 경고가 없어야 함
        $this->assertEmpty($this->service->getIncompatibleOverrides());
    }
}
