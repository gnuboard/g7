/**
 * @file analyticsFreshness.test.tsx
 * @description 방문자 통계 집계 신선도 — 기간 줄의 「마지막 집계」 문구 4분기와 상단 신선도 경고 3종의 조건표
 *
 * 스케줄러가 멈추면 숫자가 한 시각에 멈춘 채 정상처럼 보인다. 그 사실을 알리는 문구·경고가 틀린 조건으로
 * 뜨면 cron 이 멀쩡한 사이트에 오경보가 나거나, cron 이 없는 사이트에 아무 말이 없다. 구운 JSON 의 조건식을
 * 실제 표현식 평가기로 돌려 조건표 전 칸을 잰다.
 */

import { describe, it, expect } from 'vitest';
import { loadLayout, nodeById, collectObjects, analyticsScreens, stringify, actionsWithHandler } from './analyticsLayoutTestUtils';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { scanBindings } from '@core/template-engine/BindingShape';

const periodBar = loadLayout('partials/admin_analytics/_partial_period_bar.json');
const alerts = loadLayout('partials/admin_analytics/_partial_status_alerts.json');

/** 노드의 `if` 식을 주어진 신선도로 평가한다 */
function shown(node: Record<string, any> | undefined, freshness: unknown, extra: Record<string, unknown> = {}): boolean {
  const [expr] = scanBindings(String(node?.if ?? '')).map((b) => b.expr);
  return Boolean(evaluateSafeExpression(expr, { _computed: { archiveFreshness: freshness }, ...extra }));
}

/** 신선도 블록을 만든다 */
function freshness(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    last_run_at: '2026-09-28 22:07:41',
    last_run_trigger: 'on_view',
    is_today: true,
    lag_minutes: 3,
    stale: false,
    scheduler: { alive: false, last_run_at: null },
    on_view: { enabled: true, status: 'ran', today_visits: 12, max_visits: 50000 },
    ...overrides,
  };
}

/** 「마지막 집계」 줄에서 지금 보이는 문구 키 */
function visibleFreshnessKeys(f: unknown): string[] {
  const span = nodeById(periodBar, 'period_freshness');
  if (!shown(span, f)) return [];
  return (span?.children ?? [])
    .filter((child: any) => shown(child, f))
    .map((child: any) => String(child.text ?? stringify(child)).match(/admin\.analytics\.freshness\.[a-z_]+/)?.[0]);
}

describe('기간 줄 「마지막 집계」 — D14 문구 4분기', () => {
  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects freshness_line_follows_scheduler_and_on_view
  it('스케줄러 살아 있음 → 매시 자동 집계 문구 하나만', () => {
    expect(visibleFreshnessKeys(freshness({ scheduler: { alive: true, last_run_at: '2026-09-28 22:05:00' } }))).toEqual(['admin.analytics.freshness.scheduled']);
  });

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects freshness_line_follows_scheduler_and_on_view
  it('스케줄러 없음(false·null) + 화면 열 때 집계 켬 → 1분 간격 재집계 문구', () => {
    expect(visibleFreshnessKeys(freshness())).toEqual(['admin.analytics.freshness.on_view']);
    expect(visibleFreshnessKeys(freshness({ scheduler: { alive: null, last_run_at: null } }))).toEqual(['admin.analytics.freshness.on_view']);
  });

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects freshness_line_follows_scheduler_and_on_view
  it('둘 다 없음 → 오늘이면 시각만, 오늘이 아니면 날짜까지', () => {
    const off = { enabled: false, status: 'disabled', today_visits: null, max_visits: 50000 };
    expect(visibleFreshnessKeys(freshness({ on_view: off }))).toEqual(['admin.analytics.freshness.plain_time']);
    expect(visibleFreshnessKeys(freshness({ on_view: off, is_today: false }))).toEqual(['admin.analytics.freshness.plain_date']);
  });

  // @scenario screen=realtime, state=empty, viewport=1440, locale=ko
  // @effects freshness_line_hidden_without_archive
  it('집계 이력이 없거나 신선도를 두지 않은 화면(원장 화면)에서는 줄 자체가 숨는다', () => {
    expect(visibleFreshnessKeys(freshness({ last_run_at: null }))).toEqual([]);
    expect(visibleFreshnessKeys(undefined)).toEqual([]);
  });

  // @scenario screen=pages, state=normal, viewport=390, locale=ko
  // @effects freshness_line_wraps_on_mobile
  it('시각은 서버 문자열을 잘라 쓰고(형식 파이프 의존 없음) 모바일에서는 한 줄을 통째로 쓴다', () => {
    const span = nodeById(periodBar, 'period_freshness');
    expect(span?.props?.className).toContain('w-full');
    expect(span?.props?.className).toContain('sm:w-auto');
    expect(stringify(span)).toContain("slice(11, 16)");
    expect(stringify(span)).toContain('month={{Number(');
  });
});

