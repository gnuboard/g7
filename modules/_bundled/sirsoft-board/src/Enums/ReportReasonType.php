<?php

namespace Modules\Sirsoft\Board\Enums;

/**
 * 신고 사유 타입 Enum
 *
 * 신고 사유의 유형을 정의합니다.
 */
enum ReportReasonType: string
{
    /**
     * 욕설/비방
     */
    case Abuse = 'abuse';

    /**
     * 혐오 발언
     */
    case HateSpeech = 'hate_speech';

    /**
     * 스팸/광고
     */
    case Spam = 'spam';

    /**
     * 저작권 침해
     */
    case Copyright = 'copyright';

    /**
     * 개인정보 노출
     */
    case Privacy = 'privacy';

    /**
     * 허위정보/가짜뉴스
     */
    case Misinformation = 'misinformation';

    /**
     * 성적인 콘텐츠
     */
    case Sexual = 'sexual';

    /**
     * 폭력적인 콘텐츠
     */
    case Violence = 'violence';

    /**
     * 기타
     */
    case Other = 'other';

    /**
     * 모든 사유 타입 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 사유 타입 값인지 확인합니다.
     *
     * @param  string  $value  검증할 사유 타입 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 사유 타입의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 사유 타입 라벨
     */
    public function label(): string
    {
        return __('sirsoft-board::enums.report_reason_type.'.$this->value);
    }

    /**
     * 모든 사유 타입을 배열로 반환합니다 (value, label 포함).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function toArray(): array
    {
        return array_map(
            fn (self $reason) => [
                'value' => $reason->value,
                'label' => $reason->label(),
            ],
            self::cases()
        );
    }
}
