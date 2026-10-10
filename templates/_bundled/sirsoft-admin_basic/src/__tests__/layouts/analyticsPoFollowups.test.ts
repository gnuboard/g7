/**
 * #682 라운드 4 — 브라우저 검수에서 짚은 일곱 가지를 잠근다.
 *
 * 합성 입력이 아니라 구운 `.json`·실제 CSS·실제 PHP 설정을 읽는다. 모집단은 저장소에서 파생한다 —
 * 「고친 한 곳」만 세면 같은 결함이 다른 화면에 남아도 초록이다.
 *
 * ① 필터가 주소에 실리는가(요일·시간대 지표 · 기간 바의 뒤로 가기)
 * ② 아이콘 유틸이 상자만 키우고 글리프를 두고 가지 않는가
 * ③ 봇 「최근 요청」이 50행을 통째로 펼치지 않는가
 * ④ 개인정보 모드 카드에 아이콘이 있는가
 * ⑤ 알림 정의 유형 이름이 키로 새지 않는가
 * ⑥ 전체 유입 주소를 볼 길이 있는가
 * ⑦ 회원 방문자를 이름으로 알아보고 회원 화면으로 건너뛸 수 있는가
 * ⑧ 설치 현황 칩이 그 현황을 펼쳐 보이는 카드 곁에 있는가
 */
import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { scanBindings } from '@core/template-engine/BindingShape';

import {
  TEMPLATE_DIR,
  collectObjects,
  dataSource,
  langValue,
  loadLang,
  loadLayout,
  loadLocale,
  localeNames,
  nodeById,
  nodesNamed,
  stringify,
} from './analyticsLayoutTestUtils';

/** 저장소 루트 (템플릿 디렉터리 기준 세 단계 위) */
const REPO_ROOT = path.resolve(TEMPLATE_DIR, '../../..');

/** `{{…}}` 한 겹을 벗긴다 */
function unwrap(expression: string): string {
  const t = expression.trim();
  return t.startsWith('{{') && t.endsWith('}}') ? t.slice(2, -2) : t;
}

/**
 * 조건 식을 화면 계산값까지 풀어 평가한다 — 부분문자열 검사는 뒤집힌 조건도 통과시킨다(#682 S3 R6-D1).
 *
 * @returns 평가값, 평가기에서 실패하면 `'error'`
 */
function evaluateWith(expression: string, computeds: Record<string, unknown>, context: Record<string, unknown>): unknown {
  let out = unwrap(expression);
  for (let pass = 0; pass < 4; pass += 1) {
    const next = out.replace(/_computed\.([A-Za-z0-9_]+)/g, (whole, name: string) =>
      typeof computeds[name] === 'string' ? `(${unwrap(computeds[name] as string)})` : whole);
    if (next === out) break;
    out = next;
  }
  try {
    return evaluateSafeExpression(out, { _computed: {}, _global: {}, _local: {}, query: {}, $t: (key: string) => key, ...context });
  } catch {
    return 'error';
  }
}

/** `navigate` 핸들러 액션을 모두 모은다 */
function navigateActions(root: unknown): Record<string, any>[] {
  return collectObjects(root).filter((o) => o.handler === 'navigate');
}

describe('① 필터는 주소에 실리고 뒤로 가기로 되돌아간다', () => {
  // @scenario screen=patterns, state=normal, viewport=1440, locale=ko
  // @effects patterns_metric_lives_in_the_query_not_in_screen_state
  it('요일·시간대의 지표 선택이 화면 상태가 아니라 주소에 실린다', () => {
    const layout = loadLayout('admin_analytics_patterns.json');
    const json = stringify(layout);

    // 화면 상태(`_local.metric`)로 남아 있으면 새로고침·주소 공유에서 「방문」으로 되돌아간다.
    expect(json).not.toContain('_local.metric');
    expect(json).toContain('query.metric');

    const select = nodeById(layout, 'metric_select');
    expect(select, '지표 선택 칸이 있어야 한다').toBeTruthy();

    const navigates = navigateActions(select);
    expect(navigates.length, '지표를 바꾸면 주소가 바뀌어야 한다').toBeGreaterThanOrEqual(1);
    expect(stringify(navigates)).toContain('metric');

    // 차트 두 장 모두 주소의 값을 읽어야 한 장만 따라가는 어긋남이 없다.
    for (const ref of ['weekday_chart', 'hour_chart']) {
      const chart = nodeById(layout, ref);
      expect(chart, `${ref} 가 있어야 한다`).toBeTruthy();
      expect(stringify(chart)).toContain('query.metric');
    }
  });

  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects period_bar_navigations_are_pushed_so_back_returns_to_the_previous_period
  it('기간 바의 이동은 하나도 빠짐없이 이력에 쌓인다', () => {
    const bar = loadLayout('partials/admin_analytics/_partial_period_bar.json');
    const navigates = navigateActions(bar);

    // 모집단은 조각이 실제로 들고 있는 이동 전량이다 — 프리셋 8 · 달력 2 · 단위 1 · 제외 2 · 초기화 1.
    expect(navigates.length, '기간 바의 이동 개수').toBe(14);

    const replacing = navigates.filter((a) => (a.params ?? a)?.replace === true);
    expect(
      replacing,
      'replace 로 갈아 끼우면 뒤로 가기가 직전 기간이 아니라 통계 화면 밖으로 나간다',
    ).toHaveLength(0);
  });
});

