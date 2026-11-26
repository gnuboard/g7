<?php

namespace App\Support;

/**
 * 신뢰 프록시 저장값 ↔ 런타임 설정값 변환
 *
 * 같은 사실이 세 가지 모양으로 존재한다.
 *
 *   ① `.env` / `config/trustedproxy.php` : 콤마 문자열 (`'*'`, `'10.0.0.1,10.0.0.2/24'`) 또는 null
 *   ② 저장값 `storage/app/settings/debug.json` : 태그 배열 (`[]`, `['*']`, `['10.0.0.1', …]`)
 *   ③ 화면 : 모드 3지(신뢰 안 함 / 직전 프록시만 / 지정 목록) + 태그 입력
 *
 * 변환을 쓰는 곳이 넷이라(Provider 주입 · 설정 응답 · 저장 검증 · 사전 검사 API) 한 곳이
 * 어긋나면 화면과 실제 적용값이 조용히 갈린다 — 이 설정은 틀려도 오류를 내지 않고
 * 방문자 IP 만 잘못 기록되므로 갈린 것을 알아차릴 단서가 없다. 그래서 변환은 여기 한 곳이
 * 단독으로 소유한다.
 *
 * **null 과 빈 배열은 다르다.**
 *   - `null`  : 화면에서 한 번도 정한 적 없음 → 주입하지 않는다(그 설치의 권위는 `.env`).
 *   - `[]`    : 운영자가 「신뢰하지 않음」을 명시적으로 고름 → 주입한다(`.env` 를 덮는다).
 *
 * 이 구분이 없으면 `.env` 로 프록시를 설정해 둔 기존 설치가, 고급 탭의 **무관한** 항목을
 * 저장하는 순간 신뢰 설정을 잃는다. 오류도 로그도 없이.
 *
 * 순수 변환만 한다 — 설정 읽기·주입·검증 판정은 호출처(Provider·Request·Controller)가 갖는다.
 *
 * @since 7.1.0
 */
final class TrustedProxySetting
{
    /** 아무 프록시도 신뢰하지 않음 (저장값 `[]`) */
    public const MODE_NONE = 'none';

    /** 직전 호출 IP 하나만 신뢰 (저장값 `['*']`) */
    public const MODE_NEAREST = 'nearest';

    /** 지정한 IP·CIDR 만 신뢰 (저장값 `['10.0.0.1', …]`) */
    public const MODE_LIST = 'list';

    /**
     * 직전 호출 IP 만 신뢰하는 와일드카드 표기.
     *
     * `'**'` 는 Laravel 내장 미들웨어에서 `'*'` 과 같게 동작하는 호환 표기다(과거 fideloper
     * 패키지 시절 구분이 있었다). 저장·화면에서는 `'*'` 하나로 정규화한다 — 두 표기를 그대로
     * 두면 같은 설정이 화면에서 서로 다른 모드로 보인다.
     *
     * @var array<int, string>
     */
    private const NEAREST_TOKENS = ['*', '**'];

    /**
     * 어떤 모양의 값이든 태그 배열로 정규화합니다.
     *
     * @param  mixed  $value  콤마 문자열 · 배열 · null
     * @return array<int, string> 정규화된 태그 목록 (빈 토큰 제거, `**` → `*`)
     */
    public static function toList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $tokens = is_array($value) ? $value : explode(',', (string) $value);

        $normalized = [];

        foreach ($tokens as $token) {
            if (! is_scalar($token)) {
                continue;
            }

            $token = trim((string) $token);

            if ($token === '') {
                continue;
            }

            $normalized[] = in_array($token, self::NEAREST_TOKENS, true) ? '*' : $token;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * 저장값(태그 배열)을 `trustedproxy.proxies` 에 넣을 값으로 바꿉니다.
     *
     * 빈 목록은 null 이 됩니다 — 미들웨어에게 "아무도 신뢰하지 않음" 은 미설정과 같은 상태이고,
     * 빈 문자열을 넘기면 `explode()` 가 빈 토큰 하나짜리 목록을 만들어 의미가 흐려집니다.
     *
     * @param  array<int, string>  $list  태그 목록
     * @return string|null 설정값 (`'*'` · 콤마 문자열 · null)
     */
    public static function toConfigValue(array $list): ?string
    {
        $list = self::toList($list);

        if ($list === []) {
            return null;
        }

        // 와일드카드가 섞여 있으면 그것이 목록 전체를 삼킨다 — 직전 IP 를 신뢰하는 순간
        // 나머지 항목은 판정에 쓰이지 않는다. 저장값을 그대로 흘려 보내면 화면은
        // 「지정 목록」으로 보이는데 실제로는 와일드카드로 동작하는 상태가 된다.
        if (in_array('*', $list, true)) {
            return '*';
        }

        return implode(',', $list);
    }

    /**
     * 태그 목록이 화면의 어느 모드에 해당하는지 판정합니다.
     *
     * @param  array<int, string>  $list  태그 목록
     * @return string MODE_NONE · MODE_NEAREST · MODE_LIST
     */
    public static function modeOf(array $list): string
    {
        $list = self::toList($list);

        if ($list === []) {
            return self::MODE_NONE;
        }

        if (in_array('*', $list, true)) {
            return self::MODE_NEAREST;
        }

        return self::MODE_LIST;
    }

    /**
     * 태그 하나가 신뢰 프록시 값으로 쓸 수 있는 형태인지 판정합니다.
     *
     * 허용: 와일드카드 `*` · IPv4/IPv6 주소 · CIDR 표기.
     *
     * @param  string  $token  검사할 태그
     * @return bool 사용 가능 여부
     */
    public static function isValidToken(string $token): bool
    {
        $token = trim($token);

        if ($token === '') {
            return false;
        }

        if (in_array($token, self::NEAREST_TOKENS, true)) {
            return true;
        }

        if (! str_contains($token, '/')) {
            return filter_var($token, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $token, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // 프리픽스 길이 상한은 주소 계열마다 다르다 — IPv4 에 `/64` 를 허용하면 아무 것도
        // 매칭되지 않는 목록이 저장되고, 그 상태가 화면에는 정상으로 보인다.
        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return preg_match('/^\d{1,3}$/', $prefix) === 1
            && (int) $prefix >= 0
            && (int) $prefix <= $max;
    }
}
