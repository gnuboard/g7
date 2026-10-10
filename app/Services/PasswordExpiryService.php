<?php

namespace App\Services;

use App\Auth\DTO\PasswordChangeRequirement;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\PasswordChangeReason;
use App\Enums\PasswordExpiryAction;
use App\Enums\PasswordExpiryPolicy;
use App\Enums\PasswordExpiryScope;
use App\Enums\PermissionType;
use App\Exceptions\UnknownPasswordExpiryStageException;
use App\Extension\HookManager;
use App\Models\User;
use App\Privacy\DTO\PrivacyRetentionTarget;
use App\Privacy\PrivacyRetentionManager;
use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 비밀번호 만료 정책 서비스
 *
 * 적용 판정(회원 개별 > 역할 > 사이트 범위), 만료 계산, 로그인 관문의 변경 요구, 만료 사전 안내·
 * 만료일 처리 배치, 관리자 화면의 건수·명단이 모두 이 서비스를 지난다. **미리보기·명단·배치·dry-run 이
 * 같은 쿼리 메서드**(`noticeQuery()`·`expireQuery()`)를 쓴다 — 화면에서 본 명단이 곧 배치가 처리할 명단이다.
 *
 * 만료 기준일 = max(마지막 변경일 ?? 시작일, 시작일). 시작일은 운영자가 만료 기능을 켠 순간이다(D3 —
 * 켜자마자 기존 회원 전원이 만료되지 않게). 경계는 PHP 로 계산해 쿼리에 값으로 넣는다 — 원시 SQL 에
 * 테이블명을 쓰지 않는다(테이블 접두사 설치본 결함 선례).
 *
 * 설정은 한계값으로 양쪽 클램프해 읽는다 — 단건 저장 경로는 범위를 검사하지 않으므로 판독부가 최종 방어선이다.
 *
 * @since 7.1.0
 */
class PasswordExpiryService
{
    /**
     * 대상 명단 열람 권한 (설정 읽기 + 회원 읽기, 둘 다 전체 범위)
     */
    public const TARGET_PERMISSIONS = ['core.settings.read', 'core.users.read'];

    /**
     * 직권 실행 권한 (설정 변경 + 회원 변경)
     */
    public const RUN_PERMISSIONS = ['core.settings.update', 'core.users.update'];

    /**
     * 직권 실행 상태(마지막 실행 결과·접수자)를 보는 권한.
     *
     * 실행 권한이 없어도 환경설정을 볼 수 있는 관리자에게는 버튼을 잠근 채 마지막 실행 결과를 보인다(#736 S2 결정).
     */
    public const RUN_READ_PERMISSIONS = ['core.settings.read'];

    /**
     * 처리 단계 (사전 안내 / 만료일 처리)
     */
    public const STAGES = ['notice', 'expire'];

    /**
     * 만료 기준 시작일 설정 키 (서버 전용 — 요청으로 저장 금지)
     */
    public const STARTED_AT_KEY = 'security.password_expiry_started_at';

    /**
     * 배치 청크 크기
     */
    private const CHUNK_SIZE = 200;

    /**
     * @param  UserRepositoryInterface  $userRepository  회원 저장소
     */
    public function __construct(private readonly UserRepositoryInterface $userRepository) {}

    /**
     * 만료 기능이 켜져 있는지 반환합니다.
     *
     * @return bool 켜져 있으면 true
     */
    public function isEnabled(): bool
    {
        return PasswordPolicy::enabled('password_expiry_enabled');
    }

    /**
     * 만료 기간(일)을 반환합니다 (한계값 클램프).
     *
     * @return int 만료 기간
     */
    public function days(): int
    {
        return PasswordPolicy::clampedSetting('password_expiry_days', 90);
    }

    /**
     * 만료 사전 안내일(만료 N일 전)을 반환합니다 — [1, 기간-1] 로 다시 클램프한다.
     *
     * @return int 사전 안내일
     */
    public function noticeDays(): int
    {
        $notice = PasswordPolicy::clampedSetting('password_expiry_notice_days', 7);

        return max(1, min($notice, $this->days() - 1));
    }

