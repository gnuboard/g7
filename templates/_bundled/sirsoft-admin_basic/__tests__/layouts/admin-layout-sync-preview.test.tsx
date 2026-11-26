/**
 * @file admin-layout-sync-preview.test.tsx
 * @description 레이아웃 갱신·업데이트 창의 「화면별 처리 미리보기」
 *
 * 창을 열면 미리보기 API 가 화면별 판정을 돌려주고, 공통 조각(_shared/_layout_sync_preview)이 판정 표·처리 방식
 * (기본 수정 유지)·충돌 화면의 결정(운영자 판 유지 / 새 판 적용)·지워질 선언 항목을 그린다. 미리보기가 실패하면
 * 오류와 다시 시도 버튼을 보이고, 6개 창의 적용 버튼은 미리보기가 준비되기 전에는 잠긴다.
 * 업데이트 미리보기가 화면 판정을 건너뛰면(꺼 둔 확장·파일이 없는 설치본 — layouts_skipped) 0개 요약 대신 그 까닭을 알린다.
 * 업데이트 창은 레이아웃 갱신 창처럼 창부터 열고(로딩 표시) 미리보기·변경 이력을 뒤이어 받는다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { createLayoutTest, screen, fireEvent, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

type Json = Record<string, any>;

const LAYOUTS = resolve(__dirname, '../../layouts');

const TestDiv: React.FC<any> = ({ className, children, role }) => <div className={className} role={role}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestLabel: React.FC<any> = ({ className, children }) => <label className={className}>{children}</label>;
const TestInput: React.FC<any> = ({ type, name, value, checked, disabled, onChange }) => (
  <input type={type} name={name} value={value} checked={!!checked} disabled={!!disabled} onChange={onChange ?? (() => undefined)} />
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;
const TestButton: React.FC<any> = ({ className, children, onClick, type }) => (
  <button type={type} className={className} onClick={onClick}>{children}</button>
);

/**
 * 테스트용 컴포넌트를 등록합니다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const admin = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/ko/admin.json'), 'utf-8'));
const translations = { admin: { layout_sync_preview: admin.layout_sync_preview } };
const adminEn = JSON.parse(readFileSync(resolve(__dirname, '../../lang/partial/en/admin.json'), 'utf-8'));
const translationsEn = { admin: { layout_sync_preview: adminEn.layout_sync_preview } };

const ENTRIES = [
  { key: 'layout:tpl:home', kind: 'layout', template: 'tpl', name: 'home', verdict: 'merged', conflicts: [], will_delete: false },
  { key: 'layout:tpl:list', kind: 'layout', template: 'tpl', name: 'list', verdict: 'conflict', conflicts: [{ unit: 'part_a', reason: 'both' }], will_delete: false },
  { key: 'layout:tpl:old', kind: 'layout', template: 'tpl', name: 'old', verdict: 'deleted', conflicts: [], will_delete: true },
  { key: 'layout:tpl:same', kind: 'layout', template: 'tpl', name: 'same', verdict: 'unchanged', conflicts: [], will_delete: false },
];

/**
 * 공통 미리보기 조각을 전역 상태와 함께 레이아웃으로 감쌉니다.
 *
 * @param initGlobal 전역 상태
 * @returns 레이아웃 JSON
 */
function buildLayout(initGlobal: Json): Json {
  const { meta, ...partial } = JSON.parse(readFileSync(join(LAYOUTS, 'partials/_shared/_layout_sync_preview.json'), 'utf-8'));
  void meta; // 조각 표식(meta.is_partial)은 페이지에 들어가면 사라진다 — 본문만 그린다.
  return { version: '1.0.0', layout_name: 'layout_sync_preview_test', initGlobal, components: [partial] };
}

/**
 * 레이아웃 JSON 을 읽습니다.
 *
 * @param rel layouts 기준 상대 경로
 * @returns JSON 문자열
 */
function raw(rel: string): string {
  return readFileSync(join(LAYOUTS, rel), 'utf-8');
}

