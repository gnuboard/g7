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
import { TEMPLATE_DIR, loadLayout, dataSource, nodesNamed, nodeById, actionsWithHandler, stringify, collectObjects, analyticsLayoutFiles } from './analyticsLayoutTestUtils';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { scanBindings } from '@core/template-engine/BindingShape';

/**
 * 리포트 화면마다 설계로 정한 값(기본 탭·엔드포인트가 고르는 리포트 이름·기간 질의 키). 모집단은 이 표가
 * 아니라 `layouts/` 에서 **공통 표 조각을 품는 화면**으로 파생하고, 첫 케이스가 둘을 양방향으로 대조한다
 * — 6번째 리포트 화면이 생기면 이 표에 행을 더하기 전까지 red 다(#682 S3 R7-D4·R11-D2·R12-D3).
 * `screenKeys` 는 그 화면만의 질의 — 유입의 드릴다운 `host` 는 탭을 옮기면 비우고, 행동의 차원 `dimension` 은 잇는다.
 */
const PERIOD_KEYS = ['preset', 'from', 'to', 'unit', 'exclude', 'per_page'];
const SPEC: Record<string, { defaultTab: string; reports: string[]; periodSource: string; periodKeys: string[]; csvReport: string; screenKeys?: Record<string, string> }> = {
  'admin_analytics_pages.json': { defaultTab: 'popular', reports: ['page_url', 'entry_pages', 'exit_pages', 'page_title', 'visit_duration', 'visit_pages'], periodSource: 'analytics_report', periodKeys: PERIOD_KEYS, csvReport: 'page_url' },
  'admin_analytics_referrers.json': { defaultTab: 'types', reports: ['referrer_type', 'referrer_name', 'referrer_keyword', 'campaign', 'campaign_medium'], periodSource: 'analytics_report', periodKeys: PERIOD_KEYS, csvReport: 'referrer_type', screenKeys: { host: '{{undefined}}' } },
  'admin_analytics_environment.json': { defaultTab: 'browsers', reports: ['browser', 'os', 'device_type', 'resolution', 'browser_language', 'locale'], periodSource: 'analytics_report', periodKeys: PERIOD_KEYS, csvReport: 'browser' },
  'admin_analytics_regions.json': { defaultTab: 'countries', reports: ['country', 'region', 'city'], periodSource: 'analytics_report', periodKeys: PERIOD_KEYS, csvReport: 'country' },
  'admin_analytics_actions.json': { defaultTab: 'search', reports: ['site_search', 'download', 'outlink', 'goal', 'search_tab', 'event_name'], periodSource: 'analytics_report', periodKeys: PERIOD_KEYS, csvReport: 'site_search', screenKeys: { dimension: '{{query.dimension}}' } },
  // 봇 화면은 단위 칸·제외 토글을 두지 않는다(`showUnit`·`showExclude` false) — 그 두 키를 서브탭 전환에 싣지 않는다.
  'admin_analytics_bots.json': { defaultTab: 'bots', reports: ['bot_pages'], periodSource: 'analytics_bots', periodKeys: ['preset', 'from', 'to', 'per_page'], csvReport: 'bot_name' },
};

/** `layouts/` 에서 리포트 화면(공통 표 조각을 품는 화면)을 파생한다. */
function reportScreens(): string[] {
  return fs
    .readdirSync(path.join(TEMPLATE_DIR, 'layouts'))
    .filter((f) => /^admin_analytics_.+\.json$/.test(f))
    .filter((f) => collectObjects(loadLayout(f)).some((o) => o.partial === 'partials/admin_analytics/_partial_report_grid.json'))
    .sort();
}

/** `routes.json` 에서 레이아웃 → 경로 */
function routePath(file: string): string | undefined {
  const routes = JSON.parse(fs.readFileSync(path.join(TEMPLATE_DIR, 'routes.json'), 'utf8'));
  const list: any[] = Array.isArray(routes) ? routes : (routes.routes ?? []);
  const route = list.find((r) => `${r?.layout}.json` === file);
  return typeof route?.path === 'string' ? route.path.replace(/^\*/, '') : undefined;
}

const SCREENS = reportScreens();
const grid = loadLayout('partials/admin_analytics/_partial_report_grid.json');

