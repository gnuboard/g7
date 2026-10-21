/**
 * @file admin-list-loading-empty-state.test.tsx
 * @description 관리자 목록·빈 상태 안내 — 목록을 받는 동안에는 「없음」을 말하지 않는다
 *
 * 목록 데이터소스는 화면이 먼저 그려진 뒤에 받는다(progressive·background). 받기 전에는 엔진이
 * 데이터소스 값을 `undefined` 로 두므로, 그 값만 보고 「행이 0개」라고 판단하는 표·안내 문구는
 * 데이터가 있는 화면에서도 받는 동안 「없습니다」를 먼저 보였다. 일부 화면은 `x?.loading` 으로
 * 받는 중을 가르려 했지만 엔진은 데이터소스 값에 `loading` 을 싣지 않아 그 조건은 늘 거짓이었다.
 *
 * 「받는 중」은 데이터소스 값이 `undefined` 인 것이다 — 엔진의 흐림 판정(blur_until_loaded)과 같은
 * 기준이고, 받기에 실패하면 엔진이 값을 `null` 로 바꾸므로 그때는 안내가 다시 보인다.
 *
 * 각 화면의 실제 레이아웃 JSON 에서 대상 노드를 꺼내 하네스로 렌더한다:
 *
 *  1. 받는 중(값 `undefined`) — 안내가 없다. 표는 빈 문구 없이 흐림 처리된다.
 *  2. 받은 뒤 0건 — 안내가 보인다(표는 빈 문구, 행이 없으면 표를 숨기는 화면은 표가 사라진다).
 *  3. 받기 실패(값 `null`) — 안내가 보인다(받는 중으로 영영 남지 않는다).
 *  4. 받은 뒤 1건 이상 — 안내가 없다.
 *  5. 전수 — 관리자 레이아웃의 목록 표·빈 안내는 위 표본이거나 사유가 적힌 제외 대상이고,
 *     데이터소스 값의 `loading` 을 읽는 죽은 조건이 남아 있지 않다.
 *
 * 표·카드 그리드·알림 센터는 스텁이다. 실제 컴포넌트의 빈 상태 규칙만 흉내 낸다:
 *  - DataGrid: 행이 없으면 `emptyMessage ?? 기본 문구`(빈 문자열이면 아무것도 그리지 않는다)
 *  - ExtensionCardGrid: `items` 가 배열이 아니거나 `loading` 이고 비었으면 자리표시자, 아니면 빈 문구
 *  - NotificationCenter: 목록이 비었고 `loading` 이 아니면 빈 문구
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { normalizeConditionRecipes, parseConditionExpr, combineConditions } from '@core/template-engine/layout-editor/spec/conditionRecipeEngine';

type Node = Record<string, any>;

type Kind = 'grid' | 'node' | 'cardgrid' | 'notification';

interface Case {
  /** 화면·자리 이름(시험 제목용) */
  screen: string;
  /** `layouts/` 기준 JSON 경로 */
  file: string;
  /** 대상 노드 찾기 — id(data-ref) 또는 이름 + 조건식 일부 */
  find: { id?: string; name?: string; ifIncludes?: string; ifExcludes?: string };
  /** 대상이 묶인 데이터소스 */
  source: string;
  kind: Kind;
  /** 받은 뒤 0건(또는 안내가 떠야 하는) 값 */
  empty: unknown;
  /** 받은 뒤 행이 있는 값 */
  rows: unknown;
  /** 표 빈 문구 키(grid) */
  emptyKey?: string;
  /** 0건이면 표 자체를 숨기는 화면(빈 안내는 따로 있는 EmptyState 가 맡는다) */
  hideGridWhenEmpty?: boolean;
  /** 노드 조건이 읽는 다른 값 */
  initialData?: Record<string, any>;
  queryParams?: Record<string, string>;
  globalState?: Record<string, any>;
  computed?: Record<string, string>;
}

const list = (rows: any[]) => ({ data: { data: rows, pagination: { current_page: 1, last_page: 1, has_more: false, has_more_pages: false } } });

