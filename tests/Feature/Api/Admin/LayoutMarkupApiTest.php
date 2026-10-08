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
use App\Support\LayoutMarkup\EmptyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 코드 편집기 HTML(.g7.html) 기본화의 API 계약 (#476).
 *
 *  - `GET …/layouts/{name}?with_markup=1` · `GET …/layout-extensions/{id}?with_markup=1` — 역변환 결과 필드
 *  - `PUT` 저장 `format=markup` — 컴파일된 JSON 이 DB 에 실리고 `lock_version` 이 오른다 · 컴파일 오류는 422 `compile_error`
 *  - `POST …/preview` `format=markup`
 *  - `POST …/markup-compile` — 저장 없이 200/422, 권한 `core.templates.read` 만으로 가능(저장은 불가)
 */
class LayoutMarkupApiTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    private TemplateLayout $layout;

    private LayoutExtension $extension;

    private string $editorToken;

    private string $readerToken;

    /**
     * 편집·읽기 권한 관리자 토큰과 프로브 레이아웃·레이아웃 확장을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->editorToken = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit'])
            ->createToken('editor')->plainTextToken;
        $this->readerToken = $this->createAdminUser(['core.templates.read'])
            ->createToken('reader')->plainTextToken;

        // 표(`components.json`)가 실재하는 식별자 — 역변환·컴파일이 그 표로 돈다.
        $this->template = Template::factory()->create(['identifier' => 'sirsoft-admin_basic', 'type' => 'admin', 'status' => ExtensionStatus::Active->value]);

        $this->layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'admin_probe',
            'content' => [
                'layout_name' => 'admin_probe',
                'version' => '1.0.0',
                'named_actions' => ['probe' => ['comment' => '한 번만 찍히는 주석', 'handler' => 'setState', 'params' => ['target' => 'local', 'a' => 1]]],
                'components' => [['id' => 'probe_root', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'p-4']]],
            ],
        ]);

        $this->extension = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'admin_probe',
            'fragment_key' => 'probe-overlay',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-probe',
            'content' => [
                'target_layout' => 'admin_probe',
                'priority' => 100,
                'injections' => [['target_id' => 'probe_root', 'position' => 'append_child', 'components' => [['type' => 'basic', 'name' => 'Div']]]],
            ],
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /**
     * 편집기가 화면을 여는 지점 — `action` 축(유휴 변환·저장·미리보기) 어디에도 속하지 않아 조합 마킹이 없다.
     *
     * @effects with_markup_returns_decompiled_markup_and_lint_fields
     *
     * @return void 반환값 없음
     */
    public function test_show_with_markup_returns_markup_fields_for_layout_and_extension(): void
    {
        $plain = $this->getJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe', $this->headers($this->editorToken))
            ->assertOk();
        $this->assertArrayNotHasKey('content_markup', $plain->json('data'), '기본 응답은 무변경');

        $response = $this->getJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe?with_markup=1', $this->headers($this->editorToken))
            ->assertOk();

        $data = $response->json('data');
        $this->assertStringStartsWith('<layout name="admin_probe"', $data['content_markup']);
        $this->assertSame(1, substr_count($data['content_markup'], '한 번만 찍히는 주석'));
        $this->assertSame([], $data['markup_escapes']);
        $this->assertIsArray($data['markup_warnings']);
        $this->assertNull($data['markup_compile_error']);

        $ext = $this->getJson("/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}?with_markup=1", $this->headers($this->editorToken))
            ->assertOk()
            ->json('data');
        $this->assertStringStartsWith('<extension target-layout="admin_probe"', $ext['content_markup']);
        $this->assertSame('probe-overlay', $ext['fragment_key']);
    }

    /**
     * 마크업 형식으로 저장하면 컴파일된 JSON 이 DB 에 실리고 lock_version 이 오르는지 검증합니다.
     *
     * @scenario branch=layout, input=valid_edit, action=save
     *
     * @effects markup_save_compiles_to_json_and_bumps_lock_version
     *
     * @return void 반환값 없음
     */
    public function test_saving_markup_compiles_to_json_and_bumps_lock_version(): void
    {
        $markup = <<<'HTML'
        <layout name="admin_probe" version="1.0.0">
          <!-- 한 번만 찍히는 주석 -->
          <action name="probe"><set-state target="local" a="2"/></action>
          <div id="probe_root" class="p-8">저장 실측</div>
        </layout>
        HTML;

        $response = $this->putJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe', [
            'format' => 'markup',
            'content' => $markup,
            'expected_lock_version' => (int) $this->layout->lock_version,
        ], $this->headers($this->editorToken))->assertOk();

        $this->assertSame([], $response->json('data.markup_warnings'));
        $this->assertSame((int) $this->layout->lock_version + 1, $response->json('data.lock_version'));

        $stored = $this->layout->fresh()->content;
        $this->assertSame('p-8', $stored['components'][0]['props']['className'] ?? null, 'DB content 는 컴파일된 JSON 이다');
        $this->assertSame('한 번만 찍히는 주석', $stored['named_actions']['probe']['comment'] ?? null);
    }

    /**
     * 저장된 `{}` 가 편집기 본문에도 `{}` 로 보이는지 (빈 map ↔ 빈 list 구분 보존).
     *
     * Eloquent 의 `'content' => 'array'` 캐스트는 `{}` 와 `[]` 를 모두 PHP `[]` 로 만든다. 그 배열을
     * 그대로 역변환하면 작성자가 적은 `{}` 가 편집기에 `[]` 로 **틀리게 보이고**, 그 상태로 저장하면
     * DB 의 `{}` 가 실제로 `[]` 가 된다. 그 값이 API 본문·질의로 실려 가면 서버 검증이 대상을
     * 찾지 못해 422 또는 침묵 미적용이 된다 — {@see EmptyMap} 이 있는 이유다.
     *
     * @scenario branch=layout, input=valid_edit, action=idle_compile
     *
     * @effects with_markup_preserves_empty_map_vs_empty_list
     *
     * @return void 반환값 없음
     */
    public function test_show_with_markup_keeps_empty_map_distinct_from_empty_list(): void
    {
        // `{}` 와 `[]` 를 한 문서에 나란히 둔다. 모델을 거치면 캐스트가 둘을 같게 만들어 애초에
        // 구분을 심을 수 없으므로, **원본 JSON 문자열을 직접 써서** DB 상태를 만든다.
        DB::table('template_layouts')
            ->where('id', $this->layout->id)
            ->update(['content' => '{"layout_name":"admin_probe","initLocal":{},"components":[{"type":"basic","name":"Div","id":"probe_root","props":{"emptyMap":{},"emptyList":[]}}]}']);

        $response = $this->getJson(
            '/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe?with_markup=1',
            $this->headers($this->editorToken)
        )->assertOk();

        $markup = (string) $response->json('data.content_markup');

        // 빈 map 은 타입 리터럴 속성으로, 빈 list 는 `<prop>` 자식으로 나온다 — 표기 자리는 갈리지만
        // **`{}` 와 `[]` 가 서로 뒤바뀌지 않는 것**이 이 케이스가 잠그는 것이다.
        $this->assertStringContainsString(':emptyMap="{}"', $markup, '빈 map 은 `{}` 로 보여야 한다');
        $this->assertStringContainsString('<prop name="emptyList">[]</prop>', $markup, '빈 list 는 `[]` 로 남아야 한다');
        $this->assertStringNotContainsString(':emptyMap="[]"', $markup, '빈 map 이 빈 list 로 뭉개지면 안 된다');
        $this->assertStringContainsString('<init-local/>', $markup, '빈 `initLocal` 은 태그로 남아야 컴파일러가 기본값으로 덮지 않는다');
    }

    /**
     * 다른 화면을 물려받아 제목·초기값만 바꾸는 화면(슬롯·컴포넌트 없음)을 저장할 수 있다.
     *
     * 저장 요청의 정리 단계가 «슬롯이 하나도 없는 extends 문서» 에 빈 `content` 슬롯을 지어 넣어, 부모가
     * `content` 슬롯을 정의하지 않으면 「슬롯 "content"은 부모 레이아웃에 정의되지 않았습니다.」 로 막혔다
     * (실측 2026-10-06, 7.1.0 전수 실측 I1: `admin_language_packs` → `admin_language_pack_list` 상속, 제목만
     * 바꿔 저장 422). 부모가 그 슬롯을 정의했다면 빈 슬롯이 부모 본문을 비워 버리는 더 나쁜 결과가 된다.
     *
     * @scenario branch=layout, input=valid_edit, action=save
     *
     * @effects extends_only_layout_saves_without_synthesized_slot
     *
     * @return void 반환값 없음
     */
    public function test_saving_extends_only_layout_does_not_synthesize_a_content_slot(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'admin_probe_parent',
            'content' => [
                'layout_name' => 'admin_probe_parent',
                'version' => '1.0.0',
                'components' => [['id' => 'parent_root', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'p-4']]],
            ],
        ]);
        $child = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'admin_probe_child',
            'extends' => 'admin_probe_parent',
            'content' => [
                'layout_name' => 'admin_probe_child',
                'version' => '1.0.0',
                'extends' => 'admin_probe_parent',
                'meta' => ['title' => '옛 제목'],
                'initLocal' => ['scopeContext' => 'all'],
            ],
        ]);

        $markup = <<<'HTML'
        <layout name="admin_probe_child" version="1.0.0" extends="admin_probe_parent">
          <meta title="새 제목"/>
          <init-local>
            <field name="scopeContext" value="all"/>
          </init-local>
        </layout>
        HTML;

        $this->putJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe_child', [
            'format' => 'markup',
            'content' => $markup,
            'expected_lock_version' => (int) $child->lock_version,
        ], $this->headers($this->editorToken))->assertOk();

        $stored = $child->fresh()->content;
        $this->assertSame('새 제목', $stored['meta']['title'] ?? null);
        $this->assertArrayNotHasKey('slots', $stored, '물려받기만 하는 화면에 빈 슬롯을 지어 넣지 않는다');
        $this->assertSame('all', $stored['initLocal']['scopeContext'] ?? null);
    }

    /**
     * 편집기 JSON 탭이 처음 여는 `data.content` 도 저장된 `{}` 를 `{}` 로 보여 준다.
     *
     * JSON 탭은 타이핑 전에는 이 필드를, 타이핑 뒤에는 컴파일 결과를 같은 자리에 보여 준다. 이 필드만
     * 배열 캐스트를 거쳐 `{}` 를 `[]` 로 찍으면, 파일을 열자마자 JSON 탭에 틀린 값이 보이고 한 글자를
     * 치는 순간 `{}` 로 바뀐다(실측 2026-10-06, 7.1.0 전수 실측 I1: `admin_role_list` 의
     * `params.query` 가 DB 는 `{}` 인데 JSON 탭에는 `"query": []` 2곳).
     *
     * @scenario branch=layout, input=valid_edit, action=idle_compile
     *
     * @effects with_markup_preserves_empty_map_vs_empty_list
     *
     * @return void 반환값 없음
     */
    public function test_show_content_field_keeps_empty_map_distinct_from_empty_list(): void
    {
        DB::table('template_layouts')
            ->where('id', $this->layout->id)
            ->update(['content' => '{"layout_name":"admin_probe","initLocal":{},"components":[{"type":"basic","name":"Div","id":"probe_root","props":{"emptyMap":{},"emptyList":[]}}]}']);

        $content = (string) $this->getJson(
            '/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe?with_markup=1',
            $this->headers($this->editorToken)
        )->assertOk()->json('data.content');

        $this->assertStringContainsString('"emptyMap": {}', $content, '빈 map 은 JSON 탭에서도 `{}` 여야 한다');
        $this->assertStringContainsString('"emptyList": []', $content, '빈 list 는 `[]` 로 남아야 한다');
        $this->assertStringContainsString('"initLocal": {}', $content);
    }

    /**
     * `<global-headers>` 항목 주석이 저장 왕복에서 살아남고 목록 순서·형태가 유지되는지.
     *
     * 저장 검증(`UpdateLayoutContentRequest`)에서 `globalHeaders` 는 하위 키를 명시한 유일한 배열
     * 섹션이라, 규칙이 없는 키는 `validated()` 가 조용히 버린다. 그래서 **아무것도 고치지 않고
     * 저장만 해도** 항목 주석이 사라졌다(브라우저 실측: `sirsoft-basic/_user_base` 주석 101 → 99).
     *
     * 순서까지 함께 잠근다 — `comment` 규칙을 `pattern` 앞에 두면 주석 있는 항목만 먼저 쌓여
     * 목록이 `[2,3,0,1]` 로 뒤집히고 JSON 이 배열 대신 객체로 굳는다. 패턴 매칭은 순서가 뜻을 가지므로
     * 주석 보존만 확인하면 그 회귀를 놓친다.
     *
     * @scenario branch=layout, input=valid_edit, action=save
     *
     * @effects markup_save_preserves_global_header_comments_and_order
     *
     * @return void 반환값 없음
     */
    public function test_saving_markup_preserves_global_header_comments_and_entry_order(): void
    {
        $markup = <<<'HTML'
        <layout name="admin_probe" version="1.0.0">
          <global-headers>
            <match pattern="/api/a/*" X-A="1"/>
            <!-- 둘째 항목에만 붙은 설명 -->
            <match pattern="/api/b/*" X-B="2"/>
            <match pattern="/api/c/*" X-C="3"/>
          </global-headers>
          <div id="probe_root">저장 실측</div>
        </layout>
        HTML;

        $this->putJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe', [
            'format' => 'markup',
            'content' => $markup,
            'expected_lock_version' => (int) $this->layout->lock_version,
        ], $this->headers($this->editorToken))->assertOk();

        $stored = $this->layout->fresh()->content;
        $headers = $stored['globalHeaders'] ?? [];

        $this->assertCount(3, $headers);
        $this->assertSame([0, 1, 2], array_keys($headers), 'globalHeaders 는 리스트로 남아야 한다 (키가 재배열되면 JSON 이 객체로 굳는다)');
        $this->assertSame(['/api/a/*', '/api/b/*', '/api/c/*'], array_column($headers, 'pattern'), '항목 순서는 패턴 매칭 우선순위다');
        $this->assertArrayNotHasKey('comment', $headers[0]);
        $this->assertSame('둘째 항목에만 붙은 설명', $headers[1]['comment'] ?? null, '항목 주석이 저장에서 사라지면 안 된다');
        $this->assertArrayNotHasKey('comment', $headers[2]);
    }

    /**
     * 깨진 마크업 저장은 좌표가 달린 422 compile_error 를 돌려주고 행을 바꾸지 않는지 검증합니다.
     *
     * @scenario branch=layout, input=compile_error, action=save
     *
     * @effects markup_compile_error_returns_422_with_position
     *
     * @return void 반환값 없음
     */
    public function test_saving_invalid_markup_returns_422_with_compile_error_and_leaves_row_untouched(): void
    {
        // `<on>` 앞의 주석은 산출에 실을 자리가 없다 — 주석 귀속 검사가 거부한다 (좌표는 <on> 의 4행).
        $markup = <<<'HTML'
        <layout name="admin_probe" version="1.0.0">
          <div id="probe_root">
            <!-- 프로브 -->
            <on event="click"><set-state target="local" a="2"/></on>
          </div>
        </layout>
        HTML;

        $response = $this->putJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe', [
            'format' => 'markup',
            'content' => $markup,
            'expected_lock_version' => (int) $this->layout->lock_version,
        ], $this->headers($this->editorToken))->assertStatus(422);

        $error = $response->json('compile_error');
        $this->assertSame('comment_not_attachable', $error['code']);
        $this->assertSame(4, $error['line'], '주석은 다음 태그(<on>)에 귀속되므로 그 태그의 행이 보고된다');
        $this->assertGreaterThan(0, $error['column']);
        $this->assertNotEmpty($error['message']);
        $this->assertArrayHasKey('content', $response->json('errors'));
        $this->assertSame($error, $response->json('errors.compile_error'), '화면이 읽는 error.errors 에도 같은 값이 실린다');

        $this->assertSame((int) $this->layout->lock_version, (int) $this->layout->fresh()->lock_version, '저장되지 않는다');
    }

    /**
     * 레이아웃 미리보기가 마크업 형식을 받아 토큰을 발급하는지 검증합니다.
     *
     * @scenario branch=layout, input=valid_edit, action=preview
     *
     * @effects markup_preview_accepts_format_markup
     *
     * @return void 반환값 없음
     */
    public function test_preview_accepts_markup(): void
    {
        $response = $this->postJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe/preview', [
            'format' => 'markup',
            'content' => '<layout name="admin_probe" version="1.0.0"><div id="probe_root"/></layout>',
        ], $this->headers($this->editorToken))->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame([], $response->json('data.markup_warnings'));
    }

    /**
     * 미리보기도 저장과 같은 진입점을 타므로 깨진 마크업은 토큰을 만들지 않는다.
     *
     * @scenario branch=layout, input=compile_error, action=preview
     *
     * @effects layout_preview_rejects_invalid_markup_with_422
     *
     * @return void 반환값 없음
     */
    public function test_preview_rejects_invalid_markup_with_422(): void
    {
        $response = $this->postJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe/preview', [
            'format' => 'markup',
            'content' => '<layout name="admin_probe" version="1.0.0"><Div/></layout>',
        ], $this->headers($this->editorToken))->assertStatus(422);

        $this->assertSame('tag_uppercase', $response->json('compile_error.code'));
        $this->assertSame($response->json('compile_error'), $response->json('errors.compile_error'));
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 레이아웃 확장도 마크업으로 저장하면 컴파일된 JSON 이 저장되는지 검증합니다.
     *
     * @scenario branch=extension, input=valid_edit, action=save
     *
     * @effects extension_markup_save_persists_compiled_json
     *
     * @return void 반환값 없음
     */
    public function test_extension_markup_save_persists_compiled_json(): void
    {
        $ok = $this->putJson("/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}", [
            'format' => 'markup',
            'content' => "<extension target-layout=\"admin_probe\" priority=\"7\">\n  <inject target-id=\"probe_root\" position=\"append_child\">\n    <div class=\"ext\"/>\n  </inject>\n</extension>\n",
            'expected_lock_version' => (int) $this->extension->lock_version,
        ], $this->headers($this->editorToken))->assertOk();

        $this->assertSame([], $ok->json('data.markup_warnings'));
        $this->assertSame(7, $this->extension->fresh()->content['priority'] ?? null);
    }

    /**
     * 레이아웃 확장의 컴파일 오류도 레이아웃 갈래와 같은 422 형태로 응답하고 저장하지 않는지 검증합니다.
     *
     * @scenario branch=extension, input=compile_error, action=save
     *
     * @effects extension_markup_save_is_symmetric
     *
     * @return void 반환값 없음
     */
    public function test_extension_markup_compile_error_is_symmetric_with_the_layout_branch(): void
    {
        $bad = $this->putJson("/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}", [
            'format' => 'markup',
            'content' => '<extension target-layout="admin_probe"><inject position="append_child"><div/></inject></extension>',
            'expected_lock_version' => (int) $this->extension->lock_version,
        ], $this->headers($this->editorToken))->assertStatus(422);

        $this->assertSame('missing_required_attribute', $bad->json('compile_error.code'));
        $this->assertSame($bad->json('compile_error'), $bad->json('errors.compile_error'), '확장 갈래도 error.errors 로 읽을 수 있어야 한다');
        $this->assertSame(100, $this->extension->fresh()->content['priority'] ?? null, '저장되지 않는다');
    }

    /**
     * 레이아웃 확장 미리보기가 마크업 형식을 받아 토큰을 발급하는지 검증합니다.
     *
     * @scenario branch=extension, input=valid_edit, action=preview
     *
     * @effects extension_preview_accepts_format_markup
     *
     * @return void 반환값 없음
     */
    public function test_extension_preview_accepts_markup(): void
    {
        $response = $this->postJson("/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}/preview", [
            'format' => 'markup',
            'content' => '<extension target-layout="admin_probe"><inject target-id="probe_root" position="append_child"><div class="ext"/></inject></extension>',
        ], $this->headers($this->editorToken))->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * 레이아웃 확장 미리보기는 깨진 마크업에 422 를 돌려주고 토큰을 만들지 않는지 검증합니다.
     *
     * @scenario branch=extension, input=compile_error, action=preview
     *
     * @effects extension_preview_rejects_invalid_markup_with_422
     *
     * @return void 반환값 없음
     */
    public function test_extension_preview_rejects_invalid_markup_with_422(): void
    {
        $response = $this->postJson("/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}/preview", [
            'format' => 'markup',
            'content' => '<extension target-layout="admin_probe"><inject position="append_child"><div/></inject></extension>',
        ], $this->headers($this->editorToken))->assertStatus(422);

        $this->assertSame('missing_required_attribute', $response->json('compile_error.code'));
        $this->assertSame($response->json('compile_error'), $response->json('errors.compile_error'));
        $this->assertNull($response->json('data.token'));
    }

    /**
     * 마크업 변환 엔드포인트는 읽기 권한만으로 동작하지만 저장은 403 으로 막히는지 검증합니다.
     *
     * @scenario branch=layout, input=valid_edit, action=idle_compile
     *
     * @effects markup_compile_endpoint_returns_json_or_422_and_needs_only_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_markup_compile_endpoint_works_with_read_permission_but_saving_does_not(): void
    {
        $ok = $this->postJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe/markup-compile', [
            'markup' => '<layout name="admin_probe" version="1.0.0"><div id="probe_root" class="x"/></layout>',
        ], $this->headers($this->readerToken))->assertOk();

        $decoded = json_decode($ok->json('data.content'), true);
        $this->assertSame('x', $decoded['components'][0]['props']['className'] ?? null);
        $this->assertSame([], $ok->json('data.warnings'));

        // 읽기 권한만으로는 저장할 수 없다.
        $this->putJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe', [
            'format' => 'markup',
            'content' => '<layout name="admin_probe" version="1.0.0"><div id="probe_root"/></layout>',
            'expected_lock_version' => (int) $this->layout->lock_version,
        ], $this->headers($this->readerToken))->assertStatus(403);
    }

    /**
     * 타이핑이 멈춘 뒤 도는 유휴 변환이 깨진 마크업을 만나면 좌표가 달린 422 를 돌려준다 — 편집기 마커의 입력이다.
     *
     * @scenario branch=layout, input=compile_error, action=idle_compile
     *
     * @effects layout_markup_compile_rejects_invalid_markup_with_422
     *
     * @return void 반환값 없음
     */
    public function test_markup_compile_endpoint_rejects_invalid_layout_markup(): void
    {
        $bad = $this->postJson('/api/admin/templates/sirsoft-admin_basic/layouts/admin_probe/markup-compile', [
            'markup' => '<layout name="admin_probe" version="1.0.0"><Div/></layout>',
        ], $this->headers($this->readerToken))->assertStatus(422);

        $this->assertSame('tag_uppercase', $bad->json('compile_error.code'));
        $this->assertSame($bad->json('compile_error'), $bad->json('errors.compile_error'));
        $this->assertArrayHasKey('markup', $bad->json('errors'));
        $this->assertGreaterThan(0, $bad->json('compile_error.line'));
    }

    /**
     * 레이아웃 확장 마크업 변환이 올바른 조각을 JSON 으로 돌려주는지 검증합니다.
     *
     * @scenario branch=extension, input=valid_edit, action=idle_compile
     *
     * @effects extension_markup_compile_returns_json_for_valid_fragment
     *
     * @return void 반환값 없음
     */
    public function test_extension_markup_compile_returns_json_for_valid_fragment(): void
    {
        $ext = $this->postJson("/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}/markup-compile", [
            'markup' => '<extension target-layout="admin_probe"><inject target-id="probe_root" position="append_child"><div/></inject></extension>',
        ], $this->headers($this->readerToken))->assertOk();

        $this->assertSame('admin_probe', json_decode($ext->json('data.content'), true)['target_layout'] ?? null);
        $this->assertSame([], $ext->json('data.warnings'));
    }

    /**
     * 레이아웃 확장 마크업 변환이 깨진 조각에 좌표가 달린 422 를 돌려주는지 검증합니다.
     *
     * @scenario branch=extension, input=compile_error, action=idle_compile
     *
     * @effects extension_markup_compile_rejects_invalid_fragment_with_422
     *
     * @return void 반환값 없음
     */
    public function test_extension_markup_compile_rejects_invalid_fragment(): void
    {
        $bad = $this->postJson("/api/admin/templates/sirsoft-admin_basic/layout-extensions/{$this->extension->id}/markup-compile", [
            'markup' => '<extension target-layout="admin_probe"><inject position="append_child"><div/></inject></extension>',
        ], $this->headers($this->readerToken))->assertStatus(422);

        $this->assertSame('missing_required_attribute', $bad->json('compile_error.code'));
        $this->assertSame($bad->json('compile_error'), $bad->json('errors.compile_error'));
        $this->assertArrayHasKey('markup', $bad->json('errors'));
    }

    /**
     * Bearer 인증 헤더를 만듭니다.
     *
     * @param  string  $token  API 토큰
     * @return array<string, string> 요청 헤더
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /**
     * 지정 권한을 가진 역할을 붙인 관리자 사용자를 생성합니다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 사용자
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
            'identifier' => 'markup_api_'.uniqid(),
            'name' => json_encode(['ko' => '마크업 API 테스트', 'en' => 'Markup API test']),
            'type' => 'admin',
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role->id);

        return $user;
    }
}
