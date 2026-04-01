<?php

namespace Database\Seeders\Sample;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\MailTemplate;
use App\Models\Menu;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 활동 로그 샘플 시더
 *
 * 관리자 패널의 활동 로그 화면을 테스트하기 위한 데모 데이터를 생성합니다.
 * 모든 데이터는 실제 DB 레코드를 참조하며, 가상 레코드를 삽입하지 않습니다.
 *
 * 리소스 중심 접근: 각 리소스마다 rand(1,50)개의 랜덤 로그를 생성합니다.
 *
 * 수동 실행: php artisan db:seed --class="Database\Seeders\Sample\ActivityLogSampleSeeder"
 */
class ActivityLogSampleSeeder extends Seeder
{
    /**
     * 시더 실행
     */
    public function run(): void
    {
        $admins = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->get();
        if ($admins->isEmpty()) {
            $this->command->warn('관리자 사용자가 없어 시더를 건너뜁니다.');

            return;
        }

        $this->command->info('활동 로그 샘플 시딩 시작...');

        // 기존 코어 활동 로그 삭제 (모듈 로그 제외)
        $deleted = ActivityLog::where(function ($q) {
            $q->where('description_key', 'not like', 'sirsoft-ecommerce::%')
                ->where('description_key', 'not like', 'sirsoft-board::%')
                ->where('description_key', 'not like', 'sirsoft-page::%');
        })->delete();
        if ($deleted > 0) {
            $this->command->info("기존 코어 활동 로그 {$deleted}건 삭제.");
        }

        $count = 0;

        // 사용자 관리 로그
        $count += $this->seedUserLogs($admins);

        // 인증 로그
        $count += $this->seedAuthLogs($admins);

        // 역할 관리 로그
        $count += $this->seedRoleLogs($admins);

        // 메뉴 관리 로그
        $count += $this->seedMenuLogs($admins);

        // 환경설정 로그
        $count += $this->seedSettingsLogs($admins);

        // 스케줄 관리 로그
        $count += $this->seedScheduleLogs($admins);

        // 모듈/플러그인/템플릿 로그
        $count += $this->seedExtensionLogs($admins);

        // 레이아웃 로그
        $count += $this->seedLayoutLogs($admins);

        // 첨부파일 로그
        $count += $this->seedAttachmentLogs($admins);

        // 메일 템플릿 로그
        $count += $this->seedMailTemplateLogs($admins);

        // 모듈/플러그인 설정 로그
        $count += $this->seedExtensionSettingsLogs($admins);

        // 대시보드 로그
        $count += $this->seedDashboardLogs($admins);

        // 활동 로그 조회/삭제 로그
        $count += $this->seedActivityLogViewLogs($admins);
        $count += $this->seedActivityLogDeleteLogs($admins);

        // 코어 업데이트 로그
        $count += $this->seedCoreUpdateLogs($admins);

        $this->command->info("활동 로그 샘플 시딩 완료 (총 {$count}건)");
    }