const CASES: Case[] = [
  // ── 표(DataGrid) ──
  { screen: '봇 분석 · 봇별 집계 표', file: 'admin_analytics_bots.json', find: { id: 'bot_names_grid' }, source: 'analytics_bots', kind: 'grid', emptyKey: 'admin.analytics.common.empty_title', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]), hideGridWhenEmpty: true },
  { screen: '봇 분석 · 최근 요청 표', file: 'admin_analytics_bots.json', find: { id: 'recent_hits_grid' }, source: 'analytics_bots', kind: 'grid', emptyKey: 'admin.analytics.common.empty_title', empty: { data: { recent_hits: [] } }, rows: { data: { recent_hits: [{ id: 1 }, { id: 2 }] } } },
  { screen: '진단 · 제외 사유 표', file: 'admin_analytics_diagnostics.json', find: { id: 'exclusions_grid' }, source: 'analytics_diagnostics', kind: 'grid', emptyKey: 'admin.analytics.diagnostics.exclusions_empty', empty: { data: { collection: { excluded_today: {} } } }, rows: { data: { collection: { excluded_today: { bot: 3, ip: 2 } } } } },
  { screen: '진단 · 보관 실행 표', file: 'admin_analytics_diagnostics.json', find: { id: 'archive_runs_grid' }, source: 'analytics_archive_runs', kind: 'grid', emptyKey: 'admin.analytics.common.empty_title', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '진단 · 수집 실패 표', file: 'admin_analytics_diagnostics.json', find: { id: 'collect_failures_grid' }, source: 'analytics_collect_failures', kind: 'grid', emptyKey: 'admin.analytics.diagnostics.failures_empty', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '실시간 · 방문자 표', file: 'admin_analytics_realtime.json', find: { id: 'realtime_visitors_grid' }, source: 'analytics_realtime', kind: 'grid', emptyKey: 'admin.analytics.realtime.empty', empty: { data: { visitors: [] } }, rows: { data: { visitors: [{ id: 1 }, { id: 2 }] } } },
  { screen: '유입 · 유입 주소 표', file: 'admin_analytics_referrers.json', find: { id: 'referrer_urls_grid' }, source: 'analytics_referrer_urls', kind: 'grid', emptyKey: 'admin.analytics.common.empty_title', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]), hideGridWhenEmpty: true },
  { screen: '방문자 목록 표', file: 'admin_analytics_visitors.json', find: { id: 'analytics_visitors_grid' }, source: 'analytics_visitors', kind: 'grid', emptyKey: 'admin.analytics.common.empty_title', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '리포트 공통 표', file: 'partials/admin_analytics/_partial_report_grid.json', find: { id: 'analytics_report_grid' }, source: 'analytics_report', kind: 'grid', emptyKey: 'admin.analytics.common.empty_title', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]), hideGridWhenEmpty: true },
  { screen: '역할 목록 표', file: 'admin_role_list.json', find: { id: 'roles_data_grid' }, source: 'roles', kind: 'grid', emptyKey: 'common.no_data', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '회원 목록 표', file: 'admin_user_list.json', find: { id: 'users_data_grid' }, source: 'users', kind: 'grid', emptyKey: 'common.no_data', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '활동 로그 표', file: 'partials/admin_activity_log_list/_partial_datagrid.json', find: { id: 'activity_log_datagrid' }, source: 'activityLogs', kind: 'grid', emptyKey: 'common.no_data', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '본인인증 로그 표', file: 'partials/admin_identity_logs/_partial_datagrid.json', find: { id: 'identity_log_datagrid' }, source: 'identityLogs', kind: 'grid', emptyKey: 'common.no_data', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '회원 알림 표', file: 'partials/admin_member_notification_list/_partial_datagrid.json', find: { id: 'member_notification_datagrid' }, source: 'memberNotifications', kind: 'grid', emptyKey: 'admin.member_notification.empty_message', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '알림 발송 로그 표', file: 'partials/admin_notification_log_list/_partial_datagrid.json', find: { id: 'notification_log_datagrid' }, source: 'notificationLogs', kind: 'grid', emptyKey: 'common.no_data', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },
  { screen: '스케줄 목록 표', file: 'partials/admin_schedule_list/_tab_schedules.json', find: { id: 'schedules_data_grid' }, source: 'schedules', kind: 'grid', emptyKey: 'common.no_data', empty: list([]), rows: list([{ id: 1 }, { id: 2 }]) },

  // ── 빈 안내(EmptyState·문단) ──
  { screen: '봇 분석 · 봇별 집계 빈 안내', file: 'admin_analytics_bots.json', find: { name: 'EmptyState', ifIncludes: 'analytics_bots' }, source: 'analytics_bots', kind: 'node', empty: list([]), rows: list([{ id: 1 }]) },
  { screen: '개요 · 추이 빈 안내', file: 'admin_analytics_overview.json', find: { id: 'trend_empty' }, source: 'analytics_overview', kind: 'node', empty: { data: { series: { points: [] } } }, rows: { data: { series: { points: [{ visits: 1 }] } } } },
  { screen: '개요 · 인기 페이지 없음', file: 'admin_analytics_overview.json', find: { ifIncludes: 'top?.pages' }, source: 'analytics_overview', kind: 'node', empty: { data: { top: { pages: [] } } }, rows: { data: { top: { pages: [{ label: '/' }] } } } },
  { screen: '개요 · 인기 유입 없음', file: 'admin_analytics_overview.json', find: { ifIncludes: 'top?.referrers' }, source: 'analytics_overview', kind: 'node', empty: { data: { top: { referrers: [] } } }, rows: { data: { top: { referrers: [{ label: 'a' }] } } } },
  { screen: '개요 · 인기 국가 없음', file: 'admin_analytics_overview.json', find: { ifIncludes: 'top?.countries' }, source: 'analytics_overview', kind: 'node', empty: { data: { top: { countries: [] } } }, rows: { data: { top: { countries: [{ label: 'KR' }] } } } },
  { screen: '패턴 · 요일 빈 안내', file: 'admin_analytics_patterns.json', find: { name: 'EmptyState', ifIncludes: 'weekday' }, source: 'analytics_patterns', kind: 'node', empty: { data: { weekday: [] } }, rows: { data: { weekday: [{ dow: 1 }] } } },
  { screen: '패턴 · 시간대 빈 안내', file: 'admin_analytics_patterns.json', find: { name: 'EmptyState', ifIncludes: 'hour' }, source: 'analytics_patterns', kind: 'node', empty: { data: { hour: [] } }, rows: { data: { hour: [{ hour: 1 }] } } },
  { screen: '개인정보 요청 · 결과 없음', file: 'admin_analytics_privacy_requests.json', find: { id: 'subjects_empty' }, source: 'analytics_subjects', kind: 'node', empty: { data: { visits: [] } }, rows: { data: { visits: [{ visit_id: 1 }] } } },
  { screen: '실시간 · 페이지 없음', file: 'admin_analytics_realtime.json', find: { name: 'P', ifIncludes: 'analytics_realtime' }, source: 'analytics_realtime', kind: 'node', empty: { data: { visitors: [] } }, rows: { data: { visitors: [{ page: '/' }] } } },
  { screen: '유입 · 유입 주소 빈 안내', file: 'admin_analytics_referrers.json', find: { id: 'referrer_urls_empty' }, source: 'analytics_referrer_urls', kind: 'node', empty: list([]), rows: list([{ url: 'a' }]) },
  { screen: '방문자 상세 · 방문 없음', file: 'admin_analytics_visitor_detail.json', find: { id: 'visits_empty' }, source: 'analytics_visitor', kind: 'node', empty: { data: { visits: [] } }, rows: { data: { visits: [{ id: 1 }] } } },
  { screen: '리포트 · 빈 안내', file: 'partials/admin_analytics/_partial_report_grid.json', find: { id: 'report_empty' }, source: 'analytics_report', kind: 'node', empty: list([]), rows: list([{ label: 'a' }]) },
  { screen: '리포트 · 빈 안내의 진단 링크', file: 'partials/admin_analytics/_partial_report_grid.json', find: { id: 'report_empty_actions' }, source: 'analytics_report', kind: 'node', empty: list([]), rows: list([{ label: 'a' }]), initialData: { current_user: { data: { permissions: ['core.analytics.read'] } } } },
  { screen: '통계 설정 · 알림 정의 없음', file: 'partials/admin_analytics/_partial_notification_definitions.json', find: { name: 'P', ifIncludes: 'analytics_notification_definitions' }, source: 'analytics_notification_definitions', kind: 'node', empty: list([]), rows: list([{ hook_prefix: 'core.analytics' }]), initialData: { _computed: { canReadDefinitions: true } } },
  { screen: '행동 · 등록 차원 없음', file: 'admin_analytics_actions.json', find: { id: 'dimensions_none' }, source: 'analytics_registered', kind: 'node', empty: { data: { dimensions: [] } }, rows: { data: { dimensions: [{ key: 'a' }] } }, queryParams: { tab: 'dimensions' } },
  { screen: '대시보드 · 최근 알림 없음', file: 'admin_dashboard.json', find: { id: 'recent_notifications_empty' }, source: 'dashboard_recent_notifications', kind: 'node', empty: { data: [] }, rows: { data: [{ id: 1 }] } },
  { screen: '언어팩 목록 · 없음', file: 'partials/admin_language_pack_list/_content.json', find: { id: 'lp_empty' }, source: 'language_packs', kind: 'node', empty: list([]), rows: list([{ id: 1 }]) },
  { screen: '본인인증 문구 추가 창 · 정책 없음', file: 'partials/admin_settings/_modal_notification_identity_definition_add.json', find: { name: 'P', ifIncludes: 'adminIdentityPolicies', ifExcludes: 'filter' }, source: 'adminIdentityPolicies', kind: 'node', empty: list([]), rows: list([{ key: 'a' }]) },
  { screen: '드라이버 · 큐 워커 없음', file: 'partials/admin_settings/_tab_drivers.json', find: { name: 'P', ifIncludes: 'queueStatus?.data?.rows ?? []).length === 0' }, source: 'queueStatus', kind: 'node', empty: { data: { rows: [] } }, rows: { data: { rows: [{ queue: 'default' }] } } },
  { screen: '본인인증 정책 · 코어 목적 없음', file: 'partials/admin_settings/_tab_identity_policies.json', find: { name: 'Span', ifIncludes: 'identityPurposes' }, source: 'identityPurposes', kind: 'node', empty: { data: [] }, rows: { data: [{ id: 'a', source_type: 'core' }] } },
  { screen: '본인인증 정책 · 정책 없음', file: 'partials/admin_settings/_tab_identity_policies.json', find: { name: 'Div', ifIncludes: 'policies?.data?.data' }, source: 'policies', kind: 'node', empty: list([]), rows: list([{ key: 'a' }]) },
  { screen: '본인인증 제공자 · 목적 없음', file: 'partials/admin_settings/_tab_identity_providers.json', find: { name: 'Div', ifIncludes: 'identityPurposes' }, source: 'identityPurposes', kind: 'node', empty: { data: [] }, rows: { data: [{ id: 'a' }] } },
  { screen: '본인인증 제공자 · 제공자 없음', file: 'partials/admin_settings/_tab_identity_providers.json', find: { name: 'Div', ifIncludes: 'identityProviders' }, source: 'identityProviders', kind: 'node', empty: { data: [] }, rows: { data: [{ id: 'a' }] } },
  { screen: '알림 정의 · 없음', file: 'partials/admin_settings/_tab_notification_definitions.json', find: { id: 'notif_empty' }, source: 'notificationDefinitions', kind: 'node', empty: list([]), rows: list([{ id: 1 }]) },

  // ── 카드 그리드·알림 센터 ──
  { screen: '모듈 목록 카드', file: 'admin_module_list.json', find: { id: 'module_card_grid' }, source: 'modules', kind: 'cardgrid', empty: list([]), rows: list([{ identifier: 'a', name: 'A' }]) },
  { screen: '플러그인 목록 카드', file: 'admin_plugin_list.json', find: { id: 'plugin_card_grid' }, source: 'plugins', kind: 'cardgrid', empty: list([]), rows: list([{ identifier: 'a', name: 'A' }]) },
  { screen: '관리자 템플릿 목록 카드', file: 'partials/admin_template_list/_tab_admin.json', find: { id: 'template_card_grid' }, source: 'templates', kind: 'cardgrid', empty: list([]), rows: list([{ identifier: 'a', name: 'A' }]) },
  { screen: '사용자 템플릿 목록 카드', file: 'partials/admin_template_list/_tab_user.json', find: { id: 'template_card_grid' }, source: 'templates', kind: 'cardgrid', empty: list([]), rows: list([{ identifier: 'a', name: 'A' }]) },
  { screen: '관리자 알림 센터(데스크톱)', file: '_admin_base.json', find: { id: 'notification_center_desktop' }, source: 'notifications', kind: 'notification', empty: list([]), rows: list([{ id: 1, subject: 'a' }]) },
  { screen: '관리자 알림 센터(모바일)', file: '_admin_base.json', find: { id: 'notification_center_mobile' }, source: 'notifications', kind: 'notification', empty: list([]), rows: list([{ id: 1, subject: 'a' }]) },
];

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');
const DEFAULT_EMPTY = '__default_empty__';

