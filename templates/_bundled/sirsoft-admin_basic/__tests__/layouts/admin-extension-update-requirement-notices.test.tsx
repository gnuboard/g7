/**
 * @file admin-extension-update-requirement-notices.test.tsx
 * @description 업데이트 창이 새 버전이 요구하는 확장(필수 의존·연동) 중 지금 못 미치는 것을 업데이트 **전에** 보인다 (코어 7.1.5)
 *
 * 새 판이 연동 확장의 요구 버전을 올리면 업데이트가 끝난 뒤 완료 안내에서야 「연동이 꺼진 것이 있습니다」를 알렸다.
 * 목록 행이 업데이트가 있을 때 `update_dependency_warnings`·`update_integration_notices` 를 싣고, 모듈·플러그인·템플릿
 * 업데이트 창 셋이 그 목록을 경고 상자로 보인다. 배지는 설치 창의 연동 목록과 같은 문구(미설치·비활성·버전 미달)다.
 * 업데이트는 막지 않는다 — 실행 버튼은 그대로 눌린다(업데이트 뒤 안내와 같이 알리기만 한다).
 * 옛 코어처럼 두 필드가 없거나 빈 목록이면 상자를 그리지 않는다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import path from 'path';
import fs from 'fs';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

type Json = Record<string, any>;

/** 업데이트 창 partial(굽힌 짝 JSON)을 읽는다. */
function loadPartial(kind: 'module' | 'plugin' | 'template'): Json {
  const partialPath = path.resolve(__dirname, `../../layouts/partials/admin_${kind}_list/_modal_update.json`);
  return JSON.parse(fs.readFileSync(partialPath, 'utf-8'));
}

