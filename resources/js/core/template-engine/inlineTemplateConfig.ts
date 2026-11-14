/**
 * 셸 HTML 에 실린 템플릿 설정(`G7Config.templateConfig`) 읽기 (@since engine-v1.95.0, #879)
 *
 * 서버는 template.json 최상위 `expose` 배열에 제작자가 선언한 키만 셸 HTML 의 `#g7-config` 에 싣는다(선언했는데
 * template.json 에 없는 키는 null — 「선언했고 값 없음」). 선언이 없는 템플릿은 `templateConfig` 자체가 없고, 엔진은
 * 종전처럼 `GET /api/templates/{id}/config` 를 부른다.
 *
 * 엔진은 **키 단위**로 판단한다 — 부팅이 읽는 키({@link TEMPLATE_BOOT_CONFIG_KEYS})가 모두 실려 있을 때만 API 를
 * 건너뛴다. 하나라도 빠지면 API 를 1회 불러 그 값을 쓴다(제작자가 선언을 빠뜨려도 값을 조용히 잃지 않게).
 */

/** 엔진 부팅(`TemplateApp.init`)이 template.json 에서 읽는 키 — 모두 실려 있으면 config API 를 부르지 않는다 */
export const TEMPLATE_BOOT_CONFIG_KEYS = ['scroll_restoration', 'errorHandling'] as const;

/** 오류 화면(`ErrorPageHandler.loadConfig`)이 template.json 에서 읽는 키 */
export const TEMPLATE_ERROR_CONFIG_KEY = 'error_config';

/**
 * 셸에 실린 템플릿 설정을 돌려줍니다.
 *
 * @returns 키 → 값 객체 (실리지 않았거나 객체가 아니면 null)
 */
export function readInlineTemplateConfig(): Record<string, unknown> | null {
    if (typeof window === 'undefined') {
        return null;
    }
    const value = (window as { G7Config?: { templateConfig?: unknown } }).G7Config?.templateConfig;

    return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

/**
 * 셸에 실린 설정이 주어진 키를 모두 가졌는지 — 값이 null 이어도 「실렸다」로 본다.
 *
 * @param config 셸에 실린 설정 (없으면 null)
 * @param keys 확인할 키
 * @returns 모두 실렸으면 true
 */
export function hasInlineTemplateConfigKeys(config: Record<string, unknown> | null, keys: readonly string[]): boolean {
    return config !== null && keys.every((key) => Object.prototype.hasOwnProperty.call(config, key));
}
