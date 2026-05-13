<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Services;

use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * PageService 단위 테스트
 *
 * 페이지 생성/수정/삭제/발행/버전 관리 비즈니스 로직을 검증합니다.
 */
class PageServiceTest extends ModuleTestCase
{
    private PageService $service;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([]);

        // Unit 테스트에서는 HTTP 요청 없이 Auth::id()를 사용하므로
        // web guard와 sanctum guard 모두에 사용자를 설정
        Auth::setUser($this->adminUser);
        $this->actingAs($this->adminUser);

        $this->service = app(PageService::class);
    }

    // ─── createPage ────────────────────────────────────

    /**
     * 페이지를 생성할 수 있는지 확인
     */
    public function test_create_page_returns_page_model(): void
    {
        $data = [
            'slug' => 'test-svc-create',
            'title' => ['ko' => '서비스 테스트', 'en' => 'Service Test'],
            'content' => ['ko' => '본문', 'en' => 'Content'],
            'content_mode' => 'html',
        ];

        $page = $this->service->createPage($data);

        $this->assertInstanceOf(Page::class, $page);
        $this->assertEquals('test-svc-create', $page->slug);
        $this->assertEquals(1, $page->current_version);
    }

    /**
     * 페이지 생성 시 버전 1 스냅샷이 자동 생성되는지 확인
     */
    public function test_create_page_creates_version_one(): void
    {
        $page = $this->service->createPage([
            'slug' => 'test-svc-version',
            'title' => ['ko' => '버전 테스트', 'en' => 'Version'],
            'content' => ['ko' => '본문', 'en' => 'Body'],
            'content_mode' => 'html',
        ]);

        $versions = PageVersion::where('page_id', $page->id)->get();
        $this->assertCount(1, $versions);
        $this->assertEquals(1, $versions->first()->version);
    }

    /**
     * 발행 상태로 생성 시 published_at이 설정되는지 확인
     */
    public function test_create_published_page_sets_published_at(): void
    {
        $page = $this->service->createPage([
            'slug' => 'test-svc-pub',
            'title' => ['ko' => '발행', 'en' => 'Published'],
            'content' => ['ko' => '본문', 'en' => 'Body'],
            'content_mode' => 'html',
            'published' => true,
        ]);

        $this->assertTrue($page->published);
        $this->assertNotNull($page->published_at);
    }

    // ─── updatePage ────────────────────────────────────

    /**
     * 페이지를 수정할 수 있는지 확인
     */
    public function test_update_page_changes_title(): void
    {
        $page = $this->createTestPage('test-svc-update');

        $updated = $this->service->updatePage($page, [
            'title' => ['ko' => '수정됨', 'en' => 'Updated'],
        ]);

        $this->assertEquals('수정됨', $updated->title['ko']);
    }

    /**
     * 수정 시 버전 번호가 증가하는지 확인
     */
    public function test_update_page_increments_version(): void
    {
        $page = $this->createTestPage('test-svc-ver-inc');
        $originalVersion = $page->current_version;

        $updated = $this->service->updatePage($page, [
            'title' => ['ko' => '버전 증가', 'en' => 'Version Up'],
        ]);

        $this->assertEquals($originalVersion + 1, $updated->current_version);
    }

    /**
     * 수정 시 새 버전 스냅샷이 생성되는지 확인
     */
    public function test_update_page_creates_new_version_snapshot(): void
    {
        $page = $this->createTestPage('test-svc-snap');

        $this->service->updatePage($page, [
            'title' => ['ko' => '스냅샷', 'en' => 'Snapshot'],
        ]);

        $versionCount = PageVersion::where('page_id', $page->id)->count();
        $this->assertEquals(2, $versionCount); // 생성 시 1 + 수정 시 1
    }

    // ─── deletePage ────────────────────────────────────

    /**
     * 페이지를 소프트 삭제할 수 있는지 확인
     */
    public function test_delete_page_soft_deletes(): void
    {
        $page = $this->createTestPage('test-svc-delete');

        $result = $this->service->deletePage($page);

        $this->assertTrue($result);
        $this->assertSoftDeleted('pages', ['id' => $page->id]);
    }

    // ─── changePublishStatus ───────────────────────────

    /**
     * 발행 상태를 변경할 수 있는지 확인
     */
    public function test_change_publish_status_to_published(): void
    {
        $page = $this->createTestPage('test-svc-publish');

        $published = $this->service->changePublishStatus($page, true);

        $this->assertTrue($published->published);
        $this->assertNotNull($published->published_at);
    }

    /**
     * 미발행 상태로 변경할 수 있는지 확인
     */
    public function test_change_publish_status_to_unpublished(): void
    {
        $page = Page::factory()->published()->create([
            'slug' => 'test-svc-unpub',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $unpublished = $this->service->changePublishStatus($page, false);

        $this->assertFalse($unpublished->published);
    }

    // ─── bulkChangePublishStatus ───────────────────────

    /**
     * 여러 페이지를 일괄 발행할 수 있는지 확인
     */
    public function test_bulk_change_publish_status(): void
    {
        $pages = Page::factory()->count(3)->create([
            'published' => false,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $count = $this->service->bulkChangePublishStatus(
            $pages->pluck('id')->toArray(),
            true
        );

        $this->assertEquals(3, $count);
    }

    // ─── restoreVersion ────────────────────────────────

    /**
     * 특정 버전으로 복원할 수 있는지 확인
     */
    public function test_restore_version_reverts_content(): void
    {
        $page = $this->createTestPage('test-svc-restore');
        $originalTitle = $page->title;

        // 수정하여 버전 2 생성
        $page = $this->service->updatePage($page, [
            'title' => ['ko' => '변경된 제목', 'en' => 'Changed Title'],
        ]);

        // 버전 1 가져오기
        $version1 = PageVersion::where('page_id', $page->id)
            ->where('version', 1)
            ->first();

        // 버전 1로 복원
        $restored = $this->service->restoreVersion($page, $version1->id);

        $this->assertEquals($originalTitle['ko'], $restored->title['ko']);
        $this->assertEquals(3, $restored->current_version); // 복원 후 버전 증가
    }

    // ─── slugExists ────────────────────────────────────

    /**
     * 슬러그 존재 여부를 확인할 수 있는지 검증
     */
    public function test_slug_exists_returns_true_for_existing_slug(): void
    {
        $this->createTestPage('test-svc-slug-exist');

        $this->assertTrue($this->service->slugExists('test-svc-slug-exist'));
    }

    /**
     * 존재하지 않는 슬러그는 false를 반환하는지 확인
     */
    public function test_slug_exists_returns_false_for_nonexistent(): void
    {
        $this->assertFalse($this->service->slugExists('test-svc-no-slug'));
    }

    /**
     * excludeId로 자기 자신을 제외할 수 있는지 확인
     */
    public function test_slug_exists_excludes_own_id(): void
    {
        $page = $this->createTestPage('test-svc-exclude');

        $this->assertFalse($this->service->slugExists('test-svc-exclude', $page->id));
    }

    // ─── getPublishedPageBySlug ────────────────────────

    /**
     * 발행된 페이지를 슬러그로 조회할 수 있는지 확인
     */
    public function test_get_published_page_by_slug(): void
    {
        Page::factory()->published()->create([
            'slug' => 'test-svc-pub-slug',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $page = $this->service->getPublishedPageBySlug('test-svc-pub-slug');

        $this->assertNotNull($page);
        $this->assertEquals('test-svc-pub-slug', $page->slug);
    }

    /**
     * 미발행 페이지는 슬러그로 조회할 수 없는지 확인
     */
    public function test_get_published_page_by_slug_returns_null_for_draft(): void
    {
        Page::factory()->create([
            'slug' => 'test-svc-draft-slug',
            'published' => false,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $page = $this->service->getPublishedPageBySlug('test-svc-draft-slug');

        $this->assertNull($page);
    }

    // ─── scope 검증 (AccessDeniedHttpException) ────

    /**
     * getPage()에서 scope=self일 때 타인의 페이지 조회 시 AccessDeniedHttpException을 던지는지 확인
     */
    public function test_get_page_throws_exception_for_scope_denied(): void
    {
        // scope=self 사용자 설정
        $scopeUser = $this->setupScopeUser(ScopeType::Self);
        Auth::setUser($scopeUser);
        $this->actingAs($scopeUser);

        // 다른 사용자가 만든 페이지
        $page = Page::factory()->create([
            'slug' => 'test-svc-scope-show',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->getPage($page->id);
    }

    /**
     * getPage()에서 scope=self일 때 자기 페이지는 정상 조회되는지 확인
     */
    public function test_get_page_allows_own_page_with_scope_self(): void
    {
        $scopeUser = $this->setupScopeUser(ScopeType::Self);
        Auth::setUser($scopeUser);
        $this->actingAs($scopeUser);

        $page = Page::factory()->create([
            'slug' => 'test-svc-scope-show-own',
            'created_by' => $scopeUser->id,
            'updated_by' => $scopeUser->id,
        ]);

        $result = $this->service->getPage($page->id);
        $this->assertEquals($page->id, $result->id);
    }

    /**
     * updatePage()에서 scope=self일 때 타인의 페이지 수정 시 AccessDeniedHttpException을 던지는지 확인
     */
    public function test_update_page_throws_exception_for_scope_denied(): void
    {
        $scopeUser = $this->setupScopeUser(ScopeType::Self);
        Auth::setUser($scopeUser);
        $this->actingAs($scopeUser);

        $page = Page::factory()->create([
            'slug' => 'test-svc-scope-update',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->updatePage($page, [
            'title' => ['ko' => '수정 시도', 'en' => 'Attempt'],
        ]);
    }

    /**
     * deletePage()에서 scope=self일 때 타인의 페이지 삭제 시 AccessDeniedHttpException을 던지는지 확인
     */
    public function test_delete_page_throws_exception_for_scope_denied(): void
    {
        $scopeUser = $this->setupScopeUser(ScopeType::Self);
        Auth::setUser($scopeUser);
        $this->actingAs($scopeUser);

        $page = Page::factory()->create([
            'slug' => 'test-svc-scope-delete',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->deletePage($page);
    }

    /**
     * changePublishStatus()에서 scope=self일 때 타인의 페이지 발행 변경 시 AccessDeniedHttpException을 던지는지 확인
     */
    public function test_change_publish_status_throws_exception_for_scope_denied(): void
    {
        $scopeUser = $this->setupScopeUser(ScopeType::Self);
        Auth::setUser($scopeUser);
        $this->actingAs($scopeUser);

        $page = Page::factory()->create([
            'slug' => 'test-svc-scope-publish',
            'published' => false,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->changePublishStatus($page, true);
    }

    /**
     * restoreVersion()에서 scope=self일 때 타인의 페이지 버전 복원 시 AccessDeniedHttpException을 던지는지 확인
     */
    public function test_restore_version_throws_exception_for_scope_denied(): void
    {
        $scopeUser = $this->setupScopeUser(ScopeType::Self);
        Auth::setUser($scopeUser);
        $this->actingAs($scopeUser);

        $page = Page::factory()->create([
            'slug' => 'test-svc-scope-restore',
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

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->restoreVersion($page, $version->id);
    }

    // ─── 헬퍼 ────────────────────────────────────────

    /**
     * 테스트용 페이지를 생성합니다.
     */
    private function createTestPage(string $slug): Page
    {
        return $this->service->createPage([
            'slug' => $slug,
            'title' => ['ko' => '테스트 페이지', 'en' => 'Test Page'],
            'content' => ['ko' => '테스트 본문', 'en' => 'Test Content'],
            'content_mode' => 'html',
        ]);
    }

    /**
     * scope 권한이 설정된 사용자를 생성합니다.
     *
     * @param  ScopeType  $scopeType  스코프 타입
     * @return User 생성된 사용자
     */
    private function setupScopeUser(ScopeType $scopeType): User
    {
        $user = User::factory()->create();

        $role = Role::firstOrCreate(
            ['identifier' => 'test_page_svc_scope'],
            [
                'name' => ['ko' => '페이지 서비스 스코프', 'en' => 'Page Service Scope'],
                'is_active' => true,
            ]
        );
        $user->roles()->attach($role->id);

        $permissionIds = [
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
            'sirsoft-page.pages.delete',
        ];

        foreach ($permissionIds as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'type' => PermissionType::Admin,
                ]
            );

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
