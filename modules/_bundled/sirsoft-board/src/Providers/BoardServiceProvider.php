<?php

namespace Modules\Sirsoft\Board\Providers;

use App\Extension\AbstractModule;
use App\Extension\BaseModuleServiceProvider;
use App\Helpers\ResponseHelper;
use App\Seo\SitemapGenerator;
use App\Support\ContentMedia\ContentMediaRegistrar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Board\Console\Commands\AggregateBoardStatsCommand;
use Modules\Sirsoft\Board\Console\Commands\PlaywrightSeedBoardPosts;
use Modules\Sirsoft\Board\Console\Commands\PlaywrightSeedBoardReaction;
use Modules\Sirsoft\Board\Console\Commands\PlaywrightSeedBoardRetention;
use Modules\Sirsoft\Board\Console\Commands\PlaywrightSeedBoardScrap;
use Modules\Sirsoft\Board\Console\Commands\PruneAttachmentsCommand;
use Modules\Sirsoft\Board\Contracts\BoardDirectory;
use Modules\Sirsoft\Board\DisplayName\BoardDisplayNames;
use Modules\Sirsoft\Board\Fields\GuestAuthorFields;
use Modules\Sirsoft\Board\Repositories\AttachmentRepository;
use Modules\Sirsoft\Board\Repositories\BoardPermissionRepository;
use Modules\Sirsoft\Board\Repositories\BoardRepository;
use Modules\Sirsoft\Board\Repositories\BoardStatRepository;
use Modules\Sirsoft\Board\Repositories\BoardTypeRepository;
use Modules\Sirsoft\Board\Repositories\CommentRepository;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardPermissionRepositoryInterface;
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
use Modules\Sirsoft\Board\Services\Bridge\BoardDirectoryService;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\ContentMedia\BoardContentMediaService;
use Modules\Sirsoft\Board\Services\ContentMedia\BoardContentMediaStore;
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
    protected array $storageServices = [];

    /**
     * 비공개 첨부 저장소를 따르는 서비스 
     *
     * 첨부·본문 미디어는 권한 확인 뒤 서버가 내려주는 비공개 원본이라, 관리자가 환경설정 > 드라이버 「비공개 첨부 저장소」에서
     * 고른 디스크(「기본」이면 `modules`, `.env` `SIRSOFT_BOARD_ATTACHMENT_DISK` 고정이면 그 디스크)에 저장한다.
     * 기존 행은 행 disk 로 서빙·삭제하므로 디스크를 바꿔도 옛 파일에 닿는다. 저장 경로 카테고리(`attachments`)는 그대로다.
     *
     * @var array<class-string, string>
     */
    protected array $storageCategoryServices = [
        AttachmentService::class => AbstractModule::PRIVATE_ATTACHMENT_BINDING,
        BoardService::class => AbstractModule::PRIVATE_ATTACHMENT_BINDING,
        BoardContentMediaService::class => AbstractModule::PRIVATE_ATTACHMENT_BINDING,
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
        BoardPermissionRepositoryInterface::class => BoardPermissionRepository::class,
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
     * 서비스 등록
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        parent::register();

        // 게시판별 표시 이름 설정과 비회원 작성자 항목 설정을 요청 안에서 한 번만 읽는다(요청 단위).
        $this->app->scoped(BoardDisplayNames::class);
        $this->app->scoped(GuestAuthorFields::class);

        // 다른 확장에 공개하는 조회 계약(module.json public_api) — 구현은 내부 저장소에 위임한다 
        $this->app->bind(BoardDirectory::class, BoardDirectoryService::class);
    }

    /**
     * 서비스 부트스트랩
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        parent::boot();

        // 요청 처리에 끼어드는 부수효과는 활성일 때만 건다
        if (! method_exists($this, 'isExtensionActive') || $this->isExtensionActive()) {
            $this->registerRateLimiters();
        }

        // 본문 미디어 서빙 라우트를 미디어 출처 허용 패턴의 원천으로 등록한다 — 코어는 기본 출처 패턴을 두지 않으므로,
        // 이것이 없으면 게시판 본문의 동영상·오디오가 화면·봇 정화·저장에서 지워진다. 모듈이 꺼져 라우트가 없으면
        // 패턴 생성 쪽이 이 이름을 건너뛴다
        ContentMediaRegistrar::register(BoardContentMediaStore::SERVE_ROUTE);

        // Artisan 커맨드 등록
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }

        // Sitemap 기여자 등록 — 생성기를 처음 해석할 때(사이트맵 생성·SEO 화면) 붙는다. 이미 해석돼 있으면 바로
        // 붙는다. 부팅마다 생성기·기여자·리포지토리를 만들지 않는다. 비활성이면 걸지 않는다.
        if (! method_exists($this, 'isExtensionActive') || $this->isExtensionActive()) {
            $this->callAfterResolving(SitemapGenerator::class, function (SitemapGenerator $generator): void {
                try {
                    // Repository 주입을 위해 컨테이너로 해석합니다.
                    $generator->registerContributor($this->app->make(BoardSitemapContributor::class));
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }

    /**
     * 게시판 모듈의 이름 붙은 요청 제한기를 등록합니다.
     *
     * 비밀번호 확인 엔드포인트(비밀글 글 비밀번호·스레드 비밀번호, 비회원 글 수정·삭제용 확인, 비회원 댓글
     * 비밀번호 확인)는 그룹 제한(분당 600회)뿐이거나 제한이 없어 비밀번호 대입이 사실상 막히지 않았다.
     * 네 엔드포인트가 같은 제한기를 써서 IP 당 합산 분당 10회로 묶는다 — 문을 바꿔 가며 대입해도 합쳐서 센다.
     * 429 응답은 로그인 제한과 같은 다국어 문구로 낸다.
     *
     * 글·댓글의 수정·삭제 요청도 비밀번호를 본문으로 직접 받아 비교하므로 같은 제한기를 건다. 다만 비밀번호가
     * 실리지 않은 요청(회원 본인 수정·삭제, 확인 토큰으로 오는 요청)은 비교할 것이 없으므로 세지 않는다.
     * 글 번호와 비밀번호를 함께 받는 글쓰기 폼 데이터 조회·첨부 업로드·본문 미디어 업로드도 같은 방식으로 건다.
     *
     * @return void 반환값 없음
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('sirsoft-board.secret-password-verify', function (Request $request) {
            $password = $request->input('password');

            if (! is_string($password) || $password === '') {
                return Limit::none();
            }

            return Limit::perMinute(10)
                ->by((string) $request->ip())
                ->response(fn (Request $request, array $headers) => ResponseHelper::error(
                    'auth.too_many_attempts',
                    429,
                    null,
                    // `count` 로 「1 second」/「2 seconds」 를 고른다.
                    ['seconds' => (int) ($headers['Retry-After'] ?? 60), 'count' => (int) ($headers['Retry-After'] ?? 60)]
                )->withHeaders($headers));
        });
    }
}
