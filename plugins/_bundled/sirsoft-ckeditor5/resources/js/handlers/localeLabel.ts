/**
 * CKEditor 다국어 탭의 언어 이름
 *
 * 언어팩으로 켠 `fil`·`fil-PH`·`zh-Hant` 탭이 내장 표에 없어 `FIL-PH` 처럼 코드로만 보이던 것(#148)을,
 * 서버가 주는 언어팩의 언어 이름(`appConfig.localeNames` — manifest 의 `locale_native_name`)으로 먼저 보인다.
 * 그 이름은 언어팩 작성자가 정한 문자열이므로 탭은 `innerHTML` 이 아니라 요소·`textContent` 로만 만든다.
 *
 * @module localeLabel
 */

/** 언어팩·서버 이름이 없을 때 쓰는 내장 이름 */
const LOCALE_LABELS: Record<string, string> = {
    ko: '한국어',
    en: 'English',
    ja: '日本語',
    zh: '中文',
    fr: 'Français',
    de: 'Deutsch',
    es: 'Español',
};

/**
 * locale 코드를 표시 이름으로 변환합니다.
 *
 * 순서: 언어팩 이름(`appConfig.localeNames`) → 내장 표 → 코드 그대로(대문자로 바꾸지 않는다).
 *
 * @param locale 언어 코드
 * @returns 표시 이름
 */
export function getLocaleLabel(locale: string): string {
    const w = window as any;
    const fromState = w.G7Core?.state?.get?.()?.appConfig?.localeNames?.[locale];
    const fromConfig = w.G7Config?.appConfig?.localeNames?.[locale];
    const fromPack = typeof fromState === 'string' && fromState ? fromState : fromConfig;
    if (typeof fromPack === 'string' && fromPack !== '') {
        return fromPack;
    }

    return LOCALE_LABELS[locale] ?? locale;
}

/**
 * 다국어 탭 버튼의 내용(아이콘·언어 이름·기본 언어 표시)을 채웁니다.
 *
 * 언어 이름은 `textContent` 로만 넣는다 — 언어팩 문자열에 든 마크업이 요소로 해석되지 않게.
 *
 * @param tab 탭 버튼 요소
 * @param locale 언어 코드
 * @param isDefault 기본(필수) 언어 여부
 * @returns 반환값 없음
 */
export function buildLocaleTabContent(tab: HTMLElement, locale: string, isDefault: boolean): void {
    tab.replaceChildren();

    const icon = document.createElement('i');
    icon.className = 'fas fa-globe ck5-lang-icon';
    tab.appendChild(icon);

    const label = document.createElement('span');
    label.textContent = getLocaleLabel(locale);
    tab.appendChild(label);

    if (isDefault) {
        const required = document.createElement('span');
        required.className = 'ck5-required';
        required.textContent = '*';
        tab.appendChild(required);
    }
}