/**
 * 트리에서 조건에 맞는 노드와 조상 목록을 모두 찾는다.
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @param ancestors 지금까지의 조상
 * @param out 결과
 * @returns [노드, 조상 목록] 배열
 */
function findAll(node: any, predicate: (n: Node) => boolean, ancestors: Node[] = [], out: Array<[Node, Node[]]> = []): Array<[Node, Node[]]> {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, ancestors, out));
    return out;
  }
  const isComponent = typeof node.name === 'string' && typeof node.type === 'string';
  if (isComponent && predicate(node)) out.push([node, ancestors]);
  const next = isComponent ? [...ancestors, node] : ancestors;
  for (const [key, value] of Object.entries(node)) {
    if (key === 'data_sources' || key === 'props') continue;
    findAll(value, predicate, next, out);
  }
  return out;
}

/**
 * 레이아웃 JSON 을 읽는다.
 *
 * @param file `layouts/` 기준 경로
 * @returns JSON
 */
function readLayout(file: string): Node {
  return JSON.parse(fs.readFileSync(path.join(LAYOUT_ROOT, file), 'utf8'));
}

/**
 * 노드가 표본의 찾기 조건에 맞는지.
 *
 * @param c 표본
 * @param n 노드
 * @returns 맞으면 true
 */
function matchesFind(c: Case, n: Node): boolean {
  return (c.find.id === undefined || n.id === c.find.id)
    && (c.find.name === undefined || n.name === c.find.name)
    && (c.find.ifIncludes === undefined || String(n.if ?? '').includes(c.find.ifIncludes))
    && (c.find.ifExcludes === undefined || !String(n.if ?? '').includes(c.find.ifExcludes));
}

