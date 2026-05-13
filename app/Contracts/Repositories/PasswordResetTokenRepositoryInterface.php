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
}
