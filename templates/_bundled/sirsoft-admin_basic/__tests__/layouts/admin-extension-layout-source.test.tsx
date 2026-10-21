/**
 * @file admin-extension-layout-source.test.tsx
 * @description 확장 설치·업데이트·레이아웃 갱신 창 9개의 「고급 설정 — 레이아웃 설치 소스」 (#144 D10·D11)
 *
 * 템플릿·모듈·플러그인 × 설치·업데이트·레이아웃 갱신 창마다 기본 접힌 '고급 설정' 안에 자동(권장)/원본 JSON 우선 라디오를
 * 둔다. 창을 열 때마다 자동·접힘으로 되돌아가고, 고른 값은 요청 본문 `layout_source` 로 나간다.
 *
 * - 렌더: 실제로 구운 창 JSON 의 고급 설정 묶음을 그려 접힘 → 펼침(aria-expanded), 기본 auto 체크, json 선택 시 전역값을 잰다.
 * - 구조: 요청 본문·창을 여는 지점의 초기화·성공 뒤 초기화가 9창 모두에 있는지 잰다 — 한 곳이라도 빠지면 그 창만
 *   직전 선택(json)이 다음 창으로 새거나 서버에 값이 가지 않는다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen, fireEvent, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import tabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import tabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import templateInstall from '../../layouts/partials/admin_template_list/_modal_install.json';
import templateUpdate from '../../layouts/partials/admin_template_list/_modal_update.json';
import templateRefresh from '../../layouts/partials/admin_template_list/_modal_refresh_layouts.json';
import moduleInstall from '../../layouts/partials/admin_module_list/_modal_install.json';
import moduleUpdate from '../../layouts/partials/admin_module_list/_modal_update.json';
import moduleRefresh from '../../layouts/partials/admin_module_list/_modal_refresh_layouts.json';
import pluginInstall from '../../layouts/partials/admin_plugin_list/_modal_install.json';
import pluginUpdate from '../../layouts/partials/admin_plugin_list/_modal_update.json';
import pluginRefresh from '../../layouts/partials/admin_plugin_list/_modal_refresh_layouts.json';

type Json = Record<string, any>;
type Kind = 'template' | 'module' | 'plugin';
type Action = 'install' | 'update' | 'refresh_layouts';

const MODALS: Array<{ kind: Kind; action: Action; modal: Json }> = [
  { kind: 'template', action: 'install', modal: templateInstall },
  { kind: 'template', action: 'update', modal: templateUpdate },
  { kind: 'template', action: 'refresh_layouts', modal: templateRefresh },
  { kind: 'module', action: 'install', modal: moduleInstall },
  { kind: 'module', action: 'update', modal: moduleUpdate },
  { kind: 'module', action: 'refresh_layouts', modal: moduleRefresh },
  { kind: 'plugin', action: 'install', modal: pluginInstall },
  { kind: 'plugin', action: 'update', modal: pluginUpdate },
  { kind: 'plugin', action: 'refresh_layouts', modal: pluginRefresh },
];

const GROUP: Record<Kind, string> = { template: 'templates', module: 'modules', plugin: 'plugins' };
const SELECTED: Record<Kind, string> = { template: 'selectedTemplate', module: 'selectedModule', plugin: 'selectedPlugin' };

/** 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(tree: unknown, pred: (n: Json) => boolean, out: Json[] = []): Json[] {
  if (Array.isArray(tree)) {
    tree.forEach((child) => collect(child, pred, out));
  } else if (tree && typeof tree === 'object') {
    const node = tree as Json;
    if (pred(node)) out.push(node);
    Object.values(node).forEach((value) => collect(value, pred, out));
  }
  return out;
}

/** setState 액션의 params 를 모은다. */
function setStateParams(tree: unknown): Json[] {
  return collect(tree, (n) => n.handler === 'setState' && n.params && typeof n.params === 'object').map((n) => n.params);
}

