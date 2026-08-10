<?php

namespace App\Benchmark\Contracts;

use App\Benchmark\DTO\BenchmarkProfile;
use App\Benchmark\DTO\BenchmarkResult;
use App\Benchmark\DTO\BenchmarkRunOptions;
use App\Enums\BenchmarkAxis;

/**
 * 계측 축 실행기 계약
 *
 * 축이 늘어날 때 커맨드를 고치지 않고 실행기를 추가하도록 분리합니다. 커맨드는
 * 프로파일의 `type` 으로 실행기를 고르는 일만 합니다.
 */
interface BenchmarkAxisRunner
{
    /**
     * 이 실행기가 담당하는 축을 반환합니다.
     *
     * @return BenchmarkAxis 담당 축
     */
    public function axis(): BenchmarkAxis;

    /**
     * 프로파일 1건을 계측합니다.
     *
     * @param  BenchmarkProfile  $profile  계측 대상
     * @param  BenchmarkRunOptions  $options  실행 옵션
     * @param  \Closure|null  $onProgress  진행 상황 콜백 (string $message, 시딩 등 장시간 작업 알림용)
     * @return BenchmarkResult 계측 결과
     */
    public function run(BenchmarkProfile $profile, BenchmarkRunOptions $options, ?\Closure $onProgress = null): BenchmarkResult;
}