describe('리포트 화면 — 탭·엔드포인트·기간 바', () => {
  // @scenario screen=bots, state=normal, viewport=1440, locale=ko
  // @effects subtab_is_query_only, report_screens_share_period_bar
  it('리포트 화면 모집단(공통 표 조각을 품는 화면)과 설계 표가 서로를 덮는다', () => {
    expect(SCREENS.length, '파생한 리포트 화면').toBeGreaterThanOrEqual(6);
    expect(SCREENS.filter((f) => !(f in SPEC)), '설계 표에 없는 리포트 화면').toEqual([]);
    expect(Object.keys(SPEC).filter((f) => !SCREENS.includes(f)), '공통 표를 잃은 리포트 화면').toEqual([]);

    // 서브탭 전환은 병합하지 않고 기간 키를 손으로 잇는다(R2-E4 — 목록 컨텍스트를 물려받지 않으려는 설계).
    // 그 대가로 기간 바가 새 질의 키를 쓰기 시작하면 서브탭이 그 키를 떨어뜨린다 — 기간 바가 실제로 쓰는
    // 키를 조각에서 파생해 PERIOD_KEYS 와 대조한다(쪽 번호 `page` 는 목록 키라 잇지 않는다).
    const periodBar = loadLayout('partials/admin_analytics/_partial_period_bar.json');
    const written = new Set(
      actionsWithHandler(periodBar, 'navigate').flatMap((n) => Object.keys(n.params?.query ?? {})).filter((k) => k !== 'page'),
    );
    expect(written.size, '기간 바가 쓰는 질의 키').toBeGreaterThanOrEqual(4);
    expect([...written].filter((k) => !PERIOD_KEYS.includes(k)), '서브탭이 잇지 않는 기간 바 키').toEqual([]);
  });

  for (const file of Object.keys(SPEC)) {
    const s = SPEC[file];
    // 이 케이스는 SPEC 여섯 화면을 모두 돈다 — 축도 여섯을 적어야 「이 화면은 아무도 안 잰다」가
    // 매니페스트에서 보이지 않는 일이 없다(#682 S3 라운드 3: screen=environment 소비 0회였다).
    // @scenario screen=pages, state=normal, viewport=1440, locale=ko
    // @scenario screen=referrers, state=normal, viewport=1440, locale=ko
    // @scenario screen=environment, state=normal, viewport=1440, locale=ko
    // @effects subtab_is_query_only, tab_maps_to_report_name, report_screens_share_period_bar
    // @scenario screen=regions, state=normal, viewport=1440, locale=ko
    // @scenario screen=actions, state=normal, viewport=1440, locale=ko
    // @scenario screen=bots, state=normal, viewport=1440, locale=ko
    // @effects subtab_is_query_only, tab_maps_to_report_name, report_screens_share_period_bar
    it(`${file}: 서브탭은 query.tab 만 읽고 리포트 이름은 엔드포인트가 정한다`, () => {
      const layout = loadLayout(file);
      const screenPath = routePath(file);
      expect(screenPath, `${file} 의 라우트`).toBeTruthy();

      const tabs = nodesNamed(layout, 'TabNavigation')[0];
      expect(tabs?.props?.activeTabId).toBe(`{{query.tab || '${s.defaultTab}'}}`);
      expect(stringify(tabs)).not.toContain('_global.activeAnalyticsTab');
      expect((tabs?.props?.tabs ?? []).map((t: any) => t.id), '기본 탭이 탭 목록에 있다').toContain(s.defaultTab);

      // 서브탭 전환은 병합하지 않는다 — 리포트마다 행이 달라 쪽·검색·정렬을 물려받으면 빈 화면이 열린다.
      // 기간과 쪽 크기만 query 로 명시해 잇는다.
      const tabNav = actionsWithHandler(tabs, 'navigate')[0];
      expect(tabNav.params).toMatchObject({ path: screenPath, replace: true });
      expect(tabNav.params.mergeQuery).toBeUndefined();
      expect(tabNav.params.query.tab).toBe('{{$args[0]}}');
      const screenKeys = s.screenKeys ?? {};
      expect(Object.keys(tabNav.params.query).filter((k) => k !== 'tab').sort(), '서브탭이 싣는 질의 키').toEqual([...s.periodKeys, ...Object.keys(screenKeys)].sort());
      for (const key of s.periodKeys) expect(tabNav.params.query[key], key).toBe(`{{query.${key}}}`);
      for (const [key, value] of Object.entries(screenKeys)) expect(tabNav.params.query[key], key).toBe(value);
      for (const listKey of ['page', 'q', 'sort']) expect(tabNav.params.query, listKey).not.toHaveProperty(listKey);
      // 숨긴 칸의 키를 잇지 않는다 / 보이는 칸의 키는 잇는다 — 설계 표와 화면 설정이 같은 말을 한다.
      if (layout.initLocal?.showExclude === false) expect(s.periodKeys).not.toContain('exclude');
      else expect(s.periodKeys).toContain('exclude');

      const ds = dataSource(layout, 'analytics_report');
      // 매핑식이면 따옴표 붙은 리터럴로, 고정 경로면 마지막 마디로 찾는다 — 맨 글자로 찾으면 `os` 가 아무 데나 걸린다.
      for (const r of s.reports) expect(String(ds?.endpoint).includes(`'${r}'`) || String(ds?.endpoint).endsWith(`/${r}`), r).toBe(true);
      expect(ds?.params?.page).toBe('{{query.page || 1}}');
      expect(stringify(ds?.errorHandling)).toContain('showErrorPage');

      expect(layout.initLocal?.periodPath).toBe(screenPath);
      expect(layout.initLocal?.periodSource).toBe(s.periodSource);
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

  // @scenario screen=actions, state=normal, viewport=1440, locale=ko
  // @effects dimension_choices_come_from_the_registered_list
  it('행동 화면의 차원 선택지는 등록 목록 API 에서 온다(자유 입력 없음)', () => {
    const layout = loadLayout('admin_analytics_actions.json');
    const registered = dataSource(layout, 'analytics_registered');
    expect(registered?.endpoint).toBe('/api/admin/analytics/registered');
    expect(registered?.if).toContain("=== 'dimensions'");
    expect(registered?.fallback?.data?.dimensions).toEqual([]);
    expect(stringify(registered?.errorHandling)).toContain('suppress');

    const select = nodeById(layout, 'dimension_select');
    expect(select?.name).toBe('Select');
    expect(nodeById(layout, 'dimension_input')).toBeUndefined();

    // 선택지 식을 실제로 평가해 등록 목록 → {value: 원장 이름, label} 로 바뀌는지 본다.
    const options = evaluateSafeExpression(String(select?.props?.options).replace(/^\{\{|\}\}$/g, ''), {
      analytics_registered: { data: { dimensions: [{ key: 'vendor-board:board_id', label: '게시판', owner_identifier: 'vendor-board' }] } },
    }) as Array<{ value: string; label: string }>;
    expect(options[0].value).toBe('');
    expect(options[1]).toEqual({ value: 'vendor-board:board_id', label: '게시판 · vendor-board' });

    // 등록된 차원이 없을 때와 있을 때 안내가 다르다.
    expect(nodeById(layout, 'dimensions_none')?.if).toContain('.length === 0');
    expect(nodeById(layout, 'dimensions_empty')?.if).toContain('.length > 0');
  });

  // @scenario screen=actions, state=empty, viewport=1440, locale=ko
  // @effects pii_dimension_is_listed_with_the_reason_instead_of_an_empty_table
  it('개인정보 분류 기준은 목록에 표시를 달고, 고르면 빈 표·진단 안내 대신 집계하지 않는 이유를 보인다', () => {
    // 개인정보로 표시된 분류 기준은 집계하지 않는다(#682 S4 라운드 4). 목록에서 빼지 않되(선언이 보여야 한다)
    // 고르면 늘 빈 표와 「수집 진단」 안내가 떠 수집 고장으로 읽혔다(#682 S4 라운드 8).
    const layout = loadLayout('admin_analytics_actions.json');
    const dimensions = [
      { key: 'vendor-board:board_id', label: '게시판', owner_identifier: 'vendor-board', pii: false },
      { key: 'vendor-shop:buyer_email', label: '주문자 이메일', owner_identifier: 'vendor-shop', pii: true },
    ];
    const $t = (key: string) => `[${key}]`;
    const expr = (value: unknown) => String(value).replace(/^\{\{|\}\}$/g, '');

    const options = evaluateSafeExpression(expr(nodeById(layout, 'dimension_select')?.props?.options), {
      analytics_registered: { data: { dimensions } }, $t,
    }) as Array<{ value: string; label: string }>;
    expect(options[1].label).toBe('게시판 · vendor-board');
    expect(options[2].label).toBe('주문자 이메일 ([admin.analytics.actions.dimension_pii_suffix]) · vendor-shop');

    const notice = nodeById(layout, 'dimensions_pii');
    expect(notice?.props?.title).toBe('$t:admin.analytics.actions.dimension_pii_title');
    const gridIf = expr(nodeById(layout, 'report_grid_wrap')?.if);
    const cases: Array<[Record<string, string>, boolean, boolean]> = [
      [{ tab: 'dimensions', dimension: 'vendor-shop:buyer_email' }, true, false],
      [{ tab: 'dimensions', dimension: 'vendor-board:board_id' }, false, true],
      [{ tab: 'search' }, false, true],
    ];
    for (const [query, showNotice, showGrid] of cases) {
      const ctx = { query, analytics_registered: { data: { dimensions } } };
      expect(!!evaluateSafeExpression(expr(notice?.if), ctx), `안내 ${JSON.stringify(query)}`).toBe(showNotice);
      expect(!!evaluateSafeExpression(gridIf, ctx), `표 ${JSON.stringify(query)}`).toBe(showGrid);
    }
  });

  // @scenario screen=referrers, state=empty, viewport=1440, locale=ko
  // @effects empty_grid_shows_one_empty_message
  it('0건이면 표의 빈 문구와 같은 데이터의 빈 화면 안내가 함께 보이지 않는다(쪽 번호도 없음)', () => {
    // 표(빈 문구 + 쪽 번호 「1」)와 아래 빈 화면 안내가 함께 떠 「선택 기간에 기록이 없습니다」가 두 번
    // 보였다(#682 S4 라운드 8). 모집단은 관리자 레이아웃 전체에서 「표 + 그 표의 데이터가
    // 0건일 때 뜨는 빈 화면 안내」 짝을 파생한다 — 불러오기가 끝난 0건 상태에서 식을 평가한다.
    const layoutsDir = path.join(TEMPLATE_DIR, 'layouts');
    const files: string[] = [];
    const walk = (dir: string): void => {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full);
        else if (entry.name.endsWith('.json')) files.push(path.relative(layoutsDir, full));
      }
    };
    walk(layoutsDir);

    const expr = (value: unknown) => String(value ?? '').replace(/^\{\{|\}\}$/g, '');
    // 표를 감싼 조상의 `if` 도 표시 조건이다 — 조상 조건을 모아 둔다.
    const withAncestorIfs = (root: unknown): Array<{ node: Record<string, any>; ifs: string[] }> => {
      const out: Array<{ node: Record<string, any>; ifs: string[] }> = [];
      const visit = (cur: unknown, ifs: string[]): void => {
        if (Array.isArray(cur)) { cur.forEach((c) => visit(c, ifs)); return; }
        if (!cur || typeof cur !== 'object') return;
        const obj = cur as Record<string, any>;
        const own = typeof obj.if === 'string' ? [...ifs, obj.if] : ifs;
        if (obj.name) out.push({ node: obj, ifs: own });
        Object.values(obj).forEach((v) => visit(v, own));
      };
      visit(root, []);
      return out;
    };
    const pairs: string[] = [];
    for (const file of files) {
      const entries = withAncestorIfs(loadLayout(file));
      const nodes = entries.map((e) => e.node);
      const empties = nodes.filter((n) => n.name === 'EmptyState' && typeof n.if === 'string');
      for (const { node: grid, ifs: gridIfs } of entries.filter((e) => e.node.name === 'DataGrid' && typeof e.node.props?.data === 'string')) {
        const dataPath = expr(grid.props.data).replace(/\s*\?\?\s*\[\]\s*$/, '').trim();
        const source = dataPath.match(/^([A-Za-z_]\w*)\?\./)?.[1];
        if (!source) continue;
        for (const empty of empties.filter((e) => e.if.includes(dataPath) && e.if.includes('.length === 0'))) {
          pairs.push(`${file}#${grid.id}`);
          // 데이터 경로를 따라 빈 배열을 심은 문맥(불러오기 끝)
          const ctx: Record<string, any> = {};
          const segments = dataPath.split('?.');
          let cursor: any = (ctx[source] = { loading: false });
          for (const seg of segments.slice(1, -1)) cursor = cursor[seg] = {};
          cursor[segments[segments.length - 1]] = [];
          const gridShown = gridIfs.every((cond) => !!evaluateSafeExpression(expr(cond), ctx));
          const emptyShown = !!evaluateSafeExpression(expr(empty.if), ctx);
          expect(emptyShown, `${file}#${grid.id} 빈 화면 안내`).toBe(true);
          expect(gridShown, `${file}#${grid.id} 표가 빈 화면 안내와 함께 보인다`).toBe(false);
        }
      }
    }
    expect(pairs.length, '표 + 빈 화면 안내 짝 모집단').toBeGreaterThanOrEqual(3);
  });

  // @scenario screen=diagnostics, state=empty, viewport=1440, locale=ko
  // @effects button_variant_classes_come_with_the_base_btn_class
  it('버튼 색 클래스(btn-primary 등)는 기본 모양 클래스 btn 과 함께 쓴다', () => {
    // `.btn-secondary` 는 색·테두리만 있고 여백·모서리는 `.btn` 이 준다 — 빠지면 테두리만 있는 글자가 된다
    // (#682 S4 라운드 8, 빈 화면 「수집 진단 열기」). 모집단은 관리자 레이아웃 전체의 className 값.
    const layoutsDir = path.join(TEMPLATE_DIR, 'layouts');
    const files: string[] = [];
    const walk = (dir: string): void => {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full);
        else if (entry.name.endsWith('.json')) files.push(path.relative(layoutsDir, full));
      }
    };
    walk(layoutsDir);

    let checked = 0;
    const broken: string[] = [];
    for (const file of files) {
      for (const node of collectObjects(loadLayout(file))) {
        const cls = node.props?.className;
        if (typeof cls !== 'string') continue;
        // 식 안의 문자열 리터럴마다 따로 본다('btn btn-primary' : 'btn btn-secondary').
        const literals = cls.includes("'") ? (cls.match(/'[^']*'/g) ?? []).map((l) => l.slice(1, -1)) : [cls];
        for (const literal of literals) {
          const words = literal.split(/\s+/);
          if (!words.some((w) => /^btn-(primary|secondary|outline|ghost|danger)$/.test(w))) continue;
          checked++;
          if (!words.includes('btn')) broken.push(`${file}#${node.id ?? '?'} "${literal}"`);
        }
      }
    }
    expect(checked, '버튼 색 클래스 모집단').toBeGreaterThan(50);
    expect(broken).toEqual([]);
  });

  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects label_value_lists_align_values_in_one_column
  it('「라벨 값」 짝을 세 줄 이상 늘어놓은 목록은 라벨 칸을 맞춘 kv-list 격자에 담는다(값이 한 줄로 시작)', () => {
    // 라벨 폭이 달라 값이 들쭉날쭉 시작하고, 라벨(xs)·값(sm) 기준선이 어긋나던 결함(#682 S4 라운드 8:
    // 방문자 상세 › 방문 정보). 모집단은 이 템플릿 아래에서 그려지는 레이아웃 전부 — 템플릿 + 번들 모듈·플러그인.
    // 짝 = 자식이 둘이고 첫째가 고정 문구 라벨(`$t:` 만)인 행. 가로로 흐르는 요약(부모가 flex)은 목록이 아니다.
    const repo = path.resolve(TEMPLATE_DIR, '../../..');
    const roots = [
      path.join(TEMPLATE_DIR, 'layouts'),
      ...['modules', 'plugins'].flatMap((kind) => {
        const dir = path.join(repo, kind, '_bundled');
        return fs.existsSync(dir) ? fs.readdirSync(dir).map((id) => path.join(dir, id, 'resources', 'layouts')) : [];
      }),
    ].filter((dir) => fs.existsSync(dir));
    const files: string[] = [];
    const walk = (dir: string): void => {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full);
        else if (entry.name.endsWith('.json')) files.push(full);
      }
    };
    roots.forEach(walk);

    const words = (node: any): string[] => (typeof node?.props?.className === 'string' ? node.props.className.split(/\s+/) : []);
    const isPairRow = (row: any): boolean => {
      const kids = Array.isArray(row?.children) ? row.children : [];
      if (kids.length !== 2 || row.iteration) return false;
      const label = typeof kids[0]?.text === 'string' ? kids[0].text : '';
      if (!/^\$t:[\w.-]+$/.test(label.trim())) return false;
      const cls = words(row);
      if (cls.includes('kv-row')) return true;
      const flex = cls.some((w) => ['flex', 'inline-flex', 'flex-center'].includes(w));
      return flex && !cls.some((w) => ['justify-between', 'flex-between', 'flex-col'].includes(w));
    };

    let lists = 0;
    const broken: string[] = [];
    for (const file of files) {
      for (const node of collectObjects(JSON.parse(fs.readFileSync(file, 'utf8')))) {
        const kids = Array.isArray(node.children) ? node.children : [];
        const pairs = kids.filter(isPairRow);
        if (pairs.length < 3) continue;
        const parent = words(node);
        if (parent.some((w) => ['flex', 'inline-flex', 'flex-center'].includes(w))) continue;
        lists++;
        // 라벨에 고정 폭(`w-20`·`min-w-[80px]` 등)을 준 목록(필터 양식 등)도 값이 한 줄로 시작한다.
        const fixedLabel = (p: any): boolean => words(p.children[0]).some((w) => /^(min-)?w-(\d|\[)|^basis-/.test(w));
        const aligned = ((parent.includes('kv-list') || parent.includes('kv-list-2')) && pairs.every((p: any) => words(p).includes('kv-row')))
          || pairs.every(fixedLabel);
        if (!aligned) broken.push(`${path.relative(repo, file)}#${node.id ?? '?'} (${pairs.length}행)`);
      }
    }
    expect(lists, '「라벨 값」 목록 모집단').toBeGreaterThanOrEqual(15);
    expect(broken).toEqual([]);
  });

  // @scenario screen=diagnostics, state=normal, viewport=1440, locale=ko
  // @effects stored_codes_are_shown_as_human_labels
  it('통계 화면은 저장 코드값(scheduled·desktop·KR·ko-KR·hit·gdpr·database·표 이름)을 그대로 보이지 않는다', () => {
    // 집계 실행의 「실행」 열이 `scheduled` 로 찍히던 결함(#682 S4 라운드 8)과 같은 모양 전수.
    // 모집단 = 통계 레이아웃의 화면 문구(text) 중 코드 필드를 읽는 식. 코드 필드는 번역 키 끝(`$t('…' + x)`),
    // 비교·포함 검사, 또는 이름 필드 뒤의 대체값(`x_label ?? x`)으로만 쓰일 수 있다.
    const CODE_FIELDS = ['trigger', 'device_type', 'country_code', 'cache_status', 'consent_source', 'connection', 'browser_language', 'locale', 'table', 'referrer_type', 'visitor_type', 'status', 'run_type', 'reason', 'source'];
    // 이름 옆에 원래 표 이름을 보조 글자로 함께 보이는 자리(확장이 더한 표는 이름이 없어 그 칸이 이름이다).
    const SECONDARY_CODE_NODES = ['storage_table_code'];
    const fieldRe = new RegExp(`[\\w?.\\]]*\\.(${CODE_FIELDS.join('|')})\\b(?!\\s*\\()`, 'g');

    let checked = 0;
    const raw: string[] = [];
    for (const file of analyticsLayoutFiles()) {
      for (const node of collectObjects(loadLayout(file))) {
        if (SECONDARY_CODE_NODES.includes(node.id)) continue;
        for (const text of [node.text, node.props?.text]) {
          if (typeof text !== 'string') continue;
          for (const expr of text.match(/\{\{[\s\S]*?\}\}/g) ?? []) {
            for (const m of expr.matchAll(fieldRe)) {
              checked++;
              const before = expr.slice(0, m.index);
              const after = expr.slice((m.index ?? 0) + m[0].length);
              const ok = /^\s*(===|!==|&amp;&amp;|&&|\?(?!\?)|\))/.test(after) // 비교·조건·호출 인자 끝
                || /\$t\(\s*'[^']*'\s*\+\s*\(?\s*$/.test(before) // 번역 키 끝
                || /(_label|_name|\.label)\s*\?\?\s*$/.test(before) // 이름 뒤의 대체값
                || /\.includes\(\s*$/.test(before)
                // 알려진 값만 번역하고(`[…].includes(x) ? $t(…) : x`) 사이트가 따로 정한 값(큐 연결 이름 등)은 그대로 둔다.
                || (/\]\.includes\(/.test(expr) && expr.includes('$t('));
              if (!ok) raw.push(`${file}#${node.id ?? '?'} ${m[0]} in ${expr.slice(0, 140)}`);
            }
          }
        }
      }
    }
    expect(checked, '코드 필드를 읽는 화면 문구 모집단').toBeGreaterThan(20);
    expect(raw).toEqual([]);
  });

  // @scenario screen=actions, state=normal, viewport=1440, locale=ko
  // @effects report_switch_does_not_inherit_search_sort_page
  it('리포트를 바꾸는 이동(서브탭·차원 선택)은 검색·정렬·쪽을 물려주지 않고 기간만 잇는다', () => {
    // 리포트마다 행이 달라 앞 리포트의 검색어·정렬·쪽을 물려받으면 빈 표가 열린다(§4.10 기간 쿼리).
    // 모집단은 이 화면의 행동 리포트 주소로 가는 이동 중 `tab`·`dimension` 을 새 값으로 바꾸는 것 전부다.
    const layout = loadLayout('admin_analytics_actions.json');
    const switches = actionsWithHandler(layout, 'navigate').filter((nav) => {
      const query = nav.params?.query ?? {};
      return nav.params?.path === '/admin/analytics/actions'
        && ['tab', 'dimension'].some((k) => /\$args|\$event/.test(String(query[k] ?? '')));
    });
    expect(switches.length, '서브탭 전환과 차원 선택').toBeGreaterThanOrEqual(2);
    expect(switches.some((nav) => String(nav.params.query.dimension).includes('$event')), '차원 선택').toBe(true);

    for (const nav of switches) {
      const query = nav.params.query;
      expect(nav.params.mergeQuery, JSON.stringify(query)).not.toBe(true);
      for (const inherited of ['q', 'sort', 'page']) expect(query, inherited).not.toHaveProperty(inherited);
      for (const kept of ['preset', 'from', 'to', 'unit', 'exclude', 'per_page']) expect(query, kept).toHaveProperty(kept);
    }
  });
});

