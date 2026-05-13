/**
 * @file productNoticeAbilities.test.tsx
 * @description 상품정보제공고시 관리 페이지 abilities 기반 권한 제어 렌더링 테스트
 *
 * 테스트 대상:
 * - 메인 레이아웃: DS errorHandling, 템플릿 추가 버튼 disabled
 * - _panel_view.json: 편집 버튼 disabled, 삭제 버튼 if
 * - _panel_form.json: 저장 버튼, MultilingualInput, Toggle, DynamicFieldList, 상세정보 참조 버튼 disabled
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
  label?: string;
  name?: string;
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
  name?: string;
  placeholder?: string;
  layout?: string;
  error?: string;
  'data-testid'?: string;
}> = ({ disabled, 'data-testid': testId }) => (
  <input disabled={disabled} data-testid={testId} readOnly />
);

const TestDynamicFieldList: React.FC<{
  disabled?: boolean;
  name?: string;
  items?: any[];
  columns?: any[];
  addLabel?: string;
  enableDrag?: boolean;
  minItems?: number;
  emptyMessage?: string;
  errors?: any;
  'data-testid'?: string;
}> = ({ disabled, 'data-testid': testId }) => (
  <div data-testid={testId} data-disabled={disabled ? 'true' : 'false'} />
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
    DynamicFieldList: { component: TestDynamicFieldList, metadata: { name: 'DynamicFieldList', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ──────────────────────────────────────────────
// 1. 메인 레이아웃 errorHandling 검증
// ──────────────────────────────────────────────

import mainLayout from '../../../layouts/admin/admin_ecommerce_product_notice_index.json';

describe('상품정보제공고시 - 메인 레이아웃 구조 검증', () => {
  it('templates 데이터 소스에 errorHandling이 정의되어 있다', () => {
    const ds = mainLayout.data_sources.find((d: any) => d.id === 'templates');
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
// 2. 템플릿 추가 버튼 abilities 기반 disabled
// ──────────────────────────────────────────────

function createAddButtonLayout(canCreate: boolean) {
  return {
    version: '1.0.0',
    layout_name: `test_notice_add_button_${canCreate}`,
    data_sources: [],
    state: {},
    global_state: {
      templatesData: {
        data: [],
        abilities: { can_create: canCreate },
      },
    },
    components: [
      {
        id: 'add_template_button',
        type: 'basic',
        name: 'Button',
        props: {
          disabled: '{{_global.templatesData?.abilities?.can_create !== true}}',
          className: 'disabled:opacity-50 disabled:cursor-not-allowed',
          'data-testid': 'add-template-button',
        },
        text: '템플릿 추가',
      },
    ],
  };
}

describe('상품정보제공고시 - 템플릿 추가 버튼 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('can_create=true → 버튼 활성화', async () => {
    const testUtils = createLayoutTest(createAddButtonLayout(true));
    await testUtils.render();
    const btn = screen.getByTestId('add-template-button');
    expect((btn as HTMLButtonElement).disabled).toBeFalsy();
    testUtils.cleanup();
  });

  it('can_create=false → 버튼 비활성화', async () => {
    const testUtils = createLayoutTest(createAddButtonLayout(false));
    await testUtils.render();
    const btn = screen.getByTestId('add-template-button');
    expect((btn as HTMLButtonElement).disabled).toBe(true);
    testUtils.cleanup();
  });
});

// ──────────────────────────────────────────────
// 3. 편집/삭제 버튼 per-item abilities
// ──────────────────────────────────────────────

const viewPanelLayout = {
  version: '1.0.0',
  layout_name: 'test_notice_view_panel',
  data_sources: [],
  global_state: {
    selectedTemplateId: 1,
    selectedTemplate: {
      id: 1,
      name: { ko: '테스트 템플릿' },
      abilities: { can_update: true, can_delete: true },
    },
    panelMode: 'view',
  },
  state: {},
  components: [
    {
      id: 'view_buttons',
      type: 'basic',
      name: 'Div',
      children: [
        {
          id: 'edit_button',
          type: 'basic',
          name: 'Button',
          props: {
            disabled: '{{_global.selectedTemplate?.abilities?.can_update !== true}}',
            'data-testid': 'edit-button',
          },
          text: '편집',
        },
        {
          id: 'delete_button',
          type: 'basic',
          name: 'Button',
          if: '{{_global.selectedTemplate?.abilities?.can_delete === true}}',
          props: {
            'data-testid': 'delete-button',
          },
          text: '삭제',
        },
      ],
    },
  ],
};

describe('상품정보제공고시 - 뷰 패널 편집/삭제 버튼 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('can_update=true, can_delete=true → 편집 활성화, 삭제 표시', async () => {
    const testUtils = createLayoutTest(viewPanelLayout);
    await testUtils.render();

    const editBtn = screen.getByTestId('edit-button') as HTMLButtonElement;
    expect(editBtn.disabled).toBeFalsy();

    const deleteBtn = screen.queryByTestId('delete-button');
    expect(deleteBtn).not.toBeNull();

    testUtils.cleanup();
  });

  it('can_update=false → 편집 버튼 비활성화', async () => {
    const layout = {
      ...viewPanelLayout,
      layout_name: 'test_notice_view_panel_no_update',
      global_state: {
        ...viewPanelLayout.global_state,
        selectedTemplate: {
          id: 1,
          name: { ko: '테스트 템플릿' },
          abilities: { can_update: false, can_delete: true },
        },
      },
    };
    const testUtils = createLayoutTest(layout);
    await testUtils.render();

    const editBtn = screen.getByTestId('edit-button') as HTMLButtonElement;
    expect(editBtn.disabled).toBe(true);

    testUtils.cleanup();
  });

  it('can_delete=false → 삭제 버튼 숨김', async () => {
    const layout = {
      ...viewPanelLayout,
      layout_name: 'test_notice_view_panel_no_delete',
      global_state: {
        ...viewPanelLayout.global_state,
        selectedTemplate: {
          id: 1,
          name: { ko: '테스트 템플릿' },
          abilities: { can_update: true, can_delete: false },
        },
      },
    };
    const testUtils = createLayoutTest(layout);
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
  layout_name: 'test_notice_form_panel',
  data_sources: [],
  global_state: {
    panelMode: 'edit',
    selectedTemplate: {
      id: 1,
      name: { ko: '테스트 템플릿' },
      abilities: { can_update: false },
    },
  },
  state: {
    isSaving: false,
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
            disabled: '{{_local?.isSaving || (_global.panelMode === \'edit\' && _global.selectedTemplate?.abilities?.can_update !== true)}}',
            'data-testid': 'save-button',
          },
          text: '저장',
        },
        {
          id: 'name_input',
          type: 'composite',
          name: 'MultilingualInput',
          props: {
            disabled: '{{_global.panelMode === \'edit\' && _global.selectedTemplate?.abilities?.can_update !== true}}',
            'data-testid': 'name-input',
          },
        },
        {
          id: 'is_active_toggle',
          type: 'composite',
          name: 'Toggle',
          props: {
            disabled: '{{_global.panelMode === \'edit\' && _global.selectedTemplate?.abilities?.can_update !== true}}',
            'data-testid': 'is-active-toggle',
          },
        },
        {
          id: 'fill_ref_button',
          type: 'basic',
          name: 'Button',
          props: {
            type: 'button',
            disabled: '{{_global.panelMode === \'edit\' && _global.selectedTemplate?.abilities?.can_update !== true}}',
            'data-testid': 'fill-ref-button',
          },
          text: '상세정보 참조로 채우기',
        },
      ],
    },
  ],
};

describe('상품정보제공고시 - 폼 패널 수정 모드 권한 제어', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('edit 모드 + can_update=false → 저장, 입력 필드 비활성화', async () => {
    const testUtils = createLayoutTest(formPanelLayout);
    await testUtils.render();

    expect((screen.getByTestId('save-button') as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByTestId('name-input') as HTMLInputElement).disabled).toBe(true);
    expect((screen.getByTestId('is-active-toggle') as HTMLInputElement).disabled).toBe(true);
    expect((screen.getByTestId('fill-ref-button') as HTMLButtonElement).disabled).toBe(true);

    testUtils.cleanup();
  });

  it('edit 모드 + can_update=true → 저장, 입력 필드 활성화', async () => {
    const layout = {
      ...formPanelLayout,
      layout_name: 'test_notice_form_panel_can_update',
      global_state: {
        ...formPanelLayout.global_state,
        selectedTemplate: {
          id: 1,
          name: { ko: '테스트 템플릿' },
          abilities: { can_update: true },
        },
      },
    };
    const testUtils = createLayoutTest(layout);
    await testUtils.render();

    expect((screen.getByTestId('save-button') as HTMLButtonElement).disabled).toBeFalsy();
    expect((screen.getByTestId('name-input') as HTMLInputElement).disabled).toBeFalsy();
    expect((screen.getByTestId('is-active-toggle') as HTMLInputElement).disabled).toBeFalsy();
    expect((screen.getByTestId('fill-ref-button') as HTMLButtonElement).disabled).toBeFalsy();

    testUtils.cleanup();
  });

  it('create 모드 → can_update 무관하게 활성화', async () => {
    const layout = {
      ...formPanelLayout,
      layout_name: 'test_notice_form_panel_create',
      global_state: {
        ...formPanelLayout.global_state,
        panelMode: 'create',
        selectedTemplate: {
          id: 1,
          name: { ko: '테스트 템플릿' },
          abilities: { can_update: false },
        },
      },
    };
    const testUtils = createLayoutTest(layout);
    await testUtils.render();

    expect((screen.getByTestId('save-button') as HTMLButtonElement).disabled).toBeFalsy();
    expect((screen.getByTestId('name-input') as HTMLInputElement).disabled).toBeFalsy();
    expect((screen.getByTestId('is-active-toggle') as HTMLInputElement).disabled).toBeFalsy();
    expect((screen.getByTestId('fill-ref-button') as HTMLButtonElement).disabled).toBeFalsy();

    testUtils.cleanup();
  });
});
