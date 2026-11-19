<?php

namespace App\Http\Requests\Settings;

use App\Execution\ExecutionMode;
use App\Scheduling\WebCronPolicy;
use App\Support\AssetUrl;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * 허용값이 닫힌 설정 키의 허용값 표 (SSoT).
 *
 * 설정 저장 경로는 둘이다 — 벌크(`SaveSettingsRequest`, `POST /settings`)와 단건
 * (`UpdateSettingRequest`, `PUT /settings/{key}`). 허용값 검사를 한쪽에만 두면 다른 쪽이
 * 우회로가 되어 임의 문자열이 설정 파일에 남는다. 런타임은 폴백(`fromStringOrAuto` 등)으로
 * 파손을 막지만, 저장 단계에서 막아 뜻 모를 값이 기록되지 않게 한다.
 *
 * 허용값은 그 값을 해석하는 쪽(Enum·상수)에서 파생한다 — 여기에 리터럴을 다시 적지 않는다.
 */
final class EnumeratedSettingValues
{
    /**
     * 키별 허용값 목록을 반환합니다.
     *
     * @return array<string, list<string>> 설정 키 → 허용값 목록
     */
    public static function allowed(): array
    {
        return [
            // 자산 URL 방식 — 정적 최적화 서버 대응 (이슈 #486)
            'general.asset_url_mode' => [
                AssetUrl::MODE_EXTENSION,
                AssetUrl::MODE_EXTENSIONLESS,
            ],
            // 실행 모드 — 웹 요청 안의 커맨드 실행 방식 (#609)
            'system.execution_mode' => ExecutionMode::values(),
            // 예약 작업 실행 방식 — 주소 호출 / 서버 크론 (#609)
            'system.cron_mode' => WebCronPolicy::modes(),
        ];
    }

    /**
     * 키의 허용값 규칙을 반환합니다.
     *
     * @param  string  $key  설정 키 (예: 'system.execution_mode')
     * @return In|null 허용값 규칙, 허용값이 닫힌 키가 아니면 null
     */
    public static function ruleFor(string $key): ?In
    {
        $allowed = self::allowed()[$key] ?? null;

        return $allowed === null ? null : Rule::in($allowed);
    }
}
