<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Sso\Models\SsoScimEvent;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimEventRepositoryInterface;

/**
 * SCIM 수신 이력 저장소.
 *
 * @since 1.0.0
 */
class ScimEventRepository implements ScimEventRepositoryInterface
{
    /**
     * 수신 1건을 남깁니다 (본문은 남기지 않는다).
     *
     * @param  array{idp_id: int|null, method: string, resource: string, target_id: string|null, status: int, error_code: string|null}  $attributes  속성
     * @return void
     */
    public function record(array $attributes): void
    {
        SsoScimEvent::query()->create([
            'idp_id' => $attributes['idp_id'],
            'method' => mb_substr($attributes['method'], 0, 6),
            'resource' => mb_substr($attributes['resource'], 0, 25),
            'target_id' => $attributes['target_id'] !== null ? mb_substr($attributes['target_id'], 0, 191) : null,
            'status' => $attributes['status'],
            'error_code' => $attributes['error_code'] !== null ? mb_substr($attributes['error_code'], 0, 50) : null,
        ]);
    }

    /**
     * 기준 시각 전의 이력을 지웁니다 (보존 정리).
     *
     * @param  Carbon  $before  기준 시각
     * @return int 지운 행 수
     */
    public function pruneBefore(Carbon $before): int
    {
        return SsoScimEvent::query()->where('created_at', '<', $before)->delete();
    }
}