/**
 * 표본 하나의 대상 노드와 가장 가까운 흐림 조상을 꺼낸다.
 *
 * @param c 표본
 * @returns 대상 노드와 흐림 설정
 */
function targetOf(c: Case): { node: Node; blur: unknown } {
  const hits = findAll(readLayout(c.file), (n) => matchesFind(c, n));
  expect(hits, `${c.file} ${JSON.stringify(c.find)}`).toHaveLength(1);
  const [node, ancestors] = hits[0];
  const blurAncestor = [...ancestors].reverse().find((a) => a.blur_until_loaded);
  return { node, blur: node.blur_until_loaded ?? blurAncestor?.blur_until_loaded };
}

/**
 * 점 경로 다국어 키를 중첩 객체로 만든다.
 *
 * @param keys 다국어 키 목록
 * @returns 번역 사전
 */
function translationsFor(keys: string[]): Record<string, any> {
  const root: Record<string, any> = {};
  for (const key of keys) {
    const parts = key.split('.');
    let cursor = root;
    parts.slice(0, -1).forEach((part) => {
      cursor[part] = cursor[part] ?? {};
      cursor = cursor[part];
    });
    cursor[parts[parts.length - 1]] = `빈문구:${key}`;
  }
  return root;
}

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
  registry.register('composite', 'DataGrid', ({ data, emptyMessage }: any) => {
    const rows: any[] = Array.isArray(data) ? data : [];
    return React.createElement(
      'div',
      { 'data-testid': 'grid' },
      rows.length > 0
        ? rows.map((_row, index) => React.createElement('div', { key: index, 'data-testid': 'grid-row' }))
        : React.createElement('div', { 'data-testid': 'grid-empty' }, emptyMessage ?? DEFAULT_EMPTY)
    );
  });
  registry.register('composite', 'EmptyState', () => React.createElement('div', { 'data-testid': 'target' }));
  registry.register('composite', 'ExtensionCardGrid', ({ items, loading }: any) => {
    const hasRows = Array.isArray(items);
    const showSkeleton = !hasRows || (loading && items.length === 0);
    const isEmpty = !showSkeleton && hasRows && items.length === 0;
    if (isEmpty) return React.createElement('div', { 'data-testid': 'target' });
    return React.createElement('div', { 'data-testid': showSkeleton ? 'skeleton' : 'cards' });
  });
  registry.register('composite', 'NotificationCenter', ({ notifications, loading }: any) =>
    (notifications ?? []).length === 0 && !loading
      ? React.createElement('div', { 'data-testid': 'target' })
      : React.createElement('div', { 'data-testid': loading ? 'loading' : 'list' }));
});

