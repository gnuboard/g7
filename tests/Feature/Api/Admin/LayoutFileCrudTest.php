<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use App\Rules\SafeCustomAssetPath;
use App\Support\LayoutMarkup\PartialReferenceNotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 레이아웃 **파일 단위** 조작 API 계약 — 생성·삭제·이름변경 (#476 코드 편집기 개편).
 *
 * 이 화면의 "파일" 은 디스크 파일이 아니라 `template_layouts` 의 **DB 행**이고, 이름의 `/` 가
 * 폴더처럼 보일 뿐이다. 그래서 여기서 잠그는 것은 파일시스템 안전이 아니라 **행 조작의 안전**이다.
 *
 * 특히 파괴적 연산의 차단 조건을 못박는다. 참조가 걸린 레이아웃을 지우거나 이름을 바꾸면 그것을
 * 쓰던 화면이 통째로 깨지고, 그 손상은 판본 이력으로 되돌릴 수 없다 — 판본은 **본문**의 이력이지
 * 파일의 존재 이력이 아니기 때문이다.
 */
class LayoutFileCrudTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    private string $editorToken;

    private string $readerToken;

    /**
     * 편집 권한 관리자와 읽기 전용 관리자의 토큰, 테스트용 관리자 템플릿을 준비한다.
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

        // 표(`components.json`)가 실재하는 식별자여야 마크업 컴파일이 돈다.
        $this->template = Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    // ── 생성 ──────────────────────────────────────────────────────────────
    /**
     * 본문 없이 만들면 `components` 를 갖춘 기본 골격(버전 1.0.0)으로 템플릿 출처 레이아웃 행이 생긴다.
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_creates_a_compilable_skeleton
     *
     * @return void 반환값 없음
     */
    public function test_creates_layout_with_default_skeleton_when_content_is_omitted(): void
    {
        $response = $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'probe_layout']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'probe_layout');

        $row = TemplateLayout::where('template_id', $this->template->id)->where('name', 'probe_layout')->first();

        $this->assertNotNull($row);
        $this->assertSame(LayoutSourceType::Template, $row->source_type);
        $this->assertSame('1.0.0', $row->content['version'] ?? null);
        // 독립 문서는 `components` 를 가져야 한다 — `slots` 만 있는 골격은 구조 검증에서 막힌다.
        $this->assertNotEmpty($row->content['components'] ?? []);
    }

    /**
     * 이름이 `partials/…` 여도 **행은 문서**다.
     *
     * 이름의 슬래시·선행 밑줄은 편집기 트리에서 폴더를 만드는 문자열일 뿐이고, 행의 성격을 바꾸지
     * 않는다. 조각(`<partial>`)으로 만들면 산출이 노드라 **저장 경로를 지날 수 없고**(문서 규칙에서
     * 422), 억지로 통과시켜도 본문 마스킹이 그 노드를 문서 골격으로 뭉갠다
     * (브라우저 실측 2026-09-12 — 저장 뒤 본문이 `{layout_name, components: []}` 로 남았다).
     *
     * 화면에 실리는 조각은 디스크의 `.g7.html` 이 설치 시점에 인라인되는 것이므로, DB 행으로 만든
     * 조각은 어느 화면도 쓰지 않는다.
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_creates_a_compilable_skeleton
     *
     * @return void 반환값 없음
     */
    public function test_creates_a_document_even_for_a_partial_shaped_name(): void
    {
        $response = $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'partials/probe/_probe_fragment']);

        $response->assertStatus(201);

        $row = TemplateLayout::where('template_id', $this->template->id)
            ->where('name', 'partials/probe/_probe_fragment')->first();

        $this->assertNotNull($row);
        $this->assertFalse($row->content['meta']['is_partial'] ?? false);
        $this->assertSame('1.0.0', $row->content['version'] ?? null);
        $this->assertNotEmpty($row->content['components'] ?? []);
    }

    /**
     * 만든 파일은 **곧바로 저장된다** — 생성과 저장 규칙이 어긋나면 만들 수는 있는데 고칠 수 없는
     * 파일이 생긴다(실측으로 잡힌 결함).
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_creates_a_compilable_skeleton
     *
     * @return void 반환값 없음
     */
    public function test_a_created_file_can_be_saved_immediately(): void
    {
        $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'partials/probe/_probe_fragment'])
            ->assertStatus(201);

        $row = TemplateLayout::where('name', 'partials/probe/_probe_fragment')->first();

        $this->withHeaders($this->headers($this->editorToken))
            ->putJson($this->base().'/partials/probe/_probe_fragment', [
                'content' => '<layout name="_probe_fragment" version="1.0.0">
  <div class="probe"></div>
</layout>
',
                'format' => 'markup',
                'expected_lock_version' => $row->lock_version,
            ])
            ->assertStatus(200);

        $saved = TemplateLayout::where('name', 'partials/probe/_probe_fragment')->first();

        $this->assertSame($row->lock_version + 1, (int) $saved->lock_version);
        $this->assertStringContainsString('probe', json_encode($saved->content));
    }

    /**
     * 파일을 만들면 만든 직후의 본문이 판본 1건으로 기록된다.
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_can_create_rename_and_delete_files
     *
     * @return void 반환값 없음
     */
    public function test_creation_records_a_baseline_version(): void
    {
        $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'probe_layout'])->assertStatus(201);

        $row = TemplateLayout::where('name', 'probe_layout')->first();

        // 만든 직후의 본문이 판본으로 남아야 첫 수정 뒤 "만들었을 때" 로 되돌릴 수 있다.
        $this->assertSame(1, TemplateLayoutVersion::where('layout_id', $row->id)->count());
    }

    /**
     * 지운 이름을 **다시 쓸 수 있다** — 되살려 쓰므로 그 파일의 판본 이력도 이어진다.
     *
     * 유니크 키 `(template_id, name, source_type)` 는 soft delete 된 행도 포함한다. 그대로 INSERT
     * 하면 중복 키로 500 이 난다(브라우저 실측 2026-09-12 — 화면에서 지운 이름을 다시 만들면 터졌다).
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_deletes_softly
     *
     * @return void 반환값 없음
     */
    public function test_recreating_a_soft_deleted_name_restores_the_row(): void
    {
        $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'probe_layout'])->assertStatus(201);

        $original = TemplateLayout::where('name', 'probe_layout')->first();

        $this->withHeaders($this->headers($this->editorToken))
            ->deleteJson($this->base().'/probe_layout?confirm=1')->assertStatus(200);

        $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'probe_layout'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'probe_layout');

        $restored = TemplateLayout::where('name', 'probe_layout')->first();

        $this->assertNotNull($restored);
        // 같은 행이 되살아났으므로 이력이 이어진다 — 새 행을 만들면 지우기 전 이력이 끊긴다.
        $this->assertSame($original->id, $restored->id);
        $this->assertGreaterThanOrEqual(2, TemplateLayoutVersion::where('layout_id', $restored->id)->count());
    }

    /**
     * 지워진 이름으로 **이름을 바꾸는** 것도 같은 충돌이다 — 409 로 막고 500 을 내지 않는다.
     *
     * @scenario operation=rename, target=layout
     *
     * @effects code_editor_rename_respects_optimistic_lock
     *
     * @return void 반환값 없음
     */
    public function test_rename_target_conflicts_with_a_soft_deleted_name(): void
    {
        $row = $this->makeLayout('probe_layout');
        $this->makeLayout('probe_deleted');

        $this->withHeaders($this->headers($this->editorToken))
            ->deleteJson($this->base().'/probe_deleted?confirm=1')->assertStatus(200);

        $this->withHeaders($this->headers($this->editorToken))
            ->patchJson($this->base().'/probe_layout/rename', [
                'new_name' => 'probe_deleted',
                'expected_lock_version' => $row->lock_version,
            ])
            ->assertStatus(409)
            ->assertJsonPath('errors.reason', 'layouts.errors.name_taken');
    }

    /**
     * 이미 있는 이름으로 만들면 422 와 `name_taken` 사유로 거부된다.
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_rejects_invalid_file_names
     *
     * @return void 반환값 없음
     */
    public function test_rejects_duplicate_name(): void
    {
        $this->makeLayout('probe_layout');

        $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'probe_layout'])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'layouts.errors.name_taken');
    }

    /**
     * 이름 규칙 위반은 **세그먼트 판정**으로 막는다.
     *
     * 문자열 포함 검사(`str_contains('..')`)는 정상 이름을 막으면서 인코딩된 탈출은 놓친다 —
     * 그래서 세그먼트마다 본다({@see SafeCustomAssetPath} 와 같은 방식).
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_rejects_invalid_file_names
     *
     * @param  string  $name  규칙을 벗어난 레이아웃 이름
     * @return void 반환값 없음
     */
    #[DataProvider('invalidNameProvider')]
    public function test_rejects_invalid_names(string $name): void
    {
        $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => $name])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * 본문을 실어 만든 파일은 본문의 `layout_name` 을 파일 이름으로 맞춥니다.
     *
     * 코드 편집기의 «새 이름으로 저장» 은 지워진 파일의 편집분(`<layout name="옛이름">`)을 그대로 실어 보낸다.
     * 이름변경이 하는 것처럼 맞추지 않으면 파일 이름과 다른 이름의 산출물이 저장되고, 그 마크업을 다시 저장할
     * 때마다 옛 이름이 되살아난다(실측 2026-10-03: 새 이름으로 살린 파일의 `layout_name` 이 지워진 옛 파일 이름으로 남았다).
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_can_create_rename_and_delete_files
     *
     * @return void 반환값 없음
     */
    public function test_creating_with_content_aligns_layout_name_to_file_name(): void
    {
        $markup = "<layout name=\"probe_old\" version=\"1.0.0\">\n  <div>rescued</div>\n</layout>\n";

        $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'probe_rescued', 'content' => $markup])
            ->assertStatus(201);

        $layout = TemplateLayout::query()->where('name', 'probe_rescued')->firstOrFail();
        $this->assertSame('probe_rescued', $layout->content['layout_name'] ?? null);
    }

    /**
     * 이름이 규칙을 벗어나면 그 이유 하나만 돌려줍니다.
     *
     * 화면은 새 파일을 만들 때 본문을 빈 문자열로 보낸다(서버가 골격을 채운다). 이름이 틀리면 골격을 만들 수
     * 없어 「본문 필수」가 덤으로 붙었고, 응답 문구 끝에 원인과 무관한 나머지 오류 수 꼬리가 붙었다.
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_rejects_invalid_file_names
     *
     * @return void 반환값 없음
     */
    public function test_invalid_name_reports_only_the_name_error(): void
    {
        $response = $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => 'zz probe', 'content' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonMissingValidationErrors(['content']);

        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('more error', $message);
        $this->assertStringNotContainsString('개 오류)', $message);
    }

    /**
     * 이름 규칙을 벗어나는 레이아웃 이름 사례를 제공한다.
     *
     * @return array<string, array{0: string}> 사례 이름별 잘못된 레이아웃 이름
     */
    public static function invalidNameProvider(): array
    {
        return [
            'traversal' => ['../evil'],
            'leading slash' => ['/evil'],
            'trailing slash' => ['evil/'],
            'empty segment' => ['a//b'],
            'dot segment' => ['a/./b'],
            'dot dot segment' => ['a/../b'],
            'extension in name' => ['evil.g7.html'],
            'space' => ['evil name'],
            'empty' => [''],
        ];
    }

    /**
     * 255자를 넘는 이름은 검증 오류(422)로 거부된다.
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_rejects_invalid_file_names
     *
     * @return void 반환값 없음
     */
    public function test_rejects_name_longer_than_255(): void
    {
        $this->withHeaders($this->headers($this->editorToken))
            ->postJson($this->base(), ['name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * 레이아웃 편집 권한이 없는 관리자는 파일을 만들 수 없다(403).
     *
     * @scenario operation=create, target=layout
     *
     * @effects code_editor_can_create_rename_and_delete_files
     *
     * @return void 반환값 없음
     */
    public function test_creation_requires_edit_permission(): void
    {
        $this->withHeaders($this->headers($this->readerToken))
            ->postJson($this->base(), ['name' => 'probe_layout'])
            ->assertStatus(403);
    }

    // ── 삭제 ──────────────────────────────────────────────────────────────
    /**
     * 삭제는 soft delete 로 처리되어 일반 조회에서는 빠지고 행은 남는다.
     *
     * @scenario operation=delete, target=layout
     *
     * @effects code_editor_deletes_softly
     *
     * @return void 반환값 없음
     */
    public function test_deletes_layout_softly(): void
    {
        $row = $this->makeLayout('probe_layout');

        $this->withHeaders($this->headers($this->editorToken))
            ->deleteJson($this->base().'/probe_layout?confirm=1')
            ->assertStatus(200);

        // 행은 남되 조회에서 빠진다 — 오삭제를 되돌릴 수 있어야 한다.
        $this->assertNull(TemplateLayout::find($row->id));
        $this->assertNotNull(TemplateLayout::withTrashed()->find($row->id));
    }

    /**
     * 파괴적 연산은 활동 로그에 남아야 되돌릴 근거가 된다 — 생성·이름변경·삭제가 `core.layout.after_*`
     * 훅을 거쳐 `layout.create`·`layout.rename`·`layout.delete` 로 기록되는지.
     *
     * @scenario operation=delete, target=layout
     *
     * @effects code_editor_records_file_operations_in_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_file_operations_are_recorded_in_activity_log(): void
    {
        $headers = $this->headers($this->editorToken);

        $this->withHeaders($headers)->postJson($this->base(), ['name' => 'probe_logged'])->assertStatus(201);
        $created = TemplateLayout::where('template_id', $this->template->id)->where('name', 'probe_logged')->firstOrFail();

        $this->withHeaders($headers)
            ->patchJson($this->base().'/probe_logged/rename', [
                'new_name' => 'probe_logged_renamed',
                'expected_lock_version' => $created->lock_version,
            ])
            ->assertStatus(200);
        $this->withHeaders($headers)->deleteJson($this->base().'/probe_logged_renamed?confirm=1')->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', ['action' => 'layout.create']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'layout.rename']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'layout.delete']);

        $rename = ActivityLog::where('action', 'layout.rename')->latest('id')->first();
        $this->assertSame('probe_logged', $rename->properties['from'] ?? null);
        $this->assertSame('probe_logged_renamed', $rename->properties['to'] ?? null);
    }

    /**
     * 모듈이 제공한 레이아웃은 삭제가 422 와 출처 차단 사유로 막히고 행이 그대로 남는다.
     *
     * @scenario operation=delete, target=module_owned
     *
     * @effects code_editor_blocks_deleting_module_owned_layouts
     *
     * @return void 반환값 없음
     */
    public function test_blocks_deleting_a_layout_provided_by_a_module(): void
    {
        $this->makeLayout('module_owned', sourceType: LayoutSourceType::Module, sourceIdentifier: 'sirsoft-board');

        $this->withHeaders($this->headers($this->editorToken))
            ->deleteJson($this->base().'/module_owned?confirm=1')
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'layouts.errors.delete_blocked_source_type');

        $this->assertNotNull(TemplateLayout::where('name', 'module_owned')->first());
    }

    /**
     * 출처 차단 안내의 확장 종류가 내부 값(`module`)이 아니라 사용 언어로 보인다.
     *
     * 실측 2026-10-06(7.1.0 전수 실측 I1): 코드 편집기에서 게시판 모듈 레이아웃을 지우려 하자
     * 「module(sirsoft-board)이(가) 제공한 레이아웃은 삭제할 수 없습니다.」 처럼 종류가 영어 원값으로 떴다.
     *
     * @scenario operation=delete, target=module_owned
     *
     * @effects code_editor_blocks_deleting_module_owned_layouts
     *
     * @return void 반환값 없음
     */
    public function test_source_type_in_blocked_message_is_localized(): void
    {
        $this->makeLayout('module_owned', sourceType: LayoutSourceType::Module, sourceIdentifier: 'sirsoft-board');
        $this->makeLayout('plugin_owned', sourceType: LayoutSourceType::Plugin, sourceIdentifier: 'sirsoft-gdpr');

        $ko = $this->withHeaders($this->headers($this->editorToken) + ['Accept-Language' => 'ko'])
            ->deleteJson($this->base().'/module_owned?confirm=1')
            ->assertStatus(422)
            ->json('message');
        $this->assertStringContainsString('모듈(sirsoft-board)', (string) $ko);
        $this->assertStringNotContainsString('module(', (string) $ko);

        $renamed = $this->withHeaders($this->headers($this->editorToken) + ['Accept-Language' => 'ko'])
            ->patchJson($this->base().'/plugin_owned/rename', ['new_name' => 'plugin_moved', 'expected_lock_version' => 0])
            ->assertStatus(422)
            ->json('message');
        $this->assertStringContainsString('플러그인(sirsoft-gdpr)', (string) $renamed);
        $this->assertStringNotContainsString('plugin(', (string) $renamed);
    }

    /**
     * `extends` 로 참조되는 부모 레이아웃은 삭제가 막히고, 응답에 참조하는 레이아웃과 참조 종류가 실린다.
     *
     * @scenario operation=delete, target=layout
     *
     * @effects code_editor_shows_what_blocks_a_delete
     *
     * @return void 반환값 없음
     */
    public function test_blocks_deleting_a_parent_referenced_by_extends(): void
    {
        $this->makeLayout('probe_base');
        $child = $this->makeLayout('probe_child');
        $child->extends = 'probe_base';
        $child->save();

        $response = $this->withHeaders($this->headers($this->editorToken))
            ->deleteJson($this->base().'/probe_base?confirm=1');

        $response->assertStatus(422)
            ->assertJsonPath('errors.reason', 'layouts.errors.delete_blocked_by_reference');

        // 무엇이 붙들고 있는지 목록으로 알려 줘야 사용자가 정리할 수 있다.
        $references = $response->json('errors.references');
        $this->assertIsArray($references);
        $this->assertSame('probe_child', $references[0]['name']);
        $this->assertSame('extends', $references[0]['kind']);
    }

    /**
     * 다른 레이아웃이 partial 로 참조하는 조각은 삭제가 막히고, 응답에 참조하는 레이아웃과 참조 종류가 실린다.
     *
     * @scenario operation=delete, target=layout
     *
     * @effects code_editor_shows_what_blocks_a_delete
     *
     * @return void 반환값 없음
     */
    public function test_blocks_deleting_a_partial_referenced_by_another_layout(): void
    {
        $this->makeLayout('partials/probe/_probe_fragment');

        $host = $this->makeLayout('probe_host');
        $host->content = [
            'layout_name' => 'probe_host',
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div']],
            'modals' => [['partial' => 'partials/probe/_probe_fragment'.PartialReferenceNotation::JSON_EXTENSION]],
        ];
        $host->save();

        $response = $this->withHeaders($this->headers($this->editorToken))
            ->deleteJson($this->base().'/partials/probe/_probe_fragment?confirm=1');

        $response->assertStatus(422)
            ->assertJsonPath('errors.reason', 'layouts.errors.delete_blocked_by_reference');
        $this->assertSame('probe_host', $response->json('errors.references.0.name'));
        $this->assertSame('partial', $response->json('errors.references.0.kind'));
    }

    /**
     * 이름이 접두로 겹치는 조각은 참조로 세지 않는다.
     *
     * 부분 문자열 검사로 구현하면 `_probe_fragment` 를 지우려 할 때 `_probe_fragment_extra` 를
     * 참조하는 화면까지 차단 사유로 잡혀, 지울 수 있는 파일이 영영 지워지지 않는다.
     *
     * @scenario operation=delete, target=layout
     *
     * @effects code_editor_shows_what_blocks_a_delete
     *
     * @return void 반환값 없음
     */
    public function test_prefix_sharing_names_are_not_counted_as_references(): void
    {
        $this->makeLayout('partials/probe/_frag');

        $host = $this->makeLayout('probe_host');
        $host->content = [
            'layout_name' => 'probe_host',
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div']],
            'modals' => [['partial' => 'partials/probe/_frag_extra'.PartialReferenceNotation::JSON_EXTENSION]],
        ];
        $host->save();

        $this->withHeaders($this->headers($this->editorToken))
            ->deleteJson($this->base().'/partials/probe/_frag?confirm=1')
            ->assertStatus(200);
    }

    /**
     * 레이아웃 편집 권한이 없는 관리자는 파일을 지울 수 없다(403).
     *
     * @scenario operation=delete, target=layout
     *
     * @effects code_editor_can_create_rename_and_delete_files
     *
     * @return void 반환값 없음
     */
    public function test_deletion_requires_edit_permission(): void
    {
        $this->makeLayout('probe_layout');

        $this->withHeaders($this->headers($this->readerToken))
            ->deleteJson($this->base().'/probe_layout?confirm=1')
            ->assertStatus(403);
    }

    // ── 이름변경 ──────────────────────────────────────────────────────────
    /**
     * 이름변경은 같은 행의 이름과 본문 `layout_name` 을 함께 바꾸고 판본 이력을 그대로 잇는다.
     *
     * @scenario operation=rename, target=layout
     *
     * @effects code_editor_rename_keeps_version_history
     *
     * @return void 반환값 없음
     */
    public function test_renames_layout_and_keeps_version_history(): void
    {
        $row = $this->makeLayout('probe_layout');
        TemplateLayoutVersion::create([
            'layout_id' => $row->id,
            'version' => 1,
            'content' => $row->content,
            'changes_summary' => ['added' => 0, 'removed' => 0, 'char_diff' => 0],
        ]);

        $this->withHeaders($this->headers($this->editorToken))
            ->patchJson($this->base().'/probe_layout/rename', [
                'new_name' => 'probe_renamed',
                'expected_lock_version' => $row->lock_version,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'probe_renamed');

        $renamed = TemplateLayout::find($row->id);

        $this->assertSame('probe_renamed', $renamed->name);
        // 본문의 `layout_name` 도 함께 옮겨야 다음 저장이 옛 이름을 되살리지 않는다.
        $this->assertSame('probe_renamed', $renamed->content['layout_name']);
        // 새 행을 만들지 않았으므로 이력이 그대로 승계된다.
        $this->assertGreaterThanOrEqual(1, TemplateLayoutVersion::where('layout_id', $row->id)->count());
        $this->assertSame(0, TemplateLayout::where('name', 'probe_layout')->count());
    }

    /**
     * 잠금 번호가 맞지 않는 이름변경은 409 로 거부되고 원래 이름의 행이 남는다.
     *
     * @scenario operation=rename, target=layout
     *
     * @effects code_editor_rename_respects_optimistic_lock
     *
     * @return void 반환값 없음
     */
    public function test_rename_rejects_stale_lock_version(): void
    {
        $row = $this->makeLayout('probe_layout');

        $this->withHeaders($this->headers($this->editorToken))
            ->patchJson($this->base().'/probe_layout/rename', [
                'new_name' => 'probe_renamed',
                'expected_lock_version' => $row->lock_version + 99,
            ])
            ->assertStatus(409);

        $this->assertNotNull(TemplateLayout::where('name', 'probe_layout')->first());
    }

    /**
     * 이미 있는 이름으로 바꾸려 하면 409 와 `name_taken` 사유로 거부된다.
     *
     * @scenario operation=rename, target=layout
     *
     * @effects code_editor_rejects_invalid_file_names
     *
     * @return void 반환값 없음
     */
    public function test_rename_rejects_existing_target_name(): void
    {
        $row = $this->makeLayout('probe_layout');
        $this->makeLayout('probe_taken');

        $this->withHeaders($this->headers($this->editorToken))
            ->patchJson($this->base().'/probe_layout/rename', [
                'new_name' => 'probe_taken',
                'expected_lock_version' => $row->lock_version,
            ])
            ->assertStatus(409)
            ->assertJsonPath('errors.reason', 'layouts.errors.name_taken');
    }

    /**
     * 경로를 벗어나는 새 이름(`../evil`)은 `new_name` 검증 오류(422)로 거부된다.
     *
     * @scenario operation=rename, target=layout
     *
     * @effects code_editor_rejects_invalid_file_names
     *
     * @return void 반환값 없음
     */
    public function test_rename_rejects_invalid_name(): void
    {
        $row = $this->makeLayout('probe_layout');

        $this->withHeaders($this->headers($this->editorToken))
            ->patchJson($this->base().'/probe_layout/rename', [
                'new_name' => '../evil',
                'expected_lock_version' => $row->lock_version,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_name']);
    }

    /**
     * 모듈이 제공한 레이아웃은 이름변경이 422 와 출처 차단 사유로 막힌다.
     *
     * @scenario operation=rename, target=module_owned
     *
     * @effects code_editor_blocks_deleting_module_owned_layouts
     *
     * @return void 반환값 없음
     */
    public function test_blocks_renaming_a_layout_provided_by_a_module(): void
    {
        $row = $this->makeLayout('module_owned', sourceType: LayoutSourceType::Module, sourceIdentifier: 'sirsoft-board');

        $this->withHeaders($this->headers($this->editorToken))
            ->patchJson($this->base().'/module_owned/rename', [
                'new_name' => 'module_renamed',
                'expected_lock_version' => $row->lock_version,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason', 'layouts.errors.rename_blocked_source_type');
    }

    /**
     * 레이아웃 편집 권한이 없는 관리자는 이름을 바꿀 수 없다(403).
     *
     * @scenario operation=rename, target=layout
     *
     * @effects code_editor_can_create_rename_and_delete_files
     *
     * @return void 반환값 없음
     */
    public function test_rename_requires_edit_permission(): void
    {
        $row = $this->makeLayout('probe_layout');

        $this->withHeaders($this->headers($this->readerToken))
            ->patchJson($this->base().'/probe_layout/rename', [
                'new_name' => 'probe_renamed',
                'expected_lock_version' => $row->lock_version,
            ])
            ->assertStatus(403);
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────

    /**
     * 테스트 템플릿의 레이아웃 API 기준 경로를 돌려준다.
     *
     * @return string 레이아웃 API 기준 경로
     */
    private function base(): string
    {
        return "/api/admin/templates/{$this->template->identifier}/layouts";
    }

    /**
     * 토큰을 실은 Bearer 인증 헤더와 JSON 수락 헤더를 만든다.
     *
     * @param  string  $token  인증 토큰
     * @return array<string, string> 인증 헤더
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /**
     * 테스트 템플릿에 잠금 번호 0 인 레이아웃 행을 만든다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  LayoutSourceType  $sourceType  레이아웃 출처 유형
     * @param  string|null  $sourceIdentifier  출처 식별자 (null 이면 테스트 템플릿 식별자)
     * @return TemplateLayout 생성된 레이아웃
     */
    private function makeLayout(
        string $name,
        LayoutSourceType $sourceType = LayoutSourceType::Template,
        ?string $sourceIdentifier = null,
    ): TemplateLayout {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => $name,
            'source_type' => $sourceType->value,
            'source_identifier' => $sourceIdentifier ?? $this->template->identifier,
            // 팩토리는 잠금 번호를 채우지 않는다 — null 이면 이름변경 요청이 그 값을 싣지 못해
            // 낙관적 잠금이 아니라 필수 항목 누락으로 막힌다.
            'lock_version' => 0,
            'content' => [
                'layout_name' => $name,
                'version' => '1.0.0',
                'components' => [['type' => 'basic', 'name' => 'Div']],
            ],
        ]);
    }

    /**
     * 주어진 권한을 가진 관리자 역할을 만들어 새 사용자에게 붙인다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 관리자 사용자
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
            'identifier' => 'layout_file_crud_'.uniqid(),
            'name' => json_encode(['ko' => '파일 조작 테스트', 'en' => 'Layout file CRUD test']),
            'type' => 'admin',
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role->id);

        return $user;
    }
}