describe('② 아이콘 유틸은 상자와 글리프를 함께 키운다', () => {
  // @scenario screen=overview, state=normal, viewport=1440, locale=ko
  // @effects icon_size_utilities_set_the_glyph_size_not_just_the_box
  it('icon-* 여섯 단계가 모두 글자 크기를 정한다', () => {
    const css = fs.readFileSync(path.join(TEMPLATE_DIR, 'src/styles/main.css'), 'utf8');

    // 모집단을 CSS 에서 파생한다 — 단계를 더 만들면 그 단계도 자동으로 검사 대상이 된다.
    const blocks = [...css.matchAll(/\.(icon-(?:xs|sm|md|lg|xl|2xl))\s*\{([^}]*)\}/g)];
    expect(blocks.length, 'icon-* 크기 단계 수').toBe(6);

    for (const [, name, body] of blocks) {
      // 아이콘은 FontAwesome `<i>` 웹폰트다 — w-/h- 는 상자만 키우고 글리프는 본문 크기에 머문다.
      expect(body, `${name} 에 글자 크기가 없으면 상자만 커진다`).toMatch(/font-size\s*:/);
      expect(body, `${name} 의 상자 크기`).toMatch(/\bw-\d+\b/);
    }
  });
});

describe('③ 봇 최근 요청은 통째로 펼치지 않는다', () => {
  // @scenario screen=bots, state=normal, viewport=1440, locale=ko
  // @effects bot_recent_hits_are_paged_inside_the_card
  it('최근 요청 표에 쪽 나눔이 있다', () => {
    const layout = loadLayout('admin_analytics_bots.json');
    const grid = nodeById(layout, 'recent_hits_grid');

    expect(grid, '최근 요청 표가 있어야 한다').toBeTruthy();

    const props = (grid as any).props ?? grid;
    expect(props.pagination, '서버가 늘 50건을 주므로 화면이 나누지 않으면 카드가 한없이 길어진다').toBe(true);
    expect(Number(props.pageSize), '한 쪽에 보일 행 수').toBeGreaterThan(0);
    expect(Number(props.pageSize)).toBeLessThanOrEqual(25);
  });

  // @scenario screen=bots, state=normal, viewport=1440, locale=ko
  // @effects bot_recent_hits_carry_a_unique_row_id
  it('최근 요청 표의 행 식별자는 겹치지 않는 id 다', () => {
    // 시각(occurred_at)을 행 식별자로 쓰면 같은 초에 들어온 두 요청이 같은 줄로 취급돼, 쪽을 넘겨도
    // 앞 쪽 줄이 지워지지 않고 남았다(한 쪽 12줄 — 7.1.0 실측 E-D3).
    const layout = loadLayout('admin_analytics_bots.json');
    const grid = nodeById(layout, 'recent_hits_grid');
    const props = (grid as any).props ?? grid;

    expect(props.idField, '서버가 싣는 봇 요청 행 번호').toBe('id');
  });

  // @scenario screen=bots, state=normal, viewport=1440, locale=ko
  // @effects stat_cards_never_total_a_paged_list
  it('통계 화면의 KPI 는 쪽으로 잘린 목록을 더하거나 세지 않는다', () => {
    // 봇 「봇 요청」·「봇 종류」가 목록 한 쪽(25행)을 더하고 세어, 봇 종류가 한 쪽보다 많은 기간에는
    // 첫 쪽의 합만 보이고 쪽을 넘기면 KPI 가 그 쪽의 합으로 바뀌었다(#682 S3 라운드 14). 기간 숫자는
    // 서버가 쪽과 무관하게 센 값(`totals`)을 읽는다. 모집단은 통계 레이아웃·조각 전부의 StatCard 다.
    const layoutsDir = path.join(TEMPLATE_DIR, 'layouts');
    const files = [
      ...fs.readdirSync(layoutsDir).filter((f) => f.startsWith('admin_analytics_') && f.endsWith('.json')),
      ...fs.readdirSync(path.join(layoutsDir, 'partials/admin_analytics')).filter((f) => f.endsWith('.json')).map((f) => `partials/admin_analytics/${f}`),
      'partials/admin_dashboard/_analytics_widget.json',
    ];
    const cards = files.flatMap((f) => nodesNamed(loadLayout(f), 'StatCard').map((c) => ({ f, value: String(c.props?.value ?? '') })));

    expect(cards.length, 'StatCard 가 비면 아무것도 재지 않은 채 초록이 된다').toBeGreaterThanOrEqual(9);
    for (const { f, value } of cards) {
      expect(value, `${f} 의 KPI 가 쪽 목록을 더하거나 센다: ${value}`).not.toMatch(/\?\.data\?\.data\s*\?\?\s*\[\]\)\s*\.(reduce|length)/);
    }

    const bots = loadLayout('admin_analytics_bots.json');
    expect(String(nodeById(bots, 'kpi_bot_hits')?.props?.value)).toContain('analytics_bots?.data?.totals?.hits');
    expect(String(nodeById(bots, 'kpi_bot_names')?.props?.value)).toContain('analytics_bots?.data?.totals?.labels');
  });
});

