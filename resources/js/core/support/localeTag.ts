/**
 * 언어 코드(BCP 47) 판정과 다국어 값 대체 순서 — 화면 쪽 단일 기준.
 *
 * 백엔드 짝은 `App\Support\LocaleTag`(형식·협상)와 `App\Support\LocalizedValue`(다국어 값 대체)이며
 * 두 규칙은 같아야 한다 — SEO 봇이 받는 서버 렌더 HTML 과 SPA 화면의 글자가 같도록(#148).
 *
 * - 언어 코드는 언어팩 설치 규칙과 같다: `fil`·`fil-PH`·`zh-Hant`·`es-419`(정규형만, 20자 이하).
 * - 다국어 값(`{ ko: '…', en: '…' }`)이 비면 요청 언어 → 사이트 기본 언어(환경설정 「기본 언어」,
 *   `appConfig.siteLocale`) → 영어 → (선택) 아무 언어의 첫 값 순으로 대신한다. 빈 문자열·공백만 있는 값은 건너뛴다.
 * - 저장된 화면 언어나 회원 언어가 꺼진(지원 목록에 없는) 언어면 사이트 기본 언어로 연다.
 *
 * @since engine-v1.81.0
 */

/** 앵커 없는 패턴 본문 (백엔드 `LocaleTag::PATTERN_BODY` 와 같다) */
export const LOCALE_TAG_BODY = '[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?(?:-[a-zA-Z0-9]{5,8})?';

/** 언어 코드 최대 길이 — `language_packs.locale` varchar(20) */
export const LOCALE_TAG_MAX_LENGTH = 20;

const LOCALE_TAG_RE = new RegExp(`^${LOCALE_TAG_BODY}$`);

/**
 * 언어팩 설치 규칙에 맞는 정규형 언어 코드인지 판정합니다.
 *
 * @param tag 검사할 값
 * @returns 형식에 맞으면 true
 */
export function isLocaleTag(tag: unknown): tag is string {
  return typeof tag === 'string' && tag.length <= LOCALE_TAG_MAX_LENGTH && LOCALE_TAG_RE.test(tag);
}

/**
 * 주 언어 하위 태그를 소문자로 돌려줍니다 (`fil-PH`→`fil`, `ja_JP`→`ja`, `KO`→`ko`).
 *
 * @param tag 언어 코드
 * @returns 주 언어 (빈 값이면 빈 문자열)
 */
export function primaryLanguage(tag: string): string {
  return String(tag ?? '').split(/[-_]/)[0].toLowerCase();
}

/**
 * 프런트에 노출된 앱 설정(`appConfig`)을 읽습니다.
 *
 * @returns appConfig 객체 (없으면 undefined)
 */
export function getAppConfig(): any {
  if (typeof window === 'undefined') return undefined;
  const w = window as any;

  return w.__templateApp?.globalState?.appConfig ?? w.G7Config?.appConfig;
}

/**
 * 사이트 기본 언어(환경설정 「기본 언어」)를 돌려줍니다.
 *
 * @param appConfig 앱 설정 (생략 시 현재 값)
 * @returns 사이트 기본 언어 (모르면 undefined)
 */
export function getSiteLocale(appConfig: any = getAppConfig()): string | undefined {
  const site = appConfig?.siteLocale;

  return typeof site === 'string' && site !== '' ? site : undefined;
}

/**
 * 켜진(지원) 언어 목록을 돌려줍니다.
 *
 * @param appConfig 앱 설정 (생략 시 현재 값)
 * @returns 지원 언어 목록 (모르면 undefined)
 */
export function getActiveLocales(appConfig: any = getAppConfig()): string[] | undefined {
  const supported = appConfig?.supportedLocales;

  return Array.isArray(supported) && supported.length > 0 ? supported : undefined;
}

/**
 * 요청 언어를 지원 목록에서 고릅니다 (백엔드 `LocaleTag::match` 와 같다).
 *
 * ① 완전 일치(대소문자·`_` 무시) ② 주 언어 자체가 지원되면 그것 ③ 주 언어가 같은 첫 지원 항목.
 * `fil`/[ko, fil-PH] → `fil-PH`, `en-GB`/[ko, en] → `en`. 서버가 `Accept-Language`(= 저장된 `g7_locale`)를
 * 이 규칙으로 협상하므로, 화면도 같은 규칙으로 골라야 화면 문구와 서버 문구가 갈리지 않는다.
 *
 * @param requested 요청 언어
 * @param supported 지원 언어 목록
 * @returns 고른 지원 언어 (없으면 null)
 */
