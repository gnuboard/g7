<?php

namespace App\Support;

use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

/**
 * 선택 연동 선언 (`integrations`) 정규화
 *
 * `dependencies` 는 「없으면 설치·활성화할 수 없다」이고, `integrations` 는 「있으면 연동되고 없어도 동작한다」입니다.
 * 예: 문자 발송 플러그인이 쇼핑몰·게시판의 알림 설정 화면에 탭을 끼우지만, 그 확장이 없어도 플러그인은 섭니다.
 * 설치를 막지 않고 관리자 확장 상세에 「함께 쓰면 연동되는 확장」으로 보이며, 정적 검사는 `dependencies` 에도
 * `integrations` 에도 없는 확장 참조를 오류로 봅니다.
 *
 * 모양: `{ "modules": ["sirsoft-ecommerce"], "plugins": [], "templates": [] }` 또는 버전 제약 객체형
 * `{ "modules": {"sirsoft-board": ">=1.2.1"} }` (7.1.4). 목록형은 「버전 무관」이고, 객체형 값이 버전식이 아닌 문자열
 * (옛 설명형)이면 「버전 무관」으로 받고 경고를 남깁니다. 버전이 모자라도 설치·활성화는 막지 않고 안내만 합니다.
 *
 * @since 7.1.0
 */
class ExtensionIntegrations
{
    /** 연동 대상 유형 */
    public const TYPES = ['modules', 'plugins', 'templates'];

    /** 버전 제약이 없을 때(목록형·설명형)의 제약 문자열 */
    public const ANY_VERSION = '*';

    /** 확장 식별자 규칙 (vendor-name) */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9_]*-[a-z0-9][a-z0-9_]*$/';

    /**
     * 같은 프로세스에서 이미 경고한 설명형 값 (같은 경고를 요청마다 반복하지 않는다)
     *
     * @var array<string, true>
     */
    private static array $warned = [];

    /**
     * manifest 의 `integrations` 선언을 정규화합니다. 모르는 유형·형식이 틀린 식별자는 버립니다.
     *
     * 객체 형식(`{"sirsoft-board": "설명"}`)도 받아 키를 식별자로 씁니다.
     *
     * @param  mixed  $raw  manifest 값
     * @return array{modules: array<int, string>, plugins: array<int, string>, templates: array<int, string>}
     *                                                                                                        정규화한 선언
     */
    public static function normalize(mixed $raw): array
    {
        $normalized = array_fill_keys(self::TYPES, []);

        if (! is_array($raw)) {
            return $normalized;
        }

        foreach (self::TYPES as $type) {
            $list = $raw[$type] ?? [];
            if (! is_array($list)) {
                continue;
            }

            $identifiers = array_is_list($list) ? $list : array_keys($list);
            foreach ($identifiers as $identifier) {
                if (is_string($identifier) && preg_match(self::IDENTIFIER_PATTERN, $identifier) === 1) {
                    $normalized[$type][] = $identifier;
                }
            }

            $normalized[$type] = array_values(array_unique($normalized[$type]));
        }

        return $normalized;
    }

    /**
     * 확장 유형 이름을 선언 키(복수형)로 바꿉니다. 모르는 유형이면 null 입니다.
     *
     * @param  string  $type  module|plugin|template (복수형도 받음)
     * @return string|null modules|plugins|templates 또는 null
     */
    public static function pluralType(string $type): ?string
    {
        $plural = str_ends_with($type, 's') ? $type : $type.'s';

        return in_array($plural, self::TYPES, true) ? $plural : null;
    }

    /**
     * manifest 의 `integrations` 선언을 유형별 「식별자 => 버전 제약」 맵으로 정규화합니다 (7.1.4).
     *
     * 목록형(`["sirsoft-board"]`)과 버전식이 아닌 값은 `'*'`(버전 무관)이 됩니다. 버전식이 아닌 문자열(옛 설명형)은
     * 한 번 경고를 남깁니다. 모르는 유형·형식이 틀린 식별자는 {@see normalize()} 와 같이 버립니다.
     *
     * @param  mixed  $raw  manifest 값
     * @return array{modules: array<string, string>, plugins: array<string, string>, templates: array<string, string>} 정규화한 제약 맵
     */
    public static function constraints(mixed $raw): array
    {
        $constraints = array_fill_keys(self::TYPES, []);

        if (! is_array($raw)) {
            return $constraints;
        }

        foreach (self::TYPES as $type) {
            $list = $raw[$type] ?? [];
            if (! is_array($list)) {
                continue;
            }

            $entries = array_is_list($list) ? array_fill_keys(array_filter($list, 'is_string'), null) : $list;
            foreach ($entries as $identifier => $value) {
                $identifier = (string) $identifier;
                if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1 || isset($constraints[$type][$identifier])) {
                    continue;
                }

                $constraints[$type][$identifier] = self::toConstraint($type, $identifier, $value);
            }
        }

        return $constraints;
    }

    /**
     * 선언 값 하나를 버전 제약 문자열로 바꿉니다. 버전식이 아니면 `'*'` 입니다.
     *
     * @param  string  $type  연동 대상 유형 (modules|plugins|templates)
     * @param  string  $identifier  연동 대상 식별자
     * @param  mixed  $value  선언 값
     * @return string 버전 제약 문자열
     */
    private static function toConstraint(string $type, string $identifier, mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return self::ANY_VERSION;
        }

        $value = trim($value);
        if (self::isVersionConstraint($value)) {
            return $value;
        }

        self::warnDescriptive($type, $identifier, $value);

        return self::ANY_VERSION;
    }

    /**
     * 문자열이 Composer 버전 제약식인지 판정합니다.
     *
     * @param  string  $value  검사할 문자열
     * @return bool 버전 제약식이면 true
     */
    public static function isVersionConstraint(string $value): bool
    {
        try {
            (new VersionParser)->parseConstraints($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 버전식이 아닌 값을 「버전 무관」으로 받았다는 경고를 프로세스당 한 번 남깁니다.
     *
     * @param  string  $type  연동 대상 유형
     * @param  string  $identifier  연동 대상 식별자
     * @param  string  $value  선언 값
     * @return void
     */
    private static function warnDescriptive(string $type, string $identifier, string $value): void
    {
        $key = $type.':'.$identifier.':'.$value;
        if (isset(self::$warned[$key]) || Facade::getFacadeApplication() === null) {
            return;
        }

        self::$warned[$key] = true;
        Log::warning('[integrations] 버전식이 아닌 값을 버전 무관(*)으로 받습니다', [
            'type' => $type,
            'identifier' => $identifier,
            'value' => $value,
        ]);
    }
}
