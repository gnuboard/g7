<?php

namespace Tests\Unit\Services\Extension;

use App\Enums\ExtensionOwnerType;
use App\Enums\LanguagePackScope;
use App\Extension\TemplateManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use App\Services\Extension\ExtensionInstallPreviewBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ExtensionInstallPreviewBuilder 의 cascade 프리뷰 응답 검증.
 *
 * 회귀: 템플릿 설치 모달에서 의존 확장의 식별자/이름/required_version 이 표시되지 않는 버그
 * — TemplateService::getTemplateInfo() 는 manifest 의 raw `{modules, plugins}` shape 를
 * 반환(TemplateResource 가 raw 를 사용해서 변경 불가)하므로, builder 단계에서 Template 스코프 한정으로
 * DependencyEnricher::enrich() 를 거쳐야 enriched 평면 배열로 변환된다.
 */
class ExtensionInstallPreviewBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ExtensionInstallPreviewBuilder $builder;

    private string $testTemplatePath;

    /**
     * 픽스처 템플릿 경로를 비우고 빌더를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(ExtensionInstallPreviewBuilder::class);
        $this->testTemplatePath = base_path('templates/test-preview');

        if (File::exists($this->testTemplatePath)) {
            File::deleteDirectory($this->testTemplatePath);
        }
    }

    /**
     * 픽스처 템플릿 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::exists($this->testTemplatePath)) {
            File::deleteDirectory($this->testTemplatePath);
        }

        parent::tearDown();
    }

    /**
     * 의존성 선언을 담은 임시 템플릿을 만듭니다.
     *
     * @param  array<string, mixed>  $dependencies  template.json 에 실을 의존성 선언
     * @return void 반환값 없음
     */
    private function createTestTemplate(array $dependencies): void
    {
        File::makeDirectory($this->testTemplatePath.'/layouts', 0755, true);

        File::put(
            $this->testTemplatePath.'/template.json',
            json_encode([
                'identifier' => 'test-preview',
                'vendor' => 'test',
                'name' => ['ko' => '테스트 프리뷰', 'en' => 'Test Preview'],
                'version' => '1.0.0',
                'type' => 'admin',
                'description' => ['ko' => '', 'en' => ''],
                'dependencies' => $dependencies,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // builder 가 TemplateService 를 통해 매니페스트를 다시 읽도록 매니저 캐시 갱신
        app(TemplateManager::class)->loadTemplates();
    }

    /**
     * 주어진 관리자 권한만 가진 사용자를 만듭니다 — 동반 설치 기본 선택은 권한이 있어야 켜진다(#764).
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 사용자
     */
    private function createAdminWith(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'preview_test_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 템플릿 의존성이 enriched 평면 배열로 나옵니다.
     *
     * @return void 반환값 없음
     *
     * @scenario extension_type=template, surface=install_preview
     *
     * @effects icon_fields_exposed_on_install_preview
     */
    public function test_template_dependencies_are_returned_as_enriched_flat_array(): void
    {
        $this->createTestTemplate([
            'modules' => ['missing-foo' => '>=1.0.0'],
            'plugins' => ['missing-bar' => '>=2.0.0'],
        ]);

        // 미설치 의존 확장을 함께 설치·활성화할 권한이 있는 관리자 — 권한이 없으면 기본 선택이 꺼진다(#764, 아래 별도 시험)
        $this->actingAs($this->createAdminWith(['core.modules.install', 'core.modules.activate', 'core.plugins.install', 'core.plugins.activate']));

        $preview = $this->builder->build(LanguagePackScope::Template, 'test-preview');

        $this->assertIsArray($preview['dependencies']);
        $this->assertCount(2, $preview['dependencies'], '의존성은 enriched 평면 배열이어야 함 (modules+plugins 합산)');

        $byId = [];
        foreach ($preview['dependencies'] as $dep) {
            $this->assertArrayHasKey('identifier', $dep);
            $this->assertArrayHasKey('name', $dep);
            $this->assertArrayHasKey('type', $dep);
            $this->assertArrayHasKey('required_version', $dep);
            $this->assertArrayHasKey('is_installed', $dep);
            $this->assertArrayHasKey('is_met', $dep);
            $this->assertArrayHasKey('default_selected', $dep);
            $byId[$dep['identifier']] = $dep;
        }

        $this->assertArrayHasKey('missing-foo', $byId);
        $this->assertSame('module', $byId['missing-foo']['type']);
        $this->assertSame('>=1.0.0', $byId['missing-foo']['required_version']);
        $this->assertFalse($byId['missing-foo']['is_installed']);
        $this->assertFalse($byId['missing-foo']['is_met']);
        $this->assertTrue($byId['missing-foo']['default_selected']);

        $this->assertTrue($byId['missing-foo']['can_select']);

        $this->assertArrayHasKey('missing-bar', $byId);
        $this->assertSame('plugin', $byId['missing-bar']['type']);
        $this->assertSame('>=2.0.0', $byId['missing-bar']['required_version']);
        $this->assertTrue($byId['missing-bar']['default_selected']);
    }

    /**
     * 동반 설치 권한이 없으면 미충족·미설치 의존 확장도 기본 선택하지 않고 선택을 잠근다 — 설치 API 도 같은 판정으로 403 을 낸다(#764).
     *
     * 이 계약이 들어오며(#764) 위 시험은 인증 사용자 없이 `default_selected === true` 를 기대한 채 남아 실패했다(7.1.1 출시 전 확인).
     *
     * @return void 반환값 없음
     *
     * @scenario extension_type=template, surface=install_preview, permission=missing
     *
     * @effects cascade_dependency_unselected_without_permission
     */
    public function test_dependencies_are_not_preselected_without_cascade_permissions(): void
    {
        $this->createTestTemplate([
            'modules' => ['missing-foo' => '>=1.0.0'],
            'plugins' => ['missing-bar' => '>=2.0.0'],
        ]);

        // 모듈 권한만 — 플러그인은 함께 설치할 수 없다
        $this->actingAs($this->createAdminWith(['core.modules.install', 'core.modules.activate']));

        $byId = collect($this->builder->build(LanguagePackScope::Template, 'test-preview')['dependencies'])->keyBy('identifier');

        $this->assertTrue($byId['missing-foo']['default_selected']);
        $this->assertFalse($byId['missing-bar']['default_selected']);
        $this->assertFalse($byId['missing-bar']['can_select']);
        $this->assertTrue($byId['missing-bar']['available'], '미충족이라 후보이긴 하다 — 권한만 없다');
    }

    /**
     * 의존성이 없으면 빈 배열이 나옵니다.
     *
     * @return void 반환값 없음
     *
     * @scenario extension_type=template, surface=install_preview
     *
     * @effects icon_fields_exposed_on_install_preview
     */
    public function test_template_with_empty_dependencies_returns_empty_array(): void
    {
        $this->createTestTemplate([
            'modules' => [],
            'plugins' => [],
        ]);

        $preview = $this->builder->build(LanguagePackScope::Template, 'test-preview');

        $this->assertSame([], $preview['dependencies']);
    }

    /**
     * 확인 창은 목록 API 를 거치지 않고 이 응답만으로 그린다 — target 과 언어팩 줄 **양쪽**에
     * 아이콘 필드가 있어야 한다. 한쪽만 있으면 그 줄만 아이콘 없이 그려지고, 화면만 보면
     * 「아이콘이 아직 없는 확장」 과 구분되지 않아 원인을 짚기 어렵다.
     *
     * 모집단은 빌더의 실제 응답에서 파생한다 — 줄을 손으로 열거하면 언어팩이 한 종 늘어도
     * 케이스 없이 통과한다. 언어팩 줄이 하나도 없으면 이 축을 재지 못하므로 하한 가드를 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario extension_type=template, surface=install_preview
     *
     * @effects icon_fields_exposed_on_install_preview
     */
    public function test_install_preview_exposes_icon_fields_on_target_and_every_language_pack_row(): void
    {
        // 동봉 템플릿을 쓴다 — 동봉 ja 팩이 함께 잡혀 언어팩 줄이 실제로 생긴다.
        $preview = $this->builder->build(LanguagePackScope::Template, 'sirsoft-admin_basic');

        $this->assertArrayHasKey('icon_url', $preview['target']);
        $this->assertArrayHasKey('icon_source', $preview['target']);

        $rows = $preview['language_packs'];
        $this->assertNotEmpty(
            $rows,
            '언어팩 줄이 비면 이 축을 재지 못한다 — 동봉 ja 팩이 잡히는 대상으로 픽스처를 바꿔야 한다.'
        );

        foreach ($rows as $row) {
            $this->assertArrayHasKey('icon_url', $row, $row['bundled_identifier'].' 줄에 icon_url 이 없다');
            $this->assertArrayHasKey('icon_source', $row, $row['bundled_identifier'].' 줄에 icon_source 가 없다');
            $this->assertContains(
                $row['icon_source'],
                ['manifest', 'convention', 'target', 'core', 'none'],
                $row['bundled_identifier'].' 의 icon_source 가 규정 밖 값이다'
            );
        }
    }
}
