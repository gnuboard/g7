<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Repositories\Contracts\LoginAttemptRepositoryInterface;

/**
 * SSO 로그인 시도 이력 저장소.
 *
 * @since 1.0.0
 */
class LoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    /**
     * 시도 1건을 남깁니다 — 문자열 칸은 컬럼 길이에 맞춰 자른다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return void 반환값 없음
     */
    public function record(array $attributes): void
    {
        SsoLoginAttempt::query()->create([
            'idp_id' => $attributes['idp_id'] ?? null,
            'user_id' => $attributes['user_id'] ?? null,
            'intent' => mb_substr((string) ($attributes['intent'] ?? 'login'), 0, 10),
            'guard' => mb_substr((string) ($attributes['guard'] ?? 'user'), 0, 5),
            'outcome' => mb_substr((string) ($attributes['outcome'] ?? SsoLoginAttempt::OUTCOME_FAILED), 0, 20),
            'reason_code' => isset($attributes['reason_code']) ? mb_substr((string) $attributes['reason_code'], 0, 50) : null,
            'response_summary' => isset($attributes['response_summary']) && is_array($attributes['response_summary']) ? $attributes['response_summary'] : null,
            'ip_address' => isset($attributes['ip_address']) ? mb_substr((string) $attributes['ip_address'], 0, 45) : null,
            'user_agent' => isset($attributes['user_agent']) ? mb_substr((string) $attributes['user_agent'], 0, 500) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * 기준 시각 전의 시도 이력을 지웁니다 (보존 정리 — `sirsoft-sso:prune`).
     *
     * @param  Carbon  $before  기준 시각 (이 시각 전에 기록된 행을 지운다)
     * @return int 지운 행 수
     */
    public function pruneBefore(Carbon $before): int
    {
        return SsoLoginAttempt::query()->where('created_at', '<', $before)->delete();
    }
}
