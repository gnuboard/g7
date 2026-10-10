<?php

namespace Modules\Sirsoft\Board\Providers;

use App\Extension\BaseModuleServiceProvider;
use App\Seo\SitemapGenerator;
use Modules\Sirsoft\Board\Console\Commands\AggregateBoardStatsCommand;
use Modules\Sirsoft\Board\Console\Commands\PlaywrightSeedBoardReaction;
use Modules\Sirsoft\Board\Console\Commands\PlaywrightSeedBoardRetention;
use Modules\Sirsoft\Board\Console\Commands\PlaywrightSeedBoardScrap;
use Modules\Sirsoft\Board\Console\Commands\PruneAttachmentsCommand;
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
use Modules\Sirsoft\Board\Repositories\Contracts\PostScrapRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\UserNotificationSettingRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\PostRepository;
use Modules\Sirsoft\Board\Repositories\PostScrapRepository;
use Modules\Sirsoft\Board\Repositories\ReactionRepository;
use Modules\Sirsoft\Board\Repositories\ReactionTypeRepository;
use Modules\Sirsoft\Board\Repositories\ReportRepository;
use Modules\Sirsoft\Board\Repositories\UserNotificationSettingRepository;
use Modules\Sirsoft\Board\Seo\BoardSitemapContributor;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Services\ReactionService;
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
        ReactionService::class,
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
        PostScrapRepositoryInterface::class => PostScrapRepository::class,
        ReactionRepositoryInterface::class => ReactionRepository::class,
        ReactionTypeRepositoryInterface::class => ReactionTypeRepository::class,
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
        // E2E·실측 시드 — 3중 가드(CLI · G7_PLAYWRIGHT_BYPASS · APP_DEBUG)가 커맨드 안에 있다.
        PlaywrightSeedBoardReaction::class,
        PlaywrightSeedBoardRetention::class,
        PlaywrightSeedBoardScrap::class,
        PruneAttachmentsCommand::class,
    ];

    /**
     * 서비스 부트스트랩
     *
     * @return void
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
                // Repository 주입을 위해 컨테이너로 해석합니다.
                $this->app->make(SitemapGenerator::class)->registerContributor(
                    $this->app->make(BoardSitemapContributor::class)
                );
            }
        });
    }
}
