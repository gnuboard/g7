<?php

namespace Modules\Sirsoft\Page\Providers;

use App\Extension\BaseModuleServiceProvider;
use App\Seo\SitemapGenerator;
use App\Support\ContentMedia\ContentMediaRegistrar;
use Modules\Sirsoft\Page\Console\Commands\PlaywrightSeedPage;
use Modules\Sirsoft\Page\Console\Commands\PruneTempAttachmentsCommand;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageVersionRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\PageAttachmentRepository;
use Modules\Sirsoft\Page\Repositories\PageRepository;
use Modules\Sirsoft\Page\Repositories\PageVersionRepository;
use Modules\Sirsoft\Page\Seo\PageSitemapContributor;
use Modules\Sirsoft\Page\Services\ContentMedia\PageContentMediaFlow;
use Modules\Sirsoft\Page\Services\ContentMedia\PageContentMediaStore;
use Modules\Sirsoft\Page\Services\PageAttachmentService;

/**
 * Page 모듈 서비스 프로바이더
 *
 * Repository 인터페이스와 구현체 바인딩을 담당합니다.
 */
class PageServiceProvider extends BaseModuleServiceProvider
{
    /**
     * 모듈 식별자
     */
    protected string $moduleIdentifier = 'sirsoft-page';

    /**
     * StorageInterface가 필요한 서비스 목록
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [
        PageAttachmentService::class,
        PageContentMediaFlow::class,
    ];

    /**
     * Repository 인터페이스와 구현체 매핑
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        PageRepositoryInterface::class => PageRepository::class,
        PageVersionRepositoryInterface::class => PageVersionRepository::class,
        PageAttachmentRepositoryInterface::class => PageAttachmentRepository::class,
    ];

    /**
     * 서비스 부트스트랩
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        parent::boot();

        // Artisan 커맨드 등록 (콘솔 환경에서만)
        if ($this->app->runningInConsole()) {
            $this->commands([
                PlaywrightSeedPage::class,
                PruneTempAttachmentsCommand::class,
            ]);
        }

        // 본문 미디어 서빙 라우트를 미디어 출처 허용 패턴의 원천으로 등록한다 — 동영상·오디오의 src 는 등록된 서빙 라우트 주소만
        // 통과한다(#887). 이름만 넘겨 부팅마다 어댑터·리포지토리를 만들지 않는다. 모듈이 꺼져 라우트가 없으면 패턴에서 빠진다.
        ContentMediaRegistrar::register(PageContentMediaStore::SERVE_ROUTE);

        // Sitemap 기여자 등록 — 생성기를 처음 해석할 때(사이트맵 생성·SEO 화면) 붙는다. 이미 해석돼 있으면 바로
        // 붙는다. 부팅마다 생성기·기여자·리포지토리를 만들지 않는다 (#860). 비활성이면 걸지 않는다(#730 게이트).
        if (! method_exists($this, 'isExtensionActive') || $this->isExtensionActive()) {
            $this->callAfterResolving(SitemapGenerator::class, function (SitemapGenerator $generator): void {
                try {
                    // Repository 주입을 위해 컨테이너로 해석합니다.
                    $generator->registerContributor($this->app->make(PageSitemapContributor::class));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }
}
