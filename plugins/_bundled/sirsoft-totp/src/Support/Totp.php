<?php

namespace Plugins\Sirsoft\Totp\Support;

/**
 * RFC 6238 TOTP (Time-Based One-Time Password) 계산기.
 *
 * 파라미터는 **고정**입니다 — 6자리 / 30초 / SHA1. 모든 주요 인증 앱이 이 조합만 QR 로
 * 정확히 읽고, 설정으로 열어 두면 운영자가 바꾼 뒤 "앱이 만든 코드가 안 맞는다" 로만
 * 드러나는 고장을 만듭니다.
 *
 * 외부 의존성 없이 PHP 표준 `hash_hmac` 만 사용합니다.
 *
 * @since 1.0.0
 */
final class Totp
{
    /** 코드 자릿수 (RFC 6238 권장, 인증 앱 공통) */
    public const DIGITS = 6;

    /** 한 코드의 유효 구간(초) */
    public const PERIOD = 30;

    /** HMAC 알고리즘 */
    public const ALGORITHM = 'sha1';

    /**
     * 주어진 timestep 의 코드를 계산합니다.
     *
     * @param  string  $secret  Base32 표기 시크릿
     * @param  int  $timestep  유닉스시각 / PERIOD
     * @return string 앞자리 0 을 포함한 6자리 코드
     */
    public static function codeAt(string $secret, int $timestep): string
    {
        $key = Base32::decode($secret);

        // counter 는 8바이트 빅엔디언 (RFC 4226 §5.1)
        $counter = pack('J', $timestep);

        $hmac = hash_hmac(self::ALGORITHM, $counter, $key, true);

        // 동적 절단 (RFC 4226 §5.3) — 마지막 바이트의 하위 4비트가 오프셋
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;

        $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 현재 시각의 timestep 을 반환합니다.
     *
     * @param  int|null  $timestamp  기준 유닉스시각 (null 이면 현재)
     * @return int timestep
     */
    public static function timestepAt(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    /**
     * 코드를 검증하고, 맞으면 그 코드가 속한 timestep 을 돌려줍니다.
     *
     * `$window` 는 앞뒤로 허용할 timestep 수입니다 (기본 1 = ±30초). 사용자 단말 시계가
     * 조금 어긋나 있어도 통과시키되, 넓히면 탈취된 코드의 유효 시간이 함께 늘어납니다.
     *
     * `$notBeforeTimestep` 이 주어지면 **그보다 작거나 같은 timestep 은 거부**합니다 —
     * 리플레이 방지. 이 값이 없으면 방금 쓴 코드를 30초 안에 다시 제출해 재사용할 수 있습니다.
     *
     * 비교는 `hash_equals` 로 수행해 타이밍 공격으로 자릿수를 흘리지 않습니다.
     *
     * @param  string  $secret  Base32 표기 시크릿
     * @param  string  $code  사용자가 입력한 코드
     * @param  int  $window  허용 timestep 오차 (기본 1)
     * @param  int|null  $notBeforeTimestep  이 timestep 이하는 거부 (마지막 사용 timestep)
     * @param  int|null  $timestamp  기준 유닉스시각 (테스트용, null 이면 현재)
     * @return int|null 매치된 timestep, 불일치면 null
     */
    public static function verify(
        string $secret,
        string $code,
        int $window = 1,
        ?int $notBeforeTimestep = null,
        ?int $timestamp = null,
    ): ?int {
        $normalized = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($normalized) !== self::DIGITS) {
            return null;
        }

        $current = self::timestepAt($timestamp);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;

            if ($notBeforeTimestep !== null && $step <= $notBeforeTimestep) {
                continue;
            }

            if (hash_equals(self::codeAt($secret, $step), $normalized)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * 인증 앱이 QR 로 읽는 otpauth URI 를 만듭니다.
     *
     * 형식: `otpauth://totp/{issuer}:{account}?secret=…&issuer=…&algorithm=…&digits=…&period=…`
     * (Key Uri Format — google/google-authenticator wiki)
     *
     * @param  string  $secret  Base32 표기 시크릿
     * @param  string  $account  계정 표기 (보통 이메일)
     * @param  string  $issuer  발급자 표기 (사이트 이름)
     * @return string otpauth URI
     */
    public static function otpauthUri(string $secret, string $account, string $issuer): string
    {
        // label 의 issuer 접두사와 issuer 파라미터를 함께 넣는 것이 권장 표기다 —
        // 일부 앱은 label 만, 일부는 파라미터만 읽는다.
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/'.$label.'?'.$query;
    }
}
