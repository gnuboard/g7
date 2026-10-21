<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Models\LayoutExtension;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use stdClass;
use Tests\TestCase;

/**
 * 레이아웃 쓰기 경로 빈 객체 보존 테스트 (#758)
 *
 * 서빙은 DB 원문을 읽는다. 그 원문을 편집기 저장·버전 복원·이름 바꾸기·미리보기가 `json_decode(true)` 나
 * `array` 캐스트로 읽어 다시 쓰면 `{}` 가 `[]` 로 굳고, 이후 서빙도 `[]` 를 낸다
 * (`shop/show` 를 위지윅으로 한 번 저장하면 상품 상세 옵션 선택·상품평 필터 상태가 목록으로 시작한다).
 *
 * 트러블슈팅 `[case:backend-57]` 의 회귀 그물이다.
 *
 * @see tests/scenarios/layout-write-empty-object.yaml
 */
class LayoutEmptyObjectWritePathsTest extends TestCase
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

    /** 본문에 넣은 빈 객체 수 */
    private const EMPTY_OBJECTS = 4;

    private Template $template;

    private string $token;

    /**
     * 편집 권한 관리자 토큰과 활성 사용자 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit'])
            ->createToken('editor')->plainTextToken;

        $this->template = Template::factory()->create([
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * JSON 문자열로 보낸 위지윅 저장 본문의 빈 객체가 DB 원문과 서빙 응답에 남는다.
     *
     * @scenario path=json_string
     *
     * @effects write_path_keeps_empty_object_json_string
     *
     * @return void 반환값 없음
     */
    public function test_json_string_save_keeps_empty_objects(): void
    {
        $layout = $this->makeLayout('probe_page');

        $this->authRequest()->putJson($this->layoutUrl('probe_page'), [
            'expected_lock_version' => 0,
            'content' => json_encode($this->layoutContent('probe_page', '저장본')),
        ])->assertOk();

        $this->assertRawKeepsEmptyObjects($layout->fresh(), '위지윅 저장(문자열 본문)');
        $this->assertServedKeepsEmptyObjects('probe_page');
    }

    /**
     * JSON 객체 본문으로 보낸 저장도 빈 객체를 지킨다 — Laravel 이 본문을 연관 배열로 해석하며 잃던 자리.
     *
     * @scenario path=json_object
     *
     * @effects write_path_keeps_empty_object_json_object
     *
     * @return void 반환값 없음
     */
    public function test_json_object_save_keeps_empty_objects(): void
    {
        $layout = $this->makeLayout('probe_page');

        $this->authRequest()->putJson($this->layoutUrl('probe_page'), [
            'expected_lock_version' => 0,
            'content' => $this->layoutContent('probe_page', '저장본'),
        ])->assertOk();

        $this->assertRawKeepsEmptyObjects($layout->fresh(), '위지윅 저장(객체 본문)');
    }

    /**
     * `array` 규칙이 걸린 자리의 빈 객체(`"meta": {}`·`inject_props` 의 `props: {}`·`state: {}`)도 검증을 통과하고 그대로 저장된다.
     *
     * 검증은 종전처럼 배열로 보므로 422 가 나지 않고, 저장본에는 원문의 `{}` 가 남는다.
     *
     * @scenario path=json_object
     *
     * @effects write_path_keeps_empty_object_json_object, empty_object_in_array_rule_position_accepted
     *
     * @return void 반환값 없음
     */
    public function test_empty_object_in_array_rule_positions_is_accepted(): void
    {
        $layout = $this->makeLayout('probe_page');
        $content = $this->layoutContent('probe_page', '저장본');
        $content['meta'] = new stdClass;

        $this->authRequest()->putJson($this->layoutUrl('probe_page'), [
            'expected_lock_version' => 0,
            'content' => $content,
        ])->assertOk();

        // 하위 규칙이 걸린 빈 섹션은 Laravel validated() 가 원래부터 싣지 않는다(종전 `[]` 도 같다) — 422 없이 저장되고 `[]` 로 굳지 않으면 된다
        $this->assertStringNotContainsString('"meta":[]', (string) $layout->fresh()->getRawOriginal('content'));

        $overlay = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'probe_page',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-probe',
            'content' => ['target_layout' => 'probe_page', 'injections' => []],
            'lock_version' => 0,
        ]);

        $this->authRequest()->putJson($this->extensionUrl($overlay->id), [
            'expected_lock_version' => 0,
            'content' => [
                'target_layout' => 'probe_page',
                'state' => new stdClass,
                'injections' => [['target_id' => 'root', 'position' => 'inject_props', 'props' => new stdClass]],
            ],
        ])->assertOk();

        $raw = (string) $overlay->fresh()->getRawOriginal('content');
        $this->assertStringContainsString('"state":{}', $raw);
        $this->assertStringContainsString('"props":{}', $raw);
    }

    /**
     * 확장 편집 모드 저장(객체 본문)이 확장의 빈 객체를 지킨다.
     *
     * @scenario path=json_object
     *
     * @effects write_path_keeps_empty_object_json_object
     *
     * @return void 반환값 없음
     */
    public function test_extension_json_object_save_keeps_empty_objects(): void
    {
        $extension = $this->makeExtension();

        $this->authRequest()->putJson($this->extensionUrl($extension->id), [
            'expected_lock_version' => 0,
            'content' => $this->extensionContent('저장본'),
        ])->assertOk();

        $this->assertRawKeepsEmptyObjects($extension->fresh(), '확장 저장(객체 본문)', 2);
    }

    /**
     * 첫 저장이 남기는 「수정 전」 기준 버전이 원본의 빈 객체를 지킨다 — 그 버전을 복원해도 `{}` 다.
     *
     * @scenario path=baseline
     *
     * @effects write_path_keeps_empty_object_baseline_version
     *
     * @return void 반환값 없음
     */
    public function test_baseline_version_keeps_empty_objects(): void
    {
        $layout = $this->makeLayout('probe_page');
        $extension = $this->makeExtension();

        $this->authRequest()->putJson($this->layoutUrl('probe_page'), [
            'expected_lock_version' => 0,
            'content' => json_encode($this->layoutContent('probe_page', '저장본')),
        ])->assertOk();

        $this->authRequest()->putJson($this->extensionUrl($extension->id), [
            'expected_lock_version' => 0,
            'content' => json_encode($this->extensionContent('저장본')),
        ])->assertOk();

        $baseline = TemplateLayoutVersion::where('layout_id', $layout->id)->where('version', 1)->firstOrFail();
        $this->assertRawKeepsEmptyObjects($baseline, '레이아웃 기준 버전');

        $extensionBaseline = TemplateLayoutExtensionVersion::where('extension_id', $extension->id)->where('version', 1)->firstOrFail();
        $this->assertRawKeepsEmptyObjects($extensionBaseline, '확장 기준 버전', 2);
    }

    /**
     * 레이아웃 버전 복원이 버전 행의 빈 객체를 본 행에 그대로 옮긴다.
     *
     * @scenario path=restore
     *
     * @effects write_path_keeps_empty_object_restore
     *
     * @return void 반환값 없음
     */
    public function test_layout_version_restore_keeps_empty_objects(): void
    {
        $layout = $this->makeLayout('probe_page');
        $version = TemplateLayoutVersion::create([
            'layout_id' => $layout->id,
            'version' => 1,
            'content' => $this->layoutContent('probe_page', '옛 버전'),
            'changes_summary' => ['added' => 0, 'removed' => 0, 'char_diff' => 0],
        ]);

        $this->authRequest()
            ->postJson($this->layoutUrl('probe_page')."/versions/{$version->id}/restore")
            ->assertOk();

        $this->assertRawKeepsEmptyObjects($layout->fresh(), '레이아웃 복원 본 행');
        $restoredVersion = TemplateLayoutVersion::where('layout_id', $layout->id)->where('version', 2)->firstOrFail();
        $this->assertRawKeepsEmptyObjects($restoredVersion, '레이아웃 복원 버전 행');
        $this->assertServedKeepsEmptyObjects('probe_page');
    }

    /**
     * 확장 버전 복원도 빈 객체를 지킨다.
     *
     * @scenario path=restore
     *
     * @effects write_path_keeps_empty_object_restore
     *
     * @return void 반환값 없음
     */
    public function test_extension_version_restore_keeps_empty_objects(): void
    {
        $extension = $this->makeExtension();
        $version = TemplateLayoutExtensionVersion::create([
            'extension_id' => $extension->id,
            'version' => 1,
            'content' => $this->extensionContent('옛 버전'),
            'changes_summary' => ['added' => 0, 'removed' => 0, 'char_diff' => 0],
        ]);

        $this->authRequest()
            ->postJson("/api/admin/templates/{$this->template->identifier}/layout-extensions/{$extension->id}/versions/{$version->id}/restore")
            ->assertOk();

        $this->assertRawKeepsEmptyObjects($extension->fresh(), '확장 복원 본 행', 2);
    }

    /**
     * 레이아웃 이름만 바꿔도 본문의 빈 객체가 그대로다.
     *
     * @scenario path=rename
     *
     * @effects write_path_keeps_empty_object_rename
     *
     * @return void 반환값 없음
     */
    public function test_rename_keeps_empty_objects(): void
    {
        $layout = $this->makeLayout('probe_page');

        $this->authRequest()->patchJson($this->layoutUrl('probe_page').'/rename', [
            'new_name' => 'probe_renamed',
            'expected_lock_version' => 0,
        ])->assertOk();

        $this->assertRawKeepsEmptyObjects($layout->fresh(), '이름 바꾸기');
        $this->assertServedKeepsEmptyObjects('probe_renamed');
    }

    /**
     * 미리보기 저장 → 미리보기 서빙에서도 빈 객체가 `{}` 로 나간다 (빈 루트 섹션 포함).
     *
     * @scenario path=preview
     *
     * @effects write_path_keeps_empty_object_preview
     *
     * @return void 반환값 없음
     */
    public function test_preview_keeps_empty_objects(): void
    {
        $this->makeLayout('probe_page');
        $content = $this->layoutContent('probe_page', '미리보기');
        $content['meta'] = new stdClass;

        $token = $this->authRequest()->postJson($this->layoutUrl('probe_page').'/preview', [
            'content' => $content,
        ])->assertOk()->json('data.token');

        $body = $this->getJson("/api/layouts/preview/{$token}.json")->assertOk()->getContent();

        $this->assertStringContainsString('"filters":{}', $body, '미리보기 initLocal 빈 객체');
        $this->assertStringContainsString('"query":{}', $body, '미리보기 액션 인자 빈 객체');
        $this->assertStringContainsString('"meta":{}', $body, '미리보기 빈 루트 섹션');
    }

    /**
     * 확장 미리보기도 편집 중인 확장의 빈 객체를 `{}` 로 낸다.
     *
     * @scenario path=preview
     *
     * @effects write_path_keeps_empty_object_preview
     *
     * @return void 반환값 없음
     */
    public function test_extension_preview_keeps_empty_objects(): void
    {
        // 확장은 출처 모듈이 활성일 때만 적용된다
        $activeModule = $this->createMock(ModuleInterface::class);
        $activeModule->method('getIdentifier')->willReturn('sirsoft-probe');
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn(['sirsoft-probe' => $activeModule]);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $this->makeLayout('probe_page', withExtensionPoint: true);
        $extension = $this->makeExtension();

        $token = $this->authRequest()->postJson($this->extensionUrl($extension->id).'/preview', [
            'content' => $this->extensionContent('미리보기'),
            'preview_layout' => 'probe_page',
        ])->assertOk()->json('data.token');

        $body = $this->getJson("/api/layouts/preview/{$token}.json")->assertOk()->getContent();

        $this->assertStringContainsString('ext_probe', $body, '확장이 미리보기에 적용되지 않았다');
        $this->assertStringContainsString('"selection":{}', $body, '확장 액션 인자 빈 객체');
    }

    /**
     * 빈 객체 4곳을 담은 레이아웃 본문을 만듭니다 (상태 초기값·액션 인자·대체값·props 값).
     *
     * @param  string  $name  레이아웃 이름
     * @param  string  $text  본문 텍스트 (저장마다 바꿔 변경을 만든다)
     * @return array<string, mixed> 레이아웃 본문
     */
    private function layoutContent(string $name, string $text): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => $name,
            'initLocal' => ['filters' => new stdClass, 'items' => []],
            'init_actions' => [[
                'handler' => 'navigate',
                'params' => ['path' => '/shop', 'query' => new stdClass],
            ]],
            'data_sources' => [[
                'id' => 'stats',
                'type' => 'api',
                'endpoint' => '/api/stats',
                'fallback' => ['data' => ['targets' => new stdClass]],
            ]],
            'components' => [[
                'id' => 'root',
                'type' => 'basic',
                'name' => 'Div',
                'props' => ['dataMap' => new stdClass],
                'text' => $text,
            ]],
        ];
    }

    /**
     * 빈 객체 2곳을 담은 확장(extension_point) 본문을 만듭니다.
     *
     * @param  string  $text  본문 텍스트
     * @return array<string, mixed> 확장 본문
     */
    private function extensionContent(string $text): array
    {
        return [
            'extension_point' => 'probe_point',
            'components' => [[
                'id' => 'ext_probe',
                'type' => 'basic',
                'name' => 'Button',
                'props' => ['dataMap' => new stdClass],
                'text' => $text,
                'actions' => [[
                    'type' => 'click',
                    'handler' => 'setState',
                    'params' => ['target' => 'local', 'selection' => new stdClass],
                ]],
            ]],
        ];
    }

    /**
     * 빈 객체를 담은 템플릿 출처 레이아웃을 만듭니다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  bool  $withExtensionPoint  확장 지점 노드를 넣을지
     * @return TemplateLayout 생성된 레이아웃
     */
    private function makeLayout(string $name, bool $withExtensionPoint = false): TemplateLayout
    {
        $content = $this->layoutContent($name, '원본');

        if ($withExtensionPoint) {
            $content['components'][] = ['id' => 'point_host', 'type' => 'extension_point', 'name' => 'probe_point'];
        }

        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => $name,
            'source_type' => LayoutSourceType::Template->value,
            'source_identifier' => $this->template->identifier,
            'lock_version' => 0,
            'content' => $content,
        ]);
    }

    /**
     * 빈 객체를 담은 확장 지점 확장을 만듭니다.
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
            'content' => $this->extensionContent('원본'),
            'lock_version' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * 모델의 DB 원문에 빈 객체가 모두 남았는지 검증합니다.
     *
     * @param  Model  $model  검사할 행
     * @param  string  $label  실패 메시지 라벨
     * @param  int  $expected  기대 빈 객체 수
     * @return void 반환값 없음
     */
    private function assertRawKeepsEmptyObjects($model, string $label, int $expected = self::EMPTY_OBJECTS): void
    {
        $raw = (string) $model->getRawOriginal('content');

        $this->assertSame($expected, substr_count($raw, '{}'), "{$label}: DB 원문의 빈 객체 수 — {$raw}");
        $this->assertStringNotContainsString('"dataMap":[]', $raw, "{$label}: props 값 빈 객체가 배열로 굳었다");
    }

    /**
     * 서빙 응답에 상태 초기값·액션 인자의 빈 객체가 `{}` 로 나가는지 검증합니다.
     *
     * @param  string  $name  레이아웃 이름
     * @return void 반환값 없음
     */
    private function assertServedKeepsEmptyObjects(string $name): void
    {
        $body = $this->getJson("/api/layouts/{$this->template->identifier}/{$name}.json")->assertOk()->getContent();

        $this->assertStringContainsString('"filters":{}', $body, '서빙 initLocal 빈 객체');
        $this->assertStringContainsString('"items":[]', $body, '서빙 빈 목록은 목록 그대로');
        $this->assertStringContainsString('"query":{}', $body, '서빙 액션 인자 빈 객체');
    }

    /**
     * 레이아웃 편집 API 주소를 만듭니다.
     *
     * @param  string  $name  레이아웃 이름
     * @return string API 주소
     */
    private function layoutUrl(string $name): string
    {
        return "/api/admin/templates/{$this->template->identifier}/layouts/{$name}";
    }

    /**
     * 확장 편집 API 주소를 만듭니다.
     *
     * @param  int  $id  확장 ID
     * @return string API 주소
     */
    private function extensionUrl(int $id): string
    {
        return "/api/admin/templates/{$this->template->identifier}/layout-extensions/{$id}";
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
