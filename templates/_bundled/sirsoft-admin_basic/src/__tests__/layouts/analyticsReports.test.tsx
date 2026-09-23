/**
 * @file analyticsReports.test.tsx
 * @description 리포트 화면 6종(페이지·유입·환경·지역·행동·봇)과 공통 표 조각의 런타임 계약 (이슈 #682 S3)
 *
 * 잠그는 것: 서브탭이 `query.tab` 하나를 출처로 삼는 것, 탭 → 리포트 이름 매핑이 엔드포인트에 있는 것,
 * 표의 쪽 이동·정렬·검색이 전부 질의 이동인 것, 지역 화면의 GeoIP 안내, 비율 도넛의 조건, 행동 화면의 차원 탭.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { TEMPLATE_DIR, loadLayout, dataSource, nodesNamed, nodeById, actionsWithHandler, stringify, collectObjects } from './analyticsLayoutTestUtils';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { scanBindings } from '@core/template-engine/BindingShape';

const SCREENS: Array<{ file: string; path: string; defaultTab: string; reports: string[] }> = [
  { file: 'admin_analytics_pages.json', path: '/admin/analytics/pages', defaultTab: 'popular', reports: ['page_url', 'entry_pages', 'exit_pages', 'page_title'] },
  { file: 'admin_analytics_referrers.json', path: '/admin/analytics/referrers', defaultTab: 'types', reports: ['referrer_type', 'referrer_name', 'referrer_keyword', 'campaign'] },
  { file: 'admin_analytics_environment.json', path: '/admin/analytics/environment', defaultTab: 'browsers', reports: ['browser', 'os', 'device_type', 'resolution', 'browser_language', 'locale'] },
  { file: 'admin_analytics_regions.json', path: '/admin/analytics/regions', defaultTab: 'countries', reports: ['country', 'region', 'city'] },
  { file: 'admin_analytics_actions.json', path: '/admin/analytics/actions', defaultTab: 'search', reports: ['site_search', 'download', 'outlink', 'goal'] },
];

const grid = loadLayout('partials/admin_analytics/_partial_report_grid.json');

describe('리포트 화면 5종 — 탭·엔드포인트·기간 바', () => {
  for (const s of SCREENS) {
    // 이 케이스는 SCREENS 다섯 화면을 모두 돈다 — 축도 다섯을 적어야 「이 화면은 아무도 안 잰다」가
    // 매니페스트에서 보이지 않는 일이 없다(#682 S3 라운드 3: screen=environment 소비 0회였다).
    // @scenario screen=pages, state=normal, viewport=1440, locale=ko
    // @scenario screen=referrers, state=normal, viewport=1440, locale=ko
    // @scenario screen=environment, state=normal, viewport=1440, locale=ko
    // @scenario screen=regions, state=normal, viewport=1440, locale=ko
    // @scenario screen=actions, state=normal, viewport=1440, locale=ko
    // @effects subtab_is_query_only, tab_maps_to_report_name, report_screens_share_period_bar
    it(`${s.file}: 서브탭은 query.tab 만 읽고 리포트 이름은 엔드포인트가 정한다`, () => {
      const layout = loadLayout(s.file);
      const tabs = nodesNamed(layout, 'TabNavigation')[0];
      expect(tabs?.props?.activeTabId).toBe(`{{query.tab || '${s.defaultTab}'}}`);
      expect(stringify(tabs)).not.toContain('_global.activeAnalyticsTab');

      // 서브탭 전환은 병합하지 않는다 — 리포트마다 행이 달라 쪽·검색·정렬을 물려받으면 빈 화면이 열린다.
      // 기간과 쪽 크기만 query 로 명시해 잇는다.
      const tabNav = actionsWithHandler(tabs, 'navigate')[0];
      expect(tabNav.params).toMatchObject({ path: s.path, replace: true });
      expect(tabNav.params.mergeQuery).toBeUndefined();
      expect(tabNav.params.query).toMatchObject({
        tab: '{{$args[0]}}',
        preset: '{{query.preset}}',
        from: '{{query.from}}',
        to: '{{query.to}}',
        unit: '{{query.unit}}',
        exclude: '{{query.exclude}}',
        per_page: '{{query.per_page}}',
      });
      for (const listKey of ['page', 'q', 'sort']) expect(tabNav.params.query, listKey).not.toHaveProperty(listKey);

      const ds = dataSource(layout, 'analytics_report');
      for (const r of s.reports) expect(ds?.endpoint, r).toContain(`'${r}'`);
      expect(ds?.params?.page).toBe('{{query.page || 1}}');
      expect(stringify(ds?.errorHandling)).toContain('showErrorPage');

      expect(layout.initLocal?.periodPath).toBe(s.path);
      expect(layout.initLocal?.periodSource).toBe('analytics_report');
      expect(layout.computed?.csvUrl).toContain('format=csv');
      const includes = stringify(layout);
      expect(includes).toContain('partials/admin_analytics/_partial_period_bar.json');
      expect(includes).toContain('partials/admin_analytics/_partial_report_grid.json');
      expect(includes).toContain('partials/admin_analytics/_partial_status_alerts.json');
    });
  }

  // @scenario screen=regions, state=geoip_off, viewport=1440, locale=ko
  // @effects region_screen_warns_when_geoip_off
  it('지역 화면은 GeoIP 가 꺼지면 안내와 고급 설정 링크를 띄운다', () => {
    const layout = loadLayout('admin_analytics_regions.json');
    const alert = nodeById(layout, 'geoip_off_alert');
    expect(alert?.if).toContain('geoip?.enabled === false');
    const link = actionsWithHandler(alert, 'navigate')[0];
    expect(link.params).toMatchObject({ path: '/admin/settings', query: { tab: 'advanced' } });
  });

  // @scenario screen=referrers, state=normal, viewport=390, locale=en
  // @effects share_donut_only_on_type_tabs
  it('유입 유형·기기 탭에만 도넛이 붙고 데이터가 있을 때만 그린다', () => {
    const referrers = loadLayout('admin_analytics_referrers.json');
    const environment = loadLayout('admin_analytics_environment.json');
    const rDonut = nodeById(referrers, 'referrer_type_chart_card');
    const eDonut = nodeById(environment, 'device_chart_card');
    expect(rDonut?.if).toContain("=== 'types'");
    expect(eDonut?.if).toContain("=== 'devices'");
    expect(nodesNamed(rDonut, 'DonutChart')).toHaveLength(1);
    expect(nodesNamed(eDonut, 'DonutChart')).toHaveLength(1);
  });

  // @scenario screen=actions, state=empty, viewport=1440, locale=ko
  // @effects dimension_tab_needs_a_key
  it('행동 화면의 커스텀 차원 탭은 차원 키가 있어야 조회한다', () => {
    const layout = loadLayout('admin_analytics_actions.json');
    const ds = dataSource(layout, 'analytics_report');
    expect(ds?.if).toContain("!== 'dimensions' || !!query.dimension");
    expect(ds?.endpoint).toContain("'ext:' + query.dimension");
    expect(nodeById(layout, 'dimensions_empty')?.if).toContain('!query.dimension');
  });
});

describe('공통 표 조각', () => {
  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects report_grid_pages_via_query
  it('표는 선택 불가·서버 쪽 넘김이고 쪽·정렬·검색·건수는 전부 질의 이동이다', () => {
    const dg = nodesNamed(grid, 'DataGrid')[0];
    expect(dg.props.selectable).toBe(false);
    expect(dg.props.serverSidePagination).toBe(true);
    expect(dg.props.serverHasMorePages).toContain('pagination?.has_more');

    // 목록 컨텍스트 이동(쪽·정렬·검색·건수)만 이 계약의 대상이다 — 빈 상태의 「수집 진단 열기」는
    // 목록이 아니라 다른 화면으로 가는 안내라 기간을 병합하지 않는다(#682 S3 라운드 5).
    const allNavs = actionsWithHandler(grid, 'navigate');
    const navs = allNavs.filter((n) => n.params.path === '{{_local.periodPath}}');
    expect(navs.length, '목록 컨텍스트 이동').toBeGreaterThanOrEqual(4);
    expect(navs.every((n) => n.params.mergeQuery === true)).toBe(true);
    // 목록 경로도 아니고 진단 안내도 아닌 이동이 섞이면 잡는다.
    expect(
      allNavs.filter((n) => n.params.path !== '{{_local.periodPath}}').map((n) => n.params.path),
      '조각 안의 목록 밖 이동',
    ).toEqual(['/admin/analytics/diagnostics']);
    expect(navs.some((n) => n.params.query?.page === '{{$args[0]}}')).toBe(true);
    expect(navs.some((n) => typeof n.params.query?.sort === 'string')).toBe(true);
    expect(navs.some((n) => typeof n.params.query?.q === 'string' && n.params.query.page === 1)).toBe(true);
    expect(navs.some((n) => typeof n.params.query?.per_page === 'string')).toBe(true);
  });

  // @scenario screen=actions, state=normal, viewport=1440, locale=ko
  // @effects report_grid_shows_seven_columns_with_extras
  it('열은 라벨·방문·조회·이탈률·체류·방문당 페이지·부가 일곱 개이고 정렬은 방문·조회 둘이다', () => {
    // 진입·이탈 수는 진입·이탈 탭에서는 방문과 늘 같고 다른 탭에서는 늘 0 이다 — 열로 두면 인기 페이지마다
    // 「진입 0·이탈 0」이라는 거짓이 보이고, 그 열로 정렬하면 순서가 무의미해진다(브라우저 실측 §8 M3-T3).
    // CSV 는 부록 D 공통 열 규약대로 두 값을 그대로 싣는다.
    const dg = nodesNamed(grid, 'DataGrid')[0];
    const fields = (dg.props.columns ?? []).map((c: any) => c.field);
    expect(fields).toEqual(['label_display', 'visits', 'hits', 'bounce_rate', 'avg_time', 'pages_per_visit', 'extra']);
    const sortOptions = (nodeById(grid, 'report_sort')?.props?.options ?? []).map((o: any) => o.value);
    expect(sortOptions).toEqual(['visits', 'hits']);
    expect(stringify(dg)).toContain('row.extra?.no_result');
    expect(stringify(dg)).toContain('row.extra?.revenue_sum');
    expect(nodeById(grid, 'report_empty')?.if).toContain('length === 0');
  });
});

describe('리포트 표 — 차원별로 뜻 있는 열만', () => {
  // @scenario screen=actions, state=normal, viewport=1440, locale=ko
  // @effects report_columns_follow_the_dimension
  it('행동 차원(검색어·다운로드·외부 링크·전환·확장)은 이탈률·체류·방문당 페이지를 숨기고, 부가 열은 채우는 차원에만 둔다', () => {
    // 검색어·다운로드 행은 이탈률 0%·체류 0:00·방문당 페이지 1 로만 채워져 읽는 사람을 속였다(브라우저 실측 §8 M3-T8).
    const dg = nodesNamed(grid, 'DataGrid')[0];
    const [expr] = scanBindings(String(dg.props.visibleColumns ?? '')).map((b) => b.expr);
    const visible = (reportName: string) => evaluateSafeExpression(expr, { _computed: { reportName } });

    expect(visible('page_url')).toEqual(['label_display', 'visits', 'hits', 'bounce_rate', 'avg_time', 'pages_per_visit']);
    expect(visible('browser')).toEqual(['label_display', 'visits', 'hits', 'bounce_rate', 'avg_time', 'pages_per_visit']);
    expect(visible('site_search')).toEqual(['label_display', 'visits', 'hits', 'extra']);
    expect(visible('download')).toEqual(['label_display', 'visits', 'hits']);
    expect(visible('goal')).toEqual(['label_display', 'visits', 'hits', 'extra']);
    expect(visible('ext:sirsoft-board:views')).toEqual(['label_display', 'visits', 'hits', 'extra']);
  });
});

describe('리포트 표 — 긴 라벨', () => {
  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects long_labels_wrap_inside_their_cell
  it('라벨 칸은 줄을 바꿔 긴 주소가 표 전체를 넓히지 않는다', () => {
    // DataGrid 칸이 whitespace-nowrap 이라 break-all 이 먹지 않았다 — 512자 주소 하나로 표가 4,140px 가
    // 되어 모든 행의 숫자가 화면 밖으로 밀렸다(브라우저 실측 §8 M3-T10).
    const labelColumn = (nodesNamed(grid, 'DataGrid')[0].props.columns ?? []).find((c: any) => c.field === 'label_display');
    const span = collectObjects(labelColumn).find((o) => o.name === 'Span');
    expect(span?.props?.className).toContain('whitespace-normal');
    expect(span?.props?.className).toContain('break-all');
  });
});

describe('CSV 저장 파일명', () => {
  const FILES: Array<[string, string]> = [
    ['admin_analytics_overview.json', 'series'],
    ['admin_analytics_pages.json', 'page_url'],
    ['admin_analytics_referrers.json', 'referrer_type'],
    ['admin_analytics_environment.json', 'browser'],
    ['admin_analytics_regions.json', 'country'],
    ['admin_analytics_actions.json', 'site_search'],
    ['admin_analytics_bots.json', 'bot_name'],
  ];

  const evalComputed = (layout: any, name: string, ctx: Record<string, any>): unknown => {
    const [expr] = scanBindings(layout.computed?.[name] ?? '').map((b) => b.expr);
    return evaluateSafeExpression(expr, ctx);
  };

  const fileName = (
    file: string,
    query: Record<string, string>,
    local: Record<string, any>,
    served: { from?: string; to?: string } = {},
  ): unknown => {
    const layout = loadLayout(file);
    // 달력 두 칸과 파일명은 같은 값을 읽는다 — 서버가 되돌려 준 구간이 먼저이고, 첫 응답 전에는
    // 초기화가 계산해 둔 범위를 쓴다(#682 라운드 4).
    const servedData = { data: served };
    const ctx: Record<string, any> = {
      query,
      _local: local,
      _computed: {},
      analytics_report: servedData,
      // 개요 응답만 구간을 `data.meta` 에 싣는다(`OverviewController::meta`). 다른 응답처럼 `data.from` 으로
      // 흉내 내면 틀린 경로를 읽는 식이 초록이 된다(#682 S3 라운드 14).
      analytics_overview: { data: { meta: served } },
      analytics_bots: servedData,
      analytics_visitors: servedData,
      analytics_patterns: servedData,
    };
    if (layout.computed?.reportName) {
      ctx._computed.reportName = evalComputed(layout, 'reportName', ctx);
    }
    ctx._computed.periodFrom = evalComputed(layout, 'periodFrom', ctx);
    ctx._computed.periodTo = evalComputed(layout, 'periodTo', ctx);
    return evalComputed(layout, 'csvFilename', ctx);
  };

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects csv_filename_carries_the_actual_date_range
  it('프리셋이어도 저장 파일명은 서버 규약(analytics-{report}-{from}_{to}.csv)대로 실제 날짜 범위를 담는다', () => {
    // 「analytics-page_url-30d.csv」로 저장돼 어느 30일인지 파일만으로 알 수 없었다 — 서버가 준 이름은
    // 날짜를 담는데 blob 저장은 응답 헤더를 쓰지 못해 화면이 준 이름이 이긴다(브라우저 실측 §8 M3-T4).
    const presetLocal = { periodComputed: { from: '2026-08-22', to: '2026-09-20', preset: '30d' } };

    // 서버가 구간을 되돌려 준 뒤 — 파일명은 그 값을 담는다.
    for (const [file, report] of FILES) {
      const served = { from: '2026-08-22', to: '2026-09-20' };
      expect(fileName(file, { preset: '30d' }, {}, served), file).toBe(`analytics-${report}-2026-08-22_2026-09-20.csv`);
    }

    // 첫 응답이 오기 전 — 초기화가 계산해 둔 범위로 채운다(빈 이름이 되지 않는다).
    expect(fileName('admin_analytics_pages.json', { preset: '30d' }, presetLocal), '첫 그림')
      .toBe('analytics-page_url-2026-08-22_2026-09-20.csv');

    // 달력으로 직접 고른 기간은 주소가 이긴다 — 끝 날짜가 없으면 그 하루다.
    expect(
      fileName('admin_analytics_pages.json', { from: '2026-09-01' }, {}, { from: '2026-09-14', to: '2026-09-20' }),
      '끝 날짜가 없으면 그 하루',
    ).toBe('analytics-page_url-2026-09-01_2026-09-01.csv');
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects csv_filename_carries_the_actual_date_range
  it('방문자 단건 CSV 도 버튼이 넘기는 이름이 서버 규약(analytics-visitor-{key}-{from}_{to}.csv)을 따르고, 주소와 같은 기간을 담는다', () => {
    // 이 버튼만 이름을 `analytics-visitor-{key}.csv` 로 고정해 서버가 붙인 기간을 덮었다(#682 S3 라운드 13).
    const layout = loadLayout('admin_analytics_visitor_detail.json');
    const button = nodeById(layout, 'visitor_csv_button');
    const download = collectObjects(button).find((o) => o.handler === 'downloadAttachment');
    expect(download, '방문자 CSV 버튼의 내려받기 액션').toBeDefined();

    const run = (query: Record<string, string>) => {
      const ctx: Record<string, any> = { query, route: { key: 'abc123' }, _computed: {} };
      ctx._computed.csvUrl = evalComputed(layout, 'csvUrl', ctx);
      ctx._computed.csvFilename = evalComputed(layout, 'csvFilename', ctx);
      const [nameExpr] = scanBindings(String(download.params?.filename ?? '')).map((b) => b.expr);
      return { url: String(ctx._computed.csvUrl), name: evaluateSafeExpression(nameExpr, ctx) };
    };

    const ranged = run({ from: '2026-09-01', to: '2026-09-07' });
    expect(ranged.name).toBe('analytics-visitor-abc123-2026-09-01_2026-09-07.csv');
    expect(ranged.url).toContain('from=2026-09-01');
    expect(ranged.url).toContain('to=2026-09-07');

    // 끝 날짜가 없으면 주소처럼 그 하루다.
    expect(run({ from: '2026-09-01' }).name).toBe('analytics-visitor-abc123-2026-09-01_2026-09-01.csv');
    // 기간을 주지 않으면 서버도 접미를 붙이지 않는다.
    expect(run({}).name).toBe('analytics-visitor-abc123.csv');
  });
});

describe('CSV 주소 — 검색어가 있을 때', () => {
  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects csv_url_survives_a_search_term
  it('검색어를 인코딩해 싣고, 식이 실패해 내려받기 버튼이 사라지지 않는다', () => {
    // 평가기가 encodeURIComponent 를 호출하지 못해 검색어가 있으면 csvUrl 이 매 렌더 실패했다 —
    // 버튼이 `!!_computed.csvUrl` 로 숨고 콘솔에 경고가 쌓였다(브라우저 실측 §8 M3-T10).
    for (const file of ['admin_analytics_pages.json', 'admin_analytics_referrers.json', 'admin_analytics_environment.json', 'admin_analytics_actions.json', 'admin_analytics_bots.json']) {
      const layout = loadLayout(file);
      const ctx: Record<string, any> = { query: { preset: '30d', q: 'a b&c' }, _local: {}, _computed: {} };
      if (layout.computed?.reportName) {
        const [nameExpr] = scanBindings(layout.computed.reportName).map((b) => b.expr);
        ctx._computed.reportName = evaluateSafeExpression(nameExpr, ctx);
      }
      const [urlExpr] = scanBindings(layout.computed?.csvUrl ?? '').map((b) => b.expr);
      const url = evaluateSafeExpression(urlExpr, ctx);
      expect(url, file).toContain('&q=a%20b%26c');
    }
  });
});

describe('내려받기 저장 이름 전수', () => {
  // @scenario screen=bots, state=normal, viewport=1440, locale=ko
  // @effects download_filename_matches_the_server_name
  it('화면이 내려받는 모든 자리(탭까지)의 저장 이름이 서버가 붙이는 이름과 같다 — 자리는 레이아웃에서 파생한다', () => {
    // blob 저장은 응답 헤더를 못 써 화면이 준 이름이 이긴다. 기본 탭 7자리만 손으로 재던 가드가 봇 「페이지」 탭
    // (`bot_page` ↔ 서버 `bot_pages`)과 정보주체 JSON(검색한 IP 를 파일명에 넣음)을 놓쳤다(#682 S3 라운드 15).
    const repo = path.resolve(TEMPLATE_DIR, '../../..');
    const controller = fs.readFileSync(path.join(repo, 'app/Http/Controllers/Api/Admin/Analytics/ReportController.php'), 'utf8');
    const dimensions = fs.readFileSync(path.join(repo, 'app/Enums/Analytics/StatsDimension.php'), 'utf8');
    const botCase = controller.slice(controller.indexOf('public function bots(')).match(/StatsDimension::(\w+)->value/)?.[1];
    const botReport = dimensions.match(new RegExp(`case ${botCase} = '([^']+)'`))?.[1];
    expect(botReport, '봇 목록 CSV 가 쓰는 리포트 이름(ReportController::bots)').toBeTruthy();

    const served = { from: '2026-09-01', to: '2026-09-07' };
    // 서버 규약 — `ReportController::exportCsv`·`exportSeries`·`VisitorController::show` 의 sprintf.
    const serverName = (url: string): string | null => {
      const [pathPart, search = ''] = url.split('?');
      const params = new URLSearchParams(search);
      let match: RegExpMatchArray | null;
      if ((match = pathPart.match(/\/api\/admin\/analytics\/reports\/([^/]+)$/))) return `analytics-${match[1]}-${served.from}_${served.to}.csv`;
      if (/\/api\/admin\/analytics\/bots$/.test(pathPart)) return `analytics-${botReport}-${served.from}_${served.to}.csv`;
      if (/\/api\/admin\/analytics\/series$/.test(pathPart)) return `analytics-series-${served.from}_${served.to}.csv`;
      if ((match = pathPart.match(/\/api\/admin\/analytics\/visitors\/([^/]+)$/))) {
        const suffix = params.get('from') && params.get('to') ? `-${params.get('from')}_${params.get('to')}` : '';
        return `analytics-visitor-${match[1]}${suffix}.csv`;
      }
      return null;
    };
    const evalComputed = (layout: any, name: string, ctx: Record<string, any>): unknown => {
      const [expr] = scanBindings(layout.computed?.[name] ?? '').map((b) => b.expr);
      return expr ? evaluateSafeExpression(expr, ctx) : undefined;
    };

    const layoutsDir = path.join(TEMPLATE_DIR, 'layouts');
    const screens = fs
      .readdirSync(layoutsDir)
      .filter((f) => /^admin_analytics_.+\.json$/.test(f))
      .filter((f) => typeof loadLayout(f).computed?.csvUrl === 'string' && typeof loadLayout(f).computed?.csvFilename === 'string');
    expect(screens.length).toBeGreaterThanOrEqual(8);

    const offenders: string[] = [];
    let checked = 0;
    for (const file of screens) {
      const layout = loadLayout(file);
      const tabs = collectObjects(layout)
        .filter((o) => o.name === 'TabNavigation')
        .flatMap((o) => (o.props?.tabs ?? []).map((t: any) => t.id as string));
      for (const tab of tabs.length > 0 ? tabs : [undefined]) {
        const query: Record<string, string> = { preset: '7d', ...(tab ? { tab } : {}) };
        if (file === 'admin_analytics_visitor_detail.json') Object.assign(query, served);
        const echo = { data: served };
        const ctx: Record<string, any> = {
          query,
          route: { key: 'abc123' },
          _local: {},
          _computed: {},
          analytics_report: echo,
          analytics_overview: { data: { meta: served } },
          analytics_bots: echo,
          analytics_visitors: echo,
          analytics_patterns: echo,
        };
        ctx._computed.reportName = evalComputed(layout, 'reportName', ctx);
        ctx._computed.periodFrom = evalComputed(layout, 'periodFrom', ctx);
        ctx._computed.periodTo = evalComputed(layout, 'periodTo', ctx);
        const url = String(evalComputed(layout, 'csvUrl', ctx) ?? '');
        if (!url) continue; // 내려받을 표가 없는 자리 — 버튼이 숨는다.
        const expected = serverName(url);
        const name = evalComputed(layout, 'csvFilename', ctx);
        checked += 1;
        if (expected === null) offenders.push(`${file} tab=${tab}: 규약을 모르는 주소 ${url}`);
        else if (name !== expected) offenders.push(`${file} tab=${tab}: ${String(name)} ≠ 서버 ${expected}`);
      }
    }

    // 계산값을 거치지 않고 이름을 바로 적는 내려받기 — 서버가 알려 준 이름을 써야 한다(정보주체 JSON).
    const inline = screens
      .concat(fs.readdirSync(layoutsDir).filter((f) => /^admin_analytics_.+\.json$/.test(f) && !screens.includes(f)))
      .flatMap((file) => collectObjects(loadLayout(file)).filter((o) => o.handler === 'downloadAttachment').map((o) => ({ file, o })))
      .filter(({ o }) => !String(o.params?.filename ?? '').includes('csvFilename'));
    expect(inline.length).toBeGreaterThanOrEqual(1);
    for (const { file, o } of inline) {
      const [expr] = scanBindings(String(o.params.filename)).map((b) => b.expr);
      const ctx = { query: { subject: 'ip', value: '203.0.113.77' }, analytics_subjects: { data: { export_filename: 'analytics-subject-20260923.json' } } };
      checked += 1;
      if (String(o.params.url).endsWith('/privacy/subjects/export')) {
        const name = expr ? evaluateSafeExpression(expr, ctx) : o.params.filename;
        if (name !== 'analytics-subject-20260923.json') offenders.push(`${file}: 정보주체 JSON 저장 이름 ${String(name)} ≠ 서버가 알려 준 이름`);
      } else {
        offenders.push(`${file}: 규약을 모르는 내려받기 ${String(o.params.url)}`);
      }
    }

    expect(checked).toBeGreaterThanOrEqual(20);
    expect(offenders).toEqual([]);
  });
});

describe('봇 화면', () => {
  // @scenario screen=bots, state=normal, viewport=1440, locale=ko
  // @effects bots_have_no_exclude_toggles
  it('봇 탭과 페이지 탭이 소스를 나눠 갖고 제외 토글을 두지 않는다', () => {
    const layout = loadLayout('admin_analytics_bots.json');
    expect(dataSource(layout, 'analytics_bots')?.if).toContain("=== 'bots'");
    expect(dataSource(layout, 'analytics_report')?.endpoint).toBe('/api/admin/analytics/reports/bot_pages');
    expect(dataSource(layout, 'analytics_report')?.if).toContain("=== 'pages'");
    expect(layout.initLocal?.showExclude).toBe(false);
    expect(stringify(dataSource(layout, 'analytics_bots')?.params)).not.toContain('exclude');
    const recent = nodesNamed(layout, 'DataGrid').find((d) => d.id === 'recent_hits_grid');
    expect((recent?.props.columns ?? []).map((c: any) => c.field)).toEqual(['bot_name', 'url', 'cache_status', 'occurred_at']);
    expect(actionsWithHandler(layout, 'navigate').some((n) => n.params.path === '/admin/settings' && n.params.query?.tab === 'seo')).toBe(true);
  });
});
