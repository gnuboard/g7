<?php

namespace App\Services;

use App\Contracts\Repositories\UserPasswordHistoryRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\PasswordChangeSource;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 비밀번호 변경 기록 서비스 — 모든 비밀번호 쓰기의 단일 기록 지점
 *
 * 변경일·이력·표지 초기화·다른 기기 로그아웃을 비밀번호를 쓰는 각 서비스가 **명시 호출**한다.
 * 모델 옵저버(`isDirty('password')`)로 기록하지 않는 이유: 로그인 재해시(`rehashPasswordIfRequired`)가
 * 평문은 그대로인 채 password 컬럼을 다시 쓴다 — 옵저버로 기록하면 로그인할 때마다 "변경" 으로
 * 세어 만료가 무력화되고 이력이 같은 비밀번호로 채워진다.
 *
 * @since 7.1.0
 */
class PasswordChangeService
{
    /**
     * 회원당 보관하는 이전 비밀번호 해시 수 (재사용 금지 최대 N=10 = 현재 1 + 이전 9)
     */
    public const HISTORY_LIMIT = 9;

    /**
     * @param  UserPasswordHistoryRepositoryInterface  $historyRepository  비밀번호 이력 저장소
     * @param  UserRepositoryInterface  $userRepository  회원 저장소
     */
    public function __construct(
        private readonly UserPasswordHistoryRepositoryInterface $historyRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * 비밀번호 변경을 기록합니다 — 비밀번호 update 와 같은 트랜잭션 안에서 호출합니다.
     *
     * - 이전 해시를 이력에 남기고 9건 초과분을 지운다(재사용 금지가 꺼져 있어도 기록 — 켜는 즉시 효력).
     * - 변경일을 지금으로, 만료 안내·처리 표지와 연기를 비운다(새 주기 시작).
     * - 관리자가 다른 회원의 비밀번호를 정했으면 다음 로그인 때 바꾸도록 표시한다(그 밖에는 해제).
     * - 로그인 토큰을 폐기한다 — `$keepTokenId` 하나만 남긴다(본인 변경 시 현재 기기).
     * - `core.auth.password_change_recorded` 를 커밋 뒤에 발행한다(트랜잭션 밖이면 즉시).
     *
     * @param  User  $user  비밀번호가 바뀐 회원
     * @param  string|null  $oldHash  바뀌기 전 해시 (update 전에 `getRawOriginal('password')` 로 잡은 값)
     * @param  PasswordChangeSource  $source  변경 경로
     * @param  User|null  $actor  행위자 (없으면 회원 본인으로 본다)
     * @param  int|null  $keepTokenId  폐기하지 않을 토큰 ID (본인 변경 시 현재 기기)
     * @param  array<string, mixed>  $extra  훅 문맥에 덧붙일 값 (로그인 단계 변경의 reason 등)
     * @return int 폐기한 토큰 수
     */
    public function recordChange(
        User $user,
        ?string $oldHash,
        PasswordChangeSource $source,
        ?User $actor = null,
        ?int $keepTokenId = null,
        array $extra = [],
    ): int {
        $userId = (int) $user->getKey();

        if (is_string($oldHash) && $oldHash !== '') {
            $this->historyRepository->push($userId, $oldHash);
            $this->historyRepository->pruneBeyond($userId, self::HISTORY_LIMIT);
        }

        $this->userRepository->update($user, [
            'password_changed_at' => now(),
            'password_change_required' => $source === PasswordChangeSource::Admin,
            'password_expiry_notified_at' => null,
            'password_expired_processed_at' => null,
            'password_change_postponed_until' => null,
        ]);

        $revoked = $user->tokens()
            ->when($keepTokenId !== null, fn ($query) => $query->whereKeyNot($keepTokenId))
            ->delete();

        $context = [
            'source' => $source->value,
            'actor_id' => $actor?->getKey() ?? $userId,
            'sessions_revoked' => (int) $revoked,
        ] + $extra;

        DB::afterCommit(function () use ($user, $context) {
            HookManager::doAction('core.auth.password_change_recorded', $user, $context);
        });

        return (int) $revoked;
    }

    /**
     * 새로 만든 회원의 비밀번호 기록을 초기화합니다 (가입·관리자 등록·설치).
     *
     * 관리자가 등록한 회원은 관리자가 정해 준 비밀번호이므로 다음 로그인 때 바꿔야 한다(D11).
     *
     * @param  User  $user  새 회원
     * @param  PasswordChangeSource  $source  생성 경로
     * @return void
     */
    public function initializeNewUser(User $user, PasswordChangeSource $source): void
    {
        $this->userRepository->update($user, [
            'password_changed_at' => now(),
            'password_change_required' => $source === PasswordChangeSource::AdminCreate,
        ]);
    }

    /**
     * 회원의 비밀번호 이력을 지웁니다 (탈퇴·삭제 경로 — CASCADE 에 의존하지 않는다).
     *
     * @param  User  $user  대상 회원
     * @return int 삭제 건수
     */
    public function purgeHistory(User $user): int
    {
        return $this->historyRepository->deleteByUser((int) $user->getKey());
    }

    /**
     * 행위자의 현재 로그인 토큰 ID 를 반환합니다 (본인 변경 시 남길 토큰).
     *
     * 세션(쿠키) 인증·테스트용 가짜 토큰처럼 저장된 토큰이 아니면 null 이다 — 그때는 남길 토큰이 없다.
     *
     * @param  User|null  $actor  행위자
     * @return int|null 토큰 ID
     */
    public function currentTokenId(?User $actor): ?int
    {
        $token = $actor?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && is_int($token->getKey())) {
            return $token->getKey();
        }

        return null;
    }

    /**
     * 행위자와 대상으로 변경 경로를 판정합니다.
     *
     * 행위자가 없거나 대상 자신이면 본인 변경, 다르면 관리자 지정이다.
     *
     * @param  User  $target  비밀번호가 바뀌는 회원
     * @param  User|null  $actor  행위자
     * @return PasswordChangeSource 본인 변경 또는 관리자 지정
     */
    public function sourceFor(User $target, ?User $actor): PasswordChangeSource
    {
        if ($actor === null || (int) $actor->getKey() === (int) $target->getKey()) {
            return PasswordChangeSource::Self_;
        }

        return PasswordChangeSource::Admin;
    }
}
