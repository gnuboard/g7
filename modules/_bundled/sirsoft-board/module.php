<?php

namespace Modules\Sirsoft\Board;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractModule;
use App\Extension\Helpers\ExtensionLangTexts;
use App\Seo\Concerns\LocalizesSeoValues;
use App\Services\AttachmentService;
use App\Support\AssetUrl;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Database\Seeders\BoardTypeSeeder;
use Modules\Sirsoft\Board\DisplayName\BoardDisplayNames;
use Modules\Sirsoft\Board\Fields\GuestAuthorFields;
use Modules\Sirsoft\Board\Fields\UserNotificationFieldHandler;
use Modules\Sirsoft\Board\Http\Middleware\EnsureBoardExists;
use Modules\Sirsoft\Board\Listeners\ActivityLogDescriptionResolver;
use Modules\Sirsoft\Board\Listeners\BoardActivityLogListener;
use Modules\Sirsoft\Board\Listeners\BoardCommentsCountSyncListener;
use Modules\Sirsoft\Board\Listeners\BoardNotificationChannelListener;
use Modules\Sirsoft\Board\Listeners\BoardNotificationDataListener;
use Modules\Sirsoft\Board\Listeners\BoardPostsCountSyncListener;
use Modules\Sirsoft\Board\Listeners\BoardScopeLedgerListener;
use Modules\Sirsoft\Board\Listeners\Ckeditor5ReferenceSourcesListener;
use Modules\Sirsoft\Board\Listeners\CommentReplySyncListener;
use Modules\Sirsoft\Board\Listeners\EcommerceInquiryHookListener;
use Modules\Sirsoft\Board\Listeners\ExemptRequiredFieldsRoutesListener;
use Modules\Sirsoft\Board\Listeners\GuestAuthorFieldDefinitionListener;
use Modules\Sirsoft\Board\Listeners\PostAttachmentCountSyncListener;
use Modules\Sirsoft\Board\Listeners\PostCountSyncListener;
use Modules\Sirsoft\Board\Listeners\PostReplySyncListener;
use Modules\Sirsoft\Board\Listeners\PrivacyRetentionHandlerListener;
use Modules\Sirsoft\Board\Listeners\ProvideNecessaryStorageKeysListener;
use Modules\Sirsoft\Board\Listeners\SearchPostsListener;
use Modules\Sirsoft\Board\Listeners\SeoBoardCacheListener;
use Modules\Sirsoft\Board\Listeners\SeoBoardRegenerateListener;
use Modules\Sirsoft\Board\Listeners\SeoBoardSettingsCacheListener;
use Modules\Sirsoft\Board\Listeners\ThumbnailServingListener;
use Modules\Sirsoft\Board\Listeners\UserDeleteCleanupListener;
use Modules\Sirsoft\Board\Listeners\UserNotificationSettingsListener;
use Modules\Sirsoft\Board\Listeners\UserReactionInfoListener;
use Modules\Sirsoft\Board\Listeners\UserScrapInfoListener;
use Modules\Sirsoft\Board\Models\Board;

class Module extends AbstractModule
{
    use LocalizesSeoValues;

    /**
     * 모듈 제거 시 동적으로 생성된 게시판별 역할을 정리합니다.
     *
     * 게시판 생성 시 동적으로 생성된 sirsoft-board.{slug}.manager/step 역할은
     * getRoles()에 포함되지 않으므로, ModuleManager::removeModuleRoles()에서 처리되지 않습니다.
     * 따라서 uninstall()에서 직접 정리합니다.
     *
     * @return bool 제거 성공 여부
     */
    public function uninstall(): bool
    {
        // 게시판별 동적 역할 정리 (sirsoft-board.*.manager, sirsoft-board.*.step)
        // 대상 역할을 먼저 모두 읽은 뒤 지운다 — OFFSET 기반 청크 순회 중에 순회 대상을 지우면
        // 다음 페이지의 OFFSET 이 줄어든 결과 집합을 지나쳐 첫 청크(1000건)를 넘는 역할이 남는다.
        // 역할 수는 게시판 수의 두 배 수준이라 한 번에 읽어도 된다.
        $roleRepository = app(RoleRepositoryInterface::class);
        foreach ($roleRepository->getByExtension(ExtensionOwnerType::Module, 'sirsoft-board') as $role) {
            // 권한·메뉴·회원 연결과 메뉴 권한을 먼저 떼고 지운다 — DB 외래키 연쇄에 맡기지 않는다(#888)
            $roleRepository->deleteWithRelations($role);
        }

        // 반응 유형 아이콘 첨부 정리 — source_identifier 가 붙은 첨부는 코어 고아 정리
        // 대상이 아니므로(정리 대상은 source_identifier 가 NULL 인 행뿐) 여기서 지우지
        // 않으면 모듈을 지운 뒤에도 파일과 attachments 행이 남는다.
        app(AttachmentService::class)->deleteBySourceIdentifier('sirsoft-board');

        return parent::uninstall();
    }

    /**
     * 모듈 스케줄 목록 반환
     *
     * 설치·갱신 때 코어가 이 선언을 스케줄 표에 동기화하고, 운영자는 관리자 > 스케줄 관리(또는 게시판 설정의
     * 「스케줄」 탭)에서 주기·활성을 바꾼다. 시각은 사이트 시간대 기준이다.
     *
     * @return array<int, array<string, mixed>> 스케줄 선언 목록 (형식: AbstractModule::getSchedules() 참고)
     */
    public function getSchedules(): array
    {
        return [
            [
                'name' => 'aggregate-stats',
                'type' => 'artisan',
                'label' => ['ko' => '게시물 현황 집계', 'en' => 'Aggregate board stats'],
                'description' => ['ko' => '대시보드 게시물 현황을 집계합니다.', 'en' => 'Aggregates post statistics for the dashboard.'],
                'command' => 'sirsoft-board:aggregate-stats',
                'frequency' => 'hourly',
                'expression' => '0 * * * *',
            ],
            [
                'name' => 'prune-attachments',
                'type' => 'artisan',
                'label' => ['ko' => '게시판 첨부 정리', 'en' => 'Prune board attachments'],
                // 임시 첨부 정리 파트는 상시 동작해야 하므로 스케줄 자체는 조건 없이 돈다.
                // 사용자 파일을 실제로 파기하는 영구 정리 파트만 커맨드 내부에서 게이트한다
                // (attachment_settings.purge_enabled, false 폴백).
                'description' => ['ko' => '방치된 임시 첨부를 지우고, 보존 기간이 지난 삭제 첨부를 영구 정리합니다(영구 정리는 설정에서 켠 경우만).', 'en' => 'Deletes abandoned temporary attachments and permanently purges deleted attachments past retention (purge only when enabled in settings).'],
                'command' => 'sirsoft-board:prune-attachments --scheduled',
                'frequency' => 'daily',
                'expression' => '0 0 * * *',
            ],
        ];
    }

