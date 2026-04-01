<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Repositories;

use App\Models\User;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Repositories\PageRepository;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * PageRepository 키워드 검색 단위 테스트
 *
 * searchByKeyword() 및 countByKeyword()가 제목과 본문 모두 검색하는지 검증합니다.
 */
class PageRepositorySearchTest extends ModuleTestCase
{
    private PageRepository $repository;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(PageRepository::class);
        $this->user = User::factory()->create();
    }

    /**
     * 제목에 키워드가 포함된 페이지가 검색되는지 확인
     */
    public function test_search_by_keyword_finds_pages_matching_title(): void
    {
        $this->createPublishedPage('이용약관', 'test-terms-search', '서비스 이용에 관한 조건입니다.');
        $this->createPublishedPage('개인정보처리방침', 'test-privacy-search', '개인정보를 수집합니다.');

        $result = $this->repository->searchByKeyword('이용약관');

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('test-terms-search', $result['items']->first()->slug);
    }

    /**
     * 본문에 키워드가 포함된 페이지가 검색되는지 확인
     */
    public function test_search_by_keyword_finds_pages_matching_content(): void
    {
        $this->createPublishedPage('서비스 안내', 'test-guide-search', '쿠키 정책에 대한 설명입니다.');
        $this->createPublishedPage('이용약관', 'test-terms-content', '서비스 이용 조건입니다.');

        $result = $this->repository->searchByKeyword('쿠키 정책');

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('test-guide-search', $result['items']->first()->slug);
    }

    /**
     * countByKeyword()가 본문 키워드도 카운트하는지 확인
     */
    public function test_count_by_keyword_counts_pages_matching_content(): void
    {
        $this->createPublishedPage('공지사항', 'test-notice-count', '사이트 점검 일정을 안내합니다.');
        $this->createPublishedPage('FAQ', 'test-faq-count', '자주 묻는 질문입니다.');

        $count = $this->repository->countByKeyword('사이트 점검');

        $this->assertEquals(1, $count);
    }

    /**
     * 미발행 페이지는 본문 검색에서 제외되는지 확인
     */
    public function test_search_by_keyword_excludes_unpublished_pages(): void
    {
        $this->createDraftPage('미발행 안내', 'test-draft-unpublished', '독점 서비스 안내입니다.');
        $this->createPublishedPage('발행 안내', 'test-published-unpublished', '공개 안내입니다.');

        $result = $this->repository->searchByKeyword('독점 서비스');

        $this->assertEquals(0, $result['total']);
    }

    // ─── 헬퍼 ────────────────────────────────────────────

    /**
     * 발행된 테스트 페이지를 생성합니다.
     */
    private function createPublishedPage(string $titleKo, string $slug, string $contentKo): Page
    {
        return Page::create([
            'slug' => $slug,
            'title' => ['ko' => $titleKo, 'en' => ''],
            'content' => ['ko' => $contentKo, 'en' => ''],
            'published' => true,
            'published_at' => now(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /**
     * 미발행(초안) 테스트 페이지를 생성합니다.
     */
    private function createDraftPage(string $titleKo, string $slug, string $contentKo): Page
    {
        return Page::create([
            'slug' => $slug,
            'title' => ['ko' => $titleKo, 'en' => ''],
            'content' => ['ko' => $contentKo, 'en' => ''],
            'published' => false,
            'published_at' => null,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }
}
