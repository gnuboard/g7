<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;

/**
 * 문자 발송 유형(SMS/LMS) 판별기.
 *
 * 비즈뿌리오는 문자 본문을 EUC-KR 기준 byte 로 계산해 SMS(≤90byte)/LMS(≤2000byte)를
 * 구분한다. 본문에는 변수 치환이 끝난 최종 문자열을 넘겨야 하며(계획서 D4), 여기서
 * byte 를 재서 유형을 확정한다. SMS 경계를 넘으면 자동으로 LMS 가 된다.
 *
 * 이모지 등 EUC-KR 로 표현 불가능한 문자는 변환 시 물음표(?)로 치환되어 byte 가
 * 왜곡되므로, 판별 전 제거한다(발송 본문 자체의 이모지 처리는 발송측이 담당).
 */
class SmsTypeResolver
{
    /** SMS 최대 byte (EUC-KR 기준) */
    public const SMS_MAX_BYTES = 90;

    /** LMS 최대 byte (EUC-KR 기준) */
    public const LMS_MAX_BYTES = 2000;

    /**
     * 본문의 EUC-KR byte 길이로 SMS/LMS 유형을 판별합니다.
     *
     * @param  string  $message  변수 치환이 끝난 최종 본문
     * @return DispatchChannel SMS 또는 LMS
     */
    public function resolve(string $message): DispatchChannel
    {
        return $this->byteLength($message) <= self::SMS_MAX_BYTES
            ? DispatchChannel::Sms
            : DispatchChannel::Lms;
    }

    /**
     * 본문의 EUC-KR 기준 byte 길이를 반환합니다.
     *
     * 이모지 등 EUC-KR 미표현 문자를 제거한 뒤 변환하여 byte 를 측정한다.
     *
     * @param  string  $message  대상 문자열(UTF-8)
     * @return int EUC-KR byte 길이
     */
    public function byteLength(string $message): int
    {
        $sanitized = $this->stripUnsupported($message);
        $eucKr = mb_convert_encoding($sanitized, 'EUC-KR', 'UTF-8');

        return strlen($eucKr);
    }

    /**
     * LMS 최대 byte 를 초과하는지 여부를 반환합니다.
     *
     * @param  string  $message  변수 치환이 끝난 최종 본문
     * @return bool LMS 한도(2000byte) 초과 시 true
     */
    public function exceedsLmsLimit(string $message): bool
    {
        return $this->byteLength($message) > self::LMS_MAX_BYTES;
    }

    /**
     * EUC-KR 로 표현 불가능한 문자(이모지 등)를 제거합니다.
     *
     * mb_convert_encoding 은 미표현 문자를 '?' 로 바꿔 byte 를 왜곡시키므로,
     * UTF-8 → EUC-KR → UTF-8 왕복 후 원본과 달라진(=치환된) 문자를 걸러낸다.
     *
     * @param  string  $message  대상 문자열(UTF-8)
     * @return string EUC-KR 표현 가능한 문자만 남긴 문자열
     */
    private function stripUnsupported(string $message): string
    {
        $substituteBackup = mb_substitute_character();
        // 미표현 문자를 삭제(none)하도록 설정해 왜곡 없이 제거
        mb_substitute_character('none');

        try {
            $eucKr = mb_convert_encoding($message, 'EUC-KR', 'UTF-8');

            return mb_convert_encoding($eucKr, 'UTF-8', 'EUC-KR');
        } finally {
            mb_substitute_character($substituteBackup);
        }
    }
}
