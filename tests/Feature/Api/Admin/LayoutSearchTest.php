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
use App\Services\LayoutFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 레이아웃 **파일명·내용 검색** API 계약 (코드 편집기 개편).
 *
 * ## 왜 마크업을 훑는가
 *
 * DB 에는 JSON 만 있는데 사용자가 편집기에서 보는 것은 역변환한 `.g7.html` 이다. 검색 결과를 눌러
 * 그 줄로 이동하려면 서버가 센 줄 번호와 편집기의 줄 번호가 **같은 좌표계**여야 하므로, 검색 대상도
 * 역변환한 마크업이어야 한다. 여기서 잠그는 것이 그 일치다 — JSON 을 훑도록 되돌리면 줄 번호가
 * 조용히 어긋나고, 결과를 눌렀을 때 엉뚱한 줄로 간다.
 *
 * 역변환은 비싸서 `lock_version` 을 키에 담아 캐시한다. 그 키 규약도 함께 잠근다 — 저장으로
 * 무효화되지 않으면 고친 내용이 검색에 영영 안 잡힌다.
 */
class LayoutSearchTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    private string $readerToken;

    /**
     * 템플릿 조회 권한을 가진 관리자 토큰과 활성 관리자 템플릿을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->readerToken = $this->createAdminUser(['core.templates.read'])
            ->createToken('reader')->plainTextToken;

        $this->template = Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 이름 범위 검색이 파일명으로 레이아웃을 찾고, 역변환 없이 바로 응답하는지(match_count 0) 확인한다.
     *
     * @scenario scope=name, target=layout
     *
     * @effects code_editor_filters_names_without_a_round_trip
     *
     * @return void 반환값 없음
     */
    public function test_matches_by_file_name(): void
    {
        $this->makeLayout('admin_probe_users');
        $this->makeLayout('admin_other');

        $response = $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=probe_users&scope=name');

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('layout:admin_probe_users', $rows[0]['key']);
        $this->assertTrue($rows[0]['name_matched']);
        // `scope=name` 은 역변환하지 않는다 — 즉시 응답이 이 범위의 존재 이유다.
        $this->assertSame(0, $rows[0]['match_count']);
    }

    /**
     * 내용 범위 검색이 역변환한 마크업을 훑어, 편집기와 같은 줄·칸 좌표를 돌려주는지 확인한다.
     *
     * @scenario scope=content, target=layout
     *
     * @effects code_editor_searches_contents_with_editor_line_numbers
     *
     * @return void 반환값 없음
     */
    public function test_matches_markup_content_with_editor_line_numbers(): void
    {
        $this->makeLayout('admin_probe', [
            'layout_name' => 'admin_probe',
            'version' => '1.0.0',
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'probe-marker-class']],
            ],
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=probe-marker-class&scope=content');

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertGreaterThan(0, $rows[0]['match_count']);

        $hit = $rows[0]['lines'][0];

        // 서버가 센 좌표가 실제 마크업의 그 줄·그 칸을 가리켜야 한다.
        $markup = app(LayoutFileService::class)->markupFor(
            TemplateLayout::where('name', 'admin_probe')->first(),
            $this->template->identifier,
        );
        $lines = explode("\n", $markup);

        $this->assertStringContainsString('probe-marker-class', $lines[$hit['line'] - 1]);
        $this->assertSame(
            mb_strpos(mb_strtolower($lines[$hit['line'] - 1]), 'probe-marker-class') + 1,
            $hit['column'],
        );
    }

    /**
     * 역변환 마크업을 lock_version 키로 캐시하고, lock_version 이 오르면 새로 역변환하는지 확인한다.
     *
     * @effects code_editor_searches_contents_with_editor_line_numbers
     *
     * @return void 반환값 없음
     */
    public function test_caches_decompiled_markup_by_lock_version(): void
    {
        $row = $this->makeLayout('admin_probe', [
            'layout_name' => 'admin_probe',
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'probe-marker-class']]],
        ]);

        $service = app(LayoutFileService::class);

        $first = $service->markupFor($row, $this->template->identifier);
        $this->assertStringContainsString('probe-marker-class', $first);

        // 캐시 히트 — 행 본문을 바꿔도 `lock_version` 이 그대로면 캐시가 우선한다.
        $row->content = ['layout_name' => 'admin_probe', 'version' => '1.0.0', 'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'changed-class']]]];
        $row->saveQuietly();

        $cached = $service->markupFor($row->fresh(), $this->template->identifier);
        $this->assertStringContainsString('probe-marker-class', $cached);

        // `lock_version` 이 오르면 키가 바뀌어 자동으로 무효화된다 — 저장이 곧 무효화다.
        $bumped = $row->fresh();
        $bumped->lock_version = (int) $bumped->lock_version + 1;
        $bumped->saveQuietly();

        $fresh = $service->markupFor($bumped->fresh(), $this->template->identifier);
        $this->assertStringContainsString('changed-class', $fresh);
    }

    /**
     * 내용 범위 검색 결과에 확장 조각(ext: 키)도 포함되는지 확인한다.
     *
     * @scenario scope=content, target=extension_fragment
     *
     * @effects code_editor_searches_contents_with_editor_line_numbers
     *
     * @return void 반환값 없음
     */
    public function test_includes_extension_fragments(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint->value,
            'target_name' => 'admin_probe',
            'fragment_key' => 'probe_fragment',
            'source_type' => LayoutSourceType::Module->value,
            'source_identifier' => 'sirsoft-board',
            'content' => ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'ext-marker-class']],
            'priority' => 100,
            'is_active' => true,
            'lock_version' => 0,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=ext-marker-class&scope=content');

        $response->assertStatus(200);

        $keys = array_column($response->json('data'), 'key');
        $this->assertNotEmpty(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'ext:')));
    }

    /**
     * 이름 범위 검색에서도 확장 조각이 조각 키 이름으로 잡히는지 확인한다.
     *
     * @scenario scope=name, target=extension_fragment
     *
     * @effects layout_search_name_scope_includes_extension_fragments
     *
     * @return void 반환값 없음
     */
    public function test_includes_extension_fragments_when_scope_is_name(): void
    {
        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint->value,
            'target_name' => 'admin_probe',
            'fragment_key' => 'probe_fragment',
            'source_type' => LayoutSourceType::Module->value,
            'source_identifier' => 'sirsoft-board',
            'content' => ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'ext-marker-class']],
            'priority' => 100,
            'is_active' => true,
            'lock_version' => 0,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=probe_fragment&scope=name');

        $response->assertStatus(200);

        // §5.2 — 확장 조각도 «같은 방식으로» 포함된다. 이름 범위라고 조각을 통째로 빼면
        // 트리에 보이는 조각 라벨이 검색에서만 사라진다.
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('ext:', $rows[0]['key']);
        $this->assertTrue($rows[0]['name_matched']);
        $this->assertSame(0, $rows[0]['match_count']);
    }

    /**
     * 두 글자보다 짧은 검색어는 422 로 거부되는지 확인한다.
     *
     * @effects code_editor_skips_content_search_below_two_characters
     *
     * @return void 반환값 없음
     */
    public function test_rejects_keyword_shorter_than_two_characters(): void
    {
        $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=a')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    /**
     * 정해진 값이 아닌 검색 범위(scope)는 422 로 거부되는지 확인한다.
     *
     * @effects code_editor_skips_content_search_below_two_characters
     *
     * @return void 반환값 없음
     */
    public function test_rejects_unknown_scope(): void
    {
        $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=probe&scope=everything')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scope']);
    }

    /**
     * limit 로 준 파일 수까지만 결과를 돌려주는지 확인한다.
     *
     * @effects code_editor_searches_contents_with_editor_line_numbers
     *
     * @return void 반환값 없음
     */
    public function test_honours_the_file_limit(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->makeLayout("admin_probe_{$index}");
        }

        $rows = $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=admin_probe&scope=name&limit=3')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(3, $rows);
    }

    /**
     * 한 파일의 일치 줄 수가 파일당 상한을 넘지 않는지 확인한다.
     *
     * @effects code_editor_searches_contents_with_editor_line_numbers
     *
     * @return void 반환값 없음
     */
    public function test_caps_matches_per_file(): void
    {
        // 같은 문자열이 상한보다 많이 들어간 문서 — 한 파일이 결과를 통째로 덮지 않아야 한다.
        $components = [];
        for ($index = 0; $index < LayoutFileService::SEARCH_MAX_MATCHES_PER_FILE + 10; $index++) {
            $components[] = ['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'probe-marker-class']];
        }

        $this->makeLayout('admin_probe', [
            'layout_name' => 'admin_probe',
            'version' => '1.0.0',
            'components' => $components,
        ]);

        $rows = $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=probe-marker-class&scope=content')
            ->assertStatus(200)
            ->json('data');

        $this->assertLessThanOrEqual(LayoutFileService::SEARCH_MAX_MATCHES_PER_FILE, count($rows[0]['lines']));
    }

    /**
     * 긴 줄의 미리보기가 길이 상한(+ 말줄임표 1자)으로 잘리는지 확인한다.
     *
     * @effects code_editor_search_result_click_reveals_the_matching_line
     *
     * @return void 반환값 없음
     */
    public function test_truncates_long_previews(): void
    {
        $this->makeLayout('admin_probe', [
            'layout_name' => 'admin_probe',
            'version' => '1.0.0',
            'components' => [[
                'type' => 'basic',
                'name' => 'Div',
                'props' => ['className' => 'probe-marker-class '.str_repeat('x', LayoutFileService::SEARCH_PREVIEW_LENGTH + 80)],
            ]],
        ]);

        $rows = $this->withHeaders($this->headers())
            ->getJson($this->base().'?q=probe-marker-class&scope=content')
            ->assertStatus(200)
            ->json('data');

        $preview = $rows[0]['lines'][0]['preview'];

        // 상한 + 말줄임표 1자. 넘치면 응답이 파일 본문을 통째로 실어 나른다.
        $this->assertLessThanOrEqual(LayoutFileService::SEARCH_PREVIEW_LENGTH + 1, mb_strlen($preview));
    }

    /**
     * 템플릿 조회 권한이 없는 관리자는 403 을 받는지 확인한다.
     *
     * @effects code_editor_filters_names_without_a_round_trip
     *
     * @return void 반환값 없음
     */
    public function test_search_requires_read_permission(): void
    {
        $stranger = $this->createAdminUser([])->createToken('stranger')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$stranger, 'Accept' => 'application/json'])
            ->getJson($this->base().'?q=probe')
            ->assertStatus(403);
    }

    /**
     * 없는 템플릿 식별자로 검색하면 404 를 받는지 확인한다.
     *
     * @effects code_editor_filters_names_without_a_round_trip
     *
     * @return void 반환값 없음
     */
    public function test_returns_404_for_unknown_template(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/admin/templates/does-not-exist/layouts/search?q=probe')
            ->assertStatus(404);
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────

    /**
     * 이 템플릿의 레이아웃 검색 API 경로를 만든다.
     *
     * @return string 검색 API 경로
     */
    private function base(): string
    {
        return "/api/admin/templates/{$this->template->identifier}/layouts/search";
    }

    /**
     * 검색 권한을 가진 관리자의 인증 헤더를 만든다.
     *
     * @return array<string, string> Bearer 토큰과 JSON 수락 헤더
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->readerToken, 'Accept' => 'application/json'];
    }

    /**
     * 이 템플릿 소속 레이아웃 한 건을 만든다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  array<string, mixed>|null  $content  레이아웃 JSON 본문 (null 이면 빈 Div 하나)
     * @return TemplateLayout 만든 레이아웃
     */
    private function makeLayout(string $name, ?array $content = null): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => $name,
            'source_type' => LayoutSourceType::Template->value,
            'source_identifier' => $this->template->identifier,
            'lock_version' => 0,
            'content' => $content ?? [
                'layout_name' => $name,
                'version' => '1.0.0',
                'components' => [['type' => 'basic', 'name' => 'Div']],
            ],
        ]);
    }

    /**
     * 주어진 권한을 가진 역할을 붙인 관리자 사용자를 만든다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 만든 관리자 사용자
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
            'identifier' => 'layout_search_'.uniqid(),
            'name' => json_encode(['ko' => '검색 테스트', 'en' => 'Layout search test']),
            'type' => 'admin',
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role->id);

        return $user;
    }
}
