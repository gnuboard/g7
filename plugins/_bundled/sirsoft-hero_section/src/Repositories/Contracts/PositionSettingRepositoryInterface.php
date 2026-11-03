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
     * 위치의 설정 행이 없으면 기본값으로 만듭니다 — 같은 위치를 동시에 처음 저장해도 행은 하나이고, 유일 키 충돌은 이미 생긴 행으로 받습니다.
     *
     * @param  string  $position  위치 키
     * @return void 반환값 없음
     */
    public function ensureExists(string $position): void;

    /**
     * 위치의 설정 행을 잠가 읽습니다(트랜잭션 안에서 호출) — 동시 저장은 앞 저장이 끝난 값을 「바뀌기 전」으로 봅니다.
     *
     * @param  string  $position  위치 키
     * @return PositionSetting 잠근 설정 행
     */
    public function findForUpdate(string $position): PositionSetting;

    /**
     * 설정 행을 고칩니다.
     *
     * @param  PositionSetting  $setting  설정 행
     * @param  array<string, mixed>  $data  저장할 값
     * @return PositionSetting 저장된 설정 행
     */
    public function update(PositionSetting $setting, array $data): PositionSetting;
}
