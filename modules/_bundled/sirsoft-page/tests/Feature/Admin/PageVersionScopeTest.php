<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Admin;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 버전 엔드포인트의 상위 페이지 스코프 검증 테스트
 *
 * GET  /api/modules/sirsoft-page/admin/pages/{page}/versions/{versionId}
 * POST /api/modules/sirsoft-page/admin/pages/{page}/versions/{versionId}/restore
 *
 * 검증 목적:
 * - 페이지 A 의 경로로 페이지 B 의 버전을 조회·복원할 수 없다 (404)
 * - 자기 페이지의 버전은 기존과 동일하게 조회·복원된다 (200)
 * - 조회와 복원 두 엔드포인트가 같은 위반에 같은 상태코드를 낸다 (계약 일치)
 */
class PageVersionScopeTest extends FeatureTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.update',
        ]);
    }

    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'test-version-scope-%')->forceDelete();

        parent::tearDown();
    }

    /**
     * 다른 페이지의 버전은 조회할 수 없다
     *
     * @scenario resource=page_version, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_read_returns_404
     */
    public function test_cannot_show_version_of_other_page(): void
    {
        $pageA = $this->makePage('test-version-scope-a');
        $pageB = $this->makePage('test-version-scope-b');

        $versionOfB = $this->makeVersion($pageB);

        $this->actingAs($this->adminUser)
            ->getJson($this->url($pageA->id, $versionOfB->id))
            ->assertStatus(404);
    }

    /**
     * 자기 페이지의 버전은 조회된다 (회귀 방지)
     *
     * @scenario resource=page_version, parent_scope=matching, actor=admin
     *
     * @effects matching_scope_still_succeeds
     */
    public function test_can_show_version_of_own_page(): void
    {
        $page = $this->makePage('test-version-scope-own');
        $version = $this->makeVersion($page);

        $this->actingAs($this->adminUser)
            ->getJson($this->url($page->id, $version->id))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $version->id);
    }

    /**
     * 다른 페이지의 버전으로 복원할 수 없다
     *
     * 조회(showVersion)와 동일한 위반이므로 동일한 404 여야 한다. 종전에는
     * Service 의 사후 비교가 `\InvalidArgumentException` 을 던지고 컨트롤러
     * catch 사슬에 그 타입이 없어 500 으로 떨어졌다.
     *
     * @scenario resource=page_version, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_write_rejected
     */
    public function test_cannot_restore_version_of_other_page(): void
    {
        $pageA = $this->makePage('test-version-scope-restore-a');
        $pageB = $this->makePage('test-version-scope-restore-b');

        $versionOfB = $this->makeVersion($pageB, [
            'title' => ['ko' => '페이지 B 의 버전 제목', 'en' => 'Version title of page B'],
        ]);

        $this->actingAs($this->adminUser)
            ->postJson($this->url($pageA->id, $versionOfB->id).'/restore')
            ->assertStatus(404);

        // 페이지 A 가 B 의 내용으로 오염되지 않았다
        $pageA->refresh();
        $this->assertNotSame('페이지 B 의 버전 제목', $pageA->getRawOriginal('title'));
        $this->assertStringNotContainsString('페이지 B 의 버전 제목', (string) $pageA->getRawOriginal('title'));
        $this->assertSame(1, $pageA->current_version);
    }

    /**
     * 조회와 복원 두 엔드포인트가 같은 위반에 같은 상태코드를 낸다
     *
     * @scenario resource=page_version, parent_scope=mismatched, actor=admin
     *
     * @effects sibling_endpoints_share_scope_contract
     */
    public function test_show_and_restore_endpoints_reject_cross_page_alike(): void
    {
        $pageA = $this->makePage('test-version-scope-parity-a');
        $pageB = $this->makePage('test-version-scope-parity-b');

        $versionOfB = $this->makeVersion($pageB);

        $showStatus = $this->actingAs($this->adminUser)
            ->getJson($this->url($pageA->id, $versionOfB->id))
            ->getStatusCode();

        $restoreStatus = $this->actingAs($this->adminUser)
            ->postJson($this->url($pageA->id, $versionOfB->id).'/restore')
            ->getStatusCode();

        $this->assertSame($showStatus, $restoreStatus);
    }

    /**
     * 자기 페이지의 버전으로는 복원된다 (과차단 회귀 방지)
     *
     * @scenario resource=page_version, parent_scope=matching, actor=admin
     *
     * @effects matching_scope_still_succeeds
     */
    public function test_can_restore_version_of_own_page(): void
    {
        $page = $this->makePage('test-version-scope-restore-own');
        $version = $this->makeVersion($page, [
            'title' => ['ko' => '복원 대상 제목', 'en' => 'Restore target title'],
        ]);

        $this->actingAs($this->adminUser)
            ->postJson($this->url($page->id, $version->id).'/restore')
            ->assertStatus(200);

        $page->refresh();
        $this->assertStringContainsString('복원 대상 제목', (string) $page->getRawOriginal('title'));
        $this->assertSame(2, $page->current_version);
    }

    /**
     * 테스트용 페이지를 생성합니다.
     *
     * @param  string  $slug  페이지 슬러그
     * @return Page 생성된 페이지
     */
    private function makePage(string $slug): Page
    {
        return Page::factory()->create([
            'slug' => $slug,
            'current_version' => 1,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);
    }

    /**
     * 테스트용 페이지 버전을 생성합니다.
     *
     * @param  Page  $page  소속 페이지
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     * @return PageVersion 생성된 버전
     */
    private function makeVersion(Page $page, array $overrides = []): PageVersion
    {
        return PageVersion::create(array_merge([
            'page_id' => $page->id,
            'version' => 1,
            'title' => $page->title,
            'content' => $page->content,
            'content_mode' => $page->content_mode,
            'created_by' => $this->adminUser->id,
        ], $overrides));
    }

    /**
     * 버전 상세 조회 엔드포인트 URL 을 생성합니다.
     *
     * @param  int  $pageId  페이지 ID
     * @param  int  $versionId  버전 ID
     * @return string 엔드포인트 URL
     */
    private function url(int $pageId, int $versionId): string
    {
        return "/api/modules/sirsoft-page/admin/pages/{$pageId}/versions/{$versionId}";
    }
}
