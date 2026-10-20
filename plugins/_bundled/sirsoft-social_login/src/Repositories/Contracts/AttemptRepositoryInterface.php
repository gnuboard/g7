<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;

/**
 * 소셜 로그인 시도 기록 저장소.
 *
 * @since 1.0.0
 */
interface AttemptRepositoryInterface
{
    /**
     * 시도 1건을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값 (provider_key·intent·outcome 필수)
     * @return SocialLoginAttempt 기록한 행
     */
    public function record(array $attributes): SocialLoginAttempt;

    /**
     * 조건에 맞는 시도 기록을 최신순 페이지로 반환합니다 (진단 화면).
     *
     * @param  array<string, mixed>  $filters  provider·outcome·intent·error_code
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator 시도 기록
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 기간 안의 제공자 × 결과별 시도 수를 반환합니다 (통계 — test 의도 제외).
     *
     * @param  \DateTimeInterface  $since  시작 시각
     * @return array<string, array<string, int>> 제공자 키 => 결과 => 수
     */
    public function outcomeCountsByProvider(\DateTimeInterface $since): array;

    /**
     * 기간 안의 오류 코드별 시도 수를 반환합니다.
     *
     * @param  string  $errorCode  오류 코드
     * @param  \DateTimeInterface  $since  시작 시각
     * @return int 수
     */
    public function countErrorSince(string $errorCode, \DateTimeInterface $since): int;

    /**
     * 보존 기한이 지난 기록을 지웁니다.
     *
     * @param  \DateTimeInterface  $before  이 시각보다 오래된 행
     * @param  bool  $dryRun  true 면 세기만
     * @return int 대상 행 수
     */
    public function pruneBefore(\DateTimeInterface $before, bool $dryRun = false): int;
}