    /**
     * 사용자 관리 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedUserLogs(Collection $admins): int
    {
        $users = User::get();
        if ($users->isEmpty()) {
            $this->command->info('  - 사용자 데이터 없음 - 사용자 로그 스킵');

            return 0;
        }

        $morphType = (new User)->getMorphClass();

        $actions = [
            [
                'action' => 'user.show', 'key' => 'user_show', 'loggable' => true,
                'params' => fn ($u) => ['user_id' => $u->id],
                'changes' => fn ($u) => null, 'properties' => fn ($u) => null,
            ],
            [
                'action' => 'user.create', 'key' => 'user_create', 'loggable' => true,
                'params' => fn ($u) => ['user_id' => $u->id],
                'changes' => fn ($u) => null,
                'properties' => fn ($u) => ['name' => $u->name, 'email' => $u->email],
            ],
            [
                'action' => 'user.update', 'key' => 'user_update', 'loggable' => true,
                'params' => fn ($u) => ['user_id' => $u->id],
                'changes' => fn ($u) => [
                    ['field' => 'name', 'label_key' => 'activity_log.fields.name', 'old' => $u->name.' (수정 전)', 'new' => $u->name, 'type' => 'text'],
                    ['field' => 'email', 'label_key' => 'activity_log.fields.email', 'old' => 'old_'.$u->email, 'new' => $u->email, 'type' => 'text'],
                ],
                'properties' => fn ($u) => null,
            ],
            [
                'action' => 'user.delete', 'key' => 'user_delete', 'loggable' => false,
                'params' => fn ($u) => ['user_id' => $u->id],
                'changes' => fn ($u) => null,
                'properties' => fn ($u) => ['deleted_id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            ],
            [
                'action' => 'user.withdraw', 'key' => 'user_withdraw', 'loggable' => true,
                'params' => fn ($u) => ['user_id' => $u->id],
                'changes' => fn ($u) => null,
                'properties' => fn ($u) => ['reason' => '개인 사유'],
            ],
            [
                'action' => 'user.bulk_update', 'key' => 'user_bulk_update_status', 'loggable' => true,
                'params' => fn ($u) => ['count' => rand(2, 10)],
                'changes' => fn ($u) => [
                    ['field' => 'status', 'label_key' => 'activity_log.fields.status', 'old' => 'active', 'new' => 'suspended', 'type' => 'text'],
                ],
                'properties' => fn ($u) => null,
            ],
        ];

        $count = $this->generateResourceLogs($users, $admins, ActivityLogType::Admin, $morphType, $actions);

        // 비리소스 액션: 목록 조회
        $indexCount = rand(1, 5);
        for ($i = 0; $i < $indexCount; $i++) {
            $this->createLog(
                logType: ActivityLogType::Admin,
                action: 'user.list',
                descriptionKey: 'activity_log.description.user_index',
                descriptionParams: [],
                admin: $admins->random(),
            );
            $count++;
        }

        // 비리소스 액션: 검색
        $searchCount = rand(1, 5);
        for ($i = 0; $i < $searchCount; $i++) {
            $this->createLog(
                logType: ActivityLogType::Admin,
                action: 'user.search',
                descriptionKey: 'activity_log.description.user_search',
                descriptionParams: ['keyword' => 'test'],
                admin: $admins->random(),
                properties: [
                    'keyword' => 'test',
                    'result_count' => mt_rand(1, 20),
                ],
            );
            $count++;
        }

        $this->command->info("  - 사용자 관리 로그: {$count}건");

        return $count;
    }

    /**
     * 인증 관련 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedAuthLogs(Collection $admins): int
    {
        $count = 0;

        // 관리자별 로그인/로그아웃 로그
        foreach ($admins as $admin) {
            $loginCount = mt_rand(2, 4);
            for ($i = 0; $i < $loginCount; $i++) {
                $this->createLog(
                    logType: ActivityLogType::Admin,
                    action: 'auth.login',
                    descriptionKey: 'activity_log.description.auth_login',
                    descriptionParams: ['name' => $admin->name],
                    admin: $admin,
                    properties: ['login_method' => 'email'],
                );
                $count++;
            }

            $logoutCount = mt_rand(1, 3);
            for ($i = 0; $i < $logoutCount; $i++) {
                $this->createLog(
                    logType: ActivityLogType::Admin,
                    action: 'auth.logout',
                    descriptionKey: 'activity_log.description.auth_logout',
                    descriptionParams: ['name' => $admin->name],
                    admin: $admin,
                );
                $count++;
            }
        }

        // 일반 사용자 인증 로그
        $users = User::whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))->get();
        if ($users->isEmpty()) {
            $users = $admins;
        }

        $morphType = (new User)->getMorphClass();

        $authActions = [
            [
                'action' => 'auth.register', 'key' => 'auth_register', 'loggable' => true,
                'logType' => ActivityLogType::User,
                'params' => fn ($u) => ['name' => $u->name],
                'changes' => fn ($u) => null,
                'properties' => fn ($u) => ['email' => $u->email],
            ],
            [
                'action' => 'auth.forgot_password', 'key' => 'auth_forgot_password', 'loggable' => false,
                'logType' => ActivityLogType::User,
                'params' => fn ($u) => ['email' => $u->email],
                'changes' => fn ($u) => null,
                'properties' => fn ($u) => ['email' => $u->email],
            ],
            [
                'action' => 'auth.reset_password', 'key' => 'auth_reset_password', 'loggable' => true,
                'logType' => ActivityLogType::User,
                'params' => fn ($u) => ['name' => $u->name],
                'changes' => fn ($u) => null,
                'properties' => fn ($u) => null,
            ],
            [
                'action' => 'auth.record_consents', 'key' => 'auth_record_consents', 'loggable' => true,
                'logType' => ActivityLogType::User,
                'params' => fn ($u) => ['name' => $u->name],
                'changes' => fn ($u) => null,
                'properties' => fn ($u) => ['consents' => ['terms', 'privacy']],
            ],
        ];

        foreach ($users as $user) {
            $logCount = rand(1, 50);
            for ($i = 0; $i < $logCount; $i++) {
                $actionDef = $authActions[array_rand($authActions)];
                $this->createLog(
                    logType: $actionDef['logType'],
                    action: $actionDef['action'],
                    descriptionKey: 'activity_log.description.'.$actionDef['key'],
                    descriptionParams: ($actionDef['params'])($user),
                    admin: $user,
                    loggableType: $actionDef['loggable'] ? $morphType : null,
                    loggableId: $actionDef['loggable'] ? $user->id : null,
                    properties: ($actionDef['properties'])($user),
                    changes: ($actionDef['changes'])($user),
                );
                $count++;
            }
        }

        $this->command->info("  - 인증 로그: {$count}건");

        return $count;
    }

    /**
     * 역할 관리 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedRoleLogs(Collection $admins): int
    {
        $roles = Role::all();
        if ($roles->isEmpty()) {
            $this->command->info('  - 역할 데이터 없음 - 역할 로그 스킵');

            return 0;
        }

        $morphType = (new Role)->getMorphClass();

        $actions = [
            [
                'action' => 'role.create', 'key' => 'role_create', 'loggable' => true,
                'params' => fn ($r) => ['role_id' => $r->id],
                'changes' => fn ($r) => null, 'properties' => fn ($r) => null,
            ],
            [
                'action' => 'role.update', 'key' => 'role_update', 'loggable' => true,
                'params' => fn ($r) => ['role_id' => $r->id],
                'changes' => fn ($r) => [
                    ['field' => 'identifier', 'label_key' => 'activity_log.fields.identifier', 'old' => $r->identifier.'_old', 'new' => $r->identifier, 'type' => 'text'],
                    ['field' => 'is_active', 'label_key' => 'activity_log.fields.is_active', 'old' => ! $r->is_active, 'new' => $r->is_active, 'type' => 'boolean'],
                ],
                'properties' => fn ($r) => null,
            ],
            [
                'action' => 'role.show', 'key' => 'role_show', 'loggable' => true,
                'params' => fn ($r) => ['role_id' => $r->id],
                'changes' => fn ($r) => null, 'properties' => fn ($r) => null,
            ],
            [
                'action' => 'role.toggle_status', 'key' => 'role_toggle_status', 'loggable' => true,
                'params' => fn ($r) => ['role_id' => $r->id],
                'changes' => fn ($r) => null,
                'properties' => fn ($r) => ['is_active' => ! $r->is_active],
            ],
            [
                'action' => 'role.sync_permissions', 'key' => 'role_sync_permissions', 'loggable' => true,
                'params' => fn ($r) => ['role_id' => $r->id],
                'changes' => fn ($r) => null,
                'properties' => fn ($r) => ['permission_count' => mt_rand(5, 20)],
            ],
            [
                'action' => 'role.delete', 'key' => 'role_delete', 'loggable' => false,
                'params' => fn ($r) => ['role_id' => $r->id],
                'changes' => fn ($r) => null,
                'properties' => fn ($r) => ['deleted_id' => $r->id, 'name' => $r->name, 'identifier' => $r->identifier],
            ],
        ];

        $count = $this->generateResourceLogs($roles, $admins, ActivityLogType::Admin, $morphType, $actions);

        $this->command->info("  - 역할 관리 로그: {$count}건");

        return $count;
    }

    /**
     * 메뉴 관리 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedMenuLogs(Collection $admins): int
    {
        $menus = Menu::get();
        if ($menus->isEmpty()) {
            $this->command->info('  - 메뉴 데이터 없음 - 메뉴 로그 스킵');

            return 0;
        }

        $morphType = (new Menu)->getMorphClass();

        $actions = [
            [
                'action' => 'menu.create', 'key' => 'menu_create', 'loggable' => true,
                'params' => fn ($m) => ['menu_id' => $m->id],
                'changes' => fn ($m) => null, 'properties' => fn ($m) => null,
            ],
            [
                'action' => 'menu.update', 'key' => 'menu_update', 'loggable' => true,
                'params' => fn ($m) => ['menu_id' => $m->id],
                'changes' => fn ($m) => [
                    ['field' => 'url', 'label_key' => 'activity_log.fields.url', 'old' => '/old-path', 'new' => $m->url ?? '/new-path', 'type' => 'text'],
                    ['field' => 'order', 'label_key' => 'activity_log.fields.order', 'old' => max(0, $m->order - mt_rand(1, 3)), 'new' => $m->order, 'type' => 'number'],
                    ['field' => 'is_active', 'label_key' => 'activity_log.fields.is_active', 'old' => ! $m->is_active, 'new' => $m->is_active, 'type' => 'boolean'],
                ],
                'properties' => fn ($m) => null,
            ],
            [
                'action' => 'menu.show', 'key' => 'menu_show', 'loggable' => true,
                'params' => fn ($m) => ['menu_id' => $m->id],
                'changes' => fn ($m) => null, 'properties' => fn ($m) => null,
            ],
            [
                'action' => 'menu.update_order', 'key' => 'menu_update_order', 'loggable' => true,
                'params' => fn ($m) => ['menu_id' => $m->id],
                'changes' => fn ($m) => null,
                'properties' => fn ($m) => ['old_order' => max(0, $m->order - mt_rand(1, 5)), 'new_order' => $m->order],
            ],
            [
                'action' => 'menu.toggle_status', 'key' => 'menu_toggle_status', 'loggable' => true,
                'params' => fn ($m) => ['menu_id' => $m->id],
                'changes' => fn ($m) => null,
                'properties' => fn ($m) => ['is_active' => ! $m->is_active],
            ],
            [
                'action' => 'menu.delete', 'key' => 'menu_delete', 'loggable' => false,
                'params' => fn ($m) => ['menu_id' => $m->id],
                'changes' => fn ($m) => null,
                'properties' => fn ($m) => ['deleted_id' => $m->id, 'deleted_name' => $m->name, 'deleted_slug' => $m->slug],
            ],
            [
                'action' => 'menu.sync_roles', 'key' => 'menu_sync_roles', 'loggable' => true,
                'params' => fn ($m) => ['menu_id' => $m->id],
                'changes' => fn ($m) => null,
                'properties' => fn ($m) => ['role_count' => mt_rand(1, 5)],
            ],
        ];

        $count = $this->generateResourceLogs($menus, $admins, ActivityLogType::Admin, $morphType, $actions);

        $this->command->info("  - 메뉴 관리 로그: {$count}건");

        return $count;
    }

    /**
     * 환경설정 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedSettingsLogs(Collection $admins): int
    {
        $count = 0;

        $settingsActions = [
            ['action' => 'settings.save', 'group' => 'general'],
            ['action' => 'settings.save', 'group' => 'mail'],
            ['action' => 'settings.save', 'group' => 'storage'],
            ['action' => 'settings.index', 'group' => 'general'],
            ['action' => 'settings.index', 'group' => 'mail'],
            ['action' => 'settings.index', 'group' => 'security'],
            ['action' => 'settings.clear_cache', 'group' => null],
            ['action' => 'settings.clear_cache', 'group' => null],
            ['action' => 'settings.optimize_system', 'group' => null],
            ['action' => 'settings.optimize_system', 'group' => null],
        ];

        $logCount = rand(1, 5);
        for ($i = 0; $i < $logCount; $i++) {
            $item = $settingsActions[array_rand($settingsActions)];
            $params = [];
            $properties = [];

            if ($item['group']) {
                $params['group'] = $item['group'];
                $properties['group'] = $item['group'];
            }

            $this->createLog(
                logType: ActivityLogType::Admin,
                action: $item['action'],
                descriptionKey: 'activity_log.description.'.str_replace('.', '_', $item['action']),
                descriptionParams: $params,
                admin: $admins->random(),
                properties: $properties ?: null,
            );
            $count++;
        }

        $this->command->info("  - 환경설정 로그: {$count}건");

        return $count;
    }

    /**
     * 스케줄 관리 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedScheduleLogs(Collection $admins): int
    {
        $schedules = Schedule::get();
        if ($schedules->isEmpty()) {
            $this->command->info('  - 스케줄 데이터 없음 - 스케줄 로그 스킵');

            return 0;
        }

        $morphType = (new Schedule)->getMorphClass();

        $actions = [
            [
                'action' => 'schedule.create', 'key' => 'schedule_create', 'loggable' => true,
                'logType' => ActivityLogType::Admin,
                'params' => fn ($s) => ['schedule_id' => $s->id],
                'changes' => fn ($s) => null, 'properties' => fn ($s) => null,
            ],
            [
                'action' => 'schedule.update', 'key' => 'schedule_update', 'loggable' => true,
                'logType' => ActivityLogType::Admin,
                'params' => fn ($s) => ['schedule_id' => $s->id],
                'changes' => fn ($s) => [
                    ['field' => 'expression', 'label_key' => 'activity_log.fields.expression', 'old' => '0 * * * *', 'new' => $s->expression, 'type' => 'text'],
                    ['field' => 'is_active', 'label_key' => 'activity_log.fields.is_active', 'old' => ! $s->is_active, 'new' => $s->is_active, 'type' => 'boolean'],
                ],
                'properties' => fn ($s) => null,
            ],
            [
                'action' => 'schedule.show', 'key' => 'schedule_show', 'loggable' => true,
                'logType' => ActivityLogType::Admin,
                'params' => fn ($s) => ['schedule_id' => $s->id],
                'changes' => fn ($s) => null, 'properties' => fn ($s) => null,
            ],
            [
                'action' => 'schedule.delete', 'key' => 'schedule_delete', 'loggable' => false,
                'logType' => ActivityLogType::Admin,
                'params' => fn ($s) => ['schedule_id' => $s->id],
                'changes' => fn ($s) => null,
                'properties' => fn ($s) => ['deleted_id' => $s->id, 'deleted_name' => $s->name, 'deleted_command' => $s->command],
            ],
            [
                'action' => 'schedule.run', 'key' => 'schedule_run', 'loggable' => true,
                'logType' => ActivityLogType::System,
                'params' => fn ($s) => ['schedule_id' => $s->id],
                'changes' => fn ($s) => null,
                'properties' => fn ($s) => ['command' => $s->command, 'result' => 'success', 'duration_ms' => mt_rand(100, 5000)],
            ],
            [
                'action' => 'schedule.bulk_update', 'key' => 'schedule_bulk_update_status', 'loggable' => true,
                'logType' => ActivityLogType::Admin,
                'params' => fn ($s) => ['count' => rand(2, 10)],
                'changes' => fn ($s) => [
                    ['field' => 'is_active', 'label_key' => 'activity_log.fields.is_active', 'old' => ! $s->is_active, 'new' => $s->is_active, 'type' => 'boolean'],
                ],
                'properties' => fn ($s) => null,
            ],
            [
                'action' => 'schedule.bulk_delete', 'key' => 'schedule_bulk_delete', 'loggable' => true,
                'logType' => ActivityLogType::Admin,
                'params' => fn ($s) => ['count' => rand(2, 10)],
                'changes' => fn ($s) => null,
                'properties' => fn ($s) => [
                    'snapshot' => ['id' => $s->id, 'name' => $s->name, 'command' => $s->command],
                ],
            ],
        ];

        $count = 0;
        foreach ($schedules as $schedule) {
            $logCount = rand(1, 50);
            for ($i = 0; $i < $logCount; $i++) {
                $actionDef = $actions[array_rand($actions)];
                $logType = $actionDef['logType'] ?? ActivityLogType::Admin;
                $this->createLog(
                    logType: $logType,
                    action: $actionDef['action'],
                    descriptionKey: 'activity_log.description.'.$actionDef['key'],
                    descriptionParams: ($actionDef['params'])($schedule),
                    admin: $admins->random(),
                    loggableType: $actionDef['loggable'] ? $morphType : null,
                    loggableId: $actionDef['loggable'] ? $schedule->id : null,
                    properties: ($actionDef['properties'])($schedule),
                    changes: ($actionDef['changes'])($schedule),
                );
                $count++;
            }
        }

        $this->command->info("  - 스케줄 관리 로그: {$count}건");

        return $count;
    }

    /**
     * 모듈/플러그인/템플릿 관련 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedExtensionLogs(Collection $admins): int
    {
        $count = 0;

        $extensions = [
            ['type' => 'module', 'identifier' => 'sirsoft-ecommerce', 'name' => 'E-Commerce'],
            ['type' => 'module', 'identifier' => 'sirsoft-board', 'name' => 'Board'],
            ['type' => 'plugin', 'identifier' => 'sirsoft-payment', 'name' => 'Payment'],
            ['type' => 'template', 'identifier' => 'sirsoft-admin_basic', 'name' => 'Admin Basic'],
            ['type' => 'template', 'identifier' => 'sirsoft-basic', 'name' => 'Basic'],
        ];

        $extensionActions = ['install', 'activate', 'deactivate', 'uninstall'];

        foreach ($extensions as $ext) {
            $logCount = rand(1, 50);
            for ($i = 0; $i < $logCount; $i++) {
                $action = $extensionActions[array_rand($extensionActions)];
                $fullAction = $ext['type'].'.'.$action;

                $this->createLog(
                    logType: ActivityLogType::Admin,
                    action: $fullAction,
                    descriptionKey: 'activity_log.description.'.str_replace('.', '_', $fullAction),
                    descriptionParams: [$ext['type'].'_name' => $ext['name']],
                    admin: $admins->random(),
                    properties: [
                        'identifier' => $ext['identifier'],
                        'type' => $ext['type'],
                    ],
                );
                $count++;
            }
        }

        // update 로그
        $updateTypes = ['module', 'plugin', 'template'];
        foreach ($updateTypes as $type) {
            $ext = collect($extensions)->firstWhere('type', $type);
            if ($ext) {
                $updateCount = rand(1, 5);
                $actionName = $type === 'template' ? $type.'.version_update' : $type.'.update';
                for ($i = 0; $i < $updateCount; $i++) {
                    $this->createLog(
                        logType: ActivityLogType::Admin,
                        action: $actionName,
                        descriptionKey: 'activity_log.description.'.str_replace('.', '_', $actionName),
                        descriptionParams: [$type.'_name' => $ext['name']],
                        admin: $admins->random(),
                        properties: [
                            'identifier' => $ext['identifier'],
                            'type' => $type,
                            'from_version' => '0.1.0',
                            'to_version' => '0.2.0',
                        ],
                    );
                    $count++;
                }
            }
        }

        // refresh_layouts 로그 (module, template)
        foreach (['module', 'template'] as $type) {
            $ext = collect($extensions)->firstWhere('type', $type);
            if ($ext) {
                $refreshCount = rand(1, 5);
                for ($i = 0; $i < $refreshCount; $i++) {
                    $this->createLog(
                        logType: ActivityLogType::Admin,
                        action: $type.'.refresh_layouts',
                        descriptionKey: 'activity_log.description.'.$type.'_refresh_layouts',
                        descriptionParams: [$type.'_name' => $ext['name']],
                        admin: $admins->random(),
                        properties: [
                            'identifier' => $ext['identifier'],
                            'type' => $type,
                        ],
                    );
                    $count++;
                }
            }
        }

        $this->command->info("  - 확장 시스템 로그: {$count}건");

        return $count;
    }

    /**
     * 첨부파일 활동 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedAttachmentLogs(Collection $admins): int
    {
        $attachments = Attachment::get();
        if ($attachments->isEmpty()) {
            $this->command->info('  - 첨부파일 데이터 없음 - 첨부파일 로그 스킵');

            return 0;
        }

        $morphType = (new Attachment)->getMorphClass();

        $actions = [
            [
                'action' => 'attachment.upload', 'key' => 'attachment_upload', 'loggable' => true,
                'params' => fn ($a) => ['filename' => $a->original_filename ?? 'file'],
                'changes' => fn ($a) => null,
                'properties' => fn ($a) => ['filename' => $a->original_filename, 'mime_type' => $a->mime_type, 'size' => $a->size],
            ],
            [
                'action' => 'attachment.delete', 'key' => 'attachment_delete', 'loggable' => false,
                'params' => fn ($a) => ['filename' => $a->original_filename ?? 'file'],
                'changes' => fn ($a) => null,
                'properties' => fn ($a) => ['deleted_id' => $a->id, 'filename' => $a->original_filename, 'mime_type' => $a->mime_type],
            ],
            [
                'action' => 'attachment.bulk_delete', 'key' => 'attachment_bulk_delete', 'loggable' => true,
                'params' => fn ($a) => ['filename' => $a->original_filename ?? 'file'],
                'changes' => fn ($a) => null,
                'properties' => fn ($a) => ['snapshot' => ['id' => $a->id, 'filename' => $a->original_filename]],
            ],
        ];

        $count = $this->generateResourceLogs($attachments, $admins, ActivityLogType::Admin, $morphType, $actions);

        $this->command->info("  - 첨부파일 로그: {$count}건");

        return $count;
    }

    /**
     * 모듈/플러그인 설정 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedExtensionSettingsLogs(Collection $admins): int
    {
        $count = 0;

        $settingsEntries = [
            ['action' => 'module_settings.save', 'type' => 'module', 'identifier' => 'sirsoft-ecommerce', 'name' => 'E-Commerce'],
            ['action' => 'module_settings.save', 'type' => 'module', 'identifier' => 'sirsoft-board', 'name' => 'Board'],
            ['action' => 'module_settings.reset', 'type' => 'module', 'identifier' => 'sirsoft-ecommerce', 'name' => 'E-Commerce'],
            ['action' => 'module_settings.reset', 'type' => 'module', 'identifier' => 'sirsoft-board', 'name' => 'Board'],
            ['action' => 'plugin_settings.save', 'type' => 'plugin', 'identifier' => 'sirsoft-payment', 'name' => 'Payment'],
            ['action' => 'plugin_settings.reset', 'type' => 'plugin', 'identifier' => 'sirsoft-payment', 'name' => 'Payment'],
        ];

        $logCount = rand(1, 5);
        for ($i = 0; $i < $logCount; $i++) {
            $entry = $settingsEntries[array_rand($settingsEntries)];
            $this->createLog(
                logType: ActivityLogType::Admin,
                action: $entry['action'],
                descriptionKey: 'activity_log.description.'.str_replace('.', '_', $entry['action']),
                descriptionParams: [$entry['type'].'_name' => $entry['name']],
                admin: $admins->random(),
                properties: [
                    'identifier' => $entry['identifier'],
                    'group' => 'general',
                ],
            );
            $count++;
        }

        $this->command->info("  - 확장 설정 로그: {$count}건");

        return $count;
    }

    /**
     * 레이아웃 관련 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedLayoutLogs(Collection $admins): int
    {
        $count = 0;

        $layouts = [
            'admin/dashboard', 'admin/users/index', 'admin/users/show',
            'admin/roles/index', 'admin/settings/general',
            'user/home', 'user/profile', 'user/login',
        ];

        $logCount = rand(1, 5);
        for ($i = 0; $i < $logCount; $i++) {
            $layout = $layouts[array_rand($layouts)];
            $action = mt_rand(0, 1) ? 'layout.update' : 'layout.version_restore';

            $properties = ['layout_path' => $layout];

            if ($action === 'layout.version_restore') {
                $properties['restored_version'] = mt_rand(1, 5);
            }

            $this->createLog(
                logType: ActivityLogType::Admin,
                action: $action,
                descriptionKey: 'activity_log.description.'.str_replace('.', '_', $action),
                descriptionParams: ['layout_path' => $layout],
                admin: $admins->random(),
                properties: $properties,
            );
            $count++;
        }

        $this->command->info("  - 레이아웃 로그: {$count}건");

        return $count;
    }

    /**
     * 메일 템플릿 관련 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedMailTemplateLogs(Collection $admins): int
    {
        $mailTemplates = MailTemplate::get();
        if ($mailTemplates->isEmpty()) {
            $this->command->info('  - 메일 템플릿 데이터 없음 - 메일 템플릿 로그 스킵');

            return 0;
        }

        $morphType = (new MailTemplate)->getMorphClass();

        $actions = [
            [
                'action' => 'mail_template.update', 'key' => 'mail_template_update', 'loggable' => true,
                'params' => fn ($mt) => ['template_name' => $mt->type],
                'changes' => fn ($mt) => null,
                'properties' => fn ($mt) => ['template_type' => $mt->type, 'locale' => ['ko', 'en'][array_rand(['ko', 'en'])]],
            ],
            [
                'action' => 'mail_template.toggle_active', 'key' => 'mail_template_toggle_active', 'loggable' => true,
                'params' => fn ($mt) => ['template_name' => $mt->type],
                'changes' => fn ($mt) => null,
                'properties' => fn ($mt) => ['template_type' => $mt->type, 'is_active' => (bool) mt_rand(0, 1)],
            ],
        ];

        $count = $this->generateResourceLogs($mailTemplates, $admins, ActivityLogType::Admin, $morphType, $actions);

        $this->command->info("  - 메일 템플릿 로그: {$count}건");

        return $count;
    }

    /**
     * 대시보드 관련 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedDashboardLogs(Collection $admins): int
    {
        $count = 0;

        $dashboardActions = [
            'dashboard.stats',
            'dashboard.resources',
            'dashboard.activities',
            'dashboard.alerts',
        ];

        $logCount = rand(1, 5);
        for ($i = 0; $i < $logCount; $i++) {
            $action = $dashboardActions[array_rand($dashboardActions)];

            $this->createLog(
                logType: ActivityLogType::Admin,
                action: $action,
                descriptionKey: 'activity_log.description.'.str_replace('.', '_', $action),
                descriptionParams: [],
                admin: $admins->random(),
            );
            $count++;
        }

        $this->command->info("  - 대시보드 로그: {$count}건");

        return $count;
    }

    /**
     * 활동 로그 조회 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedActivityLogViewLogs(Collection $admins): int
    {
        $count = 0;

        $logCount = rand(1, 5);
        for ($i = 0; $i < $logCount; $i++) {
            $this->createLog(
                logType: ActivityLogType::Admin,
                action: 'activity_log.index',
                descriptionKey: 'activity_log.description.activity_log_index',
                descriptionParams: [],
                admin: $admins->random(),
                properties: [
                    'filters' => [
                        'log_type' => ['admin', 'system', null][array_rand(['admin', 'system', null])],
                        'page' => mt_rand(1, 5),
                    ],
                ],
            );
            $count++;
        }

        $this->command->info("  - 활동 로그 조회 로그: {$count}건");

        return $count;
    }

    /**
     * 활동 로그 삭제 관련 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedActivityLogDeleteLogs(Collection $admins): int
    {
        $count = 0;

        // 개별 삭제 로그
        $deleteCount = rand(1, 3);
        for ($i = 0; $i < $deleteCount; $i++) {
            $this->createLog(
                logType: ActivityLogType::Admin,
                action: 'activity_log.delete',
                descriptionKey: 'activity_log.description.activity_log_delete',
                descriptionParams: ['log_id' => mt_rand(1, 500)],
                admin: $admins->random(),
                properties: [],
            );
            $count++;
        }

        // 일괄 삭제 로그
        $bulkDeleteCount = rand(1, 2);
        for ($i = 0; $i < $bulkDeleteCount; $i++) {
            $deletedCount = mt_rand(5, 50);
            $this->createLog(
                logType: ActivityLogType::Admin,
                action: 'activity_log.bulk_delete',
                descriptionKey: 'activity_log.description.activity_log_bulk_delete',
                descriptionParams: ['count' => $deletedCount],
                admin: $admins->random(),
                properties: [
                    'deleted_count' => $deletedCount,
                ],
            );
            $count++;
        }

        $this->command->info("  - 활동 로그 삭제 로그: {$count}건");

        return $count;
    }

    /**
     * 코어 업데이트 관련 로그를 생성합니다.
     *
     * @param  Collection  $admins  관리자 컬렉션
     * @return int 생성된 레코드 수
     */
    private function seedCoreUpdateLogs(Collection $admins): int
    {
        $count = 0;

        $versions = ['1.0.0', '1.0.1', '1.1.0'];

        for ($i = 0; $i < 3; $i++) {
            $this->createLog(
                logType: ActivityLogType::System,
                action: 'core.check_update',
                descriptionKey: 'activity_log.description.core_update_check',
                descriptionParams: [],
                admin: $admins->random(),
                properties: [
                    'current_version' => $versions[$i] ?? '1.0.0',
                    'available' => $i < 2,
                ],
            );
            $count++;
        }

        for ($i = 0; $i < 2; $i++) {
            $fromVersion = $versions[$i];
            $toVersion = $versions[$i + 1] ?? '1.1.0';

            $this->createLog(
                logType: ActivityLogType::System,
                action: 'core.update',
                descriptionKey: 'activity_log.description.core_update_update',
                descriptionParams: ['version' => $toVersion],
                admin: $admins->random(),
                properties: [
                    'from_version' => $fromVersion,
                    'to_version' => $toVersion,
                    'duration_seconds' => mt_rand(10, 120),
                ],
            );
            $count++;
        }

        $this->command->info("  - 코어 업데이트 로그: {$count}건");

        return $count;
    }

