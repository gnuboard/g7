/**
 * @file admin_plugin_list._modal_update.force.test.tsx
 * @description 플러그인 업데이트 모달 - force 체크박스 동작 검증
 *
 * 검증 항목:
 * - is_compatible=false 시 코어 호환성 안내 패널 + force 체크박스 렌더
 * - 초기에 진행 버튼 disabled (force 체크 안됨)
 * - force 체크박스 클릭 → _global.pluginForceUpdate 토글 → 진행 버튼 enabled
 * - 진행 버튼 클릭 시 apiCall body 에 force=true 포함 (sequence 내 action 구조 검증)
 *
 * 호환되는 경우(is_compatible=true)는 force 체크박스가 렌더되지 않음
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import path from 'path';
import fs from 'fs';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// ==============================
// 실제 partial JSON 로드
// ==============================

const partialPath = path.resolve(
  __dirname,
  '../../layouts/partials/admin_plugin_list/_modal_update.json',
);
const modalPartial: any = JSON.parse(fs.readFileSync(partialPath, 'utf-8'));

// ==============================
// 테스트용 컴포넌트
// ==============================

const TestDiv: React.FC<any> = ({ className, children, role }) => (
  <div className={className} role={role}>
    {children}
  </div>
);
const TestSpan: React.FC<any> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);
const TestP: React.FC<any> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);
const TestIcon: React.FC<any> = ({ name, size, className }) => (
  <i
    className={`icon-${name} ${className || ''}`}
    data-testid={`icon-${name}`}
    data-size={size}
  />
);
const TestButton: React.FC<any> = ({
  type,
  disabled,
  className,
  children,
  'data-testid': testId,
  onClick,
}) => (
  <button
    type={type}
    disabled={disabled}
    className={className}
    data-testid={testId}
    onClick={onClick}
  >
    {children}
  </button>
);
const TestInput: React.FC<any> = ({
  type,
  checked,
  className,
  name,
  value,
  onChange,
}) => (
  <input
    type={type}
    checked={!!checked}
    onChange={onChange ?? (() => {})}
    className={className}
    name={name}
    value={value}
    data-testid={type === 'checkbox' ? 'force-checkbox' : undefined}
  />
);
const TestLabel: React.FC<any> = ({ className, children }) => (
  <label className={className}>{children}</label>
);
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

const translations = {
  common: { cancel: '취소' },
  extensions: {
    types: { plugin: '플러그인' },
    update_modal: {
      compat_warning_title: '코어 호환성 경고',
      compat_warning_message:
        '{{type}}: 코어 {{required}} 이상 필요 (현재 {{installed}})',
      compat_guide_link: '코어 업그레이드 가이드',
      force_label: '경고를 이해했으며 강제로 업데이트합니다',
    },
  },
  admin: {
    plugins: {
      updating: '업데이트 중...',
      update_success: '업데이트 성공',
      actions: { update: '업데이트' },
      modals: {
        update_title: '플러그인 업데이트',
        update_confirm: '{{name}} 을(를) 업데이트하시겠습니까?',
        update_version_info: '{{from}} → {{to}}',
        update_source_github: 'GitHub',
        update_source_bundled: '번들',
        update_source_pending: '대기',
        field_version: '버전',
        field_vendor: '벤더',
        field_update_source: '업데이트 출처',
        view_changelog: '변경사항 보기',
        update_changelog_title: '변경사항',
        layout_strategy_title: '레이아웃 전략',
        layout_strategy_overwrite: '덮어쓰기',
        layout_strategy_overwrite_desc: '기존 레이아웃을 새 버전으로 덮어씁니다',
        layout_strategy_keep: '유지',
        layout_strategy_keep_desc: '기존 레이아웃을 유지합니다',
        modified_layouts_warning: '수정된 레이아웃 {{count}}개',
        no_modified_layouts: '수정된 레이아웃 없음',
        modified_layouts_list_title: '수정 목록 ({{count}})',
        modified_layouts_keep_notice: '유지 시 새 변경사항이 적용되지 않을 수 있습니다',
      },
    },
  },
};

// ==============================
// 레이아웃 빌더 (modals 형태로 partial 주입)
// ==============================

function buildLayout(initGlobal: Record<string, any>) {
  return {
    version: '1.0.0',
    layout_name: 'plugin_modal_force_test',
    initGlobal,
    components: [
      {
        id: 'host',
        type: 'basic',
        name: 'Div',
        children: [modalPartial],
      },
    ],
  };
}

// ==============================
// 테스트
// ==============================

describe('플러그인 업데이트 모달 - force 체크박스', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('is_compatible=false 일 때 호환성 안내 패널과 force 체크박스가 렌더된다', async () => {
    testUtils = createLayoutTest(
      buildLayout({
        selectedPlugin: {
          identifier: 'sirsoft-payment',
          name: '결제',
          version: '1.0.0',
          latest_version: '2.0.0',
          update_source: 'github',
          is_compatible: false,
          required_core_version: '>=7.5.0',
          current_core_version: '7.0.0',
        },
        pluginForceUpdate: false,
        pluginUpdateError: null,
        isPluginUpdating: false,
        pluginLayoutStrategy: 'overwrite',
        hasModifiedPluginLayouts: false,
        modifiedPluginLayoutsCount: 0,
        modifiedPluginLayouts: [],
        updateChangelog: [],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    // 호환성 안내 패널 렌더
    expect(screen.getByText('코어 호환성 경고')).toBeTruthy();
    // force 체크박스 라벨
    expect(screen.getByText(/경고를 이해/)).toBeTruthy();
    // 체크박스 input
    const checkbox = screen.getByTestId('force-checkbox') as HTMLInputElement;
    expect(checkbox).toBeTruthy();
    expect(checkbox.checked).toBe(false);
  });

  // 진행 버튼은 amber-600 클래스로 식별 (텍스트는 $t: 키 또는 번역됨에 따라 달라짐)
  function getConfirmButton(): HTMLButtonElement | null {
    const buttons = Array.from(document.querySelectorAll('button'));
    return (
      (buttons.find(b => b.className.includes('bg-amber-600')) as HTMLButtonElement) ||
      null
    );
  }

  it('is_compatible=false + force 미체크 시 진행 버튼이 disabled', async () => {
    testUtils = createLayoutTest(
      buildLayout({
        selectedPlugin: {
          identifier: 'p',
          name: '결제',
          version: '1.0.0',
          latest_version: '2.0.0',
          is_compatible: false,
          required_core_version: '>=7.5.0',
          current_core_version: '7.0.0',
        },
        pluginForceUpdate: false,
        isPluginUpdating: false,
        pluginLayoutStrategy: 'overwrite',
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    const button = getConfirmButton();
    expect(button).toBeTruthy();
    expect(button!.disabled).toBe(true);
  });

  it('is_compatible=false + force 체크 시 진행 버튼이 enabled', async () => {
    testUtils = createLayoutTest(
      buildLayout({
        selectedPlugin: {
          identifier: 'p',
          name: '결제',
          version: '1.0.0',
          latest_version: '2.0.0',
          is_compatible: false,
          required_core_version: '>=7.5.0',
          current_core_version: '7.0.0',
        },
        pluginForceUpdate: true,
        isPluginUpdating: false,
        pluginLayoutStrategy: 'overwrite',
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    const button = getConfirmButton();
    expect(button).toBeTruthy();
    expect(button!.disabled).toBe(false);
  });

  it('is_compatible=true 일 때 호환성 패널/체크박스가 렌더되지 않고 진행 버튼 enabled', async () => {
    testUtils = createLayoutTest(
      buildLayout({
        selectedPlugin: {
          identifier: 'p',
          name: '결제',
          version: '1.0.0',
          latest_version: '2.0.0',
          is_compatible: true,
        },
        pluginForceUpdate: false,
        isPluginUpdating: false,
        pluginLayoutStrategy: 'overwrite',
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText('코어 호환성 경고')).toBeNull();
    expect(screen.queryByTestId('force-checkbox')).toBeNull();

    const button = getConfirmButton();
    expect(button).toBeTruthy();
    expect(button!.disabled).toBe(false);
  });

  it('진행 버튼 actions에 force 바인딩이 apiCall body 에 포함되어 있다', () => {
    // partial JSON 구조 검증 — sequence 내부 apiCall body.force 값
    const buttonsRow = modalPartial.children[modalPartial.children.length - 1];
    const confirmBtn = buttonsRow.children[1];
    expect(confirmBtn.name).toBe('Button');

    const sequence = confirmBtn.actions[0];
    expect(sequence.handler).toBe('sequence');

    const apiCall = sequence.actions.find((a: any) => a.handler === 'apiCall');
    expect(apiCall).toBeDefined();
    expect(apiCall.target).toContain('/update');
    expect(apiCall.params.method).toBe('POST');
    expect(apiCall.params.body).toHaveProperty('force');
    expect(apiCall.params.body.force).toBe(
      '{{_global.pluginForceUpdate === true}}',
    );
  });

  it('체크박스 클릭 액션이 _global.pluginForceUpdate 를 토글한다', () => {
    // partial 구조에서 체크박스 onChange 핸들러가 setState target=global, pluginForceUpdate 토글인지 검증
    function findCheckbox(node: any): any | null {
      if (node?.name === 'Input' && node?.props?.type === 'checkbox') return node;
      const children = node?.children;
      if (Array.isArray(children)) {
        for (const c of children) {
          const r = findCheckbox(c);
          if (r) return r;
        }
      }
      return null;
    }
    const checkbox = findCheckbox(modalPartial);
    expect(checkbox).not.toBeNull();
    const action = checkbox.actions[0];
    expect(action.type).toBe('change');
    expect(action.handler).toBe('setState');
    expect(action.params.target).toBe('global');
    expect(action.params.pluginForceUpdate).toBe('{{!_global.pluginForceUpdate}}');
  });
});
