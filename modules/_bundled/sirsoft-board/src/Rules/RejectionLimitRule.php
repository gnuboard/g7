<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;

/**
 * 반려 누적 제한 규칙
 *
 * 지정된 기간 내 반려 건수가 제한을 초과하면 신고 기능을 차단합니다.
 * limit이 0 이하이면 제한 없음으로 처리합니다.
 */
class RejectionLimitRule implements ValidationRule
{
    /**
     * RejectionLimitRule 생성자
     *
     * @param  ReportRepositoryInterface  $reportRepository  신고 리포지토리
     * @param  int  $userId  신고하는 회원 ID
     * @param  int  $limit  기간 내 허용 반려 수 (0 이하이면 제한 없음)
     * @param  int  $days  반려를 세는 기간(일)
     */
    public function __construct(
        private ReportRepositoryInterface $reportRepository,
        private int $userId,
        private int $limit,
        private int $days
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

        $rejectedCount = $this->reportRepository->countRejectedReportsByUser($this->userId, $this->days);

        if ($rejectedCount >= $this->limit) {
            $fail(trans_choice('sirsoft-board::validation.report.rejection_limit_exceeded', $this->limit, [
                'days' => $this->days,
                'count' => $this->limit,
            ]));
        }
    }
}
