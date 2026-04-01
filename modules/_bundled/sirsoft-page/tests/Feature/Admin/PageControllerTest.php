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
use App\Search\Engines\DatabaseFulltextEngine;
use Laravel\Scout\EngineManager;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * LIKE fallback 전용 Scout 엔진
 *
 * MySQL FULLTEXT는 트랜잭션 내 미커밋 데이터를 인덱싱하지 않으므로,
 * 테스트에서 Scout 파이프라인 전체를 검증하기 위해 LIKE fallback을 강제합니다.
 */
class LikeFallbackEngine extends DatabaseFulltextEngine
{
    public static function supportsFulltext(): bool
    {
        return false;
    }
}

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
     */
    public function test_admin_can_search_pages(): void
    {
        // Scout 엔진을 LIKE fallback으로 교체 (트랜잭션 호환)
        $this->swapScoutEngineToLikeFallback();

        Page::factory()->create([
            'slug' => 'test-search-target',
            'title' => ['ko' => '검색대상페이지', 'en' => 'Search Target'],
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/pages?search=검색대상');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('data.meta.total'));
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

    // ─── 삭제 (destroy) ────────────────────────────────

    /**
     * 페이지를 삭제(소프트 삭제)할 수 있는지 확인
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

        // 소프트 삭제 확인
        $this->assertSoftDeleted('pages', ['id' => $page->id]);
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
            ->getJson('/api/modules/sirsoft-page/admin/pages?' . http_build_query([
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
            'identifier' => 'test_page_read_only_' . uniqid(),
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

    /**
     * Scout 엔진을 LIKE fallback 모드로 교체합니다.
     *
     * MySQL FULLTEXT는 트랜잭션 내 미커밋 데이터를 검색하지 못하므로,
     * Scout 파이프라인 전체(Model::search → EngineManager → performSearch → 쿼리)를
     * 검증하기 위해 LIKE fallback 엔진으로 교체합니다.
     *
     * @return void
     */
    private function swapScoutEngineToLikeFallback(): void
    {
        $manager = $this->app->make(EngineManager::class);
        $manager->extend('mysql-fulltext', fn () => new LikeFallbackEngine());

        // EngineManager 캐시된 드라이버 인스턴스 초기화
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('drivers');
        $property->setAccessible(true);
        $property->setValue($manager, []);
    }
}
