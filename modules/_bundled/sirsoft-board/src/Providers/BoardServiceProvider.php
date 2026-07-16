<?php

namespace Modules\Sirsoft\Board\Providers;

use App\Extension\BaseModuleServiceProvider;
use App\Seo\SitemapGenerator;
use Modules\Sirsoft\Board\Console\Commands\AggregateBoardStatsCommand;
use Modules\Sirsoft\Board\Repositories\AttachmentRepository;
use Modules\Sirsoft\Board\Repositories\BoardRepository;
use Modules\Sirsoft\Board\Repositories\BoardStatRepository;
use Modules\Sirsoft\Board\Repositories\BoardTypeRepository;
use Modules\Sirsoft\Board\Repositories\CommentRepository;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardStatRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardTypeRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\UserNotificationSettingRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\PostRepository;
use Modules\Sirsoft\Board\Repositories\ReportRepository;
use Modules\Sirsoft\Board\Repositories\UserNotificationSettingRepository;
use Modules\Sirsoft\Board\Seo\BoardSitemapContributor;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Services\ReportService;

/**
 * Board 모듈 서비스 프로바이더
 *
 * Repository 인터페이스와 구현체 바인딩을 담당합니다.
 */
class BoardServiceProvider extends BaseModuleServiceProvider
{
    /**
     * 모듈 식별자
     */
    protected string $moduleIdentifier = 'sirsoft-board';

    /**
     * StorageInterface가 필요한 서비스 목록
     *
     * @var array<int, class-string>
     */
    protected array $storageServices = [
        AttachmentService::class,
        BoardService::class,
    ];

    /**
     * CacheInterface가 필요한 서비스 클래스 목록
     *
     * 이 배열에 정의된 서비스들은 모듈별 ModuleCacheDriver 가
     * `CacheInterface` 로 자동 주입됩니다 (접두사: `g7:module.sirsoft-board:`).
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [
        BoardService::class,
        CommentService::class,
        PostService::class,
        ReportService::class,
    ];

    /**
     * Repository 인터페이스와 구현체 매핑
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        AttachmentRepositoryInterface::class => AttachmentRepository::class,
        BoardRepositoryInterface::class => BoardRepository::class,
        BoardStatRepositoryInterface::class => BoardStatRepository::class,
        BoardTypeRepositoryInterface::class => BoardTypeRepository::class,
        CommentRepositoryInterface::class => CommentRepository::class,
        PostRepositoryInterface::class => PostRepository::class,
        ReportRepositoryInterface::class => ReportRepository::class,
        UserNotificationSettingRepositoryInterface::class => UserNotificationSettingRepository::class,
    ];

    /**
     * 등록할 Artisan 커맨드 목록
     *
     * @var array<int, class-string>
     */
    protected array $commands = [
        AggregateBoardStatsCommand::class,
    ];

    /**
     * 서비스 부트스트랩
     */
    public function boot(): void
    {
        parent::boot();

        // Artisan 커맨드 등록
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }

        // Sitemap 기여자 등록
        $this->app->booted(function () {
            if ($this->app->bound(SitemapGenerator::class)) {
                $this->app->make(SitemapGenerator::class)->registerContributor(
                    new BoardSitemapContributor
                );
            }
        });
    }
}
