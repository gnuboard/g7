<?php

namespace App\Search\DTO;

/**
 * 검색 인덱스 재생성 실행 보고 (Value Object · 엔진 중립)
 *
 * 커맨드는 콘솔 출력으로, 컨트롤러는 API 응답으로 같은 값을 씁니다.
 *
 * `remaining` 은 재생성 **후 다시 점검한** 잔존 목록입니다 — "재생성했다" 와
 * "복구됐다" 는 다른 사실이라 따로 담습니다.
 */
readonly class SearchIndexRepairReport
{
    /**
     * @param  string  $driver  대상 Scout 드라이버명
     * @param  int  $inspected  점검한 인덱스 수
     * @param  array<int, string>  $repaired  재생성 성공 인덱스 식별자
     * @param  array<string, string>  $failed  재생성 실패 [식별자 => 사유]
     * @param  array<int, string>  $remaining  재생성 후에도 색인 누락으로 남은 식별자
     * @param  bool  $available  이 엔진에서 점검을 수행할 수 있었는지
     * @param  string|null  $reason  수행 불가 사유 (available=false 일 때)
     */
    public function __construct(
        public string $driver,
        public int $inspected = 0,
        public array $repaired = [],
        public array $failed = [],
        public array $remaining = [],
        public bool $available = true,
        public ?string $reason = null,
    ) {}

    /**
     * 점검을 수행할 수 없었던 경우의 보고를 만듭니다.
     *
     * @param  string  $driver  드라이버명
     * @param  string|null  $reason  사유
     * @return self
     */
    public static function unavailable(string $driver, ?string $reason): self
    {
        return new self(driver: $driver, available: false, reason: $reason);
    }

    /**
     * 재생성 대상이 없었을 때의 보고를 만듭니다.
     *
     * @param  string  $driver  드라이버명
     * @param  int  $inspected  점검한 인덱스 수
     * @return self
     */
    public static function nothingToDo(string $driver, int $inspected): self
    {
        return new self(driver: $driver, inspected: $inspected);
    }

    /**
     * 재생성을 실제로 수행했는지 여부.
     *
     * @return bool
     */
    public function didRebuild(): bool
    {
        return $this->repaired !== [] || $this->failed !== [];
    }

    /**
     * 전부 복구되었는지 여부 (실패·잔존 모두 없음).
     *
     * @return bool
     */
    public function isClean(): bool
    {
        return $this->failed === [] && $this->remaining === [];
    }

    /**
     * 한 줄 요약을 반환합니다.
     *
     * @return string
     */
    public function summary(): string
    {
        if (! $this->available) {
            return $this->reason ?? __('search.index.unavailable', ['driver' => $this->driver]);
        }

        if (! $this->didRebuild()) {
            return __('search.index.report.nothing_to_do', ['count' => $this->inspected]);
        }

        return __('search.index.report.rebuilt', [
            'inspected' => $this->inspected,
            'repaired' => count($this->repaired),
            'failed' => count($this->failed),
            'remaining' => count($this->remaining),
        ]);
    }

    /**
     * 기계 판독용 배열로 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'available' => $this->available,
            'reason' => $this->reason,
            'inspected' => $this->inspected,
            'repaired' => $this->repaired,
            'failed' => $this->failed,
            'remaining' => $this->remaining,
            'summary' => $this->summary(),
        ];
    }
}
