<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Admin;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 관리자 페이지 관리 API 테스트
 *
 * PageController의 CRUD, 발행/미발행, 버전 관리, 권한 차단 등을 검증합니다.
 */
class PageControllerTest extends FeatureTestCase
{
    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (페이지 모든 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
            'sirsoft-page.pages.delete',
        ]);
    }

    /**
     * 테스트 정리
     */
    protected function tearDown(): void
    {
        // 테스트에서 생성한 페이지 삭제 (slug 패턴 매칭)
        Page::where('slug', 'like', 'test-%')->forceDelete();

        parent::tearDown();
    }

    // ─── 목록 조회 (index) ─────────────────────────────

    /**
     * 페이지 목록을 조회할 수 있는지 확인
     */
    public function test_admin_can_list_pages(): void
    {
        Page::factory()->count(3)->create([
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => ['id', 'slug', 'title', 'published'],
                    ],
                    'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
            ]);
    }

    /**
     * 발행 상태로 필터링하여 목록을 조회할 수 있는지 확인
     */
    public function test_admin_can_filter_pages_by_published(): void
    {
        Page::factory()->published()->create([
            'slug' => 'test-published-filter',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);
        Page::factory()->create([
            'slug' => 'test-draft-filter',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?published=1');

        $response->assertStatus(200);
        $items = $response->json('data.data');
        foreach ($items as $item) {
            $this->assertTrue($item['published']);
        }
    }

    /**
     * 검색어로 목록을 조회할 수 있는지 확인
     *
     * 검색 동작은 슬러그(LIKE) 매칭으로 검증한다 — 슬러그 검색은 InnoDB FULLTEXT 가
     * 트랜잭션 내 미커밋 데이터를 보장하지 않는 가시성 문제와 무관하게 안정적이다.
     * (제목/본문 FULLTEXT 컬럼 범위 분리는 test_admin_search_title_excludes_content 가 담당)
     */
    public function test_admin_can_search_pages(): void
    {
        Page::factory()->create([
            'slug' => 'test-search-target',
            'title' => ['ko' => '검색대상페이지', 'en' => 'Search Target'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // 전체(all) 검색에 슬러그 토큰을 넣으면 결과에 포함되어야 한다
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?search=search-target&search_field=all');

        $response->assertStatus(200);
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('test-search-target', $slugs);
    }

    /**
     * 검색조건 '전체'(all)로 슬러그를 검색하면 결과에 포함되는지 확인
     *
     * 회귀: 기존에는 all 검색이 Scout(제목·본문 FULLTEXT) 경로로만 처리되어
     * 슬러그가 검색 범위에서 누락됨. all 검색에도 슬러그 LIKE 가 포함되어야 한다.
     *
     * 슬러그 매칭은 LIKE 기반이라 테스트 트랜잭션의 미커밋 데이터도 검증 가능하다.
     * (제목·본문 FULLTEXT 매칭은 별도 title 단독 검색 테스트가 담당)
     */
    public function test_admin_search_all_includes_slug(): void
    {
        // 제목/본문에 없는 고유 슬러그 토큰 (운영 시더 데이터와 충돌 회피)
        Page::factory()->create([
            'slug' => 'test-zzslugonly-target',
            'title' => ['ko' => '제목없음표제', 'en' => 'Heading Only'],
            'content' => ['ko' => '본문내용', 'en' => 'Body'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // search_field 미지정 = all. 제목/본문에 없는 슬러그 토큰으로 검색
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?search=zzslugonly');

        $response->assertStatus(200);
        $slugs = collect($response->json('data.data'))->pluck('slug')->all();
        $this->assertContains('test-zzslugonly-target', $slugs);
    }

    /**
     * '전체'(all) 검색 결과의 total 이 실제 행 수와 일치하는지 확인 (#225 회귀 가드)
     *
     * 과거 Scout 콜백 내 orWhere('slug') 가 total 카운트를 부풀렸던 회귀(#225)를
     * all 검색 통합 후에도 재발하지 않는지 검증한다.
     * 고유 슬러그 토큰으로 결과를 1건으로 한정해 total ↔ 행 수 정합을 본다.
     */
    public function test_admin_search_all_total_matches_rows(): void
    {
        Page::factory()->create([
            'slug' => 'test-zztotalguard-one',
            'title' => ['ko' => '집계가드대상', 'en' => 'Count Guard'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?search=zztotalguard');

        $response->assertStatus(200);
        $total = $response->json('data.meta.total');
        $rowCount = count($response->json('data.data'));
        // 단일 페이지 결과: total 과 실제 반환 행 수가 일치해야 함 (부풀림 없음)
        $this->assertSame(1, $total);
        $this->assertSame(1, $rowCount);
    }

    /**
     * per_page 미지정 시 기본값이 20 인지 확인
     *
     * 회귀: 백엔드 기본값이 15 였으나 프론트 셀렉트는 20 을 표시해
     * '표시 20 / 실제 조회 15' 불일치 발생. 양쪽을 20 으로 통일한다.
     */
    public function test_admin_list_default_per_page_is_20(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages');

        $response->assertStatus(200);
        $this->assertSame(20, $response->json('data.meta.per_page'));
    }

    /**
     * 본문(content)에만 있는 단어는 제목/전체 검색 어느 쪽에서도 검색되지 않는지 확인 (E4)
     *
     * 검색 대상은 제목·슬러그이며 본문은 포함하지 않는다 (UI '제목 또는 슬러그로 검색',
     * 검색 필드 옵션 전체/제목/슬러그와 일치). '제목'뿐 아니라 '전체' 검색도 본문을 제외한다.
     *
     * 검증 범위: '본문 단어가 검색에 누출되지 않음'(항상 0건이라 트랜잭션 FULLTEXT 가시성과 무관하게 안정적).
     */
    public function test_admin_search_excludes_content(): void
    {
        Page::factory()->create([
            'slug' => 'test-content-scope',
            'title' => ['ko' => '제목쪽고유단어', 'en' => 'Heading'],
            'content' => ['ko' => '본문쪽고유단어', 'en' => 'Body'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // 본문에만 있는 단어는 '제목' 검색에서 0건
        $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?search=본문쪽고유단어&search_field=title')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 0);

        // 본문에만 있는 단어는 '전체' 검색에서도 0건 (본문은 검색 대상 아님)
        $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?search=본문쪽고유단어&search_field=all')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 0);
    }

    /**
     * 검색조건 '전체'(all)는 제목 외에 슬러그도 검색해 title 보다 넓은 범위인지 확인 (E4 대조)
     *
     * '전체' 검색 = 제목 + 슬러그. title 검색이 제목만 보더라도, all 검색은 슬러그까지 포함해야 한다.
     */
    public function test_admin_search_all_wider_than_title(): void
    {
        Page::factory()->create([
            'slug' => 'test-allscope-zzslugword',
            'title' => ['ko' => '무관한제목', 'en' => 'x'],
            'content' => ['ko' => '본문', 'en' => 'y'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // all 검색: 슬러그 토큰으로 잡혀야 함 (제목엔 없음)
        $all = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?search=zzslugword&search_field=all');
        $all->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $all->json('data.meta.total'));

        // title 검색: 같은 슬러그 토큰은 제목에 없으므로 0건 (범위가 좁음을 대조)
        $title = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?search=zzslugword&search_field=title');
        $title->assertStatus(200);
        $this->assertSame(0, $title->json('data.meta.total'));
    }

    /**
     * 검색어가 최대 길이를 초과하면 500 이 아닌 422 검증 오류를 반환하는지 확인 (E5)
     *
     * 회귀: 공백 없는 긴 한글(140자+)이 FULLTEXT phrase 토큰 한도를 초과해
     * 'Too many words in a FTS phrase'(191) → 500 을 유발했다.
     * search max 를 100 으로 제한해 긴 입력을 422 로 사전 차단한다.
     */
    public function test_admin_search_too_long_returns_422(): void
    {
        // 레이아웃이 실제로 쓰는 filters[0][value] 경로로 긴 검색어 전송 → 500 이 아닌 422
        $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?filters[0][field]=all&filters[0][value]='.str_repeat('가', 200))
            ->assertStatus(422);
    }

    // ─── 상세 조회 (show) ──────────────────────────────

    /**
     * 페이지 상세 정보를 조회할 수 있는지 확인
     */
    public function test_admin_can_show_page(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-show-detail',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-page/admin/pages/{$page->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id', 'slug', 'title', 'content', 'content_mode',
                    'published', 'current_version', 'creator', 'updater',
                ],
            ]);
        $this->assertEquals($page->id, $response->json('data.id'));
    }

    /**
     * 존재하지 않는 페이지 조회 시 404를 반환하는지 확인
     */
    public function test_show_nonexistent_page_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages/99999');

        $response->assertStatus(404);
    }

    /**
     * 관리자 상세 조회 시 첨부 URL이 공개 hash 라우트를 가리키는지 확인
     *
     * 썸네일 <img>·다운로드는 토큰을 실을 수 없어 인증 라우트에 물리면 401 로 깨진다.
     * 게시판·이커머스 표준과 동일하게 공개 hash 라우트로 단일화하고, 미발행 콘텐츠
     * 다운로드 차단은 공개 라우트 내부의 권한 게이트가 담당한다.
     */
    public function test_admin_show_returns_public_attachment_urls(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-admin-attach-url',
            'published' => false,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);
        $attachment = PageAttachment::factory()->image()->create([
            'page_id' => $page->id,
            'hash' => 'abc123def456',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-page/admin/pages/{$page->id}");

        $response->assertStatus(200);
        $att = $response->json('data.attachments.0');
        $this->assertSame(
            "/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}/preview",
            $att['preview_url']
        );
        $this->assertSame(
            "/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}",
            $att['download_url']
        );
    }

    /**
     * 공개 상세 조회 시 첨부 URL이 공개 라우트를 유지하는지 확인
     *
     * 공개 응답에 admin URL(발행 가드 없음)이 섞이면 미발행 첨부가
     * 노출되는 보안 회귀가 된다. 공개 응답은 공개 라우트만 반환해야 한다.
     */
    public function test_public_show_returns_public_attachment_urls(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-public-attach-url',
            'published' => true,
            'published_at' => now(),
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);
        $attachment = PageAttachment::factory()->image()->create([
            'page_id' => $page->id,
            'hash' => 'pub123def456',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->getJson("/api/modules/sirsoft-page/pages/{$page->slug}");

        $response->assertStatus(200);
        $att = $response->json('data.attachments.0');
        $this->assertSame(
            "/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}/preview",
            $att['preview_url']
        );
        $this->assertSame(
            "/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}",
            $att['download_url']
        );
    }

    // ─── 생성 (store) ──────────────────────────────────

    /**
     * 페이지를 생성할 수 있는지 확인
     */
    public function test_admin_can_create_page(): void
    {
        $data = [
            'slug' => 'test-create-page',
            'title' => ['ko' => '테스트 페이지', 'en' => 'Test Page'],
            'content' => ['ko' => '테스트 본문', 'en' => 'Test Content'],
            'content_mode' => 'html',
            'published' => false,
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', $data);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'test-create-page');

        $this->assertDatabaseHas('pages', ['slug' => 'test-create-page']);
    }

    /**
     * 발행 상태로 페이지를 생성하면 published_at이 설정되는지 확인
     */
    public function test_creating_published_page_sets_published_at(): void
    {
        $data = [
            'slug' => 'test-create-published',
            'title' => ['ko' => '발행 페이지', 'en' => 'Published Page'],
            'content' => ['ko' => '본문', 'en' => 'Content'],
            'content_mode' => 'html',
            'published' => true,
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', $data);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.published'));
        $this->assertNotNull($response->json('data.published_at'));
    }

    /**
     * 페이지 생성 시 버전 1 스냅샷이 생성되는지 확인
     */
    public function test_creating_page_creates_version_snapshot(): void
    {
        $data = [
            'slug' => 'test-create-version',
            'title' => ['ko' => '버전 테스트', 'en' => 'Version Test'],
            'content' => ['ko' => '본문', 'en' => 'Content'],
            'content_mode' => 'html',
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', $data);

        $response->assertStatus(201);
        $pageId = $response->json('data.id');

        $version = PageVersion::where('page_id', $pageId)->first();
        $this->assertNotNull($version);
        $this->assertEquals(1, $version->version);
    }

    /**
     * 필수 필드가 누락되면 422를 반환하는지 확인
     */
    public function test_creating_page_without_required_fields_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug', 'title']);
    }

    /**
     * 중복 슬러그로 생성 시 422를 반환하는지 확인
     */
    public function test_creating_page_with_duplicate_slug_returns_422(): void
    {
        Page::factory()->create([
            'slug' => 'test-duplicate-slug',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $data = [
            'slug' => 'test-duplicate-slug',
            'title' => ['ko' => '중복 테스트', 'en' => 'Duplicate Test'],
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    // ─── 수정 (update) ─────────────────────────────────

    /**
     * 페이지를 수정할 수 있는지 확인
     */
    public function test_admin_can_update_page(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-update-page',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $updateData = [
            'title' => ['ko' => '수정된 제목', 'en' => 'Updated Title'],
            'content' => ['ko' => '수정된 본문', 'en' => 'Updated Content'],
        ];

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $page->refresh();
        $this->assertEquals('수정된 제목', $page->title['ko']);
    }

    /**
     * 수정 시 버전 번호가 증가하고 스냅샷이 생성되는지 확인
     */
    public function test_updating_page_increments_version(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-update-version',
            'current_version' => 1,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // 버전 1 스냅샷 생성 (서비스에서 자동 생성하지만, 직접 생성하여 테스트 안정성 확보)
        PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => $page->title,
            'content' => $page->content,
            'content_mode' => $page->content_mode,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'title' => ['ko' => '버전 2', 'en' => 'Version 2'],
            ]);

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.current_version'));

        // 버전 2 스냅샷이 생성되었는지 확인
        $this->assertDatabaseHas('page_versions', [
            'page_id' => $page->id,
            'version' => 2,
        ]);
    }

    /**
     * 존재하지 않는 페이지 수정 시 404를 반환하는지 확인
     */
    public function test_updating_nonexistent_page_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-page/admin/pages/99999', [
                'title' => ['ko' => '없는 페이지', 'en' => 'Not Found'],
            ]);

        $response->assertStatus(404);
    }

    // ─── 수정 - 슬러그 변경 (Issue #280) ─────────────────

    /**
     * 슬러그를 새 값으로 변경하면 DB에 반영됨
     */
    public function test_admin_can_update_page_slug(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-old',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'slug' => 'test-slug-new',
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'slug' => 'test-slug-new']);
        $this->assertDatabaseMissing('pages', ['slug' => 'test-slug-old']);
    }

    /**
     * 슬러그를 동일한 값으로 수정해도 통과 (자기 자신 ignore)
     */
    public function test_updating_page_slug_to_same_value_succeeds(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-same',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'slug' => 'test-slug-same',
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(200);
    }

    /**
     * slug 키를 전송하지 않으면 기존 슬러그가 유지됨
     */
    public function test_updating_page_without_slug_keeps_existing_slug(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-keep',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'title' => ['ko' => '제목만 변경', 'en' => 'Title only'],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'slug' => 'test-slug-keep']);
    }

    /**
     * slug를 전송하지 않으면 기존 슬러그가 유지됨 (sometimes 동작)
     */
    public function test_updating_page_without_slug_field_keeps_existing_slug(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-keep',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'slug' => 'test-slug-keep']);
    }

    /**
     * slug 형식 위반(공백+특수문자) 시 422 반환
     */
    public function test_updating_page_with_space_and_special_chars_in_slug_returns_422(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-valid',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'slug' => 'Invalid Slug!',
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    /**
     * 다른 페이지의 슬러그로 변경 시 422 반환
     */
    public function test_updating_page_with_duplicate_slug_returns_422(): void
    {
        Page::factory()->create([
            'slug' => 'test-slug-taken',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $page = Page::factory()->create([
            'slug' => 'test-slug-mine',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'slug' => 'test-slug-taken',
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    /**
     * 슬러그 형식 위반(대문자 포함) 시 422 반환
     */
    public function test_updating_page_with_invalid_slug_format_returns_422(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-format',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'slug' => 'Invalid-Slug-WithUpper',
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    /**
     * 슬러그 형식 위반(특수문자) 시 422 반환
     */
    public function test_updating_page_with_special_chars_in_slug_returns_422(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-special',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'slug' => 'invalid slug!@#',
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    /**
     * 슬러그 최대 길이(256자) 초과 시 422 반환
     */
    public function test_updating_page_with_slug_exceeding_max_length_returns_422(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-maxlen',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'slug' => str_repeat('a', 256),
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    /**
     * 슬러그 변경 시에도 버전 번호가 증가함
     */
    public function test_updating_slug_increments_version(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-slug-v1',
            'current_version' => 1,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => $page->title,
            'content' => $page->content,
            'content_mode' => $page->content_mode,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'slug' => 'test-slug-v2',
                'title' => ['ko' => '제목', 'en' => 'Title'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.current_version', 2);

        $this->assertDatabaseHas('page_versions', ['page_id' => $page->id, 'version' => 2]);
    }

    // ─── 삭제 (destroy) ────────────────────────────────

    /**
     * 페이지를 삭제(물리 삭제)할 수 있는지 확인
     */
    public function test_admin_can_delete_page(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-delete-page',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-page/admin/pages/{$page->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // 물리 삭제 확인 (soft delete 미사용 — 행이 실제로 제거됨)
        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    /**
     * 존재하지 않는 페이지 삭제 시 404를 반환하는지 확인
     */
    public function test_deleting_nonexistent_page_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->deleteJson('/api/modules/sirsoft-page/admin/pages/99999');

        $response->assertStatus(404);
    }

    /**
     * 페이지 삭제 후 동일 슬러그로 재생성할 수 있는지 확인 (회귀)
     *
     * soft delete 시절에는 삭제된 페이지의 slug 가 잔존하여 동일 slug 재생성 시
     * Rule::unique 가 422 를 반환했다. hard delete 전환으로 재생성이 성공해야 한다.
     */
    public function test_can_recreate_page_with_same_slug_after_delete(): void
    {
        $slug = 'test-reuse-slug';

        // 1) 최초 생성
        $first = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', [
                'slug' => $slug,
                'title' => ['ko' => '원본 페이지', 'en' => 'Original'],
                'content' => ['ko' => '본문', 'en' => 'Content'],
                'content_mode' => 'html',
            ]);
        $first->assertStatus(201);
        $firstId = $first->json('data.id');

        // 2) 삭제
        $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-page/admin/pages/{$firstId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('pages', ['id' => $firstId]);

        // 3) 동일 slug 로 재생성 — 성공해야 함
        $second = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', [
                'slug' => $slug,
                'title' => ['ko' => '재생성 페이지', 'en' => 'Recreated'],
                'content' => ['ko' => '본문', 'en' => 'Content'],
                'content_mode' => 'html',
            ]);

        $second->assertStatus(201)
            ->assertJsonPath('data.slug', $slug);
    }

    /**
     * 페이지 삭제 후 슬러그 중복 체크와 생성 검증 결과가 일치하는지 확인 (회귀)
     *
     * 삭제 후 check-slug 는 "사용 가능", 생성도 성공해야 한다 (두 경로 정합).
     */
    public function test_check_slug_and_create_are_consistent_after_delete(): void
    {
        $slug = 'test-consistent-slug';

        $first = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', [
                'slug' => $slug,
                'title' => ['ko' => '원본', 'en' => 'Original'],
                'content_mode' => 'html',
            ]);
        $first->assertStatus(201);
        $firstId = $first->json('data.id');

        $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-page/admin/pages/{$firstId}")
            ->assertStatus(200);

        // check-slug 는 사용 가능(exists=false)으로 응답
        $check = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages/check-slug', [
                'slug' => $slug,
            ]);
        $check->assertStatus(200)
            ->assertJsonPath('data.exists', false);

        // 생성도 성공 (check-slug 와 동일 결론)
        $create = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', [
                'slug' => $slug,
                'title' => ['ko' => '재생성', 'en' => 'Recreated'],
                'content_mode' => 'html',
            ]);
        $create->assertStatus(201);
    }

    /**
     * 첨부가 있는 페이지를 물리 삭제해도 삭제 흐름(첨부 정리 + 활동로그 기록)이 정상 완료되는지 확인
     *
     * 활동로그 리스너는 삭제되는 페이지를 loggable 로 참조한다. hard delete 후 loggable 행이
     * 사라지더라도 삭제 응답이 200 이고 페이지/첨부 행이 모두 물리 삭제되어야 한다.
     */
    public function test_deleting_page_with_attachment_completes_without_error(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-delete-with-attach',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $attachment = PageAttachment::factory()->create([
            'page_id' => $page->id,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-page/admin/pages/{$page->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // 페이지 + 첨부 모두 물리 삭제
        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('page_attachments', ['id' => $attachment->id]);
    }

    // ─── 발행 토글 (publish) ───────────────────────────

    /**
     * 페이지 발행 상태를 변경할 수 있는지 확인
     */
    public function test_admin_can_publish_page(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-publish-toggle',
            'published' => false,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/publish", [
                'published' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.published', true);

        $page->refresh();
        $this->assertTrue($page->published);
        $this->assertNotNull($page->published_at);
    }

    /**
     * 페이지를 미발행으로 변경할 수 있는지 확인
     */
    public function test_admin_can_unpublish_page(): void
    {
        $page = Page::factory()->published()->create([
            'slug' => 'test-unpublish-toggle',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/publish", [
                'published' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.published', false);

        $page->refresh();
        $this->assertFalse($page->published);
    }

    // ─── 일괄 발행 (bulkPublish) ───────────────────────

    /**
     * 여러 페이지를 일괄 발행할 수 있는지 확인
     */
    public function test_admin_can_bulk_publish_pages(): void
    {
        $pages = Page::factory()->count(3)->create([
            'published' => false,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $ids = $pages->pluck('id')->toArray();

        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-page/admin/pages/bulk-publish', [
                'ids' => $ids,
                'published' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 3);

        // 성공 메시지의 :count 플레이스홀더가 실제 건수로 치환되는지 확인 (회귀)
        $message = $response->json('message');
        $this->assertStringNotContainsString(':count', $message, '메시지에 :count 플레이스홀더가 치환되지 않고 남았습니다.');
        $this->assertStringContainsString('3', $message, '메시지에 변경 건수(3)가 포함되어야 합니다.');

        // DB 확인
        foreach ($ids as $id) {
            $this->assertDatabaseHas('pages', ['id' => $id, 'published' => true]);
        }
    }

    /**
     * 여러 페이지를 일괄 미발행할 수 있는지 확인
     */
    public function test_admin_can_bulk_unpublish_pages(): void
    {
        $pages = Page::factory()->published()->count(2)->create([
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $ids = $pages->pluck('id')->toArray();

        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-page/admin/pages/bulk-publish', [
                'ids' => $ids,
                'published' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 2);
    }

    /**
     * 빈 ID 배열로 일괄 발행 시 422를 반환하는지 확인
     */
    public function test_bulk_publish_with_empty_ids_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-page/admin/pages/bulk-publish', [
                'ids' => [],
                'published' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ids']);
    }

    // ─── 슬러그 체크 (checkSlug) ───────────────────────

    /**
     * 사용 가능한 슬러그를 확인할 수 있는지 검증
     */
    public function test_admin_can_check_available_slug(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages/check-slug', [
                'slug' => 'test-available-slug',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.exists', false);
    }

    /**
     * 이미 사용 중인 슬러그를 확인할 수 있는지 검증
     */
    public function test_admin_can_check_existing_slug(): void
    {
        Page::factory()->create([
            'slug' => 'test-existing-slug',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages/check-slug', [
                'slug' => 'test-existing-slug',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.exists', true);
    }

    /**
     * 수정 시 자기 자신의 슬러그는 중복으로 판단하지 않는지 확인
     */
    public function test_check_slug_excludes_own_id(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-own-slug',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages/check-slug', [
                'slug' => 'test-own-slug',
                'exclude_id' => $page->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.exists', false);
    }

    /**
     * 슬러그에 언더스코어 사용 시 regex 검증 실패 메시지가 다국어로 반환되는지 확인
     */
    public function test_check_slug_regex_returns_localized_message(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages/check-slug', [
                'slug' => 'sms_marketing',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);

        // 다국어 키가 그대로 노출되지 않고 번역된 메시지가 반환되는지 확인
        $slugErrors = $response->json('errors.slug');
        $this->assertNotEmpty($slugErrors);
        $this->assertStringNotContainsString('sirsoft-page::validation', $slugErrors[0]);
    }

    /**
     * filters 배열 형식으로 검색이 동작하는지 확인
     */
    public function test_admin_can_search_pages_with_filters_array(): void
    {
        Page::factory()->create([
            'slug' => 'test-filters-target',
            'title' => ['ko' => '필터검색대상', 'en' => 'Filter Search Target'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?'.http_build_query([
                'filters' => [
                    ['field' => 'slug', 'value' => 'filters-target', 'operator' => 'like'],
                ],
            ]));

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('data.meta.total'));
    }

    /**
     * 페이지네이션이 올바르게 동작하는지 확인 (per_page 설정)
     */
    public function test_admin_can_paginate_pages(): void
    {
        Page::factory()->count(5)->create([
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.per_page', 2);

        $this->assertGreaterThan(1, $response->json('data.meta.last_page'));
        $this->assertCount(2, $response->json('data.data'));
    }

    // ─── 버전 이력 (versions) ──────────────────────────

    /**
     * 페이지 버전 이력을 조회할 수 있는지 확인
     */
    public function test_admin_can_list_page_versions(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-versions-list',
            'current_version' => 2,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => ['ko' => '버전 1', 'en' => 'Version 1'],
            'content' => ['ko' => '내용 1', 'en' => 'Content 1'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);
        PageVersion::create([
            'page_id' => $page->id,
            'version' => 2,
            'title' => ['ko' => '버전 2', 'en' => 'Version 2'],
            'content' => ['ko' => '내용 2', 'en' => 'Content 2'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/versions");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'page_id', 'version', 'title', 'content'],
                ],
            ]);
        $this->assertCount(2, $response->json('data'));
    }

    /**
     * 특정 버전 상세 정보를 조회할 수 있는지 확인
     */
    public function test_admin_can_show_specific_version(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-version-show',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $version = PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => ['ko' => '상세 버전', 'en' => 'Detail Version'],
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/versions/{$version->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.version', 1);
    }

    // ─── 버전 복원 (restoreVersion) ────────────────────

    /**
     * 특정 버전으로 페이지를 복원할 수 있는지 확인
     */
    public function test_admin_can_restore_version(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-restore-version',
            'title' => ['ko' => '현재 제목', 'en' => 'Current Title'],
            'current_version' => 2,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // 버전 1 스냅샷
        $version1 = PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => ['ko' => '원래 제목', 'en' => 'Original Title'],
            'content' => ['ko' => '원래 내용', 'en' => 'Original Content'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        // 버전 2 스냅샷
        PageVersion::create([
            'page_id' => $page->id,
            'version' => 2,
            'title' => ['ko' => '현재 제목', 'en' => 'Current Title'],
            'content' => ['ko' => '현재 내용', 'en' => 'Current Content'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/versions/{$version1->id}/restore");

        $response->assertStatus(200);

        $page->refresh();
        $this->assertEquals('원래 제목', $page->title['ko']);
        $this->assertEquals(3, $page->current_version); // 복원 후 버전 증가
    }

    /**
     * 버전 복원 시 페이지 상세 SEO 캐시가 무효화되는지 확인
     *
     * 복원은 title/content/seo_meta 를 이전 버전으로 되돌리므로 수정과 동일하게
     * 검색봇용 SEO 캐시를 갱신해야 한다. 복원 전 상세 URL 캐시를 적재한 뒤 복원하면
     * after_restore 훅 → SeoPageCacheListener 가 해당 캐시를 무효화해야 한다.
     * (회귀 배경: after_restore 미구독으로 복원 후에도 봇에 복원 전 버전이 잔존)
     */
    public function test_restore_version_invalidates_page_detail_seo_cache(): void
    {
        $page = Page::factory()->create([
            'slug' => 'restore-seo-cache',
            'title' => ['ko' => '현재 제목', 'en' => 'Current Title'],
            'current_version' => 2,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $version1 = PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => ['ko' => '원래 제목', 'en' => 'Original Title'],
            'content' => ['ko' => '원래 내용', 'en' => 'Original Content'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        PageVersion::create([
            'page_id' => $page->id,
            'version' => 2,
            'title' => ['ko' => '현재 제목', 'en' => 'Current Title'],
            'content' => ['ko' => '현재 내용', 'en' => 'Current Content'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        // 복원 전 페이지 상세 URL 캐시 적재 (봇 응답 캐시 시뮬레이션)
        $cache = app(SeoCacheManagerInterface::class);
        $cache->put("/page/{$page->slug}", 'ko', '<html>현재 내용</html>');
        $this->assertContains("/page/{$page->slug}", $cache->getCachedUrls());

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/versions/{$version1->id}/restore");

        $response->assertStatus(200);

        // 복원 후 상세 캐시가 무효화되어 봇이 새로 렌더한다
        $this->assertNotContains("/page/{$page->slug}", $cache->getCachedUrls());
    }

    /**
     * 버전 복원 후 새 버전이 생성되고 기존 버전 이력이 모두 보존되는지 확인 (검수항목 3·4)
     *
     * 복원은 다음 버전 번호로 새 스냅샷을 만들며, 복원 대상을 포함한 기존 버전 이력을
     * 삭제하지 않고 그대로 보존해야 한다.
     */
    public function test_restore_version_creates_new_version_and_preserves_history(): void
    {
        $page = Page::factory()->create([
            'slug' => 'restore-history-preserved',
            'title' => ['ko' => '현재 제목', 'en' => 'Current Title'],
            'current_version' => 2,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $version1 = PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => ['ko' => '원래 제목', 'en' => 'Original Title'],
            'content' => ['ko' => '원래 내용', 'en' => 'Original Content'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        PageVersion::create([
            'page_id' => $page->id,
            'version' => 2,
            'title' => ['ko' => '현재 제목', 'en' => 'Current Title'],
            'content' => ['ko' => '현재 내용', 'en' => 'Current Content'],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        $this->actingAs($this->adminUser)
            ->postJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/versions/{$version1->id}/restore")
            ->assertStatus(200);

        // 기존 버전 1·2 는 보존되고, 복원으로 버전 3 이 새로 생성되어 총 3건
        $versions = PageVersion::where('page_id', $page->id)->orderBy('version')->get();
        $this->assertCount(3, $versions);
        $this->assertEquals([1, 2, 3], $versions->pluck('version')->all());
        // 복원 대상(버전 1) 레코드가 삭제되지 않고 남아 있다
        $this->assertNotNull(PageVersion::find($version1->id));
    }

    // ─── 인증/권한 차단 ────────────────────────────────

    /**
     * 미인증 사용자가 페이지 목록에 접근할 수 없는지 확인
     */
    public function test_unauthenticated_user_cannot_access_admin_pages(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-page/admin/pages');

        $response->assertStatus(401);
    }

    /**
     * 권한 없는 사용자가 페이지를 생성할 수 없는지 확인
     */
    public function test_user_without_permission_cannot_create_page(): void
    {
        // 일반 사용자 (admin 권한 없음)
        $normalUser = $this->createUser();

        $data = [
            'slug' => 'test-no-permission',
            'title' => ['ko' => '권한 없음', 'en' => 'No Permission'],
            'content_mode' => 'html',
        ];

        $response = $this->actingAs($normalUser)
            ->postJson('/api/modules/sirsoft-page/admin/pages', $data);

        $response->assertStatus(403);
    }

    /**
     * 일반 사용자가 관리자 API에 접근할 수 없는지 확인
     */
    public function test_normal_user_cannot_access_admin_pages(): void
    {
        $normalUser = $this->createUser();

        $response = $this->actingAs($normalUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages');

        $response->assertStatus(403);
    }

    // ─── Collection abilities 응답 ───────────────────

    /**
     * 페이지 목록 응답에 collection-level abilities가 포함되는지 확인
     */
    public function test_list_response_includes_collection_abilities(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'abilities' => ['can_create', 'can_update', 'can_delete'],
                ],
            ]);

        // 모든 권한을 가진 관리자이므로 모두 true
        $this->assertTrue($response->json('data.abilities.can_create'));
        $this->assertTrue($response->json('data.abilities.can_update'));
        $this->assertTrue($response->json('data.abilities.can_delete'));
    }

    /**
     * read 권한만 가진 사용자의 collection abilities가 올바른지 확인
     */
    public function test_list_response_abilities_reflect_read_only_user(): void
    {
        // 별도 역할을 생성하여 read 권한만 부여 (admin 역할과 분리)
        $readOnlyRole = Role::create([
            'identifier' => 'test_page_read_only_'.uniqid(),
            'name' => ['ko' => '읽기전용', 'en' => 'Read Only'],
            'is_active' => true,
        ]);

        $readPermission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-page.pages.read'],
            [
                'name' => ['ko' => '페이지 읽기', 'en' => 'Page Read'],
                'type' => PermissionType::Admin,
            ]
        );
        $readOnlyRole->permissions()->attach($readPermission->id);

        $readOnlyUser = User::factory()->create();
        $readOnlyUser->roles()->attach($readOnlyRole->id);

        // PermissionHelper 캐시 초기화
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $response = $this->actingAs($readOnlyUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages');

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.abilities.can_create'));
        $this->assertFalse($response->json('data.abilities.can_update'));
        $this->assertFalse($response->json('data.abilities.can_delete'));
    }

    // ─── Per-item abilities 응답 ─────────────────────

    /**
     * 페이지 상세 응답에 per-item abilities가 포함되는지 확인
     */
    public function test_show_response_includes_per_item_abilities(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-show-abilities',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-page/admin/pages/{$page->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'abilities' => ['can_create', 'can_update', 'can_delete'],
                ],
            ]);

        $this->assertTrue($response->json('data.abilities.can_update'));
        $this->assertTrue($response->json('data.abilities.can_delete'));
    }

    /**
     * 목록의 각 항목에도 per-item abilities가 포함되는지 확인
     */
    public function test_list_items_include_per_item_abilities(): void
    {
        Page::factory()->create([
            'slug' => 'test-list-item-abilities',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages');

        $response->assertStatus(200);

        $items = $response->json('data.data');
        $this->assertNotEmpty($items);

        // 각 항목에 abilities 존재
        foreach ($items as $item) {
            $this->assertArrayHasKey('abilities', $item);
            $this->assertArrayHasKey('can_update', $item['abilities']);
            $this->assertArrayHasKey('can_delete', $item['abilities']);
        }
    }

    // ─── Service-level scope 403 검증 ────────────────

    /**
     * scope=self인 사용자가 타인의 페이지를 조회할 수 없는지 확인
     */
    public function test_scope_self_user_cannot_show_others_page(): void
    {
        // scope=self 사용자 생성
        $scopeUser = $this->createScopeUser(ScopeType::Self);

        // 다른 사용자가 만든 페이지
        $page = Page::factory()->create([
            'slug' => 'test-scope-deny-show',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($scopeUser)
            ->getJson("/api/modules/sirsoft-page/admin/pages/{$page->id}");

        $response->assertStatus(403);
    }

    /**
     * scope=self인 사용자가 자기 페이지를 조회할 수 있는지 확인
     */
    public function test_scope_self_user_can_show_own_page(): void
    {
        $scopeUser = $this->createScopeUser(ScopeType::Self);

        $page = Page::factory()->create([
            'slug' => 'test-scope-allow-show',
            'created_by' => $scopeUser->id,
            'updated_by' => $scopeUser->id,
        ]);

        $response = $this->actingAs($scopeUser)
            ->getJson("/api/modules/sirsoft-page/admin/pages/{$page->id}");

        $response->assertStatus(200);
    }

    /**
     * scope=self인 사용자가 타인의 페이지를 수정할 수 없는지 확인
     */
    public function test_scope_self_user_cannot_update_others_page(): void
    {
        $scopeUser = $this->createScopeUser(ScopeType::Self);

        $page = Page::factory()->create([
            'slug' => 'test-scope-deny-update',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($scopeUser)
            ->putJson("/api/modules/sirsoft-page/admin/pages/{$page->id}", [
                'title' => ['ko' => '수정 시도', 'en' => 'Update Attempt'],
            ]);

        $response->assertStatus(403);
    }

    /**
     * scope=self인 사용자가 타인의 페이지를 삭제할 수 없는지 확인
     */
    public function test_scope_self_user_cannot_delete_others_page(): void
    {
        $scopeUser = $this->createScopeUser(ScopeType::Self);

        $page = Page::factory()->create([
            'slug' => 'test-scope-deny-delete',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($scopeUser)
            ->deleteJson("/api/modules/sirsoft-page/admin/pages/{$page->id}");

        $response->assertStatus(403);
    }

    /**
     * scope=self인 사용자가 타인 페이지의 발행 상태를 변경할 수 없는지 확인
     */
    public function test_scope_self_user_cannot_publish_others_page(): void
    {
        $scopeUser = $this->createScopeUser(ScopeType::Self);

        $page = Page::factory()->create([
            'slug' => 'test-scope-deny-publish',
            'published' => false,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($scopeUser)
            ->patchJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/publish", [
                'published' => true,
            ]);

        $response->assertStatus(403);
    }

    /**
     * scope=self인 사용자가 타인 페이지의 버전을 복원할 수 없는지 확인
     */
    public function test_scope_self_user_cannot_restore_others_page_version(): void
    {
        $scopeUser = $this->createScopeUser(ScopeType::Self);

        $page = Page::factory()->create([
            'slug' => 'test-scope-deny-restore',
            'current_version' => 1,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $version = PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'title' => $page->title,
            'content' => $page->content ?? ['ko' => '', 'en' => ''],
            'content_mode' => 'html',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($scopeUser)
            ->postJson("/api/modules/sirsoft-page/admin/pages/{$page->id}/versions/{$version->id}/restore");

        $response->assertStatus(403);
    }

    // ─── scope 헬퍼 ─────────────────────────────────

    /**
     * scope 권한이 설정된 관리자 사용자를 생성합니다.
     *
     * @param  ScopeType  $scopeType  스코프 타입
     * @return User 생성된 사용자
     */
    private function createScopeUser(ScopeType $scopeType): User
    {
        $user = User::factory()->create();

        // 역할 생성
        $role = Role::firstOrCreate(
            ['identifier' => 'test_page_ctrl_scope'],
            [
                'name' => ['ko' => '페이지 스코프 테스트', 'en' => 'Page Scope Test'],
                'is_active' => true,
            ]
        );
        $user->roles()->attach($role->id);

        // 실제 페이지 권한 4개 설정 (resource_route_key + owner_key 포함)
        $permissionIds = ['sirsoft-page.pages.read', 'sirsoft-page.pages.create', 'sirsoft-page.pages.update', 'sirsoft-page.pages.delete'];

        foreach ($permissionIds as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'type' => PermissionType::Admin,
                ]
            );

            // resource_route_key와 owner_key 설정
            $permission->update([
                'resource_route_key' => 'page',
                'owner_key' => 'created_by',
            ]);

            $role->permissions()->syncWithoutDetaching([
                $permission->id => ['scope_type' => $scopeType],
            ]);
        }

        // PermissionHelper 캐시 초기화
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        return $user;
    }
}
