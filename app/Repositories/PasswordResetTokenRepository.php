<?php

namespace App\Repositories;

use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Models\PasswordResetToken;

/**
 * 비밀번호 재설정 토큰 Repository
 */
class PasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    /**
     * 이메일로 비밀번호 재설정 토큰을 조회합니다.
     *
     * @param  string  $email  사용자 이메일
     * @return PasswordResetToken|null 토큰 레코드 또는 null
     */
    public function findByEmail(string $email): ?PasswordResetToken
    {
        return PasswordResetToken::where('email', $email)->first();
    }

    /**
     * 비밀번호 재설정 토큰을 생성하거나 업데이트합니다.
     *
     * @param  string  $email  사용자 이메일
     * @param  array  $data  토큰 데이터
     * @return PasswordResetToken 생성 또는 업데이트된 토큰
     */
    public function updateOrCreateByEmail(string $email, array $data): PasswordResetToken
    {
        return PasswordResetToken::updateOrCreate(
            ['email' => $email],
            $data
        );
    }

    /**
     * 비밀번호 재설정 토큰을 삭제합니다.
     *
     * @param  PasswordResetToken  $token  삭제할 토큰
     * @return bool 삭제 성공 여부
     */
    public function delete(PasswordResetToken $token): bool
    {
        return $token->delete();
    }

    /**
     * 읽어 둔 토큰 레코드와 이메일·토큰 해시가 모두 같은 행만 지웁니다.
     *
     * @param  PasswordResetToken  $token  읽어 둔 토큰 레코드
     * @return int 지운 행 수
     */
    public function deleteMatching(PasswordResetToken $token): int
    {
        return PasswordResetToken::query()
            ->where('email', $token->email)
            ->where('token', $token->token)
            ->delete();
    }
}
