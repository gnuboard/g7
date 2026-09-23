<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 환불 계좌 마스킹 규칙 (단일 출처)
 *
 * 환불 계좌는 평문으로 저장된다. 그래서 가리는 책임이 응답 계층에 있고, 그 규칙이 두 벌이면 한쪽만
 * 가려도 다른 쪽이 우회로가 된다. 표시 리소스(`MasksRefundBankAccount`)와 신청 화면의 등록 계좌
 * 프리필(`HandlesClaimActions::respondClaimableOptions()`)이 이 한 곳을 쓴다.
 *
 * 계좌번호는 뒤 4자리만, 예금주는 첫 글자만 남긴다 — 계좌만 가리면 은행코드 + 실명 + 뒤 4자리
 * 조합이 남아 특정이 가능하다.
 *
 * 가린 값이 **그대로 되돌아오는** 경로도 여기서 판정한다(`restoreRegistered()`). 신청 화면은
 * 등록 계좌를 가린 채로 채워 두고, 구매자가 손대지 않고 제출하면 가린 값이 그대로 온다 — 그 값이
 * 등록 계좌를 가린 모양과 정확히 같을 때만 원문으로 바꾼다.
 */
final class RefundBankMask
{
    /**
     * 계좌번호를 가립니다. (뒤 4자리만 남긴다)
     *
     * @param  string|null  $account  계좌번호
     * @return string|null 가린 계좌번호 (빈 값은 null)
     */
    public static function account(?string $account): ?string
    {
        if ($account === null || $account === '') {
            return null;
        }

        $length = mb_strlen($account);

        return $length <= 4 ? $account : str_repeat('*', $length - 4).mb_substr($account, -4);
    }

    /**
     * 예금주명을 가립니다. (첫 글자만 남긴다)
     *
     * @param  string|null  $holder  예금주명
     * @return string|null 가린 예금주명 (빈 값은 null)
     */
    public static function holder(?string $holder): ?string
    {
        if ($holder === null || $holder === '') {
            return null;
        }

        $length = mb_strlen($holder);

        return $length <= 1 ? $holder : mb_substr($holder, 0, 1).str_repeat('*', $length - 1);
    }

    /**
     * 등록 계좌 프리필을 가린 모양으로 바꿉니다. (은행 코드는 그대로)
     *
     * @param  array{bank_code: string, account_number: string, holder: string}|null  $prefill  등록 계좌 원문
     * @return array{bank_code: string, account_number: string|null, holder: string|null, masked: bool}|null 가린 프리필
     */
    public static function prefill(?array $prefill): ?array
    {
        if ($prefill === null) {
            return null;
        }

        return [
            'bank_code' => (string) ($prefill['bank_code'] ?? ''),
            'account_number' => self::account((string) ($prefill['account_number'] ?? '')),
            'holder' => self::holder((string) ($prefill['holder'] ?? '')),
            // 화면에 원문이 아니라는 사실을 알린다 — 손대지 않고 제출해도 서버가 원문으로 되돌린다.
            'masked' => true,
        ];
    }

    /**
     * 제출된 계좌가 등록 계좌를 가린 모양 그대로면 등록 계좌 원문으로 되돌립니다.
     *
     * 세 칸이 모두 **가린 모양과 정확히** 같을 때만 바꾼다. 구매자가 한 칸이라도 고쳤으면(가린 값과
     * 다르면) 제출값을 그대로 둔다 — 고친 값을 등록 계좌로 덮어쓰면 구매자가 바꾼 계좌가 사라진다.
     *
     * @param  array  $submitted  제출된 계좌 (`bank_code` / `account_number` / `holder`)
     * @param  array{bank_code: string, account_number: string, holder: string}|null  $registered  등록 계좌 원문
     * @return array 원문으로 되돌린 계좌 (해당 없으면 제출값 그대로)
     */
    public static function restoreRegistered(array $submitted, ?array $registered): array
    {
        if ($registered === null) {
            return $submitted;
        }

        $masked = self::prefill($registered);

        $same = (string) ($submitted['bank_code'] ?? '') === $masked['bank_code']
            && (string) ($submitted['account_number'] ?? '') === (string) $masked['account_number']
            && (string) ($submitted['holder'] ?? '') === (string) $masked['holder'];

        if (! $same) {
            return $submitted;
        }

        return [
            'bank_code' => (string) $registered['bank_code'],
            'account_number' => (string) $registered['account_number'],
            'holder' => (string) $registered['holder'],
        ];
    }
}
