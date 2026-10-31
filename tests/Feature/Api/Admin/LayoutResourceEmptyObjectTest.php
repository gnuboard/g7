<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use stdClass;
use Tests\TestCase;

/**
 * 관리자 편집기 응답의 빈 객체 보존 테스트 (#758)
 *
 * 편집기가 처음 받는 본문부터 `[]` 이면, 아무것도 고치지 않은 저장도 그 `[]` 를 DB 에 굳힌다. 레이아웃·확장
 * 단건 조회, 버전 단건 조회, 위지윅 편집 모드(`with_source_meta=1`)의 `__editor.original` 이 `{}` 를 지켜야 한다.
 *
 * @see tests/scenarios/layout-write-empty-object.yaml
 */
class LayoutResourceEmptyObjectTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 레이아웃 서빙 경로가 GDPR 미들웨어를 거치므로 그 마이그레이션을 포함한다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    private string $token;

    /**
     * 편집 권한 관리자 토큰과 활성 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit'])
            ->createToken('editor')->plainTextToken;

        $this->template = Template::factory()->create(['type' => 'user', 'status' => ExtensionStatus::Active->value]);
    }

    /**
     * 레이아웃 단건 조회의 보조 필드(components·data_sources·metadata)가 빈 객체를 지킨다.
     *
     * @scenario path=json_object
     *
     * @effects admin_resource_keeps_empty_object
     *
     * @return void 반환값 없음
     */
    public function test_layout_resource_auxiliary_fields_keep_empty_objects(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'probe_page',
            'content' => [
                'layout_name' => 'probe_page',
                'metadata' => ['flags' => new stdClass],
                'data_sources' => [['id' => 's', 'type' => 'api', 'endpoint' => '/api/x', 'fallback' => ['data' => new stdClass]]],
                'components' => [['id' => 'root', 'type' => 'basic', 'name' => 'Div', 'props' => ['dataMap' => new stdClass]]],
            ],
        ]);

        $body = $this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/probe_page")
            ->assertOk();
        $data = json_decode($body->getContent())->data;

        $this->assertEquals(new stdClass, $data->components[0]->props->dataMap, 'components');
        $this->assertEquals(new stdClass, $data->data_sources[0]->fallback->data, 'data_sources');
        $this->assertEquals(new stdClass, $data->metadata->flags, 'metadata');
        $this->assertStringContainsString('"dataMap": {}', $data->content, 'content 문자열(종전부터 보존)');
    }

    /**
     * 확장 단건 조회의 content 문자열이 빈 객체를 지킨다.
     *
     * @scenario path=json_object
     *
     * @effects admin_resource_keeps_empty_object
     *
     * @return void 반환값 없음
     */
    public function test_extension_resource_keeps_empty_objects(): void
    {
        $extension = $this->makeExtension();

        $data = json_decode($this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}")
            ->assertOk()->getContent())->data;

        $this->assertStringContainsString('"selection": {}', $data->content);
    }

    /**
     * 레이아웃·확장 버전 단건 조회가 빈 객체를 지킨다 — 버전 비교 화면에 `{}`↔`[]` 가짜 차이가 생기지 않는다.
     *
     * @scenario path=restore
     *
     * @effects admin_resource_keeps_empty_object
     *
     * @return void 반환값 없음
     */
    public function test_version_resources_keep_empty_objects(): void
    {
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'probe_page',
            'content' => ['layout_name' => 'probe_page', 'components' => []],
        ]);
        TemplateLayoutVersion::create([
            'layout_id' => $layout->id,
            'version' => 1,
            'content' => ['layout_name' => 'probe_page', 'initLocal' => ['filters' => new stdClass], 'components' => []],
            'changes_summary' => ['added' => 0, 'removed' => 0, 'char_diff' => 0],
        ]);

        $extension = $this->makeExtension();
        TemplateLayoutExtensionVersion::create([
            'extension_id' => $extension->id,
            'version' => 1,
            'content' => ['extension_point' => 'probe_point', 'components' => [['id' => 'e', 'props' => ['dataMap' => new stdClass]]]],
            'changes_summary' => ['added' => 0, 'removed' => 0, 'char_diff' => 0],
        ]);

        $layoutVersion = json_decode($this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/probe_page/versions/1")
            ->assertOk()->getContent())->data;
        $this->assertEquals(new stdClass, $layoutVersion->full_content->initLocal->filters, '레이아웃 버전 full_content');

        $extensionVersion = json_decode($this->authRequest()
            ->getJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/versions/1")
            ->assertOk()->getContent())->data;
        $this->assertStringContainsString('"dataMap": {}', $extensionVersion->content, '확장 버전 content');
    }

    /**
     * 위지윅 편집 모드 응답의 `__editor.original` 이 보존 규칙 밖 자리의 빈 객체도 지킨다. 두 번째 요청(캐시)도 같다.
     *
     * @scenario path=json_string
     *
     * @effects admin_resource_keeps_empty_object
     *
     * @return void 반환값 없음
     */
    public function test_editor_original_keeps_every_empty_object(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'probe_page',
            'content' => [
                'layout_name' => 'probe_page',
                'components' => [['id' => 'root', 'type' => 'basic', 'name' => 'Div', 'props' => new stdClass, 'classMap' => ['m' => new stdClass]]],
            ],
        ]);

        foreach (['첫 요청', '캐시 요청'] as $label) {
            $data = json_decode($this->authRequest()
                ->getJson("/api/layouts/{$this->template->identifier}/probe_page.json?with_source_meta=1")
                ->assertOk()->getContent())->data;

            $original = $data->__editor->original;
            $this->assertEquals(new stdClass, $original->components[0]->props, "{$label}: props 자기 자신도 원문 그대로");
            $this->assertEquals(new stdClass, $original->components[0]->classMap->m, "{$label}: 규칙 밖 자리");
        }
    }

    /**
     * 빈 객체 액션 인자를 가진 확장 지점 확장을 만듭니다.
     *
     * @return LayoutExtension 생성된 확장
     */
    private function makeExtension(): LayoutExtension
    {
        return LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'probe_point',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-probe',
            'content' => [
                'extension_point' => 'probe_point',
                'components' => [[
                    'id' => 'ext_probe',
                    'type' => 'basic',
                    'name' => 'Button',
                    'actions' => [['type' => 'click', 'handler' => 'setState', 'params' => ['target' => 'local', 'selection' => new stdClass]]],
                ]],
            ],
        ]);
    }

    /**
     * 관리자 토큰을 실은 JSON 요청 헤더를 설정합니다.
     *
     * @return $this 헤더가 설정된 테스트 케이스
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 지정 권한을 붙인 관리자 역할을 가진 회원을 생성합니다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 관리자 회원
     */
    private function createAdminUser(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                ]
            )->id;
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'is_system' => true,
                'priority' => 0,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching($permissionIds);
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        return $user;
    }
}
