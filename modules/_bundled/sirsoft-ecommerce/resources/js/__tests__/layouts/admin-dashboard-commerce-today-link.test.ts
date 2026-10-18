/**
 * 대시보드 「오늘」 주문 배지 → 주문 목록 링크의 날짜 (7.1.0 전수 실측 QE-O2 · 원칙)
 *
 * 배지 숫자는 사이트 표준시의 오늘 집계 행(ecommerce_stats)을 읽는다. 배지를 눌러 여는 주문 목록 링크가
 * `toISOString().slice(0, 10)`(UTC 날짜)을 실으면, 한국 시간 오전 9시 전에는 배지가 센 날(사이트 오늘)이 아니라
 * 어제 주문 목록이 열렸다. 링크도 사이트 표준시의 오늘 날짜를 싣는다.
 *
 * 기준 순간 UTC 2026-10-08 05:00 = 한국(이 PC)·UTC 10-08, 호놀룰루 10-07 19:00 — 사이트 시간대를 호놀룰루로 둬 갈라 본다.
 */

import fs from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const EXTENSION = path.resolve(__dirname, '../../../extensions/admin_dashboard_commerce.json');

/** 트리에서 조건에 맞는 노드를 모두 모은다. */
function collect(node: any, match: (n: any) => boolean, found: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));
    return found;
  }
  if (node && typeof node === 'object') {
    if (match(node)) found.push(node);
    Object.values(node).forEach((value) => {
      if (value && typeof value === 'object') collect(value, match, found);
    });
  }
  return found;
}

/** 경로 문자열 안의 `{{식}}` 을 레이아웃 문맥(_global)으로 평가해 채운다. */
function render(template: string, _global: any): string {
  return template.replace(/\{\{(.+?)\}\}(?!\})/g, (_m, expr) =>
    // eslint-disable-next-line no-new-func
    String(new Function('_global', `return (${expr});`)(_global)),
  );
}

describe('대시보드 오늘 배지 링크 — 사이트 표준시의 오늘', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2026, 9, 8, 5, 0, 0)));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  // @scenario boundary=ecommerce_stats_daily, timezone=site
  // @effects ecommerce_today_badge_links_use_site_today
  it('배지 링크의 시작·끝 날짜가 사이트 표준시의 오늘이다', () => {
    const layout = JSON.parse(fs.readFileSync(EXTENSION, 'utf-8'));
    const paths: string[] = collect(
      layout,
      (n) => n.handler === 'navigate' && typeof n.params?.path === 'string' && n.params.path.includes('start_date='),
    ).map((n) => n.params.path);

    expect(paths.length).toBeGreaterThanOrEqual(7);
    const _global = { settings: { general: { timezone: 'Pacific/Honolulu' } } };
    for (const p of paths) {
      const url = new URL(render(p, _global), 'https://example.test');
      expect(url.searchParams.get('start_date')).toBe('2026-10-07');
      expect(url.searchParams.get('end_date')).toBe('2026-10-07');
    }
  });
});
