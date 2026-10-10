<?php

namespace Plugins\Sirsoft\Totp\Support;

use InvalidArgumentException;

/**
 * RFC 4648 Base32 인코더/디코더.
 *
 * TOTP 시크릿은 인증 앱이 읽을 수 있어야 하므로 otpauth URI 와 수동 입력 모두 Base32 로
 * 표기합니다. PHP 표준 라이브러리에 Base32 가 없고 이 한 가지 용도에 composer 의존성을
 * 더하지 않기 위해 직접 구현합니다 (~40줄).
 *
 * 패딩(`=`)은 생성 시 붙이지 않고 해석 시 무시합니다 — 주요 인증 앱(Google Authenticator,
 * Authy, 1Password)이 모두 패딩 없는 표기를 받아들이고, 사람이 손으로 옮겨 적을 때
 * 패딩 문자가 오탈자 원인이 되기 때문입니다.
 *
 * @since 1.0.0
 */
final class Base32
{
    /** RFC 4648 표준 알파벳 (숫자 0·1·8 과 문자 I·L·O 를 배제한 32자) */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * 바이트 문자열을 Base32 로 인코딩합니다.
     *
     * @param  string  $bytes  원본 바이트
     * @return string 패딩 없는 Base32 문자열
     */
    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            // 마지막 조각이 5비트에 못 미치면 0 으로 채운다 (RFC 4648 §6).
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $output;
    }

    /**
     * Base32 문자열을 바이트로 디코딩합니다.
     *
     * 대소문자를 가리지 않고, 패딩(`=`)과 사람이 넣기 쉬운 공백·하이픈은 무시합니다.
     *
     * @param  string  $base32  Base32 문자열
     * @return string 디코딩된 바이트
     *
     * @throws InvalidArgumentException 알파벳 밖의 문자가 있을 때
     */
    public static function decode(string $base32): string
    {
        $normalized = strtoupper(str_replace(['=', ' ', '-'], '', $base32));

        if ($normalized === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($normalized) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                // 개발자용 계약 위반 메시지 — 사용자에게 도달하지 않는다(호출부가 잡아 안내 문구로 바꾼다).
                throw new InvalidArgumentException('Base32 alphabet violation.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        // 8비트로 떨어지지 않는 꼬리는 인코딩 시 채운 패딩 비트이므로 버린다.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }

    /**
     * 암호학적으로 안전한 난수로 새 시크릿을 만듭니다.
     *
     * @param  int  $bytes  엔트로피 바이트 수 (기본 20 = 160비트, RFC 4226 §4 권장치)
     * @return string Base32 표기 시크릿
     */
    public static function randomSecret(int $bytes = 20): string
    {
        return self::encode(random_bytes($bytes));
    }
}
