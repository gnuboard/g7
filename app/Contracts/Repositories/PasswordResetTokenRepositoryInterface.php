<?php

namespace App\Contracts\Repositories;

use App\Models\PasswordResetToken;

/**
 * 비밀번호 재설정 토큰 Repository 인터페이스
 */
interface PasswordResetTokenRepositoryInterface
{
    /**
     * 이메일로 비밀번호 재설정 토큰을 조회합니다.
     *
     * @param  string  $email  사용자 이메일
     * @return PasswordResetToken|null 토큰 레코드 또는 null
     */
    public function findByEmail(string $email): ?PasswordResetToken;

    /**
     * 비밀번호 재설정 토큰을 생성하거나 업데이트합니다.
     *
     * @param  string  $email  사용자 이메일
     * @param  array  $data  토큰 데이터
     * @return PasswordResetToken 생성 또는 업데이트된 토큰
     */
    public function updateOrCreateByEmail(string $email, array $data): PasswordResetToken;

    /**
     * 비밀번호 재설정 토큰을 삭제합니다.
     *
     * @param  PasswordResetToken  $token  삭제할 토큰
     * @return bool 삭제 성공 여부
     */
    public function delete(PasswordResetToken $token): bool;

    /**
     * 읽어 둔 토큰 레코드와 이메일·토큰 해시가 모두 같은 행만 지웁니다.
     *
     * 지운 행 수로 「이 요청이 토큰을 처음 쓴 것인지」를 판정한다 — 같은 재설정 링크로 동시에 두 번 재설정하면
     * 먼저 지운 요청만 1 을 받는다(KVE 동형 O6).
     *
     * @param  PasswordResetToken  $token  읽어 둔 토큰 레코드
     * @return int 지운 행 수 (0 = 다른 요청이 먼저 썼거나 새 토큰으로 바뀜)
     *
     * @since 7.1.6
     */
    public function deleteMatching(PasswordResetToken $token): int;
}