// ==============================
// 렌더용 컴포넌트
// ==============================

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestButton: React.FC<any> = ({ children, onClick, type, className, 'aria-expanded': expanded, 'data-testid': testId }) => (
  <button type={type} className={className} onClick={onClick} aria-expanded={expanded} data-testid={testId}>{children}</button>
);
const TestSpan: React.FC<any> = ({ children, text }) => <span>{children || text}</span>;
const TestP: React.FC<any> = ({ children, text }) => <p>{children || text}</p>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestLabel: React.FC<any> = ({ children }) => <label>{children}</label>;
const TestInput: React.FC<any> = ({ type, name, value, checked, onChange }) => (
  <input type={type} name={name} value={value} checked={!!checked} onChange={onChange ?? (() => undefined)} data-testid={`radio-${value}`} />
);

/** 테스트 레지스트리를 설정한다. */
function setupRegistry(): void {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
}

/**
 * 창 JSON 에서 고급 설정 묶음 노드를 꺼내 렌더 가능한 레이아웃으로 감싼다.
 *
 * 테스트 하네스는 액션이 바꾼 전역값으로 다시 그리지 않으므로, 화면 모양은 초기 전역 상태(`global_state`)별로 그리고
 * 클릭 결과는 `getState()._global` 로 잰다.
 */
function sectionLayout(kind: Kind, action: Action, modal: Json, globalState: Json = {}): Json {
  const [section] = collect(modal, (n) => n.id === `${kind}_${action}_layout_source_section`);
  const copy = JSON.parse(JSON.stringify(section));
  const toggle = collect(copy, (n) => n.id === `${kind}_${action}_layout_source_toggle`)[0];
  toggle.props = { ...toggle.props, 'data-testid': 'layout-source-toggle' };

  return {
    version: '1.0.0',
    layout_name: `test_${kind}_${action}_layout_source`,
    global_state: globalState,
    components: [copy],
  };
}

describe('고급 설정 — 레이아웃 설치 소스 (렌더)', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | undefined;

  beforeEach(() => {
    setupRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
  });

  /**
   * @scenario case=advanced_collapsed_by_default
   * @effects advanced_section_collapsed_then_expands_with_auto_checked
   */
  it.each(MODALS)('$kind $action 창: 기본 접힘이고 토글을 누르면 펼침 상태가 된다', async ({ kind, action, modal }) => {
    testUtils = createLayoutTest(sectionLayout(kind, action, modal));
    await testUtils.render();

    const toggle = await screen.findByTestId('layout-source-toggle');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByTestId('radio-auto')).toBeNull();

    fireEvent.click(toggle);

    await waitFor(() => expect(testUtils!.getState()._global?.[`${kind}LayoutSourceOpen`]).toBe(true));
  });

  /**
   * @scenario case=advanced_collapsed_by_default
   * @effects advanced_section_collapsed_then_expands_with_auto_checked
   */
  it.each(MODALS)('$kind $action 창: 펼친 상태에서 라디오 2개 중 auto 가 기본 체크', async ({ kind, action, modal }) => {
    testUtils = createLayoutTest(sectionLayout(kind, action, modal, { [`${kind}LayoutSourceOpen`]: true }));
    await testUtils.render();

    const toggle = await screen.findByTestId('layout-source-toggle');
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect((screen.getByTestId('radio-auto') as HTMLInputElement).checked).toBe(true);
    expect((screen.getByTestId('radio-json') as HTMLInputElement).checked).toBe(false);
  });

  /**
   * @scenario case=json_selection_updates_state
   * @effects json_selection_stored_in_global_state
   */
  it.each(MODALS)('$kind $action 창: 원본 JSON 우선을 고르면 전역값이 json 이고 그 값으로 다시 그리면 json 체크', async ({ kind, action, modal }) => {
    testUtils = createLayoutTest(sectionLayout(kind, action, modal, { [`${kind}LayoutSourceOpen`]: true }));
    await testUtils.render();

    fireEvent.click(await screen.findByTestId('radio-json'));
    await waitFor(() => expect(testUtils!.getState()._global?.[`${kind}LayoutSource`]).toBe('json'));
    testUtils.cleanup();

    testUtils = createLayoutTest(sectionLayout(kind, action, modal, { [`${kind}LayoutSourceOpen`]: true, [`${kind}LayoutSource`]: 'json' }));
    await testUtils.render();
    expect((await screen.findByTestId('radio-json') as HTMLInputElement).checked).toBe(true);
    expect((screen.getByTestId('radio-auto') as HTMLInputElement).checked).toBe(false);
  });
});