describe('화면별 처리 미리보기 조각', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  /**
   * @scenario case=loading
   * @effects preview_shows_loading
   */
  it('불러오는 중이면 로딩 문구만 보이고 표는 없다', async () => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'loading' }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    expect(screen.getByText(admin.layout_sync_preview.loading)).toBeTruthy();
    expect(screen.queryByText('home')).toBeNull();
    t.cleanup();
  });

  /**
   * @scenario case=error
   * @effects preview_error_offers_retry
   */
  it('실패하면 오류 문구·서버 메시지·다시 시도 버튼을 보인다', async () => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'error', layoutPreviewError: 'boom' }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    expect(screen.getByText(admin.layout_sync_preview.error)).toBeTruthy();
    expect(screen.getByText('boom')).toBeTruthy();
    expect(screen.getByText(admin.layout_sync_preview.retry)).toBeTruthy();
    t.cleanup();
  });

  /**
   * @scenario case=ready_keep
   * @effects preview_table_lists_changed_screens, conflict_rows_offer_decision
   */
  it('keep 이면 바뀌는 화면만 표에 오르고 병합·충돌 화면에 결정 선택지가 붙는다', async () => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: {}, layoutPreview: { entries: ENTRIES, conflict_screens: ['tpl · list'] } }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    expect(screen.getByText('home')).toBeTruthy();
    expect(screen.getByText('list')).toBeTruthy();
    expect(screen.getByText('old')).toBeTruthy();
    expect(screen.queryByText('same'), '바뀌지 않는 화면은 표에 오르지 않는다').toBeNull();
    expect(screen.getAllByText(admin.layout_sync_preview.decision_take_theirs)).toHaveLength(2);
    expect(screen.getByText(admin.layout_sync_preview.verdict.deleted)).toBeTruthy();
    const keepRadio = document.querySelector('input[name="layout_sync_strategy"][value="keep"]') as HTMLInputElement;
    expect(keepRadio.checked).toBe(true);
    t.cleanup();
  });

  /**
   * @scenario case=ready_decision
   * @effects decision_choice_updates_state
   */
  it('새 판 적용을 고르면 그 화면의 결정이 전역 상태에 담긴다', async () => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: {}, layoutPreview: { entries: ENTRIES, conflict_screens: [] } }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    const radio = document.querySelector('input[name="layout_decision_layout:tpl:list"][value="take-theirs"]') as HTMLInputElement;
    fireEvent.click(radio);
    fireEvent.change(radio, { target: { checked: true } });
    await waitFor(() => {
      expect(t.getState()._global.layoutDecisions).toEqual({ 'layout:tpl:list': 'take-theirs' });
    });
    t.cleanup();
  });

  /**
   * 미리보기 요청 본문과 6개 창 적용 요청이 싣는 결정 값을 전역 상태로 평가합니다.
   *
   * @param global 전역 상태
   * @returns [미리보기 요청 본문의 decisions, 업데이트 적용 요청의 decisions]
   */
  function requestDecisions(global: Json): [unknown, unknown] {
    const fetcher = raw('admin_template_list.json').match(/"body": "(\{\{Object\.assign\(\{\}, _global\.layoutPreviewRequest[^"]+\}\})"/);
    const apply = raw('partials/admin_template_list/_modal_update.json').match(/"decisions": "(\{\{_global\.layoutDecisions[^"]+\}\})"/);
    expect(fetcher, '미리보기 요청 본문 바인딩').not.toBeNull();
    expect(apply, '업데이트 적용 요청의 결정 바인딩').not.toBeNull();
    // eslint-disable-next-line no-new-func
    const run = (binding: string) => new Function('_global', `return (${binding.slice(2, -2)});`)(global);
    return [(run(fetcher![1]) as Json).decisions, run(apply![1])];
  }

  /**
   * @scenario case=ready_decision
   * @effects decision_default_is_server_merge
   */
  it('결정이 없으면 「겹치지 않는 곳만 반영」이 골라져 있고 요청은 그 화면의 결정을 싣지 않는다', async () => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: {}, layoutPreview: { entries: ENTRIES, conflict_screens: [] } }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    const radio = (value: string) => document.querySelector(`input[name="layout_decision_layout:tpl:list"][value="${value}"]`) as HTMLInputElement;
    expect(radio('merge'), '서버 기본(겹치지 않는 곳만 반영) 선택지가 있다').not.toBeNull();
    expect(radio('merge').checked, '결정이 없으면 서버 기본 병합이 골라져 보인다').toBe(true);
    expect(radio('keep-ours').checked, '결정이 없는데 「운영자 판 유지」로 보이지 않는다').toBe(false);
    expect(radio('take-theirs').checked).toBe(false);
    expect(screen.getAllByText(admin.layout_sync_preview.decision_merge)).toHaveLength(2);
    const [preview, apply] = requestDecisions(t.getState()._global);
    expect(preview).toEqual({});
    expect(apply).toEqual({});
    t.cleanup();
  });
  /**
   * @scenario case=ready_decision
   * @effects decision_choice_updates_state
   */
  it.each(['keep-ours', 'take-theirs'])('%s 를 고르면 그 값이 요청에 그대로 실린다', async (value) => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: { 'layout:tpl:home': 'take-theirs' }, layoutPreview: { entries: ENTRIES, conflict_screens: [] } }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    const radio = (v: string) => document.querySelector(`input[name="layout_decision_layout:tpl:list"][value="${v}"]`) as HTMLInputElement;
    fireEvent.click(radio(value));
    fireEvent.change(radio(value), { target: { checked: true } });
    await waitFor(() => {
      expect(t.getState()._global.layoutDecisions).toEqual({ 'layout:tpl:home': 'take-theirs', 'layout:tpl:list': value });
    });
    await waitFor(() => {
      expect(radio(value).checked).toBe(true);
      expect(radio('merge').checked).toBe(false);
    });
    expect(requestDecisions(t.getState()._global)).toEqual([
      { 'layout:tpl:home': 'take-theirs', 'layout:tpl:list': value },
      { 'layout:tpl:home': 'take-theirs', 'layout:tpl:list': value },
    ]);
    t.cleanup();
  });

  /**
   * @scenario case=ready_decision
   * @effects decision_default_is_server_merge
   */
  it.each(['keep-ours', 'take-theirs'])('%s 였던 화면을 「겹치지 않는 곳만 반영」으로 바꾸면 그 화면의 결정만 요청에서 빠진다', async (value) => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: { 'layout:tpl:home': 'take-theirs', 'layout:tpl:list': value }, layoutPreview: { entries: ENTRIES, conflict_screens: [] } }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    const radio = (v: string) => document.querySelector(`input[name="layout_decision_layout:tpl:list"][value="${v}"]`) as HTMLInputElement;
    expect(radio(value).checked).toBe(true);
    expect(radio('merge').checked).toBe(false);
    fireEvent.click(radio('merge'));
    fireEvent.change(radio('merge'), { target: { checked: true } });
    await waitFor(() => {
      expect(t.getState()._global.layoutDecisions, '깊은 병합이 지운 키를 되살리지 않는다').toEqual({ 'layout:tpl:home': 'take-theirs' });
    });
    expect(requestDecisions(t.getState()._global)).toEqual([{ 'layout:tpl:home': 'take-theirs' }, { 'layout:tpl:home': 'take-theirs' }]);
    t.cleanup();
  });

  /**
   * @scenario case=ready_overwrite
   * @effects overwrite_shows_warning_and_hides_decisions
   */
  it('모두 교체면 경고가 보이고 결정 선택지는 사라진다', async () => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'ready', layoutSyncStrategy: 'overwrite', layoutDecisions: {}, layoutPreview: { entries: ENTRIES, conflict_screens: [] } }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    expect(screen.getByText(admin.layout_sync_preview.overwrite_warning)).toBeTruthy();
    expect(screen.queryByText(admin.layout_sync_preview.decision_take_theirs)).toBeNull();
    t.cleanup();
  });

  /**
   * @scenario case=ready_update
   * @effects update_preview_lists_declarative_deletions
   */
  it('업데이트 미리보기의 지워질 선언 항목과 운영자 수정본 표시를 그린다', async () => {
    const t = createLayoutTest(buildLayout({
      layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: {},
      layoutPreview: { entries: [], conflict_screens: [] },
      layoutUpdatePreview: { declarative_deletions: [{ type: 'notification_definition', key: 'order_completed', has_operator_overrides: true }] },
    }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    expect(screen.getByText('order_completed')).toBeTruthy();
    expect(screen.getByText(admin.layout_sync_preview.deletion_operator)).toBeTruthy();
    expect(screen.getByText(admin.layout_sync_preview.empty)).toBeTruthy();
    t.cleanup();
  });

  /**
   * @scenario case=ready_update
   * @effects update_preview_skipped_shows_notice, update_preview_lists_declarative_deletions
   */
  it.each([
    ['inactive', 'skipped_inactive'],
    ['files_missing', 'skipped_files_missing'],
  ])('업데이트 미리보기가 화면 판정을 건너뛰면(%s) 0개 요약·「바뀌는 화면 없음」 대신 까닭을 알리고 지워질 항목은 그린다', async (skipped, key) => {
    const t = createLayoutTest(buildLayout({
      layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: {},
      layoutPreview: { entries: [], conflict_screens: [] },
      layoutUpdatePreview: { layouts_skipped: skipped, pending_token: null, declarative_deletions: [{ type: 'menu', key: 'old_menu', has_operator_overrides: false }] },
    }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    expect(screen.getByText(admin.layout_sync_preview[key])).toBeTruthy();
    expect(screen.queryByText(admin.layout_sync_preview.empty), '판정을 건너뛰었는데 「바뀌는 화면이 없습니다」라고 단언하지 않는다').toBeNull();
    expect(screen.queryByText('바뀌는 화면 0개'), '건너뛴 판정의 0개 요약을 보이지 않는다').toBeNull();
    expect(screen.getByText('old_menu')).toBeTruthy();
    t.cleanup();
  });

  /**
   * @scenario case=ready_keep
   * @effects preview_summary_pluralizes_each_count
   */
  it('요약의 세 수는 수마다 단수·복수를 고른다 (en)', async () => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: {}, layoutPreview: { entries: ENTRIES, conflict_screens: ['tpl · list'] } }), { translations: translationsEn, locale: 'en', componentRegistry: registry });
    await t.render();
    // 바뀌는 화면 3개(merged·conflict·deleted), 겹친 화면 1개, 삭제 예정 1개
    expect(screen.getByText('3 screens change')).toBeTruthy();
    expect(screen.getByText('1 overlaps operator edits')).toBeTruthy();
    expect(screen.getByText('1 will be deleted')).toBeTruthy();
    // 화면별 겹침 수도 단수 꼴이다
    expect(screen.getByText('1 overlap')).toBeTruthy();
    t.cleanup();
  });

  /**
   * @scenario case=ready_keep
   * @effects preview_summary_pluralizes_each_count
   */
  it('요약은 ko 에서 세 조각이 각자 수를 받는다', async () => {
    const t = createLayoutTest(buildLayout({ layoutPreviewState: 'ready', layoutSyncStrategy: 'keep', layoutDecisions: {}, layoutPreview: { entries: ENTRIES, conflict_screens: ['tpl · list'] } }), { translations, locale: 'ko', componentRegistry: registry });
    await t.render();
    expect(screen.getByText('바뀌는 화면 3개')).toBeTruthy();
    expect(screen.getByText('운영자 수정과 겹친 화면 1개')).toBeTruthy();
    expect(screen.getByText('삭제 예정 1개')).toBeTruthy();
    t.cleanup();
  });
});

