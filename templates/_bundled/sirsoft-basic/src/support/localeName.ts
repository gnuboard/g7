/**
 * 언어 코드 표시 이름
 *
 * 사용자 헤더 언어 메뉴·선택 상자가 쓰는 언어 이름을 한 곳에서 고른다(관리자 템플릿 support/localeName 과 같은 규칙). 언어팩으로 켠 `fil`·
 * `fil-PH`·`zh-Hant` 같은 언어가 내장 표에 없어 `FIL`·`FIL-PH` 처럼 코드로만 보이던 것(#148)을, 서버가
 * 주는 언어팩의 언어 이름(`appConfig.localeNames` — 언어팩 manifest 의 `locale_native_name`)으로 먼저 보인다.
 *
 * 순서: `appConfig.localeNames[code]` → 번역 `common.language_{code}` → 내장 표 → 코드 그대로(대문자로 바꾸지 않는다).
 *
 * @param code 언어 코드
 * @returns 표시 이름
 */
export function getLocaleDisplayName(code: string): string {
  const w = window as any;
  const fromState = w.G7Core?.state?.get?.()?.appConfig?.localeNames?.[code];
  const fromConfig = w.G7Config?.appConfig?.localeNames?.[code];
  const fromPack = typeof fromState === 'string' && fromState ? fromState : fromConfig;
  if (typeof fromPack === 'string' && fromPack !== '') {
    return fromPack;
  }

  const key = `common.language_${code}`;
  const translated = w.G7Core?.t?.(key);
  if (typeof translated === 'string' && translated !== '' && translated !== key) {
    return translated;
  }

  return BUILTIN_LOCALE_NAMES[code] ?? code;
}

/** 언어팩·번역이 없을 때 쓰는 내장 이름 */
const BUILTIN_LOCALE_NAMES: Record<string, string> = {
  ko: '한국어',
  en: 'English',
  ja: '日本語',
  zh: '中文',
  es: 'Español',
  fr: 'Français',
  de: 'Deutsch',
};
