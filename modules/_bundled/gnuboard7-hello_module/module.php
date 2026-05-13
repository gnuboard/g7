<?php

namespace Modules\Gnuboard7\HelloModule;

use App\Extension\AbstractModule;
use Modules\Gnuboard7\HelloModule\Database\Seeders\MemoSeeder;
use Modules\Gnuboard7\HelloModule\Listeners\LogMemoCreatedListener;

/**
 * Hello 학습용 샘플 모듈
 *
 * Memo 엔티티 1개에 대한 Admin CRUD + User 읽기만 제공하는
 * 최소 동작 샘플입니다. manifest hidden:true 로 관리자 UI 에서 숨겨집니다.
 */
class Module extends AbstractModule
{
    /**
     * 모듈 역할 정의
     *
     * @return array<int, array<string, mixed>>
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
                'ko' => 'Hello 모듈',
                'en' => 'Hello Module',
            ],
            'description' => [
                'ko' => 'Hello 모듈 권한',
                'en' => 'Hello module permissions',
            ],
            'categories' => [
                [
                    'identifier' => 'memos',
                    'resource_route_key' => 'memo',
                    'owner_key' => null,
                    'name' => [
                        'ko' => '메모 관리',
                        'en' => 'Memo Management',
                    ],
                    'description' => [
                        'ko' => '메모 관리 권한',
                        'en' => 'Memo management permissions',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => [
                                'ko' => '메모 조회',
                                'en' => 'View Memos',
                            ],
                            'description' => [
                                'ko' => '메모 목록 및 상세 조회',
                                'en' => 'View memo list and details',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'create',
                            'name' => [
                                'ko' => '메모 생성',
                                'en' => 'Create Memo',
                            ],
                            'description' => [
                                'ko' => '새 메모 생성',
                                'en' => 'Create new memo',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => [
                                'ko' => '메모 수정',
                                'en' => 'Update Memo',
                            ],
                            'description' => [
                                'ko' => '메모 내용 수정',
                                'en' => 'Update memo content',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => [
                                'ko' => '메모 삭제',
                                'en' => 'Delete Memo',
                            ],
                            'description' => [
                                'ko' => '메모 삭제',
                                'en' => 'Delete memo',
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
            MemoSeeder::class,
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
            LogMemoCreatedListener::class,
        ];
    }

    /**
     * 관리자 메뉴 정의
     *
     * 주의: manifest `hidden: true` 이므로 실제 UI 에 노출되지 않을 수 있으나
     * 메뉴 등록 자체는 학습 목적의 샘플로 제공합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => [
                    'ko' => 'Hello 메모',
                    'en' => 'Hello Memos',
                ],
                'slug' => 'gnuboard7-hello_module',
                'url' => '/admin/memos',
                'icon' => 'fas fa-sticky-note',
                'order' => 99,
                'permission' => 'gnuboard7-hello_module.memos.read',
            ],
        ];
    }
}
