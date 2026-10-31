<?php

namespace App\Contracts\Repositories;

use App\Models\PrivacyManualRun;

/**
 * 개인정보 조치 직권 실행 기록 Repository 인터페이스
 */
interface PrivacyManualRunRepositoryInterface
{
    /**
     * ID로 실행 기록을 찾습니다.
     *
     * @param  int  $id  기록 ID
     * @return PrivacyManualRun|null 없으면 null
     */
    public function findById(int $id): ?PrivacyManualRun;

    /**
     * 실행 기록을 생성합니다.
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return PrivacyManualRun 생성된 기록
     */
    public function create(array $data): PrivacyManualRun;

    /**
     * 실행 기록을 갱신합니다.
     *
     * @param  PrivacyManualRun  $run  대상 기록
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return bool 갱신 성공 여부
     */
    public function update(PrivacyManualRun $run, array $data): bool;

    /**
     * 상태가 기대값일 때만 갱신합니다 (상태 전이의 원자적 선점).
     *
     * 같은 기록을 두 작업이 동시에 시작하거나, 이미 닫힌(실패 처리된) 기록을 늦게 도착한 작업이
     * 다시 여는 것을 막는다.
     *
     * @param  int  $id  기록 ID
     * @param  string  $expectedStatus  현재 상태가 이 값일 때만
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return bool 실제로 갱신했으면 true
     */
    public function updateIfStatus(int $id, string $expectedStatus, array $data): bool;

    /**
     * 대상의 진행 중(처리 대기·처리 중) 기록 중 가장 최근 것을 찾습니다.
     *
     * @param  string  $target  대상 키
     * @return PrivacyManualRun|null 없으면 null
     */
    public function findActiveByTarget(string $target): ?PrivacyManualRun;

    /**
     * 대상별 가장 최근 기록을 찾습니다 (접수자 함께 로드).
     *
     * @param  array<int, string>  $targets  대상 키 목록
     * @return array<string, PrivacyManualRun> 대상 키 => 최근 기록 (기록이 없는 대상은 빠진다)
     */
    public function latestByTargets(array $targets): array;
}
