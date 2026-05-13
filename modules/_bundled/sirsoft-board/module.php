<?php

namespace Modules\Sirsoft\Board;

use App\Extension\AbstractModule;
use App\Models\Role;
use App\Seo\Concerns\LocalizesSeoValues;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Board\Database\Seeders\BoardTypeSeeder;
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
                // 본인인증 정책 관리 권한 (type: admin)
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

            // PostResource toArray() 의 'thumbnail' 키 사용 (PostResource.php:183).
            // 상대 경로(/api/...) 는 url() 로 절대 URL 변환 — Facebook/Threads/Slack 모두 절대 URL 필수.
            $thumbnailRaw = (string) (data_get($post, 'thumbnail') ?? '');
            $image = $thumbnailRaw !== ''
                ? (str_starts_with($thumbnailRaw, 'http') ? $thumbnailRaw : url($thumbnailRaw))
                : '';

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

        // 썸네일 URL — thumbnail 키 우선 + 절대 URL 변환
        $thumbnailRaw = (string) (data_get($post, 'thumbnail') ?? '');
        if ($thumbnailRaw !== '') {
            $schema['image'] = str_starts_with($thumbnailRaw, 'http') ? $thumbnailRaw : url($thumbnailRaw);
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
     * 새 댓글 알림 정의.
     */
    private function newCommentDefinition(): array
    {
        return [
            'type' => 'new_comment',
            'hook_prefix' => 'sirsoft-board',
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
     */
    private function replyCommentDefinition(): array
    {
        return [
            'type' => 'reply_comment',
            'hook_prefix' => 'sirsoft-board',
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
     */
    private function postReplyDefinition(): array
    {
        return [
            'type' => 'post_reply',
            'hook_prefix' => 'sirsoft-board',
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
     */
    private function postActionDefinition(): array
    {
        return [
            'type' => 'post_action',
            'hook_prefix' => 'sirsoft-board',
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
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
                    'subject' => [
                        'ko' => '[{board_name}] \'{post_title}\' {target_type}이(가) {action_type} 처리되었습니다',
                        'en' => '[{board_name}] Your {target_type} "{post_title}" has been {action_type}',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 "<strong>{post_title}</strong>" {target_type}이(가) 관리자에 의해 <strong>{action_type}</strong> 처리되었습니다.</p>'
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
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
                    'subject' => ['ko' => '{target_type}이(가) 처리되었습니다', 'en' => 'Your {target_type} has been actioned'],
                    'body' => ['ko' => '\'{board_name}\'의 \'{post_title}\' {target_type}이(가) {action_type} 처리되었습니다.', 'en' => 'Your {target_type} \'{post_title}\' in \'{board_name}\' has been {action_type}.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 관리자 새 게시글 알림 정의.
     */
    private function newPostAdminDefinition(): array
    {
        return [
            'type' => 'new_post_admin',
            'hook_prefix' => 'sirsoft-board',
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
     */
    private function reportReceivedAdminDefinition(): array
    {
        return [
            'type' => 'report_received_admin',
            'hook_prefix' => 'sirsoft-board',
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
                    'recipients' => [['type' => 'permission', 'value' => 'sirsoft-board.reports.manage', 'exclude_trigger_user' => true]],
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
                    'recipients' => [['type' => 'permission', 'value' => 'sirsoft-board.reports.manage', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '신고가 접수되었습니다', 'en' => 'New report received'],
                    'body' => ['ko' => '\'{board_name}\'의 {target_type} \'{post_title}\'에 대한 신고({reason_type})가 접수되었습니다.', 'en' => 'A report ({reason_type}) on {target_type} \'{post_title}\' in \'{board_name}\' has been received.'],
                    'click_url' => '{report_url}',
                ],
            ],
        ];
    }

    /**
     * 신고 처리 결과 알림 정의.
     */
    private function reportActionDefinition(): array
    {
        return [
            'type' => 'report_action',
            'hook_prefix' => 'sirsoft-board',
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
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
                    'subject' => [
                        'ko' => '[{board_name}] \'{post_title}\' {target_type}이(가) {action_type} 처리되었습니다',
                        'en' => '[{board_name}] Your {target_type} "{post_title}" has been {action_type}',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 "<strong>{post_title}</strong>" {target_type}이(가) 신고 처리에 의해 <strong>{action_type}</strong> 처리되었습니다.</p>'
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
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
                    'subject' => ['ko' => '신고 처리 결과 안내', 'en' => 'Report action result'],
                    'body' => ['ko' => '\'{board_name}\'의 \'{post_title}\' {target_type}이(가) {action_type} 처리되었습니다.', 'en' => 'Your {target_type} \'{post_title}\' in \'{board_name}\' has been {action_type}.'],
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
