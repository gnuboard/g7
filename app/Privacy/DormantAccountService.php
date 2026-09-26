<?php

namespace App\Privacy;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Enums\ActivityLogType;
use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Exceptions\UnknownDormantStageException;
use App\Extension\HookManager;
use App\Models\User;
use App\Privacy\DTO\PrivacyRetentionTarget;
use App\Services\UserService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 휴면 계정 생명주기 서비스 (2단계형 — 설계 확정)
 *
 * 네 단계 중 **세 단계**를 이 서비스가 담당합니다.
 *
 * | 단계 | 언제 | 누가 |
 * |---|---|---|
 * | ① 사전 통지 | 전환 `dormant_notice_days` 전 | 이 서비스 (`notifyUpcomingDormancy`) |
 * | ② 휴면 전환 | 통지 후 `dormant_notice_days` 경과 | 이 서비스 (`convertToDormant`) |
 * | ③ 해제 | 로그인 성공 시 | `AuthService::restoreDormantAccount()` |
 * | ④ 탈퇴 예고 → 탈퇴 | 휴면 후 `dormant_withdraw_days` 경과 | 이 서비스 (`notifyUpcomingWithdrawal` / `withdrawDormant`) |
 *
 * ## 활동 기준은 로그인만이 아니다 (설계 확정)
 *
 * 판정 기준은 `max(users.last_login_at, personal_access_tokens.last_used_at)` 입니다.
 * `last_login_at` 은 **로그인 폼을 통과할 때만** 갱신되고, 토큰은 요청마다 만료가 미뤄집니다
 * (`RefreshTokenExpiration`). `security.auth_token_lifetime = 0`(무한대)으로 운영하는 사이트에서는
 * 매일 접속하는 회원도 `last_login_at` 이 첫 로그인 날짜에 얼어붙으므로, 로그인 시각만 보면
 * **활동 중인 회원이 휴면 → 자동 탈퇴(익명화)로 사라집니다.** 되돌릴 수 없는 종류의 오차라
 * 토큰 사용 시각을 함께 봅니다. 두 값이 모두 없으면(가입만 하고 쓰지 않은 계정) `created_at`
 * 을 씁니다 — NULL 을 그대로 비교하면 그 계정만 영원히 대상에서 빠집니다.
 *
 * ## 유예 기간은 우회할 수 없다
 *
 * 전환·탈퇴 조건이 "통지 단계를 거쳤고 그로부터 `dormant_notice_days` 가 지났다" 를 포함합니다
 * (`dormant_notified_at`). 그래서 운영자가 기능을 오늘 처음 켜도 이미 오래 미접속인 회원이
 * 그날 바로 전환되지 않습니다 — 유효기간제 폐지 이후 휴면 정책의 요건이 "자율 정책 + 사전
 * 안내" 이기 때문입니다.
 *
 * **강제되는 것은 유예 기간이지 통지의 도달이 아닙니다.** 표지는 통지 단계를 지났다는
 * 뜻이고, 실제 발송은 코어 알림 정의(`dormant_pre_notice`·`dormant_withdraw_notice`)와 그
 * 채널 템플릿이 켜져 있을 때만 일어납니다. 운영자가 알림을 끄고 휴면 정책만 운영하는 것은
 * **의도된 구성**이며, 그 경우에도 유예 기간은 그대로 지켜집니다(회원이 통지를 받지 못할
 * 뿐입니다). 발송이 큐에서 실패한 경우도 마찬가지로 표지가 남습니다 — 그 경로의 표지
 * 되돌리기는 이 서비스의 동기 구간 밖이라 미구현입니다(#636 R8 D77 로 이월).
 */
class DormantAccountService
{
    use ResolvesActivityLogType;

    /** 한 번에 읽어 처리할 행 수 */
    private const CHUNK_SIZE = 200;

    /**
     * 대상 목록을 볼 수 있는 관리자 권한 — 설정 읽기 + 회원 읽기 (모두 필요).
     *
     * 목록에 회원 이름·이메일이 그대로 나오므로 설정 화면 권한만으로 회원 명단을 보는
     * 우회 경로를 막는다. 보유기간 핸들러의 `targetPermissions()` 와 같은 규칙이다.
     *
     * @var array<int, string>
     */
    public const TARGET_PERMISSIONS = ['core.settings.read', 'core.users.read'];

    /**
     * 단계 식별자 목록 — 미리보기 응답 키·목록 API 경로 값과 같다.
     *
     * @var array<int, string>
     */
    public const STAGES = ['pre_notice', 'convert', 'withdraw_notice', 'withdraw'];

    /**
     * 관리자 직권 실행에서 「대기기간 생략」을 고를 수 있는 단계.
     *
     * @var array<int, string>
     */
    public const SKIPPABLE_STAGES = ['convert', 'withdraw'];

    /**
     * 직권 실행 권한 — 명단 권한({@see TARGET_PERMISSIONS})의 쓰기 대칭.
     *
     * @var array<int, string>
     */
    public const RUN_PERMISSIONS = ['core.settings.update', 'core.users.update'];

    /**
     * 휴면 서비스를 생성합니다.
     *
     * @param  UserService  $userService  탈퇴 처리 재사용 (익명화·훅·연계 정리 포함)
     */
    public function __construct(private UserService $userService) {}

    /**
     * 휴면 기능 활성 여부 (기본 꺼짐).
     *
     * @return bool 활성 여부
     */
    public function isEnabled(): bool
    {
        return (bool) g7_core_settings('privacy.dormant_enabled', false);
    }

    /**
     * 휴면 후 자동 탈퇴 활성 여부 (기본 꺼짐).
     *
     * @return bool 활성 여부
     */
    public function isWithdrawEnabled(): bool
    {
        return (bool) g7_core_settings('privacy.dormant_withdraw_enabled', false);
    }

    /**
     * 휴면 전환 기준 미접속 일수.
     *
     * @return int 일수
     */
    public function dormantDays(): int
    {
        return max(1, (int) g7_core_settings('privacy.dormant_days', 730));
    }

    /**
     * 사전 통지 선행 일수.
     *
     * @return int 일수
     */
    public function noticeDays(): int
    {
        return max(1, (int) g7_core_settings('privacy.dormant_notice_days', 30));
    }

    /**
     * 휴면 후 탈퇴 전환 기준 일수.
     *
     * @return int 일수
     */
    public function withdrawDays(): int
    {
        return max(1, (int) g7_core_settings('privacy.dormant_withdraw_days', 365));
    }

    /**
     * 각 단계의 대상 건수를 미리 봅니다 (데이터를 바꾸지 않음).
     *
     * **기능이 꺼져 있어도 네 단계를 모두 셉니다** — 운영자는 켜기 전에 규모를 봐야 합니다
     * (자동 탈퇴는 되돌릴 수 없다). 꺼짐 여부는 호출자가 `isEnabled()`·`isWithdrawEnabled()`
     * 로 함께 알린다. 실제 처리(커맨드)는 여전히 토글을 본다.
     *
     * @return array{pre_notice: int, convert: int, withdraw_notice: int, withdraw: int} 단계별 건수
     */
    public function preview(): array
    {
        $counts = [];

        foreach (self::STAGES as $stage) {
            $counts[$stage] = $this->stageQuery($stage)->count();
        }

        return $counts;
    }

    /**
     * 단계 식별자로 그 단계의 대상 쿼리를 돌려줍니다.
     *
     * 미리보기·대상 목록·커맨드가 **같은 쿼리 메서드**를 쓰도록 한 곳에서 고른다.
     *
     * @param  string  $stage  단계 식별자 ({@see STAGES})
     * @param  bool  $skipWaiting  관리자 직권의 「대기기간 생략」 — 전환·탈퇴 단계만 의미가 있다
     * @return Builder<User> 대상 쿼리
     *
     * @throws UnknownDormantStageException 알 수 없는 단계
     */
    public function stageQuery(string $stage, bool $skipWaiting = false): Builder
    {
        return match ($stage) {
            'pre_notice' => $this->preNoticeQuery(),
            'convert' => $this->convertQuery($skipWaiting),
            'withdraw_notice' => $this->withdrawNoticeQuery(),
            'withdraw' => $this->withdrawQuery($skipWaiting),
            default => throw new UnknownDormantStageException($stage),
        };
    }

    /**
     * 「대기기간 생략」이 의미 있는 단계인지 — 안내 뒤 기다리는 조건을 가진 전환·탈퇴 단계뿐이다.
     *
     * @param  string  $stage  단계 식별자 ({@see STAGES})
     * @return bool 생략 가능 여부
     */
    public function supportsSkipWaiting(string $stage): bool
    {
        return in_array($stage, self::SKIPPABLE_STAGES, true);
    }

    /**
     * 한 단계를 관리자 직권으로 실행합니다.
     *
     * 자동 배치(커맨드)와 달리 **휴면 토글·자동 탈퇴 토글을 보지 않습니다** — 자동 실행은 끄고
     * 운영자가 필요할 때만 손으로 돌리는 운영을 허용한다(2026-09-25 확정). 권한은 직권 실행
     * API 가 접수 시점에 검사했다. 처리 조건식은 배치·건수·명단과 같은 쿼리 메서드가 만든다.
     *
     * `$skipWaiting` 은 전환·탈퇴 단계에서 「안내 후 `dormant_notice_days` 경과」 조건을 뺀다 —
     * 안내를 받지 못한 회원도 기간만 지났으면 처리된다(사전 안내 의무를 운영자가 직권으로
     * 넘는 선택이라 확인 창에서 기본 해제). 관리자 계정 보호 제외는 그대로다.
     *
     * @param  string  $stage  단계 식별자 ({@see STAGES})
     * @param  bool  $dryRun  true 면 건수만 셉니다
     * @param  bool  $skipWaiting  대기기간 생략 (전환·탈퇴 단계만)
     * @return int 처리 건수
     *
     * @throws UnknownDormantStageException 알 수 없는 단계
     */
    public function runStage(string $stage, bool $dryRun, bool $skipWaiting = false): int
    {
        $skipWaiting = $skipWaiting && $this->supportsSkipWaiting($stage);

        return match ($stage) {
            'pre_notice' => $this->notifyUpcomingDormancy($dryRun),
            'convert' => $this->convertToDormant($dryRun, $skipWaiting),
            'withdraw_notice' => $this->notifyUpcomingWithdrawal($dryRun, force: true),
            'withdraw' => $this->withdrawDormant($dryRun, force: true, skipWaiting: $skipWaiting),
            default => throw new UnknownDormantStageException($stage),
        };
    }

    /**
     * 한 단계의 대상 목록 한 페이지를 돌려줍니다 (데이터를 바꾸지 않음).
     *
     * 건수와 같은 쿼리를 쓴다 — 모달에서 본 명단이 곧 배치가 처리할 명단이다. 기준일은
     * 앞 두 단계가 마지막 활동일(판정과 같이 로그인·없으면 가입일과 토큰 마지막 사용 중 늦은 쪽),
     * 뒤 두 단계가 휴면 전환일이다.
     * 가장 먼저 대상이 된 회원부터 보인다.
     *
     * @param  string  $stage  단계 식별자 ({@see STAGES})
     * @param  int  $page  페이지 번호 (1부터)
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator<int, PrivacyRetentionTarget> 대상 행 페이지
     */
    public function stageTargets(string $stage, int $page, int $perPage): LengthAwarePaginator
    {
        $byDormantAt = in_array($stage, ['withdraw_notice', 'withdraw'], true);
        $query = $this->stageQuery($stage);

        $query->select(['id', 'uuid', 'name', 'email', 'last_login_at', 'created_at', 'dormant_at']);

        if ($byDormantAt) {
            $query->orderBy('dormant_at');
        } else {
            // 기준일 = 판정과 같은 「마지막 활동」 — 로그인(없으면 가입)과 토큰 마지막 사용 중 늦은 쪽
            // (§1-D 14). 로그인 시각만 보이면 토큰으로 쓰던 회원이 실제보다 오래 쉰 것처럼 보인다.
            // 두 값 중 늦은 쪽은 GREATEST 대신 표준 CASE 로 고른다 — GREATEST 는 DB 마다 있지 않다.
            $query->withMax('tokens', 'last_used_at')
                ->orderByRaw(
                    'CASE WHEN tokens_max_last_used_at > COALESCE(last_login_at, created_at) '
                    .'THEN tokens_max_last_used_at ELSE COALESCE(last_login_at, created_at) END'
                );
        }

        return $query->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (User $user) => new PrivacyRetentionTarget(
                id: $user->id,
                title: (string) $user->name,
                subtitle: $user->email,
                date: $byDormantAt ? $user->dormant_at : $this->lastActivityAt($user),
                url: '/admin/users/'.$user->uuid,
            ));
    }

    /**
     * 목록 행의 마지막 활동 시각 — 로그인(없으면 가입)과 토큰 마지막 사용 중 늦은 쪽.
     *
     * @param  User  $user  `tokens_max_last_used_at` 을 함께 읽은 회원
     * @return Carbon|null 마지막 활동 시각
     */
    private function lastActivityAt(User $user): ?Carbon
    {
        $base = $user->last_login_at ?? $user->created_at;
        $tokenAt = $user->tokens_max_last_used_at !== null ? Carbon::parse($user->tokens_max_last_used_at) : null;

        if ($tokenAt !== null && ($base === null || $tokenAt->greaterThan($base))) {
            return $tokenAt;
        }

        return $base;
    }

    /**
     * 한 단계의 대상 목록 열 라벨을 돌려줍니다.
     *
     * @param  string  $stage  단계 식별자 ({@see STAGES})
     * @return array<string, string> 열 이름 => 라벨
     */
    public function stageColumns(string $stage): array
    {
        return [
            'title' => __('privacy.targets.columns.name'),
            'subtitle' => __('privacy.targets.columns.email'),
            'date' => in_array($stage, ['withdraw_notice', 'withdraw'], true)
                ? __('privacy.targets.columns.dormant_at')
                : __('privacy.targets.columns.last_activity_at'),
        ];
    }

    /**
     * ① 곧 휴면이 될 회원에게 사전 안내를 보냅니다.
     *
     * @param  bool  $dryRun  true 면 건수만 셉니다
     * @return int 처리 건수
     */
    public function notifyUpcomingDormancy(bool $dryRun): int
    {
        if ($dryRun) {
            return $this->preNoticeQuery()->count();
        }

        $dormantDays = $this->dormantDays();
        $processed = 0;

        $this->preNoticeQuery()->chunkById(self::CHUNK_SIZE, function ($users) use (&$processed, $dormantDays) {
            foreach ($users as $user) {
                // 건별 격리 — 한 회원이 던져도 나머지는 계속한다. 감싸지 않으면 청크 200건 중
                // 1건의 예외가 남은 회원과 이후 청크를 통째로 날리고, 더 나쁘게는 표지만 찍힌
                // 회원이 생긴다(아래).
                try {
                    // 표지를 먼저 남긴다 — 발송 뒤에 남기면 발송은 됐는데 표지가 실패한 경우
                    // 다음 실행이 같은 회원에게 또 보낸다(사전 안내가 매일 오는 증상).
                    $this->markNotified($user);

                    HookManager::doAction('core.privacy.dormant_pre_notice', $user, [
                        'dormant_days' => (string) $dormantDays,
                        'scheduled_date' => $this->scheduledDateAfterNotice($user),
                    ]);
                } catch (\Throwable $e) {
                    // 표지를 되돌린다 — 이것이 이 catch 의 핵심이다. 표지가 남은 채로 두면
                    // `preNoticeQuery()` 의 `whereNull` 이 그 회원을 **영구히** 사전 안내
                    // 대상에서 빼는 동안 `convertQuery()` 는 기한이 지나는 즉시 잡아, 안내를
                    // 한 번도 받지 못한 회원이 **예고 없이** 휴면으로 전환된다.
                    $this->rollbackNotified($user, $e, 'dormant_pre_notice');

                    continue;
                }

                $processed++;
            }
        });

        if ($processed > 0) {
            Log::info('휴면 전환 사전 안내 발송', ['count' => $processed, 'dormant_days' => $dormantDays]);
        }

        return $processed;
    }

    /**
     * ② 사전 안내 후 기간이 지난 회원을 휴면으로 전환합니다.
     *
     * @param  bool  $dryRun  true 면 건수만 셉니다
     * @param  bool  $skipWaiting  관리자 직권의 대기기간 생략 ({@see runStage()})
     * @return int 처리 건수
     */
    public function convertToDormant(bool $dryRun, bool $skipWaiting = false): int
    {
        if ($dryRun) {
            return $this->convertQuery($skipWaiting)->count();
        }

        $processed = 0;

        $this->convertQuery($skipWaiting)->chunkById(self::CHUNK_SIZE, function ($users) use (&$processed) {
            foreach ($users as $user) {
                // 건별 격리 — 전환은 상태 전이라 한 건이 던져도 그 회원은 다음 실행에 다시
                // 잡힌다(조건에서 빠지지 않는다). 감싸지 않으면 나머지 회원만 하루씩 밀린다.
                try {
                    $this->convertOne($user);
                } catch (\Throwable $e) {
                    Log::warning('휴면 전환 실패 — 건너뜀', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $processed++;
            }
        });

        if ($processed > 0) {
            Log::info('휴면 계정 전환', ['count' => $processed]);
        }

        return $processed;
    }

    /**
     * 회원 한 명을 휴면으로 전환합니다 (건별 격리 단위).
     *
     * @param  User  $user  대상 회원
     * @return void 반환값 없음
     */
    private function convertOne(User $user): void
    {
        User::query()->whereKey($user->getKey())->update([
            'status' => UserStatus::Dormant->value,
            'dormant_at' => now(),
            // 다음 단계(탈퇴 예고)가 이 표지를 다시 쓰므로 비운다.
            'dormant_notified_at' => null,
        ]);

        // 상태가 Active 가 아니게 되면 토큰을 지운다 — 관리자 상태 변경 경로
        // (UserService)와 같은 규칙이다. 미들웨어가 어차피 막지만, 남겨 두면
        // "휴면인데 토큰은 살아 있다" 는 어긋난 상태가 감사 로그에 남는다.
        $user->tokens()->delete();

        $this->logActivity('user.dormant_converted', [
            'log_type' => ActivityLogType::System,
            'description_key' => 'activity_log.description.user_dormant_converted',
            // 실명이 아니라 uuid 다 — 형제 `user.withdraw`(CoreActivityLogListener)와 같은
            // 어휘다. 이 기능은 개인정보를 파기하는 쪽이므로, 파기 사실을 남기려고
            // 감사 로그에 실명 사본을 만들면 2차 파기 핸들러가 `users` 에서 이름을 지워도
            // 그 사본이 남아 「지운다」가 거짓이 된다(`activity_logs` 는 그 핸들러의 대상이 아니다).
            'description_params' => ['user_id' => (string) $user->uuid],
            'user_id' => $user->id,
            'properties' => ['user_uuid' => (string) $user->uuid],
        ]);

        HookManager::doAction('core.privacy.dormant_converted', $user, []);
    }

    /**
     * ④-1 곧 탈퇴 처리될 휴면 회원에게 사전 안내를 보냅니다.
     *
     * @param  bool  $dryRun  true 면 건수만 셉니다
     * @param  bool  $force  관리자 직권 실행 — 자동 탈퇴 토글을 보지 않는다 ({@see runStage()})
     * @return int 처리 건수
     */
    public function notifyUpcomingWithdrawal(bool $dryRun, bool $force = false): int
    {
        if (! $force && ! $this->isWithdrawEnabled()) {
            return 0;
        }

        if ($dryRun) {
            return $this->withdrawNoticeQuery()->count();
        }

        $processed = 0;

        $this->withdrawNoticeQuery()->chunkById(self::CHUNK_SIZE, function ($users) use (&$processed) {
            foreach ($users as $user) {
                // 사전 안내 단계와 같은 구조다 — 표지가 남고 안내가 안 나가면
                // `withdrawNoticeQuery()` 의 `whereNull` 이 재예고를 막는 동안
                // `withdrawQuery()` 는 기한이 지나면 잡는다 → **예고 없이 자동 탈퇴**.
                try {
                    $this->markNotified($user);

                    HookManager::doAction('core.privacy.dormant_withdraw_notice', $user, [
                        'scheduled_date' => $this->scheduledDateAfterNotice($user),
                    ]);
                } catch (\Throwable $e) {
                    $this->rollbackNotified($user, $e, 'dormant_withdraw_notice');

                    continue;
                }

                $processed++;
            }
        });

        if ($processed > 0) {
            Log::info('휴면 계정 탈퇴 예정 안내 발송', ['count' => $processed]);
        }

        return $processed;
    }

    /**
     * ④-2 안내 후 기간이 지난 휴면 회원을 탈퇴 처리합니다.
     *
     * 익명화·연계 정리·훅은 기존 탈퇴 경로(`UserService::withdrawUser()`)를 그대로 씁니다 —
     * 여기서 상태 컬럼만 바꾸면 본인 탈퇴와 결과가 달라집니다. 그 경로의 관리자 보호 가드
     * (`assertWithdrawable`)도 함께 적용되므로 **관리자 계정은 자동 탈퇴되지 않습니다.**
     *
     * @param  bool  $dryRun  true 면 건수만 셉니다
     * @param  bool  $force  관리자 직권 실행 — 자동 탈퇴 토글을 보지 않는다 ({@see runStage()})
     * @param  bool  $skipWaiting  관리자 직권의 대기기간 생략 ({@see runStage()})
     * @return int 처리 건수
     */
    public function withdrawDormant(bool $dryRun, bool $force = false, bool $skipWaiting = false): int
    {
        if (! $force && ! $this->isWithdrawEnabled()) {
            return 0;
        }

        if ($dryRun) {
            return $this->withdrawQuery($skipWaiting)->count();
        }

        $processed = 0;

        $this->withdrawQuery($skipWaiting)->chunkById(self::CHUNK_SIZE, function ($users) use (&$processed) {
            foreach ($users as $user) {
                // 집행 직전에 상태를 다시 읽는다 — 이 청크의 인스턴스는 `withdrawQuery()` 의
                // SELECT 시점 스냅샷이고, 청크 하나를 처리하는 동안(건별 트랜잭션 + 훅 +
                // 아바타 파일 삭제) 그 회원이 로그인해 `active` 로 되돌아올 수 있다.
                // `withdrawUser()` 는 상태를 보지 않고 `User::withdraw()` 의 멱등 가드는
                // **인메모리 모델**을 읽으므로, 재확인 없이는 방금 계정을 되살린 회원이 낡은
                // 인스턴스로 탈퇴·익명화된다 — 이름·이메일에 접미사가 붙고 동의 이력이 지워져
                // 되돌릴 수 없다.
                $current = $user->fresh();

                if ($current === null || $current->status !== UserStatus::Dormant->value) {
                    continue;
                }

                // 한 건이 실패해도(관리자 보호 가드 등) 나머지는 계속한다.
                try {
                    if (! $this->userService->withdrawUser($current)) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::warning('휴면 계정 자동 탈퇴 실패 — 건너뜀', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $this->logActivity('user.dormant_withdrawn', [
                    'log_type' => ActivityLogType::System,
                    'description_key' => 'activity_log.description.user_dormant_withdrawn',
                    // `$user` 는 청크 스냅샷이라 방금 `withdrawUser()` 가 익명화하기 **전**의
                    // 실명을 아직 들고 있다. 그 값을 적으면 파기 직후에 파기 대상의 사본을
                    // 만드는 셈이 된다 — uuid 로 남긴다.
                    'description_params' => ['user_id' => (string) $user->uuid],
                    'user_id' => $user->id,
                    'properties' => ['user_uuid' => (string) $user->uuid],
                ]);

                $processed++;
            }
        });

        if ($processed > 0) {
            Log::info('휴면 계정 자동 탈퇴', ['count' => $processed]);
        }

        return $processed;
    }

    /**
     * ① 사전 통지 대상 쿼리.
     *
     * @return Builder<User> 대상 쿼리
     */
    public function preNoticeQuery(): Builder
    {
        $leadDays = max(1, $this->dormantDays() - $this->noticeDays());

        $query = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereNull('dormant_notified_at');

        $this->applyInactiveSince($query, now()->subDays($leadDays));

        return $query;
    }

    /**
     * ② 휴면 전환 대상 쿼리.
     *
     * @param  bool  $skipWaiting  관리자 직권의 대기기간 생략 — 안내 조건을 빼고 기간 경과만 본다
     * @return Builder<User> 대상 쿼리
     */
    public function convertQuery(bool $skipWaiting = false): Builder
    {
        $query = User::query()->where('status', UserStatus::Active->value);

        if (! $skipWaiting) {
            // 사전 안내를 보냈고 그로부터 통지 기간이 지난 회원만 — 이 두 조건이 법정
            // 사전 안내를 구조적으로 강제한다. 빠지는 경로는 관리자 직권의 명시적 생략뿐이다.
            $query->whereNotNull('dormant_notified_at')
                ->where('dormant_notified_at', '<=', now()->subDays($this->noticeDays()));
        }

        $this->applyInactiveSince($query, now()->subDays($this->dormantDays()));

        return $query;
    }

    /**
     * ④-1 탈퇴 예정 안내 대상 쿼리.
     *
     * @return Builder<User> 대상 쿼리
     */
    public function withdrawNoticeQuery(): Builder
    {
        $leadDays = max(1, $this->withdrawDays() - $this->noticeDays());

        return $this->excludeWithdrawalProtected(
            User::query()
                ->where('status', UserStatus::Dormant->value)
                ->whereNull('dormant_notified_at')
                ->whereNotNull('dormant_at')
                ->where('dormant_at', '<=', now()->subDays($leadDays))
        );
    }

    /**
     * 자동 탈퇴가 구조적으로 불가능한 계정을 대상에서 제외합니다.
     *
     * `UserService::assertWithdrawable()` 이 슈퍼 관리자와 관리자 역할 보유자를 예외로 막으므로
     * 이들은 집행 단계에서 어차피 건너뛰어진다. 그런데 **예고는 그보다 앞서 나간다** — 제외하지
     * 않으면 장기 미접속 관리자가 「되돌릴 수 없으니 로그인하라」는 탈퇴 예고를 절대 일어나지
     * 않을 일에 대해 받고, 예정일마다 실패 경고만 쌓인다. 루트 CHANGELOG 의 「관리자 계정은
     * 자동 탈퇴 대상에서 제외됩니다」가 예고 경로에서도 참이 되도록 여기서 함께 막는다.
     *
     * 판정 어휘는 `assertWithdrawable()` 과 같다 — `is_super` 이거나 `PermissionType::Admin`
     * 권한을 주는 역할을 하나라도 가진 계정. 둘이 갈리면 `DormantLifecycleTest` 가 red 가 된다.
     *
     * @param  Builder<User>  $query  대상 쿼리
     * @return Builder<User> 관리자를 제외한 쿼리
     */
    private function excludeWithdrawalProtected(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->where('is_super', false)->orWhereNull('is_super'))
            ->whereDoesntHave('roles.permissions', fn ($permissions) => $permissions->where('permissions.type', PermissionType::Admin->value));
    }

    /**
     * ④-2 탈퇴 처리 대상 쿼리.
     *
     * @param  bool  $skipWaiting  관리자 직권의 대기기간 생략 — 탈퇴 예고 조건을 빼고 기간 경과만 본다
     * @return Builder<User> 대상 쿼리
     */
    public function withdrawQuery(bool $skipWaiting = false): Builder
    {
        $query = User::query()->where('status', UserStatus::Dormant->value);

        if (! $skipWaiting) {
            $query->whereNotNull('dormant_notified_at')
                ->where('dormant_notified_at', '<=', now()->subDays($this->noticeDays()));
        }

        return $this->excludeWithdrawalProtected(
            $query->whereNotNull('dormant_at')
                ->where('dormant_at', '<=', now()->subDays($this->withdrawDays()))
        );
    }

    /**
     * "마지막 활동이 기준 시각 이전" 조건을 쿼리에 붙입니다.
     *
     * `max(로그인 시각, 토큰 마지막 사용 시각) <= 기준` 을 GREATEST 없이 **같은 뜻의 두 조건**
     * 으로 표현합니다.
     *
     *   ① 로그인(없으면 가입) 시각이 기준 이전이고
     *   ② 기준 이후에 쓰인 토큰이 하나도 없다
     *
     * 관계(`tokens`)를 경유하는 이유는 테이블 접두사 때문입니다 — 원시 SQL 에 테이블명을
     * 박으면 `DB_PREFIX` 를 쓰는 설치본에서 "테이블이 없다" 로 터집니다.
     *
     * @param  Builder<User>  $query  적용 대상 쿼리
     * @param  Carbon  $threshold  기준 시각
     * @return void 쿼리에 조건을 직접 더한다
     */
    private function applyInactiveSince(Builder $query, Carbon $threshold): void
    {
        // 컬럼명만 쓰는 식이라 접두사와 무관하다(테이블명을 적지 않는다).
        $query->whereRaw('COALESCE(last_login_at, created_at) <= ?', [$threshold])
            ->whereDoesntHave('tokens', function ($tokenQuery) use ($threshold) {
                $tokenQuery->where('last_used_at', '>', $threshold);
            });
    }

    /**
     * 현재 단계의 사전 통지 표지를 남깁니다.
     *
     * @param  User  $user  대상 회원
     * @return void
     */
    private function markNotified(User $user): void
    {
        User::query()->whereKey($user->getKey())->update(['dormant_notified_at' => now()]);
    }

    /**
     * 안내 발송이 실패한 회원의 통지 표지를 되돌립니다.
     *
     * 표지는 중복 발송을 막으려고 발송보다 **먼저** 찍는다({@see markNotified()}). 그 순서는
     * 발송이 성공할 때만 옳다 — 실패한 회원의 표지를 그대로 두면 재통지 쿼리
     * (`preNoticeQuery()`·`withdrawNoticeQuery()` 의 `whereNull`)가 그를 **영구히** 배제하는
     * 동안 집행 쿼리(`convertQuery()`·`withdrawQuery()`)는 기한이 지나는 즉시 잡는다. 결과는
     * 「안내를 한 번도 받지 못한 회원의 예고 없는 전환·탈퇴」이고, 이 클래스 주석이 선언한
     * 「사전 안내는 우회할 수 없다」가 바로 그 경로에서만 조용히 깨진다.
     *
     * 되돌리기 자체가 또 실패할 수 있으므로(DB 가 죽은 경우) 그때는 삼키지 않고 남긴다 —
     * 표지가 남은 회원이 있다는 사실이 운영자에게 보여야 한다.
     *
     * @param  User  $user  대상 회원
     * @param  \Throwable  $error  발송 단계에서 잡은 예외
     * @param  string  $stage  단계 식별자 (로그용)
     * @return void 반환값 없음
     */
    private function rollbackNotified(User $user, \Throwable $error, string $stage): void
    {
        try {
            User::query()->whereKey($user->getKey())->update(['dormant_notified_at' => null]);

            Log::warning('휴면 사전 안내 실패 — 통지 표지를 되돌렸습니다', [
                'stage' => $stage,
                'user_id' => $user->id,
                'error' => $error->getMessage(),
            ]);
        } catch (\Throwable $rollbackError) {
            Log::error('휴면 사전 안내 실패 후 통지 표지를 되돌리지 못했습니다 — 이 회원은 안내 없이 다음 단계로 넘어갈 수 있습니다', [
                'stage' => $stage,
                'user_id' => $user->id,
                'error' => $error->getMessage(),
                'rollback_error' => $rollbackError->getMessage(),
            ]);
        }
    }

    /**
     * 사전 통지문에 실을 **예정일**을 계산합니다 (전환·자동 탈퇴 공용).
     *
     * 예정일의 정본은 **집행 쿼리의 조건식**이다 — 전환도 탈퇴도 `dormant_notified_at` 에
     * `noticeDays()` 를 더한 시점부터 대상이 된다(`convertQuery()`·`withdrawQuery()`). 표지는
     * 바로 이 발송 시점에 찍히므로 예정일 = 발송 시각 + 통지일수다.
     *
     * 종전에는 회원의 **과거 시각**(마지막 활동 + 전환일수 · `dormant_at` + 탈퇴일수)에서
     * 계산했다. 그 식은 **기준일에 정확히 걸린 회원에게만** 집행 시점과 일치하고, 그보다 더
     * 오래 미접속인 회원 — 기능을 처음 켠 사이트의 백로그 **전원** — 에게는 **이미 지난 날짜**가
     * 「전환 예정일」로 나간다. 법정 사전 통지가 알려야 할 바로 그 값이 틀리는 자리였다.
     *
     * 달력 날짜는 **수신자의 시간대**로 렌더한다(설계 확정 2026-09-23). 저장 시각은 UTC 고정
     * (`config/app.php` `timezone`)이고 예약은 사이트 시간대(`app.schedule_timezone`, 기본
     * `Asia/Seoul`)로 도므로, `toDateString()` 을 그대로 쓰면 UTC 달력 날짜가 나간다 — 새벽
     * 05:10 KST = 전날 20:10 UTC 라 안내문 날짜가 실제 집행 날짜보다 **항상 하루 이르다**.
     * 회원 개인 시간대는 `User::getTimezone()` 이 소유하고 비어 있으면 사이트 기본값으로
     * 떨어지므로, 알림 목록 시각(`UserNotificationResource`)과 같은 어휘가 된다.
     *
     * 더하는 것이 먼저고 시간대 변환이 나중인 순서를 지킨다 — 집행 조건이 보는 것은 UTC
     * 순간이므로, 그 순간을 그대로 만든 뒤 수신자 달력으로 읽어야 실제 집행일과 맞는다.
     *
     * @param  User  $user  안내를 받는 회원 (시간대 소유자)
     * @return string 예정일(Y-m-d, 수신자 시간대 기준)
     */
    private function scheduledDateAfterNotice(User $user): string
    {
        return now()
            ->addDays($this->noticeDays())
            ->setTimezone($user->getTimezone())
            ->toDateString();
    }
}
