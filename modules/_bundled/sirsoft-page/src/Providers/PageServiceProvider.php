<?php

namespace Modules\Sirsoft\Page\Providers;

use App\Extension\BaseModuleServiceProvider;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageVersionRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\PageAttachmentRepository;
use Modules\Sirsoft\Page\Repositories\PageRepository;
use Modules\Sirsoft\Page\Repositories\PageVersionRepository;
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
     *
     * @var string
     */
    protected string $moduleIdentifier = 'sirsoft-page';

    /**
     * StorageInterface가 필요한 서비스 목록
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [
        PageAttachmentService::class,
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
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        // Sitemap 기여자 등록
        $this->app->booted(function () {
            if ($this->app->bound(\App\Seo\SitemapGenerator::class)) {
                $this->app->make(\App\Seo\SitemapGenerator::class)->registerContributor(
                    new \Modules\Sirsoft\Page\Seo\PageSitemapContributor()
                );
            }
        });
    }
}