    /**
     * 만료 시 로그인 동작을 반환합니다.
     *
     * @return PasswordExpiryAction 변경해야 로그인 / 다음에 변경 허용
     */
    public function action(): PasswordExpiryAction
    {
        return PasswordExpiryAction::tryFrom((string) g7_core_settings('security.password_expiry_action', 'force'))
            ?? PasswordExpiryAction::Force;
    }

    /**
     * '다음에 변경' 연기 일수를 반환합니다 (한계값 클램프).
     *
     * @return int 연기 일수
     */
    public function remindDays(): int
    {
        return PasswordPolicy::clampedSetting('password_expiry_remind_days', 30);
    }

    /**
     * 사이트 적용 범위를 반환합니다.
     *
     * @return PasswordExpiryScope 전체 / 관리자만 / 지정한 역할·회원만
     */
    public function scope(): PasswordExpiryScope
    {
        return PasswordExpiryScope::tryFrom((string) g7_core_settings('security.password_expiry_scope', 'all'))
            ?? PasswordExpiryScope::All;
    }

    /**
     * 1회 실행당 처리 상한을 반환합니다 (0 = 무제한, 한계값 클램프).
     *
     * @return int 상한
     */
    public function batchSize(): int
    {
        return PasswordPolicy::clampedSetting('password_expiry_batch_size', 1000);
    }

