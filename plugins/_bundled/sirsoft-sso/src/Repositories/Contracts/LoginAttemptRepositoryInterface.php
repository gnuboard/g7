<?php

namespace Plugins\Sirsoft\Sso\Repositories\Contracts;

use Illuminate\Support\Carbon;

/**
 * SSO 로그인 시도 이력 저장소 계약.
 *
 * @since 1.0.0
 */
interface LoginAttemptRepositoryInterface
{
    /**
     * 시도 1건을 남깁니다.
     *
     * @param  array{idp_id: int|null, user_id: int|null, intent: string, guard: string, outcome: string, reason_code: string|null, ip_address: string|null, user_agent: string|null}  $attributes  속성
     * @return void 반환값 없음
     */
    public function record(array $attributes): void;

    /**
     * 기준 시각 전의 시도 이력을 지웁니다 (보존 정리 — `sirsoft-sso:prune`).
     *
     * @param  Carbon  $before  기준 시각 (이 시각 전에 기록된 행을 지운다)
     * @return int 지운 행 수
     */
    public function pruneBefore(Carbon $before): int;
}