describe('상단 신선도 경고 3종 — 조건표', () => {
  const schedulerMissing = nodeById(alerts, 'alert_scheduler_missing');
  const archiveStale = nodeById(alerts, 'alert_archive_stale');
  const tooMany = nodeById(alerts, 'alert_on_view_too_many');

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects scheduler_missing_alert_conditions
  it('스케줄러 미가동 안내: alive=false 이고 화면 열 때 집계가 켜져 있을 때만(null 은 설치 직후라 띄우지 않음)', () => {
    expect(schedulerMissing?.props?.className).toBe('alert-info');
    expect(shown(schedulerMissing, freshness())).toBe(true);
    expect(shown(schedulerMissing, freshness({ scheduler: { alive: null, last_run_at: null } }))).toBe(false);
    expect(shown(schedulerMissing, freshness({ scheduler: { alive: true, last_run_at: 'x' } }))).toBe(false);
    expect(shown(schedulerMissing, freshness({ on_view: { enabled: false } }))).toBe(false);
    expect(shown(schedulerMissing, undefined)).toBe(false);
  });

  // @scenario screen=pages, state=stale, viewport=1440, locale=ko
  // @effects archive_stale_alert_conditions
  it('집계 멈춤 경고: stale 이고 화면 열 때 집계가 꺼져 있을 때만', () => {
    expect(archiveStale?.props?.className).toBe('alert-warning');
    expect(shown(archiveStale, freshness({ stale: true, on_view: { enabled: false } }))).toBe(true);
    expect(shown(archiveStale, freshness({ stale: true }))).toBe(false);
    expect(shown(archiveStale, freshness({ stale: false, on_view: { enabled: false } }))).toBe(false);
    expect(stringify(archiveStale)).toContain('Math.floor((_computed.archiveFreshness?.lag_minutes ?? 0) / 60)');
  });

  // @scenario screen=pages, state=too_many_visits, viewport=1440, locale=ko
  // @effects too_many_visits_alert_conditions
  it('상한 초과 경고: 이번 요청이 too_many_visits 이고 스케줄러가 살아 있지 않을 때만', () => {
    expect(tooMany?.props?.className).toBe('alert-warning');
    expect(shown(tooMany, freshness({ on_view: { enabled: true, status: 'too_many_visits', max_visits: 50000 } }))).toBe(true);
    expect(shown(tooMany, freshness({ on_view: { enabled: true, status: 'too_many_visits' }, scheduler: { alive: true } }))).toBe(false);
    expect(shown(tooMany, freshness({ on_view: { enabled: true, status: 'throttled' } }))).toBe(false);
  });

  // @scenario screen=pages, state=stale, viewport=1440, locale=ko
  // @effects archive_now_button_needs_update_permission
  it('「지금 집계」는 설정 권한 계정에게만 보이고, 누르면 매시 집계를 요청한 뒤 이 화면 소스와 상태를 다시 받는다', () => {
    for (const id of ['alert_archive_now_button', 'alert_too_many_archive_now_button']) {
      const button = nodeById(alerts, id);
      expect(button, id).toBeTruthy();
      const [guard] = scanBindings(String(button?.if ?? '')).map((b) => b.expr);
      const can = (perms: string[]) => Boolean(evaluateSafeExpression(guard, { current_user: { data: { permissions: perms.map((p) => ({ identifier: p })) } } }));
      expect(can(['core.analytics.read'])).toBe(false);
      expect(can(['core.analytics.read', 'core.analytics.update'])).toBe(true);

      const call = actionsWithHandler(button, 'apiCall')[0];
      expect(call?.target ?? call?.params?.target).toBe('/api/admin/analytics/archive-runs');
      expect(stringify(call)).toContain('"run_type":"hour"');
      const refetched = actionsWithHandler(button, 'refetchDataSource').map((a) => a.params?.dataSourceId ?? a.dataSourceId);
      expect(refetched).toEqual(expect.arrayContaining(['{{_local.periodSource}}', 'analytics_status']));
    }
  });
});

describe('신선도 출처 — 집계 화면만 정의한다', () => {
  const AGGREGATE = [
    'admin_analytics_actions.json', 'admin_analytics_bots.json', 'admin_analytics_environment.json', 'admin_analytics_overview.json',
    'admin_analytics_pages.json', 'admin_analytics_patterns.json', 'admin_analytics_referrers.json', 'admin_analytics_regions.json',
  ];

  // @scenario screen=realtime, state=normal, viewport=1440, locale=ko
  // @effects freshness_line_hidden_without_archive
  it('집계 화면 8개는 `archiveFreshness` 를 집계 응답의 `data.freshness` 에서 읽고, 원장·진단·설정 화면은 두지 않는다', () => {
    const screens = analyticsScreens();
    expect(screens.length).toBeGreaterThanOrEqual(14);

    for (const screen of screens) {
      const computed = loadLayout(screen).computed?.archiveFreshness;
      if (AGGREGATE.includes(screen)) {
        expect(computed, screen).toContain('data?.freshness');
      } else {
        expect(computed, screen).toBeUndefined();
      }
    }
  });

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects freshness_line_follows_scheduler_and_on_view
  it('경고·문구가 쓰는 키는 전부 `_computed.archiveFreshness` 에서 온다 — 다른 소스를 직접 읽지 않는다', () => {
    const texts = collectObjects([periodBar, alerts])
      .map((o) => (typeof o.if === 'string' ? o.if : ''))
      .filter((t) => /scheduler\?\.alive|on_view\?\.|\?\.stale|last_run_at/.test(t));
    expect(texts.length).toBeGreaterThanOrEqual(5);
    for (const t of texts) expect(t).toContain('_computed.archiveFreshness');
  });
});
