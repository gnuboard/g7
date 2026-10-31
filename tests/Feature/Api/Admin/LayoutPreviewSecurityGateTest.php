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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 미리보기 생성·마크업 변환 경로의 저장측 보안 게이트 (KVE-2026-2350)
 *
 * 미리보기 생성 요청 2종은 content 를 저장해 미리보기 URL 로 렌더하면서도 SafeLayoutExpressions·
 * NoExternalUrls 를 부착하지 않은 **게이트 없는 저장 경로**였다. 권한도 `core.templates.read` 라,
 * 읽기 권한만 가진 관리자가 호스트 capability 탈출 표현식을 담은 레이아웃을 렌더시킬 수 있었다.
 * 마크업 변환(`markup-compile`)은 저장하지 않지만 편집기가 그 결과를 미리보기·저장에 그대로 쓴다.
 *
 * 스키마를 새로 만들지 않는다(DatabaseTransactions) — 식별자에 실행 고유 접미어를 붙이고
 * 공용 행(템플릿·권한)은 firstOrCreate 로 받는다.
 */
class LayoutPreviewSecurityGateTest extends TestCase
{
    use DatabaseTransactions;

    private string $layoutName;

    private LayoutExtension $extension;

    private string $readerToken;

    /**
     * 템플릿·레이아웃·확장 조각과 읽기 권한 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $this->layoutName = 'gate_probe_'.$suffix;

        // 표(`components.json`)가 실재하는 식별자 — 마크업 컴파일이 그 표로 돈다.
        $template = Template::firstOrCreate(
            ['identifier' => 'sirsoft-admin_basic'],
            Template::factory()->make(['identifier' => 'sirsoft-admin_basic', 'type' => 'admin', 'status' => ExtensionStatus::Active->value])->getAttributes(),
        );

        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => $this->layoutName,
            'content' => [
                'layout_name' => $this->layoutName,
                'version' => '1.0.0',
                'components' => [['id' => 'probe_root', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'p-4']]],
            ],
        ]);

        $this->extension = LayoutExtension::create([
            'template_id' => $template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => $this->layoutName,
            'fragment_key' => 'gate-overlay-'.$suffix,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-probe',
            'content' => [
                'target_layout' => $this->layoutName,
                'priority' => 100,
                'injections' => [['target_id' => 'probe_root', 'position' => 'append_child', 'components' => [['type' => 'basic', 'name' => 'Div']]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);

        $this->readerToken = $this->createAdminUser(['core.templates.read'], $suffix)
            ->createToken('reader')->plainTextToken;
    }

    /**
     * 신고 payload 가 top-level slots 에 담긴 레이아웃 content 를 만듭니다.
     *
     * @return array<string, mixed> 레이아웃 content
     */
    private function payloadContent(): array
    {
        return [
            'layout_name' => $this->layoutName,
            'version' => '1.0.0',
            'extends' => '_admin_base',
            'slots' => ['content' => [[
                'id' => 'securitytest_expression_probe',
                'type' => 'basic',
                'name' => 'Img',
                'props' => ['src' => '/__missing__.png'],
                'actions' => [[
                    'type' => 'error',
                    'handler' => 'setState',
                    'params' => [
                        'target' => 'local',
                        'probe' => "{{\$event.target.ownerDocument.defaultView.localStorage.getItem('auth_token')}}",
                    ],
                ]],
            ]]],
        ];
    }

