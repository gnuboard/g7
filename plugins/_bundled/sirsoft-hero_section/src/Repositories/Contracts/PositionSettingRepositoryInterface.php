<?php

namespace Plugins\Sirsoft\HeroSection\Repositories\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;

/**
 * 위치별 슬라이더 설정 Repository 인터페이스
 */
interface PositionSettingRepositoryInterface
{
    /**
     * 위치의 설정 행을 찾습니다.
     *
     * @param  string  $position  위치 키
     * @return PositionSetting|null 설정 행 (없으면 null)
     */
    public function findByPosition(string $position): ?PositionSetting;

    /**
     * 저장된 설정 행을 위치 키로 묶어 반환합니다.
     *
     * @return Collection<string, PositionSetting> 위치 키 => 설정 행
     */
    public function allKeyed(): Collection;

    /**
     * 위치 설정을 만들거나 고칩니다.
     *
     * @param  string  $position  위치 키
     * @param  array<string, mixed>  $data  저장할 값
     * @return PositionSetting 저장된 설정 행
     */
    public function upsert(string $position, array $data): PositionSetting;
}
