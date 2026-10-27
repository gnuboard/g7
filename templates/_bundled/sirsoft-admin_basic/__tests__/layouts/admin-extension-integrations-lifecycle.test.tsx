/**
 * @file admin-extension-integrations-lifecycle.test.tsx
 * @description 함께 쓰면 연동되는 확장(integrations)의 관리자 화면 — 상세 버전 배지·설치 창 체크목록·끄기 안내 창·삭제 안내 (코어 7.1.4)
 *
 * integrations 가 의존(dependencies)과 같은 수준의 스펙이 되면서 관리자 화면도 의존처럼 다룬다. 다만 연동은 막지 않는다 —
 * 설치 창은 해제해도 설치가 진행되고, 연동 중인 확장이 있는 모듈·플러그인을 끄면 안내 창을 거칠 뿐 끄기는 진행된다.
 * 옛 코어(필드 없음)에서는 절이 그려지지 않거나 종전 흐름을 탄다.
 *
 * 테스트 대상(굽기 산출 JSON):
 * - partials/admin_{module,plugin,template}_list/_modal_detail.json (integrations_section)
 * - partials/admin_{module,plugin,template}_list/_modal_install.json (cascade_integrations_section · 요청 본문)
 * - admin_{module,plugin}_list.json (onToggle 갈래) · partials/admin_template_list/_tab_{admin,user}.json (설치 창 열기)
 * - partials/admin_{module,plugin}_list/_modal_deactivate_integrations.json (신규)
 * - partials/admin_{module,plugin,template}_list/_modal_uninstall.json · admin_template_list/_modal_deactivate.json
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

type Json = Record<string, any>;
type Ext = 'module' | 'plugin' | 'template';

const ROOT = resolve(__dirname, '../..');
const LAYOUTS = join(ROOT, 'layouts');
const SELECTED: Record<Ext, string> = { module: 'selectedModule', plugin: 'selectedPlugin', template: 'selectedTemplate' };
const EXTS: Ext[] = ['module', 'plugin', 'template'];

const load = (rel: string): Json => JSON.parse(readFileSync(join(LAYOUTS, rel), 'utf-8'));

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;
const TestLabel: React.FC<any> = ({ className, children }) => <label className={className}>{children}</label>;
const TestInput: React.FC<any> = ({ type, checked, disabled, 'aria-label': ariaLabel }) => (
  <input type={type} checked={!!checked} disabled={!!disabled} aria-label={ariaLabel} readOnly />
);

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const koAdmin = JSON.parse(readFileSync(join(ROOT, 'lang/partial/ko/admin.json'), 'utf-8'));
const translations = { admin: { extension_integrations: koAdmin.extension_integrations } };
const T = koAdmin.extension_integrations as Record<string, string>;

/** 트리에서 조건에 맞는 노드를 모은다. */
function collect(tree: unknown, pred: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (pred(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

const byId = (tree: unknown, id: string): Json => {
  const found = collect(tree, (n) => n.id === id);
  expect(found.length, `${id} 노드가 하나여야 합니다.`).toBe(1);
  return found[0];
};

/** `$t('키')` 를 ko 문구로 바꾸는 식 도우미 — 엔진의 식 컨텍스트 `$t` 를 대신한다. */
const $t = (key: string): string => key.split('.').reduce((o: any, k) => o?.[k], { admin: koAdmin }) ?? key;

/** 바인딩 식을 평가한다. */
function evalBinding(expr: string, scope: Json): any {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(String(expr).trim());
  const full = { $t, ...scope };
  const names = Object.keys(full);
  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${m ? m[1] : JSON.stringify(expr)});`)(...names.map((k) => full[k]));
}

function renderSection(section: Json, initGlobal: Json) {
  setupRegistry();
  return createLayoutTest(
    { version: '1.0.0', layout_name: 'integrations_test', initGlobal, components: [section] } as any,
    { translations, locale: 'ko', componentRegistry: ComponentRegistry.getInstance() },
  );
}

let testUtils: ReturnType<typeof createLayoutTest> | undefined;
afterEach(() => {
  testUtils?.cleanup();
  testUtils = undefined;
});

const DETAIL_ITEMS = [
  { type: 'module', identifier: 'sirsoft-board', name: '게시판', is_installed: true, is_active: true, required_version: '>=1.2.1', installed_version: '1.2.1', is_met: true },
  { type: 'module', identifier: 'sirsoft-ecommerce', name: '쇼핑몰', is_installed: true, is_active: true, required_version: '>=2.0.0', installed_version: '1.3.2', is_met: false },
  { type: 'plugin', identifier: 'sirsoft-daum_postcode', name: '우편번호', is_installed: false, is_active: false, required_version: '*', installed_version: null, is_met: false },
];

/** 상세 창: 요구·설치 버전과, 설치됐는데 제약을 못 맞춘 항목에만 「버전 미달」을 단다(연동 상태 4가지를 한 목록에 담는다). */
async function assertDetailVersionBadges(ext: Ext): Promise<void> {
  const section = byId(load(`partials/admin_${ext}_list/_modal_detail.json`), 'integrations_section');
  testUtils = renderSection(section, { [SELECTED[ext]]: { integrations: DETAIL_ITEMS } });
  await testUtils.render();

  expect(screen.getByText('요구 >=1.2.1')).toBeTruthy();
  expect(screen.getByText('설치 1.3.2')).toBeTruthy();
  // 무제약('*')은 요구 버전 배지를 그리지 않는다.
  expect(screen.queryByText('요구 *')).toBeNull();
  // 쇼핑몰(설치·제약 미충족)만 「버전 미달」 — 미설치 우편번호는 is_met=false 여도 「미설치」만.
  expect(screen.getAllByText(T.version_mismatch)).toHaveLength(1);
  expect(screen.getAllByText(T.not_installed)).toHaveLength(1);
}

/** 상세 창: 옛 코어(버전 필드 없음)에서는 버전 배지·「버전 미달」이 붙지 않는다. */
async function assertDetailLegacyCore(ext: Ext): Promise<void> {
  const section = byId(load(`partials/admin_${ext}_list/_modal_detail.json`), 'integrations_section');
  const legacy = [{ type: 'module', identifier: 'sirsoft-board', name: '게시판', is_installed: true, is_active: true }];
  testUtils = renderSection(section, { [SELECTED[ext]]: { integrations: legacy } });
  await testUtils.render();

  expect(screen.getByText('게시판 (sirsoft-board)')).toBeTruthy();
  expect(screen.queryByText(T.version_mismatch)).toBeNull();
  expect(screen.queryByText(/^요구 /)).toBeNull();
}

describe('상세 창 — 연동 확장의 요구 버전·「버전 미달」', () => {
  /**
   * @scenario extension_type=module,core_version=with_integration_fields
   * @effects detail_shows_required_version_and_mismatch_badge
   */
  it('모듈: 요구·설치 버전과 설치됐는데 제약을 못 맞춘 항목에만 「버전 미달」을 단다', () => assertDetailVersionBadges('module'));

  /**
   * @scenario extension_type=plugin,core_version=with_integration_fields
   * @effects detail_shows_required_version_and_mismatch_badge
   */
  it('플러그인: 요구·설치 버전과 설치됐는데 제약을 못 맞춘 항목에만 「버전 미달」을 단다', () => assertDetailVersionBadges('plugin'));

  /**
   * @scenario extension_type=template,core_version=with_integration_fields
   * @effects detail_shows_required_version_and_mismatch_badge
   */
  it('템플릿: 요구·설치 버전과 설치됐는데 제약을 못 맞춘 항목에만 「버전 미달」을 단다', () => assertDetailVersionBadges('template'));

  /**
   * @scenario extension_type=module,core_version=legacy_core
   * @effects detail_shows_required_version_and_mismatch_badge
   */
  it('모듈: 옛 코어(버전 필드 없음)에서는 버전 배지·「버전 미달」이 붙지 않는다', () => assertDetailLegacyCore('module'));

  /**
   * @scenario extension_type=plugin,core_version=legacy_core
   * @effects detail_shows_required_version_and_mismatch_badge
   */
  it('플러그인: 옛 코어(버전 필드 없음)에서는 버전 배지·「버전 미달」이 붙지 않는다', () => assertDetailLegacyCore('plugin'));

  /**
   * @scenario extension_type=template,core_version=legacy_core
   * @effects detail_shows_required_version_and_mismatch_badge
   */
  it('템플릿: 옛 코어(버전 필드 없음)에서는 버전 배지·「버전 미달」이 붙지 않는다', () => assertDetailLegacyCore('template'));
});

const PREVIEW = {
  integrations: [
    { type: 'module', identifier: 'sirsoft-board', name: '게시판', status: 'not_installed', required_version: '>=1.2.1', installed_version: null, is_installed: false, is_active: false, is_met: false, can_select: true, default_selected: true },
    { type: 'plugin', identifier: 'sirsoft-daum_postcode', name: '우편번호', status: 'inactive', required_version: '*', installed_version: '1.0.0', is_installed: true, is_active: false, is_met: true, can_select: true, default_selected: true },
    { type: 'module', identifier: 'sirsoft-page', name: '페이지', status: 'active', required_version: '*', installed_version: '1.0.0', is_installed: true, is_active: true, is_met: true, can_select: true, default_selected: false },
    { type: 'module', identifier: 'sirsoft-ecommerce', name: '쇼핑몰', status: 'inactive', required_version: '>=2.0.0', installed_version: '1.3.2', is_installed: true, is_active: false, is_met: false, can_select: false, default_selected: false },
    { type: 'template', identifier: 'sirsoft-basic', name: '기본 템플릿', status: 'not_installed', required_version: '*', installed_version: null, is_installed: false, is_active: false, is_met: false, can_select: false, default_selected: false },
  ],
};

/** 설치 apiCall 노드. */
const installCall = (tree: Json): Json => {
  const calls = collect(tree, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && /\/install$/.test(n.target));
  expect(calls.length).toBe(1);
  return calls[0];
};

describe('설치 창 — 함께 쓰면 연동되는 확장(선택)', () => {
  /**
   * @scenario extension_type=module,core_version=with_integration_fields
   * @effects install_modal_lists_integrations_with_default_selection
   */
  it.each(EXTS)('%s: 기본 체크는 default_selected 이고, 고를 수 없는 항목·이미 켜진 항목은 잠근다', async (ext) => {
    const section = byId(load(`partials/admin_${ext}_list/_modal_install.json`), 'cascade_integrations_section');
    testUtils = renderSection(section, { installPreview: PREVIEW, installSelectedIntegrations: null });
    await testUtils.render();

    const box = (label: string) => screen.getByLabelText(label) as HTMLInputElement;
    expect(screen.getByText(T.install_title)).toBeTruthy();
    expect(box('게시판 (sirsoft-board)').checked).toBe(true);
    expect(box('게시판 (sirsoft-board)').disabled).toBe(false);
    expect(box('우편번호 (sirsoft-daum_postcode)').checked).toBe(true);
    // 이미 켜진 페이지 — 표시만(잠금·해제 상태), 「사용 중」.
    expect(box('페이지 (sirsoft-page)').checked).toBe(false);
    expect(box('페이지 (sirsoft-page)').disabled).toBe(true);
    expect(screen.getByText(T.active)).toBeTruthy();
    // can_select=false(템플릿 대상·권한 없음) — 잠금 + 「여기서 설치할 수 없음」.
    expect(box('쇼핑몰 (sirsoft-ecommerce)').disabled).toBe(true);
    expect(box('기본 템플릿 (sirsoft-basic)').disabled).toBe(true);
    expect(screen.getAllByText(T.cannot_select)).toHaveLength(2);
    // 설치됐는데 버전이 낮은 쇼핑몰 — 「버전 미달」.
    expect(screen.getAllByText(T.version_mismatch)).toHaveLength(1);
  });

  /**
   * @scenario extension_type=plugin,core_version=legacy_core
   * @effects install_modal_lists_integrations_with_default_selection
   */
  it.each(EXTS)('%s: 미리보기에 integrations 가 없으면(옛 코어) 절을 그리지 않는다', (ext) => {
    const section = byId(load(`partials/admin_${ext}_list/_modal_install.json`), 'cascade_integrations_section');
    expect(evalBinding(section.if, { _global: { installPreview: { dependencies: [] } } })).toBe(false);
    expect(evalBinding(section.if, { _global: { installPreview: PREVIEW } })).toBe(true);
  });

  /**
   * @scenario extension_type=template,core_version=with_integration_fields
   * @effects install_request_carries_selected_integrations
   */
  it.each(EXTS)('%s: 요청 본문 integrations 는 고른 module·plugin 만 [{type, identifier}] 로 싣고, 해제하면 뺀다', (ext) => {
    const tree = load(`partials/admin_${ext}_list/_modal_install.json`);
    const body = installCall(tree).params.body;
    const expr = body.integrations as string;
    expect(expr, '설치 요청 본문에 integrations 가 없습니다.').toBeTruthy();

    // 건드리지 않음 → 서버 기본 선택(고를 수 있고 아직 안 켜진 것만).
    expect(evalBinding(expr, { _global: { installPreview: PREVIEW, installSelectedIntegrations: null } })).toEqual([
      { type: 'module', identifier: 'sirsoft-board' },
      { type: 'plugin', identifier: 'sirsoft-daum_postcode' },
    ]);

    // 체크 해제 — 체크 상자의 change 식으로 선택값을 만든 뒤 본문을 다시 계산한다.
    const section = byId(tree, 'cascade_integrations_section');
    const change = collect(section, (n) => n.handler === 'setState' && 'installSelectedIntegrations' in (n.params ?? {}))[0];
    const item = PREVIEW.integrations[0];
    const next = evalBinding(change.params.installSelectedIntegrations, { $event: { target: { checked: false } }, item, _global: { installPreview: PREVIEW, installSelectedIntegrations: null } });
    expect(next).toEqual(['plugin:sirsoft-daum_postcode']);
    expect(evalBinding(expr, { _global: { installPreview: PREVIEW, installSelectedIntegrations: next } })).toEqual([
      { type: 'plugin', identifier: 'sirsoft-daum_postcode' },
    ]);

    // 잠긴 항목(can_select=false·활성)은 선택값에 들어 있어도 싣지 않는다.
    expect(evalBinding(expr, { _global: { installPreview: PREVIEW, installSelectedIntegrations: ['module:sirsoft-ecommerce', 'module:sirsoft-page', 'template:sirsoft-basic'] } })).toEqual([]);
    // 옛 코어 — 미리보기에 integrations 가 없으면 빈 배열.
    expect(evalBinding(expr, { _global: { installPreview: { dependencies: [] }, installSelectedIntegrations: null } })).toEqual([]);

    // 성공하면 선택값을 비운다.
    const reset = collect(installCall(tree).onSuccess, (n) => n.handler === 'setState' && 'installSelectedIntegrations' in (n.params ?? {}));
    expect(reset.length).toBeGreaterThan(0);
    expect(reset[0].params.installSelectedIntegrations).toBeNull();
  });

  /**
   * @scenario extension_type=module,core_version=with_integration_fields
   * @effects install_selection_resets_when_modal_opens
   */
  it.each([
    ['admin_module_list.json', 'module_install_modal'],
    ['admin_plugin_list.json', 'plugin_install_modal'],
    ['partials/admin_template_list/_tab_admin.json', 'template_install_modal'],
    ['partials/admin_template_list/_tab_user.json', 'template_install_modal'],
  ])('%s: 설치 창을 열 때 직전 선택(의존·언어팩·연동)을 비운다', (rel, modal) => {
    const lists = collect(load(rel), (n) => Array.isArray(n.actions) && n.actions.some((a: Json) => a?.handler === 'openModal' && a.target === modal));
    expect(lists.length).toBe(1);
    const actions = lists[0].actions as Json[];
    const at = actions.findIndex((a) => a.handler === 'openModal' && a.target === modal);
    const resets = actions.slice(0, at).filter((a) => a.handler === 'setState');
    const params = Object.assign({}, ...resets.map((a) => a.params));
    expect(params.installSelectedDeps).toBeNull();
    expect(params.installSelectedLPs).toBeNull();
    expect(params.installSelectedIntegrations).toBeNull();
  });
});

describe('모듈·플러그인 끄기 — 연동 중인 확장 안내 창', () => {
  const lists: Array<[Ext, string]> = [['module', 'admin_module_list.json'], ['plugin', 'admin_plugin_list.json']];

  /**
   * @scenario extension_type=module,core_version=with_integration_fields
   * @effects deactivate_with_integrated_by_opens_notice_modal
   */
  it.each(lists)('%s: 끄려는 확장을 연동으로 쓰는 쪽이 있으면 안내 창을 열고, 없거나 켜는 중이면 곧바로 부른다', (ext, rel) => {
    const toggles = collect(load(rel), (n) => n.event === 'onToggle' && n.handler === 'switch');
    expect(toggles.length).toBe(1);
    const sw = toggles[0];
    const integratedBy = [{ type: 'template', identifier: 'sirsoft-basic', name: '기본 템플릿' }];
    expect(evalBinding(sw.params.value, { $args: [false, { integrated_by: integratedBy }] })).toBe('confirm');
    expect(evalBinding(sw.params.value, { $args: [false, { integrated_by: [] }] })).toBe('call');
    expect(evalBinding(sw.params.value, { $args: [false, {}] }), '옛 코어(integrated_by 없음)').toBe('call');
    expect(evalBinding(sw.params.value, { $args: [true, { integrated_by: integratedBy }] }), '켜기는 묻지 않는다').toBe('call');

    const confirm = JSON.stringify(sw.cases.confirm);
    expect(confirm).toContain('extensionToggleResync');
    expect(confirm).toContain(`"${SELECTED[ext]}":"{{$args[1]}}"`);
    expect(confirm).toContain(`"target":"${ext}_deactivate_integrations_modal"`);
    // 곧바로 부르는 갈래는 종전 호출(409 강제 창 포함)을 그대로 갖는다.
    const call = JSON.stringify(sw.cases.call);
    expect(call).toContain(`/api/admin/${ext}s/deactivate`);
    expect(call).toContain(`${ext}_force_deactivate_modal`);
    // 안내 창이 레이아웃에 실려 있다.
    expect(JSON.stringify(load(rel).modals ?? [])).toContain(`partials/admin_${ext}_list/_modal_deactivate_integrations`);
  });

  /**
   * @scenario extension_type=plugin,core_version=with_integration_fields
   * @effects deactivate_with_integrated_by_opens_notice_modal
   */
  it.each(lists)('%s: 안내 창은 이름과 연동 중인 확장 목록을 보인다', async (ext) => {
    const modal = load(`partials/admin_${ext}_list/_modal_deactivate_integrations.json`);
    expect(modal.id).toBe(`${ext}_deactivate_integrations_modal`);
    testUtils = renderSection(modal.children[0], {
      [SELECTED[ext]]: { identifier: 'sirsoft-board', name: '게시판', integrated_by: [{ type: 'template', identifier: 'sirsoft-basic', name: '기본 템플릿' }, { type: 'plugin', identifier: 'sirsoft-marketing', name: '마케팅' }] },
    });
    await testUtils.render();
    expect(screen.getByText(T.deactivate_notice_body.replace('{{name}}', '게시판'))).toBeTruthy();
    expect(screen.getByText(T.integrated_by_label)).toBeTruthy();
    expect(screen.getByText('기본 템플릿 (sirsoft-basic)')).toBeTruthy();
    expect(screen.getByText('마케팅 (sirsoft-marketing)')).toBeTruthy();
  });

  /**
   * @scenario extension_type=module,core_version=with_integration_fields
   * @effects notice_modal_deactivates_and_hands_409_to_force_modal
   */
  it.each(lists)('%s: 안내 창의 비활성 호출 — 성공은 목록 갱신·경고 창 연결, 409 는 강제 비활성 창으로 넘긴다', (ext) => {
    const modal = load(`partials/admin_${ext}_list/_modal_deactivate_integrations.json`);
    const calls = collect(modal, (n) => n.handler === 'apiCall');
    expect(calls.length).toBe(1);
    const call = calls[0];
    expect(call.target).toBe(`/api/admin/${ext}s/deactivate`);
    expect(call.params.body).toEqual({ [`${ext}_name`]: `{{_global.${SELECTED[ext]}.identifier}}` });
    expect(call.params.body.force, '강제 끄기는 강제 창의 몫이다').toBeUndefined();

    const success = JSON.stringify(call.onSuccess);
    expect(success).toContain(`"dataSourceId":"${ext}s"`);
    expect(success).toContain('"lifecycleWarnings"');
    expect(success).toContain('lifecycle_warnings_modal');

    const on409 = JSON.stringify(call.errorHandling?.['409'] ?? call.params?.errorHandling?.['409'] ?? collect(call, (n) => n['409'])[0]?.['409']);
    expect(on409).toContain('closeModal');
    expect(on409).toContain('deactivateWarningData');
    expect(on409).toContain(`${ext}_force_deactivate_modal`);
  });
});

describe('삭제 창·템플릿 끄기 창 — 연동 안내', () => {
  const integratedBy = [{ type: 'module', identifier: 'sirsoft-ecommerce', name: '쇼핑몰' }];

  /**
   * @scenario extension_type=template,core_version=with_integration_fields
   * @effects uninstall_and_template_deactivate_show_integrated_by
   */
  it.each(EXTS)('%s 삭제 창: 연동 중인 확장이 있을 때만 안내 블록을 보이고, 삭제를 마친 화면에서는 감춘다', async (ext) => {
    const block = byId(load(`partials/admin_${ext}_list/_modal_uninstall.json`), 'uninstall_integrated_by');
    const done = `${ext}UninstallBackups`;
    expect(evalBinding(block.if, { _global: { [SELECTED[ext]]: { integrated_by: integratedBy } } })).toBe(true);
    expect(evalBinding(block.if, { _global: { [SELECTED[ext]]: { integrated_by: [] } } })).toBe(false);
    expect(evalBinding(block.if, { _global: { [SELECTED[ext]]: {} } }), '옛 코어').toBe(false);
    expect(evalBinding(block.if, { _global: { [SELECTED[ext]]: { integrated_by: integratedBy }, [done]: ['backup.zip'] } })).toBe(false);

    testUtils = renderSection(block, { [SELECTED[ext]]: { identifier: 'x', name: 'X', integrated_by: integratedBy } });
    await testUtils.render();
    expect(screen.getByText(T.uninstall_notice)).toBeTruthy();
    expect(screen.getByText('쇼핑몰 (sirsoft-ecommerce)')).toBeTruthy();
  });

  /**
   * @scenario extension_type=template,core_version=with_integration_fields
   * @effects uninstall_and_template_deactivate_show_integrated_by
   */
  it('템플릿 끄기 창: 기존 확인 창 안에 연동 안내를 싣는다(창을 두 번 띄우지 않는다)', async () => {
    const tree = load('partials/admin_template_list/_modal_deactivate.json');
    const block = byId(tree, 'deactivate_integrated_by');
    expect(evalBinding(block.if, { _global: { selectedTemplate: { integrated_by: [] } } })).toBe(false);

    testUtils = renderSection(block, { selectedTemplate: { identifier: 'sirsoft-basic', name: '기본 템플릿', integrated_by: [{ type: 'module', identifier: 'sirsoft-x', name: 'X 모듈' }] } });
    await testUtils.render();
    expect(screen.getByText(T.deactivate_notice_body.replace('{{name}}', '기본 템플릿'))).toBeTruthy();
    expect(screen.getByText('X 모듈 (sirsoft-x)')).toBeTruthy();
    // 템플릿 목록은 별도 연동 안내 창을 두지 않는다.
    expect(() => readFileSync(join(LAYOUTS, 'partials/admin_template_list/_modal_deactivate_integrations.json'))).toThrow();
  });
});

describe('설치·활성화 결과 — 연동 안내 토스트', () => {
  /** 설치·활성화 apiCall 의 onSuccess 를 모두 모은다. */
  const sites: Array<[string, Json]> = [];
  for (const ext of EXTS) {
    for (const file of ['_modal_install', '_modal_manual_install', '_modal_force_activate']) {
      sites.push([`${ext} ${file}`, load(`partials/admin_${ext}_list/${file}.json`)]);
    }
  }
  sites.push(['template _modal_activate', load('partials/admin_template_list/_modal_activate.json')]);
  sites.push(['module 목록 스위치', load('admin_module_list.json')]);
  sites.push(['plugin 목록 스위치', load('admin_plugin_list.json')]);

  const NOTICES = [
    { type: 'module', identifier: 'sirsoft-board', name: '게시판', state: 'not_installed', required_version: '*', installed_version: null },
    { type: 'module', identifier: 'sirsoft-ecommerce', name: '쇼핑몰', state: 'version_mismatch', required_version: '>=2.0.0', installed_version: '1.3.2' },
    { type: 'plugin', identifier: 'sirsoft-daum_postcode', name: '', state: 'inactive', required_version: '*', installed_version: '1.0.0' },
  ];

  /**
   * @scenario extension_type=module,core_version=with_integration_fields
   * @effects lifecycle_success_shows_integration_notice_toast
   */
  it.each(sites)('%s: integration_notices 가 있으면 이름·상태를 안내 토스트로 띄우고, 없으면 띄우지 않는다', (_label, tree) => {
    const lifecycle = collect(tree, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && /(install[a-z-]*|activate)(?=$|['"\s])/.test(n.target) && Array.isArray(n.onSuccess));
    expect(lifecycle.length).toBeGreaterThan(0);
    for (const call of lifecycle) {
      const toasts = collect(call.onSuccess, (n) => n.handler === 'toast' && String(n.params?.message ?? '').includes('$t:admin.extension_integrations.notice_toast'));
      expect(toasts.length, `${call.target}: 연동 안내 토스트가 없습니다.`).toBe(1);
      const toast = toasts[0];
      expect(toast.params.type).toBe('info');
      expect(evalBinding(toast.if, { response: { data: { integration_notices: NOTICES } } })).toBe(true);
      expect(evalBinding(toast.if, { response: { data: { integration_notices: [] } } })).toBe(false);
      expect(evalBinding(toast.if, { response: { data: {} } }), '옛 코어').toBe(false);
      const items = /items=(\{\{[\s\S]*\}\})$/.exec(toast.params.message)![1];
      expect(evalBinding(items, { response: { data: { integration_notices: NOTICES } } }))
        .toBe(`게시판 (${T.not_installed}), 쇼핑몰 (${T.version_mismatch}), sirsoft-daum_postcode (${T.inactive})`);
    }
  });
});

describe('다국어 — extension_integrations 키', () => {
  const files = (dir: string): string[] => readdirSync(dir).flatMap((n) => {
    const full = join(dir, n);
    return statSync(full).isDirectory() ? files(full) : (n.endsWith('.g7.html') ? [full] : []);
  });
  const used = new Set<string>();
  for (const f of files(LAYOUTS)) {
    const text = readFileSync(f, 'utf-8');
    for (const m of text.matchAll(/\$t:admin\.extension_integrations\.([a-z_]+)/g)) used.add(m[1]);
    for (const m of text.matchAll(/\$t\('admin\.extension_integrations\.([a-z_]+)'\)/g)) used.add(m[1]);
  }
  // 상태 이름은 식으로 고른다(`'admin.extension_integrations.' + n.state`).
  ['not_installed', 'inactive', 'version_mismatch'].forEach((k) => used.add(k));

  const locales: Array<[string, string]> = [
    ['ko', join(ROOT, 'lang/partial/ko/admin.json')],
    ['en', join(ROOT, 'lang/partial/en/admin.json')],
    ['ja', resolve(ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json')],
  ];

  /**
   * @scenario extension_type=module,core_version=with_integration_fields
   * @effects integration_keys_exist_in_all_locales
   */
  it.each(locales)('%s: 레이아웃이 쓰는 키가 모두 있다', (_locale, file) => {
    const dict = JSON.parse(readFileSync(file, 'utf-8')).extension_integrations ?? {};
    expect(used.size).toBeGreaterThan(10);
    const missing = [...used].filter((k) => typeof dict[k] !== 'string' || dict[k] === '');
    expect(missing).toEqual([]);
  });
});