const TestDiv: React.FC<any> = ({ className, children, role }) => (
  <div className={className} role={role}>
    {children}
  </div>
);
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestIcon: React.FC<any> = ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} />;
const TestButton: React.FC<any> = ({ type, disabled, className, children, onClick }) => (
  <button type={type} disabled={disabled} className={className} onClick={onClick}>
    {children}
  </button>
);
const TestInput: React.FC<any> = ({ type, checked, className, name, value, onChange }) => (
  <input type={type} checked={!!checked} onChange={onChange ?? (() => {})} className={className} name={name} value={value} />
);
const TestLabel: React.FC<any> = ({ className, children }) => <label className={className}>{children}</label>;
const TestA: React.FC<any> = ({ href, target, className, children }) => (
  <a href={href} target={target} className={className}>
    {children}
  </a>
);
const TestModal: React.FC<any> = ({ title, children }) => (
  <div role="dialog">
    <h2>{title}</h2>
    {children}
  </div>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/** 창이 쓰는 문구 — 새 키(requirements_*)와 설치 창 연동 목록의 배지 키를 함께 둔다. */
const translations = {
  common: { cancel: '취소' },
  extensions: {
    types: { module: '모듈', plugin: '플러그인', template: '템플릿' },
    update_modal: {
      compat_warning_title: '코어 호환성 경고',
      compat_warning_message: '{{type}}: 코어 {{required}} 이상 필요 (현재 {{installed}})',
      compat_guide_link: '코어 업그레이드 가이드',
      force_label: '강제 업데이트',
      requirements_title: 'REQ_TITLE',
      requirements_dependencies_hint: 'REQ_DEPENDENCIES_HINT',
      requirements_integrations_hint: 'REQ_INTEGRATIONS_HINT',
    },
  },
  admin: {
    extension_integrations: {
      not_installed: 'BADGE_NOT_INSTALLED',
      inactive: 'BADGE_INACTIVE',
      version_mismatch: 'BADGE_VERSION_MISMATCH',
      required_version: '요구 {{version}}',
      installed_version: '설치 {{version}}',
    },
  },
};

/** 종류별 전역 상태 이름. */
const STATE: Record<'module' | 'plugin' | 'template', { selected: string; force: string; updating: string; strategy: string }> = {
  module: { selected: 'selectedModule', force: 'moduleForceUpdate', updating: 'isModuleUpdating', strategy: 'moduleLayoutStrategy' },
  plugin: { selected: 'selectedPlugin', force: 'pluginForceUpdate', updating: 'isPluginUpdating', strategy: 'pluginLayoutStrategy' },
  template: { selected: 'selectedTemplate', force: 'templateForceUpdate', updating: 'isTemplateUpdating', strategy: 'templateLayoutStrategy' },
};

/** 목록 API 가 업데이트가 있는 행에 실어 주는 모양(새 판이 연동 둘의 요구 버전을 올리고 필수 의존 하나를 새로 요구). */
function rowWithRequirements(extra: Json = {}): Json {
  return {
    identifier: 'acme-target',
    name: '대상 확장',
    version: '1.3.0',
    latest_version: '1.3.1',
    update_source: 'bundled',
    is_compatible: true,
    update_dependency_warnings: [
      { type: 'module', identifier: 'acme-required', name: '필수 모듈', state: 'not_installed', required_version: '>=1.0.0', installed_version: null, message: '' },
    ],
    update_integration_notices: [
      { type: 'module', identifier: 'acme-shop', name: '쇼핑', state: 'version_mismatch', required_version: '>=1.3.4', installed_version: '1.3.3', message: '' },
      { type: 'plugin', identifier: 'acme-postcode', name: '우편번호', state: 'inactive', required_version: '>=1.2.0', installed_version: '1.2.0', message: '' },
    ],
    ...extra,
  };
}

function buildLayout(kind: 'module' | 'plugin' | 'template', row: Json): Json {
  const s = STATE[kind];
  return {
    version: '1.0.0',
    layout_name: `${kind}_update_requirements_test`,
    initGlobal: {
      [s.selected]: row,
      [s.force]: false,
      [s.updating]: false,
      [s.strategy]: 'overwrite',
      updateChangelog: [],
    },
    components: [{ id: 'host', type: 'basic', name: 'Div', children: [loadPartial(kind)] }],
  };
}

/** 실행 버튼 — 주황(amber-700) 배경으로 식별한다. */
function confirmButton(): HTMLButtonElement {
  const button = Array.from(document.querySelectorAll('button')).find((b) => b.className.includes('bg-amber-700')) as HTMLButtonElement | undefined;
  expect(button, '실행 버튼이 있어야 합니다').toBeTruthy();
  return button!;
}

describe.each(['module', 'plugin', 'template'] as const)('%s 업데이트 창 — 새 버전이 요구하는 확장 경고', (kind) => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  // @scenario case=modal_shows_unmet_requirements_before_update
  // @effects update_modal_warns_unmet_requirements_before_update
  it('못 미치는 필수 의존·연동을 이름·요구 버전·설치 버전·상태 배지로 보인다', async () => {
    testUtils = createLayoutTest(buildLayout(kind, rowWithRequirements()), { translations, locale: 'ko', componentRegistry: registry });
    await testUtils.render();

    expect(screen.getByText('REQ_TITLE')).toBeTruthy();
    expect(screen.getByText('REQ_DEPENDENCIES_HINT')).toBeTruthy();
    expect(screen.getByText('REQ_INTEGRATIONS_HINT')).toBeTruthy();

    expect(screen.getByText('필수 모듈 (acme-required)')).toBeTruthy();
    expect(screen.getByText('쇼핑 (acme-shop)')).toBeTruthy();
    expect(screen.getByText('우편번호 (acme-postcode)')).toBeTruthy();

    expect(screen.getByText('요구 >=1.3.4')).toBeTruthy();
    expect(screen.getByText('설치 1.3.3')).toBeTruthy();
    expect(screen.getByText('BADGE_NOT_INSTALLED')).toBeTruthy();
    expect(screen.getByText('BADGE_VERSION_MISMATCH')).toBeTruthy();
    expect(screen.getByText('BADGE_INACTIVE')).toBeTruthy();

    // 경고 상자는 알림 역할이다(코어 호환 경고와 같은 자리·모양)
    const box = screen.getByText('REQ_TITLE').closest('[role="alert"]');
    expect(box).not.toBeNull();
  });

  // @scenario case=modal_does_not_block_update
  // @effects update_modal_requirements_do_not_block_update
  it('경고가 있어도 실행 버튼은 잠기지 않는다(업데이트를 막지 않는다)', async () => {
    testUtils = createLayoutTest(buildLayout(kind, rowWithRequirements()), { translations, locale: 'ko', componentRegistry: registry });
    await testUtils.render();

    expect(screen.getByText('REQ_TITLE')).toBeTruthy();
    expect(confirmButton().disabled).toBe(false);
  });

  // @scenario case=modal_integrations_only
  // @effects update_modal_warns_unmet_requirements_before_update
  it('연동만 못 미치면 연동 절만 그린다', async () => {
    testUtils = createLayoutTest(buildLayout(kind, rowWithRequirements({ update_dependency_warnings: [] })), {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByText('REQ_TITLE')).toBeTruthy();
    expect(screen.queryByText('REQ_DEPENDENCIES_HINT')).toBeNull();
    expect(screen.getByText('REQ_INTEGRATIONS_HINT')).toBeTruthy();
  });

  // @scenario case=modal_requirements_met_or_old_core
  // @effects update_modal_hides_requirements_when_met
  it.each([
    ['모두 충족(빈 목록)', { update_dependency_warnings: [], update_integration_notices: [] }],
    ['대상 판 manifest 를 모름(null)', { update_dependency_warnings: null, update_integration_notices: null }],
    ['옛 코어(필드 없음)', { update_dependency_warnings: undefined, update_integration_notices: undefined }],
  ])('%s 이면 경고 상자를 그리지 않는다', async (_label, extra) => {
    testUtils = createLayoutTest(buildLayout(kind, rowWithRequirements(extra)), { translations, locale: 'ko', componentRegistry: registry });
    await testUtils.render();

    expect(screen.queryByText('REQ_TITLE')).toBeNull();
    expect(confirmButton().disabled).toBe(false);
  });
});