describe('확장 지점 — 통계 화면마다 확장이 조각을 붙일 자리', () => {
  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects every_report_screen_has_an_extension_point
  it('리포트·운영 화면 11면 전부에 analytics_{화면}_widgets 확장 지점이 있다', () => {
    // 모집단은 디렉터리에서 판다 — 화면이 늘면 확장 지점도 함께 늘려야 red 가 풀린다.
    const exempt = new Set(['settings', 'privacy_requests', 'visitor_detail']);
    const screens = fs.readdirSync(path.join(TEMPLATE_DIR, 'layouts'))
      .map((f) => f.match(/^admin_analytics_(.+)\.json$/)?.[1])
      .filter((name): name is string => !!name && !exempt.has(name));

    expect(screens.length).toBeGreaterThanOrEqual(11);
    for (const name of screens) {
      const layout = loadLayout(`admin_analytics_${name}.json`);
      const points = nodesNamed(layout, 'ExtensionPoint').map((n) => n.props?.name ?? n.name);
      const found = collectObjects(layout).some((o) => o.type === 'extension_point' && o.name === `analytics_${name}_widgets`);
      expect(found, `admin_analytics_${name}: ${JSON.stringify(points)}`).toBe(true);
    }
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
  it('열은 라벨·방문·조회·이탈률·체류·방문당 페이지·부가·추이 여덟 개이고 정렬은 방문·조회 둘이다', () => {
    // 진입·이탈 수는 진입·이탈 탭에서는 방문과 늘 같고 다른 탭에서는 늘 0 이다 — 열로 두면 인기 페이지마다
    // 「진입 0·이탈 0」이라는 거짓이 보이고, 그 열로 정렬하면 순서가 무의미해진다(브라우저 실측 §8 M3-T3).
    // CSV 는 부록 D 공통 열 규약대로 두 값을 그대로 싣는다.
    const dg = nodesNamed(grid, 'DataGrid')[0];
    const fields = (dg.props.columns ?? []).map((c: any) => c.field);
    expect(fields).toEqual(['label_display', 'visits', 'hits', 'bounce_rate', 'avg_time', 'pages_per_visit', 'extra', 'trend']);
    const sortOptions = (nodeById(grid, 'report_sort')?.props?.options ?? []).map((o: any) => o.value);
    expect(sortOptions).toEqual(['visits', 'hits']);
    expect(stringify(dg)).toContain('row.extra?.no_result');
    // JSON 의 extra 는 집계가 담은 원래 키(센트)다 — `revenue_sum` 은 CSV 헤더 이름일 뿐이라 화면이 읽으면 늘 「-」
    // (서버 쪽 대조는 ReportControllerTest::test_report_grid_reads_the_extra_keys_the_api_sends).
    expect(stringify(dg)).toContain('row.extra?.revenue_cents');
    expect(stringify(dg)).not.toContain('row.extra?.revenue_sum');
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

    // 행 끝 추이 아이콘(`trend`)은 모든 리포트의 마지막 열이다.
    expect(visible('page_url')).toEqual(['label_display', 'visits', 'hits', 'bounce_rate', 'avg_time', 'pages_per_visit', 'trend']);
    expect(visible('browser')).toEqual(['label_display', 'visits', 'hits', 'bounce_rate', 'avg_time', 'pages_per_visit', 'trend']);
    expect(visible('site_search')).toEqual(['label_display', 'visits', 'hits', 'extra', 'trend']);
    expect(visible('download')).toEqual(['label_display', 'visits', 'hits', 'trend']);
    expect(visible('goal')).toEqual(['label_display', 'visits', 'hits', 'extra', 'trend']);
    expect(visible('ext:sirsoft-board:views')).toEqual(['label_display', 'visits', 'hits', 'extra', 'trend']);
    // 검색 탭·이벤트도 행동을 센다 — 이탈률·체류·방문당 페이지를 숨긴다. 구간·매체 리포트는 방문 차원이라 보인다.
    expect(visible('search_tab')).toEqual(['label_display', 'visits', 'hits', 'trend']);
    expect(visible('event_name')).toEqual(['label_display', 'visits', 'hits', 'trend']);
    expect(visible('visit_duration')).toEqual(['label_display', 'visits', 'hits', 'bounce_rate', 'avg_time', 'pages_per_visit', 'trend']);
    expect(visible('campaign_medium')).toEqual(['label_display', 'visits', 'hits', 'bounce_rate', 'avg_time', 'pages_per_visit', 'trend']);
  });
});

describe('리포트 표 — 한 항목 추이', () => {
  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects row_trend_icon_opens_the_modal
  it('행 끝 추이 아이콘이 리포트 이름·저장 라벨을 전역에 담고 추이 창을 연다', () => {
    const button = nodeById(grid, 'row_trend_button');
    expect(button, '추이 아이콘').toBeTruthy();
    expect(button?.props?.['aria-label']).toBe('$t:admin.analytics.row_trend.open');
    const s = stringify(button);
    expect(s).toContain('analyticsRowTrend');
    expect(s).toContain('{{_computed.reportName}}');
    expect(s).toContain("{{row.label ?? ''}}");
    const open = actionsWithHandler(button, 'openModal')[0];
    expect(open?.target ?? open?.params?.target ?? open?.params?.id).toBe('analytics_row_trend_modal');
    // 소스는 자동으로 받지 않는다 — 엔진에는 「상태가 바뀌면 따라 받기」가 없으므로(`depends_on` 은 편집기 전용 표기)
    // 아이콘이 상태를 정한 뒤 직접 다시 받게 해야 창에 차트가 뜬다(브라우저 실측: 창만 열리고 요청 0건이던 결함).
    const refetch = actionsWithHandler(button, 'refetchDataSource').map((a) => a.params?.dataSourceId ?? a.dataSourceId);
    expect(refetch).toContain('analytics_row_trend');
    const order = collectObjects(button).filter((o) => ['setState', 'refetchDataSource', 'openModal'].includes(o.handler)).map((o) => o.handler);
    expect(order.indexOf('setState')).toBeLessThan(order.indexOf('refetchDataSource'));
  });

  for (const file of Object.keys(SPEC)) {
    // @scenario screen=pages, state=normal, viewport=1440, locale=ko
    // @effects row_trend_source_follows_the_selected_row
    it(`${file}: 추이 창 소스가 고른 행을 따라 받고 창이 등록돼 있다`, () => {
      const layout = loadLayout(file);
      const ds = dataSource(layout, 'analytics_row_trend');
      expect(ds, '추이 소스').toBeTruthy();
      expect(ds?.endpoint).toBe("/api/admin/analytics/reports/{{_global.analyticsRowTrend?.report ?? ''}}/series");
      expect(ds?.auto_fetch).toBe(false);
      expect(ds?.params?.label).toBe("{{_global.analyticsRowTrend?.label ?? ''}}");
      expect(ds?.params?.preset).toBe("{{query.from ? undefined : (query.preset || '7d')}}");
      // 첫 그림에서 평가되는 `if` 로 소스를 거르면 나중에 상태가 정해져도 소스가 없어 받을 수 없다.
      expect(ds?.if).toBeUndefined();
      expect(stringify(layout.modals ?? layout)).toContain('_modal_row_trend.json');
    });
  }

  // 탭 → 리포트 이름 맵은 소스 엔드포인트와 `reportName`(추이 창·CSV 가 쓰는 이름) 두 곳에 중복돼 있다.
  // 한쪽만 고치면 목록은 A 리포트인데 추이 창·CSV 는 B 리포트를 받는다 — 탭마다 두 식을 평가해 대조한다.
  // 모집단은 각 화면의 TabNavigation 탭 목록(레이아웃 파생)이고, 엔드포인트가 고정 경로인 화면(봇)은 대상이 아니다.
  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects tab_maps_to_report_name
  it('탭마다 엔드포인트가 고르는 리포트와 reportName 이 같다', () => {
    const inner = (v: unknown) => String(v ?? '').replace(/^\{\{|\}\}$/g, '');
    let compared = 0;
    for (const file of Object.keys(SPEC)) {
      const layout = loadLayout(file);
      const source = dataSource(layout, 'analytics_report');
      const endpoint = String(source?.endpoint ?? '');
      if (!endpoint.includes('{{')) continue;
      const prefix = endpoint.slice(0, endpoint.indexOf('{{'));
      const tabs = (nodesNamed(layout, 'TabNavigation')[0]?.props?.tabs ?? []).map((t: any) => t.id);
      expect(tabs.length, `${file} 탭`).toBeGreaterThan(0);
      for (const tab of tabs) {
        // 행동 화면의 확장 차원 탭은 차원을 고른 뒤에만 소스가 켜진다 — 고른 상태로 잰다.
        const ctx = { query: { tab, dimension: 'sirsoft-board:board' } };
        if (source?.if && !evaluateSafeExpression(inner(source.if), ctx)) continue;
        const fromEndpoint = evaluateSafeExpression(inner(endpoint.slice(prefix.length)), ctx);
        const fromComputed = evaluateSafeExpression(inner(layout.computed?.reportName), ctx);
        expect(fromComputed, `${file} tab=${tab}`).toBe(fromEndpoint);
        compared++;
      }
    }
    expect(compared, '대조한 탭 수').toBeGreaterThanOrEqual(20);
  });

  // @scenario screen=pages, state=empty, viewport=1440, locale=ko
  // @effects row_trend_modal_shows_empty_and_error
  it('추이 창은 두 선(방문·조회)·빈 상태·오류·단위 낮춤 안내를 갖는다', () => {
    const modal = loadLayout('partials/admin_analytics/_modal_row_trend.json');
    const chart = nodeById(modal, 'row_trend_chart');
    expect(chart?.props?.showLegend).toBe(true);
    expect(chart?.props?.datasets).toContain('admin.analytics.common.visits');
    expect(chart?.props?.datasets).toContain('admin.analytics.common.hits');
    expect(nodeById(modal, 'row_trend_empty')?.if).toContain('length === 0');
    expect(nodeById(modal, 'row_trend_downgraded')?.if).toContain('unit_downgraded');
  });

  // @scenario screen=pages, state=error, viewport=1440, locale=ko
  // @effects row_trend_failure_is_not_shown_as_data
  it('추이 창은 고른 행의 응답일 때만 차트·빈 상태를 그리고, 아니면 오류 카드를 띄운다', () => {
    // 엔진은 실패한 요청에 `.error` 를 싣지 않는다 — 마지막 성공 값(키가 원시 정의라 다른 행의 것)이나
    // fallback 을 성공처럼 돌려준다. 그래서 `.error` 로 가르면 오류 카드는 뜨지 않고, 실패가 「기록 없음」이나
    // 앞서 연 다른 행의 차트로 보인다. 응답의 report·label 이 고른 행과 같은지로 가른다(fallback 엔 둘 다 없다).
    const modal = loadLayout('partials/admin_analytics/_modal_row_trend.json');
    const match = "analytics_row_trend?.data?.label === _global.analyticsRowTrend?.label && analytics_row_trend?.data?.report === _global.analyticsRowTrend?.report";
    for (const id of ['row_trend_chart', 'row_trend_empty', 'row_trend_period']) {
      expect(nodeById(modal, id)?.if, id).toContain(match);
    }
    const errorIf = nodeById(modal, 'row_trend_error')?.if ?? '';
    expect(errorIf).toContain(`!(${match})`);
    expect(errorIf).not.toContain('?.error');
    expect(nodeById(modal, 'row_trend_empty')?.if).not.toContain('?.error');
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
  // 기본 탭의 저장 이름 — 리포트 화면은 설계 표(SPEC)에서, 그 밖에 CSV 를 내는 화면은 여기서 더한다.
  // 방문자 단건(`visitor_detail`)은 규약이 달라(기간이 없으면 접미 없음) 아래 별도 케이스가 잰다.
  const OTHER_CSV: Record<string, string> = { 'admin_analytics_overview.json': 'series' };
  const FILES: Array<[string, string]> = [
    ...Object.entries(OTHER_CSV),
    ...Object.entries(SPEC).map(([file, spec]) => [file, spec.csvReport] as [string, string]),
  ];

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects csv_filename_carries_the_actual_date_range
  it('저장 이름을 계산하는 화면 전부가 아래 검사 모집단에 있다 — 레이아웃에서 파생해 대조한다', () => {
    const derived = fs
      .readdirSync(path.join(TEMPLATE_DIR, 'layouts'))
      .filter((f) => /^admin_analytics_.+\.json$/.test(f) && f !== 'admin_analytics_visitor_detail.json')
      .filter((f) => typeof loadLayout(f).computed?.csvFilename === 'string')
      .sort();
    expect(derived.length, 'csvFilename 을 가진 화면').toBeGreaterThanOrEqual(7);
    expect(FILES.map(([file]) => file).sort()).toEqual(derived);
  });

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

describe('검색어 탭 안내', () => {
  // @scenario screen=referrers, state=empty, viewport=1440, locale=ko
  // @effects keyword_tab_explains_https_empty_keywords
  it('검색어 탭에서만 「HTTPS 검색엔진은 검색어를 넘기지 않는다」 안내가 보인다', () => {
    const referrers = loadLayout('admin_analytics_referrers.json');
    const hint = nodeById(referrers, 'keywords_https_hint');
    expect(hint, '검색어 탭 안내').toBeTruthy();
    const [ifExpr] = scanBindings(String(hint?.if ?? '')).map((b) => b.expr);
    const shown = (tab?: string): boolean => !!evaluateSafeExpression(ifExpr, { query: tab === undefined ? {} : { tab } });
    expect(shown('keywords')).toBe(true);
    expect(shown('sites')).toBe(false);
    expect(shown(undefined), '기본 탭(유형)에서는 숨는다').toBe(false);
  });
});
