<?php

namespace App\Support;

/**
 * 민감 설정값(sensitive: true)의 응답 마스킹 규약.
 *
 * 관리자 설정 조회 응답은 저장된 비밀값(암호화 키, API 시크릿 등)을 평문으로 되돌려주지 않는다.
 * 값이 저장되어 있다는 사실만 마스크로 알리고, 실제 값은 서버 안에만 둔다 — 화면·브라우저 개발자
 * 도구·프록시 로그에 비밀값이 남지 않게 하기 위함이다.
 *
 * 저장 쪽과 짝을 이룬다: 운영자가 값을 건드리지 않으면 화면이 마스크를 그대로 되돌려 보내므로,
 * 저장 단계에서 마스크를 걸러내 기존 값을 보존한다. 빈 문자열은 마스크가 아니라 "지우겠다" 는
 * 명시적 의사로 취급한다.
 *
 * 내부 소비자(provider 에 자격증명을 주입하는 listener 등)는 마스킹되지 않은 값을 그대로 받는다 —
 * 마스킹은 오직 관리자 API 응답 경계에서만 적용한다.
 *
 * @since 7.0.6
 */
final class SensitiveSettingMask
{
    /**
     * 저장된 비밀값을 대신하는 마스크 문자열.
     *
     * 실제 값으로 오인될 수 없도록 통상적인 키·시크릿 문자셋에 없는 형태를 쓴다.
     */
    public const MASK = '••••••••';

    /**
     * 스키마의 sensitive 필드를 마스크로 치환한다.
     *
     * 값이 비어 있는 필드는 마스크를 씌우지 않는다 — 화면이 "아직 입력되지 않음" 을 구분해야 한다.
     *
     * @param  array<string, mixed>  $settings  설정 배열 (복호화된 상태)
     * @param  array<string, mixed>  $schema  설정 스키마 (sensitive 플래그 보유)
     * @return array<string, mixed> 마스킹된 설정 배열
     */
    public static function apply(array $settings, array $schema): array
    {
        foreach ($schema as $field => $config) {
            if (! is_array($config) || ! ($config['sensitive'] ?? false)) {
                continue;
            }
            if (! array_key_exists($field, $settings)) {
                continue;
            }
            if ($settings[$field] === null || $settings[$field] === '') {
                continue;
            }

            $settings[$field] = self::MASK;
        }

        return $settings;
    }

    /**
     * 저장 요청에서 마스크로 되돌아온 sensitive 필드를 제거한다.
     *
     * 제거된 필드는 저장 단계의 기존 설정 병합에서 종전 값이 유지된다.
     *
     * @param  array<string, mixed>  $settings  저장 요청 설정 배열
     * @param  array<string, mixed>  $schema  설정 스키마
     * @return array<string, mixed> 마스크 필드가 제거된 설정 배열
     */
    public static function stripUnchanged(array $settings, array $schema): array
    {
        foreach ($schema as $field => $config) {
            if (! is_array($config) || ! ($config['sensitive'] ?? false)) {
                continue;
            }
            if (($settings[$field] ?? null) === self::MASK) {
                unset($settings[$field]);
            }
        }

        return $settings;
    }
}
