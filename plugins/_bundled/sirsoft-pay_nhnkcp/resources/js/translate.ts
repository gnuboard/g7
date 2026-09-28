// e2e:allow 번역 키 조회 도우미 — 화면 흐름이 외부 PG 결제 데이터에 의존해 브라우저 E2E 재현 불가, Vitest 회귀(__tests__/payment*Translated.test.ts)로 검증
const PLUGIN_ID = 'sirsoft-pay_nhnkcp';

/**
 * 플러그인 번역 문구를 얻습니다.
 *
 * @param key 번역 키 (플러그인 네임스페이스 이하)
 * @param fallback 번역 엔진이 없을 때 쓸 문구 ({{이름}} 자리는 params 로 채운다)
 * @param params 문구에 채울 값
 * @returns 번역된 문구
 */
export function translateNhnkcp(key: string, fallback: string, params?: Record<string, string | number>): string {
    const fill = (text: string): string => (params
        ? text.replace(/\{\{(\w+)\}\}/g, (match, name: string) => (name in params ? String(params[name]) : match))
        : text);
    const translate = (window as any)?.G7Core?.t;

    if (typeof translate !== 'function') {
        return fill(fallback);
    }

    const full = `${PLUGIN_ID}.${key}`;
    const result = params ? translate(full, params) : translate(full);

    return typeof result === 'string' && result !== full ? result : fill(fallback);
}
