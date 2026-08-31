/**
 * 관리자 화면의 현재 로케일(_global.locale) 주입 핸들러
 *
 * 레이아웃 표현식에는 "지금 어느 로케일인가" 를 알려 주는 변수가 없다. `$locale` 은
 * DynamicRenderer 가 **편집 모드에서만** 주입하고(`DynamicRenderer.tsx:1826-1845`),
 * `_global.locale` 은 코어가 한 번도 쓰지 않는다(`TemplateApp` 의 globalState 는
 * `sidebarOpen` + `loadG7Config()` 의 settings/plugins/modules/appConfig 뿐).
 * 그래서 저장소 관례인 `$locale ?? _global.locale ?? 'ko'` 체인은 지금 언제나 마지막
 * 항에 떨어져 **어떤 로케일에서도 'ko' 로 굳는다**.
 *
 * 표시용이라면 `$localized()` 로 충분하지만, 이 값이 필요한 자리는 표시가 아니다:
 *   - 사유·배송사·배송유형 추가 폼의 확정 게이트 — 서버가 요구하는 것은 **현재 로케일**
 *     이름이다(`StoreEcommerceSettingsRequest` 가 `app()->getLocale()` 로 판정).
 *     `$localized()` 는 ko/en 폴백이 있어 "일본어 칸이 비었는데 한국어가 차 있으면 통과"
 *     시켜 버리므로 게이트로 쓸 수 없다.
 *   - 저장 실패 시 필드 오류 조회 키 — 서버 422 는 `...name.{현재로케일}` 로 온다.
 *     ko 고정으로 찾으면 비-ko 관리자에게는 **필드 오류가 아예 표시되지 않는다**.
 *
 * 그래서 핸들러가 `G7Core.locale.current()`(= `TemplateApp.getConfig().locale`, 요청의
 * `Accept-Language` 로 나가는 바로 그 값)를 읽어 `_global.locale` 에 넣는다. 20개 표현식은
 * 손대지 않고 관례 체인이 그대로 성립한다.
 *
 * 표준 `loadFromLocalStorage` 를 쓰지 않는 이유는 `initPreferredCurrency` 와 같다 —
 * 그것은 React 컴포넌트 state 만 갱신해 컴포넌트 컨텍스트가 없는 init_actions 에서는
 * _global 승격이 실패한다. `G7Core.state.set` 으로 동기 주입한다.
 *
 * 로케일 전환(`TemplateApp.changeLocale`)은 페이지를 새로고침하지 않고 템플릿 엔진을
 * 재초기화하므로 레이아웃이 다시 마운트되고 init_actions 가 다시 실행된다 — 그때 이
 * 핸들러가 새 로케일로 덮어쓴다.
 */

const LOCALE_STORAGE_NAME = 'g7_locale';
const FALLBACK_LOCALE = 'ko';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Handler:InitAdminLocale')) ?? {
    log: (...args: unknown[]) => console.log('[Handler:InitAdminLocale]', ...args),
    warn: (...args: unknown[]) => console.warn('[Handler:InitAdminLocale]', ...args),
    error: (...args: unknown[]) => console.error('[Handler:InitAdminLocale]', ...args),
};

/**
 * 현재 로케일을 해석합니다.
 *
 * 우선순위:
 *   1. `G7Core.locale.current()` — TemplateApp 이 확정한 값(요청 Accept-Language 와 동일)
 *   2. localStorage `g7_locale` — G7Core 미초기화 시점 대비
 *   3. 'ko'
 *
 * @returns 로케일 코드
 */
function resolveCurrentLocale(): string {
    const G7Core = (window as any).G7Core;

    const fromCore = G7Core?.locale?.current?.();
    if (typeof fromCore === 'string' && fromCore !== '') {
        return fromCore;
    }

    try {
        const stored = window.localStorage?.getItem(LOCALE_STORAGE_NAME);
        if (typeof stored === 'string' && stored !== '') {
            return stored;
        }
    } catch (error) {
        // 프라이빗 모드 등 localStorage 접근 불가 — 폴백으로 진행
        logger.warn('[initAdminLocale] localStorage 접근 실패', error);
    }

    return FALLBACK_LOCALE;
}

/**
 * 현재 로케일을 전역 상태(`_global.locale`)에 주입합니다.
 *
 * @returns 주입한 로케일 코드
 */
export function initAdminLocaleHandler(): string {
    const locale = resolveCurrentLocale();

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state?.set) {
        logger.warn('[initAdminLocale] G7Core.state API 미가용 — _global.locale 주입 생략');

        return locale;
    }

    G7Core.state.set({ locale });

    return locale;
}