    /**
     * 리소스 중심 랜덤 로그를 생성합니다.
     *
     * 각 리소스마다 rand(1, 50)개의 랜덤 액션을 선택하여 로그를 생성합니다.
     *
     * @param  Collection  $resources  리소스 컬렉션
     * @param  Collection  $actors  행위자 컬렉션
     * @param  ActivityLogType  $logType  기본 로그 유형
     * @param  string  $morphType  리소스 morph 타입
     * @param  array  $actions  액션 정의 배열
     * @return int 생성된 레코드 수
     */
    private function generateResourceLogs(
        Collection $resources,
        Collection $actors,
        ActivityLogType $logType,
        string $morphType,
        array $actions,
    ): int {
        $count = 0;

        foreach ($resources as $resource) {
            $logCount = rand(1, 50);
            for ($i = 0; $i < $logCount; $i++) {
                $actionDef = $actions[array_rand($actions)];
                $effectiveLogType = $actionDef['logType'] ?? $logType;

                $this->createLog(
                    logType: $effectiveLogType,
                    action: $actionDef['action'],
                    descriptionKey: 'activity_log.description.'.$actionDef['key'],
                    descriptionParams: ($actionDef['params'])($resource),
                    admin: $actors->random(),
                    loggableType: $actionDef['loggable'] ? $morphType : null,
                    loggableId: $actionDef['loggable'] ? $resource->id : null,
                    properties: ($actionDef['properties'])($resource),
                    changes: ($actionDef['changes'])($resource),
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * 활동 로그 레코드를 생성합니다.
     *
     * @param  ActivityLogType  $logType  로그 유형
     * @param  string  $action  액션
     * @param  string  $descriptionKey  설명 다국어 키
     * @param  array  $descriptionParams  설명 파라미터
     * @param  User  $admin  행위자
     * @param  string|null  $loggableType  대상 모델 클래스
     * @param  int|null  $loggableId  대상 모델 ID
     * @param  array|null  $properties  추가 속성
     * @param  array|null  $changes  변경 내역 (old/new)
     * @return ActivityLog 생성된 활동 로그
     */
    private function createLog(
        ActivityLogType $logType,
        string $action,
        string $descriptionKey,
        array $descriptionParams,
        User $admin,
        ?string $loggableType = null,
        ?int $loggableId = null,
        ?array $properties = null,
        ?array $changes = null,
    ): ActivityLog {
        return ActivityLog::create([
            'log_type' => $logType,
            'action' => $action,
            'description_key' => $descriptionKey,
            'description_params' => $descriptionParams,
            'user_id' => $admin->id,
            'loggable_type' => $loggableType,
            'loggable_id' => $loggableId,
            'properties' => $properties,
            'changes' => $changes,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => $this->randomCreatedAt(),
        ]);
    }

    /**
     * 최근 30일 내 랜덤 생성 시각을 반환합니다.
     *
     * @return Carbon 랜덤 생성 시각
     */
    private function randomCreatedAt(): Carbon
    {
        return Carbon::now()
            ->subDays(mt_rand(0, 30))
            ->subHours(mt_rand(0, 23))
            ->subMinutes(mt_rand(0, 59))
            ->subSeconds(mt_rand(0, 59));
    }
}