interface Rendered {
  /** 대상(빈 안내)이 그려졌는가 — 표는 빈 문구가 비어 있지 않을 때 */
  shown: boolean;
  /** 표가 그려졌는가 */
  gridRendered: boolean;
  /** 표 빈 문구(표가 없거나 행이 있으면 null) */
  emptyText: string | null;
  rowCount: number;
  blurred: boolean;
}

/**
 * 표본 하나를 데이터소스 값과 함께 렌더한다.
 *
 * @param c 표본
 * @param value 데이터소스 값 — `undefined` 는 받는 중, `null` 은 받기 실패(엔진이 넣는 값)
 * @returns 렌더 결과
 */
async function renderCase(c: Case, value: unknown): Promise<Rendered> {
  const { node, blur } = targetOf(c);
  const { meta, ...rest } = node;
  // 문단·블록 안내는 그려졌는지만 본다 — 표지를 달아 찾는다
  const target = c.kind === 'node' && node.type === 'basic'
    ? { ...rest, props: { ...(rest.props ?? {}), 'data-testid': 'target' } }
    : rest;
  // 가장 가까운 흐림 조상을 감싸개로 옮겨 실제 화면의 흐림 판정을 재현한다
  const tree = blur && !node.blur_until_loaded
    ? { id: 'blur_wrapper', type: 'basic', name: 'Div', blur_until_loaded: blur, children: [target] }
    : target;
  const utils = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: `test/admin-loading-empty/${c.source}`,
      ...(c.computed ? { computed: c.computed } : {}),
      components: [tree],
    } as any,
    {
      componentRegistry: registry as any,
      locale: 'ko',
      translations: translationsFor(c.emptyKey ? [c.emptyKey] : []),
      queryParams: c.queryParams,
      initialState: c.globalState ? { _global: c.globalState } : undefined,
      initialData: { ...(c.initialData ?? {}), [c.source]: value, $templateId: 'test-template', $locale: 'ko' },
    }
  );
  try {
    const { container } = await utils.render();
    const grid = container.querySelector('[data-testid="grid"]');
    const empty = container.querySelector('[data-testid="grid-empty"]');
    const emptyText = empty ? (empty.textContent ?? '') : null;
    return {
      shown: c.kind === 'grid' ? emptyText !== null && emptyText !== '' : container.querySelector('[data-testid="target"]') !== null,
      gridRendered: grid !== null,
      emptyText,
      rowCount: container.querySelectorAll('[data-testid="grid-row"]').length,
      blurred: container.querySelector('.blur-sm') !== null,
    };
  } finally {
    utils.cleanup();
  }
}

describe('[case:components-datagrid-empty-message-while-loading] 관리자 목록 — 받는 중에는 「없음」을 말하지 않는다', () => {
  /**
   * 목록을 아직 받지 못했으면(값 `undefined`) 빈 안내를 그리지 않는다. 표는 빈 문구 없이 흐리게 덮인다.
   *
   * @scenario load_state=loading
   * @effects loading_hides_empty_notice, loading_blurs_grid
   */
  it.each(CASES)('$screen: 받는 중에는 빈 안내가 없다', async (c) => {
    const result = await renderCase(c, undefined);

    expect(result.shown).toBe(false);
    if (c.kind === 'grid') {
      expect(result.gridRendered).toBe(true);
      expect(result.emptyText).toBe('');
      expect(result.blurred).toBe(true);
    }
  });

  /**
   * 받은 결과가 0건이면 그 화면의 빈 안내가 보인다. 0건이면 표를 숨기는 화면은 표가 사라진다.
   *
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_notice
   */
  it.each(CASES)('$screen: 받은 뒤 0건이면 빈 안내가 보인다', async (c) => {
    const result = await renderCase(c, c.empty);

    if (c.kind === 'grid' && c.hideGridWhenEmpty) {
      expect(result.gridRendered).toBe(false);
      return;
    }
    expect(result.shown).toBe(true);
    if (c.kind === 'grid') {
      expect(result.emptyText).toBe(`빈문구:${c.emptyKey}`);
      expect(result.blurred).toBe(false);
    }
  });

  /**
   * 받기에 실패하면 엔진이 값을 `null` 로 바꾼다 — 받는 중으로 남지 않고 빈 안내가 보인다.
   *
   * @scenario load_state=error
   * @effects load_failure_releases_notice
   */
  it.each(CASES.filter((c) => !c.hideGridWhenEmpty))('$screen: 받기 실패 뒤에는 받는 중으로 남지 않는다', async (c) => {
    const result = await renderCase(c, null);

    expect(result.shown).toBe(true);
  });

  /**
   * 받은 결과가 있으면 빈 안내가 없다.
   *
   * @scenario load_state=loaded_rows
   * @effects loaded_rows_hide_notice
   */
  it.each(CASES)('$screen: 받은 뒤 행이 있으면 빈 안내가 없다', async (c) => {
    const result = await renderCase(c, c.rows);

    expect(result.shown).toBe(false);
    if (c.kind === 'grid') {
      expect(result.rowCount).toBe(2);
      expect(result.blurred).toBe(false);
    }
  });
});

