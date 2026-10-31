<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\PasswordAuthDisallowedException;
use App\Extension\Auth\PasswordAuthDecision;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * 비밀번호 인증 정책 판정.
 *
 * 비밀번호를 쓰거나 만드는 코어 경로가 필터 `core.auth.password_auth_decision` 에 「이 식별자·회원은 비밀번호를 써도 되는가」를
 * 묻는다. 확장이 없으면 언제나 허용이다. 확장이 엉뚱한 값을 돌려주면 허용하고 경고를 남긴다(확장 오류가 로그인 전체를 막지 않게).
 *
 * purpose:
 * - `login` — 비밀번호 로그인
 * - `reset_request` / `reset_complete` — 비밀번호 찾기 요청 / 재설정 완료
 * - `set_password` — 본인의 비밀번호 최초 설정·변경
 * - `admin_set_password` — 관리자가 남의 비밀번호를 지정
 * - `login_password_change` — 로그인 중 비밀번호 변경 관문
 *
 * @since 7.2.0
 */
class PasswordAuthPolicyService
{
    /** 판정 목적 목록 */
    public const PURPOSES = ['login', 'reset_request', 'reset_complete', 'set_password', 'admin_set_password', 'login_password_change'];

    /**
     * 판정합니다.
     *
     * @param  array{identifier: string, user?: User|null, guard?: string, purpose: string}  $subject  판정 대상
     *                                                                                                 (`user` 가 없으면 식별자 기준 판정)
     * @return PasswordAuthDecision 판정 결과
     */
    public function decide(array $subject): PasswordAuthDecision
    {
        $subject = [
            'identifier' => (string) ($subject['identifier'] ?? ''),
            'user' => ($subject['user'] ?? null) instanceof User ? $subject['user'] : null,
            'guard' => (string) ($subject['guard'] ?? 'user'),
            'purpose' => (string) ($subject['purpose'] ?? 'login'),
        ];

        $decision = HookManager::applyFilters('core.auth.password_auth_decision', null, $subject);

        if ($decision === null) {
            return PasswordAuthDecision::allow();
        }

        if (! $decision instanceof PasswordAuthDecision) {
            Log::warning('core.auth.password_auth_decision 필터가 PasswordAuthDecision 이 아닌 값을 돌려줬습니다 — 허용으로 처리합니다', [
                'type' => get_debug_type($decision),
                'purpose' => $subject['purpose'],
            ]);

            return PasswordAuthDecision::allow();
        }

        return $decision;
    }

    /**
     * 판정해 거절이면 예외를 던집니다.
     *
     * @param  array{identifier: string, user?: User|null, guard?: string, purpose: string}  $subject  판정 대상
     * @return PasswordAuthDecision 허용 판정 (2단계 인증 요구 여부를 담는다)
     *
     * @throws PasswordAuthDisallowedException 거절일 때
     */
    public function assertAllowed(array $subject): PasswordAuthDecision
    {
        $decision = $this->decide($subject);

        if (! $decision->allowed) {
            throw new PasswordAuthDisallowedException($decision);
        }

        return $decision;
    }

    /**
     * 이 회원의 비밀번호를 정해도 되는지 판정합니다 — 저장 경로와 미리 알리는 회원 응답이 같은 판정을 쓴다.
     *
     * 본인이 정하면 `set_password`(guard `user`), 관리자가 남의 비밀번호를 정하면 `admin_set_password`(guard `admin`).
     *
     * @param  User  $user  비밀번호를 정할 회원
     * @param  User|null  $actor  정하는 사람 (null 이면 본인)
     * @return PasswordAuthDecision 판정 결과
     *
     * @since 7.2.0
     */
    public function decideSetPassword(User $user, ?User $actor = null): PasswordAuthDecision
    {
        return $this->decide($this->setPasswordSubject($user, $actor));
    }

    /**
     * 이 회원의 비밀번호를 정해도 되는지 판정해 거절이면 예외를 던집니다.
     *
     * @param  User  $user  비밀번호를 정할 회원
     * @param  User|null  $actor  정하는 사람 (null 이면 본인)
     * @return PasswordAuthDecision 허용 판정
     *
     * @throws PasswordAuthDisallowedException 거절일 때
     *
     * @since 7.2.0
     */
    public function assertCanSetPassword(User $user, ?User $actor = null): PasswordAuthDecision
    {
        return $this->assertAllowed($this->setPasswordSubject($user, $actor));
    }

    /**
     * 비밀번호 설정 판정 대상을 만듭니다.
     *
     * @param  User  $user  비밀번호를 정할 회원
     * @param  User|null  $actor  정하는 사람 (null 이면 본인)
     * @return array{identifier: string, user: User, guard: string, purpose: string} 판정 대상
     */
    private function setPasswordSubject(User $user, ?User $actor): array
    {
        $byOther = $actor instanceof User && (int) $actor->getKey() !== (int) $user->getKey();

        return [
            'identifier' => (string) $user->email,
            'user' => $user,
            'guard' => $byOther ? 'admin' : 'user',
            'purpose' => $byOther ? 'admin_set_password' : 'set_password',
        ];
    }
}