    /**
     * 읽기 권한 관리자가 탈출 payload 로 미리보기를 만들지 못하는지(422·토큰 없음) 검증합니다.
     *
     * @scenario case=host_graph_escape
     *
     * @effects preview_and_compile_are_gated, storage_gate_rejects_host_graph_payload
     *
     * @return void 반환값 없음
     */
    public function test_layout_preview_rejects_host_graph_payload(): void
    {
        $response = $this->postJson(
            "/api/admin/templates/sirsoft-admin_basic/layouts/{$this->layoutName}/preview",
            ['content' => $this->payloadContent()],
            $this->headers()
        )->assertStatus(422);

        $this->assertArrayHasKey('content', $response->json('errors'));
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 레이아웃 미리보기가 트리 어디에 있든 실행 스킴을 거부하는지 검증합니다.
     *
     * @effects preview_and_compile_are_gated, executable_scheme_rejected_anywhere_in_tree
     *
     * @return void 반환값 없음
     */
    public function test_layout_preview_rejects_executable_scheme_in_state(): void
    {
        $this->postJson(
            "/api/admin/templates/sirsoft-admin_basic/layouts/{$this->layoutName}/preview",
            ['content' => [
                'layout_name' => $this->layoutName,
                'version' => '1.0.0',
                'state' => ['next' => 'javascript:alert(document.domain)'],
                'components' => [],
            ]],
            $this->headers()
        )->assertStatus(422);
    }

    /**
     * 확장 조각 미리보기도 같은 게이트를 거치는지 검증합니다.
     *
     * @effects preview_and_compile_are_gated
     *
     * @return void 반환값 없음
     */
    public function test_extension_preview_rejects_host_graph_payload(): void
    {
        $response = $this->postJson(
            "/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}/preview",
            ['content' => [
                'target_layout' => $this->layoutName,
                'priority' => 100,
                'injections' => [[
                    'target_id' => 'probe_root',
                    'position' => 'append_child',
                    'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['title' => "{{\$event.target.ownerDocument.defaultView.fetch('/api/x')}}"]]],
                ]],
            ]],
            $this->headers()
        )->assertStatus(422);

        $this->assertNull($response->json('data.token'));
    }

    /**
     * 정상 content 미리보기는 종전대로 토큰을 발급하는지(정상 회귀) 검증합니다.
     *
     * @scenario case=safe_expression
     *
     * @effects preview_and_compile_are_gated, bundled_layouts_pass_strengthened_rules
     *
     * @return void 반환값 없음
     */
    public function test_regular_preview_still_issues_token(): void
    {
        $response = $this->postJson(
            "/api/admin/templates/sirsoft-admin_basic/layouts/{$this->layoutName}/preview",
            ['content' => [
                'layout_name' => $this->layoutName,
                'version' => '1.0.0',
                'components' => [['id' => 'probe_root', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => "{{(items ?? []).map(i => i.name).join(',')}}"]]],
            ]],
            $this->headers()
        )->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * 마크업 변환이 위험 표현식을 담은 산출 JSON 을 돌려주지 않는지(422) 검증합니다.
     *
     * @effects preview_and_compile_are_gated
     *
     * @return void 반환값 없음
     */
    public function test_markup_compile_rejects_host_graph_expression(): void
    {
        $response = $this->postJson(
            "/api/admin/templates/sirsoft-admin_basic/layouts/{$this->layoutName}/markup-compile",
            ['markup' => "<layout name=\"{$this->layoutName}\" version=\"1.0.0\"><div id=\"probe_root\" class=\"{{w.fetch('/api/x')}}\"/></layout>"],
            $this->headers()
        )->assertStatus(422);

        $this->assertArrayHasKey('content', $response->json('errors'));
        $this->assertNull($response->json('data.content'));
    }

    /**
     * 확장 조각 마크업 변환도 같은 게이트를 거치는지 검증합니다.
     *
     * @effects preview_and_compile_are_gated
     *
     * @return void 반환값 없음
     */
    public function test_extension_markup_compile_rejects_host_graph_expression(): void
    {
        $this->postJson(
            "/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}/markup-compile",
            ['markup' => "<extension target-layout=\"{$this->layoutName}\"><inject target-id=\"probe_root\" position=\"append_child\"><div class=\"{{el.ownerDocument.title}}\"/></inject></extension>"],
            $this->headers()
        )->assertStatus(422);
    }

    /**
     * 정상 마크업 변환은 종전대로 200 JSON 을 돌려주는지(정상 회귀) 검증합니다.
     *
     * @effects preview_and_compile_are_gated
     *
     * @return void 반환값 없음
     */
    public function test_regular_markup_compile_still_returns_json(): void
    {
        $ok = $this->postJson(
            "/api/admin/templates/sirsoft-admin_basic/layouts/{$this->layoutName}/markup-compile",
            ['markup' => "<layout name=\"{$this->layoutName}\" version=\"1.0.0\"><div id=\"probe_root\" class=\"x\"/></layout>"],
            $this->headers()
        )->assertOk();

        $this->assertSame('x', json_decode($ok->json('data.content'), true)['components'][0]['props']['className'] ?? null);
    }

    /**
     * 인증 헤더를 만듭니다.
     *
     * @return array<string, string> 요청 헤더
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->readerToken, 'Accept' => 'application/json'];
    }

    /**
     * 지정 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @param  string  $suffix  역할 식별자 고유 접미어
     * @return User 관리자
     */
    private function createAdminUser(array $permissions, string $suffix): User
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
            'identifier' => 'preview_gate_'.$suffix,
            'name' => json_encode(['ko' => '미리보기 게이트 테스트', 'en' => 'Preview gate test']),
            'type' => 'admin',
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role->id);

        return $user;
    }
}
