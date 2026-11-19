import type React from 'react';

/**
 * 관리자 화면 보조 — 활성 관리자 템플릿의 기본 컴포넌트를 빌려 쓰고, 플러그인 번역 키를 조회한다.
 */

/** 활성 템플릿의 기본 컴포넌트를 우선 쓰고, 독립 미리보기·테스트는 HTML 기본 요소로 대체합니다. */
export function basic(name: string, fallback: React.ElementType): React.ElementType {
  return (window as any).G7Core?.getComponentRegistry?.()?.getComponent?.(name) ?? fallback;
}

/** 플러그인 번역 키(`sirsoft-popup.` 로 시작하는 전체 키)를 조회합니다(없으면 키 그대로). 키는 리터럴로 적어 미사용 키 검사가 찾게 합니다. */
export function translate(key: string, params?: Record<string, string | number>): string {
  return (window as any).G7Core?.t?.(key, params) ?? key;
}

/**
 * 저장하면 어떤 상태로 보일지 계산합니다 — 시간대가 없는 입력을 브라우저 시간대로 파싱하지 않고 사이트 벽시계 문자열끼리 비교합니다.
 *
 * @param period always 또는 range
 * @param active 사용 여부
 * @param start 사이트 시간대 시작 (YYYY-MM-DDTHH:mm)
 * @param end 사이트 시간대 종료
 * @param timezone 사이트 시간대
 * @param instant 지금 (epoch 밀리초, 서버 시계 기준)
 * @returns active·scheduled·ended·inactive·incomplete
 */
export function periodStatus(period: string, active: boolean, start: string, end: string, timezone: string, instant: number): string {
  if (!active) return 'inactive';
  if (period !== 'range') return 'active';
  if (!start || !end || !timezone) return 'incomplete';
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(instant));
  const part = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
  const now = `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}:${part('second')}`;
  const seconds = (value: string) => (value.length === 16 ? `${value}:00` : value);
  if (now < seconds(start)) return 'scheduled';
  if (now > seconds(end)) return 'ended';

  return 'active';
}