describe('④ 개인정보 모드 카드는 아이콘으로 구분된다', () => {
  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects privacy_mode_cards_carry_distinct_decorative_icons
  it('세 모드 카드가 모두 아이콘을 들고 있다', () => {
    const layout = loadLayout('admin_analytics_settings.json');
    const cards = ['mode_full', 'mode_consent', 'mode_anonymous'];

    const icons = new Set<string>();

    for (const ref of cards) {
      const card = nodeById(layout, ref);
      expect(card, `${ref} 카드가 있어야 한다`).toBeTruthy();

      const found = nodesNamed(card, 'Icon');
      expect(found.length, `${ref} 에 아이콘이 없다`).toBeGreaterThanOrEqual(1);

      const name = String((found[0].props ?? found[0]).name ?? '');
      expect(name, `${ref} 의 아이콘 이름`).not.toBe('');
      icons.add(name);

      // 아이콘은 장식이다 — 이름에 들어가면 버튼 이름이 「users 전체 수집 …」이 된다.
      expect(
        stringify(found[0]),
        `${ref} 의 아이콘이 접근성 이름에서 빠져 있어야 한다`,
      ).toContain('aria-hidden');
    }

    expect(icons.size, '세 모드가 서로 다른 아이콘이어야 구분된다').toBe(cards.length);
  });
});

