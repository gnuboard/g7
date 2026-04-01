/**
 * @file commonInfoAbilities.test.tsx
 * @description 공통정보 관리 페이지 abilities 기반 권한 제어 렌더링 테스트
 *
 * 테스트 대상:
 * - 메인 레이아웃: DS errorHandling, 공통정보 추가 버튼 disabled
 * - _panel_list.json: is_active Toggle per-item disabled
 * - _panel_view.json: 편집/삭제/복사/기본값 설정 버튼 권한 제어
 * - _panel_form.json: 저장, MultilingualInput, Textarea, Checkbox, Toggles disabled
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트 정의
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
  type?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);

const TestToggle: React.FC<{
  disabled?: boolean;
  checked?: boolean;
  size?: string;
  'data-testid'?: string;
}> = ({ disabled, checked, 'data-testid': testId }) => (
  <input
    type="checkbox"
    checked={checked}
    disabled={disabled}
    data-testid={testId}
    readOnly
  />
);

const TestMultilingualInput: React.FC<{
  disabled?: boolean;
  value?: any;
  placeholder?: string;
  error?: string;
  layout?: string;
  'data-testid'?: string;
}> = ({ disabled, 'data-testid': testId }) => (
  <input disabled={disabled} data-testid={testId} readOnly />
);

const TestTextarea: React.FC<{
  disabled?: boolean;
  value?: string;
  placeholder?: string;
  rows?: number;
  className?: string;
  'data-testid'?: string;
}> = ({ disabled, 'data-testid': testId }) => (
  <textarea disabled={disabled} data-testid={testId} readOnly />
);

const TestInput: React.FC<{
  disabled?: boolean;
  type?: string;
  checked?: boolean;
  className?: string;
  'data-testid'?: string;
}> = ({ disabled, type, checked, 'data-testid': testId }) => (
  <input type={type} checked={checked} disabled={disabled} data-testid={testId} readOnly />
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
    MultilingualInput: { component: TestMultilingualInput, metadata: { name: 'MultilingualInput', type: 'composite' } },
    Textarea: { component: TestTextarea, metadata: { name: 'Textarea', type: 'composite' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ──────────────────────────────────────────────
// 1. 메인 레이아웃 errorHandling 검증
// ──────────────────────────────────────────────

import mainLayout from '../../../layouts/admin/admin_ecommerce_product_common_info_index.json';

describe('공통정보 - 메인 레이아웃 구조 검증', () => {
  it('commonInfos 데이터 소스에 errorHandling이 정의되어 있다', () => {
    const ds = mainLayout.data_sources.find((d: any) => d.id === 'commonInfos');
    expect(ds).toBeDefined();
    expect(ds!.errorHandling).toBeDefined();
    expect(ds!.errorHandling['403']).toEqual({
      handler: 'showErrorPage',
      params: { target: 'content' },
    });
    expect(ds!.errorHandling['default']).toEqual({
      handler: 'showErrorPage',
      params: { target: 'content' },
    });
  });
});

// ──────────────────────────────────────────────
// 2. 공통정보 추가 버튼 abilities 기반 disabled
// ──────────────────────────────────────────────

function createAddButtonLayout(canCreate: boolean) {
  return {
    version: '1.0.0',
    layout_name: `test_common_info_add_button_${canCreate}`,
    data_sources: [],
    state: {},
    global_state: {
      commonInfosData: {
        data: [],
        abilities: { can_create: canCreate, can_update: canCreate },
      },
    },
    components: [
      {
        id: 'add_common_info_button',
        type: 'basic',
        name: 'Button',
        props: {
          disabled: '{{_global.commonInfosData?.abilities?.can_create !== true}}',
          className: 'disabled:opacity-50 disabled:cursor-not-allowed',
          'data-testid': 'add-button',
        },
        text: '공통정보 추가',
      },
    ],
  };
}

describe('공통정보 - 추가 버튼 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('can_create=true → 버튼 활성화', async () => {
    const testUtils = createLayoutTest(createAddButtonLayout(true));
    await testUtils.render();
    expect((screen.getByTestId('add-button') as HTMLButtonElement).disabled).toBeFalsy();
    testUtils.cleanup();
  });

  it('can_create=false → 버튼 비활성화', async () => {
    const testUtils = createLayoutTest(createAddButtonLayout(false));
    await testUtils.render();
    expect((screen.getByTestId('add-button') as HTMLButtonElement).disabled).toBe(true);
    testUtils.cleanup();
  });
});

// ──────────────────────────────────────────────
// 3. 뷰 패널 - 편집/삭제/복사/기본값 설정 버튼
// ──────────────────────────────────────────────

function createViewPanelLayout(canUpdate: boolean, canDelete: boolean, collectionCanUpdate: boolean = true) {
  return {
    version: '1.0.0',
    layout_name: `test_common_info_view_panel_${canUpdate}_${canDelete}`,
    data_sources: [],
    global_state: {
      selectedId: 1,
      selectedItem: {
        id: 1,
        name: { ko: '테스트 공통정보' },
        abilities: { can_update: canUpdate, can_delete: canDelete },
      },
      panelMode: 'view',
      commonInfosData: {
        data: [],
        abilities: { can_create: true, can_update: collectionCanUpdate },
      },
    },
    state: {},
    components: [
      {
        id: 'view_buttons',
        type: 'basic',
        name: 'Div',
        children: [
          {
            id: 'copy_button',
            type: 'basic',
            name: 'Button',
            props: {
              disabled: '{{_global.selectedItem?.abilities?.can_update !== true}}',
              'data-testid': 'copy-button',
            },
            text: '복사',
          },
          {
            id: 'set_default_button',
            type: 'basic',
            name: 'Button',
            props: {
              disabled: '{{_global.commonInfosData?.abilities?.can_update !== true}}',
              'data-testid': 'set-default-button',
            },
            text: '기본값 설정',
          },
          {
            id: 'edit_button',
            type: 'basic',
            name: 'Button',
            props: {
              disabled: '{{_global.selectedItem?.abilities?.can_update !== true}}',
              'data-testid': 'edit-button',
            },
            text: '편집',
          },
          {
            id: 'delete_button',
            type: 'basic',
            name: 'Button',
            if: '{{_global.selectedItem?.abilities?.can_delete === true}}',
            props: {
              'data-testid': 'delete-button',
            },
            text: '삭제',
          },
        ],
      },
    ],
  };
}

describe('공통정보 - 뷰 패널 버튼 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('모든 권한 있음 → 모든 버튼 활성화/표시', async () => {
    const testUtils = createLayoutTest(createViewPanelLayout(true, true, true));
    await testUtils.render();

    expect((screen.getByTestId('copy-button') as HTMLButtonElement).disabled).toBeFalsy();
    expect((screen.getByTestId('set-default-button') as HTMLButtonElement).disabled).toBeFalsy();
    expect((screen.getByTestId('edit-button') as HTMLButtonElement).disabled).toBeFalsy();
    expect(screen.queryByTestId('delete-button')).not.toBeNull();

    testUtils.cleanup();
  });

  it('can_update=false → 복사/편집/기본값 버튼 비활성화', async () => {
    const testUtils = createLayoutTest(createViewPanelLayout(false, true, false));
    await testUtils.render();

    expect((screen.getByTestId('copy-button') as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByTestId('set-default-button') as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByTestId('edit-button') as HTMLButtonElement).disabled).toBe(true);

    testUtils.cleanup();
  });

  it('can_delete=false → 삭제 버튼 숨김', async () => {
    const testUtils = createLayoutTest(createViewPanelLayout(true, false, true));
    await testUtils.render();

    expect(screen.queryByTestId('delete-button')).toBeNull();

    testUtils.cleanup();
  });
});

// ──────────────────────────────────────────────
// 4. 폼 패널 - 수정 모드 disabled 제어
// ──────────────────────────────────────────────

const formPanelLayout = {
  version: '1.0.0',
  layout_name: 'test_common_info_form_panel',
  data_sources: [],
  global_state: {
    panelMode: 'edit',
    selectedItem: {
      id: 1,
      name: { ko: '테스트' },
      abilities: { can_update: false },
    },
  },
  state: {
    isSaving: false,
    form: {
      name: {},
      content: {},
      content_mode: 'text',
      is_default: false,
      is_active: true,
    },
  },
  components: [
    {
      id: 'form_panel',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'save_button',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: '{{_local.isSaving || (_global.panelMode === \'edit\' && _global.selectedItem?.abilities?.can_update !== true)}}',
            'data-testid': 'save-button',
          },
          text: '저장',
        },
        {
          id: 'name_input',
          type: 'composite',
          name: 'MultilingualInput',
          props: {
            disabled: '{{_global.panelMode === \'edit\' && _global.selectedItem?.abilities?.can_update !== true}}',
            'data-testid': 'name-input',
          },
        },
        {
          id: 'content_textarea',
          type: 'composite',
          name: 'Textarea',
          props: {
            disabled: '{{_global.panelMode === \'edit\' && _global.selectedItem?.abilities?.can_update !== true}}',
            'data-testid': 'content-textarea',
          },
        },
        {
          id: 'html_mode_checkbox',
          type: 'basic',
          name: 'Input',
          props: {
            type: 'checkbox',
            disabled: '{{_global.panelMode === \'edit\' && _global.selectedItem?.abilities?.can_update !== true}}',
            'data-testid': 'html-mode-checkbox',
          },
        },
        {
          id: 'is_default_toggle',
          type: 'composite',
          name: 'Toggle',
          props: {
            disabled: '{{_global.panelMode === \'edit\' && _global.selectedItem?.abilities?.can_update !== true}}',
            'data-testid': 'is-default-toggle',
          },
        },
        {
          id: 'is_active_toggle',
          type: 'composite',
          name: 'Toggle',
          props: {
            disabled: '{{_global.panelMode === \'edit\' && _global.selectedItem?.abilities?.can_update !== true}}',
            'data-testid': 'is-active-toggle',
          },
        },
      ],
    },
  ],
};

describe('공통정보 - 폼 패널 수정 모드 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('edit 모드 + can_update=false → 모든 입력 필드 비활성화', async () => {
    const testUtils = createLayoutTest(formPanelLayout);
    await testUtils.render();

    expect((screen.getByTestId('save-button') as any).disabled).toBe(true);
    expect((screen.getByTestId('name-input') as any).disabled).toBe(true);
    expect((screen.getByTestId('content-textarea') as any).disabled).toBe(true);
    expect((screen.getByTestId('html-mode-checkbox') as any).disabled).toBe(true);
    expect((screen.getByTestId('is-default-toggle') as any).disabled).toBe(true);
    expect((screen.getByTestId('is-active-toggle') as any).disabled).toBe(true);

    testUtils.cleanup();
  });

  it('edit 모드 + can_update=true → 모든 입력 필드 활성화', async () => {
    const layout = {
      ...formPanelLayout,
      layout_name: 'test_common_info_form_can_update',
      global_state: {
        ...formPanelLayout.global_state,
        selectedItem: {
          id: 1,
          name: { ko: '테스트' },
          abilities: { can_update: true },
        },
      },
    };
    const testUtils = createLayoutTest(layout);
    await testUtils.render();

    expect((screen.getByTestId('save-button') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('name-input') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('content-textarea') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('html-mode-checkbox') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('is-default-toggle') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('is-active-toggle') as any).disabled).toBeFalsy();

    testUtils.cleanup();
  });

  it('create 모드 → can_update 무관하게 활성화', async () => {
    const layout = {
      ...formPanelLayout,
      layout_name: 'test_common_info_form_create',
      global_state: {
        ...formPanelLayout.global_state,
        panelMode: 'create',
      },
    };
    const testUtils = createLayoutTest(layout);
    await testUtils.render();

    expect((screen.getByTestId('save-button') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('name-input') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('content-textarea') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('html-mode-checkbox') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('is-default-toggle') as any).disabled).toBeFalsy();
    expect((screen.getByTestId('is-active-toggle') as any).disabled).toBeFalsy();

    testUtils.cleanup();
  });

  // ──────────────────────────────────────────────
  // 5. 리스트 패널 - per-item Toggle disabled
  // ──────────────────────────────────────────────

  const listToggleLayout = {
    version: '1.0.0',
    layout_name: 'test_common_info_list_toggle',
    data_sources: [],
    state: {
      item: {
        id: 1,
        is_active: true,
        abilities: { can_update: false },
      },
    },
    components: [
      {
        id: 'list_toggle',
        type: 'composite',
        name: 'Toggle',
        props: {
          size: 'sm',
          checked: '{{_local.item.is_active}}',
          disabled: '{{!_local.item.abilities?.can_update}}',
          'data-testid': 'list-toggle',
        },
      },
    ],
  };

  it('per-item can_update=false → 리스트 Toggle 비활성화', async () => {
    const testUtils = createLayoutTest(listToggleLayout);
    await testUtils.render();

    expect((screen.getByTestId('list-toggle') as any).disabled).toBe(true);
    testUtils.cleanup();
  });

  it('per-item can_update=true → 리스트 Toggle 활성화', async () => {
    const layout = {
      ...listToggleLayout,
      layout_name: 'test_common_info_list_toggle_active',
      state: {
        item: {
          id: 1,
          is_active: true,
          abilities: { can_update: true },
        },
      },
    };
    const testUtils = createLayoutTest(layout);
    await testUtils.render();

    expect((screen.getByTestId('list-toggle') as any).disabled).toBeFalsy();
    testUtils.cleanup();
  });
});
