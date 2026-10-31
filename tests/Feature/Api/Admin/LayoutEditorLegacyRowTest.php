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
use App\Models\User;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 편집 대상 템플릿의 컴포넌트 목록에 없는 컴포넌트를 이미 담고 있는 행의 편집 (#712 W3·W6).
 *
 * 7.0 은 확장 조각을 모든 활성 템플릿에 무차별 등록했다. 그래서 서드파티 템플릿에는 그 템플릿에 없는 컴포넌트
 * (`Label`·`Icon`·`Modal` 등)를 쓴 조각 행이 남아 있고, 편집기는 그 행을 열면 「컴파일 오류 — 저장이 막힙니다」를
 * 띄우고 무수정 저장도 422 로 막았다. 결정(#712 Q2·D6, #703 의 템플릿 무관 컴파일에 맞춰 조정): 편집기 경로에서
 * **저장본에 이미 있던 미등록 템플릿 컴포넌트는 그 개수까지 허용**하고(비차단 경고로 알림), 사용자가 새로 쓴
 * 미등록 컴포넌트는 종전대로 422 다. 새 파일 만들기는 저장본이 없으므로 종전대로 엄격하다.
 *
 * 함께 고정하는 것(W6): 템플릿 `components.json` 이 스키마를 어기면 편집기 열기가 500 이었다 — 200 + 오류 정보로 연다.
 */
class LayoutEditorLegacyRowTest extends TestCase
{
    use DatabaseTransactions;

    private const TEMPLATE = 'probe-legacy_editor';

    private string $templateDir;

    private Template $template;

    private LayoutExtension $extension;

    private string $token;

    /**
     * 컴포넌트 목록이 작은(Div·Span) 임시 템플릿과, 목록에 없는 `Label` 을 쓴 7.0 시절 조각 행을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->templateDir = base_path('templates/'.self::TEMPLATE);
        File::ensureDirectoryExists($this->templateDir);
        $this->writeManifest([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'templateId' => self::TEMPLATE,
            'version' => '0.0.1',
            'components' => [
                'basic' => [['name' => 'Div', 'type' => 'basic'], ['name' => 'Span', 'type' => 'basic']],
                'composite' => [],
                'layout' => [],
            ],
        ]);

        $this->token = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit'])->createToken('editor')->plainTextToken;
        $this->template = Template::factory()->create(['identifier' => self::TEMPLATE, 'type' => 'user', 'status' => ExtensionStatus::Active->value]);

        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'probe_host',
            'content' => [
                'layout_name' => 'probe_host',
                'version' => '1.0.0',
                'components' => [['id' => 'probe_root', 'type' => 'basic', 'name' => 'Div']],
            ],
        ]);

        $this->extension = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'probe_host',
            'fragment_key' => 'legacy-probe',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-legacyprobe',
            'content' => [
                'target_layout' => 'probe_host',
                'priority' => 100,
                'injections' => [[
                    'target_id' => 'probe_root',
                    'position' => 'append_child',
                    'components' => [[
                        'type' => 'basic',
                        'name' => 'Div',
                        'children' => [['type' => 'basic', 'name' => 'Label', 'props' => ['text' => 'legacy label']]],
                    ]],
                ]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /**
     * 임시 템플릿 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->templateDir);

        parent::tearDown();
    }

    /**
     * 열기: 저장본에 있던 미등록 컴포넌트는 오류가 아니라 비차단 경고다.
     *
     * @scenario case=editor_open_legacy_row
     *
     * @effects legacy_row_opens_without_compile_error_and_with_kept_warning
     *
     * @return void 반환값 없음
     */
    public function test_open_has_no_compile_error_and_warns_kept_component(): void
    {
        $data = $this->getJson($this->extensionUrl().'?with_markup=1', $this->headers())->assertOk()->json('data');

        $this->assertNull($data['markup_compile_error'], '저장본에 이미 있던 컴포넌트로 저장을 막지 않는다');
        $this->assertStringContainsString('<label', $data['content_markup']);
        $this->assertContains('template_component_kept', array_column($data['markup_warnings'], 'code'));
    }

    /**
     * 무수정 저장은 200 이고, 저장 뒤 본문은 저장 전과 정규화 동일하다.
     *
     * @scenario case=editor_save_unmodified
     *
     * @effects legacy_row_unmodified_save_succeeds_and_preserves_content
     *
     * @return void 반환값 없음
     */
    public function test_unmodified_save_succeeds_and_preserves_content(): void
    {
        $before = $this->extension->fresh()->content;
        $markup = $this->openMarkup();

        $this->putJson($this->extensionUrl(), [
            'format' => 'markup',
            'content' => $markup,
            'expected_lock_version' => (int) $this->extension->fresh()->lock_version,
        ], $this->headers())->assertOk();

        $after = $this->extension->fresh()->content;
        $this->assertSame(
            LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($before)),
            LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize($after)),
            '무수정 저장이 본문을 바꿨다'
        );
        $this->assertStringNotContainsString('__g7', json_encode($after), '편집기 내부 표식이 저장 JSON 에 새면 안 된다');
    }

    /**
     * 사용자가 새로 쓴 미등록 컴포넌트는 종전대로 422 — 다른 이름도, 같은 이름을 하나 더 쓴 것도.
     *
     * @scenario case=editor_new_unregistered_rejected
     *
     * @effects newly_written_unregistered_component_is_still_rejected
     *
     * @return void 반환값 없음
     */
    public function test_newly_written_unregistered_component_is_rejected(): void
    {
        $markup = $this->openMarkup();
        $lock = (int) $this->extension->fresh()->lock_version;

        $withIcon = preg_replace('/(<label\b)/', '<icon name="x"/>$1', $markup, 1);
        $this->putJson($this->extensionUrl(), ['format' => 'markup', 'content' => $withIcon, 'expected_lock_version' => $lock], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('compile_error.code', 'component_not_in_registry');

        $twoLabels = preg_replace('/(<label\b[^>]*\/>)/', '$1$1', $markup, 1);
        $this->assertNotSame($markup, $twoLabels, '전제: 라벨을 하나 더 넣었다');
        $this->putJson($this->extensionUrl(), ['format' => 'markup', 'content' => $twoLabels, 'expected_lock_version' => $lock], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('compile_error.code', 'component_not_in_registry');
    }

    /**
     * 저장 없는 변환(markup-compile)도 같은 기준이다.
     *
     * @scenario case=editor_markup_compile_baseline
     *
     * @effects legacy_row_markup_compile_endpoint_uses_stored_baseline
     *
     * @return void 반환값 없음
     */
    public function test_markup_compile_endpoint_uses_stored_baseline(): void
    {
        $this->postJson($this->extensionUrl().'/markup-compile', ['markup' => $this->openMarkup()], $this->headers())->assertOk();
    }

    /**
     * 새 파일 만들기는 저장본이 없으므로 미등록 컴포넌트를 종전대로 막는다.
     *
     * @scenario case=editor_new_file_strict
     *
     * @effects new_layout_file_with_unregistered_component_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_new_layout_file_stays_strict(): void
    {
        $this->postJson('/api/admin/templates/'.self::TEMPLATE.'/layouts', [
            'name' => 'probe_new',
            'content' => "<layout name=\"probe_new\" version=\"1.0.0\">\n  <label text=\"x\"/>\n</layout>\n",
        ], $this->headers())->assertStatus(422);
    }

    /**
     * 템플릿 `components.json` 이 스키마를 어기면 열기는 200 + 오류 정보, 저장은 422 — 500 금지 (W6).
     *
     * @scenario case=editor_invalid_manifest
     *
     * @effects invalid_manifest_open_returns_error_payload_not_500
     *
     * @return void 반환값 없음
     */
    public function test_invalid_manifest_opens_with_error_payload_and_save_is_422(): void
    {
        $this->writeManifest(['components' => ['basic' => 'not-an-array', 'composite' => [], 'layout' => []]]);

        $layout = $this->getJson('/api/admin/templates/'.self::TEMPLATE.'/layouts/probe_host?with_markup=1', $this->headers())
            ->assertOk()->json('data');
        $this->assertSame('', $layout['content_markup']);
        $this->assertStringStartsWith('manifest_', (string) ($layout['markup_compile_error']['code'] ?? ''));

        $extension = $this->getJson($this->extensionUrl().'?with_markup=1', $this->headers())->assertOk()->json('data');
        $this->assertStringStartsWith('manifest_', (string) ($extension['markup_compile_error']['code'] ?? ''));

        $this->putJson('/api/admin/templates/'.self::TEMPLATE.'/layouts/probe_host', [
            'format' => 'markup',
            'content' => "<layout name=\"probe_host\" version=\"1.0.0\">\n  <div id=\"probe_root\"/>\n</layout>\n",
            'expected_lock_version' => 0,
        ], $this->headers())->assertStatus(422);
    }

    /**
     * 편집기가 여는 마크업을 가져옵니다.
     *
     * @return string 역변환 마크업
     */
    private function openMarkup(): string
    {
        return (string) $this->getJson($this->extensionUrl().'?with_markup=1', $this->headers())->assertOk()->json('data.content_markup');
    }

    /**
     * 조각 행 API 주소.
     *
     * @return string 주소
     */
    private function extensionUrl(): string
    {
        return '/api/admin/templates/'.self::TEMPLATE.'/layout-extensions/'.$this->extension->id;
    }

    /**
     * 임시 템플릿의 `components.json` 을 씁니다.
     *
     * @param  array<string, mixed>  $manifest  매니페스트
     * @return void 반환값 없음
     */
    private function writeManifest(array $manifest): void
    {
        File::put($this->templateDir.'/components.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 인증 헤더.
     *
     * @return array<string, string> 헤더
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    /**
     * 주어진 권한의 관리자 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자
     * @return User 사용자
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
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'legacy_editor_'.uniqid(),
            'name' => json_encode(['ko' => '레거시 편집 테스트', 'en' => 'Legacy editor test']),
            'type' => 'admin',
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role->id);

        return $user;
    }
}
