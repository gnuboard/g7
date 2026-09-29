/**
 * @file analyticsOverview.test.tsx
 * @description 방문자 통계 개요 화면 + 공통 조각(기간 바·KPI·실시간 줄·상단 경고)의 런타임 계약 검증 (이슈 #682 S3)
 *
 * 잠그는 것: 데이터소스 id·엔드포인트·fallback·403 처리, 기간 바의 이동이 `mergeQuery` + `page:1` 인 것,
 * 프리셋이 `setAnalyticsPeriod` 를 거치는 것, 실시간 폴링 타이머 id 고정과 이탈 시 정리, KPI 카드 4장,
 * 추이 LineChart 의 세 계열. (`$t:` 키의 로케일 존재는 `analyticsStateBranches.test.ts` 가 통계 레이아웃 전수로 잰다.)
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import { fireEvent, waitFor } from '@testing-library/react';
import {
  loadLayout, loadLang, langValue, dataSource, nodesNamed, nodeById, actionsWithHandler, stringify, collectObjects,
} from './analyticsLayoutTestUtils';
import { ANALYTICS_PERIOD_PRESETS } from '../../handlers/setAnalyticsPeriodHandler';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const layout = loadLayout('admin_analytics_overview.json');
const periodBar = loadLayout('partials/admin_analytics/_partial_period_bar.json');
const kpiRow = loadLayout('partials/admin_analytics/_partial_kpi_row.json');
const realtimeBar = loadLayout('partials/admin_analytics/_partial_realtime_bar.json');
const statusAlerts = loadLayout('partials/admin_analytics/_partial_status_alerts.json');

describe('개요 화면 — 데이터소스 계약', () => {
  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects overview_sources_follow_period_query
  it('개요·실시간·진단 세 소스를 갖고 개요만 첫 그림을 막는다', () => {
    const overview = dataSource(layout, 'analytics_overview');
    expect(overview?.endpoint).toBe('/api/admin/analytics/overview');
    expect(overview?.params).toMatchObject({ from: '{{query.from}}', to: '{{query.to}}' });
    expect(overview?.params['exclude[]']).toContain("query.exclude");
    expect(overview?.fallback?.data?.kpi).toBeDefined();
    expect(overview?.fallback?.data?.series?.points).toEqual([]);
    expect(stringify(overview?.errorHandling)).toContain('showErrorPage');

    expect(dataSource(layout, 'analytics_realtime')?.loading_strategy).toBe('background');
    expect(dataSource(layout, 'analytics_status')?.endpoint).toBe('/api/admin/analytics/diagnostics');
    expect(layout.transition_overlay?.wait_for).toEqual(['analytics_overview']);
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects period_bar_rehydrates_from_query
  it('기간 바가 보여 주는 것은 주소에서 나오고 초기화는 첫 그림용 범위만 계산한다', () => {
    const init = layout.init_actions ?? [];
    const preset = init.find((a: any) => a.handler === 'setAnalyticsPeriod');
    expect(preset?.params?.preset).toContain("query.preset || '7d'");
    expect(preset?.resultTo).toEqual({ target: '_local', key: 'periodComputed' });

    // 화면 상태(`_local.period`)로 그리면 뒤로 가기가 주소만 되돌리고 바는 직전 기간을 가리킨 채
    // 남는다 — 실측으로 확인한 뒤 바를 주소 기준으로 돌렸다(#682 라운드 4, 검수 지적 ①ⓑ).
    expect(stringify(periodBar)).not.toContain('_local.period?.');
    expect(stringify(periodBar)).toContain('query.preset');
    expect(stringify(periodBar)).toContain('query.exclude');

    // 프리셋은 주소에 날짜를 남기지 않으므로 달력 두 칸은 화면이 넘겨 준 구간을 읽는다.
    expect(layout.computed?.periodFrom).toContain('query.from');
    // 개요 응답은 구간을 `data.meta` 에 싣는다 — `data.from` 을 읽으면 식이 조용히 브라우저 날짜로 떨어진다(#682 S3 라운드 14).
    expect(layout.computed?.periodFrom).toContain('analytics_overview?.data?.meta?.from');
    expect(layout.computed?.periodTo).toContain('analytics_overview?.data?.meta?.to');
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects realtime_interval_id_is_fixed_and_cleared_on_unmount
  it('실시간 폴링은 고정 id 로 시작하고 화면을 떠날 때 같은 id 로 멈춘다', () => {
    const start = (layout.init_actions ?? []).find((a: any) => a.handler === 'startInterval');
    expect(start?.params?.id).toBe('analytics_overview_realtime');
    // [case:components-misc-global-settings-prefix] 접두사 없는 `settings?.` 는 늘 undefined 라 기본 30초로만 돈다
    expect(start?.params?.intervalMs).toContain('_global.settings?.analytics?.realtime_poll_seconds');
    expect(start?.params?.actions?.[0]).toMatchObject({ handler: 'refetchDataSource', params: { dataSourceId: 'analytics_realtime' } });

    const stops = actionsWithHandler(layout, 'stopInterval');
    expect(stops.some((a) => a.params?.id === 'analytics_overview_realtime')).toBe(true);

    // 실시간 화면과 **다른** id 여야 한다. 같은 id 를 쓰면 화면을 옮길 때 새 화면이 켠 타이머를 떠나는
    // 화면의 정리가 그대로 끄고, 돌아온 화면은 한 번도 갱신되지 않는다(브라우저 실측 §8 M4-T7).
    const realtimeScreen = loadLayout('admin_analytics_realtime.json');
    const realtimeStart = (realtimeScreen.init_actions ?? []).find((a: any) => a.handler === 'startInterval');
    expect(realtimeStart?.params?.id).not.toBe(start?.params?.id);
    const root = nodeById(layout, 'analytics_overview_content');
    expect(stringify(root?.lifecycle ?? root)).toContain('onUnmount');
  });
});

describe('기간 바 조각', () => {
  // 모집단은 핸들러 상수다 — 리터럴로 적으면 9번째 프리셋이 생겨도 검사 밖에 남는다(#682 S3 R7-D9).
  const presets = [...ANALYTICS_PERIOD_PRESETS];

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects preset_uses_date_only_handler
  it('기간 바의 프리셋 버튼 집합이 핸들러가 아는 프리셋 집합과 같다 — 한쪽만 늘면 red', () => {
    expect(presets.length, '핸들러 프리셋 모집단').toBeGreaterThanOrEqual(8);
    const buttons = collectObjects(periodBar)
      .map((o) => (typeof o.id === 'string' ? o.id.match(/^preset_(.+)$/)?.[1] : undefined))
      .filter((id): id is string => !!id);
    expect(buttons.length, '기간 바의 프리셋 버튼').toBeGreaterThan(0);
    expect([...new Set(buttons)].sort()).toEqual([...presets].sort());
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects preset_uses_date_only_handler, period_bar_navigates_with_merge_query_and_page_reset
  it('프리셋 8개는 주소만 바꾸고, 그 이동이 이력에 쌓인다', () => {
    for (const p of presets) {
      const btn = nodeById(periodBar, `preset_${p}`);
      expect(btn, p).toBeDefined();
      const seq = collectObjects(btn).find((o) => o.handler === 'sequence');
      const steps = seq?.actions ?? [];

      // 날짜는 서버가 프리셋을 풀어 되돌려 주므로 화면이 계산해 상태에 담지 않는다.
      expect(steps.map((s: any) => s.handler)).toEqual(['navigate']);
      expect(steps[0].params.mergeQuery).toBe(true);
      expect(steps[0].params.query).toMatchObject({ preset: p, page: 1, from: '{{undefined}}', to: '{{undefined}}' });

      // `replace` 로 갈아 끼우면 뒤로 가기가 직전 기간이 아니라 통계 화면 밖으로 나간다.
      expect(steps[0].params.replace, p).toBeUndefined();
    }
    expect(stringify(periodBar)).not.toContain('"setDateRange"');
  });

  // @scenario screen=overview, state=normal, viewport=390, locale=en
  // @effects period_bar_navigates_with_merge_query_and_page_reset
  it('달력 변경은 프리셋을 빼고, 단위·제외 토글도 page 를 1 로 되돌린다', () => {
    const navs = actionsWithHandler(periodBar, 'navigate');
    const fromNav = navs.find((n) => n.params?.query?.from?.includes('$event.target.value'));
    expect(fromNav?.params?.query?.preset).toBe('{{undefined}}');
    expect(fromNav?.params?.query?.page).toBe(1);

    const unitNav = navs.find((n) => typeof n.params?.query?.unit === 'string');
    expect(unitNav?.params?.query?.unit).toContain("'auto' ? undefined");
    // 초기화 버튼은 제외 키를 지우기만 한다(`{{undefined}}`) — 토글 두 개만 센다.
    const excludeNavs = navs.filter((n) => typeof n.params?.query?.exclude === 'string' && n.params.query.exclude !== '{{undefined}}');
    expect(excludeNavs).toHaveLength(2);
    for (const n of navs) {
      expect(n.params.mergeQuery, '기간 바의 모든 이동은 병합이다').toBe(true);
      expect(n.params.path).toBe('{{_local.periodPath}}');
    }
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects csv_button_downloads_with_token
  it('CSV 는 토큰 동반 blob 다운로드 핸들러로 받고 새로고침은 화면이 정한 소스를 다시 부른다', () => {
    const csv = actionsWithHandler(periodBar, 'downloadAttachment')[0];
    expect(csv?.params?.url).toBe('{{_computed.csvUrl}}');
    expect(nodeById(periodBar, 'csv_download_button')?.if).toContain('_computed.csvUrl');
    // 390px 에서는 기간 바 아래 전폭이다(계획서 §4.11) — 칩 사이에 끼면 누를 면적이 칩 하나만 해진다.
    const csvClass = String(nodeById(periodBar, 'csv_download_button')?.props?.className ?? '');
    expect(csvClass).toContain('w-full');
    expect(csvClass).toContain('sm:w-auto');
    const refetch = actionsWithHandler(periodBar, 'refetchDataSource')[0];
    expect(refetch?.params?.dataSourceId).toBe('{{_local.periodSource}}');
  });
});

describe('기간 바 — 달력 두 칸', () => {
  // @scenario screen=overview, state=normal, viewport=390, locale=ko
  // @effects period_dates_show_the_preset_range_and_fit_the_card
  it('좁은 화면에서는 두 칸이 카드 폭을 나눠 쓰고 넓은 화면에서는 고정 폭이다', () => {
    // 390px 에서 w-40 두 칸 + 물결이 카드 안쪽(311px)보다 26px 넓어 끝 칸이 카드 밖으로 나갔다(브라우저 실측 §8 M2-T8).
    const dates = nodeById(periodBar, 'period_dates');
    expect(dates?.props?.className).toContain('w-full');
    expect(dates?.props?.className).toContain('sm:w-auto');
    for (const id of ['period_from', 'period_to']) {
      const cls: string = nodeById(periodBar, id)?.props?.className ?? '';
      expect(cls, id).toContain('flex-1');
      expect(cls, id).toContain('min-w-0');
      expect(cls, id).toContain('sm:w-40');
    }
  });

  const DateInput: React.FC<any> = ({ id, value, type, onChange }) =>
    React.createElement('input', { id, type, value: value ?? '', onChange: onChange ?? (() => {}) });
  const Plain = (tag: string): React.FC<any> => ({ id, className, children, text }) =>
    React.createElement(tag, { id, className }, children ?? text);

  function datesRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: Plain('div'), metadata: { name: 'Div', type: 'basic' } },
      Span: { component: Plain('span'), metadata: { name: 'Span', type: 'basic' } },
      Input: { component: DateInput, metadata: { name: 'Input', type: 'basic' } },
      Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
    };
    return registry;
  }

  const fresh = () => ({
    periodPath: '/admin/analytics',
    periodComputed: { from: '2026-08-21', to: '2026-09-19', preset: '30d' },
  });

  // 두 칸이 읽는 값은 화면이 넘겨 주는 `_computed.periodFrom`·`periodTo` 다 — 주소의 날짜가 먼저고,
  // 프리셋이면 서버가 되돌려 준 구간, 그것도 없으면 초기화가 계산해 둔 범위다(#682 라운드 4).
  const range = () => ({ periodFrom: '2026-08-21', periodTo: '2026-09-19' });

  const renderDates = async (local: Record<string, any>, computed: Record<string, any> = range()) => {
    const probe = {
      version: '1.0.0',
      layout_name: 'analytics_period_dates_probe',
      components: [nodeById(periodBar, 'period_dates')],
    };
    const t = createLayoutTest(probe, {
      componentRegistry: datesRegistry(),
      translations: { admin: loadLang('ko', 'admin') },
      locale: 'ko',
      initialState: { _local: local },
      // 두 칸이 읽는 값은 화면이 넘겨 주는 `_computed.periodFrom`·`periodTo` 다 — 조각만 떼어
      // 그릴 때도 같은 이름으로 준다.
      initialData: { _computed: computed },
    });
    await t.render();
    return t;
  };

  const input = (id: string) => document.querySelector(`#${id}`) as HTMLInputElement;

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects period_dates_show_the_preset_range_and_fit_the_card
  it('첫 진입·새로고침에도 프리셋의 날짜 범위를 두 칸에 보여 준다', async () => {
    // 프리셋을 눌렀을 때만 채워지고 첫 진입·새로고침에는 비어 있었다 — 초기화가 계산해 둔 범위를 칸이 읽지 않았다.
    const t = await renderDates(fresh());
    expect(input('period_from').value).toBe('2026-08-21');
    expect(input('period_to').value).toBe('2026-09-19');
    t.cleanup();
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects period_dates_show_the_preset_range_and_fit_the_card
  it('시작일만 고치면 보이던 끝 날짜를 잇고, 비운 칸은 이동하지 않는다', async () => {
    const t = await renderDates(fresh());

    fireEvent.change(input('period_from'), { target: { value: '2026-09-01' } });
    await waitFor(() => expect(t.getNavigationHistory()).toHaveLength(1));
    expect(t.getNavigationHistory()[0]).toContain('from=2026-09-01');
    expect(t.getNavigationHistory()[0]).toContain('to=2026-09-19');
    expect(t.getNavigationHistory()[0]).not.toContain('preset=');

    fireEvent.change(input('period_from'), { target: { value: '' } });
    await new Promise((r) => setTimeout(r, 50));
    expect(t.getNavigationHistory(), '비운 칸은 기간을 바꾸지 않는다').toHaveLength(1);
    t.cleanup();
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects period_dates_show_the_preset_range_and_fit_the_card
  it('끝 날짜를 시작보다 앞으로 고치면 그 하루로 줄여 뒤집힌 기간을 보내지 않는다', async () => {
    const t = await renderDates(fresh());

    fireEvent.change(input('period_to'), { target: { value: '2026-08-01' } });
    await waitFor(() => expect(t.getNavigationHistory()).toHaveLength(1));
    expect(t.getNavigationHistory()[0]).toContain('from=2026-08-01');
    expect(t.getNavigationHistory()[0]).toContain('to=2026-08-01');
    t.cleanup();
  });
});

describe('기간 바 — 조회 한도(366일)', () => {
  const Plain = (tag: string): React.FC<any> => ({ id, className, children, text }) =>
    React.createElement(tag, { id, className }, children ?? text);

  function barRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: Plain('div'), metadata: { name: 'Div', type: 'basic' } },
      P: { component: Plain('p'), metadata: { name: 'P', type: 'basic' } },
      Icon: { component: Plain('i'), metadata: { name: 'Icon', type: 'basic' } },
      Button: { component: Plain('button'), metadata: { name: 'Button', type: 'basic' } },
      Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
    };
    return registry;
  }

  const renderWith = async (queryParams: Record<string, string>, failing: boolean) => {
    const probe = {
      version: '1.0.0',
      layout_name: 'analytics_period_limit_probe',
      data_sources: [dataSource(layout, 'analytics_overview')],
      components: [nodeById(periodBar, 'period_range_error'), nodeById(layout, 'overview_load_failed')].filter(Boolean),
    };
    const t = createLayoutTest(probe, {
      componentRegistry: barRegistry(),
      translations: { admin: loadLang('ko', 'admin') },
      locale: 'ko',
      queryParams,
    });
    if (failing) {
      t.mockApiError('analytics_overview', 422, 'too long');
    } else {
      t.mockApi('analytics_overview', { response: { data: { kpi: {}, series: { points: [] }, top: {}, meta: {} } } });
    }
    await t.render();
    return t;
  };

  // @scenario screen=overview, state=load_failed, viewport=1440, locale=ko
  // @effects period_over_the_limit_is_explained_inline
  it('주소의 기간이 366일을 넘으면 기간 바 아래에 한도를 알리고 「잠시 뒤 다시 시도」 카드는 띄우지 않는다', async () => {
    // 422 가 일반 실패 카드(잠시 뒤 다시 시도하세요)와 사라지는 토스트로만 보였다 — 다시 시도해도 풀리지 않는 오류다(§8 M2-T10).
    const t = await renderWith({ from: '2025-01-01', to: '2026-09-19' }, true);
    const ko = loadLang('ko', 'admin');
    const expected = String(langValue(ko, 'admin.analytics.period.range_too_long')).replace('{{days}}', '366');

    expect(document.querySelector('#period_range_error')?.textContent).toBe(expected);
    expect(document.querySelector('#overview_load_failed'), '원인이 기간이면 일반 실패 카드는 겹쳐 띄우지 않는다').toBeNull();
    t.cleanup();
  });

  // @scenario screen=overview, state=load_failed, viewport=1440, locale=ko
  // @effects period_over_the_limit_is_explained_inline
  it('366일 이내면 안내가 없고, 그 기간에서 난 실패는 일반 실패 카드가 맡는다', async () => {
    const within = await renderWith({ from: '2026-01-01', to: '2026-12-31' }, true);
    expect(document.querySelector('#period_range_error')).toBeNull();
    expect(document.querySelector('#overview_load_failed')).not.toBeNull();
    within.cleanup();

    const preset = await renderWith({ preset: 'this_year' }, false);
    expect(document.querySelector('#period_range_error'), '프리셋은 한도를 넘지 않는다').toBeNull();
    preset.cleanup();
  });
});

describe('KPI·실시간 줄·상단 경고 조각', () => {
  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects overview_renders_four_kpi_cards
  it('KPI 카드 4장(오늘·어제·최대·전체)이고 오늘만 어제 대비 증감을 갖는다', () => {
    const cards = nodesNamed(kpiRow, 'StatCard');
    expect(cards).toHaveLength(4);
    expect(cards.map((c) => c.id)).toEqual(['kpi_today', 'kpi_yesterday', 'kpi_max', 'kpi_total']);
    expect(cards[0].props.change).toContain('change_pct');
    expect(cards[1].props.change).toBeUndefined();
  });

  // @scenario screen=overview, state=unit_downgraded, viewport=1440, locale=ko
  // @effects trend_chart_keeps_base_series
  it('추이 차트는 LineChart 이고 기본 세 계열(방문자·방문·페이지뷰)을 유지한다 — 신규·재방문 두 선은 아래 케이스가 잰다', () => {
    const chart = nodesNamed(layout, 'LineChart')[0];
    expect(chart).toBeDefined();
    expect(chart.props.datasets).toContain('admin.analytics.common.visitors');
    expect(chart.props.datasets).toContain('admin.analytics.common.visits');
    expect(chart.props.datasets).toContain('admin.analytics.common.pageviews');
    expect(chart.props.showLegend).toBe(true);
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects trend_chart_adds_new_and_returning
  it('추이 차트에 신규·재방문 두 선이 더해지고(범례로 켜고 끔), 품질 줄에 신규·재방문 카드가 있다', () => {
    const chart = nodesNamed(layout, 'LineChart')[0];
    const datasets = String(chart.props.datasets);
    expect(datasets.match(/\{label: /g) ?? [], '계열 수').toHaveLength(5);
    expect(datasets).toContain("{label: $t('admin.analytics.overview.new_visitors'), data: (analytics_overview?.data?.series?.points ?? []).map(p => p.new_visitors ?? 0), color: '#10B981'}");
    // 익명 칸(null)은 선에서 0 으로 그린다 — 카드는 「구분할 수 없음」으로 따로 말한다.
    expect(datasets).toContain("{label: $t('admin.analytics.overview.returning_visitors'), data: (analytics_overview?.data?.series?.points ?? []).map(p => p.returning_visitors ?? 0), color: '#F43F5E'}");

    const card = nodeById(layout, 'quality_new_returning');
    expect(card, '신규·재방문 카드').toBeTruthy();
    expect(stringify(card)).toContain('admin.analytics.overview.quality.new_returning');
    expect(stringify(card)).toContain('admin.analytics.overview.new_returning_unavailable');
    expect(stringify(card)).toContain('p.new_visitors != null || p.returning_visitors != null');
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects quality_cards_share_one_shape
  it('신규·재방문 카드는 옆 세 카드(StatCard)와 같은 틀 — 카드·아이콘·큰 수치·라벨 클래스가 같다', () => {
    // 비율 막대를 넣으려고 StatCard 대신 직접 만든 카드라, 틀을 따로 두면 줄 안에서 높이·여백·그림자가 어긋난다.
    const card = nodeById(layout, 'quality_new_returning');
    const classes = collectObjects(card).map((o) => String(o.props?.className ?? ''));
    expect(String(card?.props?.className ?? '').split(/\s+/), '카드 틀').toContain('stat-card');
    for (const cls of ['stats-icon', 'stats-value-lg', 'stats-label']) {
      expect(classes.some((c) => c.split(/\s+/).includes(cls)), cls).toBe(true);
    }
    expect(String(card?.props?.className ?? '')).not.toContain('admin-card');
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects realtime_bar_counts_top_pages
  it('실시간 줄은 접속자 수와 상위 페이지 3개를 같은 소스에서 만든다', () => {
    expect(stringify(realtimeBar)).toContain('analytics_realtime?.data?.count');
    expect(stringify(realtimeBar)).toContain('slice(0, 3)');
  });

  // @scenario screen=overview, state=collection_off, viewport=1440, locale=ko
  // @effects status_alerts_read_diagnostics
  it('상단 경고 셋은 진단 소스를 읽고 수집 꺼짐·큐 적체는 갈 곳을 가리킨다', () => {
    expect(nodeById(statusAlerts, 'alert_collection_disabled')?.if).toContain('analytics_status?.data?.collection?.enabled === false');
    // 종전엔 `mode === 'consent'` 를 고정했는데 그 계약이 틀렸다 — 서버는
    // `consent_plugin_blocks_cookies` 를 **`full` 모드에서만** 만든다
    // (`app/Analytics/AnalyticsConfig.php` 의 `anonymousFallbackReason`). 두 조건이 배타적이라
    // 「전체 수집 + GDPR」 사이트는 실제로 익명 수집 중인데 안내가 한 면도 안 떴다
    // (#682 S3 라운드 8 브라우저 실측). 사유가 실리면 띄우는 것이 옳은 계약이다.
    expect(nodeById(statusAlerts, 'alert_consent_fallback')?.if).toContain('anonymous_fallback_reason');
    expect(nodeById(statusAlerts, 'alert_queue_backlog')?.if).toContain('queue?.backlogged === true');
    const navs = actionsWithHandler(statusAlerts, 'navigate').map((n) => n.params.path);
    expect(navs).toEqual(expect.arrayContaining(['/admin/analytics/settings', '/admin/analytics/diagnostics']));
  });
});

describe('개요 요청 실패 — 오류 카드', () => {
  const DATA_SECTIONS = ['kpi_section', 'trend_card', 'quality_row', 'top_lists'];

  // @scenario screen=overview, state=load_failed, viewport=1440, locale=ko
  // @effects overview_failure_shows_an_error_card_not_an_empty_period
  it('fallback 에 실패 표식이 있고, 오류 카드가 그 표식으로 뜨며 다시 시도는 같은 소스를 다시 부른다', () => {
    // 403 밖의 실패에서 엔진은 fallback 을 채운다. 표식이 없으면 화면은 실패와 빈 기간을 가를 수 없어
    // KPI 0 과 「선택 기간에 기록이 없습니다」를 그렸다(브라우저 실측 §8 M2-T4).
    const overview = dataSource(layout, 'analytics_overview');
    expect(overview?.fallback?.unavailable).toBe(true);

    const card = nodeById(layout, 'overview_load_failed');
    expect(card?.if).toContain('analytics_overview?.unavailable === true');
    expect(card?.if, '기간 한도 초과는 기간 바가 알린다').toContain('> 366');
    const retry = actionsWithHandler(card, 'refetchDataSource');
    expect(retry.map((a) => a.params?.dataSourceId)).toEqual(['analytics_overview']);

    for (const id of DATA_SECTIONS) {
      expect(nodeById(layout, id)?.if, id).toBe('{{analytics_overview?.unavailable !== true}}');
    }
  });

  const Stub = (tag: string): React.FC<any> => ({ id, className, children, text, title }) =>
    React.createElement(tag, { id, className }, children ?? text ?? title);

  function overviewRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    const tags: Record<string, string> = {
      Div: 'div', P: 'p', H2: 'h2', Span: 'span', Button: 'button', Icon: 'i', Badge: 'span', LineChart: 'div', EmptyState: 'div',
    };
    (registry as any).registry = Object.fromEntries(
      Object.entries(tags).map(([name, tag]) => [name, { component: Stub(tag), metadata: { name, type: 'basic' } }]),
    );
    (registry as any).registry.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
    return registry;
  }

  const probe = () => ({
    version: '1.0.0',
    layout_name: 'analytics_overview_failure_probe',
    data_sources: [dataSource(layout, 'analytics_overview')],
    components: [nodeById(layout, 'overview_load_failed'), nodeById(layout, 'trend_card')].filter(Boolean),
  });

  const render = async (arrange: (t: ReturnType<typeof createLayoutTest>) => void) => {
    const t = createLayoutTest(probe(), {
      componentRegistry: overviewRegistry(),
      translations: { admin: loadLang('ko', 'admin') },
      locale: 'ko',
    });
    arrange(t);
    await t.render();
    return t;
  };

  // @scenario screen=overview, state=load_failed, viewport=1440, locale=ko
  // @effects overview_failure_shows_an_error_card_not_an_empty_period
  it('500 이면 오류 카드와 다시 시도 버튼이 뜨고 빈 기간 안내·추이 카드는 그리지 않는다', async () => {
    const t = await render((x) => x.mockApiError('analytics_overview', 500, 'Server Error'));

    expect(document.querySelector('#overview_load_failed')?.textContent).toContain(langValue(loadLang('ko', 'admin'), 'admin.analytics.alerts.load_failed'));
    expect(document.querySelector('#overview_retry_button')).not.toBeNull();
    expect(document.querySelector('#trend_card')).toBeNull();
    expect(document.body.textContent).not.toContain(langValue(loadLang('ko', 'admin'), 'admin.analytics.common.empty_title'));
    t.cleanup();
  });

  // @scenario screen=overview, state=empty, viewport=1440, locale=ko
  // @effects overview_failure_shows_an_error_card_not_an_empty_period
  it('응답이 비었을 뿐이면 오류 카드 없이 빈 기간 안내를 그린다', async () => {
    const t = await render((x) => x.mockApi('analytics_overview', {
      response: {
        data: {
          kpi: { today: 0, yesterday: 0, max: { value: 0, date: null }, total: 0, change_pct: null },
          series: { unit: 'day', unit_downgraded: false, points: [] },
          top: { pages: [], referrers: [], countries: [] },
          realtime: { count: 0, visitors: [] },
          meta: { collection_enabled: true, mode: 'full', consent_source: 'none', geoip_enabled: false },
        },
      },
    }));

    expect(document.querySelector('#overview_load_failed')).toBeNull();
    expect(document.querySelector('#trend_empty')?.textContent).toContain(langValue(loadLang('ko', 'admin'), 'admin.analytics.common.empty_title'));
    t.cleanup();
  });
});
