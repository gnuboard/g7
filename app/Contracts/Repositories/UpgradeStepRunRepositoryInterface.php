<?php

namespace App\Contracts\Repositories;

use App\Enums\UpgradeStepRunStatus;
use App\Models\UpgradeStepRun;

/**
 * 업그레이드 스텝 실행 대장 Repository 인터페이스.
 *
 * @since 7.2.0
 */
interface UpgradeStepRunRepositoryInterface
{
    /**
     * 대장 테이블이 있는지 확인합니다 — 마이그레이션 전(옛 판에서 올라오는 첫 업데이트)에는 대장 없이 진행한다.
     *
     * @return bool 테이블이 있으면 true
     */
    public function available(): bool;

    /**
     * 스텝 한 건의 대장 행을 찾습니다.
     *
     * @param  string  $scope  범위 (core|module|plugin|template)
     * @param  string  $identifier  확장 식별자 (코어는 빈 문자열)
     * @param  string  $version  스텝 판 번호
     * @param  string  $step  스텝 식별
     * @return UpgradeStepRun|null 대장 행
     */
    public function find(string $scope, string $identifier, string $version, string $step): ?UpgradeStepRun;

    /**
     * 새 행을 「실행 중」으로 삽입해 선점합니다 — 같은 스텝 행이 이미 있으면 false.
     *
     * @param  string  $scope  범위
     * @param  string  $identifier  확장 식별자
     * @param  string  $version  스텝 판 번호
     * @param  string  $step  스텝 식별
     * @param  string|null  $host  실행 서버 이름
     * @return bool 선점했으면 true
     */
    public function insertRunning(string $scope, string $identifier, string $version, string $step, ?string $host): bool;

    /**
     * 기존 행을 「실행 중」으로 되돌려 선점합니다 — 지금 상태가 `$fromStatuses` 중 하나일 때만(조건부 갱신).
     *
     * `$fromStatuses` 에 「실행 중」이 있으면 읽은 뒤 다른 실행이 먼저 다시 선점하지 않았을 때만(시작 시각이 그대로일 때만)
     * 선점한다. `$staleRunningBefore` 를 주면 그 시각보다 먼저 시작해 아직 「실행 중」인 행(비정상 종료로 남은 행)도 선점한다.
     *
     * @param  UpgradeStepRun  $run  대장 행
     * @param  array<int, UpgradeStepRunStatus>  $fromStatuses  선점을 허용하는 지금 상태
     * @param  string|null  $host  실행 서버 이름
     * @param  \DateTimeInterface|null  $staleRunningBefore  이 시각보다 먼저 시작한 「실행 중」 행도 선점 (null 이면 보지 않음)
     * @return bool 선점했으면 true
     */
    public function reclaim(UpgradeStepRun $run, array $fromStatuses, ?string $host, ?\DateTimeInterface $staleRunningBefore = null): bool;

    /**
     * 실행이 끝난 상태를 기록합니다.
     *
     * `Done` 이면 이번에 돈 스텝의 내용 지문도 함께 적는다(클로저 스텝은 null). `Failed` 는 지문을 건드리지 않는다.
     *
     * @param  string  $scope  범위
     * @param  string  $identifier  확장 식별자
     * @param  string  $version  스텝 판 번호
     * @param  string  $step  스텝 식별
     * @param  UpgradeStepRunStatus  $status  끝난 상태 (Done|Failed)
     * @param  string|null  $fingerprint  이번에 돈 스텝의 내용 지문 (`Done` 일 때만 적는다)
     * @return void 반환값 없음
     */
    public function finish(string $scope, string $identifier, string $version, string $step, UpgradeStepRunStatus $status, ?string $fingerprint = null): void;
}
