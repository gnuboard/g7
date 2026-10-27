<?php

namespace Tests\Feature\Seo;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Enums\SitemapGenerationMode;
use App\Extension\Storage\CoreStorageDriver;
use App\Seo\AbstractSitemapContributor;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapGenerator;
use App\Seo\SitemapManager;
use App\Seo\SitemapProgress;
use App\Seo\TemplateRouteResolver;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * 꺼진 확장의 사이트맵 기여자 제외 (#822)
 *
 * 모듈·플러그인의 서비스 프로바이더는 비활성이어도 등록되므로 꺼진 쇼핑몰·페이지 모듈의 기여자도
 * 등록돼 있다. 사이트맵에는 켜진 확장의 URL 만 실려야 한다 — 전체 재생성의 저장소 재적재와,
 * 이미 쌓인 저장소 행을 파일로 내보내는 단계 둘 다.
 * 트러블슈팅 `[case:backend-58]` 의 회귀 그물이다.
 *
 * @scenario sitemap-inactive-extension
 */
class SitemapInactiveExtensionContributorTest extends TestCase
{
    use DatabaseTransactions;

    private CoreStorageDriver $storage;

    private SitemapUrlRepositoryInterface $repository;

    /**
     * 테스트 초기화 - 가짜 디스크와 사이트맵 저장소를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);
        Config::set('g7_settings.core.seo.sitemap_enabled', true);

        Storage::fake('local');
        $this->storage = new CoreStorageDriver('local');
        $this->repository = $this->app->make(SitemapUrlRepositoryInterface::class);
    }

    /**
     * 테스트 정리 - Mockery 리소스를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 꺼진 확장 목록을 고정한 생성기를 만듭니다(정적 라우트 수집은 비움).
     *
     * @param  array<int, string>  $inactive  꺼진 확장 식별자 목록
     * @return SitemapGenerator 생성기
     */
    private function generatorWithInactive(array $inactive): SitemapGenerator
    {
        $resolver = Mockery::mock(TemplateRouteResolver::class);
        $service = Mockery::mock(TemplateService::class);

        return new class($resolver, $service, $inactive) extends SitemapGenerator
        {
            /**
             * 꺼진 확장 목록을 받아 둡니다.
             *
             * @param  TemplateRouteResolver  $resolver  라우트 해석기
             * @param  TemplateService  $service  템플릿 서비스
             * @param  array<int, string>  $inactive  꺼진 확장 식별자 목록
             */
            public function __construct(TemplateRouteResolver $resolver, TemplateService $service, private array $inactive)
            {
                parent::__construct($resolver, $service);
            }

            /**
             * 고정한 꺼진 확장 목록을 돌려줍니다.
             *
             * @return array<int, string> 꺼진 확장 식별자 목록
             */
            public function inactiveExtensionIdentifiers(): array
            {
                return $this->inactive;
            }

            /**
             * 정적 라우트는 이 시험의 관심사가 아니므로 비웁니다.
             *
             * @return array<int, array<string, mixed>> 빈 목록
             */
            public function collectStaticRoutesForTest(): array
            {
                return [];
            }
        };
    }

    /**
     * 주어진 식별자로 URL 하나를 내는 기여자를 만듭니다.
     *
     * @param  string  $identifier  기여자 식별자
     * @param  string  $url  상대 경로
     * @return AbstractSitemapContributor 기여자
     */
    private function contributor(string $identifier, string $url): AbstractSitemapContributor
    {
        return new class($identifier, $url) extends AbstractSitemapContributor
        {
            /**
             * 식별자와 경로를 받아 둡니다.
             *
             * @param  string  $identifier  기여자 식별자
             * @param  string  $url  상대 경로
             */
            public function __construct(private string $identifier, private string $url) {}

            /**
             * 기여자 식별자를 돌려줍니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return $this->identifier;
            }

            /**
             * URL 하나를 내보냅니다.
             *
             * @return iterable<array<string, mixed>> URL 항목
             */
            public function getUrlsLazy(): iterable
            {
                yield ['url' => $this->url, 'resource_type' => 'item', 'resource_id' => '1'];
            }
        };
    }

    /**
     * 생성기로 사이트맵 관리자를 만듭니다.
     *
     * @param  SitemapGenerator  $generator  생성기
     * @return SitemapManager 사이트맵 관리자
     */
    private function manager(SitemapGenerator $generator): SitemapManager
    {
        return new SitemapManager(
            $generator,
            Mockery::spy(CacheInterface::class),
            Mockery::spy(ConfigRepositoryInterface::class),
            $this->storage,
            $this->repository,
            $this->app->make(SitemapProgress::class),
        );
    }

    /**
     * 첫 번째 자식 사이트맵 XML 을 읽습니다.
     *
     * @return string 첫 번째 자식 사이트맵 XML 본문
     */
    private function firstChildXml(): string
    {
        return (string) $this->storage->get(SitemapFileStore::CATEGORY, SitemapFileStore::LIVE_DIR.'/'.SitemapFileStore::childFilename(1));
    }

    /**
     * 꺼진 확장의 기여자는 활성 기여자 목록에서 빠지고, 확장이 아닌 기여자는 남는다.
     *
     * @scenario case=active_contributors
     *
     * @effects active_contributors_exclude_inactive_extension
     *
     * @return void 반환값 없음
     */
    public function test_active_contributors_exclude_inactive_extension(): void
    {
        $generator = $this->generatorWithInactive(['sirsoft-ecommerce']);
        $generator->registerContributor($this->contributor('sirsoft-board', '/board/a'));
        $generator->registerContributor($this->contributor('sirsoft-ecommerce', '/shop/products/1'));
        $generator->registerContributor($this->contributor('core-pages', '/about'));

        $this->assertSame(['sirsoft-board', 'core-pages'], array_keys($generator->getActiveContributors()));
        $this->assertCount(3, $generator->getContributors(), '등록 목록 자체는 그대로다');
    }

    /**
     * 전체 재생성은 꺼진 확장의 기여자를 드레인하지 않고, 저장소에 남은 그 기여자의 행도 파일에 싣지 않는다.
     *
     * @scenario case=full_rebuild
     *
     * @effects full_rebuild_skips_inactive_extension, stored_rows_of_inactive_extension_not_written
     *
     * @return void 반환값 없음
     */
    public function test_full_rebuild_excludes_inactive_extension_urls(): void
    {
        // 쇼핑몰이 켜져 있을 때 쌓인 행
        $this->repository->replaceAllForContributor('sirsoft-ecommerce', [
            ['loc' => url('/shop/products/old'), 'resource_type' => 'product', 'resource_id' => '9'],
        ]);

        $generator = $this->generatorWithInactive(['sirsoft-ecommerce']);
        $generator->registerContributor($this->contributor('sirsoft-board', '/board/a'));
        $generator->registerContributor($this->contributor('sirsoft-ecommerce', '/shop/products/1'));

        $this->manager($generator)->regenerate(SitemapGenerationMode::Full);

        $xml = $this->firstChildXml();
        $this->assertStringContainsString(url('/board/a'), $xml);
        $this->assertStringNotContainsString('/shop/products', $xml);
    }

    /**
     * 확장 상태를 모르면(빈 목록) 지금처럼 모든 기여자의 URL 을 싣는다.
     *
     * @scenario case=unknown_status
     *
     * @effects unknown_status_keeps_all_contributors
     *
     * @return void 반환값 없음
     */
    public function test_unknown_status_keeps_all_contributors(): void
    {
        $generator = $this->generatorWithInactive([]);
        $generator->registerContributor($this->contributor('sirsoft-board', '/board/a'));
        $generator->registerContributor($this->contributor('sirsoft-ecommerce', '/shop/products/1'));

        $this->manager($generator)->regenerate(SitemapGenerationMode::Full);

        $xml = $this->firstChildXml();
        $this->assertStringContainsString(url('/board/a'), $xml);
        $this->assertStringContainsString(url('/shop/products/1'), $xml);
    }
}
