<?php

namespace App\Enums;

/**
 * 검색 인덱스 건강도 등급 (엔진 중립)
 *
 * 판정 방법은 엔진마다 다릅니다 — 각 `SearchIndexMaintainer` 구현이 자기 방식으로
 * 판정한 뒤 이 등급 중 하나로 답합니다. 코어는 등급만 보고 재생성 대상을 고릅니다.
 */
enum SearchIndexStatus: string
{
    /** 정상 — 색인된 내용으로 검색이 성립한다 */
    case Healthy = 'healthy';

    /** 부분 — 일부만 성립. 엔진 특성일 수 있어 자동 재생성 대상이 아니다 */
    case Degraded = 'degraded';

    /** 색인 누락 — 검색이 성립하지 않는다. 재생성 대상 */
    case Stale = 'stale';

    /** 판정 불가 — 표본·연결 부재 등 (사유를 함께 기록) */
    case Skipped = 'skipped';

    /**
     * 사용자 친화 라벨을 반환합니다.
     *
     * @return string lang 키 해석 결과 (locale 자동 반영)
     */
    public function label(): string
    {
        return __('search.index.status.'.$this->value);
    }

    /**
     * 콘솔 출력용 색상명을 반환합니다.
     *
     * @return string Symfony 콘솔 색상명
     */
    public function consoleColor(): string
    {
        return match ($this) {
            self::Healthy => 'green',
            self::Degraded => 'yellow',
            self::Stale => 'red',
            self::Skipped => 'gray',
        };
    }

    /**
     * 모든 케이스의 string 값 목록.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
