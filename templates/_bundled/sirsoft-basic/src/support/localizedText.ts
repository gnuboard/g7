/**
 * 다국어 값(`{ ko: '…', en: '…' }`)에서 화면에 보일 글자를 고릅니다.
 *
 * 엔진 `G7Core.locale.pick()`(engine-v1.81.0) — 요청 언어 → 사이트 기본 언어 → 영어 → 첫 값, 빈 칸 건너뜀 —
 * 에 맡긴다. 한국어(ko) 값으로 고정 대체하지 않는다(#148). 엔진이 pick 을 모르면 요청 언어 → 첫 값.
 *
 * @param value 다국어 값 또는 문자열
 * @param locale 우선 언어 (생략 시 화면 언어)
 * @returns 고른 글자 (없으면 undefined)
 */
export function localizedText(value: unknown, locale?: string): string | undefined {
  if (typeof value === 'string') return value;
  if (!value || typeof value !== 'object') return undefined;

  const pick = (window as any).G7Core?.locale?.pick;
  if (typeof pick === 'function') {
    const picked = pick(value, locale);

    return typeof picked === 'string' ? picked : undefined;
  }

  const map = value as Record<string, unknown>;
  const direct = locale ? map[locale] : undefined;
  if (typeof direct === 'string' && direct.trim() !== '') return direct;

  return Object.values(map).find((v): v is string => typeof v === 'string' && v.trim() !== '');
}
