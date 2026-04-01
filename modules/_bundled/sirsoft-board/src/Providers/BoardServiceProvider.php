<?php

namespace Modules\Sirsoft\Board\Providers;

use App\Extension\BaseModuleServiceProvider;
use Modules\Sirsoft\Board\Repositories\AttachmentRepository;
use Modules\Sirsoft\Board\Repositories\BoardRepository;
use Modules\Sirsoft\Board\Repositories\CommentRepository;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardMailTemplateRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardTypeRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\UserNotificationSettingRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\BoardMailTemplateRepository;
use Modules\Sirsoft\Board\Repositories\BoardTypeRepository;
use Modules\Sirsoft\Board\Repositories\PostRepository;
use Modules\Sirsoft\Board\Repositories\ReportRepository;
use Modules\Sirsoft\Board\Repositories\UserNotificationSettingRepository;
use Modules\Sirsoft\Board\Services\AttachmentService;

/**
 * Board 모듈 서비스 프로바이더
 *
 * Repository 인터페이스와 구현체 바인딩을 담당합니다.
 */
class BoardServiceProvider extends BaseModuleServiceProvider
{
    /**
     * 모듈 식별자
     *
     * @var string
     */
    protected string $moduleIdentifier = 'sirsoft-board';

    /**
     * StorageInterface가 필요한 서비스 목록
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [
        AttachmentService::class,
    ];

    /**
     * Repository 인터페이스와 구현체 매핑
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        AttachmentRepositoryInterface::class => AttachmentRepository::class,
        BoardRepositoryInterface::class => BoardRepository::class,
        BoardTypeRepositoryInterface::class => BoardTypeRepository::class,
        CommentRepositoryInterface::class => CommentRepository::class,
        PostRepositoryInterface::class => PostRepository::class,
        ReportRepositoryInterface::class => ReportRepository::class,
        UserNotificationSettingRepositoryInterface::class => UserNotificationSettingRepository::class,
        BoardMailTemplateRepositoryInterface::class => BoardMailTemplateRepository::class,
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
                    new \Modules\Sirsoft\Board\Seo\BoardSitemapContributor()
                );
            }
        });
    }
}
