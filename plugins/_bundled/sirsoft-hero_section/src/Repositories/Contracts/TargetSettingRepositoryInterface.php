<?php

namespace Plugins\Sirsoft\HeroSection\Repositories\Contracts;

use Plugins\Sirsoft\HeroSection\Models\TargetSetting;

/**
 * 동적 위치 대상별 슬라이더 설정 저장소
 */
interface TargetSettingRepositoryInterface
{
    /**
     * 위치 + 대상의 재정의 행을 찾습니다.
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @return TargetSetting|null 재정의 행 (없으면 null — 모두 상속)
     */
    public function find(string $position, string $targetKey): ?TargetSetting;

    /**
     * 재정의 묶음을 원자적으로 바꿉니다(없으면 만들고, 빈 묶음이면 행을 지운다).
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @param  array<string, mixed>  $overrides  명시한 항목만
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return array{0: TargetSetting|null, 1: array<string, mixed>} [저장된 행(빈 묶음이면 null), 바꾸기 전 overrides]
     */
    public function replace(string $position, string $targetKey, array $overrides, ?int $actorId): array;

    /**
     * 재정의 행을 지웁니다.
     *
     * @param  string  $position  위치 키
     * @param  string  $targetKey  대상 키
     * @return array<string, mixed>|null 지우기 전 overrides (행이 없었으면 null)
     */
    public function delete(string $position, string $targetKey): ?array;

    /**
     * 위치에서 재정의 행이 있는 대상 키를 최근 수정 순으로 반환합니다.
     *
     * @param  string  $position  위치 키
     * @return array<int, string> 대상 키
     */
    public function targetKeys(string $position): array;
}