export function matchLocale(requested: string | null | undefined, supported: string[]): string | null {
  if (typeof requested !== 'string' || requested === '') return null;
  const norm = (tag: string): string => tag.replace(/_/g, '-').toLowerCase();
  const wanted = norm(requested);

  const exact = supported.find((tag) => norm(tag) === wanted);
  if (exact) return exact;

  const primary = primaryLanguage(requested);
  if (supported.includes(primary)) return primary;

  return supported.find((tag) => primaryLanguage(tag) === primary) ?? null;
}

/**
 * 화면 언어로 쓸 수 있는 언어를 고릅니다.
 *
 * 지원 목록을 모르면 후보를 그대로 쓴다(종전 동작). 후보가 지원되면 그대로, 주 언어가 같은 지원 언어가 있으면
 * 그것(`matchLocale`), 아니면(꺼진 언어) 사이트 기본 언어 → 서버가 정한 요청 언어 → 지원 목록 첫 항목 순이다.
 *
 * @param candidate 후보 언어 (저장값·회원 언어)
 * @param appConfig 앱 설정
 * @param serverLocale 서버가 이 요청에 정한 언어
 * @returns 쓸 언어
 */
export function resolveUsableLocale(
  candidate: string | null | undefined,
  appConfig: any = getAppConfig(),
  serverLocale: string | null = null,
): string {
  const supported = getActiveLocales(appConfig);
  if (!supported) {
    return candidate || serverLocale || 'ko';
  }
  if (candidate && supported.includes(candidate)) {
    return candidate;
  }
  const matched = matchLocale(candidate, supported);
  if (matched) {
    return matched;
  }

  const site = getSiteLocale(appConfig);
  if (site && supported.includes(site)) {
    return site;
  }
  if (serverLocale && supported.includes(serverLocale)) {
    return serverLocale;
  }

  return supported[0];
}

/**
 * 현재 화면 언어를 돌려줍니다.
 *
 * @returns 화면 언어 (모르면 undefined)
 */
function currentLocale(): string | undefined {
  if (typeof window === 'undefined') return undefined;
  const w = window as any;

  return w.__templateApp?.getLocale?.() ?? w.__templateApp?.getConfig?.()?.locale ?? w.G7Config?.locale;
}

/**
 * 다국어 값을 찾을 언어 순서를 돌려줍니다 (백엔드 `LocalizedValue::fallbackChain` 과 같다).
 *
 * @param locale 우선 언어 (생략 시 현재 화면 언어)
 * @returns [우선 언어, 사이트 기본 언어, 'en'] — 중복·빈 값 제거
 */
export function valueFallbackChain(locale?: string | null): string[] {
  const chain = [locale || currentLocale(), getSiteLocale(), 'en'];

  return chain.filter((value, index): value is string =>
    typeof value === 'string' && value !== '' && chain.indexOf(value) === index);
}

/**
 * 보여 줄 수 있는 값인지 판정합니다 (비어 있지 않은 문자열이거나 유한한 숫자).
 *
 * @param candidate 후보 값
 * @returns 쓸 수 있으면 true
 */
function isUsable(candidate: unknown): boolean {
  if (typeof candidate === 'number') return Number.isFinite(candidate);

  return typeof candidate === 'string' && candidate.trim() !== '';
}

/**
 * 다국어 값에서 보여 줄 글자를 고릅니다 (백엔드 `LocalizedValue::pick` 과 같은 규칙).
 *
 * @param value 다국어 값 또는 문자열
 * @param locale 우선 언어 (생략 시 현재 화면 언어)
 * @param anyAsLast 체인에서 못 찾으면 아무 언어의 첫 유효값을 쓸지
 * @returns 고른 글자 (없으면 null)
 */
export function pickLocalized(value: unknown, locale?: string | null, anyAsLast = true): string | null {
  if (typeof value === 'string') return value;
  if (value === null || typeof value !== 'object') return null;

  const map = value as Record<string, unknown>;
  for (const candidateLocale of valueFallbackChain(locale)) {
    if (isUsable(map[candidateLocale])) {
      return String(map[candidateLocale]);
    }
  }

  if (anyAsLast) {
    for (const candidate of Object.values(map)) {
      if (isUsable(candidate)) {
        return String(candidate);
      }
    }
  }

  return null;
}
