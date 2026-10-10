<?php

namespace Plugins\Sirsoft\Totp\Repositories\Contracts;

use Plugins\Sirsoft\Totp\Models\TotpCredential;

/**
 * 인증 앱 자격증명 저장소 계약.
 *
 * Service·Listener·Provider 는 모델을 직접 만지지 않고 이 계약만 쓴다 — 저장 경로를
 * 한 곳에 모아야 "어디서 시크릿이 쓰이는가" 를 한 파일로 답할 수 있다.
 *
 * @since 1.0.0
 */
interface TotpCredentialRepositoryInterface
{
    /**
     * 사용자의 자격증명을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return TotpCredential|null 자격증명, 없으면 null
     */
    public function findByUserId(int $userId): ?TotpCredential;

    /**
     * 사용자에게 확정된 자격증명이 있는지 확인합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return bool 확정 자격증명 보유 여부
     */
    public function hasConfirmed(int $userId): bool;

    /**
     * 사용자의 확정된 자격증명을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return TotpCredential|null 확정 자격증명, 없으면 null
     */
    public function findConfirmedByUserId(int $userId): ?TotpCredential;

    /**
     * 사용자의 자격증명을 새 시크릿으로 발급(또는 재발급)합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $secret  Base32 시크릿
     * @return TotpCredential 저장된 자격증명
     */
    public function issue(int $userId, string $secret): TotpCredential;

    /**
     * 자격증명의 속성을 갱신합니다.
     *
     * @param  TotpCredential  $credential  대상 자격증명
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return TotpCredential 갱신된 자격증명
     */
    public function update(TotpCredential $credential, array $attributes): TotpCredential;

    /**
     * 사용자의 자격증명을 삭제합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return int 삭제된 행 수
     */
    public function deleteByUserId(int $userId): int;
}
