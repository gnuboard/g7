<?php

namespace App\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\ActivityLogType;
use App\Enums\PrivacyManualRunStatus;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\Attachment;
use App\Models\IdentityVerificationLog;
use App\Models\PrivacyManualRun;
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
     * @param  RoleRepositoryInterface  $roleRepository  역할 Repository (역할 ID → 식별자)
     */
    public function __construct(
        protected ActivityLogRepositoryInterface $activityLogRepository,
        protected UserRepositoryInterface $userRepository,
        protected ScheduleRepositoryInterface $scheduleRepository,
        protected RoleRepositoryInterface $roleRepository,
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
            'core.user.after_bulk_update_roles' => ['method' => 'handleUserAfterBulkUpdateRoles', 'priority' => 20],
            // 회원 등록·수정 화면에서 역할·최고관리자 지정이 바뀐 경우(#736 D23) — 권한 부여·변경 기록이라 3년 보관 대상
            'core.user.after_update_roles' => ['method' => 'handleUserAfterUpdateRoles', 'priority' => 20],

            // ─── Auth ───
            'core.auth.after_login' => ['method' => 'handleAuthAfterLogin', 'priority' => 20],
            'core.auth.logout' => ['method' => 'handleAuthLogout', 'priority' => 20],
            // AuthService 가 실제로 발화하는 이름. 옛 이름(core.auth.register·forgot_password·reset_password)으로
            // 구독해 세 사건의 로그가 한 번도 남지 않았다(#138 후속).
            'core.auth.after_register' => ['method' => 'handleAuthRegister', 'priority' => 20],
            // 두 번째 인자에 재설정 토큰이 든 URL 이 실린다 — 큐로 보내면 토큰이 작업 테이블에 한 부 더 저장되므로 동기 실행
            'core.auth.after_reset_password_request' => ['method' => 'handleAuthForgotPassword', 'priority' => 20, 'sync' => true],
            // 비밀번호 변경은 경로·행위자를 아는 기록 훅에서 남긴다(#736). 종전 `after_password_changed` 구독은
            // 인자가 회원뿐이라 관리자 지정·로그인 단계 변경까지 전부 "비밀번호 재설정" 으로 적었다.
            'core.auth.password_change_recorded' => ['method' => 'handlePasswordChangeRecorded', 'priority' => 20],
            'core.auth.password_change_postponed' => ['method' => 'handlePasswordChangePostponed', 'priority' => 20],
            'core.auth.password_expired' => ['method' => 'handlePasswordExpired', 'priority' => 20],
            'core.auth.record_consents' => ['method' => 'handleAuthRecordConsents', 'priority' => 20],
            'core.auth.login_failed' => ['method' => 'handleAuthLoginFailed', 'priority' => 20],
            'core.auth.account_locked' => ['method' => 'handleAuthAccountLocked', 'priority' => 20],
            'core.auth.account_unlocked' => ['method' => 'handleAuthAccountUnlocked', 'priority' => 20],

            // ─── IdentityVerification (IDV) ───
            // DTO(VerificationChallenge/VerificationResult) 를 인자로 받으므로 sync 실행 필수
            // (큐 직렬화 대상에 POPO 는 포함되지 않아 queue 시 null 전달됨)
            'core.identity.after_request' => ['method' => 'handleIdentityRequested', 'priority' => 20, 'sync' => true],
            'core.identity.after_verify' => ['method' => 'handleIdentityVerified', 'priority' => 20, 'sync' => true],
            'core.identity.challenge_expired' => ['method' => 'handleIdentityExpired', 'priority' => 20, 'sync' => true],

            // ─── MemberNotification (회원 알림함 — 관리자 조회·삭제) ───
            // 관리자가 남의 수신함을 지우는 행위다. 무엇이 사라졌는지 남기지 않으면
            // 회원이 «알림이 없어졌다» 고 물었을 때 되짚을 근거가 없다.
            'core.member_notification.after_bulk_delete' => ['method' => 'handleMemberNotificationAfterBulkDelete', 'priority' => 20],

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

            // ─── Privacy (관리자 직권 실행) ───
            'core.privacy.manual_run_queued' => ['method' => 'handlePrivacyManualRunQueued', 'priority' => 20],
            'core.privacy.manual_run_finished' => ['method' => 'handlePrivacyManualRunFinished', 'priority' => 20],

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
            'core.custom_assets.after_change' => ['method' => 'handleCustomAssetAfterChange', 'priority' => 20],

            // ─── Layout ───
            'core.layout.after_update' => ['method' => 'handleLayoutAfterUpdate', 'priority' => 20],
            'core.layout.after_version_restore' => ['method' => 'handleLayoutAfterVersionRestore', 'priority' => 20],
            'core.layout.after_create' => ['method' => 'handleLayoutAfterCreate', 'priority' => 20],
            'core.layout.after_rename' => ['method' => 'handleLayoutAfterRename', 'priority' => 20],
            'core.layout.after_delete' => ['method' => 'handleLayoutAfterDelete', 'priority' => 20],

            // ─── Module Settings ───
            'core.module_settings.after_save' => ['method' => 'handleModuleSettingsAfterSave', 'priority' => 20],
            'core.module_settings.after_reset' => ['method' => 'handleModuleSettingsAfterReset', 'priority' => 20],

            // ─── Plugin Settings ───
            'core.plugin_settings.after_save' => ['method' => 'handlePluginSettingsAfterSave', 'priority' => 20],
            'core.plugin_settings.after_reset' => ['method' => 'handlePluginSettingsAfterReset', 'priority' => 20],

            // ─── Analytics ───
            // 운영자가 방문 데이터에 손대는 행위만 남긴다. 수집 자체(초당 여러 건)는
            // 활동 로그에 넣지 않는다 — 감사 기록이 통계 트래픽에 묻힌다.
            // 동기(sync)여야 한다 — 아홉 훅이 한 메서드를 나눠 쓰며 어느 훅인지를 실행 중인
            // 훅 이름으로 가린다(handleAnalyticsHook). 큐 워커는 그 이름을 되살리지 않아
            // 기본 큐(database)에서는 감사 기록이 한 줄도 남지 않는다.
            'core.analytics.settings_updated' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
            'core.analytics.privacy_mode_changed' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
            'core.analytics.data_purged' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
            'core.analytics.anonymized' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
            'core.analytics.subject_exported' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
            'core.analytics.subject_deleted' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
            'core.analytics.opt_out_changed' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
            'core.analytics.archive_requested' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
            'core.analytics.extension_purged' => ['method' => 'handleAnalyticsHook', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 반환값 없음
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
     * @param  User  $user  생성된 사용자
     * @param  array  $originalData  원본 요청 데이터
     * @return void 반환값 없음
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
     * @param  User  $user  수정된 사용자
     * @param  array  $originalData  원본 요청 데이터
     * @param  array|null  $snapshot  수정 전 스냅샷
     * @return void 반환값 없음
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
     * @param  array  $userData  삭제된 사용자 데이터
     * @return void 반환값 없음
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
     * @param  User  $user  탈퇴한 사용자
     * @return void 반환값 없음
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
     * @param  User  $user  조회된 사용자
     * @return void 반환값 없음
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
     * @param  int  $count  조회된 총 사용자 수
     * @return void 반환값 없음
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
     * @param  string  $query  검색어
     * @param  int  $count  검색 결과 수
     * @return void 반환값 없음
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
     * @param  array  $uuids  대상 UUID 목록
     * @param  string  $status  변경된 상태
     * @param  int  $updatedCount  변경된 수
     * @param  array  $snapshots  변경 전 스냅샷 (uuid => snapshot)
     * @return void 반환값 없음
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

    /**
     * 회원 역할 일괄 변경 후 회원마다 로그 기록
     *
     * @param  array<int, int>  $userIds  실제로 바뀐 회원 ID
     * @param  array<int, int>  $roleIds  요청한 역할 ID
     * @param  string  $mode  'add' | 'remove'
     * @param  array<int, array{user_id: int, added: array<int, int>, removed: array<int, int>}>  $changes  회원별 변경분
     * @return void 반환값 없음
     */
    public function handleUserAfterBulkUpdateRoles(array $userIds, array $roleIds, string $mode, array $changes = []): void
    {
        $users = $this->userRepository->findManyByIdsKeyed($userIds);

        foreach ($changes as $change) {
            $user = $users->get($change['user_id']);
            if (! $user) {
                continue;
            }

            $this->logActivity('user.bulk_roles', [
                'loggable' => $user,
                'description_key' => 'activity_log.description.user_bulk_roles',
                'description_params' => ['user_id' => $user->id],
                'properties' => [
                    'mode' => $mode,
                    'added' => array_values($change['added']),
                    'removed' => array_values($change['removed']),
                ],
            ]);
        }
    }

    /**
     * 회원 등록·수정으로 역할·최고관리자 지정이 바뀐 뒤 로그 기록 (#736 D23)
     *
     * 붙은·떨어진 역할은 식별자로 남긴다 — 역할 ID 는 역할을 지우고 다시 만들면 다른 역할을 가리킨다.
     * 지금은 없는 역할(기록 사이에 삭제)은 ID 문자열로 남긴다.
     *
     * @param  User  $user  대상 회원
     * @param  array{attached?: array<int, int>, detached?: array<int, int>, is_super?: array{from: bool, to: bool}|null}  $changes  변경분
     * @param  string  $source  'create' | 'update'
     * @return void 반환값 없음
     */
    public function handleUserAfterUpdateRoles(User $user, array $changes, string $source = 'update'): void
    {
        $attached = array_map('intval', $changes['attached'] ?? []);
        $detached = array_map('intval', $changes['detached'] ?? []);
        $identifiers = $this->roleRepository->getIdentifiersByIds(array_merge($attached, $detached));
        $toIdentifiers = static fn (array $ids): array => array_values(array_map(
            static fn (int $id): string => (string) ($identifiers[$id] ?? $id),
            $ids,
        ));

        $added = $toIdentifiers($attached);
        $removed = $toIdentifiers($detached);

        $this->logActivity('user.roles_update', [
            'loggable' => $user,
            'description_key' => 'activity_log.description.user_roles_update',
            'description_params' => [
                'user_id' => $user->id,
                'added' => $added === [] ? '-' : implode(', ', $added),
                'removed' => $removed === [] ? '-' : implode(', ', $removed),
            ],
            'properties' => [
                'source' => $source,
                'added' => $added,
                'removed' => $removed,
                'is_super' => $changes['is_super'] ?? null,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Auth 핸들러
    // ═══════════════════════════════════════════

    /**
     * 로그인 후 로그 기록 (사용자·관리자 로그인 공통 훅)
     *
     * 같은 훅이 사용자 로그인(`/api/auth/login…`)과 관리자 로그인(`/api/auth/admin/login…`)에서 모두 발화한다.
     * 설명은 들어온 경로로 고른다 — 사용자 로그인에도 「관리자 로그인」이 찍히던 문제(7.1.0 실측 O-B3).
     *
     * @param  User  $user  로그인한 사용자
     * @param  array  $loginData  로그인 데이터
     * @return void 반환값 없음
     */
    public function handleAuthAfterLogin(User $user, array $loginData): void
    {
        $this->logActivity('auth.login', [
            'loggable' => $user,
            'description_key' => $this->isAdminAuthRequest()
                ? 'activity_log.description.auth_admin_login'
                : 'activity_log.description.auth_login',
            'user_id' => $user->id,
        ]);
    }

    /**
     * 로그아웃 로그 기록 (사용자·관리자 로그아웃 공통 훅)
     *
     * @param  User  $user  로그아웃한 사용자
     * @return void 반환값 없음
     */
    public function handleAuthLogout(User $user): void
    {
        $this->logActivity('auth.logout', [
            'loggable' => $user,
            'description_key' => $this->isAdminAuthRequest()
                ? 'activity_log.description.auth_admin_logout'
                : 'activity_log.description.auth_logout',
            'user_id' => $user->id,
        ]);
    }

    /**
     * 지금 요청이 관리자 인증 경로인지 판정합니다.
     *
     * 관리자 로그인·2단계 인증·로그인 비밀번호 변경은 `api/auth/admin/*`, 관리자 로그아웃은 `api/admin/*` 이다.
     * 요청이 없으면(CLI) 관리자가 아니다.
     *
     * @return bool 관리자 인증 경로이면 true
     */
    private function isAdminAuthRequest(): bool
    {
        $request = request();

        return $request !== null && ($request->is('api/auth/admin/*') || $request->is('api/admin/*'));
    }

    /**
     * 회원가입 후 로그 기록
     *
     * @param  User  $user  등록된 사용자
     * @param  array  $registrationData  등록 데이터
     * @return void 반환값 없음
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
     * @param  User  $user  요청한 사용자
     * @return void 반환값 없음
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
     * 비밀번호 변경 기록 로그
     *
     * 경로(source)로 문구를 가른다 — 메일 재설정은 종전 `auth.reset_password`, 그 밖(본인 변경·관리자 지정·
     * 로그인 단계 변경)은 `auth.password_change`. 행위자는 훅이 넘긴 `actor_id` 를 쓴다(큐 실행이라 요청의
     * 인증 문맥보다 명시값이 정확하다). 관리자가 정해 준 경우 관리자가 한 일을 회원이 한 것으로 적지 않는다.
     *
     * @param  User  $user  비밀번호가 바뀐 회원
     * @param  array  $context  {source, actor_id, sessions_revoked, reason?}
     * @return void 반환값 없음
     */
    public function handlePasswordChangeRecorded(User $user, array $context = []): void
    {
        $source = (string) ($context['source'] ?? '');
        $actorId = $context['actor_id'] ?? $user->id;

        if ($source === 'reset') {
            $this->logActivity('auth.reset_password', [
                'loggable' => $user,
                'description_key' => 'activity_log.description.auth_reset_password',
                'user_id' => $actorId,
            ]);

            return;
        }

        [$descriptionKey, $params] = match ($source) {
            'admin' => ['activity_log.description.auth_password_change_by_admin', ['user_id' => $user->uuid]],
            'login_required' => [
                ($context['reason'] ?? null) === 'admin_assigned'
                    ? 'activity_log.description.auth_password_change_on_login_admin_assigned'
                    : 'activity_log.description.auth_password_change_on_login_expired',
                [],
            ],
            default => ['activity_log.description.auth_password_change_self', []],
        };

        $this->logActivity('auth.password_change', [
            'loggable' => $user,
            'log_type' => $source === 'admin' ? ActivityLogType::Admin : ActivityLogType::User,
            'description_key' => $descriptionKey,
            'description_params' => $params,
            'properties' => [
                'source' => $source,
                'sessions_revoked' => (int) ($context['sessions_revoked'] ?? 0),
            ],
            'user_id' => $actorId,
        ]);
    }

    /**
     * 비밀번호 만료 변경 연기 로그 ('다음에 변경 허용' 모드에서 회원이 연기를 고름)
     *
     * @param  User  $user  연기한 회원
     * @param  array  $context  {until}
     * @return void 반환값 없음
     */
    public function handlePasswordChangePostponed(User $user, array $context = []): void
    {
        $this->logActivity('auth.password_change_postpone', [
            'loggable' => $user,
            'log_type' => ActivityLogType::User,
            'description_key' => 'activity_log.description.auth_password_change_postpone',
            'description_params' => ['until' => (string) ($context['until_display'] ?? $context['until'] ?? '')],
            'user_id' => $user->id,
        ]);
    }

    /**
     * 비밀번호 만료일 처리 로그 — 로그인 토큰을 폐기한 경우에만 남긴다('변경해야 로그인' 모드)
     *
     * @param  User  $user  만료된 회원
     * @param  array  $context  {expired_at, sessions_revoked: 폐기한 토큰 수}
     * @return void 반환값 없음
     */
    public function handlePasswordExpired(User $user, array $context = []): void
    {
        if (empty($context['sessions_revoked'])) {
            return;
        }

        $this->logActivity('auth.password_expiry_logout', [
            'loggable' => $user,
            'log_type' => ActivityLogType::System,
            'description_key' => 'activity_log.description.auth_password_expiry_logout',
            'description_params' => ['user_id' => $user->uuid, 'count' => (int) $context['sessions_revoked']],
            'user_id' => null,
        ]);
    }

    /**
     * 이용약관 동의 기록 로그
     *
     * @param  User  $user  동의한 사용자
     * @param  array  $data  동의 데이터
     * @param  string|null  $agreedAt  동의 시각 (DispatchHookListenerJob 역직렬화 과정에서 null 가능)
     * @param  string|null  $ip  IP 주소
     * @return void 반환값 없음
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
     * 회원 알림함 일괄 삭제 로그 기록
     *
     * 훅은 루프 앞뒤로 한 번씩만 발화하므로 로그도 건당이 아니라 **일괄 1건**이다.
     * 지운 id 목록을 properties 에 실어 어느 알림이 사라졌는지 되짚을 수 있게 한다.
     *
     * @param  array<int, string>  $ids  삭제 요청된 알림 uuid 목록
     * @param  int  $count  실제로 삭제된 건수
     * @return void 반환값 없음
     */
    public function handleMemberNotificationAfterBulkDelete(array $ids, int $count): void
    {
        $this->logActivity('member_notification.bulk_delete', [
            'description_key' => 'activity_log.description.member_notification_bulk_delete',
            'description_params' => ['count' => $count],
            'properties' => [
                'ids' => array_values($ids),
                'requested_count' => count($ids),
                'deleted_count' => $count,
            ],
        ]);
    }

    /**
     * 로그인 실패 로그 기록 (사용자 존재 여부와 무관)
     *
     * @param  string  $email  시도된 이메일
     * @param  array  $context  IP/UA/시각 등 부가 정보
     * @return void 반환값 없음
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
     * @param  User  $user  잠긴 사용자
     * @param  array  $context  attempts/locked_until/lockout_minutes/IP
     * @return void 반환값 없음
     */
    public function handleAuthAccountLocked(User $user, array $context = []): void
    {
        // 영구 잠금(무한대)은 차단 분(minutes)이 없으므로 별도 문구를 사용한다.
        $permanent = ($context['permanent'] ?? false) || ($context['locked_until'] ?? null) === null;

        $this->logActivity('auth.account_locked', [
            'loggable' => $user,
            'description_key' => $permanent
                ? 'activity_log.description.auth_account_locked_permanently'
                : 'activity_log.description.auth_account_locked',
            'description_params' => [
                'attempts' => $context['attempts'] ?? null,
                'minutes' => $context['lockout_minutes'] ?? null,
                // 영어 문구 「1 attempt / N attempts」 를 화면 번역이 이 수로 고른다.
                'count' => $context['attempts'] ?? null,
            ],
            'user_id' => $user->id,
            'ip_address' => $context['ip_address'] ?? null,
        ]);
    }

    /**
     * 계정 잠금 해제 로그 기록 (관리자 수동 해제 / 비밀번호 재설정)
     *
     * @param  User  $user  잠금이 해제된 사용자
     * @param  array  $context  IP·해제 사유(`reason` = password_reset 이면 본인 재설정) 등 부가 정보
     * @return void 반환값 없음
     */
    public function handleAuthAccountUnlocked(User $user, array $context = []): void
    {
        $byPasswordReset = ($context['reason'] ?? null) === 'password_reset';

        $this->logActivity('auth.account_unlocked', [
            'loggable' => $user,
            'description_key' => $byPasswordReset
                ? 'activity_log.description.auth_account_unlocked_by_password_reset'
                : 'activity_log.description.auth_account_unlocked',
            'description_params' => ['email' => $user->email],
            'ip_address' => $context['ip_address'] ?? null,
        ]);
    }

    // ═══════════════════════════════════════════
    // Role 핸들러
    // ═══════════════════════════════════════════

    /**
     * 역할 생성 후 로그 기록
     *
     * @param  Model  $role  생성된 역할
     * @return void 반환값 없음
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
     * @param  Model  $role  수정된 역할
     * @param  array|null  $snapshot  수정 전 스냅샷
     * @return void 반환값 없음
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
     * @param  int  $roleId  삭제된 역할 ID
     * @return void 반환값 없음
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
     * @param  Model  $role  역할
     * @param  array  $previousPermissions  이전 권한 목록
     * @param  array  $currentPermissions  현재 권한 목록
     * @return void 반환값 없음
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
     * @param  Model  $role  역할
     * @return void 반환값 없음
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
     * @param  Model  $menu  생성된 메뉴
     * @return void 반환값 없음
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
     * @param  Model  $menu  수정된 메뉴
     * @param  array|null  $snapshot  수정 전 스냅샷
     * @return void 반환값 없음
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
     * @param  int  $menuId  삭제된 메뉴 ID
     * @return void 반환값 없음
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
     * @param  array  $orderData  순서 데이터
     * @return void 반환값 없음
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
     * @param  Model  $menu  메뉴
     * @return void 반환값 없음
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
     * @param  Model  $menu  메뉴
     * @param  array  $previousRoles  이전 역할 목록
     * @param  array  $currentRoles  현재 역할 목록
     * @return void 반환값 없음
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
     * @param  string  $tab  설정 탭
     * @param  array  $settings  저장된 설정
     * @param  bool  $result  저장 결과
     * @return void 반환값 없음
     */
    public function handleSettingsAfterSave(string $tab, array $settings, bool $result): void
    {
        $this->logActivity('settings.save', [
            'description_key' => 'activity_log.description.settings_save',
            'properties' => ['tab' => $tab, 'keys' => array_keys($settings)],
        ]);
    }

    /**
     * 개인정보 조치 직권 실행 접수 로그 기록
     *
     * 접수한 관리자의 요청 안에서 발행되므로 로그 유형은 요청자 기준(관리자)으로 정해진다.
     * 대상 이름은 접수 시점의 라벨 그대로 남긴다 — 확장이 나중에 비활성화돼도 무엇을 돌렸는지 읽힌다.
     *
     * @param  PrivacyManualRun  $run  접수된 기록
     * @param  string  $label  대상 이름
     * @return void 반환값 없음
     */
    public function handlePrivacyManualRunQueued(PrivacyManualRun $run, string $label): void
    {
        $this->logActivity('privacy.manual_run_queued', [
            'description_key' => $run->skip_waiting
                ? 'activity_log.description.privacy_manual_run_queued_skip_waiting'
                : 'activity_log.description.privacy_manual_run_queued',
            'description_params' => ['target' => $label],
            'properties' => [
                'run_id' => $run->id,
                'target' => $run->target,
                'skip_waiting' => (bool) $run->skip_waiting,
            ],
        ]);
    }

    /**
     * 개인정보 조치 직권 실행 종료(완료·실패) 로그 기록
     *
     * 큐 작업 안에서 발행되므로 인증 사용자가 없다 — 시스템 로그로 남기고 접수자를 속성에 적는다.
     *
     * @param  PrivacyManualRun  $run  종료된 기록
     * @param  string  $label  대상 이름
     * @return void 반환값 없음
     */
    public function handlePrivacyManualRunFinished(PrivacyManualRun $run, string $label): void
    {
        $failed = $run->status === PrivacyManualRunStatus::Failed;

        $this->logActivity('privacy.manual_run_finished', [
            'log_type' => ActivityLogType::System,
            'description_key' => $failed
                ? 'activity_log.description.privacy_manual_run_failed'
                : 'activity_log.description.privacy_manual_run_completed',
            'description_params' => [
                'target' => $label,
                'count' => (string) ($run->processed_count ?? 0),
            ],
            'properties' => [
                'run_id' => $run->id,
                'target' => $run->target,
                'status' => $run->status->value,
                'processed_count' => $run->processed_count,
                'remaining_count' => $run->remaining_count,
                'mode' => $run->mode,
                'requested_by' => $run->requested_by,
                'error' => $run->error,
            ],
        ]);
    }

    /**
     * 개별 설정 변경 후 로그 기록
     *
     * @param  string  $key  설정 키
     * @param  mixed  $value  설정 값
     * @param  bool  $result  저장 결과
     * @return void 반환값 없음
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
     * @param  Model  $schedule  생성된 스케줄
     * @return void 반환값 없음
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
     * @param  Model  $schedule  수정된 스케줄
     * @param  array|null  $snapshot  수정 전 스냅샷
     * @return void 반환값 없음
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
     * @param  int  $scheduleId  삭제된 스케줄 ID
     * @return void 반환값 없음
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
     * @param  Model  $schedule  실행된 스케줄
     * @param  Model  $history  실행 이력
     * @return void 반환값 없음
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
     * @param  array  $ids  대상 ID 목록
     * @param  bool  $isActive  활성화 여부
     * @param  int  $updatedCount  변경된 수
     * @param  array  $snapshots  변경 전 스냅샷 (id => snapshot)
     * @return void 반환값 없음
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
     * @param  array  $ids  대상 ID 목록
     * @param  int  $deletedCount  삭제된 수
     * @param  array  $snapshots  삭제 전 스냅샷 (id => snapshot)
     * @return void 반환값 없음
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
     * @param  Model  $attachment  업로드된 첨부파일
     * @return void 반환값 없음
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
     * @param  Model|null  $attachment  삭제된 첨부파일 (DispatchHookListenerJob 역직렬화 과정에서 null 가능)
     * @return void 반환값 없음
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
     * @param  string  $identifier  식별자
     * @param  int  $count  삭제된 수
     * @param  array  $ids  삭제된 첨부파일 ID 목록
     * @param  array  $snapshots  삭제 전 스냅샷 (keyBy id)
     * @return void 반환값 없음
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
     * @param  string  $moduleName  모듈 식별자
     * @param  array  $moduleInfo  모듈 정보
     * @return void 반환값 없음
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
     * @param  string  $moduleName  모듈 식별자
     * @param  array  $moduleInfo  모듈 정보
     * @return void 반환값 없음
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
     * @param  string  $moduleName  모듈 식별자
     * @return void 반환값 없음
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
     * @param  string  $moduleName  모듈 식별자
     * @param  array  $moduleInfo  모듈 정보
     * @param  bool  $deleteData  데이터 삭제 여부
     * @return void 반환값 없음
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
     * @param  string  $moduleName  모듈 식별자
     * @param  array  $result  업데이트 결과 배열 (['success' => bool, ...])
     * @param  array  $moduleInfo  모듈 정보
     * @return void 반환값 없음
     */
    public function handleModuleAfterUpdate(string $moduleName, array $result, array $moduleInfo): void
    {
        $this->logActivity('module.update', [
            'description_key' => 'activity_log.description.module_update',
            'description_params' => ['module_name' => $moduleName],
            'properties' => ['identifier' => $moduleName, 'result' => $result['success'] ?? false] + $this->layoutSyncProperties($result),
        ] + $this->layoutSyncChanges($result));
    }

    /**
     * 모듈 레이아웃 갱신 후 로그 기록
     *
     * @param  string  $moduleName  모듈 식별자
     * @param  array  $result  갱신 결과
     * @return void 반환값 없음
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
     * @param  string  $pluginName  플러그인 식별자
     * @param  array  $pluginInfo  플러그인 정보
     * @return void 반환값 없음
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
     * @param  string  $pluginName  플러그인 식별자
     * @param  array  $pluginInfo  플러그인 정보
     * @return void 반환값 없음
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
     * @param  string  $pluginName  플러그인 식별자
     * @return void 반환값 없음
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
     * @param  string  $pluginName  플러그인 식별자
     * @param  bool  $deleteData  데이터 삭제 여부
     * @param  bool  $result  제거 결과
     * @return void 반환값 없음
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
     * @param  string  $pluginName  플러그인 식별자
     * @param  array  $result  업데이트 결과 배열 (['success' => bool, ...])
     * @param  array  $pluginInfo  플러그인 정보
     * @return void 반환값 없음
     */
    public function handlePluginAfterUpdate(string $pluginName, array $result, array $pluginInfo): void
    {
        $this->logActivity('plugin.update', [
            'description_key' => 'activity_log.description.plugin_update',
            'description_params' => ['plugin_name' => $pluginName],
            'properties' => ['identifier' => $pluginName, 'result' => $result['success'] ?? false] + $this->layoutSyncProperties($result),
        ] + $this->layoutSyncChanges($result));
    }

    // ═══════════════════════════════════════════
    // Template 핸들러
    // ═══════════════════════════════════════════

    /**
     * 템플릿 설치 후 로그 기록
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  array  $templateInfo  템플릿 정보
     * @return void 반환값 없음
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
     * @param  array  $templateInfo  템플릿 정보
     * @return void 반환값 없음
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
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
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
     * @param  string  $identifier  템플릿 식별자
     * @param  array  $templateInfo  템플릿 정보
     * @param  bool  $deleteData  데이터 삭제 여부
     * @return void 반환값 없음
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
     * @param  string  $templateName  템플릿 식별자
     * @param  array  $result  업데이트 결과
     * @param  array  $templateInfo  템플릿 정보
     * @return void 반환값 없음
     */
    public function handleTemplateAfterVersionUpdate(string $templateName, array $result, array $templateInfo): void
    {
        $this->logActivity('template.version_update', [
            'description_key' => 'activity_log.description.template_version_update',
            'description_params' => ['template_name' => $templateName],
            'properties' => ['identifier' => $templateName, 'result' => $result['success'] ?? false] + $this->layoutSyncProperties($result),
        ] + $this->layoutSyncChanges($result));
    }

    /**
     * 템플릿 레이아웃 갱신 후 로그 기록
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  array  $result  갱신 결과
     * @return void 반환값 없음
     */
    public function handleTemplateAfterRefreshLayouts(string $identifier, array $result): void
    {
        $this->logActivity('template.refresh_layouts', [
            'description_key' => 'activity_log.description.template_refresh_layouts',
            'description_params' => ['template_name' => $identifier],
        ]);
    }

    /**
     * 사용자 추가 에셋(`custom/`) 변경 후 로그 기록
     *
     * @param  string  $extensionType  확장 타입 (`templates` 등)
     * @param  string  $identifier  확장 식별자
     * @param  string  $operation  수행한 작업 (`save` | `upload` | `delete` | `rename`)
     * @param  string  $path  `custom/` 기준 상대 경로
     * @return void 반환값 없음
     */
    public function handleCustomAssetAfterChange(
        string $extensionType,
        string $identifier,
        string $operation,
        string $path
    ): void {
        $this->logActivity('custom_asset.'.$operation, [
            'description_key' => 'activity_log.description.custom_asset_'.$operation,
            'description_params' => ['identifier' => $identifier, 'path' => $path],
            'properties' => [
                'extension_type' => $extensionType,
                'identifier' => $identifier,
                'path' => $path,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Layout 핸들러
    // ═══════════════════════════════════════════

    /**
     * 레이아웃 수정 후 로그 기록
     *
     * @param  Model  $layout  수정된 레이아웃
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @param  array  $data  수정 데이터
     * @return void 반환값 없음
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
     * @param  Model  $newVersion  복원된 새 버전
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @param  int  $versionId  복원 대상 버전 ID
     * @return void 반환값 없음
     */
    public function handleLayoutAfterVersionRestore(Model $newVersion, int $templateId, string $name, int $versionId): void
    {
        $this->logActivity('layout.version_restore', [
            'description_key' => 'activity_log.description.layout_version_restore',
            'description_params' => ['layout_path' => $name],
            'properties' => ['version_id' => $versionId],
        ]);
    }

    /**
     * 레이아웃 파일 생성 후 로그 기록
     *
     * @param  Model  $layout  만들어진(또는 되살린) 레이아웃
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return void 반환값 없음
     */
    public function handleLayoutAfterCreate(Model $layout, int $templateId, string $name): void
    {
        $this->logActivity('layout.create', [
            'loggable' => $layout,
            'description_key' => 'activity_log.description.layout_create',
            'description_params' => ['layout_path' => $name],
            'properties' => ['template_id' => $templateId],
        ]);
    }

    /**
     * 레이아웃 파일 이름변경 후 로그 기록
     *
     * @param  Model  $layout  옮겨진 레이아웃 (같은 행)
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  옛 이름
     * @param  string  $newName  새 이름
     * @return void 반환값 없음
     */
    public function handleLayoutAfterRename(Model $layout, int $templateId, string $name, string $newName): void
    {
        $this->logActivity('layout.rename', [
            'loggable' => $layout,
            'description_key' => 'activity_log.description.layout_rename',
            'description_params' => ['layout_path' => $name, 'new_path' => $newName],
            'properties' => ['template_id' => $templateId, 'from' => $name, 'to' => $newName],
        ]);
    }

    /**
     * 레이아웃 파일 삭제(soft delete) 후 로그 기록
     *
     * 파괴적 연산이라 누가 언제 무엇을 지웠는지가 남아야 되돌릴 근거가 된다.
     *
     * @param  Model  $layout  지워진 레이아웃 (행은 남는다)
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return void 반환값 없음
     */
    public function handleLayoutAfterDelete(Model $layout, int $templateId, string $name): void
    {
        $this->logActivity('layout.delete', [
            'loggable' => $layout,
            'description_key' => 'activity_log.description.layout_delete',
            'description_params' => ['layout_path' => $name],
            'properties' => ['template_id' => $templateId],
        ]);
    }

    // ═══════════════════════════════════════════
    // Module Settings 핸들러
    // ═══════════════════════════════════════════

    /**
     * 모듈 설정 저장 후 로그 기록
     *
     * @param  string  $identifier  모듈 식별자
     * @param  array  $settings  저장된 설정
     * @param  bool  $result  저장 결과
     * @return void 반환값 없음
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
     * @param  string  $identifier  모듈 식별자
     * @return void 반환값 없음
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
     * @param  string  $identifier  플러그인 식별자
     * @param  array  $settings  저장된 설정
     * @param  bool  $result  저장 결과
     * @return void 반환값 없음
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
     * @param  string  $identifier  플러그인 식별자
     * @return void 반환값 없음
     */
    public function handlePluginSettingsAfterReset(string $identifier): void
    {
        $this->logActivity('plugin_settings.reset', [
            'description_key' => 'activity_log.description.plugin_settings_reset',
            'description_params' => ['plugin_name' => $identifier],
        ]);
    }

    // ─────────────────────────────────────────────
    // 방문자 통계 핸들러
    // ─────────────────────────────────────────────

    /**
     * 방문자 통계 운영 행위를 활동 로그에 남깁니다.
     *
     * 아홉 개 훅이 이 메서드 하나를 공유합니다 — 남길 내용이 「누가 무엇을 했는가」로
     * 같고, 훅마다 메서드를 두면 같은 코드가 아홉 벌이 됩니다. 어느 훅이었는지는
     * 실행 중인 훅 이름에서 얻습니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 인자가 맥락 배열이면 로그 파라미터로 쓴다)
     * @return void 반환값 없음
     */
    public function handleAnalyticsHook(...$args): void
    {
        $hook = HookManager::getRunningHook();

        if ($hook === null || ! str_starts_with($hook, 'core.analytics.')) {
            return;
        }

        $action = substr($hook, strlen('core.analytics.'));
        $context = is_array($args[0] ?? null) ? $args[0] : [];

        // 이 훅들은 전부 운영자의 결정(설정·삭제·익명화·정보주체 처리)이다. 콘솔 명령이나
        // 큐 워커에서 발화해도 관리자 활동으로 남겨야 관리자 활동 로그 화면에서 보인다.
        $this->logActivity('analytics.'.$action, [
            'log_type' => ActivityLogType::Admin,
            'description_key' => 'activity_log.analytics.'.$action,
            'description_params' => $this->analyticsDescriptionParams($context),
            'properties' => $context,
        ]);
    }

    /**
     * 활동 로그 설명에 넣을 값을 고릅니다.
     *
     * 맥락 배열을 통째로 문구에 넣으면 개인정보가 설명문에 섞일 수 있으므로,
     * 사람이 읽을 만한 몇 개만 고릅니다.
     *
     * @param  array<string, mixed>  $context  훅이 넘긴 맥락
     * @return array<string, mixed> 설명 파라미터
     */
    private function analyticsDescriptionParams(array $context): array
    {
        $params = [];

        foreach (['mode', 'run_type', 'period', 'subject', 'identifier', 'count'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $params[$key] = (string) $context[$key];
            }
        }

        // 발화부가 쓰는 키 이름이 문구의 자리표시자와 다른 훅 — 맞춰 주지 않으면 설명문에
        // `(:mode)` 같은 글자가 그대로 보인다. 모드는 바뀐 뒤 값, 확장은 식별자다.
        if (! isset($params['mode']) && isset($context['to']) && is_scalar($context['to'])) {
            $params['mode'] = (string) $context['to'];
        }

        if (! isset($params['identifier']) && isset($context['extension_identifier']) && is_scalar($context['extension_identifier'])) {
            $params['identifier'] = (string) $context['extension_identifier'];
        }

        // 정보주체는 「무엇으로 찾았는가」(ip·user_id·visitor_key) 이름 목록으로 온다 —
        // 값이 아니라 기준 이름이라 설명문에 넣어도 개인정보가 섞이지 않는다.
        if (! isset($params['subject']) && isset($context['subject']) && is_array($context['subject'])) {
            $params['subject'] = implode(', ', array_filter($context['subject'], 'is_string'));
        }

        return $params;
    }

    // ─────────────────────────────────────────────
    // IdentityVerification (IDV) 핸들러
    // ─────────────────────────────────────────────

    /**
     * 본인인증 challenge 요청 후 로그 기록.
     *
     * @param  VerificationChallenge  $challenge  발급된 본인인증 challenge
     * @param  string  $purpose  인증 목적 (IdentityVerificationPurpose 값)
     * @param  User|array  $target  인증 대상 (회원 모델 또는 이메일/전화 배열)
     * @param  array  $context  부가 컨텍스트 (IP 등)
     * @return void 반환값 없음
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
     * @param  VerificationResult  $result  검증 결과 DTO
     * @param  IdentityVerificationLog|null  $log  검증 로그 (없을 수 있음)
     * @param  array  $context  부가 컨텍스트 (IP 등)
     * @return void 반환값 없음
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
     * @param  IdentityVerificationLog  $log  만료된 본인인증 로그
     * @return void 반환값 없음
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

    /**
     * 확장 업데이트 결과의 레이아웃 처리 요약을 활동 로그 properties 로 만듭니다 (#727 D2).
     *
     * 카운트만 싣고 행 목록은 `changes` 로 보낸다. 겹친 조각 이름은 `layout_sync_conflicts` 에 최대 50건.
     *
     * @param  array<string, mixed>  $result  업데이트 결과 (`layout_strategy`·`layout_sync`)
     * @return array<string, mixed> properties 에 더할 키 (보고가 없으면 빈 배열)
     */
    private function layoutSyncProperties(array $result): array
    {
        $sync = $result['layout_sync'] ?? null;

        if (! is_array($sync)) {
            return [];
        }

        $conflicts = [];
        foreach ($sync['entries'] ?? [] as $entry) {
            foreach ($entry['conflicts'] ?? [] as $conflict) {
                $conflicts[] = ['layout' => ($entry['template'] ?? '').' · '.($entry['name'] ?? ''), 'unit' => $conflict['unit'] ?? '', 'reason' => $conflict['reason'] ?? ''];
            }
        }

        return [
            'layout_strategy' => $result['layout_strategy'] ?? ($sync['strategy'] ?? null),
            'layout_sync' => array_intersect_key($sync, array_flip([
                'applied_count', 'conflict_count', 'kept_no_base_count', 'kept_removed_count',
                'kept_deleted_by_operator_count', 'kept_name_collision_count', 'kept_invalid_count', 'modified_count',
            ])),
            'layout_sync_conflicts' => array_slice($conflicts, 0, 50),
        ];
    }

    /**
     * 운영자가 고친 화면마다 「변경 내역」 한 줄을 만듭니다 — 목록에서 행을 펼치면 항목/이전/이후 표로 보인다.
     *
     * 모듈 레이아웃은 관리자 템플릿마다 등록돼 이름이 겹칠 수 있어 항목을 `{템플릿} · {화면}` 으로 쓴다.
     *
     * @param  array<string, mixed>  $result  업데이트 결과
     * @return array{changes?: list<array<string, mixed>>} 로그 문맥에 더할 `changes` (없으면 빈 배열)
     */
    private function layoutSyncChanges(array $result): array
    {
        $changes = [];

        foreach ($result['layout_sync']['entries'] ?? [] as $entry) {
            $verdict = (string) ($entry['verdict'] ?? '');
            $changes[] = [
                'field' => ($entry['template'] ?? '').' · '.($entry['name'] ?? ''),
                'type' => 'enum',
                'old' => 'operator_modified',
                'new' => $verdict,
                'old_label_key' => 'activity_log.layout_sync.operator_modified',
                'new_label_key' => 'extensions.layout_sync.verdict.'.$verdict,
            ];
        }

        return $changes === [] ? [] : ['changes' => $changes];
    }
}