describe('⑤ 알림 정의 유형은 이름으로 나온다', () => {
  // @scenario screen=settings, state=normal, viewport=1440, locale=ko
  // @effects every_core_notification_type_has_a_localized_name
  it('코어가 정의한 알림 유형 전부에 ko·en 이름이 있다', () => {
    // 모집단을 `config/core.php` 에서 파생한다 — 유형을 새로 더하면 그것도 검사 대상이 된다.
    const php = fs.readFileSync(path.join(REPO_ROOT, 'config/core.php'), 'utf8');
    const start = php.indexOf("    'notification_definitions' => [");
    expect(start, 'config/core.php 에서 알림 정의 절을 찾지 못했다').toBeGreaterThan(-1);

    // 다음 최상위 키(4칸 들여쓰기)가 나오면 절이 끝난 것이다 — 파일 끝까지 자르면 뒤쪽 절의
    // 키(identity_purposes 의 signup 등)까지 알림 유형으로 셈해 엉뚱한 곳을 red 로 만든다.
    const rest = php.slice(start + 1);
    const end = rest.search(/\n {4}'[a-z0-9_]+' => \[/);
    const section = end === -1 ? rest : rest.slice(0, end);

    const types = [...section.matchAll(/^\s{8}'([a-z0-9_]+)' => \[$/gm)].map((m) => m[1]);
    expect(types.length, '코어 알림 유형 수').toBeGreaterThanOrEqual(10);

    for (const locale of ['ko', 'en'] as const) {
      const tree = loadLang(locale, 'admin');
      const names = tree?.settings?.notification_definitions?.types ?? {};

      const missing = types.filter((t) => typeof names[t] !== 'string' || names[t] === '');
      expect(
        missing,
        `${locale}: 이름이 없으면 화면에 키(admin.settings.notification_definitions.types.…)가 그대로 나온다`,
      ).toEqual([]);
    }

    // 값이 들어 있기만 해서는 모자란다 — 영어 원문을 ko 에 그대로 붙여 넣는 실수는 키 검사를
    // 통과한다. ko 는 한글을 한 글자라도 품어야 한다(#682 라운드 4에서 실제로 낸 실수).
    const ko = loadLang('ko', 'admin')?.settings?.notification_definitions?.types ?? {};
    const notKorean = types.filter((t) => !/[가-힣]/.test(String(ko[t] ?? '')));
    expect(notKorean, 'ko 인데 한글이 없는 이름').toEqual([]);
  });
});

describe('⑥ 전체 유입 주소를 볼 길이 있다', () => {
  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects visitor_detail_screen_shows_the_full_referrer_url
  it('방문자 상세가 방문마다 전체 주소를 그린다', () => {
    const layout = loadLayout('admin_analytics_visitor_detail.json');
    const json = stringify(layout);

    expect(json, '호스트만 그리면 어느 글에서 왔는지 알 수 없다').toContain('visit.referrer_url');
    expect(json).toContain('admin.analytics.visitor_detail.referrer_label');
  });

  // @scenario screen=referrers, state=normal, viewport=1440, locale=ko
  // @effects referring_site_rows_open_the_full_url_list_for_that_host
  it('유입 사이트 행에서 그 도메인의 주소 목록으로 들어갈 수 있다', () => {
    const layout = loadLayout('admin_analytics_referrers.json');

    const source = dataSource(layout, 'analytics_referrer_urls');
    expect(source, '주소 목록 소스가 있어야 한다').toBeTruthy();
    expect(String((source as any).endpoint)).toContain('/api/admin/analytics/referrer-urls');
    expect(stringify(source), '고른 도메인을 서버에 넘겨야 한다').toContain('query.host');

    // 라벨을 눌러 들어가는 것은 사이트 탭에서만 켠다.
    expect(stringify(layout)).toContain('labelDrilldownKey');

    const card = nodeById(layout, 'referrer_urls_card');
    expect(card, '주소 목록 카드가 있어야 한다').toBeTruthy();
    expect(nodeById(card, 'referrer_urls_back'), '사이트 목록으로 돌아갈 길이 있어야 한다').toBeTruthy();
  });

  // @scenario screen=referrers, state=normal, viewport=1440, locale=ko
  // @effects referrer_drilldown_follows_in_page_tab_moves
  it('화면 안에서 탭·주소 목록을 옮겨도 라벨 링크 여부가 새 자리를 따른다', () => {
    // `replace` 이동은 `<init>` 을 다시 돌리지 않는다 — 링크 여부를 `<init>` 에서만 정하면 「유형 → 사이트」로
    // 옮긴 표가 글자로만 남고 「사이트 → 검색어」로 옮긴 표가 갈 곳 없는 링크로 남았다(#682 S3 라운드 14, 실측).
    // 모집단은 손으로 적지 않는다 — `tab`·`host` 를 바꾸는 `replace` 이동을 레이아웃에서 전부 뽑는다.
    const layout = loadLayout('admin_analytics_referrers.json');
    const sequences = collectObjects(layout).filter((o) => o.handler === 'sequence' && Array.isArray(o.actions));
    const moves = collectObjects(layout).filter(
      (o) => o.handler === 'navigate' && o.params?.replace === true
        && o.params?.query && ('tab' in o.params.query || o.params.query.host === '{{undefined}}'),
    );

    expect(moves.length, '탭 전환·목록 복귀 이동이 비면 아무것도 재지 않은 채 초록이 된다').toBeGreaterThanOrEqual(2);

    for (const move of moves) {
      const seq = sequences.find((s) => s.actions.includes(move));
      const before = seq ? seq.actions.slice(0, seq.actions.indexOf(move)) : [];
      const keySet = before.find((a: any) => a.handler === 'setState' && 'labelDrilldownKey' in (a.params ?? {}));
      expect(keySet, `${JSON.stringify(move.params.query)} 이동 앞에서 링크 여부를 맞추지 않는다`).toBeTruthy();

      // 새 탭이 사이트면 켜고 아니면 끈다 · 주소 목록에서 돌아오면 켠다.
      const expr = String(keySet.params.labelDrilldownKey);
      if ('tab' in move.params.query) {
        const evalKey = new Function('$args', `return (${expr.replace(/^\{\{|\}\}$/g, '')});`);
        expect(evalKey(['sites'])).toBe('host');
        expect(evalKey(['types'])).toBe('');
        expect(evalKey(['keywords'])).toBe('');
      } else {
        expect(expr).toBe('host');
      }
    }
  });

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko
  // @effects report_grid_label_links_only_where_the_screen_opts_in
  it('공통 리포트 표는 켜지 않은 화면에서 라벨을 링크로 만들지 않는다', () => {
    const grid = loadLayout('partials/admin_analytics/_partial_report_grid.json');
    const json = stringify(grid);

    // 켜는 화면만 링크가 된다 — 조건 없이 링크가 되면 페이지·환경·지역에서 갈 곳 없는 이동이 생긴다.
    expect(json).toContain("_local.labelDrilldownKey === 'host'");
    expect(json).toContain("_local.labelDrilldownKey !== 'host'");

    // 이 조각을 쓰는 화면 가운데 실제로 켜는 곳은 유입 경로 하나여야 한다.
    const layoutsDir = path.join(TEMPLATE_DIR, 'layouts');
    const users = fs
      .readdirSync(layoutsDir)
      .filter((f) => f.endsWith('.json') && f.startsWith('admin_analytics_'))
      .filter((f) => fs.readFileSync(path.join(layoutsDir, f), 'utf8').includes('labelDrilldownKey'));

    expect(users).toEqual(['admin_analytics_referrers.json']);
  });
});

describe('⑨ 신규·재방문 카드는 기록 없음과 구분 불가를 가른다', () => {
  // 방문이 0건인 기간도 점의 신규·재방문이 null 이라, 카드가 「—」와 「익명 모드에서는 신규·재방문을 구분할 수
  // 없습니다」를 띄웠다 — 익명 모드가 아닌데 익명 모드 탓으로 읽힌다(7.1.0 실측 E-D9, 09-27 하루 조회).
  // 기록이 없으면 0 · 0 이고, 방문이 있는데 가를 수 없을 때만 구분 불가를 말한다.
  // @scenario screen=overview, state=empty, viewport=1440, locale=ko
  // @effects new_returning_card_separates_no_visits_from_indistinguishable
  it('방문 0건 기간은 0 · 0, 방문이 있는데 모두 null 이면 — 와 구분 불가 안내', () => {
    const layout = loadLayout('admin_analytics_overview.json');
    const card = nodeById(layout, 'quality_new_returning') as any;
    expect(card, '신규·재방문 카드').toBeTruthy();
    const value = card.children.find((c: any) => c.name === 'H3').text as string;
    const hint = card.children.find((c: any) => c.text === '$t:admin.analytics.overview.new_returning_unavailable') as any;
    expect(hint?.if, '구분 불가 안내의 조건').toBeTruthy();

    const ctx = (points: unknown[]) => ({ analytics_overview: { data: { series: { points } } } });
    const none = ctx([{ ts: '2026-09-27', visits: 0, new_visitors: null, returning_visitors: null }]);
    const anonymous = ctx([{ ts: '2026-09-27', visits: 3, new_visitors: null, returning_visitors: null }]);
    const split = ctx([{ ts: '2026-09-27', visits: 3, new_visitors: 1, returning_visitors: 2 }]);

    expect(evaluateSafeExpression(unwrap(value), none)).toBe('0 · 0');
    expect(evaluateSafeExpression(unwrap(hint.if), none), '기록이 없으면 구분 불가 안내를 띄우지 않는다').toBe(false);
    expect(evaluateSafeExpression(unwrap(value), anonymous)).toBe('—');
    expect(evaluateSafeExpression(unwrap(hint.if), anonymous)).toBe(true);
    expect(evaluateSafeExpression(unwrap(value), split)).toBe('1 · 2');
    expect(evaluateSafeExpression(unwrap(hint.if), split)).toBe(false);
  });
});

describe('⑦ 회원 방문자는 이름으로 알아본다', () => {
  // @scenario screen=visitors, state=normal, viewport=1440, locale=ko
  // @effects visitor_list_shows_members_by_name_with_an_actor_menu
  it('방문자 목록에 회원 칸과 행위자 메뉴가 있다', () => {
    const layout = loadLayout('admin_analytics_visitors.json');
    const json = stringify(layout);

    expect(json, '회원 칸 머리글').toContain('admin.analytics.visitors.columns.member');
    expect(json, '이름·이메일을 그려야 번호만 보이지 않는다').toContain('row.user.name');

    const menus = nodesNamed(layout, 'ActionMenu');
    expect(menus.length, '이름을 눌렀을 때 뜨는 메뉴가 있어야 한다').toBeGreaterThanOrEqual(1);

    const menuJson = stringify(menus);
    // 회원 화면은 새 창으로 — 보던 목록을 잃지 않는다(활동 로그와 같은 규약).
    expect(menuJson).toContain('openWindow');
    expect(menuJson).toContain('/admin/users/');
    expect(menuJson).toContain('admin.analytics.visitors.action.view_member');
    expect(menuJson).toContain('admin.analytics.visitors.action.search_by_member');
  });

  // 「이 회원으로 검색」은 그 회원의 방문만 봐야 한다. 숫자만 검색칸에 넣으면 서버가 방문자 키 앞부분에도 대 보아
  // 키가 같은 숫자로 시작하는 비회원이 섞였다(브라우저 실측: 회원 1 을 찾으면 키가 1 로 시작하는 비회원 방문이 함께 나왔다).
  // `#번호` 는 서버가 회원 번호로만 찾는 표기다.
  // @scenario screen=visitors, state=normal, viewport=1440, locale=ko
  // @effects visitor_list_shows_members_by_name_with_an_actor_menu
  it('「이 회원으로 검색」은 #회원번호 로 찾는다', () => {
    const layout = loadLayout('admin_analytics_visitors.json');
    const menuJson = stringify(nodesNamed(layout, 'ActionMenu'));

    expect(menuJson).toContain("'#' + String(row.user_id ?? '')");
    expect(menuJson).not.toContain('"q":"{{String(row.user_id ?? \'\')}}"');
  });

  // 표 셀(cellChildren)은 `{row, value}` 에 데이터소스만 얹어 그린다 — `_computed` 는 빈 객체다
  // (G7Core.renderItemChildren: 「_computed 는 itemContext 에서 전달받거나 비어 있음」). 셀의 조건이
  // `_computed.canReadUsers` 를 읽으면 늘 거짓이라, 실시간 화면의 회원 이름이 회원 조회 권한이 있어도
  // 링크가 되지 않았다(7.1.0 실측 E-D4). 클릭 때 도는 액션은 별도 문맥이라 대상이 아니다.
  // 모집단은 통계 레이아웃·조각 전부다.
  // @scenario screen=realtime, state=normal, viewport=1440, locale=ko
  // @effects table_cells_do_not_read_computed_values
  it('통계 표의 셀 조건·값은 _computed 를 읽지 않는다', () => {
    const layoutsDir = path.join(TEMPLATE_DIR, 'layouts');
    const files = [
      ...fs.readdirSync(layoutsDir).filter((f) => f.startsWith('admin_analytics_') && f.endsWith('.json')),
      ...fs.readdirSync(path.join(layoutsDir, 'partials/admin_analytics')).filter((f) => f.endsWith('.json')).map((f) => `partials/admin_analytics/${f}`),
    ];
    const offenders: string[] = [];
    let cellCount = 0;

    const walk = (node: unknown, file: string, inCell: boolean): void => {
      if (Array.isArray(node)) {
        node.forEach((n) => walk(n, file, inCell));
        return;
      }
      if (node && typeof node === 'object') {
        for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
          if (key === 'actions') continue; // 클릭 때 도는 액션은 액션 문맥(_computed 포함)으로 평가된다
          if (key === 'comment') continue; // 마크업 주석은 평가되지 않는다
          if (key === 'cellChildren') cellCount += 1;
          walk(value, file, inCell || key === 'cellChildren');
        }
        return;
      }
      if (inCell && typeof node === 'string' && node.includes('_computed')) {
        offenders.push(`${file}: ${node.slice(0, 80)}`);
      }
    };

    for (const f of files) walk(loadLayout(f), f, false);

    expect(cellCount, '셀 모집단이 비면 아무것도 재지 않는다').toBeGreaterThan(5);
    expect(offenders).toEqual([]);
  });

  // @scenario screen=visitors, state=normal, viewport=1440, locale=en
  // @effects member_column_labels_exist_in_both_locales
  it('회원 칸이 쓰는 이름이 모든 로케일(ko·en·ja)에 있다', () => {
    const keys = [
      'analytics.visitors.columns.member',
      'analytics.visitors.action.view_member',
      'analytics.visitors.action.search_by_member',
      'analytics.visitor_detail.referrer_label',
      'analytics.referrers.urls_title',
      'analytics.referrers.urls_back',
      'analytics.referrers.urls_column',
      'analytics.referrers.urls_hint',
      'analytics.referrers.urls_empty_description',
    ];

    // 로케일은 디렉터리·언어팩에서 판다(ja 포함). 모집단이 비면 아래 비교가 조용히 통과한다.
    const locales = localeNames();
    expect(keys.length, '검사할 키').toBeGreaterThan(0);
    expect(locales, '로케일 모집단').toContain('ja');
    for (const locale of locales) {
      const tree = loadLocale(locale, 'admin');
      const missing = keys.filter((k) => typeof langValue(tree, `admin.${k}`) !== 'string');
      expect(missing, `${locale} 에 없는 키`).toEqual([]);
    }
  });
});

describe('⑧ 설치 현황 칩은 상태 카드 곁에 선다', () => {
  // @scenario screen=dashboard_widget, state=normal, viewport=1440, locale=ko
  // @effects install_summary_chips_sit_next_to_the_status_cards
  it('활성 모듈·플러그인·템플릿·언어팩 칩이 상태 카드 바로 위에 온다', () => {
    const layout = loadLayout('admin_dashboard.json');

    // 구운 JSON 을 문자열로 훑어 두 블록의 등장 순서를 본다 — 칩이 화면 맨 위에 떨어져 있으면
    // 「3/3」이 무엇의 3인지 알 수 없다(#682 라운드 4, 검수 지적 ⑧).
    const json = stringify(layout);
    const chips = json.indexOf('"secondary_stats_badges"');
    const cards = json.indexOf('"extension_status_grid"');
    const analytics = json.indexOf('_analytics_widget');

    expect(chips, '칩 묶음이 있어야 한다').toBeGreaterThan(-1);
    expect(cards, '상태 카드 묶음이 있어야 한다').toBeGreaterThan(-1);
    expect(chips, '칩은 상태 카드보다 앞에 온다').toBeLessThan(cards);
    expect(chips, '칩은 방문자 통계 카드보다 뒤에 온다 — 맨 위가 아니다').toBeGreaterThan(analytics);

    // 칩 네 개가 그대로 있어야 한다(옮기면서 빠뜨리지 않았는지).
    for (const id of ['modules_badge', 'plugins_badge', 'templates_badge', 'language_packs_badge']) {
      expect(nodeById(layout, id), id).toBeTruthy();
    }
  });
});

describe('⑨ 통계 화면 안의 이동은 기간을 데리고 간다', () => {
  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko
  // @effects analytics_internal_navigation_keeps_the_period
  it('통계 화면끼리의 이동 전량이 mergeQuery 이거나 기간 키를 명시로 넘긴다', () => {
    // §4.10 은 「모든 통계 화면이 같은 키를 쓰고 navigate 는 mergeQuery:true 라 탭·화면을 옮겨도
    // 기간이 유지되고 URL 공유가 그대로 열린다」고 못박았다. 라운드 4 까지 정보주체 화면의 방문자
    // 드릴다운 한 자리만 이 모집단에서 사유 없이 빠져 있었다 — 기간을 좁혀 찾은 뒤 상세로 들어가면
    // 기간이 떨어지고 그 화면의 단건 CSV 도 다른 기간을 내려줬다(#682 S3 라운드 5).
    // 모집단을 레이아웃 디렉터리에서 파생한다 — 화면이 늘면 그 화면의 이동도 자동으로 검사 대상이다.
    const dir = path.join(TEMPLATE_DIR, 'layouts');
    const files = fs.readdirSync(dir).filter((f) => /^admin_analytics_.+\.json$/.test(f));
    expect(files.length, '통계 화면 레이아웃').toBeGreaterThanOrEqual(14);

    // 기간을 명시로 넘기는 이동(서브탭 전환 등)은 병합하지 않아도 기간이 보존된다.
    const PERIOD_KEYS = ['preset', 'from', 'to'];
    const offenders: string[] = [];

    for (const file of files) {
      const layout = loadLayout(file);

      // 기간을 주소에서 읽지 않는 화면(설정)은 데리고 갈 기간이 없다 — 그 화면의 탭 이동에
      // mergeQuery 를 붙이면 없는 키를 옮기는 셈이다. 모집단을 문언이 아니라 코드에서 가른다.
      const json = stringify(layout);
      if (!json.includes('query.preset') && !json.includes('query.from')) continue;

      for (const nav of navigateActions(layout)) {
        const target = String(nav.params?.path ?? '');
        // 통계 도메인 밖으로 나가는 이동은 병합하면 코어 화면에 통계 키가 새어 들어간다 — 제외한다.
        if (!target.startsWith('/admin/analytics')) continue;
        if (nav.params?.mergeQuery === true) continue;
        const query = nav.params?.query ?? {};
        if (PERIOD_KEYS.every((k) => query[k] !== undefined)) continue;
        offenders.push(`${file}: ${target} → ${JSON.stringify(query)}`);
      }
    }

    expect(offenders, '기간을 데리고 가지 않는 통계 화면 간 이동').toEqual([]);
  });
});

describe('⑩ 주소를 그리는 화면은 그 주소가 마스킹된 값임을 함께 말한다', () => {
  // @scenario screen=visitor_detail, state=normal, viewport=1440, locale=ko
  // @effects masked_ip_is_labelled_as_masked
  it('방문자 주소를 그리는 통계 화면 전량이 ip_mask_octets 로 마스킹 사실을 보인다', () => {
    // §4.10 은 방문자 상세 카드를 「IP(마스킹 표시)」로 적었다. 설정이 뒤 옥텟을 지운 값을
    // 원본으로 읽으면 정보주체가 「내 접속 기록이 맞느냐」 물었을 때 그 주소로 잘못 답한다
    // (#682 S3 라운드 6). 모집단을 레이아웃에서 파생한다 — 주소를 그리는 화면이 늘면 같이 걸린다.
    const dir = path.join(TEMPLATE_DIR, 'layouts');
    const files = fs.readdirSync(dir).filter((f) => /^admin_analytics_.+\.json$/.test(f));
    expect(files.length, '통계 화면 레이아웃').toBeGreaterThanOrEqual(14);

    // 현재 접속자 표는 지금 이 순간의 주소를 원장 그대로 보이는 자리라 마스킹 설정을 따로
    // 알리지 않는다 — 실시간은 보존·소급 대상이 아니고 카드가 아니라 흐르는 목록이다.
    const REALTIME = 'admin_analytics_realtime.json';
    const drawsIp: string[] = [];
    const missing: string[] = [];

    for (const file of files) {
      if (file === REALTIME) continue;
      const layout = loadLayout(file);
      const json = stringify(layout);
      // 주소 「값」을 그리는 자리만 센다 — 검색 입력·파라미터의 ip 는 그리는 것이 아니다.
      if (!/\{\{[^}]*\.ip\s*\?\?/.test(json) && !/row\.ip\b/.test(json)) continue;
      drawsIp.push(file);
      // 문자열에 이름이 있는지가 아니라, 그리는 자리의 식이 마스킹 옥텟 수에 따라 **달리 그리는지**를 잰다.
      // 주석·fallback·요청 파라미터는 그리는 자리가 아니다.
      const sites: string[] = [];
      const walk = (value: unknown, key: string): void => {
        if (typeof value === 'string') {
          if (value.includes('ip_mask_octets') && value.includes('{{')) sites.push(...scanBindings(value).map((b) => b.expr).filter((e) => e.includes('ip_mask_octets')));
          return;
        }
        if (Array.isArray(value)) { value.forEach((v) => walk(v, key)); return; }
        if (value && typeof value === 'object') {
          for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
            if (k === 'comment' || k === 'fallback' || k === 'params' || k === 'data_sources' || k === 'actions') continue;
            walk(v, k);
          }
        }
      };
      walk(layout, '');
      const reacts = sites.some((expr) => {
        const ctxFor = (octets: number): Record<string, unknown> => {
          const ctx: Record<string, any> = {};
          for (const m of expr.matchAll(/([A-Za-z_]+)((?:\?\.[A-Za-z_]+)*)\?\.ip_mask_octets/g)) {
            const path = [m[1], ...m[2].split('?.').filter(Boolean)];
            let cur = ctx;
            for (const part of path) cur = (cur[part] = cur[part] ?? {});
            cur.ip_mask_octets = octets;
          }
          return ctx;
        };
        const off = evaluateWith(expr, {}, ctxFor(0));
        const on = evaluateWith(expr, {}, ctxFor(2));
        return off !== 'error' && on !== 'error' && JSON.stringify(off) !== JSON.stringify(on);
      });
      if (!reacts) missing.push(`${file} (옥텟 수를 읽는 그리는 식 ${sites.length}개 — 0 과 2 에서 같은 것을 그린다)`);
    }

    expect(drawsIp.length, '주소를 그리는 통계 화면').toBeGreaterThanOrEqual(2);
    expect(missing, '마스킹 사실을 말하지 않고 주소만 그리는 화면').toEqual([]);
  });
});

describe('⑪ 회원 화면으로 건너뛰는 자리는 목적지 권한을 먼저 본다', () => {
  // @scenario screen=realtime, state=normal, viewport=1440, locale=ko
  // @effects member_jump_is_gated_by_destination_permission
  it('통계 화면의 /admin/users 이동 전량이 core.users.read 가드를 갖는다', () => {
    // 목적지는 core.users.read 를 요구한다 — 가드 없이 그리면 개인정보 권한만 가진 담당자가
    // 눌러 「권한 없음」 새 창을 받는다(막다른 안내). 라운드 6 이 실시간 표에 같은 이동을
    // 더했으므로 한 자리가 아니라 전량을 파생해 고정한다.
    const dir = path.join(TEMPLATE_DIR, 'layouts');
    const files = fs.readdirSync(dir).filter((f) => /^admin_analytics_.+\.json$/.test(f));
    const jumps: string[] = [];
    const ungated: string[] = [];

    const perms = (list: string[]) => ({ current_user: { data: { permissions: list.map((identifier) => ({ identifier })) } } });
    for (const file of files) {
      const layout = loadLayout(file);
      const computeds = (layout.computed ?? {}) as Record<string, unknown>;
      // 이동 액션마다 조상 사슬의 조건(노드 `if` 와 메뉴 항목 `if`)을 모아, 그중 하나가 목적지 권한으로
      // 실제로 갈리는지 평가한다 — 권한 없는 계정에서 거짓, 있는 계정에서 참. 문자열 포함으로 재면
      // 뒤집힌 가드(`!…`)도 통과한다(#682 S3 R6-D1).
      const walk = (value: unknown, chain: Record<string, any>[]): void => {
        if (Array.isArray(value)) { value.forEach((v) => walk(v, chain)); return; }
        if (!value || typeof value !== 'object') return;
        const node = value as Record<string, any>;
        if ((node.handler === 'openWindow' || node.handler === 'navigate') && String(node.params?.path ?? '').startsWith('/admin/users/')) {
          jumps.push(file);
          const guards = chain.flatMap((owner) => [
            ...(typeof owner.if === 'string' ? [owner.if] : []),
            ...((owner.props?.items ?? []) as any[]).filter((item) => typeof item?.if === 'string').map((item) => item.if as string),
          ]);
          const row = { row: { user: { uuid: 'u-1', name: 'n' } } };
          const verdicts = guards.map((g) => ({
            without: evaluateWith(g, computeds, { ...row, ...perms(['core.analytics.read', 'core.analytics.privacy']) }),
            with: evaluateWith(g, computeds, { ...row, ...perms(['core.analytics.read', 'core.analytics.privacy', 'core.users.read']) }),
          }));
          if (!verdicts.some((v) => !v.without && v.without !== 'error' && !!v.with && v.with !== 'error')) {
            ungated.push(`${file}: ${node.params.path} 앞 조건 ${guards.length}개 중 core.users.read 로 갈리는 것이 없다`);
          }
        }
        for (const v of Object.values(node)) walk(v, [...chain, node]);
      };
      walk(layout, []);
    }

    expect(jumps.length, '회원 화면으로 건너뛰는 통계 화면').toBeGreaterThanOrEqual(2);
    expect(ungated, '목적지 권한을 보지 않는 회원 이동').toEqual([]);
  });
});
