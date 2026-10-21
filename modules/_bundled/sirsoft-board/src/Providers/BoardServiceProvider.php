<?php

namespace Modules\Sirsoft\Board\Providers;

use App\Extension\BaseModuleServiceProvider;
use App\Helpers\ResponseHelper;
use App\Seo\SitemapGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Board\Console\Commands\AggregateBoardStatsCommand;
use Modules\Sirsoft\Board\Console\Commands\PlaywrightSeedBoardPosts;
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
use Modules\Sirsoft\Board\Services\SecretViewTokenService;

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
        SecretViewTokenService::class,
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
        PlaywrightSeedBoardPosts::class,
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

        $this->registerRateLimiters();

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

    /**
     * 게시판 모듈의 이름 붙은 요청 제한기를 등록합니다.
     *
     * 비밀글 비밀번호 확인(글 비밀번호·스레드 비밀번호)은 그룹 제한(분당 600회)만 걸려 있어
     * 비밀번호 대입이 사실상 막히지 않았다. 두 엔드포인트가 같은 제한기를 써서 IP 당 합산
     * 분당 10회로 묶는다. 429 응답은 로그인 제한과 같은 다국어 문구로 낸다.
     *
     * @return void 반환값 없음
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('sirsoft-board.secret-password-verify', fn (Request $request) => Limit::perMinute(10)
            ->by((string) $request->ip())
            ->response(fn (Request $request, array $headers) => ResponseHelper::error(
                'auth.too_many_attempts',
                429,
                null,
                // `count` 로 「1 second」/「2 seconds」 를 고른다.
                ['seconds' => (int) ($headers['Retry-After'] ?? 60), 'count' => (int) ($headers['Retry-After'] ?? 60)]
            )->withHeaders($headers)));
    }
}
