<?php

namespace App\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * 코어 서비스 훅 구독 리스너
 *
 * 코어 서비스에서 발행하는 훅을 구독하여
 * Log::channel('activity')를 통해 활동 로그를 기록합니다.
 *
 * Monolog 기반 아키텍처:
 * Service → doAction → CoreActivityLogListener → Log::channel('activity') → ActivityLogHandler → DB
 */
class CoreActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * @param  ActivityLogRepositoryInterface  $activityLogRepository  활동 로그 Repository
     * @param  UserRepositoryInterface  $userRepository  사용자 Repository (bulk lookup)
     * @param  ScheduleRepositoryInterface  $scheduleRepository  스케줄 Repository (bulk lookup)
     */
    public function __construct(
        protected ActivityLogRepositoryInterface $activityLogRepository,
        protected UserRepositoryInterface $userRepository,
        protected ScheduleRepositoryInterface $scheduleRepository,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // ─── User ───
            'core.user.after_create' => ['method' => 'handleUserAfterCreate', 'priority' => 20],
            'core.user.after_update' => ['method' => 'handleUserAfterUpdate', 'priority' => 20],
            'core.user.after_delete' => ['method' => 'handleUserAfterDelete', 'priority' => 20],
            'core.user.after_withdraw' => ['method' => 'handleUserAfterWithdraw', 'priority' => 20],
            'core.user.after_show' => ['method' => 'handleUserAfterShow', 'priority' => 20],
            'core.user.after_list' => ['method' => 'handleUserAfterList', 'priority' => 20],
            'core.user.after_search' => ['method' => 'handleUserAfterSearch', 'priority' => 20],
            'sirsoft-core.user.after_bulk_update' => ['method' => 'handleUserAfterBulkUpdate', 'priority' => 20],

            // ─── Auth ───
            'core.auth.after_login' => ['method' => 'handleAuthAfterLogin', 'priority' => 20],
            'core.auth.logout' => ['method' => 'handleAuthLogout', 'priority' => 20],
            'core.auth.register' => ['method' => 'handleAuthRegister', 'priority' => 20],
            'core.auth.forgot_password' => ['method' => 'handleAuthForgotPassword', 'priority' => 20],
            'core.auth.reset_password' => ['method' => 'handleAuthResetPassword', 'priority' => 20],
            'core.auth.record_consents' => ['method' => 'handleAuthRecordConsents', 'priority' => 20],
            'core.auth.login_failed' => ['method' => 'handleAuthLoginFailed', 'priority' => 20],
            'core.auth.account_locked' => ['method' => 'handleAuthAccountLocked', 'priority' => 20],

            // ─── IdentityVerification (IDV) ───
            // DTO(VerificationChallenge/VerificationResult) 를 인자로 받으므로 sync 실행 필수
            // (큐 직렬화 대상에 POPO 는 포함되지 않아 queue 시 null 전달됨)
            'core.identity.after_request' => ['method' => 'handleIdentityRequested', 'priority' => 20, 'sync' => true],
            'core.identity.after_verify' => ['method' => 'handleIdentityVerified', 'priority' => 20, 'sync' => true],
            'core.identity.challenge_expired' => ['method' => 'handleIdentityExpired', 'priority' => 20, 'sync' => true],

            // ─── Role ───
            'core.role.after_create' => ['method' => 'handleRoleAfterCreate', 'priority' => 20],
            'core.role.after_update' => ['method' => 'handleRoleAfterUpdate', 'priority' => 20],
            'core.role.after_delete' => ['method' => 'handleRoleAfterDelete', 'priority' => 20],
            'core.role.after_sync_permissions' => ['method' => 'handleRoleAfterSyncPermissions', 'priority' => 20],
            'core.role.after_toggle_status' => ['method' => 'handleRoleAfterToggleStatus', 'priority' => 20],

            // ─── Menu ───
            'core.menu.after_create' => ['method' => 'handleMenuAfterCreate', 'priority' => 20],
            'core.menu.after_update' => ['method' => 'handleMenuAfterUpdate', 'priority' => 20],
            'core.menu.after_delete' => ['method' => 'handleMenuAfterDelete', 'priority' => 20],
            'core.menu.after_update_order' => ['method' => 'handleMenuAfterUpdateOrder', 'priority' => 20],
            'core.menu.after_toggle_status' => ['method' => 'handleMenuAfterToggleStatus', 'priority' => 20],
            'core.menu.after_sync_roles' => ['method' => 'handleMenuAfterSyncRoles', 'priority' => 20],

            // ─── Settings ───
            'core.settings.after_save' => ['method' => 'handleSettingsAfterSave', 'priority' => 20],
            'core.settings.after_set' => ['method' => 'handleSettingsAfterSet', 'priority' => 20],

            // ─── Schedule ───
            'core.schedule.after_create' => ['method' => 'handleScheduleAfterCreate', 'priority' => 20],
            'core.schedule.after_update' => ['method' => 'handleScheduleAfterUpdate', 'priority' => 20],
            'core.schedule.after_delete' => ['method' => 'handleScheduleAfterDelete', 'priority' => 20],
            'core.schedule.after_run' => ['method' => 'handleScheduleAfterRun', 'priority' => 20],
            'core.schedule.after_bulk_update' => ['method' => 'handleScheduleAfterBulkUpdate', 'priority' => 20],
            'core.schedule.after_bulk_delete' => ['method' => 'handleScheduleAfterBulkDelete', 'priority' => 20],

            // ─── Attachment ───
            'core.attachment.after_upload' => ['method' => 'handleAttachmentAfterUpload', 'priority' => 20],
            'core.attachment.after_delete' => ['method' => 'handleAttachmentAfterDelete', 'priority' => 20],
            'core.attachment.after_bulk_delete' => ['method' => 'handleAttachmentAfterBulkDelete', 'priority' => 20],

            // ─── Module ───
            'core.modules.after_install' => ['method' => 'handleModuleAfterInstall', 'priority' => 20],
            'core.modules.after_activate' => ['method' => 'handleModuleAfterActivate', 'priority' => 20],
            'core.modules.after_deactivate' => ['method' => 'handleModuleAfterDeactivate', 'priority' => 20],
            'core.modules.after_uninstall' => ['method' => 'handleModuleAfterUninstall', 'priority' => 20],
            'core.modules.after_update' => ['method' => 'handleModuleAfterUpdate', 'priority' => 20],
            'core.modules.after_refresh_layouts' => ['method' => 'handleModuleAfterRefreshLayouts', 'priority' => 20],

            // ─── Plugin ───
            'core.plugins.after_install' => ['method' => 'handlePluginAfterInstall', 'priority' => 20],
            'core.plugins.after_activate' => ['method' => 'handlePluginAfterActivate', 'priority' => 20],
            'core.plugins.after_deactivate' => ['method' => 'handlePluginAfterDeactivate', 'priority' => 20],
            'core.plugins.after_uninstall' => ['method' => 'handlePluginAfterUninstall', 'priority' => 20],
            'core.plugins.after_update' => ['method' => 'handlePluginAfterUpdate', 'priority' => 20],

            // ─── Template ───
            'core.templates.after_install' => ['method' => 'handleTemplateAfterInstall', 'priority' => 20],
            'core.templates.after_activate' => ['method' => 'handleTemplateAfterActivate', 'priority' => 20],
            'core.templates.after_deactivate' => ['method' => 'handleTemplateAfterDeactivate', 'priority' => 20],
            'core.templates.after_uninstall' => ['method' => 'handleTemplateAfterUninstall', 'priority' => 20],
            'core.templates.after_version_update' => ['method' => 'handleTemplateAfterVersionUpdate', 'priority' => 20],
            'core.templates.after_refresh_layouts' => ['method' => 'handleTemplateAfterRefreshLayouts', 'priority' => 20],

            // ─── Layout ───
            'core.layout.after_update' => ['method' => 'handleLayoutAfterUpdate', 'priority' => 20],
            'core.layout.after_version_restore' => ['method' => 'handleLayoutAfterVersionRestore', 'priority' => 20],

            // ─── Module Settings ───
            'core.module_settings.after_save' => ['method' => 'handleModuleSettingsAfterSave', 'priority' => 20],
            'core.module_settings.after_reset' => ['method' => 'handleModuleSettingsAfterReset', 'priority' => 20],

            // ─── Plugin Settings ───
            'core.plugin_settings.after_save' => ['method' => 'handlePluginSettingsAfterSave', 'priority' => 20],
            'core.plugin_settings.after_reset' => ['method' => 'handlePluginSettingsAfterReset', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    // ═══════════════════════════════════════════
    // User 핸들러
    // ═══════════════════════════════════════════

    /**
     * 사용자 생성 후 로그 기록
     *
     * @param User $user 생성된 사용자
     * @param array $originalData 원본 요청 데이터
     */
    public function handleUserAfterCreate(User $user, array $originalData): void
    {
        $this->logActivity('user.create', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.user_create',
            'description_params' => ['user_id' => $user->uuid],
            'properties' => ['email' => $user->email, 'name' => $user->name],
        ]);
    }

    /**
     * 사용자 수정 후 로그 기록
     *
     * @param User $user 수정된 사용자
     * @param array $originalData 원본 요청 데이터
     * @param array|null $snapshot 수정 전 스냅샷
     */
    public function handleUserAfterUpdate(User $user, array $originalData, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($user, $snapshot);

        $this->logActivity('user.update', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.user_update',
            'description_params' => ['user_id' => $user->uuid],
            'changes' => $changes,
        ]);
    }

    /**
     * 사용자 삭제 후 로그 기록
     *
     * @param array $userData 삭제된 사용자 데이터
     */
    public function handleUserAfterDelete(array $userData): void
    {
        // user 삭제 시 activity_logs.user_id를 NULL 처리 (FK 제거됨 — 파티셔닝 호환)
        if (isset($userData['id'])) {
            $this->activityLogRepository->anonymizeUserId((int) $userData['id']);
        }

        $this->logActivity('user.delete', [
            'description_key' => 'activity_log.description.user_delete',
            'description_params' => ['user_id' => $userData['uuid'] ?? ''],
            'properties' => ['deleted_user' => $userData],
        ]);
    }

    /**
     * 사용자 탈퇴 후 로그 기록
     *
     * @param User $user 탈퇴한 사용자
     */
    public function handleUserAfterWithdraw(User $user): void
    {
        $this->logActivity('user.withdraw', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.user_withdraw',
            'description_params' => ['user_id' => $user->uuid],
        ]);
    }

    /**
     * 사용자 상세 조회 후 로그 기록
     *
     * @param User $user 조회된 사용자
     */
    public function handleUserAfterShow(User $user): void
    {
        $this->logActivity('user.show', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.user_show',
            'description_params' => ['user_id' => $user->uuid],
        ]);
    }

    /**
     * 사용자 목록 조회 후 로그 기록
     *
     * @param int $count 조회된 총 사용자 수
     */
    public function handleUserAfterList(int $count): void
    {
        $this->logActivity('user.index', [
            'description_key' => 'activity_log.description.user_index',
            'properties' => ['result_count' => $count],
        ]);
    }

    /**
     * 사용자 검색 후 로그 기록
     *
     * @param string $query 검색어
     * @param int $count 검색 결과 수
     */
    public function handleUserAfterSearch(string $query, int $count): void
    {
        $this->logActivity('user.search', [
            'description_key' => 'activity_log.description.user_search',
            'properties' => ['query' => $query, 'result_count' => $count],
        ]);
    }

    /**
     * 사용자 일괄 상태 변경 후 로그 기록
     *
     * @param array $uuids 대상 UUID 목록
     * @param string $status 변경된 상태
     * @param int $updatedCount 변경된 수
     * @param array $snapshots 변경 전 스냅샷 (uuid => snapshot)
     */
    public function handleUserAfterBulkUpdate(array $uuids, string $status, int $updatedCount, array $snapshots = []): void
    {
        $users = $this->userRepository->findManyByUuidsKeyed($uuids);

        foreach ($uuids as $uuid) {
            $user = $users->get($uuid);
            if (! $user) {
                continue;
            }

            $snapshot = $snapshots[$uuid] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($user, $snapshot) : null;

            $this->logActivity('user.bulk_update_status', [
                'loggable' => $user,
                'description_key' => 'activity_log.description.user_bulk_update_status',
                'description_params' => ['count' => 1],
                'properties' => ['uuid' => $uuid, 'status' => $status],
                'changes' => $changes,
            ]);
        }
    }

    // ═══════════════════════════════════════════
    // Auth 핸들러
    // ═══════════════════════════════════════════

    /**
     * 관리자 로그인 후 로그 기록
     *
     * @param User $user 로그인한 사용자
     * @param array $loginData 로그인 데이터
     */
    public function handleAuthAfterLogin(User $user, array $loginData): void
    {
        $this->logActivity('auth.login', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.auth_login',
            'user_id' => $user->id,
        ]);
    }

    /**
     * 로그아웃 로그 기록
     *
     * @param User $user 로그아웃한 사용자
     */
    public function handleAuthLogout(User $user): void
    {
        $this->logActivity('auth.logout', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.auth_logout',
            'user_id' => $user->id,
        ]);
    }

    /**
     * 회원가입 후 로그 기록
     *
     * @param User $user 등록된 사용자
     * @param array $registrationData 등록 데이터
     */
    public function handleAuthRegister(User $user, array $registrationData): void
    {
        $this->logActivity('auth.register', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.auth_register',
            'user_id' => $user->id,
        ]);
    }

    /**
     * 비밀번호 찾기 요청 로그 기록
     *
     * @param User $user 요청한 사용자
     */
    public function handleAuthForgotPassword(User $user): void
    {
        $this->logActivity('auth.forgot_password', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.auth_forgot_password',
            'user_id' => $user->id,
        ]);
    }

    /**
     * 비밀번호 재설정 로그 기록
     *
     * @param User $user 재설정한 사용자
     */
    public function handleAuthResetPassword(User $user): void
    {
        $this->logActivity('auth.reset_password', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.auth_reset_password',
            'user_id' => $user->id,
        ]);
    }

    /**
     * 이용약관 동의 기록 로그
     *
     * @param User $user 동의한 사용자
     * @param array $data 동의 데이터
     * @param string|null $agreedAt 동의 시각 (DispatchHookListenerJob 역직렬화 과정에서 null 가능)
     * @param string|null $ip IP 주소
     */
    public function handleAuthRecordConsents(User $user, array $data, ?string $agreedAt = null, ?string $ip = null): void
    {
        $this->logActivity('auth.record_consents', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.auth_record_consents',
            'user_id' => $user->id,
            'ip_address' => $ip,
        ]);
    }

    /**
     * 로그인 실패 로그 기록 (사용자 존재 여부와 무관)
     *
     * @param string $email 시도된 이메일
     * @param array $context IP/UA/시각 등 부가 정보
     */
    public function handleAuthLoginFailed(string $email, array $context = []): void
    {
        $this->logActivity('auth.login_failed', [
            'description_key' => 'activity_log.description.auth_login_failed',
            'description_params' => ['email' => $email],
            'ip_address' => $context['ip_address'] ?? null,
        ]);
    }

    /**
     * 계정 잠금 로그 기록
     *
     * @param User $user 잠긴 사용자
     * @param array $context attempts/locked_until/lockout_minutes/IP
     */
    public function handleAuthAccountLocked(User $user, array $context = []): void
    {
        $this->logActivity('auth.account_locked', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.auth_account_locked',
            'description_params' => [
                'attempts' => $context['attempts'] ?? null,
                'minutes' => $context['lockout_minutes'] ?? null,
            ],
            'user_id' => $user->id,
            'ip_address' => $context['ip_address'] ?? null,
        ]);
    }

    // ═══════════════════════════════════════════
    // Role 핸들러
    // ═══════════════════════════════════════════

    /**
     * 역할 생성 후 로그 기록
     *
     * @param Model $role 생성된 역할
     */
    public function handleRoleAfterCreate(Model $role): void
    {
        $this->logActivity('role.create', [
            'loggable' => $role,
            'description_key' => 'activity_log.description.role_create',
            'description_params' => ['role_id' => $role->id],
            'properties' => ['name' => $role->name ?? null],
        ]);
    }

    /**
     * 역할 수정 후 로그 기록
     *
     * @param Model $role 수정된 역할
     * @param array|null $snapshot 수정 전 스냅샷
     */
    public function handleRoleAfterUpdate(Model $role, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($role, $snapshot);

        $this->logActivity('role.update', [
            'loggable' => $role,
            'description_key' => 'activity_log.description.role_update',
            'description_params' => ['role_id' => $role->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 역할 삭제 후 로그 기록
     *
     * @param int $roleId 삭제된 역할 ID
     */
    public function handleRoleAfterDelete(int $roleId): void
    {
        $this->logActivity('role.delete', [
            'description_key' => 'activity_log.description.role_delete',
            'description_params' => ['role_id' => $roleId],
            'properties' => ['role_id' => $roleId],
        ]);
    }

    /**
     * 역할 권한 동기화 후 로그 기록
     *
     * @param Model $role 역할
     * @param array $previousPermissions 이전 권한 목록
     * @param array $currentPermissions 현재 권한 목록
     */
    public function handleRoleAfterSyncPermissions(Model $role, array $previousPermissions, array $currentPermissions): void
    {
        $this->logActivity('role.sync_permissions', [
            'loggable' => $role,
            'description_key' => 'activity_log.description.role_sync_permissions',
            'description_params' => ['role_id' => $role->id],
            'properties' => [
                'added' => array_values(array_diff($currentPermissions, $previousPermissions)),
                'removed' => array_values(array_diff($previousPermissions, $currentPermissions)),
            ],
        ]);
    }

    /**
     * 역할 상태 전환 후 로그 기록
     *
     * @param Model $role 역할
     */
    public function handleRoleAfterToggleStatus(Model $role): void
    {
        $this->logActivity('role.toggle_status', [
            'loggable' => $role,
            'description_key' => 'activity_log.description.role_toggle_status',
            'description_params' => ['role_id' => $role->id],
        ]);
    }

    // ═══════════════════════════════════════════
    // Menu 핸들러
    // ═══════════════════════════════════════════

    /**
     * 메뉴 생성 후 로그 기록
     *
     * @param Model $menu 생성된 메뉴
     */
    public function handleMenuAfterCreate(Model $menu): void
    {
        $this->logActivity('menu.create', [
            'loggable' => $menu,
            'description_key' => 'activity_log.description.menu_create',
            'description_params' => ['menu_id' => $menu->id],
            'properties' => ['title' => $menu->title ?? null],
        ]);
    }

    /**
     * 메뉴 수정 후 로그 기록
     *
     * @param Model $menu 수정된 메뉴
     * @param array|null $snapshot 수정 전 스냅샷
     */
    public function handleMenuAfterUpdate(Model $menu, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($menu, $snapshot);

        $this->logActivity('menu.update', [
            'loggable' => $menu,
            'description_key' => 'activity_log.description.menu_update',
            'description_params' => ['menu_id' => $menu->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 메뉴 삭제 후 로그 기록
     *
     * @param int $menuId 삭제된 메뉴 ID
     */
    public function handleMenuAfterDelete(int $menuId): void
    {
        $this->logActivity('menu.delete', [
            'description_key' => 'activity_log.description.menu_delete',
            'description_params' => ['menu_id' => $menuId],
            'properties' => ['menu_id' => $menuId],
        ]);
    }

    /**
     * 메뉴 순서 변경 후 로그 기록
     *
     * @param array $orderData 순서 데이터
     */
    public function handleMenuAfterUpdateOrder(array $orderData): void
    {
        $this->logActivity('menu.update_order', [
            'description_key' => 'activity_log.description.menu_update_order',
        ]);
    }

    /**
     * 메뉴 상태 전환 후 로그 기록
     *
     * @param Model $menu 메뉴
     */
    public function handleMenuAfterToggleStatus(Model $menu): void
    {
        $this->logActivity('menu.toggle_status', [
            'loggable' => $menu,
            'description_key' => 'activity_log.description.menu_toggle_status',
            'description_params' => ['menu_id' => $menu->id],
        ]);
    }

    /**
     * 메뉴 역할 동기화 후 로그 기록
     *
     * @param Model $menu 메뉴
     * @param array $previousRoles 이전 역할 목록
     * @param array $currentRoles 현재 역할 목록
     */
    public function handleMenuAfterSyncRoles(Model $menu, array $previousRoles, array $currentRoles): void
    {
        $this->logActivity('menu.sync_roles', [
            'loggable' => $menu,
            'description_key' => 'activity_log.description.menu_sync_roles',
            'description_params' => ['menu_id' => $menu->id],
            'properties' => [
                'added' => array_values(array_diff($currentRoles, $previousRoles)),
                'removed' => array_values(array_diff($previousRoles, $currentRoles)),
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Settings 핸들러
    // ═══════════════════════════════════════════

    /**
     * 설정 저장 후 로그 기록
     *
     * @param string $tab 설정 탭
     * @param array $settings 저장된 설정
     * @param bool $result 저장 결과
     */
    public function handleSettingsAfterSave(string $tab, array $settings, bool $result): void
    {
        $this->logActivity('settings.save', [
            'description_key' => 'activity_log.description.settings_save',
            'properties' => ['tab' => $tab, 'keys' => array_keys($settings)],
        ]);
    }

    /**
     * 개별 설정 변경 후 로그 기록
     *
     * @param string $key 설정 키
     * @param mixed $value 설정 값
     * @param bool $result 저장 결과
     */
    public function handleSettingsAfterSet(string $key, mixed $value, bool $result): void
    {
        $this->logActivity('settings.update', [
            'description_key' => 'activity_log.description.settings_update',
            'properties' => ['key' => $key],
        ]);
    }

    // ═══════════════════════════════════════════
    // Schedule 핸들러
    // ═══════════════════════════════════════════

    /**
     * 스케줄 생성 후 로그 기록
     *
     * @param Model $schedule 생성된 스케줄
     */
    public function handleScheduleAfterCreate(Model $schedule): void
    {
        $this->logActivity('schedule.create', [
            'loggable' => $schedule,
            'description_key' => 'activity_log.description.schedule_create',
            'description_params' => ['schedule_id' => $schedule->id],
        ]);
    }

    /**
     * 스케줄 수정 후 로그 기록
     *
     * @param Model $schedule 수정된 스케줄
     * @param array|null $snapshot 수정 전 스냅샷
     */
    public function handleScheduleAfterUpdate(Model $schedule, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($schedule, $snapshot);

        $this->logActivity('schedule.update', [
            'loggable' => $schedule,
            'description_key' => 'activity_log.description.schedule_update',
            'description_params' => ['schedule_id' => $schedule->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 스케줄 삭제 후 로그 기록
     *
     * @param int $scheduleId 삭제된 스케줄 ID
     */
    public function handleScheduleAfterDelete(int $scheduleId): void
    {
        $this->logActivity('schedule.delete', [
            'description_key' => 'activity_log.description.schedule_delete',
            'description_params' => ['schedule_id' => $scheduleId],
            'properties' => ['schedule_id' => $scheduleId],
        ]);
    }

    /**
     * 스케줄 수동 실행 후 로그 기록
     *
     * @param Model $schedule 실행된 스케줄
     * @param Model $history 실행 이력
     */
    public function handleScheduleAfterRun(Model $schedule, Model $history): void
    {
        $this->logActivity('schedule.run', [
            'loggable' => $schedule,
            'description_key' => 'activity_log.description.schedule_run',
            'description_params' => ['schedule_id' => $schedule->id],
        ]);
    }

    /**
     * 스케줄 일괄 상태 변경 후 로그 기록
     *
     * @param array $ids 대상 ID 목록
     * @param bool $isActive 활성화 여부
     * @param int $updatedCount 변경된 수
     * @param array $snapshots 변경 전 스냅샷 (id => snapshot)
     */
    public function handleScheduleAfterBulkUpdate(array $ids, bool $isActive, int $updatedCount, array $snapshots = []): void
    {
        $schedules = $this->scheduleRepository->findManyByIdsKeyed($ids);

        foreach ($ids as $id) {
            $schedule = $schedules->get($id);
            if (! $schedule) {
                continue;
            }

            $snapshot = $snapshots[$id] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($schedule, $snapshot) : null;

            $this->logActivity('schedule.bulk_update', [
                'loggable' => $schedule,
                'description_key' => 'activity_log.description.schedule_bulk_update_status',
                'description_params' => ['count' => 1],
                'properties' => ['schedule_id' => $id, 'is_active' => $isActive],
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 스케줄 일괄 삭제 후 로그 기록
     *
     * @param array $ids 대상 ID 목록
     * @param int $deletedCount 삭제된 수
     * @param array $snapshots 삭제 전 스냅샷 (id => snapshot)
     */
    public function handleScheduleAfterBulkDelete(array $ids, int $deletedCount, array $snapshots = []): void
    {
        foreach ($ids as $id) {
            $snapshot = $snapshots[$id] ?? null;

            $this->logActivity('schedule.bulk_delete', [
                'loggable_type' => Schedule::class,
                'loggable_id' => $id,
                'description_key' => 'activity_log.description.schedule_bulk_delete',
                'description_params' => ['count' => 1],
                'properties' => [
                    'schedule_id' => $id,
                    'snapshot' => $snapshot,
                ],
            ]);
        }
    }

    // ═══════════════════════════════════════════
    // Attachment 핸들러
    // ═══════════════════════════════════════════

    /**
     * 첨부파일 업로드 후 로그 기록
     *
     * @param Model $attachment 업로드된 첨부파일
     */
    public function handleAttachmentAfterUpload(Model $attachment): void
    {
        $this->logActivity('attachment.upload', [
            'loggable' => $attachment,
            'description_key' => 'activity_log.description.attachment_upload',
        ]);
    }

    /**
     * 첨부파일 삭제 후 로그 기록
     *
     * @param Model|null $attachment 삭제된 첨부파일 (DispatchHookListenerJob 역직렬화 과정에서 null 가능)
     */
    public function handleAttachmentAfterDelete(?Model $attachment = null): void
    {
        $this->logActivity('attachment.delete', [
            'description_key' => 'activity_log.description.attachment_delete',
        ]);
    }

    /**
     * 첨부파일 일괄 삭제 후 로그 기록 (per-item)
     *
     * @param string $identifier 식별자
     * @param int $count 삭제된 수
     * @param array $ids 삭제된 첨부파일 ID 목록
     * @param array $snapshots 삭제 전 스냅샷 (keyBy id)
     */
    public function handleAttachmentAfterBulkDelete(string $identifier, int $count, array $ids = [], array $snapshots = []): void
    {
        foreach ($ids as $id) {
            $snapshot = $snapshots[$id] ?? null;

            $this->logActivity('attachment.bulk_delete', [
                'loggable_type' => Attachment::class,
                'loggable_id' => $id,
                'description_key' => 'activity_log.description.attachment_bulk_delete',
                'description_params' => ['count' => 1],
                'properties' => [
                    'attachment_id' => $id,
                    'identifier' => $identifier,
                    'snapshot' => $snapshot,
                ],
            ]);
        }
    }

    // ═══════════════════════════════════════════
    // Module 핸들러
    // ═══════════════════════════════════════════

    /**
     * 모듈 설치 후 로그 기록
     *
     * @param string $moduleName 모듈 식별자
     * @param array $moduleInfo 모듈 정보
     */
    public function handleModuleAfterInstall(string $moduleName, array $moduleInfo): void
    {
        $this->logActivity('module.install', [
            'description_key' => 'activity_log.description.module_install',
            'description_params' => ['module_name' => $moduleName],
            'properties' => ['identifier' => $moduleName, 'version' => $moduleInfo['version'] ?? null],
        ]);
    }

    /**
     * 모듈 활성화 후 로그 기록
     *
     * @param string $moduleName 모듈 식별자
     * @param array $moduleInfo 모듈 정보
     */
    public function handleModuleAfterActivate(string $moduleName, array $moduleInfo): void
    {
        $this->logActivity('module.activate', [
            'description_key' => 'activity_log.description.module_activate',
            'description_params' => ['module_name' => $moduleName],
        ]);
    }

    /**
     * 모듈 비활성화 후 로그 기록
     *
     * @param string $moduleName 모듈 식별자
     */
    public function handleModuleAfterDeactivate(string $moduleName): void
    {
        $this->logActivity('module.deactivate', [
            'description_key' => 'activity_log.description.module_deactivate',
            'description_params' => ['module_name' => $moduleName],
        ]);
    }

    /**
     * 모듈 제거 후 로그 기록
     *
     * @param string $moduleName 모듈 식별자
     * @param array $moduleInfo 모듈 정보
     * @param bool $deleteData 데이터 삭제 여부
     */
    public function handleModuleAfterUninstall(string $moduleName, array $moduleInfo, bool $deleteData): void
    {
        $this->logActivity('module.uninstall', [
            'description_key' => 'activity_log.description.module_uninstall',
            'description_params' => ['module_name' => $moduleName],
            'properties' => ['identifier' => $moduleName, 'delete_data' => $deleteData],
        ]);
    }

    /**
     * 모듈 업데이트 후 로그 기록
     *
     * @param string $moduleName 모듈 식별자
     * @param array $result 업데이트 결과 배열 (['success' => bool, ...])
     * @param array $moduleInfo 모듈 정보
     */
    public function handleModuleAfterUpdate(string $moduleName, array $result, array $moduleInfo): void
    {
        $this->logActivity('module.update', [
            'description_key' => 'activity_log.description.module_update',
            'description_params' => ['module_name' => $moduleName],
            'properties' => ['identifier' => $moduleName, 'result' => $result['success'] ?? false],
        ]);
    }

    /**
     * 모듈 레이아웃 갱신 후 로그 기록
     *
     * @param string $moduleName 모듈 식별자
     * @param array $result 갱신 결과
     */
    public function handleModuleAfterRefreshLayouts(string $moduleName, array $result): void
    {
        $this->logActivity('module.refresh_layouts', [
            'description_key' => 'activity_log.description.module_refresh_layouts',
            'description_params' => ['module_name' => $moduleName],
        ]);
    }

    // ═══════════════════════════════════════════
    // Plugin 핸들러
    // ═══════════════════════════════════════════

    /**
     * 플러그인 설치 후 로그 기록
     *
     * @param string $pluginName 플러그인 식별자
     * @param array $pluginInfo 플러그인 정보
     */
    public function handlePluginAfterInstall(string $pluginName, array $pluginInfo): void
    {
        $this->logActivity('plugin.install', [
            'description_key' => 'activity_log.description.plugin_install',
            'description_params' => ['plugin_name' => $pluginName],
            'properties' => ['identifier' => $pluginName, 'version' => $pluginInfo['version'] ?? null],
        ]);
    }

    /**
     * 플러그인 활성화 후 로그 기록
     *
     * @param string $pluginName 플러그인 식별자
     * @param array $pluginInfo 플러그인 정보
     */
    public function handlePluginAfterActivate(string $pluginName, array $pluginInfo): void
    {
        $this->logActivity('plugin.activate', [
            'description_key' => 'activity_log.description.plugin_activate',
            'description_params' => ['plugin_name' => $pluginName],
        ]);
    }

    /**
     * 플러그인 비활성화 후 로그 기록
     *
     * @param string $pluginName 플러그인 식별자
     */
    public function handlePluginAfterDeactivate(string $pluginName): void
    {
        $this->logActivity('plugin.deactivate', [
            'description_key' => 'activity_log.description.plugin_deactivate',
            'description_params' => ['plugin_name' => $pluginName],
        ]);
    }

    /**
     * 플러그인 제거 후 로그 기록
     *
     * @param string $pluginName 플러그인 식별자
     * @param bool $deleteData 데이터 삭제 여부
     * @param bool $result 제거 결과
     */
    public function handlePluginAfterUninstall(string $pluginName, bool $deleteData, bool $result): void
    {
        $this->logActivity('plugin.uninstall', [
            'description_key' => 'activity_log.description.plugin_uninstall',
            'description_params' => ['plugin_name' => $pluginName],
            'properties' => ['identifier' => $pluginName, 'delete_data' => $deleteData],
        ]);
    }

    /**
     * 플러그인 업데이트 후 로그 기록
     *
     * @param string $pluginName 플러그인 식별자
     * @param array $result 업데이트 결과 배열 (['success' => bool, ...])
     * @param array $pluginInfo 플러그인 정보
     */
    public function handlePluginAfterUpdate(string $pluginName, array $result, array $pluginInfo): void
    {
        $this->logActivity('plugin.update', [
            'description_key' => 'activity_log.description.plugin_update',
            'description_params' => ['plugin_name' => $pluginName],
            'properties' => ['identifier' => $pluginName, 'result' => $result['success'] ?? false],
        ]);
    }

    // ═══════════════════════════════════════════
    // Template 핸들러
    // ═══════════════════════════════════════════

    /**
     * 템플릿 설치 후 로그 기록
     *
     * @param string $identifier 템플릿 식별자
     * @param array $templateInfo 템플릿 정보
     */
    public function handleTemplateAfterInstall(string $identifier, array $templateInfo): void
    {
        $this->logActivity('template.install', [
            'description_key' => 'activity_log.description.template_install',
            'description_params' => ['template_name' => $identifier],
            'properties' => ['identifier' => $identifier, 'version' => $templateInfo['version'] ?? null],
        ]);
    }

    /**
     * 템플릿 활성화 후 로그 기록
     *
     * @param array $templateInfo 템플릿 정보
     */
    public function handleTemplateAfterActivate(array $templateInfo): void
    {
        $this->logActivity('template.activate', [
            'description_key' => 'activity_log.description.template_activate',
            'description_params' => ['template_name' => $templateInfo['identifier'] ?? ''],
        ]);
    }

    /**
     * 템플릿 비활성화 후 로그 기록
     *
     * @param string $identifier 템플릿 식별자
     */
    public function handleTemplateAfterDeactivate(string $identifier): void
    {
        $this->logActivity('template.deactivate', [
            'description_key' => 'activity_log.description.template_deactivate',
            'description_params' => ['template_name' => $identifier],
        ]);
    }

    /**
     * 템플릿 제거 후 로그 기록
     *
     * @param string $identifier 템플릿 식별자
     * @param array $templateInfo 템플릿 정보
     * @param bool $deleteData 데이터 삭제 여부
     */
    public function handleTemplateAfterUninstall(string $identifier, array $templateInfo, bool $deleteData): void
    {
        $this->logActivity('template.uninstall', [
            'description_key' => 'activity_log.description.template_uninstall',
            'description_params' => ['template_name' => $identifier],
            'properties' => ['identifier' => $identifier, 'delete_data' => $deleteData],
        ]);
    }

    /**
     * 템플릿 버전 업데이트 후 로그 기록
     *
     * @param string $templateName 템플릿 식별자
     * @param array $result 업데이트 결과
     * @param array $templateInfo 템플릿 정보
     */
    public function handleTemplateAfterVersionUpdate(string $templateName, array $result, array $templateInfo): void
    {
        $this->logActivity('template.version_update', [
            'description_key' => 'activity_log.description.template_version_update',
            'description_params' => ['template_name' => $templateName],
            'properties' => ['identifier' => $templateName, 'result' => $result['success'] ?? false],
        ]);
    }

    /**
     * 템플릿 레이아웃 갱신 후 로그 기록
     *
     * @param string $identifier 템플릿 식별자
     * @param array $result 갱신 결과
     */
    public function handleTemplateAfterRefreshLayouts(string $identifier, array $result): void
    {
        $this->logActivity('template.refresh_layouts', [
            'description_key' => 'activity_log.description.template_refresh_layouts',
            'description_params' => ['template_name' => $identifier],
        ]);
    }

    // ═══════════════════════════════════════════
    // Layout 핸들러
    // ═══════════════════════════════════════════

    /**
     * 레이아웃 수정 후 로그 기록
     *
     * @param Model $layout 수정된 레이아웃
     * @param int $templateId 템플릿 ID
     * @param string $name 레이아웃 이름
     * @param array $data 수정 데이터
     */
    public function handleLayoutAfterUpdate(Model $layout, int $templateId, string $name, array $data): void
    {
        $this->logActivity('layout.update', [
            'loggable' => $layout,
            'description_key' => 'activity_log.description.layout_update',
            'description_params' => ['layout_path' => $name],
        ]);
    }

    /**
     * 레이아웃 버전 복원 후 로그 기록
     *
     * @param Model $newVersion 복원된 새 버전
     * @param int $templateId 템플릿 ID
     * @param string $name 레이아웃 이름
     * @param int $versionId 복원 대상 버전 ID
     */
    public function handleLayoutAfterVersionRestore(Model $newVersion, int $templateId, string $name, int $versionId): void
    {
        $this->logActivity('layout.version_restore', [
            'description_key' => 'activity_log.description.layout_version_restore',
            'description_params' => ['layout_path' => $name],
            'properties' => ['version_id' => $versionId],
        ]);
    }

    // ═══════════════════════════════════════════
    // Module Settings 핸들러
    // ═══════════════════════════════════════════

    /**
     * 모듈 설정 저장 후 로그 기록
     *
     * @param string $identifier 모듈 식별자
     * @param array $settings 저장된 설정
     * @param bool $result 저장 결과
     */
    public function handleModuleSettingsAfterSave(string $identifier, array $settings, bool $result): void
    {
        $this->logActivity('module_settings.save', [
            'description_key' => 'activity_log.description.module_settings_save',
            'description_params' => ['module_name' => $identifier],
            'properties' => ['identifier' => $identifier, 'keys' => array_keys($settings)],
        ]);
    }

    /**
     * 모듈 설정 초기화 후 로그 기록
     *
     * @param string $identifier 모듈 식별자
     */
    public function handleModuleSettingsAfterReset(string $identifier): void
    {
        $this->logActivity('module_settings.reset', [
            'description_key' => 'activity_log.description.module_settings_reset',
            'description_params' => ['module_name' => $identifier],
        ]);
    }

    // ═══════════════════════════════════════════
    // Plugin Settings 핸들러
    // ═══════════════════════════════════════════

    /**
     * 플러그인 설정 저장 후 로그 기록
     *
     * @param string $identifier 플러그인 식별자
     * @param array $settings 저장된 설정
     * @param bool $result 저장 결과
     */
    public function handlePluginSettingsAfterSave(string $identifier, array $settings, bool $result): void
    {
        $this->logActivity('plugin_settings.save', [
            'description_key' => 'activity_log.description.plugin_settings_save',
            'description_params' => ['plugin_name' => $identifier],
            'properties' => ['identifier' => $identifier, 'keys' => array_keys($settings)],
        ]);
    }

    /**
     * 플러그인 설정 초기화 후 로그 기록
     *
     * @param string $identifier 플러그인 식별자
     */
    public function handlePluginSettingsAfterReset(string $identifier): void
    {
        $this->logActivity('plugin_settings.reset', [
            'description_key' => 'activity_log.description.plugin_settings_reset',
            'description_params' => ['plugin_name' => $identifier],
        ]);
    }

    // ─────────────────────────────────────────────
    // IdentityVerification (IDV) 핸들러
    // ─────────────────────────────────────────────

    /**
     * 본인인증 challenge 요청 후 로그 기록.
     *
     * @param  \App\Extension\IdentityVerification\DTO\VerificationChallenge  $challenge
     * @param  string  $purpose
     * @param  \App\Models\User|array  $target
     * @param  array  $context
     */
    public function handleIdentityRequested($challenge, string $purpose, $target, array $context = []): void
    {
        $this->logActivity('identity.request', [
            'description_key' => 'identity.logs.activity.requested',
            'description_params' => [
                'email' => is_object($target) ? ($target->email ?? '') : (string) ($target['email'] ?? ''),
            ],
            'properties' => [
                'provider_id' => $challenge->providerId ?? null,
                'purpose' => $purpose,
                'render_hint' => $challenge->renderHint ?? null,
            ],
        ]);
    }

    /**
     * 본인인증 검증 후 로그 기록 (성공·실패 공통 디스패치).
     *
     * @param  \App\Extension\IdentityVerification\DTO\VerificationResult  $result
     * @param  \App\Models\IdentityVerificationLog|null  $log
     * @param  array  $context
     */
    public function handleIdentityVerified($result, $log, array $context = []): void
    {
        $action = $result->success ? 'identity.verify' : 'identity.verify_failed';
        $key = $result->success
            ? 'identity.logs.activity.verified'
            : 'identity.logs.activity.failed';

        $this->logActivity($action, [
            'description_key' => $key,
            'description_params' => [],
            'properties' => [
                'provider_id' => $result->providerId ?? null,
                'challenge_id' => $result->challengeId ?? null,
                'failure_code' => $result->failureCode ?? null,
            ],
        ]);
    }

    /**
     * 본인인증 challenge 만료 로그 기록.
     *
     * @param  \App\Models\IdentityVerificationLog  $log
     */
    public function handleIdentityExpired($log): void
    {
        $this->logActivity('identity.expired', [
            'description_key' => 'identity.logs.activity.expired',
            'description_params' => [],
            'properties' => [
                'provider_id' => $log->provider_id ?? null,
                'purpose' => $log->purpose ?? null,
            ],
        ]);
    }

}