/**
 * 받은 뒤에 여는 창 — 여는 시퀀스가 `refetch-data-source` 를 기다린 뒤 `open-modal` 한다(핸들러가 refetch 를
 * await). 그래서 창이 열려 있는 동안 「받는 중」은 없다. 열렸는데 값이 `undefined` 이거나 고른 행과 다르면
 * 받기에 실패한 것이다(refetch 실패는 값을 바꾸지 않는다) — 이때 「받는 중」으로 가리면 빈 창만 남는다.
 */
const OPENED_AFTER_FETCH: Case[] = [
  { screen: '행 추이 창 · 기록 없음', file: 'partials/admin_analytics/_modal_row_trend.json', find: { id: 'row_trend_empty' }, source: 'analytics_row_trend', kind: 'node', empty: { data: { label: 'a', report: 'pages', points: [] } }, rows: { data: { label: 'a', report: 'pages', points: [{ visits: 1 }] } }, globalState: { analyticsRowTrend: { label: 'a', report: 'pages' } } },
  { screen: '행 추이 창 · 오류 안내', file: 'partials/admin_analytics/_modal_row_trend.json', find: { id: 'row_trend_error' }, source: 'analytics_row_trend', kind: 'node', empty: { data: { label: 'other', report: 'pages', points: [] } }, rows: { data: { label: 'a', report: 'pages', points: [{ visits: 1 }] } }, globalState: { analyticsRowTrend: { label: 'a', report: 'pages' } } },
  { screen: '개인정보 처리 대상 창 · 없음', file: 'partials/admin_settings/_modal_privacy_targets.json', find: { id: 'privacy_targets_empty' }, source: 'privacyTargets', kind: 'node', empty: list([]), rows: list([{ id: 1 }]) },
];

/** 창을 여는 시퀀스 — [여는 레이아웃, 데이터소스, 창 id] */
const OPENERS: Array<[string, string, string]> = [
  // 행 추이 창은 공통 리포트 표(6화면이 include)와 봇 화면의 행 끝 단추가 연다
  ['partials/admin_analytics/_partial_report_grid.json', 'analytics_row_trend', 'analytics_row_trend_modal'],
  ['admin_analytics_bots.json', 'analytics_row_trend', 'analytics_row_trend_modal'],
  ['partials/admin_settings/_tab_privacy.json', 'privacyTargets', 'privacy_targets_modal'],
  ['partials/admin_settings/_tab_security_password_policy.json', 'privacyTargets', 'privacy_targets_modal'],
];

describe('관리자 목록 — 받은 뒤에 여는 창', () => {
  /**
   * 여는 곳은 모두 「받기 → 열기」 순서다. 이 순서가 바뀌면 아래 판정(열린 창의 undefined = 실패)이 틀린다.
   *
   * @scenario load_state=loading
   * @effects modal_opens_after_fetch
   */
  it.each(OPENERS)('%s: %s 를 받은 뒤에 창을 연다', (file, source, modal) => {
    const sequences: any[][] = [];
    const visit = (n: any): void => {
      if (!n || typeof n !== 'object') return;
      if (Array.isArray(n)) { n.forEach(visit); return; }
      if (n.handler === 'sequence' && Array.isArray(n.actions)
        && n.actions.some((x: any) => x?.handler === 'openModal' && x?.target === modal)) sequences.push(n.actions);
      Object.values(n).forEach(visit);
    };
    visit(readLayout(file));
    expect(sequences.length).toBeGreaterThan(0);
    for (const steps of sequences) {
      const fetchAt = steps.findIndex((x) => x?.handler === 'refetchDataSource' && x?.params?.dataSourceId === source);
      const openAt = steps.findIndex((x) => x?.handler === 'openModal' && x?.target === modal);
      expect(fetchAt).toBeGreaterThanOrEqual(0);
      expect(fetchAt).toBeLessThan(openAt);
    }
  });

  /**
   * 열린 창에서 값이 없으면(받기 실패) 안내를 보인다 — 받는 중으로 가려 빈 창을 남기지 않는다.
   *
   * @scenario load_state=error
   * @effects opened_modal_failure_shows_notice
   */
  it.each(OPENED_AFTER_FETCH.filter((c) => c.screen !== '행 추이 창 · 기록 없음'))('$screen: 열린 뒤 값이 없으면 안내가 보인다', async (c) => {
    expect((await renderCase(c, undefined)).shown).toBe(true);
  });

  /**
   * @scenario load_state=loaded_empty
   * @effects loaded_empty_shows_notice
   */
  it.each(OPENED_AFTER_FETCH)('$screen: 받은 결과가 안내 조건이면 보인다', async (c) => {
    expect((await renderCase(c, c.empty)).shown).toBe(true);
  });

  /**
   * @scenario load_state=loaded_rows
   * @effects loaded_rows_hide_notice
   */
  it.each(OPENED_AFTER_FETCH)('$screen: 받은 행이 있으면 안내가 없다', async (c) => {
    expect((await renderCase(c, c.rows)).shown).toBe(false);
  });
});