describe('고급 설정 — 레이아웃 설치 소스 (구조)', () => {
  /**
   * @scenario case=request_body_carries_layout_source
   * @effects layout_source_sent_in_request_body
   */
  it.each(MODALS)('$kind $action 창: 요청 본문에 layout_source(기본 auto)를 싣는다', ({ kind, modal }) => {
    const calls = collect(modal, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && n.target.startsWith('/api/admin/'));
    expect(calls.length).toBe(1);
    expect(calls[0].params?.body?.layout_source).toBe(`{{_global.${kind}LayoutSource ?? 'auto'}}`);
  });

  /**
   * @scenario case=reset_after_success
   * @effects layout_source_reset_on_reopen_and_after_success
   */
  it.each(MODALS)('$kind $action 창: 성공 뒤 대상과 함께 auto·접힘으로 되돌린다', ({ kind, modal }) => {
    const resets = setStateParams(modal).filter((p) => p[SELECTED[kind]] === null);
    expect(resets.length).toBe(1);
    expect(resets[0][`${kind}LayoutSource`]).toBe('auto');
    expect(resets[0][`${kind}LayoutSourceOpen`]).toBe(false);
  });

  /**
   * @scenario case=reset_on_open
   * @effects layout_source_reset_on_reopen_and_after_success
   */
  it.each([
    { kind: 'module' as Kind, layout: moduleList, expected: 3 },
    { kind: 'plugin' as Kind, layout: pluginList, expected: 3 },
    { kind: 'template' as Kind, layout: tabAdmin, expected: 4 },
    { kind: 'template' as Kind, layout: tabUser, expected: 4 },
  ])('$kind 목록: 창을 여는 모든 지점이 auto·접힘으로 초기화한다 ($expected곳)', ({ kind, layout, expected }) => {
    const openers = setStateParams(layout).filter((p) => SELECTED[kind] in p && typeof p[SELECTED[kind]] === 'string' && `${kind}LayoutSource` in p);
    expect(openers.length).toBe(expected);
    openers.forEach((p) => {
      expect(p[`${kind}LayoutSource`]).toBe('auto');
      expect(p[`${kind}LayoutSourceOpen`]).toBe(false);
    });

    // 설치·업데이트·갱신 창을 여는 시드 가운데 초기화가 빠진 것이 없다
    const allOpeners = setStateParams(layout).filter(
      (p) => typeof p[SELECTED[kind]] === 'string' && /\$args\[[01]\]/.test(p[SELECTED[kind]]) && (`${kind}LayoutStrategy` in p || Object.keys(p).length > 0),
    );
    expect(allOpeners.length).toBeGreaterThanOrEqual(expected);
  });

  /**
   * @scenario case=labels_translated
   * @effects layout_source_labels_use_group_keys
   */
  it.each(MODALS)('$kind $action 창: 문구는 그 확장 종류의 다국어 키를 쓴다', ({ kind, modal }) => {
    const text = JSON.stringify(modal);
    for (const key of ['layout_source_advanced', 'layout_source_title', 'layout_source_help', 'layout_source_auto', 'layout_source_auto_desc', 'layout_source_json', 'layout_source_json_desc']) {
      expect(text).toContain(`$t:admin.${GROUP[kind]}.modals.${key}`);
    }
  });
});
