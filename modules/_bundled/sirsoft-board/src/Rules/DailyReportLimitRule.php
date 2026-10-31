<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;

/**
 * 일일 신고 횟수 제한 규칙
 *
 * 사용자당 하루(자정 기준) 전체 게시판 통틀어 신고 가능 횟수를 제한합니다.
 * limit이 0 이하이면 제한 없음으로 처리합니다.
 */
class DailyReportLimitRule implements ValidationRule
{
    /**
     * DailyReportLimitRule 생성자
     *
     * @param  ReportRepositoryInterface  $reportRepository  신고 리포지토리
     * @param  int  $userId  신고하는 회원 ID
     * @param  int  $limit  하루 신고 가능 횟수 (0 이하이면 제한 없음)
     */
    public function __construct(
        private ReportRepositoryInterface $reportRepository,
        private int $userId,
        private int $limit
    ) {}

    /**
     * 검증을 수행합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->limit <= 0) {
            return;
        }

        $todayCount = $this->reportRepository->countTodayReportsByUser($this->userId);

        if ($todayCount >= $this->limit) {
            $fail(trans_choice('sirsoft-board::validation.report.daily_limit_exceeded', $this->limit, [
                'limit' => $this->limit,
            ]));
        }
    }
}
