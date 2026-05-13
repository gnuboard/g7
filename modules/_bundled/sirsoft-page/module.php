<?php

namespace Modules\Sirsoft\Page;

use App\Extension\AbstractModule;
use Modules\Sirsoft\Page\Database\Seeders\PageSeeder;
use Modules\Sirsoft\Page\Listeners\ActivityLogDescriptionResolver;
use Modules\Sirsoft\Page\Listeners\PageActivityLogListener;
use Modules\Sirsoft\Page\Listeners\SearchPagesListener;
use Modules\Sirsoft\Page\Listeners\SeoPageCacheListener;

class Module extends AbstractModule
{
    /**
     * 모듈 역할 정의
     */
    public function getRoles(): array
    {
        return [];
    }

    /**
     * 모듈 권한 목록 반환 (계층형 구조, 다국어 지원)
     *
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return [
            'name' => [
                'ko' => '페이지',
                'en' => 'Page',
            ],
            'description' => [
                'ko' => '페이지 모듈 권한',
                'en' => 'Page module permissions',
            ],
            'categories' => [
                [
                    'identifier' => 'pages',
                    'resource_route_key' => 'page',
                    'owner_key' => 'created_by',
                    'name' => [
                        'ko' => '페이지 관리',
                        'en' => 'Page Management',
                    ],
                    'description' => [
                        'ko' => '페이지 관리 권한',
                        'en' => 'Page management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '페이지 조회',
                                'en' => 'View Pages',
                            ],
                            'description' => [
                                'ko' => '페이지 목록 및 상세 조회',
                                'en' => 'View page list and details',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '페이지 생성',
                                'en' => 'Create Page',
                            ],
                            'description' => [
                                'ko' => '새 페이지 생성',
                                'en' => 'Create new page',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '페이지 수정',
                                'en' => 'Update Page',
                            ],
                            'description' => [
                                'ko' => '페이지 내용 수정',
                                'en' => 'Update page content',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '페이지 삭제',
                                'en' => 'Delete Page',
                            ],
                            'description' => [
                                'ko' => '페이지 삭제',
                                'en' => 'Delete page',
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
     * 모듈 설치 시 실행할 시더 목록 반환
     *
     * @return array<class-string<\Illuminate\Database\Seeder>>
     */
    public function getSeeders(): array
    {
        return [
            PageSeeder::class,
        ];
    }

    /**
     * 훅 리스너 목록 반환
     *
     * @return array<class-string>
     */
    public function getHookListeners(): array
    {
        return [
            SearchPagesListener::class,
            PageActivityLogListener::class,
            ActivityLogDescriptionResolver::class,
            SeoPageCacheListener::class,
        ];
    }

    /**
     * 스토리지 디스크 설정 반환
     */
    public function getStorageDisk(): string
    {
        return config('sirsoft-page.attachment.disk', 'modules');
    }

    /**
     * 관리자 메뉴 정의
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => [
                    'ko' => '페이지 관리',
                    'en' => 'Page Management',
                ],
                'slug' => 'sirsoft-page',
                'url' => '/admin/pages',
                'icon' => 'fas fa-file-alt',
                'order' => 25,
                'permission' => 'sirsoft-page.pages.read',
            ],
        ];
    }
}