    /**
     * 이 모듈이 등록할 HTTP 미들웨어 선언을 반환합니다(코어 self-gate 가 요청 시점에 targets 매칭으로 실행).
     *
     * - EnsureBoardExists: 게시판 하위 라우트(`{slug}`)에서 없는 게시판을 권한 판정보다 먼저 404 로 끝낸다.
     *   게시판별 동적 권한(`sirsoft-board.{slug}.*`)은 게시판이 없으면 권한 행도 없어 401·403 이 나가 「권한이
     *   없습니다」로 보였다. 대상은 보호형 규율대로 서브트리 glob 이다 — 글·댓글·첨부(사용자)와 관리자 글·댓글·첨부.
     *   api 그룹 after_core 라 라우트의 `admin`·`permission` 보다 먼저 돈다 — 관리자 API 도 없는 게시판이면
     *   관리자 판정 전에 404 다(게시판 slug 는 공개 주소라 존재 노출 문제가 없다, 2026-10-18 결정). 로그인 판정
     *   (`auth:sanctum`)은 라라벨 미들웨어 우선순위로 그룹 미들웨어보다 앞으로 옮겨져 비회원은 401 이 먼저다.
     *   `{slug}` 가 없는 같은 서브트리 라우트(`boards.posts.recent`)는 미들웨어가 그대로 통과시킨다.
     *   `boards.show`·`admin.boards.show-by-slug` 는 컨트롤러가 이미 404 를 돌려줘 대상에서 뺀다.
     *   정합성은 `MissingBoardNotFoundTest` 가 라우트 테이블 전수(게시판별 권한을 보는 라우트)로 강제한다.
     *
     * @return array<int, array{class: class-string, groups: array<int, string>, timing?: string, targets: array<int, string>}> 미들웨어 선언
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => EnsureBoardExists::class,
                'groups' => ['api'],
                'timing' => 'after_core',
                'targets' => [
                    'api.modules.sirsoft-board.boards.posts.*',
                    'api.modules.sirsoft-board.boards.comments.*',
                    'api.modules.sirsoft-board.boards.attachments.*',
                    'api.modules.sirsoft-board.boards.attachment.*',
                    // 본문 미디어 업로드·서빙 (#883) — 서빙(show)은 컨트롤러도 없는 게시판을 404 로 돌려 결과가 같다
                    'api.modules.sirsoft-board.boards.content-media.*',
                    'api.modules.sirsoft-board.admin.board.*',
                ],
            ],
        ];
    }

    /**
     * 모듈 권한 목록 반환 (계층형 구조, 다국어 지원)
     *
     * 구조: 모듈(1레벨) → 카테고리(2레벨) → 개별 권한(3레벨)
     * identifier는 자동 생성됨: {module}.{category}.{action}
     * 게시판별 권한(sirsoft-board.{slug}.*)은 게시판 생성 시 BoardPermissionService에서 동적으로 생성됨
     *
     * 모듈 레벨 권한만 정의:
     * - sirsoft-board.boards.* (게시판 관리)
     * - sirsoft-board.reports.* (신고 관리)
     * - sirsoft-board.scraps.* (전 회원 스크랩 조회·삭제)
     *
     * @return array<string, mixed> 모듈 → 카테고리 → 개별 권한 계층 정의
     */
    public function getPermissions(): array
    {
        return [
            'name' => [
                'ko' => '게시판',
                'en' => 'Board',
            ],
            'description' => [
                'ko' => '게시판 모듈 권한',
                'en' => 'Board module permissions',
            ],
            'categories' => [
                // 게시판 관리 권한 (type: admin)
                [
                    'identifier' => 'boards',
                    'resource_route_key' => 'board',
                    'owner_key' => 'created_by',
                    'name' => [
                        'ko' => '게시판 관리',
                        'en' => 'Board Management',
                    ],
                    'description' => [
                        'ko' => '게시판 관리 권한',
                        'en' => 'Board management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '게시판 조회',
                                'en' => 'View Boards',
                            ],
                            'description' => [
                                'ko' => '게시판 목록 및 상세 조회',
                                'en' => 'View board list and details',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '게시판 생성',
                                'en' => 'Create Board',
                            ],
                            'description' => [
                                'ko' => '새 게시판 생성',
                                'en' => 'Create new board',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '게시판 수정',
                                'en' => 'Update Board',
                            ],
                            'description' => [
                                'ko' => '게시판 설정 수정',
                                'en' => 'Update board settings',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '게시판 삭제',
                                'en' => 'Delete Board',
                            ],
                            'description' => [
                                'ko' => '게시판 삭제',
                                'en' => 'Delete board',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                // 환경설정 권한 (type: admin)
                [
                    'identifier' => 'settings',
                    'name' => [
                        'ko' => '환경설정',
                        'en' => 'Settings',
                    ],
                    'description' => [
                        'ko' => '게시판 환경설정 권한',
                        'en' => 'Board settings permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '환경설정 조회',
                                'en' => 'View Settings',
                            ],
                            'description' => [
                                'ko' => '게시판 환경설정 조회',
                                'en' => 'View board settings',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '환경설정 수정',
                                'en' => 'Update Settings',
                            ],
                            'description' => [
                                'ko' => '게시판 환경설정 수정',
                                'en' => 'Update board settings',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                // 본인인증 정책 관리 권한 (type: admin) — 환경설정 「본인인증 정책」 탭의 조회·추가·수정·삭제 축
                [
                    'identifier' => 'identity.policies',
                    'name' => [
                        'ko' => '게시판 본인인증 정책',
                        'en' => 'Board Identity Policies',
                    ],
                    'description' => [
                        'ko' => '게시판 컨텍스트의 본인인증 정책 관리 권한',
                        'en' => 'Manage identity verification policies in board context',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '본인인증 정책 조회',
                                'en' => 'View Identity Policies',
                            ],
                            'description' => [
                                'ko' => '게시판 본인인증 정책 조회',
                                'en' => 'View board identity policies',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '본인인증 정책 수정',
                                'en' => 'Update Identity Policies',
                            ],
                            'description' => [
                                'ko' => '게시판 본인인증 정책 수정/추가/삭제',
                                'en' => 'Update, add, delete board identity policies',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                // 대시보드 조회 권한 (type: admin)
                [
                    'identifier' => 'dashboard',
                    'name' => [
                        'ko' => '게시판 대시보드',
                        'en' => 'Board Dashboard',
                    ],
                    'description' => [
                        'ko' => '게시판 대시보드 조회 권한',
                        'en' => 'Board dashboard view permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => [
                                'ko' => '대시보드 조회',
                                'en' => 'View Dashboard',
                            ],
                            'description' => [
                                'ko' => '게시판 대시보드(현황/추세/최신글/미처리 신고) 조회',
                                'en' => 'View board dashboard (overview, trend, recent posts, pending reports)',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                    ],
                ],
                // 신고 관리 권한 (type: admin)
                // 범위(resource_route_key·owner_key)를 선언하지 않는다 — 신고는 게시판을 가로지르는 운영 업무라
                // 「본인만」의 자연스러운 뜻이 없고, 1케이스 구조 뒤 케이스 테이블에 신고자 컬럼도 없다(#789).
                [
                    'identifier' => 'reports',
                    'name' => [
                        'ko' => '게시판 신고 관리',
                        'en' => 'Report Management',
                    ],
                    'description' => [
                        'ko' => '게시판 신고 관리 권한',
                        'en' => 'Board report management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => [
                                'ko' => '신고 조회',
                                'en' => 'View Reports',
                            ],
                            'description' => [
                                'ko' => '신고 목록 및 상세 조회',
                                'en' => 'View report list and details',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '신고 처리',
                                'en' => 'Manage Reports',
                            ],
                            'description' => [
                                'ko' => '신고 상태 변경 및 처리',
                                'en' => 'Update report status and process',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                [
                    'identifier' => 'scraps',
                    'resource_route_key' => 'scrap',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '게시글 스크랩 관리',
                        'en' => 'Scrap Management',
                    ],
                    'description' => [
                        'ko' => '전 회원 게시글 스크랩 조회·삭제 권한',
                        'en' => 'View and delete post scraps across all members',
                    ],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => [
                                'ko' => '스크랩 조회',
                                'en' => 'View Scraps',
                            ],
                            'description' => [
                                'ko' => '전 회원 스크랩 목록 조회',
                                'en' => 'View scrap list across all members',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '스크랩 삭제',
                                'en' => 'Delete Scraps',
                            ],
                            'description' => [
                                'ko' => '회원 스크랩 개별·일괄 삭제',
                                'en' => 'Delete member scraps individually or in bulk',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
                // 반응 관리 권한 (type: admin) — 스크랩과 동형
                [
                    'identifier' => 'reactions',
                    'resource_route_key' => 'reaction',
                    'owner_key' => 'user_id',
                    'name' => [
                        'ko' => '게시글 반응 관리',
                        'en' => 'Reaction Management',
                    ],
                    'description' => [
                        'ko' => '전 회원 게시글·댓글 반응 조회·삭제 권한',
                        'en' => 'View and delete post/comment reactions across all members',
                    ],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => [
                                'ko' => '반응 조회',
                                'en' => 'View Reactions',
                            ],
                            'description' => [
                                'ko' => '전 회원 반응 목록 조회',
                                'en' => 'View reaction list across all members',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'manage',
                            'name' => [
                                'ko' => '반응 삭제',
                                'en' => 'Delete Reactions',
                            ],
                            'description' => [
                                'ko' => '회원 반응 개별·일괄 삭제',
                                'en' => 'Delete member reactions individually or in bulk',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 모듈 설정 파일 목록 반환
     *
     * @return array<string, string> 설정 키 => 설정 파일 경로
     */
    public function getConfig(): array
    {
        return [
            'sirsoft-board' => $this->getModulePath().'/config/board.php',
        ];
    }

    /**
     * 런타임 동적 권한 식별자 전수.
     *
     * BoardPermissionService 가 게시판 생성 시 아래 세 계층을 Permission 테이블에 기록한다:
     *   - {module}                        (모듈 루트)
     *   - {module}.{slug}                 (게시판 카테고리)
     *   - {module}.{slug}.{action}        (action ∈ config board_permission_definitions)
     *
     * 모듈 루트는 getPermissions() 의 정적 식별자(moduleIdentifier) 와 동일하므로 생략.
     * cleanup 시 이 목록에 포함된 식별자는 stale 로 판정되지 않는다.
     *
     * @return array<int, string> 동적 권한 식별자 목록
     */
    public function getDynamicPermissionIdentifiers(): array
    {
        if (! Schema::hasTable('boards')) {
            return [];
        }

        $actions = array_keys((array) config('sirsoft-board.board_permission_definitions', []));
        $module = $this->getIdentifier();
        $ids = [];
        foreach (Board::query()->select('slug')->get() as $board) {
            $category = $module.'.'.$board->slug;
            $ids[] = $category;
            foreach ($actions as $action) {
                $ids[] = $category.'.'.$action;
            }
        }

        return $ids;
    }

    /**
     * 런타임 동적 역할 식별자 전수.
     *
     * BoardService::createBoardRoles 가 게시판 생성 시 생성:
     *   - {module}.{slug}.manager
     *   - {module}.{slug}.step
     *
     * @return array<int, string> 동적 역할 식별자 목록
     */
    public function getDynamicRoleIdentifiers(): array
    {
        if (! Schema::hasTable('boards')) {
            return [];
        }

        $module = $this->getIdentifier();
        $ids = [];
        foreach (Board::query()->select('slug')->get() as $board) {
            $ids[] = $module.'.'.$board->slug.'.manager';
            $ids[] = $module.'.'.$board->slug.'.step';
        }

        return $ids;
    }

    /**
     * 런타임 동적 메뉴 slug 전수.
     *
     * BoardService 가 게시판 생성 시 `board-{slug}` 형식의 메뉴를 기록.
     *
     * @return array<int, string> 동적 메뉴 slug 목록
     */
    public function getDynamicMenuSlugs(): array
    {
        if (! Schema::hasTable('boards')) {
            return [];
        }

        $slugs = [];
        foreach (Board::query()->select('slug')->get() as $board) {
            $slugs[] = 'board-'.$board->slug;
        }

        return $slugs;
    }

    /**
     * 모듈 설치 시 실행할 시더 목록 반환
     *
     * BoardTypeSeeder: 기본 게시판 유형(basic, gallery, card) 생성
     * BoardReactionTypeSeeder: 기본 반응 유형(추천·비추천) 생성 — 빠지면 새로 설치한 사이트의
     *   게시판에 반응 버튼이 나오지 않는다(게시판 기본 설정의 사용할 반응 유형이 가리킬 행이 없다)
     *
     * 그 외 시더(DatabaseSeeder, BoardSampleSeeder, PostSampleSeeder, ReportSampleSeeder)는
     * 테스트용 더미 데이터 시더이므로 설치 시 실행하지 않습니다.
     * 테스트 데이터가 필요한 경우 수동으로 실행하세요:
     *   php artisan module:seed sirsoft-board
     *
     * @return array<class-string<Seeder>> 시더 클래스명 배열 (FQCN)
     */
    public function getSeeders(): array
    {
        return [
            BoardTypeSeeder::class,
            BoardReactionTypeSeeder::class,
        ];
    }

    /**
     * 이 모듈이 선언하는 항목 목록을 반환합니다.
     *
     * 회원 항목 집합(`core.user`)에 게시판 알림 수신 설정 4칸을 선언한다. 값은 종전과 같이 이 모듈의 테이블에 두고
     * 핸들러(`UserNotificationFieldHandler`)가 읽고 쓴다. 요청 키와 응답 키는 항목 키 그대로(최상위 `notify_*`)다.
     * 가입 화면 표시는 기본이 꺼짐이며 운영자가 환경설정 > 회원에서 켤 수 있다.
     *
     * @return array<int, array<string, mixed>> 항목 선언 목록
     */
    public function getFields(): array
    {
        $fields = [];
        foreach (UserNotificationFieldHandler::FIELD_KEYS as $index => $key) {
            $fields[] = [
                'field_set' => 'core.user',
                'key' => $key,
                'type' => 'boolean',
                'storage' => 'handler',
                'storage_target' => UserNotificationFieldHandler::class,
                'name' => $this->fieldTexts("user.{$key}.name"),
                'description' => $this->fieldTexts("user.{$key}.description"),
                'enabled' => true,
                'required' => false,
                'show_on_signup' => false,
                'edit_scope' => 'self',
                'ui' => ['group' => 'notifications'],
                'sort_order' => 2000 + ($index * 10),
            ];
        }

        // 비회원 작성자 항목 집합의 이메일. 기본은 꺼짐이고 게시판마다 글과 댓글에서 따로 켠다.
        $fields[] = [
            'field_set' => GuestAuthorFields::SET,
            'key' => 'email',
            'type' => 'email',
            'storage' => 'value',
            'name' => $this->fieldTexts('guest_author.email.name'),
            'description' => $this->fieldTexts('guest_author.email.description'),
            'enabled' => false,
            'required' => false,
            'sort_order' => 100,
        ];

        return $fields;
    }

    /**
     * 이 모듈이 가진 항목 집합 선언을 반환합니다.
     *
     * 비회원 작성자 항목 집합(`sirsoft-board.guest_author`)은 비회원이 글과 댓글을 쓸 때 받는 항목이 모이는 자리다.
     * 이름과 비밀번호는 글과 댓글의 컬럼에 저장하는 고정 항목이라 집합에 넣지 않는다(늘 사용, 늘 필수).
     * 다른 확장도 `getFields()` 에서 이 집합에 항목을 선언할 수 있고, 그 항목은 게시판 설정의 「비회원 작성자 항목」 표에
     * 출처와 함께 나타난다. 요청 키는 `guest_fields.{항목 키}`, 값은 공용 값 테이블에 저장한다.
     *
     * @return array<int, array<string, mixed>> 집합 선언 목록
     */
    public function getFieldSets(): array
    {
        return [
            [
                'key' => GuestAuthorFields::SET,
                'label' => 'sirsoft-board::admin.guest_author_fields.set_label',
                'owner_types' => [GuestAuthorFields::OWNER_POST, GuestAuthorFields::OWNER_COMMENT],
                'surfaces' => [GuestAuthorFields::SURFACE_POST, GuestAuthorFields::SURFACE_COMMENT],
                // 비회원은 파일을 올릴 소유 계정이 없다. 파일과 이미지 유형은 받지 않는다.
                'allowed_types' => [
                    'text', 'textarea', 'number', 'boolean', 'date', 'datetime', 'date_range',
                    'choice', 'multi_choice', 'phone', 'email', 'url', 'address',
                ],
                'accepts_contributions' => true,
                'allows_custom' => true,
                'read_permission' => 'sirsoft-board.boards.read',
                'manage_permission' => 'sirsoft-board.boards.update',
                'bundle_key' => GuestAuthorFields::BUNDLE,
            ],
        ];
    }

    /**
     * 이 모듈이 등록하는 표시 이름 영역을 반환합니다.
     *
     * - `sirsoft-board:posts`: 사용자 화면의 글, 댓글, 최근글, 인기글, 검색, 스크랩과 반응 목록의 작성자. 게시판마다
     *   다르게 정할 수 있다(`boards.display_name_setting`). 정하지 않은 게시판은 이 영역의 설정을, 그것도 없으면 사이트 기본을 따른다.
     * - `sirsoft-board:admin`: 관리자 화면의 게시글, 댓글, 신고, 스크랩, 반응 관리의 회원 이름. 관리자 화면 영역을 따른다.
     *
     * @return array<int, array<string, mixed>> 영역 선언 목록
     */
    public function getDisplayNameAreas(): array
    {
        return [
            [
                'key' => BoardDisplayNames::AREA_POSTS,
                'label' => 'sirsoft-board::admin.display_name.areas.posts',
                'audience' => 'user',
                'parent' => 'core:site',
                'default' => ['mode' => 'inherit'],
                'instance_resolver' => [BoardDisplayNames::class, 'instanceSetting'],
                'used_kinds_provider' => [BoardDisplayNames::class, 'usages'],
                'sort_order' => 100,
            ],
            [
                'key' => BoardDisplayNames::AREA_ADMIN,
                'label' => 'sirsoft-board::admin.display_name.areas.admin',
                'audience' => 'admin',
                'parent' => 'core:admin',
                'default' => ['mode' => 'inherit'],
                'sort_order' => 110,
            ],
        ];
    }

    /**
     * 항목 문구 파일(`src/lang/{언어}/fields.php`)과 이 모듈을 대상으로 하는 활성 언어팩의
     * `backend/{언어}/fields.php` 에서 한 키의 언어별 글을 모읍니다(코어 `ExtensionLangTexts`).
     *
     * 번역기를 거치지 않고 파일을 직접 읽는다. 선언 동기화는 설치와 갱신 도중에도 돌아, 그때는 이 모듈의
     * 번역 네임스페이스가 아직 등록되지 않았을 수 있다. 경로는 `getModulePath()` 로 얻는다. 갱신 도중 새 코드를 다시 읽는
     * 인스턴스에서는 `__DIR__` 이 모듈 디렉터리를 가리키지 않을 수 있다. 언어팩(ja 등)을 읽지 않으면 그 언어 화면에서
     * 이 모듈의 항목 이름만 한국어로 보인다. 팩을 켜고 끄면 코어가 이 선언을 다시 동기화한다.
     *
     * @param  string  $path  점 표기 키 (예: user.notify_comment.name)
     * @return array<string, string> 언어 => 글
     */
    private function fieldTexts(string $path): array
    {
        return ExtensionLangTexts::collect('sirsoft-board', $this->getModulePath().'/src/lang', 'fields', $path);
    }

    /**
     * 훅 리스너 목록 반환
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            UserNotificationSettingsListener::class,
            SearchPostsListener::class,
            BoardNotificationDataListener::class,
            BoardNotificationChannelListener::class,
            BoardActivityLogListener::class,
            ActivityLogDescriptionResolver::class,
            SeoBoardCacheListener::class,
            // 상세 재렌더·재활성 대량 재색인은 큐 — 무효화(SeoBoardCacheListener, sync)가 먼저 돌고 커밋 뒤에 실행된다(#724)
            SeoBoardRegenerateListener::class,
            SeoBoardSettingsCacheListener::class,
            EcommerceInquiryHookListener::class,
            PostCountSyncListener::class,
            PostAttachmentCountSyncListener::class,
            PostReplySyncListener::class,
            CommentReplySyncListener::class,
            BoardPostsCountSyncListener::class,
            BoardCommentsCountSyncListener::class,
            Ckeditor5ReferenceSourcesListener::class,
            UserScrapInfoListener::class,
            UserReactionInfoListener::class,
            PrivacyRetentionHandlerListener::class,
            // 개인정보 동의 플러그인에 비밀글 열람 토큰 키를 필수 저장 키로 제공 (#789)
            ProvideNecessaryStorageKeysListener::class,
            // 스코프 강제 지점 원장 — 감사 전용(런타임 동작 무변경), #789
            BoardScopeLedgerListener::class,
            // 첨부 미리보기를 코어 이미지 썸네일의 서빙 경로·원본 제공자로 등록 (#869)
            ThumbnailServingListener::class,
            // 비회원 작성자 항목에 게시판별 사용, 필수 설정을 입힌다
            GuestAuthorFieldDefinitionListener::class,
            // 추가 정보를 입력하지 않은 회원에게도 비밀번호 확인 라우트를 열어 둔다
            ExemptRequiredFieldsRoutesListener::class,
            // 회원 삭제 직전 게시판 알림 설정·반응·스크랩을 명시적으로 지우고 집계를 다시 맞춘다 (#888)
            UserDeleteCleanupListener::class,
        ];
    }

    /**
     * SEO 변수 메타데이터 정의
     *
     * 게시판 모듈이 SEO 렌더링에 제공하는 변수를 page_type별로 선언합니다.
     *
     * @return array page_type별 변수 정의 배열
     */
    public function seoVariables(): array
    {
        return [
            '_common' => [
                'site_name' => [
                    'description' => '사이트명',
                    'source' => 'core_setting',
                    'key' => 'general.site_name',
                ],
            ],
            'boards' => [],
            'board' => [
                'board_name' => [
                    'description' => '게시판명',
                    'source' => 'data',
                    'required' => true,
                ],
                'board_description' => [
                    'description' => '게시판 설명',
                    'source' => 'data',
                ],
            ],
            'post' => [
                'board_name' => [
                    'description' => '게시판명',
                    'source' => 'data',
                    'required' => true,
                ],
                'post_title' => [
                    'description' => '게시글 제목',
                    'source' => 'data',
                    'required' => true,
                ],
            ],
        ];
    }

    /**
     * 페이지 타입별 OG 메타태그 기본값 선언
     *
     * 게시판 도메인에서 og:type=article, og:image, og:article:* 부속 태그를 직접 제공.
     *
     * @param  string  $pageType  페이지 타입
     * @param  array  $context  데이터 컨텍스트
     * @param  array  $routeParams  라우트 파라미터
     * @return array OG 데이터
     */
    public function seoOgDefaults(string $pageType, array $context, array $routeParams = []): array
    {
        if ($pageType === 'post') {
            $post = data_get($context, 'post.data', []);
            // 다국어 JSON array (MariaDB 환경) 자동 변환
            $title = $this->resolveLocalizedValue($post['subject'] ?? $post['title'] ?? '');

            $extra = [];
            $publishedAt = (string) ($post['created_at'] ?? '');
            if ($publishedAt !== '') {
                $extra[] = ['property' => 'article:published_time', 'content' => $publishedAt];
            }
            $modifiedAt = (string) ($post['updated_at'] ?? '');
            if ($modifiedAt !== '') {
                $extra[] = ['property' => 'article:modified_time', 'content' => $modifiedAt];
            }
            $authorName = (string) (data_get($post, 'author.name') ?? data_get($post, 'user.name') ?? '');
            if ($authorName !== '') {
                $extra[] = ['property' => 'article:author', 'content' => $authorName];
            }
            $boardName = (string) (data_get($context, 'board.data.name') ?? '');
            if ($boardName !== '') {
                $extra[] = ['property' => 'article:section', 'content' => $boardName];
            }

            // PostResource toArray() 의 'thumbnail' 키 사용 (PostResource.php:203).
            // 상대 경로는 절대 URL 로 변환한다 — Facebook/Threads/Slack 모두 절대 URL 필수.
            //
            // 변환은 `url()` 이 아니라 `AssetUrl::absolute()` 다. 이 값의 생산자
            // (`Attachment::previewUrlForSlug()`)가 `AssetUrl::basePrefix()` 를 거치므로
            // 값은 **이미 마운트 접두사를 품은** 사이트 절대 경로(`/g7/api/…`)인데,
            // `url()` 은 요청 루트(접두사 포함)를 앞에 다시 붙여 `https://host/g7/g7/api/…`
            // 가 된다. 크롤러만 받는 값이라 화면에는 아무 표시가 없고 미리보기 이미지만
            // 404 다. 접두사를 멱등으로 보정한 뒤 오리진만 붙이는
            // `AssetUrl::siteAbsolute()` 가 그 SSoT 이며, 그 독블록이 이 실패를 이름으로
            // 적고 있다.
            //
            // 종전에는 이 자리에 `str_starts_with('http') ? … : AssetUrl::absolute()` 라는
            // 순진한 형태가 있었다. 그 형태는 세 입력을 조용히 망가뜨린다 —
            // 프로토콜 상대(`//cdn/…` → `https://host//cdn/…`) · 상대 경로
            // (`img.png` → `https://hostimg.png`) · `data:` URI. 그리고 접두사를 채우는
            // 주체가 없어 리터럴 사이트 경로(`/images/logo.png`)는 하위 폴더 배치에서
            // 접두사를 잃었다.
            $thumbnailRaw = (string) (data_get($post, 'thumbnail') ?? '');
            // 보안: 요청 Host 가 아니라 사이트 주소 — 봇 화면은 캐시되어 모든 크롤러·미리보기에 나간다.
            // 오리진은 `AssetUrl::siteAbsolute()` 가 위임하는 `SiteUrl`(APP_URL)이 정한다.
            $image = AssetUrl::siteAbsolute($thumbnailRaw);

            return array_filter([
                'type' => 'article',
                'image' => $image,
                'image_alt' => $title,
                'extra' => $extra,
            ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        }

        return [];
    }

    /**
     * 페이지 타입별 JSON-LD 구조화 데이터 선언
     *
     * 게시판 도메인 스키마(Article)를 모듈이 직접 owned.
     *
     * @param  string  $pageType  페이지 타입
     * @param  array  $context  데이터 컨텍스트
     * @param  array  $routeParams  라우트 파라미터
     * @return array Schema.org Article 형식
     */
    public function seoStructuredData(string $pageType, array $context, array $routeParams = []): array
    {
        if ($pageType !== 'post') {
            return [];
        }

        $post = data_get($context, 'post.data', []);
        if (empty($post)) {
            return [];
        }

        // 다국어 JSON array 안전 변환
        $headline = $this->resolveLocalizedValue($post['subject'] ?? $post['title'] ?? '');
        if ($headline === '') {
            return [];
        }

        $schema = [
            '@type' => 'Article',
            'headline' => $headline,
        ];

        $description = $this->resolveLocalizedValue($post['summary'] ?? $post['excerpt'] ?? $post['content_text'] ?? '');
        if ($description !== '') {
            $schema['description'] = mb_substr(strip_tags($description), 0, 200);
        }

        // 썸네일 URL — thumbnail 키 우선 + 절대 URL 변환.
        // 형제(`seoOgDefaults`)와 **같은 변환기**를 쓴다. 두 곳이 갈리면 같은 값이 og 와
        // schema 에서 다른 주소로 나가고, 그중 한쪽만 404 가 된다.
        $thumbnailRaw = (string) (data_get($post, 'thumbnail') ?? '');
        if ($thumbnailRaw !== '') {
            $schema['image'] = AssetUrl::siteAbsolute($thumbnailRaw);
        }

        $publishedAt = (string) ($post['created_at'] ?? '');
        if ($publishedAt !== '') {
            $schema['datePublished'] = $publishedAt;
        }
        $modifiedAt = (string) ($post['updated_at'] ?? '');
        if ($modifiedAt !== '') {
            $schema['dateModified'] = $modifiedAt;
        }

        $authorName = (string) (data_get($post, 'author.name') ?? data_get($post, 'user.name') ?? '');
        if ($authorName !== '') {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => $authorName,
            ];
        }

        $publisherName = (string) g7_core_settings('general.site_name', '');
        if ($publisherName !== '') {
            $schema['publisher'] = [
                '@type' => 'Organization',
                'name' => $publisherName,
            ];
        }

        return $schema;
    }

    /**
     * OG 기본값 키별 데이터 출처(연결 칩) 메타 선언 — 편집기 전용
     *
     * seoOgDefaults() 가 resolve 해 반환하는 평문값이 **어느 게시글 데이터에서 왔는지**를 편집기
     * [검색엔진] 탭이 "대표 이미지"·"게시글 제목" 같은 연결 칩으로 보여주고 교체할 수 있도록,
     * 키별 데이터 경로(표현식)와 사용자용 라벨을 제공합니다. 단순 1:1 경로 키만 선언합니다.
     *
     * @param  string  $pageType  페이지 타입
     * @return array<string, array{expr: string, label: array<string, string>}> 키별 데이터 경로 메타
     */
    public function seoOgDefaultMeta(string $pageType): array
    {
        // label 은 번역 키 — 번들 언어팩(ja 등)이 같은 키를 번역하면 추가 언어에 자동 대응(편집기가 __() 해석).
        if ($pageType === 'post') {
            return [
                'image' => ['expr' => '{{post.data.thumbnail}}', 'label' => 'sirsoft-board::seo.auto_value.post_image'],
                'image_alt' => ['expr' => '{{post.data.subject}}', 'label' => 'sirsoft-board::seo.auto_value.post_title'],
            ];
        }

        return [];
    }

    /**
     * 구조화 데이터 속성별 데이터 출처(연결 칩) 메타 선언 — 편집기 전용
     *
     * seoStructuredData() 의 Article 스키마를 점 경로 키로 평탄화한 기준으로 선언합니다. 파생값
     * (description=strip_tags·datePublished·author.* 등)은 단순 경로가 아니라 제외합니다(평문 폴백).
     *
     * @param  string  $pageType  페이지 타입
     * @return array<string, array{expr: string, label: array<string, string>}> 점 경로 키별 데이터 경로 메타
     */
    public function seoStructuredDataMeta(string $pageType): array
    {
        if ($pageType !== 'post') {
            return [];
        }

        return [
            'headline' => ['expr' => '{{post.data.subject}}', 'label' => 'sirsoft-board::seo.auto_value.post_title'],
            'image' => ['expr' => '{{post.data.thumbnail}}', 'label' => 'sirsoft-board::seo.auto_value.post_image'],
        ];
    }

    /**
     * 스토리지 디스크 설정 반환
     *
     * @return string 디스크 이름(`.env` `SIRSOFT_BOARD_ATTACHMENT_DISK` 가 있으면 그 값, 없으면 `modules`)
     */
    public function getStorageDisk(): string
    {
        return config('sirsoft-board.attachment.disk', 'modules');
    }

    /**
     * `.env` `SIRSOFT_BOARD_ATTACHMENT_DISK` 로 첨부 디스크를 고정했는지 반환합니다 (#916 D7).
     *
     * 고정돼 있으면 첨부·본문 미디어는 관리자의 「비공개 첨부 저장소」 선택 대신 그 디스크를 쓰고, 환경설정 > 드라이버 화면이
     * 「.env 로 고정된 확장: 게시판」으로 안내한다.
     *
     * @return array{env: string, disk: string}|null 고정 정보(고정 없음이면 null)
     */
    public function getPrivateAttachmentDiskPin(): ?array
    {
        $explicit = config('sirsoft-board.attachment.disk_explicit');

        return is_string($explicit) && $explicit !== ''
            ? ['env' => 'SIRSOFT_BOARD_ATTACHMENT_DISK', 'disk' => $explicit]
            : null;
    }

    /**
     * 관리자 메뉴 정의
     *
     * @return array<int, array<string, mixed>> 관리자 메뉴 정의 목록
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => [
                    'ko' => '게시판 관리',
                    'en' => 'Board Management',
                ],
                'slug' => 'sirsoft-board',
                'url' => null,
                'icon' => 'fas fa-clipboard-list',
                'order' => 30,
                'children' => [
                    [
                        'name' => [
                            'ko' => '환경설정',
                            'en' => 'Settings',
                        ],
                        'slug' => 'sirsoft-board-settings',
                        'url' => '/admin/boards/settings',
                        'icon' => 'fas fa-cog',
                        'order' => 1,
                        'permission' => 'sirsoft-board.settings.read',
                    ],
                    [
                        'name' => [
                            'ko' => '게시판 목록',
                            'en' => 'Board List',
                        ],
                        'slug' => 'sirsoft-board-list',
                        'url' => '/admin/boards',
                        'icon' => 'fas fa-list',
                        'order' => 2,
                        'permission' => 'sirsoft-board.boards.read',
                    ],
                    [
                        'name' => [
                            'ko' => '게시판 신고현황',
                            'en' => 'Board Reports',
                        ],
                        'slug' => 'sirsoft-board-reports',
                        'url' => '/admin/boards/reports',
                        'icon' => 'fas fa-flag',
                        'order' => 3,
                        'permission' => 'sirsoft-board.reports.view',
                    ],
                    [
                        'name' => [
                            'ko' => '스크랩 관리',
                            'en' => 'Scrap Management',
                        ],
                        'slug' => 'sirsoft-board-scraps',
                        'url' => '/admin/boards/scraps',
                        'icon' => 'fas fa-bookmark',
                        'order' => 4,
                        'permission' => 'sirsoft-board.scraps.view',
                    ],
                    [
                        'name' => [
                            'ko' => '반응 관리',
                            'en' => 'Reaction Management',
                        ],
                        'slug' => 'sirsoft-board-reactions',
                        'url' => '/admin/boards/reactions',
                        'icon' => 'fas fa-thumbs-up',
                        'order' => 5,
                        'permission' => 'sirsoft-board.reactions.view',
                    ],
                ],
            ],
        ];
    }

    /**
     * 게시판 모듈이 선언하는 IDV 정책 목록.
     *
     * 코어 IDV 인프라가 install/update 시 IdentityPolicySyncHelper 를 통해 자동 동기화한다.
     * 운영자가 S1d / 게시판 환경설정 > 본인인증 탭 에서 enabled / grace_minutes / provider_id /
     * fail_mode 4 필드를 수정하면 user_overrides 에 기록되어 모듈 재설치 시에도 보존된다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIdentityPolicies(): array
    {
        return [
            // 게시글 개별 삭제 (관리자 민감 작업)
            [
                'key' => 'sirsoft-board.post.delete',
                'scope' => 'hook',
                'target' => 'sirsoft-board.post.before_delete',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 5,
                'enabled' => false,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
            // 게시글 블라인드 (관리자 민감 작업)
            [
                'key' => 'sirsoft-board.post.blind',
                'scope' => 'hook',
                'target' => 'sirsoft-board.post.before_blind',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 5,
                'enabled' => false,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
            // 게시글 일괄 처리 (관리자 민감 작업) — 삭제·블라인드·복구 공통.
            // 항목별 before_delete/before_blind 가 아니라 일괄 훅 하나를 대상으로 삼는다:
            // 루프 중간에 428 이 터지면 앞 항목만 처리된 상태로 끝나기 때문이다.
            [
                'key' => 'sirsoft-board.post.bulk_action',
                'scope' => 'hook',
                'target' => 'sirsoft-board.post.before_bulk_action',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 5,
                'enabled' => false,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
            // 신고 일괄 처리 (관리자 민감 작업)
            [
                'key' => 'sirsoft-board.report.bulk_action',
                'scope' => 'hook',
                'target' => 'sirsoft-board.report.before_bulk_update_status',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 5,
                'enabled' => false,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
            // 신고 삭제 (관리자 민감 작업)
            [
                'key' => 'sirsoft-board.report.delete',
                'scope' => 'hook',
                'target' => 'sirsoft-board.report.before_delete',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => false,
                'applies_to' => 'admin',
                'fail_mode' => 'block',
            ],
            // 자기 글 삭제 (사용자 본인 작업) — 계정 탈취 시 게시물 일괄 삭제 차단.
            // post.delete 정책과 같은 훅(before_delete)을 공유하지만 applies_to=self 로 분기됨.
            [
                'key' => 'sirsoft-board.post.user_delete',
                'scope' => 'hook',
                'target' => 'sirsoft-board.post.before_delete',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => false,
                'applies_to' => 'self',
                'fail_mode' => 'block',
            ],
            // 자기 댓글 삭제 (사용자 본인 작업) — 빈도 높아 grace 길게
            [
                'key' => 'sirsoft-board.comment.user_delete',
                'scope' => 'hook',
                'target' => 'sirsoft-board.comment.before_delete',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 30,
                'enabled' => false,
                'applies_to' => 'self',
                'fail_mode' => 'block',
            ],
            // 신고 작성 (사용자 본인 작업) — 악성/장난 신고 방지
            [
                'key' => 'sirsoft-board.report.create',
                'scope' => 'hook',
                'target' => 'sirsoft-board.report.before_create',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 30,
                'enabled' => false,
                'applies_to' => 'self',
                'fail_mode' => 'block',
            ],
            // 첫 글 작성 (사용자 본인 작업) — 스팸봇 방지
            [
                'key' => 'sirsoft-board.post.user_create',
                'scope' => 'hook',
                'target' => 'sirsoft-board.post.before_create',
                'purpose' => 'sensitive_action',
                'grace_minutes' => 0,
                'enabled' => false,
                'applies_to' => 'self',
                'fail_mode' => 'block',
            ],
        ];
    }

    /**
     * 게시판 모듈이 등록할 IDV purpose 목록.
     *
     * 게시판은 코어 sensitive_action 으로 충분하므로 신규 purpose 를 도입하지 않는다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getIdentityPurposes(): array
    {
        return [];
    }

    /**
     * 게시판 모듈이 등록할 IDV 메시지 정의.
     *
     * 현재 4개 정책 모두 purpose=sensitive_action 을 사용하므로 코어 fallback
     * (`provider=g7:core.mail`, `scope_type=purpose`, `scope_value=sensitive_action`)
     * 메시지로 충분하다. 게시판 도메인에 특화된 문구가 필요해지거나 사용자 측 정책
     * (예: 자기 글 삭제, 신고 작성)이 추가되면 여기에 등록한다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIdentityMessages(): array
    {
        return [];
    }

    /**
     * 게시판 모듈이 등록할 알림 정의.
     *
     * AbstractModule 계약에 따라 ModuleManager 가 activate/update 시 자동으로
     * NotificationSyncHelper 를 통해 동기화하며, uninstall(deleteData=true) 시 정리합니다.
     * 운영자가 관리자 UI 에서 수정한 필드는 user_overrides JSON 에 보존됩니다.
     *
     * `extension_type`/`extension_identifier` 는 Manager 가 자동 주입.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNotificationDefinitions(): array
    {
        return [
            $this->newCommentDefinition(),
            $this->replyCommentDefinition(),
            $this->postReplyDefinition(),
            $this->postActionDefinition(),
            $this->newPostAdminDefinition(),
            $this->reportReceivedAdminDefinition(),
            $this->reportActionDefinition(),
        ];
    }

    /**
     * 성능 계측 프로파일 정의 (`g7:bench`).
     *
     * 게시글 목록의 `columns` 는 `PostRepository::paginate()` 가 select 하는 컬럼 집합입니다
     * (본문 미리보기용 `SUBSTRING` 표현식은 지연 조인의 outer 에서만 적용되고 계측 대상인
     * 건너뛰기 비용과 무관하므로 제외). 게시글 목록은 화면이 게시판 하나를 조회하므로 필터
     * 없이 재면 인덱스 선택이 달라져 화면에서 일어나는 일과 다른 것을 재게 됩니다.
     *
     * @return array<string, array<string, mixed>> 프로파일 키 → 정의
     */
    public function getBenchmarkProfiles(): array
    {
        return [
            'board_posts' => [
                'type' => 'list',
                'label' => '게시글 목록',
                'table' => 'board_posts',
                'columns' => [
                    'id', 'board_id', 'user_id', 'parent_id', 'category',
                    'title', 'author_name', 'content_mode',
                    'is_notice', 'is_secret', 'status', 'depth',
                    'view_count', 'comments_count', 'replies_count', 'attachments_count',
                    'trigger_type', 'ip_address', 'created_at', 'updated_at', 'deleted_at',
                ],
                'order' => [['created_at', 'desc'], ['id', 'desc']],
                // 실제 목록 쿼리(PostRepository::buildSortedPostList)는 공지와 답글을 빼고
                // 원글만 페이지네이션한다. 필터를 board_id 만 걸면 등치 사슬이 is_notice 에서
                // 끊겨 옵티마이저가 다른 인덱스를 골라, 제품이 실행하지 않는 실행 계획을 재게 된다.
                'filters' => ['board_id' => 1, 'is_notice' => 0, 'parent_id' => null],
                'seed_overrides' => ['board_id' => 1, 'is_notice' => 0, 'parent_id' => null],
                'soft_delete' => true,
            ],
            'board_posts_by_view_count' => [
                'type' => 'list',
                'label' => '게시글 목록 (조회순)',
                'table' => 'board_posts',
                'columns' => [
                    'id', 'board_id', 'user_id', 'parent_id', 'category',
                    'title', 'author_name', 'content_mode',
                    'is_notice', 'is_secret', 'status', 'depth',
                    'view_count', 'comments_count', 'replies_count', 'attachments_count',
                    'trigger_type', 'ip_address', 'created_at', 'updated_at', 'deleted_at',
                ],
                // 조회수 정렬은 화면에서 실제로 도달 가능한 경로다 — 게시판 설정
                // `order_by='view_count'` 와 목록 URL `?sort_by=view_count` 둘 다 있고,
                // 저장소 정렬 화이트리스트에도 들어 있다. 작성일 정렬과 같은 술어에
                // 정렬 축만 다르므로 별도 프로파일로 재야 인덱스 커버리지가 판정된다.
                'order' => [['view_count', 'desc'], ['id', 'desc']],
                'filters' => ['board_id' => 1, 'is_notice' => 0, 'parent_id' => null],
                'seed_overrides' => ['board_id' => 1, 'is_notice' => 0, 'parent_id' => null],
                'soft_delete' => true,
            ],
            // 댓글은 계측 프로파일을 두지 않는다. 페이지네이션되는 댓글 목록은 회원 본인 댓글
            // 목록뿐이고(CommentRepository), 그 쿼리에는 회원 스코프 · 삭제 게시글 제외
            // (whereExists 서브쿼리) · 비활성 게시판 제외가 무조건 붙는다. 선언형 필터로
            // 재현할 수 없는 술어라, 맨 테이블 스캔을 재면 어느 화면도 내지 않는 수치가 된다.
            'reports' => [
                'type' => 'list',
                'label' => '신고 목록',
                'table' => 'boards_reports',
                'columns' => ['*'],
                'order' => [['created_at', 'desc'], ['id', 'desc']],
                'soft_delete' => true,
            ],
            // 요청 1건을 새 프로세스에서 처음부터 처리하는 부팅 비용 — 코어 boot 축(7.1.5)이 없는 코어에서는
            // 알 수 없는 축으로 경고만 남고 이 선언만 빠진다
            'boot_board_menu' => [
                'type' => 'boot',
                'label' => '게시판 메뉴 API 부팅',
                'uri' => '/api/modules/sirsoft-board/boards/board-menu',
            ],
        ];
    }

    /**
     * 새 댓글 알림 정의.
     *
     * @return array<string, mixed> 알림 정의(유형·훅·변수·채널 템플릿)
     */
    private function newCommentDefinition(): array
    {
        return [
            'type' => 'new_comment',
            'hook_prefix' => 'sirsoft-board',
            'audience' => 'customer',
            'name' => ['ko' => '새 댓글 알림', 'en' => 'New Comment Notification'],
            'description' => ['ko' => '게시글에 새 댓글이 작성되면 게시글 작성자에게 발송', 'en' => 'Sent to post author when a new comment is posted'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.comment.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'comment_author', 'description' => '댓글 작성자'],
                ['key' => 'comment_content', 'description' => '댓글 내용 (200자)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] 게시글에 새 댓글이 등록되었습니다',
                        'en' => '[{board_name}] New comment on your post',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 게시글에 <strong>{comment_author}</strong>님이 댓글을 남겼습니다.</p>'
                            .'<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                            .$this->notificationButton('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p><strong>{comment_author}</strong> commented on your post in <strong>{board_name}</strong>.</p>'
                            .'<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                            .$this->notificationButton('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '게시글에 새 댓글이 달렸습니다', 'en' => 'New comment on your post'],
                    'body' => ['ko' => '{comment_author}님이 \'{board_name}\' 게시글 \'{post_title}\'에 댓글을 남겼습니다.', 'en' => '{comment_author} commented on your post \'{post_title}\' in \'{board_name}\'.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 대댓글 알림 정의.
     *
     * @return array<string, mixed> 알림 정의(유형·훅·변수·채널 템플릿)
     */
    private function replyCommentDefinition(): array
    {
        return [
            'type' => 'reply_comment',
            'hook_prefix' => 'sirsoft-board',
            'audience' => 'customer',
            'name' => ['ko' => '대댓글 알림', 'en' => 'Reply Comment Notification'],
            'description' => ['ko' => '댓글에 대댓글이 작성되면 댓글 작성자에게 발송', 'en' => 'Sent to comment author when a reply is posted'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.comment.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'comment_author', 'description' => '답글 작성자'],
                ['key' => 'comment_content', 'description' => '답글 내용 (200자)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'parent_comment_author', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] 댓글에 답글이 등록되었습니다',
                        'en' => '[{board_name}] Reply to your comment',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판에서 <strong>{comment_author}</strong>님이 댓글에 답글을 남겼습니다.</p>'
                            .'<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                            .$this->notificationButton('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p><strong>{comment_author}</strong> replied to your comment in <strong>{board_name}</strong>.</p>'
                            .'<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                            .$this->notificationButton('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'parent_comment_author', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '댓글에 답글이 달렸습니다', 'en' => 'New reply to your comment'],
                    'body' => ['ko' => '{comment_author}님이 \'{board_name}\'의 댓글에 답글을 남겼습니다.', 'en' => '{comment_author} replied to your comment in \'{board_name}\'.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 답변글 알림 정의.
     *
     * @return array<string, mixed> 알림 정의(유형·훅·변수·채널 템플릿)
     */
    private function postReplyDefinition(): array
    {
        return [
            'type' => 'post_reply',
            'hook_prefix' => 'sirsoft-board',
            'audience' => 'customer',
            'name' => ['ko' => '답변글 알림', 'en' => 'Post Reply Notification'],
            'description' => ['ko' => '게시글에 답변글이 작성되면 원글 작성자에게 발송', 'en' => 'Sent to original post author when a reply post is created'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.post.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'original_post_author', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] 게시글에 답변글이 등록되었습니다',
                        'en' => '[{board_name}] Reply to your post',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 게시글 "<strong>{post_title}</strong>"에 답변글이 등록되었습니다.</p>'
                            .$this->notificationButton('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>A reply has been posted to your post "<strong>{post_title}</strong>" in <strong>{board_name}</strong>.</p>'
                            .$this->notificationButton('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'original_post_author', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '게시글에 답변이 등록되었습니다', 'en' => 'A reply has been posted to your post'],
                    'body' => ['ko' => '\'{board_name}\'의 게시글 \'{post_title}\'에 답변이 등록되었습니다.', 'en' => 'A reply has been posted to your post \'{post_title}\' in \'{board_name}\'.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 게시글 처리 알림 정의.
     *
     * @return array<string, mixed> 알림 정의(유형·훅·변수·채널 템플릿)
     */
    private function postActionDefinition(): array
    {
        return [
            'type' => 'post_action',
            'hook_prefix' => 'sirsoft-board',
            'audience' => 'customer',
            'name' => ['ko' => '게시글/댓글 처리 알림', 'en' => 'Content Action Notification'],
            'description' => ['ko' => '게시글/댓글 블라인드/삭제/복원 시 작성자에게 발송', 'en' => 'Sent to author on blind/delete/restore actions'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.post.after_blind', 'sirsoft-board.post.after_delete', 'sirsoft-board.post.after_restore', 'sirsoft-board.comment.after_blind', 'sirsoft-board.comment.after_delete', 'sirsoft-board.comment.after_restore'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'action_type', 'description' => '처리 유형 (블라인드/삭제/복원)'],
                ['key' => 'target_type', 'description' => '처리 대상 (게시글/댓글)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] \'{post_title}\' {target_type} {action_type} 처리 안내',
                        'en' => '[{board_name}] Your {target_type} "{post_title}" has been {action_type}',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 "<strong>{post_title}</strong>" {target_type}에 대해 관리자가 <strong>{action_type}</strong> 처리를 했습니다.</p>'
                            .'<p>문의사항이 있으시면 관리자에게 연락해 주세요.</p>'
                            .$this->notificationButton('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your {target_type} "<strong>{post_title}</strong>" in <strong>{board_name}</strong> has been <strong>{action_type}</strong> by an administrator.</p>'
                            .'<p>If you have any questions, please contact the administrator.</p>'
                            .$this->notificationButton('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '{target_type} 처리 안내', 'en' => 'Your {target_type} has been actioned'],
                    'body' => ['ko' => '\'{board_name}\'의 \'{post_title}\' {target_type}에 대해 {action_type} 처리를 했습니다.', 'en' => 'Your {target_type} \'{post_title}\' in \'{board_name}\' has been {action_type}.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 관리자 새 게시글 알림 정의.
     *
     * @return array<string, mixed> 알림 정의(유형·훅·변수·채널 템플릿)
     */
    private function newPostAdminDefinition(): array
    {
        return [
            'type' => 'new_post_admin',
            'hook_prefix' => 'sirsoft-board',
            'audience' => 'staff',
            'name' => ['ko' => '새 게시글 관리자 알림', 'en' => 'New Post Admin Notification'],
            'description' => ['ko' => '새 게시글 작성 시 관리자에게 발송', 'en' => 'Sent to admin when a new post is created'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.post.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름 (관리자)'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'post_author', 'description' => '게시글 작성자'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'board_managers', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] 새 게시글이 등록되었습니다',
                        'en' => '[{board_name}] New post has been created',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판에 <strong>{post_author}</strong>님이 새 게시글을 등록했습니다.</p>'
                            .'<p>게시글 제목: <strong>{post_title}</strong></p>'
                            .$this->notificationButton('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p><strong>{post_author}</strong> created a new post in <strong>{board_name}</strong>.</p>'
                            .'<p>Title: <strong>{post_title}</strong></p>'
                            .$this->notificationButton('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'board_managers', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '새 게시글이 등록되었습니다', 'en' => 'New post registered'],
                    'body' => ['ko' => '{post_author}님이 \'{board_name}\'에 새 게시글 \'{post_title}\'을 등록했습니다.', 'en' => '{post_author} posted \'{post_title}\' in \'{board_name}\'.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 신고 접수 관리자 알림 정의.
     *
     * @return array<string, mixed> 알림 정의(유형·훅·변수·채널 템플릿)
     */
    private function reportReceivedAdminDefinition(): array
    {
        return [
            'type' => 'report_received_admin',
            'hook_prefix' => 'sirsoft-board',
            'audience' => 'staff',
            'name' => ['ko' => '신고 접수 관리자 알림', 'en' => 'Report Received Admin Notification'],
            'description' => ['ko' => '신고 접수 시 관리자에게 발송', 'en' => 'Sent to admin when a report is received'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.report.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '신고 대상 게시글 제목'],
                ['key' => 'target_type', 'description' => '신고 대상 유형 (게시글/댓글)'],
                ['key' => 'reason_type', 'description' => '신고 사유'],
                ['key' => 'report_url', 'description' => '신고 관리 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [
                        // 그 게시판의 관리자 — 게시판마다 역할이 동적으로 생겨 이름을 열거할 수 없다.
                        ['type' => 'related_user', 'relation' => 'board_managers', 'exclude_trigger_user' => true],
                        // 신고 처리 대기열을 소유하는 사이트 운영자.
                        ['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true],
                    ],
                    'subject' => [
                        'ko' => '[{board_name}] "{post_title}"에 대한 신고가 접수되었습니다',
                        'en' => '[{board_name}] A new report has been received for "{post_title}"',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판에서 <strong>{target_type}</strong> "<strong>{post_title}</strong>"에 대한 새 신고가 접수되었습니다.</p>'
                            .'<p>신고 사유: <strong>{reason_type}</strong></p>'
                            .$this->notificationButton('신고 관리 페이지로 이동', '{report_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>A new report has been received for the <strong>{target_type}</strong> "<strong>{post_title}</strong>" in <strong>{board_name}</strong>.</p>'
                            .'<p>Reason: <strong>{reason_type}</strong></p>'
                            .$this->notificationButton('Go to Report Management', '{report_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [
                        // 그 게시판의 관리자 — 게시판마다 역할이 동적으로 생겨 이름을 열거할 수 없다.
                        ['type' => 'related_user', 'relation' => 'board_managers', 'exclude_trigger_user' => true],
                        // 신고 처리 대기열을 소유하는 사이트 운영자.
                        ['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true],
                    ],
                    'subject' => ['ko' => '신고가 접수되었습니다', 'en' => 'New report received'],
                    'body' => ['ko' => '\'{board_name}\'의 {target_type} \'{post_title}\'에 대한 신고({reason_type})가 접수되었습니다.', 'en' => 'A report ({reason_type}) on {target_type} \'{post_title}\' in \'{board_name}\' has been received.'],
                    'click_url' => '{report_url}',
                ],
            ],
        ];
    }

    /**
     * 신고 처리 결과 알림 정의.
     *
     * @return array<string, mixed> 알림 정의(유형·훅·변수·채널 템플릿)
     */
    private function reportActionDefinition(): array
    {
        return [
            'type' => 'report_action',
            'hook_prefix' => 'sirsoft-board',
            'audience' => 'customer',
            'name' => ['ko' => '신고 처리 결과 알림', 'en' => 'Report Action Notification'],
            'description' => ['ko' => '신고 처리 완료 시 게시글 작성자에게 발송', 'en' => 'Sent to post author when a report action is taken'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.post.after_blind', 'sirsoft-board.post.after_delete', 'sirsoft-board.post.after_restore', 'sirsoft-board.comment.after_blind', 'sirsoft-board.comment.after_delete', 'sirsoft-board.comment.after_restore'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'action_type', 'description' => '처리 유형 (블라인드/삭제/복원)'],
                ['key' => 'target_type', 'description' => '처리 대상 (게시글/댓글)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] \'{post_title}\' {target_type} {action_type} 처리 안내',
                        'en' => '[{board_name}] Your {target_type} "{post_title}" has been {action_type}',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 "<strong>{post_title}</strong>" {target_type}에 대해 신고 처리로 <strong>{action_type}</strong> 조치가 이루어졌습니다.</p>'
                            .'<p>문의사항이 있으시면 관리자에게 연락해 주세요.</p>'
                            .$this->notificationButton('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your {target_type} "<strong>{post_title}</strong>" in <strong>{board_name}</strong> has been <strong>{action_type}</strong> due to reports.</p>'
                            .'<p>If you have any questions, please contact the administrator.</p>'
                            .$this->notificationButton('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '신고 처리 결과 안내', 'en' => 'Report action result'],
                    'body' => ['ko' => '\'{board_name}\'의 \'{post_title}\' {target_type}에 대해 {action_type} 처리를 했습니다.', 'en' => 'Your {target_type} \'{post_title}\' in \'{board_name}\' has been {action_type}.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 이메일 호환 CTA 버튼 HTML 을 생성합니다.
     *
     * @param  string  $text  버튼 텍스트
     * @param  string  $url  버튼 링크 URL
     * @return string 인라인 스타일 버튼 HTML
     */
    private function notificationButton(string $text, string $url): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;">'
            .'<tr><td align="center">'
            .'<a href="'.$url.'" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">'
            .$text
            .'</a>'
            .'</td></tr></table>';
    }
}
