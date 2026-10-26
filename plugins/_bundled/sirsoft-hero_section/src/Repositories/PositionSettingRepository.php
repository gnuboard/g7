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
     * @param  array<string, mixed>  $data  저장할 값
     * @return PositionSetting 저장된 설정 행
     */
    public function upsert(string $position, array $data): PositionSetting
    {
        $setting = PositionSetting::query()->firstOrNew(['position' => $position]);
        if (! $setting->exists) {
            $setting->fill(PositionSetting::DEFAULTS);
        }
        $setting->fill($data)->save();

        return $setting->fresh();
    }
}