describe('관리자 목록 — 전수', () => {
  /**
   * 디렉터리 아래 JSON 레이아웃을 모두 모은다.
   *
   * @param dir 디렉터리
   * @param out 결과
   * @returns 파일 경로 목록
   */
  function walk(dir: string, out: string[] = []): string[] {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full, out);
      else if (entry.name.endsWith('.json')) out.push(full);
    }
    return out;
  }

  const files = walk(LAYOUT_ROOT).map((f) => path.relative(LAYOUT_ROOT, f).split(path.sep).join('/'));

  /** 데이터소스 id → 전략(progressive 기본). 같은 id 가 여러 화면에 있으면 모두 모은다 */
  const strategies = new Map<string, Set<string>>();
  for (const file of files) {
    for (const ds of readLayout(file).data_sources ?? []) {
      if (!strategies.has(ds.id)) strategies.set(ds.id, new Set());
      strategies.get(ds.id)!.add(ds.loading_strategy ?? 'progressive');
    }
  }
  const deferred = new Set([...strategies].filter(([, s]) => [...s].some((v) => v !== 'blocking')).map(([id]) => id));

  /** 표본에 들어 있지 않아도 되는 목록 표·빈 안내 — 받는 중 상태가 화면에 나오지 않는 이유 */
  const EXCLUDED: Array<{ file: string; match: string; reason: string }> = [
    { file: 'admin_analytics_privacy_requests.json', match: 'subjects_grid', reason: '행이 있을 때만 그리는 감싸개 안 — 빈 문구가 나올 수 없다' },
    { file: 'admin_analytics_settings.json', match: 'storage_grid', reason: '행이 있을 때만 그리는 감싸개 안' },
    { file: 'admin_role_form.json', match: 'permType', reason: '받은 권한 유형을 도는 반복 안 — 받기 전에는 반복이 없다' },
    { file: 'admin_analytics_actions.json', match: 'dimensions_empty', reason: '차원이 있을 때 고르라는 안내 — 0건 안내가 아니다' },
    { file: 'partials/admin_language_pack_list/_content.json', match: 'lp_card_grid', reason: '행이 있을 때만 그린다 — 0건·받는 중 안내는 lp_empty 가 맡는다' },
    { file: 'partials/admin_language_pack_list/_modal_detail.json', match: 'changelog_entries', reason: '상세를 받은 뒤에만 그리는 감싸개 안(받는 중에는 「불러오는 중」)' },
    { file: 'partials/admin_settings/_modal_notification_identity_definition_add.json', match: '.filter(p', reason: '정책이 1건 이상일 때만 참 — 받기 전에는 거짓' },
    { file: '_admin_base.json', match: 'admin_sidebar_empty', reason: '응답 성공(success === true)으로 이미 받은 뒤만 가른다' },
  ];

  /**
   * 노드가 받는 중에 영향을 받는 데이터소스를 읽는지 — 조건식·표 데이터·카드 항목에서 식별자를 찾는다.
   *
   * @param text 식
   * @returns 읽는 데이터소스 id
   */
  function deferredSourcesIn(text: string): string[] {
    const ids = new Set<string>();
    for (const m of text.matchAll(/(?<![.\w$])([A-Za-z_]\w*)\s*(?:\?\.|\.)/g)) {
      if (deferred.has(m[1])) ids.add(m[1]);
    }
    return [...ids];
  }

  /**
   * 목록 표·카드 그리드·빈 안내(0건 조건) 가운데 화면이 그려진 뒤 받는 목록에 묶인 것은
   * 모두 표본에 있거나 제외 사유가 적혀 있다. 새 목록이 규칙 없이 들어오면 여기서 드러난다.
   *
   * @scenario load_state=loading
   * @effects every_deferred_list_notice_gated
   */
  it('목록 표·빈 안내는 모두 받는 중 규칙을 지킨다', () => {
    const covered = new Set(CASES.map((c) => `${c.file}#${JSON.stringify(c.find)}`));
    const offenders: string[] = [];
    for (const file of files) {
      for (const [node] of findAll(readLayout(file), (n) => {
        if (['DataGrid', 'ExtensionCardGrid', 'NotificationCenter'].includes(n.name)) return true;
        return typeof n.if === 'string' && /length\s*===?\s*0|\?\?\s*0\)\s*===\s*0/.test(n.if);
      })) {
        if ([...CASES, ...OPENED_AFTER_FETCH].some((c) => c.file === file && matchesFind(c, node))) continue;
        const text = `${node.if ?? ''} ${node.props?.data ?? ''} ${node.props?.items ?? ''} ${node.props?.notifications ?? ''}`;
        if (deferredSourcesIn(text).length === 0) continue;
        const label = `${node.id ?? ''} ${node.if ?? ''}`;
        if (EXCLUDED.some((e) => e.file === file && label.includes(e.match))) continue;
        offenders.push(`${file} ${node.name} ${label.slice(0, 120)}`);
      }
    }
    expect(covered.size).toBe(CASES.length);
    expect(offenders).toEqual([]);
  });

  /**
   * 엔진은 데이터소스 값에 `loading` 을 싣지 않는다 — `소스?.loading` 은 늘 거짓이라
   * 받는 중을 가르지 못한다. 받는 중은 `소스 === undefined` 로 가른다.
   *
   * @scenario load_state=loading
   * @effects no_dead_loading_flag
   */
  it('데이터소스 값의 loading 을 읽는 조건이 없다', () => {
    const offenders: string[] = [];
    for (const file of files) {
      const text = fs.readFileSync(path.join(LAYOUT_ROOT, file), 'utf8');
      for (const m of text.matchAll(/(?<![.\w$])([A-Za-z_]\w*)\??\.(?:loading|error)\b/g)) {
        if (deferred.has(m[1])) offenders.push(`${file}: ${m[0]}`);
      }
    }
    expect(offenders).toEqual([]);
  });
});

