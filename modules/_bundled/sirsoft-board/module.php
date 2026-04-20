<?php

namespace Modules\Sirsoft\Board;

use App\Extension\AbstractModule;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Board\Database\Seeders\BoardTypeSeeder;
use Modules\Sirsoft\Board\Database\Seeders\InstallSeeder;
use Modules\Sirsoft\Board\Listeners\ActivityLogDescriptionResolver;
use Modules\Sirsoft\Board\Listeners\BoardActivityLogListener;
use Modules\Sirsoft\Board\Listeners\BoardNotificationChannelListener;
use Modules\Sirsoft\Board\Listeners\BoardNotificationDataListener;
use Modules\Sirsoft\Board\Listeners\BoardCommentsCountSyncListener;
use Modules\Sirsoft\Board\Listeners\BoardPostsCountSyncListener;
use Modules\Sirsoft\Board\Listeners\CommentReplySyncListener;
use Modules\Sirsoft\Board\Listeners\EcommerceInquiryHookListener;
use Modules\Sirsoft\Board\Listeners\PostAttachmentCountSyncListener;
use Modules\Sirsoft\Board\Listeners\PostCountSyncListener;
use Modules\Sirsoft\Board\Listeners\PostReplySyncListener;
use Modules\Sirsoft\Board\Listeners\SearchPostsListener;
use Modules\Sirsoft\Board\Listeners\SeoBoardCacheListener;
use Modules\Sirsoft\Board\Listeners\SeoBoardSettingsCacheListener;
use Modules\Sirsoft\Board\Listeners\UserNotificationSettingsListener;

class Module extends AbstractModule
{
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
        Role::where('extension_type', 'module')
            ->where('extension_identifier', 'sirsoft-board')
            ->each(function (Role $role) {
                $role->permissions()->detach();
                $role->users()->detach();
                $role->delete();
            });

        return parent::uninstall();
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
                // 신고 관리 권한 (type: admin)
                [
                    'identifier' => 'reports',
                    'resource_route_key' => 'report',
                    'owner_key' => 'reporter_id',
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
            ],
        ];
    }

    /**
     * 모듈 설정 파일 목록 반환
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
     */
    public function getDynamicPermissionIdentifiers(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('boards')) {
            return [];
        }

        $actions = array_keys((array) config('sirsoft-board.board_permission_definitions', []));
        $module = $this->getIdentifier();
        $ids = [];
        foreach (\Modules\Sirsoft\Board\Models\Board::query()->select('slug')->get() as $board) {
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
     */
    public function getDynamicRoleIdentifiers(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('boards')) {
            return [];
        }

        $module = $this->getIdentifier();
        $ids = [];
        foreach (\Modules\Sirsoft\Board\Models\Board::query()->select('slug')->get() as $board) {
            $ids[] = $module.'.'.$board->slug.'.manager';
            $ids[] = $module.'.'.$board->slug.'.step';
        }

        return $ids;
    }

    /**
     * 런타임 동적 메뉴 slug 전수.
     *
     * BoardService 가 게시판 생성 시 `board-{slug}` 형식의 메뉴를 기록.
     */
    public function getDynamicMenuSlugs(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('boards')) {
            return [];
        }

        $slugs = [];
        foreach (\Modules\Sirsoft\Board\Models\Board::query()->select('slug')->get() as $board) {
            $slugs[] = 'board-'.$board->slug;
        }

        return $slugs;
    }

    /**
     * 모듈 설치 시 실행할 시더 목록 반환
     *
     * BoardTypeSeeder: 기본 게시판 유형(basic, gallery, card) 생성
     * InstallSeeder: 기본 메일 템플릿 5종 생성
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
            InstallSeeder::class,
        ];
    }

    /**
     * 훅 리스너 목록 반환
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
            SeoBoardSettingsCacheListener::class,
            EcommerceInquiryHookListener::class,
            PostCountSyncListener::class,
            PostAttachmentCountSyncListener::class,
            PostReplySyncListener::class,
            CommentReplySyncListener::class,
            BoardPostsCountSyncListener::class,
            BoardCommentsCountSyncListener::class,
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
     * 스토리지 디스크 설정 반환
     */
    public function getStorageDisk(): string
    {
        return config('sirsoft-board.attachment.disk', 'modules');
    }

    /**
     * 관리자 메뉴 정의
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
                ],
            ],
        ];
    }
}
