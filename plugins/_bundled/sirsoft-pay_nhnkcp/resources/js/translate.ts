const PLUGIN_ID = 'sirsoft-pay_nhnkcp';

/**
 * 플러그인 번역 문구를 얻습니다.
 *
 * @param key 번역 키 (플러그인 네임스페이스 이하)
 * @param fallback 번역 엔진이 없을 때 쓸 문구
 * @returns 번역된 문구
 */
export function translateNhnkcp(key: string, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;

    if (typeof translate !== 'function') {
        return fallback;
    }

    const full = `${PLUGIN_ID}.${key}`;
    const result = translate(full);

    return typeof result === 'string' && result !== full ? result : fallback;
}
