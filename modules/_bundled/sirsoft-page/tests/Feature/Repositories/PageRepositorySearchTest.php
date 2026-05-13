<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Repositories;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Repositories\PageRepository;
use Tests\TestCase;

/**
 * PageRepository 키워드 검색 통합 테스트
 *
 * searchByKeyword() 및 countByKeyword()가 제목/본문/슬러그를 검색하는지 검증합니다.
 *
 * 주의: DatabaseTransactions 대신 RefreshDatabase 사용 — InnoDB FULLTEXT 인덱스는
 * 커밋된 데이터만 인덱싱하므로, 트랜잭션 내부에서 MATCH 조회는 0 을 반환한다.
 * 따라서 이 스위트만 매 테스트 fresh DB + commit 되는 INSERT 로 FULLTEXT 경로를
 * 실제 검증한다. (ModuleTestCase 의 DatabaseTransactions 와 상호배타.)
 */
class PageRepositorySearchTest extends TestCase
{
    use RefreshDatabase;

    private PageRepository $repository;

    private User $user;

    /**
     * 트랜잭션 wrapping 비활성화.
     *
     * 기본 RefreshDatabase 는 테스트마다 beginTransaction/rollBack 으로 격리하지만,
     * MySQL InnoDB FULLTEXT 는 커밋된 데이터만 인덱싱하므로 트랜잭션 내부의 INSERT 는
     * MATCH 조회에서 보이지 않는다. 빈 배열을 반환해 transaction wrapping 을 끄고,
     * tearDown 에서 수동으로 insert 된 레코드를 정리한다.
     *
     * @return array<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 오토로드 등록 (ModuleTestCase 와 동일 로직 — 상속 대신 복제)
        $this->registerModuleAutoload();

        // 모듈 ServiceProvider 등록 (Repository 바인딩)
        $this->app->register(\Modules\Sirsoft\Page\Providers\PageServiceProvider::class);

        // 모듈 마이그레이션 실행 (pages 테이블)
        $this->artisan('migrate', [
            '--path' => dirname(__DIR__, 3).'/database/migrations',
            '--realpath' => true,
        ]);

        $this->repository = app(PageRepository::class);
        $this->user = User::factory()->create();
    }

    /**
     * 수동 정리: connectionsToTransact=[] 로 인해 transaction rollback 이 없으므로
     * 테스트에서 insert 한 Page + User 를 직접 제거해 격리를 유지한다.
     */
    protected function tearDown(): void
    {
        Page::query()->withTrashed()->forceDelete();

        if (isset($this->user)) {
            User::where('id', $this->user->id)->delete();
        }

        parent::tearDown();
    }

    /**
     * 모듈 오토로드를 등록합니다 (ModuleTestCase 와 동일 로직 사본).
     */
    private function registerModuleAutoload(): void
    {
        $moduleBasePath = dirname(__DIR__, 3);

        spl_autoload_register(function ($class) use ($moduleBasePath) {
            $prefix = 'Modules\\Sirsoft\\Page\\';
            $len = strlen($prefix);

            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);

            if (str_starts_with($relativeClass, 'Database\\Factories\\')) {
                $factoryClass = substr($relativeClass, strlen('Database\\Factories\\'));
                $file = $moduleBasePath.'/database/factories/'.str_replace('\\', '/', $factoryClass).'.php';
            } elseif (str_starts_with($relativeClass, 'Database\\Seeders\\')) {
                $seederClass = substr($relativeClass, strlen('Database\\Seeders\\'));
                $file = $moduleBasePath.'/database/seeders/'.str_replace('\\', '/', $seederClass).'.php';
            } else {
                $file = $moduleBasePath.'/src/'.str_replace('\\', '/', $relativeClass).'.php';
            }

            if (file_exists($file)) {
                require $file;
            }
        });
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
