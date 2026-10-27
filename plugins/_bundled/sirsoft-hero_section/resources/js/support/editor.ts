import type React from 'react';

/** 활성 템플릿의 기본 컴포넌트를 우선 쓰고, 독립 미리보기·테스트는 HTML 기본 요소로 대체합니다. */
export function basic(name: string, fallback: React.ElementType): React.ElementType {
  return (window as any).G7Core?.getComponentRegistry?.()?.getComponent?.(name) ?? fallback;
}

/** 플러그인 번역 키를 조회합니다. */
export function translate(key: string, params?: Record<string, string | number>): string {
  const full = `sirsoft-hero_section.${key}`;
  return (window as any).G7Core?.t?.(full, params) ?? full;
}

/** 공개 응답과 같은 순서(요청·사이트 기본·en·첫 유효값)로 문구를 고릅니다. */
export function localized(value: Record<string, string> | null | undefined, locale: string, defaultLocale: string): string {
  for (const key of [...new Set([locale, defaultLocale, 'en', ...Object.keys(value ?? {})])]) {
    if (typeof value?.[key] === 'string' && value[key].trim() !== '') return value[key];
  }
  return '';
}

/** 시간대가 없는 입력을 브라우저 시간대로 파싱하지 않고 사이트의 벽시계 문자열끼리 비교합니다. */
export function periodStatus(period: string, active: boolean, start: string, end: string, timezone: string, instant: number): string {
  if (!active) return 'inactive';
  if (period !== 'range') return 'active';
  if (!start || !end || !timezone) return 'incomplete';
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(instant));
  const part = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
  const now = `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}:${part('second')}`;
  const seconds = (value: string) => value.length === 16 ? `${value}:00` : value;
  if (now < seconds(start)) return 'scheduled';
  if (now > seconds(end)) return 'ended';
  return 'active';
}
