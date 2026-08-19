<?php

namespace Modules\Sirsoft\Page;

use App\Extension\AbstractModule;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Page\Database\Seeders\PageSeeder;
use Modules\Sirsoft\Page\Listeners\ActivityLogDescriptionResolver;
use Modules\Sirsoft\Page\Listeners\Ckeditor5ReferenceSourcesListener;
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
     * @return array<class-string<Seeder>>
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
            Ckeditor5ReferenceSourcesListener::class,
        ];
    }

    /**
     * 모듈 스케줄 목록 반환
     *
     * 페이지 작성 폼에서 올린 뒤 저장되지 않은 임시 첨부를 매일 정리합니다.
     * `temp_key` 가 남아 있으면 끝내 연결되지 않은 폼 세션 부산물이라 오탐 여지가 없어
     * 별도 운영자 토글 없이 상시 동작합니다 (보존기간은 커맨드 옵션).
     *
     * @return array<int, array<string, mixed>> 스케줄 정의 목록
     */
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'sirsoft-page:prune-temp-attachments',
                'schedule' => 'daily',
                'description' => '미연결 임시 페이지 첨부 자동 삭제',
                'enabled_config' => null,
            ],
        ];
    }

    /**
     * 스토리지 디스크 설정 반환
     */
    public function getStorageDisk(): string
    {
        // 스토리지 디스크는 모듈 환경설정으로 정할 수 없다 — 설정 파일 자체가 이 디스크에 저장되므로
        // module_setting() 을 여기서 호출하면 설정 로드 → getStorage() → getStorageDisk() → 설정 로드
        // 무한 재귀가 된다. 기존의 config('sirsoft-page.attachment.disk') 참조도 해당 config 파일이
        // 존재하지 않아 항상 폴백만 반환했다. 디스크는 코어 filesystems 설정으로 관리한다.
        return 'modules';
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

    /**
     * 성능 계측 프로파일 정의 (`g7:bench`).
     *
     * 페이지 목록은 응답 계약상 본문까지 그대로 노출하므로 컬럼 프루닝이 불가합니다
     * (`['*']`). 이 경우 계측의 비교축은 select * vs select id 이며, 그 배수가 지연 조인
     * 적용의 기대 효과입니다.
     *
     * @return array<string, array<string, mixed>> 프로파일 키 → 정의
     */
    public function getBenchmarkProfiles(): array
    {
        return [
            'pages' => [
                'type' => 'list',
                'label' => '페이지 목록',
                'table' => 'pages',
                'columns' => ['*'],
                'order' => [['created_at', 'desc'], ['id', 'desc']],
                // 페이지는 소프트 삭제 컬럼이 없다 — 실제 스키마 기준 선언
                'soft_delete' => false,
                'index_exempt' => '페이지는 사이트 구조를 구성하는 정의성 데이터라 운영자가 손으로 만드는 수만큼만 늘어난다(수십~수백). '
                    .'깊은 OFFSET 이 성립하지 않아 정렬 색인의 이득보다 쓰기 비용이 크다. '
                    .'행 수가 수천을 넘기 시작하면 (created_at, id) 색인을 추가할 것.',
            ],
            'pages_screen' => [
                'type' => 'screen',
                'label' => '관리자 페이지 목록 화면',
                'route' => 'api.modules.sirsoft-page.admin.pages.index',
                'query' => ['per_page' => 20],
                'permissions' => ['sirsoft-page.pages.read'],
            ],
        ];
    }
}
