<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;
use Modules\Sirsoft\Page\Repositories\Contracts\PageVersionRepositoryInterface;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 버전 changes_summary 계산 테스트
 *
 * 페이지 생성/수정/복원 시 changes_summary가 올바르게 계산되는지 검증합니다.
 */
class PageVersionChangesSummaryTest extends ModuleTestCase
{
    private PageService $pageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pageService = $this->app->make(PageService::class);
    }

    /**
     * 테스트용 관리자 사용자를 생성하고 인증합니다.
     *
     * @return \App\Models\User 인증된 사용자
     */
    private function actAsAdmin(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        Auth::login($user);

        return $user;
    }

    #[Test]
    public function 최초_생성시_changes_summary는_null이다(): void
    {
        $this->actAsAdmin();

        $page = $this->pageService->createPage([
            'title' => ['ko' => '테스트 페이지', 'en' => 'Test Page'],
            'slug' => 'test-page-v1',
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'html',
        ]);

        $version = PageVersion::where('page_id', $page->id)
            ->where('version', 1)
            ->first();

        $this->assertNotNull($version);
        $this->assertNull($version->changes_summary);
    }

    #[Test]
    public function title만_수정시_changed_fields에_title만_포함된다(): void
    {
        $this->actAsAdmin();

        $page = $this->pageService->createPage([
            'title' => ['ko' => '원본 제목', 'en' => 'Original Title'],
            'slug' => 'test-page-title',
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'html',
        ]);

        $page = $this->pageService->updatePage($page, [
            'title' => ['ko' => '수정된 제목', 'en' => 'Updated Title'],
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'html',
        ]);

        $version = PageVersion::where('page_id', $page->id)
            ->where('version', 2)
            ->first();

        $this->assertNotNull($version);
        $this->assertNotNull($version->changes_summary);
        $this->assertEquals(['title'], $version->changes_summary['changed_fields']);
        $this->assertNull($version->changes_summary['restored_from']);
    }

    #[Test]
    public function title과_content_수정시_두_필드_모두_포함된다(): void
    {
        $this->actAsAdmin();

        $page = $this->pageService->createPage([
            'title' => ['ko' => '원본', 'en' => 'Original'],
            'slug' => 'test-page-multi',
            'content' => ['ko' => '원본 내용', 'en' => 'Original Content'],
            'content_mode' => 'html',
        ]);

        $page = $this->pageService->updatePage($page, [
            'title' => ['ko' => '수정', 'en' => 'Updated'],
            'content' => ['ko' => '수정 내용', 'en' => 'Updated Content'],
            'content_mode' => 'html',
        ]);

        $version = PageVersion::where('page_id', $page->id)
            ->where('version', 2)
            ->first();

        $this->assertNotNull($version->changes_summary);
        $this->assertContains('title', $version->changes_summary['changed_fields']);
        $this->assertContains('content', $version->changes_summary['changed_fields']);
        $this->assertNotContains('content_mode', $version->changes_summary['changed_fields']);
    }

    #[Test]
    public function seo_meta_수정시_changed_fields에_seo_meta가_포함된다(): void
    {
        $this->actAsAdmin();

        $page = $this->pageService->createPage([
            'title' => ['ko' => '제목', 'en' => 'Title'],
            'slug' => 'test-page-seo',
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'html',
            'seo_meta' => ['title' => 'SEO 원본', 'description' => '설명'],
        ]);

        $page = $this->pageService->updatePage($page, [
            'title' => ['ko' => '제목', 'en' => 'Title'],
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'html',
            'seo_meta' => ['title' => 'SEO 수정', 'description' => '수정 설명'],
        ]);

        $version = PageVersion::where('page_id', $page->id)
            ->where('version', 2)
            ->first();

        $this->assertNotNull($version->changes_summary);
        $this->assertEquals(['seo_meta'], $version->changes_summary['changed_fields']);
    }

    #[Test]
    public function 복원시_restored_from에_원본_버전_번호가_포함된다(): void
    {
        $this->actAsAdmin();

        // v1: 최초 생성
        $page = $this->pageService->createPage([
            'title' => ['ko' => 'v1 제목', 'en' => 'v1 Title'],
            'slug' => 'test-page-restore',
            'content' => ['ko' => 'v1 내용', 'en' => 'v1 Content'],
            'content_mode' => 'html',
        ]);

        // v2: 수정
        $page = $this->pageService->updatePage($page, [
            'title' => ['ko' => 'v2 제목', 'en' => 'v2 Title'],
            'content' => ['ko' => 'v2 내용', 'en' => 'v2 Content'],
            'content_mode' => 'html',
        ]);

        // v1 버전 ID 조회
        $v1 = PageVersion::where('page_id', $page->id)
            ->where('version', 1)
            ->first();

        // v3: v1으로 복원
        $page = $this->pageService->restoreVersion($page, $v1->id);

        $v3 = PageVersion::where('page_id', $page->id)
            ->where('version', 3)
            ->first();

        $this->assertNotNull($v3->changes_summary);
        $this->assertEquals(1, $v3->changes_summary['restored_from']);
        $this->assertIsArray($v3->changes_summary['changed_fields']);
    }

    #[Test]
    public function 버전_목록_API에서_changes_summary가_반환된다(): void
    {
        $user = $this->actAsAdmin();

        $page = $this->pageService->createPage([
            'title' => ['ko' => '제목', 'en' => 'Title'],
            'slug' => 'test-page-api',
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'html',
        ]);

        $page = $this->pageService->updatePage($page, [
            'title' => ['ko' => '수정 제목', 'en' => 'Updated Title'],
            'content' => ['ko' => '내용', 'en' => 'Content'],
            'content_mode' => 'html',
        ]);

        $versions = $this->pageService->getVersions($page);
        $versionRepository = $this->app->make(PageVersionRepositoryInterface::class);

        // v1은 null, v2는 changed_fields 포함
        $v1 = $versions->firstWhere('version', 1);
        $v2 = $versions->firstWhere('version', 2);

        $this->assertNull($v1->changes_summary);
        $this->assertNotNull($v2->changes_summary);
        $this->assertArrayHasKey('changed_fields', $v2->changes_summary);
        $this->assertArrayHasKey('restored_from', $v2->changes_summary);
    }
}
