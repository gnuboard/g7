<?php

namespace Plugins\Sirsoft\Totp\Repositories;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
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
     * 남아 있던 행의 시크릿을 읽을 수 없어도(암호화 키 변경) 발급은 성공한다 — 그 행을 지우고 새로 만든다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $secret  Base32 시크릿
     * @return TotpCredential 저장된 자격증명
     */
    public function issue(int $userId, string $secret): TotpCredential
    {
        $attributes = [
            'secret' => $secret,
            'confirmed_at' => null,
            'last_used_timestep' => null,
            'backup_codes' => null,
        ];

        try {
            return TotpCredential::query()->updateOrCreate(['user_id' => $userId], $attributes);
        } catch (DecryptException) {
            // 남아 있던 행의 시크릿이 지금 키로 풀리지 않는다(사이트 암호화 키가 바뀐 뒤 남은 행). 덮어쓰기는
            // 바뀐 값인지 보려고 옛 값을 읽다가 실패하므로, 어차피 전부 비울 행을 지우고 새로 만든다.
            TotpCredential::query()->where('user_id', $userId)->delete();

            return TotpCredential::query()->create(['user_id' => $userId] + $attributes);
        }
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

    /**
     * 확정 자격증명의 마지막 사용 timestep 을 「더 클 때만」 올립니다.
     *
     * @param  int  $credentialId  자격증명 ID
     * @param  int  $step  이번에 맞은 timestep
     * @return bool 이번 호출이 올렸으면 true
     */
    public function advanceTimestep(int $credentialId, int $step): bool
    {
        return TotpCredential::query()
            ->whereKey($credentialId)
            ->whereNotNull('confirmed_at')
            ->where(fn ($query) => $query->whereNull('last_used_timestep')->orWhere('last_used_timestep', '<', $step))
            ->update(['last_used_timestep' => $step]) > 0;
    }

    /**
     * 백업 코드 하나를 행 잠금 안에서 대조·소진합니다.
     *
     * 목록을 통째로 덮어쓰는 갱신이라, 잠금 없이 읽은 목록으로 쓰면 같은 코드를 동시에 낸 두 요청이 둘 다 통과한다.
     *
     * @param  int  $credentialId  자격증명 ID
     * @param  string  $plain  입력한 백업 코드
     * @return bool 맞고 이번 호출이 소진했으면 true
     */
    public function consumeBackupCode(int $credentialId, string $plain): bool
    {
        return DB::transaction(function () use ($credentialId, $plain): bool {
            $locked = TotpCredential::query()->whereKey($credentialId)->whereNotNull('confirmed_at')->lockForUpdate()->first();

            if ($locked === null) {
                return false;
            }

            $remaining = $locked->matchBackupCode($plain);

            if ($remaining === null) {
                return false;
            }

            $locked->backup_codes = $remaining;
            $locked->save();

            return true;
        });
    }

    /**
     * 미확정 자격증명을 「시크릿이 그대로일 때만」 확정합니다.
     *
     * 시크릿은 암호화 저장이라 같은 평문이어도 저장값이 매번 다르다 — 읽어 둔 원본 암호문과 같은지로 「그 사이 재발급되지
     * 않았음」을 확인한다.
     *
     * @param  TotpCredential  $credential  확정할 때 읽은 자격증명
     * @param  int  $step  확정에 쓴 timestep
     * @param  array<int, string>  $hashes  저장할 백업 코드 해시
     * @return bool 이번 호출이 확정했으면 true
     */
    public function confirmIfPending(TotpCredential $credential, int $step, array $hashes): bool
    {
        return TotpCredential::query()
            ->whereKey($credential->getKey())
            ->whereNull('confirmed_at')
            ->where('secret', $credential->getRawOriginal('secret'))
            ->update([
                'confirmed_at' => now(),
                'last_used_timestep' => $step,
                'backup_codes' => json_encode(array_values($hashes)),
            ]) > 0;
    }

    /**
     * 확정 자격증명의 백업 코드를 통째로 바꿉니다.
     *
     * @param  int  $credentialId  자격증명 ID
     * @param  array<int, string>  $hashes  새 백업 코드 해시
     * @return bool 행이 있어 바꿨으면 true
     */
    public function replaceBackupCodes(int $credentialId, array $hashes): bool
    {
        return TotpCredential::query()
            ->whereKey($credentialId)
            ->whereNotNull('confirmed_at')
            ->update(['backup_codes' => json_encode(array_values($hashes))]) > 0;
    }
}
