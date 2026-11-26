<?php

namespace App\Http\Requests\Settings;

use App\Rules\ValidIpAllowList;
use App\Rules\ValidTrustedProxyAddress;
use App\Thumbnails\ThumbnailConfig;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 값의 **형태**에 전용 규칙이 붙는 설정 키의 표 (SSoT).
 *
 * 형제 표는 EnumeratedSettingValues 다 — 그쪽은 "허용값이 닫힌" 키(열거형)를, 이쪽은
 * 열거할 수는 없지만 형태가 정해진 키(IP 목록·주소 형식 등)를 담는다.
 *
 * 두 표 모두 저장 경로 **양쪽**이 공유한다 — 벌크(`SaveSettingsRequest`, `POST /settings`)와
 * 단건(`UpdateSettingRequest`, `PUT /settings/{key}`). 한쪽에만 두면 다른 쪽이 우회로가
 * 되어 판정기가 조용히 매칭에 실패하는 값이 저장된다. 단건 경로는 값의 타입·길이만
 * 보므로, 그 경로에서 이 표가 빠지면 형태 검증이 통째로 사라진다.
 *
 * 값이 목록인 키는 두 표로 나눈다 — `all()` 은 값 전체에, `itemRules()` 는 목록의 각
 * 항목(`value.*`)에 건다.
 *
 * @since 7.1.0
 */
final class SettingShapeRules
{
    /**
     * 키별 전용 형태 규칙을 반환합니다.
     *
     * @return array<string, list<ValidationRule|string>> 설정 키 → 규칙 목록
     */
    public static function all(): array
    {
        return [
            // 웹 크론 호출을 허용할 IP·CIDR 목록 — 접근 게이트라 오타가 그대로
            // 저장되면 "넣었는데 막힌다" 가 되고 운영자는 원인을 알 수 없다.
            'system.cron_allowed_ips' => [new ValidIpAllowList],

            // 신뢰 프록시 목록 — 벌크(`advanced.trusted_proxies`)와 같은 형태.
            // 문자열을 받으면 항목 검증(`value.*`)이 걸리지 않아 아무 값이나 저장된다.
            'debug.trusted_proxies' => ['array', 'max:50'],

            // 썸네일 서버 보호 값 — 기본값이 비운 값(null)이라 단건 경로는 선언 타입으로 정수를 강제하지 못한다.
            // 범위는 화면 칸과 같은 `core.settings_limits` 이고, 근거는 config/core.php 「이미지 썸네일」 절에 있다.
            ...self::thumbnailServerLimitRules(),
        ];
    }

    /**
     * 썸네일 서버 보호 값(`ThumbnailConfig::SERVER_LIMIT_KEYS`)의 정수·범위 규칙을 만듭니다.
     *
     * @return array<string, list<string>> 설정 키 → 규칙 목록
     */
    private static function thumbnailServerLimitRules(): array
    {
        $rules = [];

        foreach (ThumbnailConfig::SERVER_LIMIT_KEYS as $key) {
            $rules['upload.thumbnail_'.$key] = [
                'integer',
                'min:'.config("core.settings_limits.upload_thumbnail_{$key}_min"),
                'max:'.config("core.settings_limits.upload_thumbnail_{$key}_max"),
            ];
        }

        return $rules;
    }

    /**
     * 값이 목록인 키의 항목별 형태 규칙을 반환합니다.
     *
     * @return array<string, list<ValidationRule|string>> 설정 키 → 항목 규칙 목록
     */
    public static function itemRules(): array
    {
        return [
            // 틀려도 오류 없이 방문자 IP 만 잘못 기록되는 설정이라 저장 단계에서 막는다.
            'debug.trusted_proxies' => ['string', 'max:60', new ValidTrustedProxyAddress],
        ];
    }

    /**
     * 키의 형태 규칙을 반환합니다.
     *
     * @param  string  $key  설정 키 (예: 'system.cron_allowed_ips')
     * @return list<ValidationRule|string> 규칙 목록 (없으면 빈 배열)
     */
    public static function rulesFor(string $key): array
    {
        return self::all()[$key] ?? [];
    }

    /**
     * 키의 항목별 형태 규칙을 반환합니다.
     *
     * @param  string  $key  설정 키 (예: 'debug.trusted_proxies')
     * @return list<ValidationRule|string> 항목 규칙 목록 (없으면 빈 배열)
     */
    public static function itemRulesFor(string $key): array
    {
        return self::itemRules()[$key] ?? [];
    }
}
