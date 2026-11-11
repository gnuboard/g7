<?php

namespace Plugins\Sirsoft\HeroSection\Repositories;

use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Models\TargetSetting;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\TargetSettingRepositoryInterface;

/**
 * 동적 위치 대상별 슬라이더 설정 저장소
 */
class TargetSettingRepository implements TargetSettingRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @return TargetSetting|null 재정의 행
     */
    public function find(string $position, string $targetKey): ?TargetSetting
    {
        return TargetSetting::query()->where('position', $position)->where('target_key', $targetKey)->first();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @param  array<string, mixed>  $overrides  명시한 항목만
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return array{0: TargetSetting|null, 1: array<string, mixed>} [저장된 행, 바꾸기 전 overrides]
     */
    public function replace(string $position, string $targetKey, array $overrides, ?int $actorId): array
    {
        if ($overrides === []) {
            $before = $this->delete($position, $targetKey);

            return [null, $before ?? []];
        }

        // 행을 먼저 보장하고 잠가 고친다 — 같은 대상을 동시에 처음 저장해도 유일 키 충돌(500)이 나지 않는다
        if (! TargetSetting::query()->where('position', $position)->where('target_key', $targetKey)->exists()) {
            $now = now();
            TargetSetting::query()->insertOrIgnore(['position' => $position, 'target_key' => $targetKey, 'overrides' => '{}', 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
        }

        return DB::transaction(function () use ($position, $targetKey, $overrides, $actorId) {
            $row = TargetSetting::query()->where('position', $position)->where('target_key', $targetKey)->lockForUpdate()->firstOrFail();
            $before = $row->overrideValues();
            $row->fill(['overrides' => $overrides, 'updated_by' => $actorId])->save();

            return [$row->fresh(), $before];
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @return array<string, mixed>|null 지우기 전 overrides
     */
    public function delete(string $position, string $targetKey): ?array
    {
        return DB::transaction(function () use ($position, $targetKey) {
            $row = TargetSetting::query()->where('position', $position)->where('target_key', $targetKey)->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $before = $row->overrideValues();
            $row->delete();

            return $before;
        });
    }
}
