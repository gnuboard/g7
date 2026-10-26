<?php

namespace Plugins\Sirsoft\Sso\Repositories\Contracts;

use Illuminate\Support\Carbon;

/**
 * SCIM 수신 이력 저장소 계약.
 *
 * @since 1.0.0
 */
interface ScimEventRepositoryInterface
{
    /**
     * 수신 1건을 남깁니다 (본문은 남기지 않는다).
     *
     * @param  array{idp_id: int|null, method: string, resource: string, target_id: string|null, status: int, error_code: string|null}  $attributes  속성
     * @return void
     */
    public function record(array $attributes): void;

    /**
     * 기준 시각 전의 이력을 지웁니다 (보존 정리).
     *
     * @param  Carbon  $before  기준 시각
     * @return int 지운 행 수
     */
    public function pruneBefore(Carbon $before): int;
}