describe('6개 창의 미리보기 연결', () => {
  const modals = [
    ['partials/admin_template_list/_modal_refresh_layouts.json', 'template_refresh_layouts_apply'],
    ['partials/admin_module_list/_modal_refresh_layouts.json', 'module_refresh_layouts_apply'],
    ['partials/admin_plugin_list/_modal_refresh_layouts.json', 'plugin_refresh_layouts_apply'],
    ['partials/admin_template_list/_modal_update.json', null],
    ['partials/admin_module_list/_modal_update.json', null],
    ['partials/admin_plugin_list/_modal_update.json', null],
  ] as const;

  /**
   * @scenario case=modals
   * @effects modals_include_shared_preview, apply_waits_for_preview
   */
  it.each(modals)('%s — 공통 조각을 품고, 적용은 미리보기 뒤에만, 요청은 처리 방식·결정을 싣는다', (rel) => {
    const json = raw(rel);
    expect(json).toContain('"partial": "partials/_shared/_layout_sync_preview.json"');
    expect(json).toContain("_global.layoutPreviewState !== 'ready'");
    expect(json).toContain("_global.layoutSyncStrategy ?? 'keep'");
    expect(json).toContain('_global.layoutDecisions ?? {}');
  });

  /**
   * @scenario case=openers
   * @effects openers_fetch_preview
   */
  it.each([
    'admin_module_list.json',
    'admin_plugin_list.json',
    'partials/admin_template_list/_tab_admin.json',
    'partials/admin_template_list/_tab_user.json',
  ])('%s — 갱신·업데이트 창을 열 때 미리보기를 부른다', (rel) => {
    const json = raw(rel);
    expect(json).toContain('refresh-layouts/preview');
    expect(json).toContain('/update/preview');
    expect((json.match(/"fetchLayoutPreview"/g) ?? []).length).toBeGreaterThanOrEqual(2);
  });

  /**
   * @scenario case=update_body
   * @effects update_reuses_pending_download
   */
  it.each(['module', 'plugin', 'template'])('%s 업데이트 요청이 미리보기의 내려받은 새 판 토큰을 싣는다', (l) => {
    expect(raw(`partials/admin_${l}_list/_modal_update.json`)).toContain('_global.layoutUpdatePreview?.pending_token');
  });

  /**
   * 레이아웃 JSON 트리에서 조건에 맞는 노드를 모두 모읍니다.
   *
   * @param node 트리
   * @param match 조건
   * @param out 모은 노드
   * @returns 모은 노드
   */
  function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
    if (Array.isArray(node)) {
      node.forEach((child) => collect(child, match, out));
      return out;
    }
    if (node && typeof node === 'object') {
      if (match(node)) out.push(node);
      Object.values(node).forEach((v) => collect(v, match, out));
    }
    return out;
  }

  /**
   * 바인딩 식(`{{…}}`)을 response 로 평가합니다.
   *
   * @param binding 바인딩 문자열
   * @param response 응답
   * @returns 평가 결과
   */
  function evaluate(binding: string, response: Json): unknown {
    // eslint-disable-next-line no-new-func
    return new Function('response', `return (${binding.slice(2, -2)});`)(response);
  }

  /**
   * @scenario case=openers
   * @effects update_preview_keeps_deletions_when_layouts_skipped
   */
  it.each(['admin_module_list.json', 'admin_plugin_list.json', 'admin_template_list.json'])('%s — 미리보기 응답 종류는 declarative_deletions 로 가른다(판정을 건너뛴 업데이트도 지워질 항목·토큰을 지킨다)', (rel) => {
    const success = collect(JSON.parse(raw(rel)), (n) => n.handler === 'setState' && typeof n.params?.layoutUpdatePreview === 'string')
      .map((n) => n.params);
    expect(success).toHaveLength(1);
    const { layoutPreview, layoutUpdatePreview } = success[0];

    // 업데이트 — 판정을 건너뛰어도(layouts_skipped) 응답 전체를 업데이트 미리보기로 담는다
    const skipped = { data: { layouts_skipped: 'inactive', pending_token: 'tok', layouts: { entries: [], conflict_screens: [] }, declarative_deletions: [{ type: 'menu', key: 'm' }] } };
    expect(evaluate(layoutUpdatePreview, skipped)).toEqual(skipped.data);
    expect(evaluate(layoutPreview, skipped)).toEqual(skipped.data.layouts);
    // 업데이트 응답에 layouts 가 없어도 지워질 항목·토큰은 남는다
    const noLayouts = { data: { pending_token: 'tok', declarative_deletions: [{ type: 'menu', key: 'm' }] } };
    expect(evaluate(layoutUpdatePreview, noLayouts)).toEqual(noLayouts.data);
    expect(evaluate(layoutPreview, noLayouts)).toBeNull();
    // 레이아웃 갱신 — 응답이 곧 판정이고 업데이트 미리보기는 없다
    const refresh = { data: { entries: [{ key: 'k' }], conflict_screens: [] } };
    expect(evaluate(layoutPreview, refresh)).toEqual(refresh.data);
    expect(evaluate(layoutUpdatePreview, refresh)).toBeNull();
  });

  /**
   * @scenario case=openers
   * @effects update_opens_modal_before_preview
   */
  it.each([
    ['admin_module_list.json', 'module_update_modal', 1],
    ['admin_plugin_list.json', 'plugin_update_modal', 1],
    ['partials/admin_template_list/_tab_admin.json', 'template_update_modal', 2],
    ['partials/admin_template_list/_tab_user.json', 'template_update_modal', 2],
  ] as const)('%s — 업데이트 창은 갱신 창처럼 먼저 열고(로딩) 변경 이력·미리보기를 뒤이어 받는다', (rel, modal, count) => {
    const tree = JSON.parse(raw(rel));
    const openers = collect(tree, (n) => n.handler === 'sequence' && Array.isArray(n.actions)
      && String(n.actions[0]?.params?.layoutPreviewRequest ?? '').includes('/update/preview'));
    expect(openers).toHaveLength(count);
    for (const seq of openers) {
      const steps = seq.actions.map((a: any) => a.actionRef ?? a.handler);
      expect(steps).toEqual(['setState', 'openModal', 'apiCall', 'fetchLayoutPreview']);
      expect(seq.actions[0].params.layoutPreviewState).toBe('loading');
      expect(seq.actions[1].target).toBe(modal);
      expect(seq.actions[2].target).toContain('/changelog');
      // 창은 이미 열렸다 — 변경 이력 응답이 창을 다시 열지 않는다
      expect(collect(seq.actions[2], (n) => n.handler === 'openModal')).toHaveLength(0);
    }
    // 판정은 미리보기가 한다 — 옛 수정 레이아웃 확인 호출은 없다
    expect(raw(rel)).not.toContain('/check-modified-layouts');
  });
});
