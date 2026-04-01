<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 코어 권한 정의
    |--------------------------------------------------------------------------
    | RolePermissionSeeder 및 CoreUpdateService::syncCoreRolesAndPermissions()에서 사용
    | 새 권한 추가 시 이 배열에 추가하면 설치/업데이트 모두 반영됩니다.
    |
    | 구조: 모듈(1레벨) → 카테고리(2레벨) → 개별 권한(3레벨)
    */
    'permissions' => [
        // 1레벨: 코어 모듈
        'module' => [
            'identifier' => 'core',
            'name' => ['ko' => '코어', 'en' => 'Core'],
            'description' => ['ko' => '코어 시스템 권한', 'en' => 'Core system permissions'],
            'order' => 1,
        ],

        // 2레벨: 카테고리들 + 3레벨: 개별 권한
        'categories' => [
            [
                'identifier' => 'core.users',
                'name' => ['ko' => '사용자 관리', 'en' => 'User Management'],
                'description' => ['ko' => '사용자 관리 권한', 'en' => 'User management permissions'],
                'category' => 'users',
                'order' => 1,
                'permissions' => [
                    ['identifier' => 'core.users.read', 'name' => ['ko' => '사용자 조회', 'en' => 'View Users'], 'description' => ['ko' => '사용자 목록 및 상세 정보를 조회할 수 있습니다.', 'en' => 'Can view user list and details.'], 'order' => 1, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                    ['identifier' => 'core.users.create', 'name' => ['ko' => '사용자 생성', 'en' => 'Create Users'], 'description' => ['ko' => '새로운 사용자를 생성할 수 있습니다.', 'en' => 'Can create new users.'], 'order' => 2],
                    ['identifier' => 'core.users.update', 'name' => ['ko' => '사용자 수정', 'en' => 'Update Users'], 'description' => ['ko' => '사용자 정보를 수정할 수 있습니다.', 'en' => 'Can update user information.'], 'order' => 3, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                    ['identifier' => 'core.users.delete', 'name' => ['ko' => '사용자 삭제', 'en' => 'Delete Users'], 'description' => ['ko' => '사용자를 삭제할 수 있습니다.', 'en' => 'Can delete users.'], 'order' => 4, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                ],
            ],
            [
                'identifier' => 'core.menus',
                'name' => ['ko' => '메뉴 관리', 'en' => 'Menu Management'],
                'description' => ['ko' => '메뉴 관리 권한', 'en' => 'Menu management permissions'],
                'category' => 'menus',
                'order' => 2,
                'permissions' => [
                    ['identifier' => 'core.menus.read', 'name' => ['ko' => '메뉴 조회', 'en' => 'View Menus'], 'description' => ['ko' => '메뉴 목록을 조회할 수 있습니다.', 'en' => 'Can view menu list.'], 'order' => 1, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.menus.create', 'name' => ['ko' => '메뉴 생성', 'en' => 'Create Menus'], 'description' => ['ko' => '새로운 메뉴를 생성할 수 있습니다.', 'en' => 'Can create new menus.'], 'order' => 2],
                    ['identifier' => 'core.menus.update', 'name' => ['ko' => '메뉴 수정', 'en' => 'Update Menus'], 'description' => ['ko' => '메뉴 정보를 수정할 수 있습니다.', 'en' => 'Can update menu information.'], 'order' => 3, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.menus.delete', 'name' => ['ko' => '메뉴 삭제', 'en' => 'Delete Menus'], 'description' => ['ko' => '메뉴를 삭제할 수 있습니다.', 'en' => 'Can delete menus.'], 'order' => 4, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                ],
            ],
            [
                'identifier' => 'core.modules',
                'name' => ['ko' => '모듈 관리', 'en' => 'Module Management'],
                'description' => ['ko' => '모듈 관리 권한', 'en' => 'Module management permissions'],
                'category' => 'modules',
                'order' => 3,
                'permissions' => [
                    ['identifier' => 'core.modules.read', 'name' => ['ko' => '모듈 조회', 'en' => 'View Modules'], 'description' => ['ko' => '모듈 목록을 조회할 수 있습니다.', 'en' => 'Can view module list.'], 'order' => 1],
                    ['identifier' => 'core.modules.install', 'name' => ['ko' => '모듈 설치', 'en' => 'Install Modules'], 'description' => ['ko' => '새로운 모듈을 설치할 수 있습니다.', 'en' => 'Can install new modules.'], 'order' => 2],
                    ['identifier' => 'core.modules.activate', 'name' => ['ko' => '모듈 활성화', 'en' => 'Activate Modules'], 'description' => ['ko' => '모듈을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate modules.'], 'order' => 3],
                    ['identifier' => 'core.modules.uninstall', 'name' => ['ko' => '모듈 삭제', 'en' => 'Uninstall Modules'], 'description' => ['ko' => '모듈을 삭제할 수 있습니다.', 'en' => 'Can uninstall modules.'], 'order' => 4],
                ],
            ],
            [
                'identifier' => 'core.plugins',
                'name' => ['ko' => '플러그인 관리', 'en' => 'Plugin Management'],
                'description' => ['ko' => '플러그인 관리 권한', 'en' => 'Plugin management permissions'],
                'category' => 'plugins',
                'order' => 4,
                'permissions' => [
                    ['identifier' => 'core.plugins.read', 'name' => ['ko' => '플러그인 조회', 'en' => 'View Plugins'], 'description' => ['ko' => '플러그인 목록을 조회할 수 있습니다.', 'en' => 'Can view plugin list.'], 'order' => 1],
                    ['identifier' => 'core.plugins.install', 'name' => ['ko' => '플러그인 설치', 'en' => 'Install Plugins'], 'description' => ['ko' => '새로운 플러그인을 설치할 수 있습니다.', 'en' => 'Can install new plugins.'], 'order' => 2],
                    ['identifier' => 'core.plugins.activate', 'name' => ['ko' => '플러그인 활성화', 'en' => 'Activate Plugins'], 'description' => ['ko' => '플러그인을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate plugins.'], 'order' => 3],
                    ['identifier' => 'core.plugins.update', 'name' => ['ko' => '플러그인 설정', 'en' => 'Configure Plugins'], 'description' => ['ko' => '플러그인 환경설정을 수정할 수 있습니다.', 'en' => 'Can update plugin settings.'], 'order' => 4],
                    ['identifier' => 'core.plugins.uninstall', 'name' => ['ko' => '플러그인 삭제', 'en' => 'Uninstall Plugins'], 'description' => ['ko' => '플러그인을 삭제할 수 있습니다.', 'en' => 'Can uninstall plugins.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.templates',
                'name' => ['ko' => '템플릿 관리', 'en' => 'Template Management'],
                'description' => ['ko' => '템플릿 관리 권한', 'en' => 'Template management permissions'],
                'category' => 'templates',
                'order' => 5,
                'permissions' => [
                    ['identifier' => 'core.templates.read', 'name' => ['ko' => '템플릿 조회', 'en' => 'View Templates'], 'description' => ['ko' => '템플릿 목록을 조회할 수 있습니다.', 'en' => 'Can view template list.'], 'order' => 1],
                    ['identifier' => 'core.templates.install', 'name' => ['ko' => '템플릿 설치', 'en' => 'Install Templates'], 'description' => ['ko' => '새로운 템플릿을 설치할 수 있습니다.', 'en' => 'Can install new templates.'], 'order' => 2],
                    ['identifier' => 'core.templates.activate', 'name' => ['ko' => '템플릿 활성화', 'en' => 'Activate Templates'], 'description' => ['ko' => '템플릿을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate templates.'], 'order' => 3],
                    ['identifier' => 'core.templates.uninstall', 'name' => ['ko' => '템플릿 삭제', 'en' => 'Uninstall Templates'], 'description' => ['ko' => '템플릿을 삭제할 수 있습니다.', 'en' => 'Can uninstall templates.'], 'order' => 4],
                    ['identifier' => 'core.templates.layouts.edit', 'name' => ['ko' => '레이아웃 편집', 'en' => 'Edit Layouts'], 'description' => ['ko' => '템플릿 레이아웃을 편집할 수 있습니다.', 'en' => 'Can edit template layouts.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.permissions',
                'name' => ['ko' => '권한 관리', 'en' => 'Permission Management'],
                'description' => ['ko' => '역할 및 권한 관리 권한', 'en' => 'Role and permission management permissions'],
                'category' => 'permissions',
                'order' => 6,
                'permissions' => [
                    ['identifier' => 'core.permissions.read', 'name' => ['ko' => '권한 조회', 'en' => 'View Permissions'], 'description' => ['ko' => '역할 및 권한 목록을 조회할 수 있습니다.', 'en' => 'Can view roles and permissions.'], 'order' => 1],
                    ['identifier' => 'core.permissions.create', 'name' => ['ko' => '역할 생성', 'en' => 'Create Roles'], 'description' => ['ko' => '새로운 역할을 생성할 수 있습니다.', 'en' => 'Can create new roles.'], 'order' => 2],
                    ['identifier' => 'core.permissions.update', 'name' => ['ko' => '역할 수정', 'en' => 'Update Roles'], 'description' => ['ko' => '역할 정보와 권한을 수정할 수 있습니다.', 'en' => 'Can update role information and permissions.'], 'order' => 3],
                    ['identifier' => 'core.permissions.delete', 'name' => ['ko' => '역할 삭제', 'en' => 'Delete Roles'], 'description' => ['ko' => '역할을 삭제할 수 있습니다.', 'en' => 'Can delete roles.'], 'order' => 4],
                ],
            ],
            [
                'identifier' => 'core.mail-send-logs',
                'name' => ['ko' => '메일 발송 이력', 'en' => 'Mail Send Logs'],
                'description' => ['ko' => '메일 발송 이력 관리 권한', 'en' => 'Mail send log management permissions'],
                'category' => 'mail-send-logs',
                'order' => 7,
                'permissions' => [
                    ['identifier' => 'core.mail-send-logs.read', 'name' => ['ko' => '발송 이력 조회', 'en' => 'View Mail Send Logs'], 'description' => ['ko' => '메일 발송 이력을 조회할 수 있습니다.', 'en' => 'Can view mail send logs.'], 'order' => 1],
                    ['identifier' => 'core.mail-send-logs.delete', 'name' => ['ko' => '발송 이력 삭제', 'en' => 'Delete Mail Send Logs'], 'description' => ['ko' => '메일 발송 이력을 삭제할 수 있습니다.', 'en' => 'Can delete mail send logs.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.settings',
                'name' => ['ko' => '환경설정', 'en' => 'Settings'],
                'description' => ['ko' => '시스템 환경설정 권한', 'en' => 'System settings permissions'],
                'category' => 'settings',
                'order' => 8,
                'permissions' => [
                    ['identifier' => 'core.settings.read', 'name' => ['ko' => '설정 조회', 'en' => 'View Settings'], 'description' => ['ko' => '시스템 설정을 조회할 수 있습니다.', 'en' => 'Can view system settings.'], 'order' => 1],
                    ['identifier' => 'core.settings.update', 'name' => ['ko' => '설정 수정', 'en' => 'Update Settings'], 'description' => ['ko' => '시스템 설정을 수정할 수 있습니다.', 'en' => 'Can update system settings.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.dashboard',
                'name' => ['ko' => '대시보드', 'en' => 'Dashboard'],
                'description' => ['ko' => '대시보드 접근 권한', 'en' => 'Dashboard access permissions'],
                'category' => 'dashboard',
                'order' => 9,
                'permissions' => [
                    ['identifier' => 'core.dashboard.read', 'name' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'], 'description' => ['ko' => '대시보드 통계 및 정보를 조회할 수 있습니다.', 'en' => 'Can view dashboard statistics and information.'], 'order' => 1],
                    ['identifier' => 'core.dashboard.system-status', 'name' => ['ko' => '시스템 상태', 'en' => 'System Status'], 'description' => ['ko' => '시스템 상태 정보를 조회할 수 있습니다.', 'en' => 'Can view system status information.'], 'order' => 2],
                    ['identifier' => 'core.dashboard.resources', 'name' => ['ko' => '시스템 리소스', 'en' => 'System Resources'], 'description' => ['ko' => 'CPU, 메모리, 디스크 사용량을 조회할 수 있습니다.', 'en' => 'Can view CPU, memory, and disk usage.'], 'order' => 3],
                    ['identifier' => 'core.dashboard.activities', 'name' => ['ko' => '최근 활동', 'en' => 'Recent Activities'], 'description' => ['ko' => '최근 활동 이력을 조회할 수 있습니다.', 'en' => 'Can view recent activity history.'], 'order' => 4, 'resource_route_key' => 'activityLog', 'owner_key' => 'user_id'],
                    ['identifier' => 'core.dashboard.alerts', 'name' => ['ko' => '시스템 알림', 'en' => 'System Alerts'], 'description' => ['ko' => '시스템 알림을 조회할 수 있습니다.', 'en' => 'Can view system alerts.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.activities',
                'name' => ['ko' => '활동 로그', 'en' => 'Activity Logs'],
                'description' => ['ko' => '활동 로그 조회 권한', 'en' => 'Activity log access permissions'],
                'category' => 'activities',
                'order' => 10,
                'permissions' => [
                    ['identifier' => 'core.activities.read', 'name' => ['ko' => '활동 로그 조회', 'en' => 'View Activity Logs'], 'description' => ['ko' => '활동 로그를 조회할 수 있습니다.', 'en' => 'Can view activity logs.'], 'order' => 1, 'resource_route_key' => 'activityLog', 'owner_key' => 'user_id'],
                    ['identifier' => 'core.activities.delete', 'name' => ['ko' => '활동 로그 삭제', 'en' => 'Delete Activity Logs'], 'description' => ['ko' => '활동 로그를 삭제할 수 있습니다.', 'en' => 'Can delete activity logs.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.attachments',
                'name' => ['ko' => '첨부파일 관리', 'en' => 'Attachment Management'],
                'description' => ['ko' => '첨부파일 관리 권한', 'en' => 'Attachment management permissions'],
                'category' => 'attachments',
                'order' => 11,
                'permissions' => [
                    ['identifier' => 'core.attachments.create', 'name' => ['ko' => '첨부파일 업로드', 'en' => 'Upload Attachments'], 'description' => ['ko' => '첨부파일을 업로드할 수 있습니다.', 'en' => 'Can upload attachments.'], 'order' => 1],
                    ['identifier' => 'core.attachments.update', 'name' => ['ko' => '첨부파일 수정', 'en' => 'Update Attachments'], 'description' => ['ko' => '첨부파일 정보 및 순서를 수정할 수 있습니다.', 'en' => 'Can update attachment information and order.'], 'order' => 2, 'resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.attachments.delete', 'name' => ['ko' => '첨부파일 삭제', 'en' => 'Delete Attachments'], 'description' => ['ko' => '첨부파일을 삭제할 수 있습니다.', 'en' => 'Can delete attachments.'], 'order' => 3, 'resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
                ],
            ],
            [
                'identifier' => 'core.schedules',
                'name' => ['ko' => '스케줄 관리', 'en' => 'Schedule Management'],
                'description' => ['ko' => '스케줄 작업 관리 권한', 'en' => 'Schedule task management permissions'],
                'category' => 'schedules',
                'order' => 12,
                'permissions' => [
                    ['identifier' => 'core.schedules.read', 'name' => ['ko' => '스케줄 조회', 'en' => 'View Schedules'], 'description' => ['ko' => '스케줄 목록 및 상세 정보를 조회할 수 있습니다.', 'en' => 'Can view schedule list and details.'], 'order' => 1],
                    ['identifier' => 'core.schedules.create', 'name' => ['ko' => '스케줄 생성', 'en' => 'Create Schedules'], 'description' => ['ko' => '새로운 스케줄을 생성할 수 있습니다.', 'en' => 'Can create new schedules.'], 'order' => 2],
                    ['identifier' => 'core.schedules.update', 'name' => ['ko' => '스케줄 수정', 'en' => 'Update Schedules'], 'description' => ['ko' => '스케줄 정보를 수정할 수 있습니다.', 'en' => 'Can update schedule information.'], 'order' => 3],
                    ['identifier' => 'core.schedules.delete', 'name' => ['ko' => '스케줄 삭제', 'en' => 'Delete Schedules'], 'description' => ['ko' => '스케줄을 삭제할 수 있습니다.', 'en' => 'Can delete schedules.'], 'order' => 4],
                    ['identifier' => 'core.schedules.run', 'name' => ['ko' => '스케줄 실행', 'en' => 'Run Schedules'], 'description' => ['ko' => '스케줄을 수동으로 실행할 수 있습니다.', 'en' => 'Can manually run schedules.'], 'order' => 5],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 역할 정의
    |--------------------------------------------------------------------------
    | RolePermissionSeeder 및 CoreUpdateService::syncCoreRolesAndPermissions()에서 사용
    */
    'roles' => [
        [
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '시스템의 모든 기능에 접근할 수 있는 최고 관리자입니다.', 'en' => 'Super administrator with access to all system features.'],
            'attributes' => ['is_active' => true],
            'permissions' => 'all_leaf', // 모든 리프 권한 할당
        ],
        [
            'identifier' => 'manager',
            'name' => ['ko' => '매니저', 'en' => 'Manager'],
            'description' => ['ko' => '콘텐츠 및 사용자 관리 권한을 가진 관리자입니다.', 'en' => 'Manager with content and user management permissions.'],
            'attributes' => ['is_active' => true],
            'permissions' => [
                'core.users.read', 'core.users.create', 'core.users.update',
                'core.menus.read', 'core.menus.create', 'core.menus.update',
                'core.dashboard.read',
                'core.dashboard.system-status',
                'core.dashboard.activities',
                'core.activities.read',
                'core.attachments.create', 'core.attachments.update', 'core.attachments.delete',
                'core.mail-send-logs.read',
            ],
            // 권한별 스코프 지정 (self: 본인 소유만, role: 같은 역할 범위, 미지정: 전체)
            'permission_scopes' => [
                'core.users.read' => 'self',
                'core.users.update' => 'self',
                'core.activities.read' => 'self',
                'core.attachments.update' => 'self',
                'core.attachments.delete' => 'self',
            ],
        ],
        [
            'identifier' => 'user',
            'name' => ['ko' => '일반 사용자', 'en' => 'User'],
            'description' => ['ko' => '기본 사용자 역할입니다.', 'en' => 'Default user role.'],
            'attributes' => ['is_active' => true],
            'permissions' => [],
        ],
        [
            'identifier' => 'guest',
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
            'description' => [
                'ko' => '인증되지 않은 사용자 역할입니다. 관리자가 권한을 부여할 수 있습니다.',
                'en' => 'Unauthenticated user role. Permissions can be granted by administrators.',
            ],
            'attributes' => ['is_active' => true],
            'permissions' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 메뉴 정의
    |--------------------------------------------------------------------------
    | CoreAdminMenuSeeder 및 CoreUpdateService::syncCoreMenus()에서 사용
    */
    'menus' => [
        [
            'slug' => 'admin-dashboard',
            'name' => ['ko' => '대시보드', 'en' => 'Dashboard'],
            'url' => '/admin/dashboard',
            'icon' => 'fas fa-tachometer-alt',
            'parent_id' => null,
            'order' => 1,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-settings',
            'name' => ['ko' => '환경설정', 'en' => 'Settings'],
            'url' => '/admin/settings',
            'icon' => 'fas fa-cog',
            'parent_id' => null,
            'order' => 2,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-mail-send-logs',
            'name' => ['ko' => '메일 발송 이력', 'en' => 'Mail Send Logs'],
            'url' => '/admin/mail-send-logs',
            'icon' => 'fas fa-paper-plane',
            'parent_id' => null,
            'order' => 3,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-activity-logs',
            'name' => ['ko' => '활동 로그', 'en' => 'Activity Logs'],
            'url' => '/admin/activity-logs',
            'icon' => 'fas fa-history',
            'parent_id' => null,
            'order' => 4,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-menus',
            'name' => ['ko' => '메뉴 관리', 'en' => 'Menu Management'],
            'url' => '/admin/menus',
            'icon' => 'fas fa-bars',
            'parent_id' => null,
            'order' => 5,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-users',
            'name' => ['ko' => '사용자 관리', 'en' => 'User Management'],
            'url' => '/admin/users',
            'icon' => 'fas fa-users',
            'parent_id' => null,
            'order' => 6,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-roles',
            'name' => ['ko' => '권한 관리', 'en' => 'Permission Management'],
            'url' => '/admin/roles',
            'icon' => 'fas fa-lock',
            'parent_id' => null,
            'order' => 7,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-modules',
            'name' => ['ko' => '모듈 관리', 'en' => 'Module Management'],
            'url' => '/admin/modules',
            'icon' => 'fas fa-cube',
            'parent_id' => null,
            'order' => 8,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-plugins',
            'name' => ['ko' => '플러그인 관리', 'en' => 'Plugin Management'],
            'url' => '/admin/plugins',
            'icon' => 'fas fa-puzzle-piece',
            'parent_id' => null,
            'order' => 9,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-templates',
            'name' => ['ko' => '템플릿 관리', 'en' => 'Template Management'],
            'url' => '/admin/templates',
            'icon' => 'fas fa-palette',
            'parent_id' => null,
            'order' => 10,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-schedules',
            'name' => ['ko' => '스케쥴 관리', 'en' => 'Schedule Management'],
            'url' => '/admin/schedules',
            'icon' => 'fas fa-clock',
            'parent_id' => null,
            'order' => 10,
            'is_active' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 메일 템플릿 정의
    |--------------------------------------------------------------------------
    | MailTemplateSeeder 및 CoreUpdateService::syncCoreMailTemplates()에서 사용
    | 새 메일 템플릿 추가 시 이 배열에 추가하면 설치/업데이트 모두 반영됩니다.
    */
    'mail_templates' => [
        [
            'type' => 'welcome',
            'subject' => [
                'ko' => '[{app_name}] 회원가입을 환영합니다',
                'en' => '[{app_name}] Welcome to Our Service',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 환영합니다!</h1>'
                    .'<p>{app_name}에 가입해 주셔서 감사합니다.</p>'
                    .'<p>이제 모든 서비스를 이용하실 수 있습니다. 아래 버튼을 클릭하여 로그인해 주세요.</p>'
                    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">로그인하기</a></td></tr></table>'
                    .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Welcome, {name}!</h1>'
                    .'<p>Thank you for joining {app_name}.</p>'
                    .'<p>You now have access to all our services. Click the button below to log in.</p>'
                    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Log In</a></td></tr></table>'
                    .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
        ],
        [
            'type' => 'reset_password',
            'subject' => [
                'ko' => '[{app_name}] 비밀번호 재설정 안내',
                'en' => '[{app_name}] Password Reset Request',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    .'<p>비밀번호 재설정 요청을 받았습니다. 아래 버튼을 클릭하여 비밀번호를 재설정해 주세요.</p>'
                    .'<p>이 링크는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">비밀번호 재설정</a></td></tr></table>'
                    .'<p>비밀번호 재설정을 요청하지 않으셨다면, 이 이메일을 무시해 주세요.</p>'
                    .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    .'<p>We received a request to reset your password. Click the button below to set a new password.</p>'
                    .'<p>This link will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Reset Password</a></td></tr></table>'
                    .'<p>If you did not request a password reset, please ignore this email.</p>'
                    .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '비밀번호 재설정 URL'],
                ['key' => 'expire_minutes', 'description' => '링크 만료 시간(분)'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
        ],
        [
            'type' => 'password_changed',
            'subject' => [
                'ko' => '[{app_name}] 비밀번호가 변경되었습니다',
                'en' => '[{app_name}] Your Password Has Been Changed',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    .'<p>계정의 비밀번호가 성공적으로 변경되었습니다.</p>'
                    .'<p><strong>본인이 변경하지 않았다면, 즉시 고객 지원팀에 문의하시기 바랍니다.</strong></p>'
                    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">로그인하기</a></td></tr></table>'
                    .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    .'<p>Your account password has been successfully changed.</p>'
                    .'<p><strong>If you did not make this change, please contact our support team immediately.</strong></p>'
                    .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Log In</a></td></tr></table>'
                    .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
        ],
    ],
];
