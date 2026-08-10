<?php

namespace App\Enums;

/**
 * 성능 계측 축 Enum
 *
 * `g7:bench` 커맨드가 재는 네 가지 대상을 구분합니다. 프로파일 선언의 `type` 필드
 * 값 도메인이며, 축마다 필수 옵션과 실행기(`App\Benchmark\Axes\*`)가 다릅니다.
 */
enum BenchmarkAxis: string
{
    /**
     * 목록 SELECT 비용 (전체 컬럼 / 목록 컬럼 / 키 컬럼 3축 비교)
     */
    case ListQuery = 'list';

    /**
     * 화면 1장 응답 시간 + 실행 쿼리 건수 + N+1 후보
     */
    case Screen = 'screen';

    /**
     * 저장 경로 1회 소요 시간 (주문 생성, 게시글 등록 등)
     */
    case Write = 'write';

    /**
     * 배치 커맨드 소요 시간 + 피크 메모리
     */
    case Batch = 'batch';

    /**
     * 사람이 읽을 수 있는 라벨
     *
     * @return string 축 라벨
     */
    public function label(): string
    {
        return match ($this) {
            self::ListQuery => '목록 조회',
            self::Screen => '화면 응답',
            self::Write => '쓰기 작업',
            self::Batch => '배치 작업',
        };
    }

    /**
     * 프로파일 선언에서 반드시 채워야 하는 옵션 키 목록
     *
     * 각 원소는 "대안 그룹"이며, 그룹마다 최소 하나가 선언되어야 합니다
     * (`screen` 축은 라우트명 또는 URI 중 하나). 레지스트리가 이 목록으로 선언을
     * 검증하며, 누락된 선언은 사유와 함께 경고로 드러내고 목록에서 제외합니다
     * (조용히 버리면 계측 사각이 됩니다).
     *
     * @return array<int, array<int, string>> 필수 옵션 대안 그룹 목록
     */
    public function requiredOptions(): array
    {
        return match ($this) {
            self::ListQuery => [['table']],
            self::Screen => [['route', 'uri']],
            self::Write => [['callback']],
            self::Batch => [['command']],
        };
    }

    /**
     * 기본적으로 데이터를 변경하는 축인지 여부
     *
     * true 인 축은 `--allow-write` 없이는 실행을 거부합니다. `screen` 축은 선언한
     * HTTP 메서드에 따라 달라지므로 프로파일 단위로 다시 판정합니다.
     *
     * @return bool 데이터 변경 축 여부
     */
    public function mutatesByDefault(): bool
    {
        return match ($this) {
            self::ListQuery, self::Screen => false,
            self::Write, self::Batch => true,
        };
    }

    /**
     * 모든 값 배열
     *
     * @return array<int, string> 축 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
