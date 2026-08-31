<?php

namespace App\Http\Requests\Settings;

use App\Rules\ValidIpAllowList;
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
 * @since 7.1.0
 */
final class SettingShapeRules
{
    /**
     * 키별 전용 형태 규칙을 반환합니다.
     *
     * @return array<string, list<ValidationRule>> 설정 키 → 규칙 목록
     */
    public static function all(): array
    {
        return [
            // 웹 크론 호출을 허용할 IP·CIDR 목록 (#609) — 접근 게이트라 오타가 그대로
            // 저장되면 "넣었는데 막힌다" 가 되고 운영자는 원인을 알 수 없다.
            'system.cron_allowed_ips' => [new ValidIpAllowList],
        ];
    }

    /**
     * 키의 형태 규칙을 반환합니다.
     *
     * @param  string  $key  설정 키 (예: 'system.cron_allowed_ips')
     * @return list<ValidationRule> 규칙 목록 (없으면 빈 배열)
     */
    public static function rulesFor(string $key): array
    {
        return self::all()[$key] ?? [];
    }
}
