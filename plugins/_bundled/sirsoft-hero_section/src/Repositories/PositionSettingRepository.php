<?php

namespace Plugins\Sirsoft\HeroSection\Repositories;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\PositionSettingRepositoryInterface;

/**
 * 위치별 슬라이더 설정 Repository
 */
class PositionSettingRepository implements PositionSettingRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @return PositionSetting|null 설정 행
     */
    public function findByPosition(string $position): ?PositionSetting
    {
        return PositionSetting::query()->where('position', $position)->first();
    }

    /**
     * {@inheritDoc}
     *
     * @return Collection<string, PositionSetting> 위치 키 => 설정 행
     */
    public function allKeyed(): Collection
    {
        return PositionSetting::query()->get()->keyBy('position');
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @return void 반환값 없음
     */
    public function ensureExists(string $position): void
    {
        // 다른 요청이 그 사이 먼저 만들었으면 INSERT IGNORE 가 조용히 넘어간다 — 이어지는 잠금 읽기(현재 값 읽기)가 그 행을 본다
        if (! PositionSetting::query()->where('position', $position)->exists()) {
            $now = now();
            PositionSetting::query()->insertOrIgnore(['position' => $position] + PositionSetting::DEFAULTS + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $position  위치 키
     * @return PositionSetting 잠근 설정 행
     */
    public function findForUpdate(string $position): PositionSetting
    {
        return PositionSetting::query()->where('position', $position)->lockForUpdate()->firstOrFail();
    }

    /**
     * {@inheritDoc}
     *
     * @param  PositionSetting  $setting  설정 행
     * @param  array<string, mixed>  $data  저장할 값
     * @return PositionSetting 저장된 설정 행
     */
    public function update(PositionSetting $setting, array $data): PositionSetting
    {
        $setting->fill($data)->save();

        return $setting->fresh();
    }
}
