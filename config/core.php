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
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.users.read', 'type' => 'admin', 'name' => ['ko' => '사용자 조회', 'en' => 'View Users'], 'description' => ['ko' => '사용자 목록 및 상세 정보를 조회할 수 있습니다.', 'en' => 'Can view user list and details.'], 'order' => 1, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                    ['identifier' => 'core.users.create', 'type' => 'admin', 'name' => ['ko' => '사용자 생성', 'en' => 'Create Users'], 'description' => ['ko' => '새로운 사용자를 생성할 수 있습니다.', 'en' => 'Can create new users.'], 'order' => 2],
                    ['identifier' => 'core.users.update', 'type' => 'admin', 'name' => ['ko' => '사용자 수정', 'en' => 'Update Users'], 'description' => ['ko' => '사용자 정보를 수정할 수 있습니다.', 'en' => 'Can update user information.'], 'order' => 3, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                    ['identifier' => 'core.users.delete', 'type' => 'admin', 'name' => ['ko' => '사용자 삭제', 'en' => 'Delete Users'], 'description' => ['ko' => '사용자를 삭제할 수 있습니다.', 'en' => 'Can delete users.'], 'order' => 4, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                ],
            ],
            [
                'identifier' => 'core.menus',
                'name' => ['ko' => '메뉴 관리', 'en' => 'Menu Management'],
                'description' => ['ko' => '메뉴 관리 권한', 'en' => 'Menu management permissions'],
                'category' => 'menus',
                'order' => 2,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.menus.read', 'type' => 'admin', 'name' => ['ko' => '메뉴 조회', 'en' => 'View Menus'], 'description' => ['ko' => '메뉴 목록을 조회할 수 있습니다.', 'en' => 'Can view menu list.'], 'order' => 1, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.menus.create', 'type' => 'admin', 'name' => ['ko' => '메뉴 생성', 'en' => 'Create Menus'], 'description' => ['ko' => '새로운 메뉴를 생성할 수 있습니다.', 'en' => 'Can create new menus.'], 'order' => 2],
                    ['identifier' => 'core.menus.update', 'type' => 'admin', 'name' => ['ko' => '메뉴 수정', 'en' => 'Update Menus'], 'description' => ['ko' => '메뉴 정보를 수정할 수 있습니다.', 'en' => 'Can update menu information.'], 'order' => 3, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.menus.delete', 'type' => 'admin', 'name' => ['ko' => '메뉴 삭제', 'en' => 'Delete Menus'], 'description' => ['ko' => '메뉴를 삭제할 수 있습니다.', 'en' => 'Can delete menus.'], 'order' => 4, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                ],
            ],
            [
                'identifier' => 'core.modules',
                'name' => ['ko' => '모듈 관리', 'en' => 'Module Management'],
                'description' => ['ko' => '모듈 관리 권한', 'en' => 'Module management permissions'],
                'category' => 'modules',
                'order' => 3,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.modules.read', 'type' => 'admin', 'name' => ['ko' => '모듈 조회', 'en' => 'View Modules'], 'description' => ['ko' => '모듈 목록을 조회할 수 있습니다.', 'en' => 'Can view module list.'], 'order' => 1],
                    ['identifier' => 'core.modules.install', 'type' => 'admin', 'name' => ['ko' => '모듈 설치', 'en' => 'Install Modules'], 'description' => ['ko' => '새로운 모듈을 설치할 수 있습니다.', 'en' => 'Can install new modules.'], 'order' => 2],
                    ['identifier' => 'core.modules.activate', 'type' => 'admin', 'name' => ['ko' => '모듈 활성화', 'en' => 'Activate Modules'], 'description' => ['ko' => '모듈을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate modules.'], 'order' => 3],
                    ['identifier' => 'core.modules.uninstall', 'type' => 'admin', 'name' => ['ko' => '모듈 삭제', 'en' => 'Uninstall Modules'], 'description' => ['ko' => '모듈을 삭제할 수 있습니다.', 'en' => 'Can uninstall modules.'], 'order' => 4],
                ],
            ],
            [
                'identifier' => 'core.plugins',
                'name' => ['ko' => '플러그인 관리', 'en' => 'Plugin Management'],
                'description' => ['ko' => '플러그인 관리 권한', 'en' => 'Plugin management permissions'],
                'category' => 'plugins',
                'order' => 4,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.plugins.read', 'type' => 'admin', 'name' => ['ko' => '플러그인 조회', 'en' => 'View Plugins'], 'description' => ['ko' => '플러그인 목록을 조회할 수 있습니다.', 'en' => 'Can view plugin list.'], 'order' => 1],
                    ['identifier' => 'core.plugins.install', 'type' => 'admin', 'name' => ['ko' => '플러그인 설치', 'en' => 'Install Plugins'], 'description' => ['ko' => '새로운 플러그인을 설치할 수 있습니다.', 'en' => 'Can install new plugins.'], 'order' => 2],
                    ['identifier' => 'core.plugins.activate', 'type' => 'admin', 'name' => ['ko' => '플러그인 활성화', 'en' => 'Activate Plugins'], 'description' => ['ko' => '플러그인을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate plugins.'], 'order' => 3],
                    ['identifier' => 'core.plugins.update', 'type' => 'admin', 'name' => ['ko' => '플러그인 설정', 'en' => 'Configure Plugins'], 'description' => ['ko' => '플러그인 환경설정을 수정할 수 있습니다.', 'en' => 'Can update plugin settings.'], 'order' => 4],
                    ['identifier' => 'core.plugins.uninstall', 'type' => 'admin', 'name' => ['ko' => '플러그인 삭제', 'en' => 'Uninstall Plugins'], 'description' => ['ko' => '플러그인을 삭제할 수 있습니다.', 'en' => 'Can uninstall plugins.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.templates',
                'name' => ['ko' => '템플릿 관리', 'en' => 'Template Management'],
                'description' => ['ko' => '템플릿 관리 권한', 'en' => 'Template management permissions'],
                'category' => 'templates',
                'order' => 5,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.templates.read', 'type' => 'admin', 'name' => ['ko' => '템플릿 조회', 'en' => 'View Templates'], 'description' => ['ko' => '템플릿 목록을 조회할 수 있습니다.', 'en' => 'Can view template list.'], 'order' => 1],
                    ['identifier' => 'core.templates.install', 'type' => 'admin', 'name' => ['ko' => '템플릿 설치', 'en' => 'Install Templates'], 'description' => ['ko' => '새로운 템플릿을 설치할 수 있습니다.', 'en' => 'Can install new templates.'], 'order' => 2],
                    ['identifier' => 'core.templates.activate', 'type' => 'admin', 'name' => ['ko' => '템플릿 활성화', 'en' => 'Activate Templates'], 'description' => ['ko' => '템플릿을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate templates.'], 'order' => 3],
                    ['identifier' => 'core.templates.uninstall', 'type' => 'admin', 'name' => ['ko' => '템플릿 삭제', 'en' => 'Uninstall Templates'], 'description' => ['ko' => '템플릿을 삭제할 수 있습니다.', 'en' => 'Can uninstall templates.'], 'order' => 4],
                    ['identifier' => 'core.templates.layouts.edit', 'type' => 'admin', 'name' => ['ko' => '레이아웃 편집', 'en' => 'Edit Layouts'], 'description' => ['ko' => '템플릿 레이아웃을 편집할 수 있습니다.', 'en' => 'Can edit template layouts.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.permissions',
                'name' => ['ko' => '권한 관리', 'en' => 'Permission Management'],
                'description' => ['ko' => '역할 및 권한 관리 권한', 'en' => 'Role and permission management permissions'],
                'category' => 'permissions',
                'order' => 6,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.permissions.read', 'type' => 'admin', 'name' => ['ko' => '권한 조회', 'en' => 'View Permissions'], 'description' => ['ko' => '역할 및 권한 목록을 조회할 수 있습니다.', 'en' => 'Can view roles and permissions.'], 'order' => 1],
                    ['identifier' => 'core.permissions.create', 'type' => 'admin', 'name' => ['ko' => '역할 생성', 'en' => 'Create Roles'], 'description' => ['ko' => '새로운 역할을 생성할 수 있습니다.', 'en' => 'Can create new roles.'], 'order' => 2],
                    ['identifier' => 'core.permissions.update', 'type' => 'admin', 'name' => ['ko' => '역할 수정', 'en' => 'Update Roles'], 'description' => ['ko' => '역할 정보와 권한을 수정할 수 있습니다.', 'en' => 'Can update role information and permissions.'], 'order' => 3],
                    ['identifier' => 'core.permissions.delete', 'type' => 'admin', 'name' => ['ko' => '역할 삭제', 'en' => 'Delete Roles'], 'description' => ['ko' => '역할을 삭제할 수 있습니다.', 'en' => 'Can delete roles.'], 'order' => 4],
                ],
            ],
            [
                'identifier' => 'core.notification-logs',
                'name' => ['ko' => '알림 발송 이력', 'en' => 'Notification Logs'],
                'description' => ['ko' => '알림 발송 이력 관리 권한', 'en' => 'Notification log management permissions'],
                'category' => 'notification-logs',
                'order' => 7,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.notification-logs.read', 'type' => 'admin', 'name' => ['ko' => '발송 이력 조회', 'en' => 'View Notification Logs'], 'description' => ['ko' => '알림 발송 이력을 조회할 수 있습니다.', 'en' => 'Can view notification logs.'], 'order' => 1],
                    ['identifier' => 'core.notification-logs.delete', 'type' => 'admin', 'name' => ['ko' => '발송 이력 삭제', 'en' => 'Delete Notification Logs'], 'description' => ['ko' => '알림 발송 이력을 삭제할 수 있습니다.', 'en' => 'Can delete notification logs.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.notifications',
                'name' => ['ko' => '알림 (관리자)', 'en' => 'Notifications (Admin)'],
                'description' => ['ko' => '관리자용 알림 관리 권한 (관리자 화면에서 사용)', 'en' => 'Admin notification management permissions (used in admin UI)'],
                'category' => 'notifications',
                'order' => 7.5,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.notifications.read', 'type' => 'admin', 'name' => ['ko' => '알림 조회', 'en' => 'View Notifications'], 'description' => ['ko' => '알림 목록 및 읽지 않은 수를 조회할 수 있습니다.', 'en' => 'Can view notification list and unread count.'], 'order' => 1],
                    ['identifier' => 'core.notifications.update', 'type' => 'admin', 'name' => ['ko' => '알림 읽음 처리', 'en' => 'Mark Notifications Read'], 'description' => ['ko' => '알림을 읽음 처리할 수 있습니다.', 'en' => 'Can mark notifications as read.'], 'order' => 2],
                    ['identifier' => 'core.notifications.delete', 'type' => 'admin', 'name' => ['ko' => '알림 삭제', 'en' => 'Delete Notifications'], 'description' => ['ko' => '알림을 삭제할 수 있습니다.', 'en' => 'Can delete notifications.'], 'order' => 3],
                ],
            ],
            [
                'identifier' => 'core.user-notifications',
                'name' => ['ko' => '알림 (사용자)', 'en' => 'Notifications (User)'],
                'description' => ['ko' => '사용자용 알림 권한 (사용자 화면에서 본인 알림 관리)', 'en' => 'User notification permissions (managing own notifications in user UI)'],
                'category' => 'user-notifications',
                'order' => 7.6,
                'type' => 'user',
                'permissions' => [
                    ['identifier' => 'core.user-notifications.read', 'type' => 'user', 'name' => ['ko' => '알림 조회', 'en' => 'View Notifications'], 'description' => ['ko' => '본인의 알림 목록 및 읽지 않은 수를 조회할 수 있습니다.', 'en' => 'Can view own notification list and unread count.'], 'order' => 1],
                    ['identifier' => 'core.user-notifications.update', 'type' => 'user', 'name' => ['ko' => '알림 읽음 처리', 'en' => 'Mark Notifications Read'], 'description' => ['ko' => '본인의 알림을 읽음 처리할 수 있습니다.', 'en' => 'Can mark own notifications as read.'], 'order' => 2],
                    ['identifier' => 'core.user-notifications.delete', 'type' => 'user', 'name' => ['ko' => '알림 삭제', 'en' => 'Delete Notifications'], 'description' => ['ko' => '본인의 알림을 삭제할 수 있습니다.', 'en' => 'Can delete own notifications.'], 'order' => 3],
                ],
            ],
            [
                'identifier' => 'core.identity',
                'name' => ['ko' => '본인인증 (사용자)', 'en' => 'Identity Verification (User)'],
                'description' => ['ko' => '로그인 사용자의 본인인증 challenge 요청/검증/취소 권한', 'en' => 'Permissions for authenticated users to request/verify/cancel IDV challenges'],
                'category' => 'identity',
                'order' => 7.7,
                'type' => 'user',
                'permissions' => [
                    ['identifier' => 'core.identity.request', 'type' => 'user', 'name' => ['ko' => 'IDV 요청', 'en' => 'Request IDV Challenge'], 'description' => ['ko' => '본인인증 challenge 를 요청할 수 있습니다.', 'en' => 'Can request identity verification challenges.'], 'order' => 1],
                    ['identifier' => 'core.identity.verify', 'type' => 'user', 'name' => ['ko' => 'IDV 검증', 'en' => 'Verify IDV Challenge'], 'description' => ['ko' => '본인의 본인인증 challenge 를 검증할 수 있습니다.', 'en' => 'Can verify own identity verification challenges.'], 'order' => 2, 'resource_route_key' => 'challenge', 'owner_key' => 'user_id'],
                    ['identifier' => 'core.identity.cancel', 'type' => 'user', 'name' => ['ko' => 'IDV 취소', 'en' => 'Cancel IDV Challenge'], 'description' => ['ko' => '본인의 본인인증 challenge 를 취소할 수 있습니다.', 'en' => 'Can cancel own identity verification challenges.'], 'order' => 3, 'resource_route_key' => 'challenge', 'owner_key' => 'user_id'],
                ],
            ],
            [
                'identifier' => 'core.admin.identity',
                'name' => ['ko' => '본인인증 관리 (관리자)', 'en' => 'Identity Verification Management (Admin)'],
                'description' => ['ko' => '관리자용 IDV 프로바이더/정책/로그 관리 권한', 'en' => 'Admin permissions for IDV providers, policies, and logs'],
                'category' => 'identity',
                'order' => 7.8,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.admin.identity.manage', 'type' => 'admin', 'name' => ['ko' => '프로바이더 설정', 'en' => 'Manage IDV Providers'], 'description' => ['ko' => '본인인증 프로바이더 설정을 관리할 수 있습니다.', 'en' => 'Can manage identity verification providers.'], 'order' => 1],
                    ['identifier' => 'core.admin.identity.policies.manage', 'type' => 'admin', 'name' => ['ko' => '정책 관리', 'en' => 'Manage IDV Policies'], 'description' => ['ko' => '본인인증 정책(라우트/훅별)을 CRUD 할 수 있습니다.', 'en' => 'Can CRUD identity verification policies by route/hook.'], 'order' => 2],
                    ['identifier' => 'core.admin.identity.logs.read', 'type' => 'admin', 'name' => ['ko' => '로그 열람', 'en' => 'View IDV Logs'], 'description' => ['ko' => '본인인증 이력을 조회할 수 있습니다.', 'en' => 'Can view identity verification logs.'], 'order' => 3],
                    ['identifier' => 'core.admin.identity.logs.purge', 'type' => 'admin', 'name' => ['ko' => '로그 파기', 'en' => 'Purge IDV Logs'], 'description' => ['ko' => '본인인증 이력을 파기(보관주기 외)할 수 있습니다.', 'en' => 'Can purge identity verification logs (retention-based).'], 'order' => 4],
                    ['identifier' => 'core.admin.identity.messages.read', 'type' => 'admin', 'name' => ['ko' => '메시지 템플릿 조회', 'en' => 'View IDV Message Templates'], 'description' => ['ko' => '본인인증 메시지 정의/템플릿을 조회할 수 있습니다.', 'en' => 'Can view identity verification message definitions and templates.'], 'order' => 5],
                    ['identifier' => 'core.admin.identity.messages.update', 'type' => 'admin', 'name' => ['ko' => '메시지 템플릿 수정', 'en' => 'Update IDV Message Templates'], 'description' => ['ko' => '본인인증 메시지 정의/템플릿을 수정할 수 있습니다.', 'en' => 'Can update identity verification message definitions and templates.'], 'order' => 6],
                ],
            ],
            [
                'identifier' => 'core.settings',
                'name' => ['ko' => '환경설정', 'en' => 'Settings'],
                'description' => ['ko' => '시스템 환경설정 권한', 'en' => 'System settings permissions'],
                'category' => 'settings',
                'order' => 8,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.settings.read', 'type' => 'admin', 'name' => ['ko' => '설정 조회', 'en' => 'View Settings'], 'description' => ['ko' => '시스템 설정을 조회할 수 있습니다.', 'en' => 'Can view system settings.'], 'order' => 1],
                    ['identifier' => 'core.settings.update', 'type' => 'admin', 'name' => ['ko' => '설정 수정', 'en' => 'Update Settings'], 'description' => ['ko' => '시스템 설정을 수정할 수 있습니다.', 'en' => 'Can update system settings.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.dashboard',
                'name' => ['ko' => '대시보드', 'en' => 'Dashboard'],
                'description' => ['ko' => '대시보드 접근 권한', 'en' => 'Dashboard access permissions'],
                'category' => 'dashboard',
                'order' => 9,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.dashboard.read', 'type' => 'admin', 'name' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'], 'description' => ['ko' => '대시보드 통계 및 정보를 조회할 수 있습니다.', 'en' => 'Can view dashboard statistics and information.'], 'order' => 1],
                    ['identifier' => 'core.dashboard.system-status', 'type' => 'admin', 'name' => ['ko' => '시스템 상태', 'en' => 'System Status'], 'description' => ['ko' => '시스템 상태 정보를 조회할 수 있습니다.', 'en' => 'Can view system status information.'], 'order' => 2],
                    ['identifier' => 'core.dashboard.resources', 'type' => 'admin', 'name' => ['ko' => '시스템 리소스', 'en' => 'System Resources'], 'description' => ['ko' => 'CPU, 메모리, 디스크 사용량을 조회할 수 있습니다.', 'en' => 'Can view CPU, memory, and disk usage.'], 'order' => 3],
                    ['identifier' => 'core.dashboard.activities', 'type' => 'admin', 'name' => ['ko' => '최근 활동', 'en' => 'Recent Activities'], 'description' => ['ko' => '최근 활동 이력을 조회할 수 있습니다.', 'en' => 'Can view recent activity history.'], 'order' => 4, 'resource_route_key' => 'activityLog', 'owner_key' => 'user_id'],
                    ['identifier' => 'core.dashboard.alerts', 'type' => 'admin', 'name' => ['ko' => '시스템 알림', 'en' => 'System Alerts'], 'description' => ['ko' => '시스템 알림을 조회할 수 있습니다.', 'en' => 'Can view system alerts.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.activities',
                'name' => ['ko' => '활동 로그', 'en' => 'Activity Logs'],
                'description' => ['ko' => '활동 로그 조회 권한', 'en' => 'Activity log access permissions'],
                'category' => 'activities',
                'order' => 10,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.activities.read', 'type' => 'admin', 'name' => ['ko' => '활동 로그 조회', 'en' => 'View Activity Logs'], 'description' => ['ko' => '활동 로그를 조회할 수 있습니다.', 'en' => 'Can view activity logs.'], 'order' => 1, 'resource_route_key' => 'activityLog', 'owner_key' => 'user_id'],
                    ['identifier' => 'core.activities.delete', 'type' => 'admin', 'name' => ['ko' => '활동 로그 삭제', 'en' => 'Delete Activity Logs'], 'description' => ['ko' => '활동 로그를 삭제할 수 있습니다.', 'en' => 'Can delete activity logs.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.attachments',
                'name' => ['ko' => '첨부파일 관리', 'en' => 'Attachment Management'],
                'description' => ['ko' => '첨부파일 관리 권한', 'en' => 'Attachment management permissions'],
                'category' => 'attachments',
                'order' => 11,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.attachments.create', 'type' => 'admin', 'name' => ['ko' => '첨부파일 업로드', 'en' => 'Upload Attachments'], 'description' => ['ko' => '첨부파일을 업로드할 수 있습니다.', 'en' => 'Can upload attachments.'], 'order' => 1],
                    ['identifier' => 'core.attachments.update', 'type' => 'admin', 'name' => ['ko' => '첨부파일 수정', 'en' => 'Update Attachments'], 'description' => ['ko' => '첨부파일 정보 및 순서를 수정할 수 있습니다.', 'en' => 'Can update attachment information and order.'], 'order' => 2, 'resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.attachments.delete', 'type' => 'admin', 'name' => ['ko' => '첨부파일 삭제', 'en' => 'Delete Attachments'], 'description' => ['ko' => '첨부파일을 삭제할 수 있습니다.', 'en' => 'Can delete attachments.'], 'order' => 3, 'resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
                ],
            ],
            [
                'identifier' => 'core.schedules',
                'name' => ['ko' => '스케줄 관리', 'en' => 'Schedule Management'],
                'description' => ['ko' => '스케줄 작업 관리 권한', 'en' => 'Schedule task management permissions'],
                'category' => 'schedules',
                'order' => 12,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.schedules.read', 'type' => 'admin', 'name' => ['ko' => '스케줄 조회', 'en' => 'View Schedules'], 'description' => ['ko' => '스케줄 목록 및 상세 정보를 조회할 수 있습니다.', 'en' => 'Can view schedule list and details.'], 'order' => 1],
                    ['identifier' => 'core.schedules.create', 'type' => 'admin', 'name' => ['ko' => '스케줄 생성', 'en' => 'Create Schedules'], 'description' => ['ko' => '새로운 스케줄을 생성할 수 있습니다.', 'en' => 'Can create new schedules.'], 'order' => 2],
                    ['identifier' => 'core.schedules.update', 'type' => 'admin', 'name' => ['ko' => '스케줄 수정', 'en' => 'Update Schedules'], 'description' => ['ko' => '스케줄 정보를 수정할 수 있습니다.', 'en' => 'Can update schedule information.'], 'order' => 3],
                    ['identifier' => 'core.schedules.delete', 'type' => 'admin', 'name' => ['ko' => '스케줄 삭제', 'en' => 'Delete Schedules'], 'description' => ['ko' => '스케줄을 삭제할 수 있습니다.', 'en' => 'Can delete schedules.'], 'order' => 4],
                    ['identifier' => 'core.schedules.run', 'type' => 'admin', 'name' => ['ko' => '스케줄 실행', 'en' => 'Run Schedules'], 'description' => ['ko' => '스케줄을 수동으로 실행할 수 있습니다.', 'en' => 'Can manually run schedules.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.language_packs',
                'name' => ['ko' => '언어팩 관리', 'en' => 'Language Pack Management'],
                'description' => ['ko' => '언어팩 설치/제거/활성화 권한', 'en' => 'Language pack install/uninstall/activation permissions'],
                'category' => 'language_packs',
                'order' => 13,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.language_packs.read', 'type' => 'admin', 'name' => ['ko' => '언어팩 조회', 'en' => 'View Language Packs'], 'description' => ['ko' => '설치된 언어팩 목록 및 상세 정보를 조회할 수 있습니다.', 'en' => 'Can view installed language pack list and details.'], 'order' => 1],
                    ['identifier' => 'core.language_packs.install', 'type' => 'admin', 'name' => ['ko' => '언어팩 설치', 'en' => 'Install Language Packs'], 'description' => ['ko' => 'ZIP/GitHub/URL 로 언어팩을 설치할 수 있습니다.', 'en' => 'Can install language packs from ZIP/GitHub/URL.'], 'order' => 2],
                    ['identifier' => 'core.language_packs.manage', 'type' => 'admin', 'name' => ['ko' => '언어팩 관리', 'en' => 'Manage Language Packs'], 'description' => ['ko' => '언어팩을 활성화/비활성화/제거할 수 있습니다.', 'en' => 'Can activate/deactivate/uninstall language packs.'], 'order' => 3],
                    ['identifier' => 'core.language_packs.update', 'type' => 'admin', 'name' => ['ko' => '언어팩 업데이트', 'en' => 'Update Language Packs'], 'description' => ['ko' => '설치된 언어팩의 업데이트를 확인하고 실행할 수 있습니다.', 'en' => 'Can check and apply updates for installed language packs.'], 'order' => 4],
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
                'core.notification-logs.read',
            ],
            // 권한별 스코프 지정 (self: 본인 소유만, role: 같은 역할 범위, 미지정: 전체)
            'permission_scopes' => [
                'core.users.read' => 'self',
                'core.users.update' => 'self',
                'core.activities.read' => 'self',
                'core.attachments.update' => 'self',
                'core.attachments.delete' => 'self',
                'core.notification-logs.read' => 'self',
            ],
        ],
        [
            'identifier' => 'user',
            'name' => ['ko' => '일반 사용자', 'en' => 'User'],
            'description' => ['ko' => '기본 사용자 역할입니다.', 'en' => 'Default user role.'],
            'attributes' => ['is_active' => true],
            'permissions' => [
                'core.user-notifications.read', 'core.user-notifications.update', 'core.user-notifications.delete',
                'core.identity.request', 'core.identity.verify', 'core.identity.cancel',
            ],
            // verify/cancel 은 본인 challenge(user_id 일치) 만 — PermissionMiddleware 의 scope=self 가드가 자동 검증
            'permission_scopes' => [
                'core.identity.verify' => 'self',
                'core.identity.cancel' => 'self',
            ],
        ],
        [
            'identifier' => 'guest',
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
            'description' => [
                'ko' => '인증되지 않은 사용자 역할입니다. 관리자가 권한을 부여할 수 있습니다.',
                'en' => 'Unauthenticated user role. Permissions can be granted by administrators.',
            ],
            'attributes' => ['is_active' => true],
            // 비로그인 가입(Mode B) · 비밀번호 재설정 등 IDV 진입에 필요 — PermissionMiddleware 가 비인증 시 scope 검사를 자동 스킵하므로 owner 가드는 throttle + UUID 추정 어려움에 의존
            'permissions' => [
                'core.identity.request', 'core.identity.verify', 'core.identity.cancel',
            ],
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
            'slug' => 'admin-notification-logs',
            'name' => ['ko' => '알림 발송 이력', 'en' => 'Notification Logs'],
            'url' => '/admin/notification-logs',
            'icon' => 'fas fa-bell',
            'parent_id' => null,
            'order' => 3,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-identity-logs',
            'name' => ['ko' => '본인인증 이력', 'en' => 'Identity Logs'],
            'url' => '/admin/identity/logs',
            'icon' => 'fas fa-clipboard-check',
            'parent_id' => null,
            'order' => 4,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-activity-logs',
            'name' => ['ko' => '활동 로그', 'en' => 'Activity Logs'],
            'url' => '/admin/activity-logs',
            'icon' => 'fas fa-history',
            'parent_id' => null,
            'order' => 5,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-menus',
            'name' => ['ko' => '메뉴 관리', 'en' => 'Menu Management'],
            'url' => '/admin/menus',
            'icon' => 'fas fa-bars',
            'parent_id' => null,
            'order' => 6,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-users',
            'name' => ['ko' => '사용자 관리', 'en' => 'User Management'],
            'url' => '/admin/users',
            'icon' => 'fas fa-users',
            'parent_id' => null,
            'order' => 7,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-roles',
            'name' => ['ko' => '권한 관리', 'en' => 'Permission Management'],
            'url' => '/admin/roles',
            'icon' => 'fas fa-lock',
            'parent_id' => null,
            'order' => 8,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-modules',
            'name' => ['ko' => '모듈 관리', 'en' => 'Module Management'],
            'url' => '/admin/modules',
            'icon' => 'fas fa-cube',
            'parent_id' => null,
            'order' => 9,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-plugins',
            'name' => ['ko' => '플러그인 관리', 'en' => 'Plugin Management'],
            'url' => '/admin/plugins',
            'icon' => 'fas fa-puzzle-piece',
            'parent_id' => null,
            'order' => 10,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-templates',
            'name' => ['ko' => '템플릿 관리', 'en' => 'Template Management'],
            'url' => '/admin/templates',
            'icon' => 'fas fa-palette',
            'parent_id' => null,
            'order' => 11,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-schedules',
            'name' => ['ko' => '스케쥴 관리', 'en' => 'Schedule Management'],
            'url' => '/admin/schedules',
            'icon' => 'fas fa-clock',
            'parent_id' => null,
            'order' => 12,
            'is_active' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 알림 정의
    |--------------------------------------------------------------------------
    | NotificationDefinitionSeeder 와 NotificationTemplateService 가 SSoT 로 사용합니다.
    | NotificationSyncHelper 가 upsert 하며, 운영자가 관리자 UI 에서 수정한 필드는
    | user_overrides JSON 에 보존되어 재시딩 시 덮어써지지 않습니다.
    | 모듈/플러그인 자체 알림은 각자의 AbstractModule/AbstractPlugin::getNotificationDefinitions()
    | 에서 선언하며 ModuleManager/PluginManager 가 자동 동기화합니다.
    */
    'notification_definitions' => [
        'welcome' => [
            'hook_prefix' => 'core.auth',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
            'description' => ['ko' => '회원가입 완료 시 발송되는 환영 알림', 'en' => 'Welcome notification sent upon registration'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_register'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
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
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '회원가입을 환영합니다', 'en' => 'Welcome to our service'],
                    'body' => ['ko' => '{name}님, {app_name}에 가입해 주셔서 감사합니다.', 'en' => 'Welcome {name}, thank you for joining {app_name}.'],
                    'click_url' => '/mypage',
                ],
            ],
        ],
        'reset_password' => [
            'hook_prefix' => 'core.auth',
            'name' => ['ko' => '비밀번호 재설정', 'en' => 'Password Reset'],
            'description' => ['ko' => '비밀번호 재설정 요청 시 발송되는 알림', 'en' => 'Notification sent when password reset is requested'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_reset_password_request'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '비밀번호 재설정 URL'],
                ['key' => 'expire_minutes', 'description' => '링크 만료 시간(분)'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
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
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '비밀번호 재설정 안내', 'en' => 'Password Reset Request'],
                    'body' => ['ko' => '{name}님, 비밀번호 재설정이 요청되었습니다. 본인이 요청하지 않았다면 이 알림을 무시해 주세요.', 'en' => '{name}, a password reset has been requested. If you did not request this, please ignore this notification.'],
                    'click_url' => '{action_url}',
                ],
            ],
        ],
        'password_changed' => [
            'hook_prefix' => 'core.auth',
            'name' => ['ko' => '비밀번호 변경', 'en' => 'Password Changed'],
            'description' => ['ko' => '비밀번호 변경 완료 시 발송되는 알림', 'en' => 'Notification sent when password is changed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_password_changed'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
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
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '비밀번호가 변경되었습니다', 'en' => 'Your password has been changed'],
                    'body' => ['ko' => '{name}님, 비밀번호가 변경되었습니다. 본인이 변경하지 않았다면 즉시 고객 지원팀에 문의하시기 바랍니다.', 'en' => '{name}, your password has been changed. If you did not make this change, please contact support immediately.'],
                    'click_url' => '/mypage/change-password',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 본인인증(IDV) 정책
    |--------------------------------------------------------------------------
    | IdentityPolicySeeder 에서 사용됩니다. IdentityPolicySyncHelper 가 upsert 하며,
    | 운영자가 S1d UI 에서 수정한 필드는 user_overrides JSON 에 기록되어 재시딩 시 보존됩니다.
    */
    'identity_policies' => [
        // 회원가입 — Mode B (route 단계, verification_token 검증 필수)
        'core.auth.signup_before_submit' => [
            'scope' => 'route',
            'target' => 'api.auth.register',
            'purpose' => 'signup',
            'provider_id' => null,
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'priority' => 110,
            'conditions' => ['signup_stage' => 'before_submit', 'http_method' => ['POST']],
        ],

        // 회원가입 — Mode C (hook 단계, after_register 시 challenge 발행 + PendingVerification)
        'core.auth.signup_after_create' => [
            'scope' => 'hook',
            'target' => 'core.auth.after_register',
            'purpose' => 'signup',
            'provider_id' => null,
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'priority' => 100,
            'conditions' => ['signup_stage' => 'after_create'],
        ],

        // 비밀번호 재설정 — 기존 forgotPassword 흐름이 IDV 인프라 경유 (안전 기본 OFF)
        'core.auth.password_reset' => [
            'scope' => 'hook',
            'target' => 'core.auth.before_reset_password',
            'purpose' => 'password_reset',
            'provider_id' => null,
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'priority' => 100,
        ],

        // 비밀번호 변경 (로그인 상태 자기 변경)
        'core.profile.password_change' => [
            'scope' => 'route',
            'target' => 'api.me.password',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 5,
            'enabled' => true,
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ],

        // 민감 정보 변경 (이메일/전화 변경)
        'core.profile.contact_change' => [
            'scope' => 'hook',
            'target' => 'core.user.before_update',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 5,
            'enabled' => true,
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'conditions' => ['changed_fields' => ['email', 'phone', 'mobile']],
        ],

        // 계정 탈퇴
        'core.account.withdraw' => [
            'scope' => 'route',
            'target' => 'api.me.destroy',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ],

        // 관리자: App Key 재생성
        'core.admin.app_key_regenerate' => [
            'scope' => 'route',
            'target' => 'api.admin.settings.regenerate-app-key',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'admin',
            'fail_mode' => 'block',
        ],

        // 관리자: 사용자 삭제
        'core.admin.user_delete' => [
            'scope' => 'hook',
            'target' => 'core.user.before_delete',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'admin',
            'fail_mode' => 'block',
        ],

        // 관리자: 모듈/플러그인 제거
        'core.admin.extension_uninstall' => [
            'scope' => 'route',
            'target' => 'api.admin.{modules,plugins}.uninstall',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'admin',
            'fail_mode' => 'block',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 본인인증(IDV) 메시지 정의/템플릿
    |--------------------------------------------------------------------------
    | IdentityMessageDefinitionSeeder 에서 사용됩니다. IdentityMessageSyncHelper 가
    | upsert 하며, 운영자가 관리자 UI 에서 수정한 필드는 user_overrides JSON 에 보존됩니다.
    | 모듈/플러그인 자체 IDV 메시지는 각자의 getIdentityMessages() 에서 선언합니다.
    |
    | 각 항목 키는 사람이 읽기 쉬운 식별자이며, 실제 unique 키는 (provider_id, scope_type, scope_value) 조합입니다.
    | scope_type: 'provider_default' | 'purpose' | 'policy'
    |
    | 코어 변수(commonVariables) 는 모든 mail 정의 공통:
    |   code, action_url, expire_minutes, purpose_label, app_name, site_url, recipient_email
    */
    'identity_messages' => [
        'mail.provider_default' => [
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'provider_default',
            'scope_value' => '',
            'name' => ['ko' => '메일 본인 확인 (기본)', 'en' => 'Mail Verification (default)'],
            'description' => [
                'ko' => '특정 목적이 매칭되지 않을 때 사용되는 기본 메일 템플릿',
                'en' => 'Fallback mail template used when no specific purpose matches',
            ],
            'channels' => ['mail'],
            'variables' => '__common__',
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 본인 확인 인증 코드',
                        'en' => '[{app_name}] Verification Code',
                    ],
                    'body' => [
                        'ko' => '<p>안녕하세요.</p>'
                            .'<p>아래 인증 코드를 입력해 본인 확인을 완료해 주세요.</p>'
                            .'<p style="font-size:24px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p>본인이 요청하지 않았다면 이 메일을 무시해 주세요.</p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<p>Hello.</p>'
                            .'<p>Please enter the verification code below to confirm your identity.</p>'
                            .'<p style="font-size:24px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p>If you did not request this, please ignore this email.</p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],

        'mail.purpose.signup' => [
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'purpose',
            'scope_value' => 'signup',
            'name' => ['ko' => '회원가입 인증', 'en' => 'Signup Verification'],
            'description' => [
                'ko' => '회원가입 단계에서 이메일 본인 확인 시 발송',
                'en' => 'Sent when verifying email during signup',
            ],
            'channels' => ['mail'],
            'variables' => '__common__',
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 회원가입 인증 코드',
                        'en' => '[{app_name}] Signup Verification Code',
                    ],
                    'body' => [
                        'ko' => '<h1>{app_name} 회원가입 인증</h1>'
                            .'<p>아래 인증 코드를 회원가입 화면에 입력해 주세요.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p>본인이 가입을 시도하지 않았다면 이 메일을 무시해 주세요.</p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>{app_name} Signup Verification</h1>'
                            .'<p>Please enter the verification code below on the signup screen.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p>If you did not request this, please ignore this email.</p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],

        'mail.purpose.password_reset' => [
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'purpose',
            'scope_value' => 'password_reset',
            'name' => ['ko' => '비밀번호 재설정 링크', 'en' => 'Password Reset Link'],
            'description' => [
                'ko' => '비밀번호 재설정 요청 시 발송되는 서명 링크 메일 (link 흐름)',
                'en' => 'Signed-link email sent on password reset request (link flow)',
            ],
            'channels' => ['mail'],
            'variables' => '__common__',
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 비밀번호 재설정 안내',
                        'en' => '[{app_name}] Password Reset Request',
                    ],
                    'body' => [
                        'ko' => '<h1>비밀번호 재설정</h1>'
                            .'<p>비밀번호 재설정 요청을 받았습니다. 아래 버튼을 클릭하여 비밀번호를 재설정해 주세요.</p>'
                            .'<p>이 링크는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td align="center"><a href="{action_url}" style="display:inline-block; padding:12px 32px; background-color:#2d3748; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:600; font-size:14px;">비밀번호 재설정</a></td></tr></table>'
                            .'<p>본인이 요청하지 않았다면 이 메일을 무시해 주세요.</p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Password Reset</h1>'
                            .'<p>We received a password reset request. Click the button below to set a new password.</p>'
                            .'<p>This link will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td align="center"><a href="{action_url}" style="display:inline-block; padding:12px 32px; background-color:#2d3748; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:600; font-size:14px;">Reset Password</a></td></tr></table>'
                            .'<p>If you did not request this, please ignore this email.</p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],

        'mail.purpose.self_update' => [
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'purpose',
            'scope_value' => 'self_update',
            'name' => ['ko' => '계정 정보 변경 인증', 'en' => 'Self-update Verification'],
            'description' => [
                'ko' => '계정 정보 변경 시 본인 확인 코드 메일',
                'en' => 'Verification code mail when updating account info',
            ],
            'channels' => ['mail'],
            'variables' => '__common__',
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 계정 정보 변경 인증 코드',
                        'en' => '[{app_name}] Account Update Verification Code',
                    ],
                    'body' => [
                        'ko' => '<h1>계정 정보 변경 인증</h1>'
                            .'<p>{purpose_label} 처리를 위해 아래 인증 코드를 입력해 주세요.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p><strong>본인이 변경을 시도하지 않았다면 즉시 비밀번호를 변경하시기 바랍니다.</strong></p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Account Update Verification</h1>'
                            .'<p>Please enter the code below to proceed with {purpose_label}.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p><strong>If you did not initiate this change, please change your password immediately.</strong></p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],

        'mail.purpose.sensitive_action' => [
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'purpose',
            'scope_value' => 'sensitive_action',
            'name' => ['ko' => '중요 작업 인증', 'en' => 'Sensitive Action Verification'],
            'description' => [
                'ko' => '결제, 주요 설정 변경 등 중요 작업 시 본인 확인 코드 메일',
                'en' => 'Verification code mail for sensitive actions (payment, critical settings)',
            ],
            'channels' => ['mail'],
            'variables' => '__common__',
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 중요 작업 인증 코드',
                        'en' => '[{app_name}] Sensitive Action Verification Code',
                    ],
                    'body' => [
                        'ko' => '<h1>중요 작업 인증</h1>'
                            .'<p>{purpose_label} 진행을 위해 아래 인증 코드를 입력해 주세요.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p><strong>본인이 시도하지 않았다면 즉시 고객 지원팀에 문의해 주세요.</strong></p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Sensitive Action Verification</h1>'
                            .'<p>Please enter the code below to proceed with {purpose_label}.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p><strong>If you did not initiate this action, please contact support immediately.</strong></p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 본인인증(IDV) 목적 (purpose) 메타데이터
    |--------------------------------------------------------------------------
    | App\Enums\IdentityVerificationPurpose enum 이 코드 계약(타입 안전) 을 담당하고,
    | 본 블록은 라벨/설명/기본 provider/허용 채널 등 운영 메타데이터의 SSoT 입니다.
    | IdentityVerificationManager::registerCorePurposes() 가 부팅 시 본 블록을 로드하며,
    | 모듈/플러그인은 각자의 getIdentityPurposes() 에서 추가 purpose 를 선언합니다.
    | 라벨은 lang/{locale}/identity.php::purposes.{key}.label 가 우선 (다국어 정합).
    */
    'identity_purposes' => [
        'signup' => [
            // 'label' / 'description' 의 값은 i18n 키 문자열. resolvePurposeText 가 __() 로 풀이.
            // (`label_key` / `description_key` 명명은 controller 쪽이 'label'/'description' 만
            // 인식해 동작하지 않으므로 표준 명명으로 정렬)
            'label' => 'identity.purposes.signup.label',
            'description' => 'identity.purposes.signup.description',
            'default_provider' => 'g7:core.mail',
            'allowed_channels' => ['mail', 'sms'],
        ],
        'password_reset' => [
            'label' => 'identity.purposes.password_reset.label',
            'description' => 'identity.purposes.password_reset.description',
            'default_provider' => 'g7:core.mail',
            'allowed_channels' => ['mail', 'sms'],
        ],
        'self_update' => [
            'label' => 'identity.purposes.self_update.label',
            'description' => 'identity.purposes.self_update.description',
            'default_provider' => 'g7:core.mail',
            'allowed_channels' => ['mail', 'sms'],
        ],
        'sensitive_action' => [
            'label' => 'identity.purposes.sensitive_action.label',
            'description' => 'identity.purposes.sensitive_action.description',
            'default_provider' => 'g7:core.mail',
            'allowed_channels' => ['mail', 'sms'],
        ],
    ],

];
