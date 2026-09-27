<?php

namespace Plugins\Sirsoft\Totp\Repositories;

use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;

/**
 * 인증 앱 자격증명 저장소 구현.
 *
 * @since 1.0.0
 */
class TotpCredentialRepository implements TotpCredentialRepositoryInterface
{
    /**
     * 사용자의 자격증명을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return TotpCredential|null 자격증명, 없으면 null
     */
    public function findByUserId(int $userId): ?TotpCredential
    {
        return TotpCredential::query()->where('user_id', $userId)->first();
    }

    /**
     * 사용자에게 확정된 자격증명이 있는지 확인합니다.
     *
     * 판정만 필요한 호출자(로그인 경로)가 시크릿을 메모리에 올리지 않도록 exists 로 답한다.
     *
     * @param  int  $userId  사용자 ID
     * @return bool 확정 자격증명 보유 여부
     */
    public function hasConfirmed(int $userId): bool
    {
        return TotpCredential::query()
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    /**
     * 사용자의 확정된 자격증명을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return TotpCredential|null 확정 자격증명, 없으면 null
     */
    public function findConfirmedByUserId(int $userId): ?TotpCredential
    {
        return TotpCredential::query()
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->first();
    }

    /**
     * 사용자의 자격증명을 새 시크릿으로 발급(또는 재발급)합니다.
     *
     * 확정·리플레이·백업 코드 상태를 함께 비운다 — 새 시크릿에 옛 상태가 따라붙으면
     * 이전 앱으로 만든 코드가 통과하거나 옛 백업 코드가 살아남는다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $secret  Base32 시크릿
     * @return TotpCredential 저장된 자격증명
     */
    public function issue(int $userId, string $secret): TotpCredential
    {
        return TotpCredential::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'secret' => $secret,
                'confirmed_at' => null,
                'last_used_timestep' => null,
                'backup_codes' => null,
            ]
        );
    }

    /**
     * 자격증명의 속성을 갱신합니다.
     *
     * @param  TotpCredential  $credential  대상 자격증명
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return TotpCredential 갱신된 자격증명
     */
    public function update(TotpCredential $credential, array $attributes): TotpCredential
    {
        $credential->fill($attributes);
        $credential->save();

        return $credential;
    }

    /**
     * 사용자의 자격증명을 삭제합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return int 삭제된 행 수
     */
    public function deleteByUserId(int $userId): int
    {
        return TotpCredential::query()->where('user_id', $userId)->delete();
    }
}