    /**
     * 만료 기준 시작일(운영자가 만료 기능을 켠 순간)을 반환합니다.
     *
     * 켜져 있는데 비어 있으면(기록 경로를 거치지 않은 설치본 등) 지금으로 채워 저장한다 — 그날부터
     * 계산하는 것이 D3 의 뜻이다. 꺼져 있고 비어 있으면 저장하지 않고 지금을 돌려준다(아무도 만료되지 않음).
     *
     * @return Carbon 시작일 (UTC)
     */
    public function startedAt(): Carbon
    {
        $raw = g7_core_settings('security.password_expiry_started_at');

        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw)->utc();
            } catch (\Throwable) {
                // 깨진 값은 아래에서 비어 있는 것과 같이 다룬다.
            }
        }

        $now = now();

        if ($this->isEnabled()) {
            app(SettingsService::class)->setSetting(self::STARTED_AT_KEY, $now->toIso8601String());

            Log::info('비밀번호 만료 시작일이 비어 있어 지금으로 기록했습니다', ['started_at' => $now->toIso8601String()]);
        }

        return $now;
    }

    /**
     * 회원에게 만료 정책이 적용되는지 판정합니다 (D9·D10).
     *
     * 꺼져 있으면 false. 회원 개별 apply/exempt 가 우선, 따름이면 역할 중 apply 가 하나라도 있으면 적용
     * (역할끼리 부딪치면 적용 — 보안 우선), 없고 exempt 가 있으면 제외, 둘 다 없으면 사이트 범위를 따른다.
     *
     * @param  User  $user  대상 회원
     * @return bool 적용되면 true
     */
    public function appliesTo(User $user): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return $this->policyAppliesTo($user);
    }

    /**
     * 토글과 무관하게 적용 판정만 합니다 (`appliesTo()` 의 본체 — SQL 판정 `applyScope()` 와 짝).
     *
     * @param  User  $user  대상 회원
     * @return bool 적용되면 true
     */
    public function policyAppliesTo(User $user): bool
    {
        $own = $user->password_expiry_policy instanceof PasswordExpiryPolicy
            ? $user->password_expiry_policy
            : (PasswordExpiryPolicy::tryFrom((string) $user->password_expiry_policy) ?? PasswordExpiryPolicy::Inherit);

        if ($own === PasswordExpiryPolicy::Apply) {
            return true;
        }

        if ($own === PasswordExpiryPolicy::Exempt) {
            return false;
        }

        $rolePolicies = $user->roles
            ->map(fn ($role) => $role->password_expiry_policy instanceof PasswordExpiryPolicy
                ? $role->password_expiry_policy
                : PasswordExpiryPolicy::tryFrom((string) $role->password_expiry_policy))
            ->filter();

        if ($rolePolicies->contains(PasswordExpiryPolicy::Apply)) {
            return true;
        }

        if ($rolePolicies->contains(PasswordExpiryPolicy::Exempt)) {
            return false;
        }

        return match ($this->scope()) {
            PasswordExpiryScope::All => true,
            PasswordExpiryScope::Admin => $user->isAdmin(),
            PasswordExpiryScope::Selected => false,
        };
    }

    /**
     * 적용 판정(`policyAppliesTo()`)의 SQL 판을 쿼리에 붙입니다.
     *
     * 관계를 경유해 테이블명을 원시 SQL 에 쓰지 않는다. 관리자 판정 어휘는 `User::isAdmin()` 과 같다
     * (관리자 유형 권한을 주는 역할을 하나라도 가짐).
     *
     * @param  Builder<User>  $query  회원 쿼리
     * @return Builder<User> 조건을 붙인 쿼리
     */
    public function applyScope(Builder $query): Builder
    {
        $model = $query->getModel();
        $policyColumn = $model->qualifyColumn('password_expiry_policy');
        $scope = $this->scope();

        return $query->where(function (Builder $q) use ($policyColumn, $scope) {
            $q->where($policyColumn, PasswordExpiryPolicy::Apply->value)
                ->orWhere(function (Builder $inherit) use ($policyColumn, $scope) {
                    $inherit->where($policyColumn, PasswordExpiryPolicy::Inherit->value)
                        ->where(function (Builder $byRole) use ($scope) {
                            $byRole->whereHas('roles', fn ($roles) => $roles->where(
                                $roles->getModel()->qualifyColumn('password_expiry_policy'),
                                PasswordExpiryPolicy::Apply->value
                            ))->orWhere(function (Builder $site) use ($scope) {
                                $site->whereDoesntHave('roles', fn ($roles) => $roles->where(
                                    $roles->getModel()->qualifyColumn('password_expiry_policy'),
                                    PasswordExpiryPolicy::Exempt->value
                                ));

                                match ($scope) {
                                    PasswordExpiryScope::All => null,
                                    PasswordExpiryScope::Admin => $site->whereHas(
                                        'roles.permissions',
                                        fn ($permissions) => $permissions->where('permissions.type', PermissionType::Admin->value)
                                    ),
                                    PasswordExpiryScope::Selected => $site->whereRaw('1 = 0'),
                                };
                            });
                        });
                });
        });
    }

    /**
     * 회원의 만료 기준일을 반환합니다 — max(마지막 변경일 ?? 시작일, 시작일).
     *
     * @param  User  $user  대상 회원
     * @param  Carbon|null  $startedAt  시작일 (반복 호출 시 넘겨 판독을 줄인다)
     * @return Carbon 기준일
     */
    public function baselineAt(User $user, ?Carbon $startedAt = null): Carbon
    {
        $startedAt ??= $this->startedAt();
        $changedAt = $user->password_changed_at;

        if ($changedAt === null || $changedAt->lessThan($startedAt)) {
            return $startedAt->copy();
        }

        return Carbon::instance($changedAt);
    }

    /**
     * 회원의 만료 일시를 반환합니다 (기준일 + 기간).
     *
     * @param  User  $user  대상 회원
     * @param  Carbon|null  $startedAt  시작일
     * @return Carbon 만료 일시
     */
    public function expiresAt(User $user, ?Carbon $startedAt = null): Carbon
    {
        return $this->baselineAt($user, $startedAt)->addDays($this->days());
    }

    /**
     * 회원의 비밀번호가 만료됐는지 반환합니다 (적용 여부는 보지 않는다).
     *
     * @param  User  $user  대상 회원
     * @return bool 만료됐으면 true
     */
    public function isExpired(User $user): bool
    {
        return ! $this->expiresAt($user)->isFuture();
    }

    /**
     * 만료까지 남은 날수를 반환합니다 (지났으면 0, 하루 미만도 1일로 올린다).
     *
     * @param  User  $user  대상 회원
     * @param  Carbon|null  $startedAt  시작일
     * @return int 남은 날수
     */
    public function daysLeft(User $user, ?Carbon $startedAt = null): int
    {
        $seconds = now()->diffInSeconds($this->expiresAt($user, $startedAt), false);

        return $seconds <= 0 ? 0 : (int) ceil($seconds / 86400);
    }

    /**
     * 로그인 관문이 비밀번호 변경을 요구해야 하는지 판정합니다.
     *
     * - 관리자가 정해 준 비밀번호 → 변경 요구(연기 불가, 만료 기능이 꺼져 있어도 동작 — D11)
     * - 만료 적용·만료됨 → 변경해야 로그인 모드면 연기 불가, 다음에 변경 허용 모드면 연기 기간 중엔 통과
     *
     * @param  User  $user  로그인하는 회원
     * @return PasswordChangeRequirement|null 요구가 없으면 null
     */
    public function loginRequirement(User $user): ?PasswordChangeRequirement
    {
        if ((bool) $user->password_change_required) {
            return new PasswordChangeRequirement(PasswordChangeReason::AdminAssigned);
        }

        if (! $this->appliesTo($user)) {
            return null;
        }

        $expiresAt = $this->expiresAt($user);

        if ($expiresAt->isFuture()) {
            return null;
        }

        if ($this->action() === PasswordExpiryAction::Force) {
            return new PasswordChangeRequirement(PasswordChangeReason::Expired, false, null, $expiresAt);
        }

        $postponedUntil = $user->password_change_postponed_until;

        if ($postponedUntil !== null && $postponedUntil->isFuture()) {
            return null;
        }

        return new PasswordChangeRequirement(PasswordChangeReason::Expired, true, $this->remindDays(), $expiresAt);
    }

    /**
     * '다음에 변경' 을 기록합니다 — 연기 일수 뒤 다시 안내한다.
     *
     * @param  User  $user  연기한 회원
     * @return Carbon 연기 종료 일시
     */
    public function postpone(User $user): Carbon
    {
        $until = now()->addDays($this->remindDays());

        $this->userRepository->updateManyByIds([(int) $user->getKey()], ['password_change_postponed_until' => $until]);
        $user->password_change_postponed_until = $until;

        return $until;
    }

    /**
     * 만료 사전 안내 대상 쿼리 — 안내 창에 들어왔지만 아직 만료되지 않았고, 이번 주기에 안내하지 않은 회원.
     *
     * @param  Carbon|null  $startedAt  시작일
     * @return Builder<User> 대상 쿼리
     */
    public function noticeQuery(?Carbon $startedAt = null): Builder
    {
        $startedAt ??= $this->startedAt();
        $cutoff = now()->subDays($this->days());              // 이 시각 이전 기준일 = 만료
        $noticeCutoff = $cutoff->copy()->addDays($this->noticeDays()); // 이 시각 이전 기준일 = 안내 창

        $query = $this->baseQuery();

        if ($startedAt->greaterThan($noticeCutoff)) {
            return $query->whereRaw('1 = 0');
        }

        // 기준일 <= 안내 경계
        $query->where(fn (Builder $q) => $q->whereNull('password_changed_at')->orWhere('password_changed_at', '<=', $noticeCutoff));

        // 아직 만료 아님 (기준일 > 만료 경계)
        if ($startedAt->lessThanOrEqualTo($cutoff)) {
            $query->whereNotNull('password_changed_at')->where('password_changed_at', '>', $cutoff);
        }

        // 이번 주기에 안내하지 않음 (안내 시각이 기준일 이전이면 지난 주기)
        return $query->where(fn (Builder $q) => $q->whereNull('password_expiry_notified_at')
            ->orWhere('password_expiry_notified_at', '<', $startedAt)
            ->orWhereColumn('password_expiry_notified_at', '<', 'password_changed_at'));
    }

    /**
     * 만료일 처리 대상 쿼리 — 만료됐고 이번 주기에 처리하지 않은 회원.
     *
     * @param  Carbon|null  $startedAt  시작일
     * @return Builder<User> 대상 쿼리
     */
    public function expireQuery(?Carbon $startedAt = null): Builder
    {
        $startedAt ??= $this->startedAt();
        $cutoff = now()->subDays($this->days());

        $query = $this->baseQuery();

        if ($startedAt->greaterThan($cutoff)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(fn (Builder $q) => $q->whereNull('password_changed_at')->orWhere('password_changed_at', '<=', $cutoff))
            ->where(fn (Builder $q) => $q->whereNull('password_expired_processed_at')
                ->orWhere('password_expired_processed_at', '<', $startedAt)
                ->orWhereColumn('password_expired_processed_at', '<', 'password_changed_at'));
    }

    /**
     * 단계 식별자로 대상 쿼리를 고릅니다.
     *
     * @param  string  $stage  처리 단계 식별자 — 사전 안내 notice · 만료 처리 expire ({@see self::STAGES})
     * @param  Carbon|null  $startedAt  시작일
     * @return Builder<User> 대상 쿼리
     *
     * @throws UnknownPasswordExpiryStageException 알 수 없는 단계
     */
    public function stageQuery(string $stage, ?Carbon $startedAt = null): Builder
    {
        return match ($stage) {
            'notice' => $this->noticeQuery($startedAt),
            'expire' => $this->expireQuery($startedAt),
            default => throw new UnknownPasswordExpiryStageException($stage),
        };
    }

    /**
     * 두 단계의 대상 건수를 미리 봅니다 (데이터를 바꾸지 않음 — 꺼져 있어도 저장값으로 센다).
     *
     * @param  User|null  $viewer  요청 관리자 (명단 버튼 노출 판정용)
     * @return array{enabled: bool, started_at: string|null, notice: int, expire: int, batch_size: int, can_view_targets: bool} 미리보기
     */
    public function preview(?User $viewer): array
    {
        $startedAt = $this->startedAt();
        $raw = g7_core_settings('security.password_expiry_started_at');

        return [
            'enabled' => $this->isEnabled(),
            'started_at' => is_string($raw) && $raw !== '' ? $startedAt->toIso8601String() : null,
            'notice' => $this->noticeQuery($startedAt)->count(),
            'expire' => $this->expireQuery($startedAt)->count(),
            'batch_size' => $this->batchSize(),
            'can_view_targets' => app(PrivacyRetentionManager::class)->canViewTargetList(self::TARGET_PERMISSIONS, $viewer),
        ];
    }

    /**
     * 한 단계의 대상 명단 한 페이지를 돌려줍니다 (만료가 가까운 순).
     *
     * @param  string  $stage  처리 단계 식별자 — 사전 안내 notice · 만료 처리 expire ({@see self::STAGES})
     * @param  int  $page  페이지 번호 (1부터)
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator<int, PrivacyRetentionTarget> 대상 행 페이지
     */
    public function stageTargets(string $stage, int $page, int $perPage): LengthAwarePaginator
    {
        $startedAt = $this->startedAt();
        $query = $this->stageQuery($stage, $startedAt)
            ->select(['id', 'uuid', 'name', 'email', 'is_super', 'password_changed_at'])
            ->withExists(['roles as has_admin_role' => fn ($roles) => $roles->whereHas(
                'permissions',
                fn ($permissions) => $permissions->where('permissions.type', PermissionType::Admin->value)
            )]);

        return $this->orderByExpiry($query, $startedAt)
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (User $user) => new PrivacyRetentionTarget(
                id: $user->id,
                title: (string) $user->name,
                subtitle: $user->email,
                context: ($user->has_admin_role || $user->is_super)
                    ? __('user.password_expiry_target.admin')
                    : __('user.password_expiry_target.member'),
                date: $this->expiresAt($user, $startedAt),
                url: '/admin/users/'.$user->uuid,
            ));
    }

    /**
     * 명단 열 라벨을 돌려줍니다.
     *
     * 날짜 열은 단계마다 이름이 다르다 — 사전 안내 명단의 날짜는 아직 오지 않은 「만료 예정일」, 만료 처리 명단은
     * 이미 지난 「만료일」이다(#736, 2026-10-04 결정).
     *
     * @param  string  $stage  처리 단계 식별자 — 사전 안내 notice · 만료 처리 expire ({@see self::STAGES})
     * @return array<string, string> 열 이름 => 라벨
     */
    public function stageColumns(string $stage): array
    {
        return [
            'title' => __('privacy.targets.columns.name'),
            'subtitle' => __('privacy.targets.columns.email'),
            'context' => __('user.password_expiry_target.column_type'),
            'date' => $stage === 'notice'
                ? __('user.password_expiry_target.column_expires_due')
                : __('user.password_expiry_target.column_expires_at'),
        ];
    }

    /**
     * 이번 실행에서 처리할 건수를 셉니다 — min(대상 수, 상한).
     *
     * @param  string  $stage  처리 단계 식별자 — 사전 안내 notice · 만료 처리 expire ({@see self::STAGES})
     * @return int 처리 예정 건수
     */
    public function runCount(string $stage): int
    {
        $total = $this->stageQuery($stage)->count();
        $cap = $this->batchSize();

        return $cap > 0 ? min($total, $cap) : $total;
    }

    /**
     * 만료 예정 회원에게 사전 안내를 보냅니다.
     *
     * 회원마다 표지를 **먼저** 남기고 훅을 발행한다 — 발송 뒤에 남기면 표지 실패 시 다음 실행이 또 보낸다.
     * 발행이 던지면 표지를 되돌린다(휴면 사전 안내와 같은 규칙).
     *
     * 직권 실행도 토글이 꺼져 있으면 처리하지 않는다 — 꺼져 있으면 로그인 관문이 변경을 요구하지 않아 안내가
     * 사실과 달라진다(#736, 2026-10-03 확정). 접수는 직권 실행 서비스가 먼저 거절하고, 여기는 접수 뒤 꺼진 경우다.
     *
     * @param  bool  $dryRun  true 면 건수만 센다
     * @return array{count: int, remaining: int} 처리 건수·남은 건수
     */
    public function notifyUpcoming(bool $dryRun): array
    {
        if (! $this->isEnabled()) {
            return ['count' => 0, 'remaining' => 0];
        }

        $startedAt = $this->startedAt();

        return $this->runBatch($this->noticeQuery($startedAt), $startedAt, $dryRun, function (User $user) use ($startedAt) {
            $this->markColumn($user, 'password_expiry_notified_at', now());

            try {
                HookManager::doAction('core.auth.password_expiry_notice', $user, [
                    'expires_at' => $this->expiresAt($user, $startedAt)->setTimezone($user->getTimezone())->toDateString(),
                    'days_left' => $this->daysLeft($user, $startedAt),
                ]);
            } catch (\Throwable $e) {
                $this->rollbackColumn($user, 'password_expiry_notified_at', $e);

                return false;
            }

            return true;
        });
    }

    /**
     * 만료된 회원을 처리합니다 — 만료 알림 + ('변경해야 로그인' 모드면) 로그인 토큰 전부 폐기(D5).
     *
     * 직권 실행도 토글이 꺼져 있으면 처리하지 않는다({@see notifyUpcoming()} 와 같은 이유).
     *
     * @param  bool  $dryRun  true 면 건수만 센다
     * @return array{count: int, remaining: int} 처리 건수·남은 건수
     */
    public function processExpired(bool $dryRun): array
    {
        if (! $this->isEnabled()) {
            return ['count' => 0, 'remaining' => 0];
        }

        $startedAt = $this->startedAt();
        $revoke = $this->action() === PasswordExpiryAction::Force;

        return $this->runBatch($this->expireQuery($startedAt), $startedAt, $dryRun, function (User $user) use ($startedAt, $revoke) {
            $this->markColumn($user, 'password_expired_processed_at', now());

            try {
                $revoked = $revoke ? (int) $user->tokens()->delete() : 0;

                HookManager::doAction('core.auth.password_expired', $user, [
                    'expired_at' => $this->expiresAt($user, $startedAt)->setTimezone($user->getTimezone())->toDateString(),
                    'sessions_revoked' => $revoked,
                ]);
            } catch (\Throwable $e) {
                $this->rollbackColumn($user, 'password_expired_processed_at', $e);

                return false;
            }

            return true;
        });
    }

    /**
     * 공통 대상 조건 — 활성 회원, 관리자 지정 변경 대기자 제외, 적용 범위(A7).
     *
     * 휴면·차단·탈퇴·인증대기는 로그인 관문에서 처리하고, 관리자 지정 대기자에게 만료 안내는 모순이다.
     *
     * @return Builder<User> 기본 쿼리
     */
    private function baseQuery(): Builder
    {
        return $this->applyScope($this->userRepository->passwordExpiryCandidateQuery());
    }

    /**
     * 만료가 가까운 순(기준일 오름차순, 같으면 ID 순)으로 정렬합니다.
     *
     * 기준일 = max(변경일 ?? 시작일, 시작일) 을 컬럼명만 쓰는 CASE 식으로 표현한다(테이블 접두사 무관).
     *
     * @param  Builder<User>  $query  대상 쿼리
     * @param  Carbon  $startedAt  시작일
     * @return Builder<User> 정렬한 쿼리
     */
    private function orderByExpiry(Builder $query, Carbon $startedAt): Builder
    {
        return $query
            ->orderByRaw(
                'CASE WHEN password_changed_at IS NULL OR password_changed_at < ? THEN ? ELSE password_changed_at END asc',
                [$startedAt, $startedAt]
            )
            ->orderBy('id');
    }

    /**
     * 상한·순서를 적용해 대상을 회원별로 처리합니다.
     *
     * @param  Builder<User>  $query  대상 쿼리
     * @param  Carbon  $startedAt  시작일
     * @param  bool  $dryRun  true 면 건수만 센다
     * @param  callable(User): bool  $handle  회원 하나를 처리하고 성공 여부를 돌려주는 콜백
     * @return array{count: int, remaining: int} 처리 건수·남은 건수
     */
    private function runBatch(Builder $query, Carbon $startedAt, bool $dryRun, callable $handle): array
    {
        $total = (clone $query)->count();
        $cap = $this->batchSize();
        $planned = $cap > 0 ? min($total, $cap) : $total;

        if ($dryRun || $planned === 0) {
            return ['count' => $planned, 'remaining' => max(0, $total - $planned)];
        }

        if ($cap > 0) {
            $ids = $this->orderByExpiry(clone $query, $startedAt)->limit($cap)->pluck('id')->all();
            $query = $this->userRepository->queryByIds($ids);
        }

        $processed = 0;

        $query->chunkById(self::CHUNK_SIZE, function ($users) use (&$processed, $handle) {
            foreach ($users as $user) {
                if ($handle($user)) {
                    $processed++;
                }
            }
        });

        return ['count' => $processed, 'remaining' => max(0, $total - $processed)];
    }

    /**
     * 회원의 표지 컬럼을 기록합니다.
     *
     * @param  User  $user  대상 회원
     * @param  string  $column  표지 컬럼
     * @param  Carbon  $at  기록 시각
     * @return void
     */
    private function markColumn(User $user, string $column, Carbon $at): void
    {
        $this->userRepository->updateManyByIds([(int) $user->getKey()], [$column => $at]);
        $user->setAttribute($column, $at);
    }

    /**
     * 처리에 실패한 회원의 표지를 되돌립니다 — 남겨 두면 그 회원은 이번 주기에 다시 처리되지 않는다.
     *
     * @param  User  $user  대상 회원
     * @param  string  $column  표지 컬럼
     * @param  \Throwable  $error  처리 단계에서 잡은 예외
     * @return void
     */
    private function rollbackColumn(User $user, string $column, \Throwable $error): void
    {
        try {
            $this->userRepository->updateManyByIds([(int) $user->getKey()], [$column => null]);

            Log::warning('비밀번호 만료 처리 실패 — 표지를 되돌렸습니다', [
                'column' => $column,
                'user_id' => $user->id,
                'error' => $error->getMessage(),
            ]);
        } catch (\Throwable $rollbackError) {
            Log::error('비밀번호 만료 처리 실패 후 표지를 되돌리지 못했습니다', [
                'column' => $column,
                'user_id' => $user->id,
                'error' => $error->getMessage(),
                'rollback_error' => $rollbackError->getMessage(),
            ]);
        }
    }
}
