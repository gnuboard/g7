<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use Plugins\Sirsoft\MessageBizppurio\Enums\ResultCategory;

/**
 * 비즈뿌리오 결과 코드 해석기 (계획서 D11).
 *
 * 발송 응답·webhook 리포트의 결과 코드를 (1) 성공/실패/재시도/잔액부족으로 분류하고,
 * (2) result_codes lang 으로 사람이 읽는 사유로 변환한다. 표시는 `사유 (코드)` 형식
 * (예: "음영 지역 (4400)"). lang 에 없는 코드는 코드만 노출한다.
 *
 * 분류 상수는 매뉴얼(부록 C-3) 1차 범위 기준이며, lang/{ko,en}/result_codes.php 와
 * 함께 유지한다.
 */
class ResultCodeResolver
{
    /**
     * 성공 코드 — 발송 응답 1000 / 리포트 SMS 4100 · LMS 6600 · 알림톡 7000 / 카카오 관리 200.
     */
    private const SUCCESS_CODES = ['1000', '4100', '6600', '7000', '200'];

    /**
     * 재시도(일시 오류) 코드 — 큐가 재시도해야 하는 코드.
     *
     * 공통 일시오류(5002 요청 과다·5003/5004/5005 게이트웨이 오류·9000 알 수 없는 오류·
     * 3011 비즈뿌리오 내부 오류·3013 미완료 메시지)에 더해, 알림톡 일시오류(7306 카카오
     * 시스템오류·7307 처리지연·7421 타임아웃·7437 메시지 요청실패)도 포함한다.
     * 7305(성공 불확실)는 이미 발송됐을 수 있어 중복발송 위험이 있으므로 제외한다.
     * 출처: 비즈뿌리오 공식 응답코드(bizppurio.github.io/response-codes).
     */
    private const RETRYABLE_CODES = ['5002', '5003', '5004', '5005', '9000', '3011', '3013', '7306', '7307', '7421', '7437'];

    /**
     * 잔액 부족 코드 — 선불 잔액부족 9070 / 알림톡 지갑 잔액부족 7436 /
     * 후불 한도초과 9071 (D3 자체 알림 대상).
     *
     * 셋 다 "잔액·한도 소진으로 발송 불가" 성격이라 동일하게 관리자 자체 알림을 발화한다.
     * 표시 사유는 result_codes lang 으로 각각 구분된다(9071 = 후불 한도 초과).
     */
    private const BALANCE_LOW_CODES = ['9070', '7436', '9071'];

    /**
     * 결과 코드를 카테고리로 분류합니다.
     *
     * @param  string  $code  결과 코드
     * @return ResultCategory 성공/재시도/잔액부족/영구실패
     */
    public function categorize(string $code): ResultCategory
    {
        if (in_array($code, self::SUCCESS_CODES, true)) {
            return ResultCategory::Success;
        }

        if (in_array($code, self::BALANCE_LOW_CODES, true)) {
            return ResultCategory::BalanceLow;
        }

        if (in_array($code, self::RETRYABLE_CODES, true)) {
            return ResultCategory::Retry;
        }

        return ResultCategory::PermanentFailure;
    }

    /**
     * 결과 코드가 성공인지 여부.
     *
     * @param  string  $code  결과 코드
     * @return bool
     */
    public function isSuccess(string $code): bool
    {
        return $this->categorize($code) === ResultCategory::Success;
    }

    /**
     * 결과 코드가 잔액 부족인지 여부 (D3 자체 알림 트리거).
     *
     * @param  string  $code  결과 코드
     * @return bool
     */
    public function isBalanceLow(string $code): bool
    {
        return in_array($code, self::BALANCE_LOW_CODES, true);
    }

    /**
     * 결과 코드의 사람이 읽는 사유(로케일)를 반환합니다.
     *
     * lang 에 정의된 코드면 사유를, 없으면 null 을 반환한다.
     *
     * @param  string  $code  결과 코드
     * @return string|null 사유 또는 null(미정의)
     */
    public function reason(string $code): ?string
    {
        $key = "sirsoft-message_bizppurio::result_codes.{$code}";
        $reason = __($key);

        // __() 는 미정의 시 키 문자열을 그대로 반환 → 미정의로 판정.
        return $reason === $key ? null : $reason;
    }

    /**
     * 표시용 라벨 `사유 (코드)` 을 반환합니다.
     *
     * lang 에 사유가 있으면 "사유 (코드)", 없으면 코드만 반환한다.
     *
     * @param  string  $code  결과 코드
     * @return string 표시 라벨
     */
    public function label(string $code): string
    {
        $reason = $this->reason($code);

        return $reason === null ? $code : "{$reason} ({$code})";
    }
}
