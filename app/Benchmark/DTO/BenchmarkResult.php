<?php

namespace App\Benchmark\DTO;

/**
 * 계측 결과 1건 — 축 실행기의 산출물 (Value Object)
 *
 * 출력기(`BenchmarkReporter`)가 축을 몰라도 표/JSON/마크다운을 만들 수 있도록,
 * 표시용 표(`headers`/`rows`)와 기계 판독용 수치(`metrics`)를 함께 담습니다.
 * 축이 늘어날 때 출력기를 고치지 않아도 되는 지점이 여기입니다.
 */
final readonly class BenchmarkResult
{
    /**
     * @param  BenchmarkProfile  $profile  계측 대상 프로파일
     * @param  array<int, string>  $headers  표 헤더
     * @param  array<int, array<int, string>>  $rows  표 행 (표시용 문자열)
     * @param  array<string, mixed>  $metrics  기계 판독용 원시 수치
     * @param  array<int, string>  $notes  부가 설명 줄 (실행 계획, N+1 후보 등)
     * @param  bool  $skipped  실행하지 않았는지 여부
     * @param  string|null  $skipReason  실행하지 않은 사유
     */
    public function __construct(
        public BenchmarkProfile $profile,
        public array $headers = [],
        public array $rows = [],
        public array $metrics = [],
        public array $notes = [],
        public bool $skipped = false,
        public ?string $skipReason = null,
    ) {}

    /**
     * 실행하지 않은 결과를 만듭니다.
     *
     * 실패/건너뜀을 결과 목록에서 빼면 리포트가 "전부 측정됨"으로 읽히므로, 사유를 담은
     * 결과로 남겨 표와 JSON 양쪽에 드러냅니다.
     *
     * @param  BenchmarkProfile  $profile  대상 프로파일
     * @param  string  $reason  건너뛴 사유
     * @return self 건너뜀 결과
     */
    public static function skipped(BenchmarkProfile $profile, string $reason): self
    {
        return new self(profile: $profile, skipped: true, skipReason: $reason);
    }

    /**
     * 배열로 직렬화합니다.
     *
     * @return array<string, mixed> 직렬화 결과
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile->qualifiedKey(),
            'axis' => $this->profile->axis->value,
            'label' => $this->profile->label,
            'source' => ['kind' => $this->profile->sourceKind, 'identifier' => $this->profile->sourceIdentifier],
            'skipped' => $this->skipped,
            'skip_reason' => $this->skipReason,
            'headers' => $this->headers,
            'rows' => $this->rows,
            'metrics' => $this->metrics,
            'notes' => $this->notes,
        ];
    }
}
