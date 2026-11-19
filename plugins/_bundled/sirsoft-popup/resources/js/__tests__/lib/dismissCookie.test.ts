/**
 * @file dismissCookie.test.ts
 * @description 「보지 않기」 쿠키 — 읽기(만료·깨진 값), 만료 계산(오늘 자정·N일), 쓰기(합치기·만료 정리·50개 상한)
 * (매니페스트 popup-layer.yaml — 쿠키 케이스)
 */
import { describe, it, expect } from 'vitest';
import { DISMISS_COOKIE, MAX_ENTRIES, buildDismissCookie, dismissExpiry, readDismissed } from '../../lib/dismissCookie';

/** 쿠키 문자열에서 값만 꺼낸다. */
const valueOf = (cookie: string) => JSON.parse(decodeURIComponent(cookie.split(';')[0].slice(DISMISS_COOKIE.length + 1)));

describe('dismissCookie', () => {
  // @scenario surface=dismiss_today_expires_local_midnight
  // @effects dismiss_cookie_written
  it('「오늘 하루」는 방문자 브라우저의 다음 자정까지', () => {
    const now = new Date(2026, 9, 27, 15, 30, 0);
    const until = dismissExpiry('today', 7, now);

    expect(new Date(until * 1000).getDate()).toBe(28);
    expect(new Date(until * 1000).getHours()).toBe(0);
    expect(new Date(until * 1000).getMinutes()).toBe(0);
  });

  // @scenario surface=dismiss_days_expires_after_n_days
  // @effects dismiss_cookie_written
  it('「N일」은 지금 + N×24시간, N 은 1~30 으로 묶는다', () => {
    const now = new Date(2026, 9, 27, 15, 30, 0);
    const nowSec = Math.floor(now.getTime() / 1000);

    expect(dismissExpiry('days', 7, now)).toBe(nowSec + 7 * 86400);
    expect(dismissExpiry('days', 0, now)).toBe(nowSec + 86400);
    expect(dismissExpiry('days', 99, now)).toBe(nowSec + 30 * 86400);
  });

  // @scenario surface=dismiss_cookie_prunes_expired_and_caps_entries
  // @effects dismiss_cookie_written
  it('쓸 때 만료 지난 항목은 빼고, 50개를 넘으면 만료가 이른 것부터 버린다', () => {
    const now = 1_000_000;
    const current: Record<string, number> = { '1': now - 10, '2': now + 100 };
    const cookie = buildDismissCookie(current, [3, 4], now + 500, now, true);
    const value = valueOf(cookie);

    expect(value).toEqual({ '2': now + 100, '3': now + 500, '4': now + 500 });
    expect(cookie).toContain('path=/');
    expect(cookie).toContain('SameSite=Lax');
    expect(cookie).toContain('Secure');
    expect(cookie).toContain(`expires=${new Date((now + 500) * 1000).toUTCString()}`);

    const many: Record<string, number> = {};
    for (let i = 1; i <= MAX_ENTRIES + 5; i += 1) many[String(i)] = now + i;
    const capped = valueOf(buildDismissCookie(many, [], now + 1, now, false));
    expect(Object.keys(capped)).toHaveLength(MAX_ENTRIES);
    expect(capped['1']).toBeUndefined();
    expect(capped[String(MAX_ENTRIES + 5)]).toBe(now + MAX_ENTRIES + 5);
  });

  // @scenario surface=dismiss_cookie_corrupt_treated_empty
  it('깨진 값·다른 모양은 빈 기록으로, 만료 지난 항목은 읽지 않는다', () => {
    const now = 1_000_000;

    expect(readDismissed(`${DISMISS_COOKIE}=%7Bnot-json`, now)).toEqual({});
    expect(readDismissed(`${DISMISS_COOKIE}=${encodeURIComponent('[1,2]')}`, now)).toEqual({});
    expect(readDismissed('other=1', now)).toEqual({});
    expect(readDismissed(`a=1; ${DISMISS_COOKIE}=${encodeURIComponent(JSON.stringify({ '5': now + 9, '6': now - 1, x: now + 9 }))}`, now)).toEqual({ '5': now + 9 });
  });
});
