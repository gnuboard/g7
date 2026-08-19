<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Repositories;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Providers\PageServiceProvider;
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
// audit:allow test-extension-base-class reason: InnoDB FULLTEXT 는 커밋된 행만 색인하므로
// ModuleTestCase 의 DatabaseTransactions 와 상호배타 — RefreshDatabase + 수동 정리로 격리하고
// 모듈 부팅(오토로드/Provider/마이그레이션)은 setUp 이 동일 로직으로 수행한다
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
        $this->app->register(PageServiceProvider::class);

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
        Page::query()->forceDelete();

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

            if (file_exists($file)
                && ! class_exists($class, false) && ! interface_exists($class, false)
                && ! trait_exists($class, false) && ! enum_exists($class, false)) {
                // 활성 디렉토리 사본이 이미 로드된 심볼을 다시 선언하면 fatal 이 된다 —
                // 선언 여부를 자체 확인하고 require_once 로 이중 방어한다
                require_once $file;
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

        $this->assertEquals(1, $result->total());
        $this->assertEquals('test-terms-search', $result->getCollection()->first()->slug);
    }

    /**
     * 제목만으로 매칭되는지 확인 (본문·슬러그에는 키워드가 없다).
     *
     * 회귀 배경 (#492 D-25): 운영 DB 에서 제목 검색이 어떤 키워드로도 0 건이었다
     * (`이용약관` / `약관` / `Terms` / `Service` 전부 0, 같은 행을 `LIKE` 로는 찾음).
     * 기존 제목 테스트는 초록이었는데, 본문에 같은 낱말이 들어 있어 **content 매칭으로**
     * 통과하고 있었기 때문이다. 이 케이스는 본문·슬러그에서 키워드를 완전히 제거해
     * 제목 경로만 남긴다.
     *
     * 주의: 이 테스트는 fresh 테스트 DB 에서는 수정 전에도 통과한다(그 DB 의 FT 인덱스는
     * 정상 동작한다). 운영 DB 의 FT 인덱스 상태 차이를 재현하지는 못하며, 여기서 잠그는 것은
     * **제목 검색이 본문 매칭에 얹혀 통과하지 않는다**는 계약이다.
     */
    public function test_search_by_keyword_matches_title_only(): void
    {
        $this->createPublishedPage('이용약관', 'aaa-bbb-ccc', '본문에는 검색어가 없습니다.');

        $koFull = $this->repository->searchByKeyword('이용약관');
        $koPartial = $this->repository->searchByKeyword('약관');

        $this->assertEquals(1, $koFull->total(), '제목 전체 일치가 검색되지 않았다');
        $this->assertEquals('aaa-bbb-ccc', $koFull->getCollection()->first()->slug);
        $this->assertEquals(1, $koPartial->total(), '제목 부분 일치가 검색되지 않았다');
    }

    /**
     * 다른 로케일(en) 제목으로도 검색되는지 확인.
     */
    public function test_search_by_keyword_matches_other_locale_title(): void
    {
        $page = $this->createPublishedPage('이용약관', 'ddd-eee-fff', '본문에는 검색어가 없습니다.');
        $page->title = ['ko' => '이용약관', 'en' => 'Terms of Service'];
        $page->save();

        $result = $this->repository->searchByKeyword('Terms');

        $this->assertEquals(1, $result->total(), '영문 제목으로 검색되지 않았다');
        $this->assertEquals('ddd-eee-fff', $result->getCollection()->first()->slug);
    }

    /**
     * 본문에 키워드가 포함된 페이지가 검색되는지 확인
     */
    public function test_search_by_keyword_finds_pages_matching_content(): void
    {
        $this->createPublishedPage('서비스 안내', 'test-guide-search', '쿠키 정책에 대한 설명입니다.');
        $this->createPublishedPage('이용약관', 'test-terms-content', '서비스 이용 조건입니다.');

        $result = $this->repository->searchByKeyword('쿠키 정책');

        $this->assertEquals(1, $result->total());
        $this->assertEquals('test-guide-search', $result->getCollection()->first()->slug);
    }

    /**
     * countByKeyword()가 본문 키워드도 카운트하는지 확인
     */
    public function test_count_by_keyword_counts_pages_matching_content(): void
    {
        $this->createPublishedPage('공지사항', 'test-notice-count', '사이트 점검 일정을 안내합니다.');
        $this->createPublishedPage('FAQ', 'test-faq-count', '자주 묻는 질문입니다.');

        $count = $this->repository->countByKeyword('사이트 점검');

        // 건수만 세는 자리도 정확도를 함께 돌려준다 (#519) — 상한에 걸려 잘린 값이
        // 정확한 것처럼 화면에 나가지 않도록.
        $this->assertSame(1, $count->total);
        $this->assertTrue($count->totalRelation()->isExact());
    }

    /**
     * 미발행 페이지는 본문 검색에서 제외되는지 확인
     */
    public function test_search_by_keyword_excludes_unpublished_pages(): void
    {
        $this->createDraftPage('미발행 안내', 'test-draft-unpublished', '독점 서비스 안내입니다.');
        $this->createPublishedPage('발행 안내', 'test-published-unpublished', '공개 안내입니다.');

        $result = $this->repository->searchByKeyword('독점 서비스');

        $this->assertEquals(0, $result->total());
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