describe('편집기 조건 레시피 — 데이터소스 받는 중·비었음', () => {
  const recipes = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../editor-spec/conditionRecipes.json'), 'utf8')).operators as Array<{ value: string; expr: string }>;
  const engine = new DataBindingEngine();

  /**
   * 레시피 식에 데이터소스 id 를 넣어 실제 엔진으로 평가한다.
   *
   * @param value 레시피 값
   * @param ctx 데이터 문맥
   * @returns 평가 결과
   */
  function evaluate(value: string, ctx: Record<string, unknown>): unknown {
    const recipe = recipes.find((r) => r.value === value);
    expect(recipe, value).toBeTruthy();
    return engine.evaluateExpression(recipe!.expr.replace(/\{src\}/g, 'posts'), ctx as any, { skipCache: true });
  }

  /**
   * 「데이터 로딩 중」은 값이 아직 없을 때(undefined)만 참 — 엔진은 데이터소스 값에 loading 을 싣지 않는다.
   * 받기 실패(null)·받은 뒤에는 거짓.
   *
   * @scenario load_state=loading
   * @effects editor_recipe_matches_engine
   */
  it('dsLoading 은 받기 전에만 참이다', () => {
    expect(evaluate('dsLoading', {})).toBe(true);
    expect(evaluate('dsLoading', { posts: null })).toBe(false);
    expect(evaluate('dsLoading', { posts: { data: [] } })).toBe(false);
  });

  /**
   * 「오류일 때」는 엔진이 실패를 적는 `_dataSourceErrors.{src}` 를 읽는다 — 데이터소스 값에는 `error` 가 없다
   * (실패하면 값은 null). 예전 `!!{src}?.error` 는 늘 거짓이었다.
   *
   * @scenario load_state=error
   * @effects editor_recipe_matches_engine
   */
  it('dsError 는 엔진이 실패를 적었을 때만 참이다', () => {
    // 관리자 템플릿은 「오류일 때」 레시피를 두지 않는다 — 생기면 같은 계약으로 검사한다
    if (!recipes.some((r) => r.value === 'dsError')) return;
    expect(evaluate('dsError', { posts: null, _dataSourceErrors: { posts: { message: 'x', status: 500 } } })).toBe(true);
    expect(evaluate('dsError', { posts: null, _dataSourceErrors: { other: { message: 'x' } } })).toBe(false);
    expect(evaluate('dsError', { posts: { data: [] } })).toBe(false);
    expect(evaluate('dsError', {})).toBe(false);
  });

  /**
   * 편집기가 노드의 `if` 를 다시 읽을 때(역파싱) 데이터소스 조건 셋은 자기 자신으로 돌아오고,
   * 범용 조건(값이 undefined·null·참)은 데이터소스 조건으로 바뀌어 보이지 않는다.
   *
   * @scenario load_state=loading
   * @effects editor_recipe_matches_engine
   */
  it('데이터소스 조건 셋은 역파싱이 자기 자신으로 돌아오고 범용 조건과 겹치지 않는다', () => {
    const ops = normalizeConditionRecipes({ operators: recipes } as never);
    for (const value of ['dsLoading', 'dsEmpty', 'dsError', 'dsHasData']) {
      if (!recipes.some((r) => r.value === value)) continue;
      const expr = combineConditions([{ operator: value, params: { src: 'posts' } }], 'and', ops);
      const parsed = parseConditionExpr(expr, ops);
      expect(parsed?.clauses, `${value}: ${expr}`).toEqual([{ operator: value, params: { src: 'posts' } }]);
    }
    const generic: Array<[string, string]> = [
      ['valueUndefined', '{{ _local.v === undefined }}'],
      ['valueIsNull', '{{ _local.v === null }}'],
    ];
    for (const [value, expr] of generic) {
      if (!recipes.some((r) => r.value === value)) continue;
      expect(parseConditionExpr(expr, ops)?.clauses?.[0]?.operator, expr).toBe(value);
    }
  });

  /**
   * 「데이터 비었음」은 받은 뒤 0건일 때만 참 — 받기 전에 참이면 그 레시피로 만든 빈 안내가 받는 동안 먼저 보인다.
   *
   * @scenario load_state=loaded_empty
   * @effects editor_recipe_matches_engine
   */
  it('dsEmpty 는 받은 뒤 0건일 때만 참이다', () => {
    expect(evaluate('dsEmpty', {})).toBe(false);
    expect(evaluate('dsEmpty', { posts: { data: [] } })).toBe(true);
    expect(evaluate('dsEmpty', { posts: { data: [1] } })).toBe(false);
  });
});
