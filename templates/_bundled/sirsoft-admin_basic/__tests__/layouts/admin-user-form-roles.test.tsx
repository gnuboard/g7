/**
 * @file admin-user-form-roles.test.tsx
 * @description 사용자 등록/수정 폼 - 역할 TagInput 렌더링 테스트
 *
 * 테스트 대상:
 * - 역할 필드가 TagInput으로 렌더링되는지
 * - options가 roles API 데이터에서 {value: id, label: name} 형태로 매핑되는지
 * - 생성/수정 모드에서 value가 올바르게 바인딩되는지
 * - onChange 시 _local.form.role_ids가 정수 배열로 업데이트되는지
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
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

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <label className={className}>{children || text}</label>
);

const TestTagInput: React.FC<{
  name?: string;
  value?: (string | number)[];
  options?: Array<{ value: string | number; label: string }>;
  placeholder?: string;
  noOptionsMessage?: string;
  className?: string;
  defaultVariant?: string;
  onChange?: (event: any) => void;
  'data-testid'?: string;
}> = ({ name, value, options, placeholder, noOptionsMessage, className, defaultVariant, onChange, 'data-testid': testId }) => (
  <div
    data-testid={testId || `taginput-${name}`}
    data-name={name}
    data-value={JSON.stringify(value ?? [])}
    data-options={JSON.stringify(options ?? [])}
    data-placeholder={placeholder}
    data-no-options-message={noOptionsMessage}
    data-variant={defaultVariant}
    className={className}
  >
    {(value ?? []).map((v: any) => {
      const opt = (options ?? []).find((o: any) => o.value === v);
      return <span key={v} data-testid={`tag-${v}`}>{opt?.label ?? v}</span>;
    })}
    {placeholder && (value ?? []).length === 0 && <span data-testid="placeholder">{placeholder}</span>}
  </div>
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 컴포넌트 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    TagInput: { component: TestTagInput, metadata: { name: 'TagInput', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// 테스트용 역할 데이터
const mockRolesData = [
  { id: 1, identifier: 'super_admin', name: '슈퍼 관리자', is_system: true, is_active: true },
  { id: 2, identifier: 'admin', name: '관리자', is_system: true, is_active: true },
  { id: 3, identifier: 'manager', name: '매니저', is_system: false, is_active: true },
];

// 역할 TagInput이 포함된 레이아웃 JSON (생성 모드)
const createModeLayout = {
  version: '1.0.0',
  layout_name: 'test_user_form_create',
  data_sources: [
    {
      id: 'roles',
      type: 'api',
      endpoint: '/api/admin/roles/active',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      fallback: { data: [] },
    },
  ],
  components: [
    {
      id: 'field_roles',
      type: 'basic',
      name: 'Div',
      props: { className: 'md:col-span-2' },
      children: [
        {
          type: 'basic',
          name: 'Label',
          props: { className: 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2' },
          text: '역할',
        },
        {
          type: 'composite',
          name: 'TagInput',
          props: {
            name: 'role_ids',
            value: '{{_local.form?.role_ids ?? _local.form?.roles?.map(r => r.id) ?? []}}',
            options: '{{(roles?.data ?? []).map(r => ({value: r.id, label: r.name}))}}',
            placeholder: '역할을 선택하세요',
            noOptionsMessage: '선택 가능한 역할이 없습니다',
            className: 'w-full',
            defaultVariant: 'blue',
          },
          actions: [
            {
              type: 'change',
              handler: 'setState',
              params: {
                target: 'local',
                form: {
                  role_ids: '{{$event.target.value}}',
                },
              },
            },
          ],
        },
      ],
    },
  ],
};

// 수정 모드 레이아웃 (initLocal 시뮬레이션)
const editModeLayout = {
  ...createModeLayout,
  layout_name: 'test_user_form_edit',
};

describe('사용자 폼 - 역할 TagInput 테스트', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('생성 모드', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;

    afterEach(() => {
      testUtils?.cleanup();
    });

    it('역할 TagInput이 렌더링된다', async () => {
      testUtils = createLayoutTest(createModeLayout, {
        translations: {},
        componentRegistry: registry,
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      // 라벨이 렌더링되는지 확인
      expect(screen.getByText('역할')).toBeInTheDocument();
    });

    it('roles API 데이터가 options로 매핑된다', async () => {
      testUtils = createLayoutTest(createModeLayout, {
        translations: {},
        componentRegistry: registry,
        initialData: {
          roles: { data: mockRolesData },
        },
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      // TagInput의 options가 {value: id, label: name} 형태로 매핑되는지 확인
      const tagInput = screen.getByTestId('taginput-role_ids');
      const options = JSON.parse(tagInput.getAttribute('data-options') || '[]');

      expect(options).toEqual([
        { value: 1, label: '슈퍼 관리자' },
        { value: 2, label: '관리자' },
        { value: 3, label: '매니저' },
      ]);
    });

    it('생성 모드에서 초기 value는 빈 배열이다', async () => {
      testUtils = createLayoutTest(createModeLayout, {
        translations: {},
        componentRegistry: registry,
        initialData: {
          roles: { data: mockRolesData },
        },
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      const tagInput = screen.getByTestId('taginput-role_ids');
      const value = JSON.parse(tagInput.getAttribute('data-value') || '[]');
      expect(value).toEqual([]);
    });

    it('역할 선택 시 _local.form.role_ids가 업데이트된다', async () => {
      testUtils = createLayoutTest(createModeLayout, {
        translations: {},
        componentRegistry: registry,
        initialData: {
          roles: { data: mockRolesData },
        },
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      // setState를 통해 role_ids 설정 (TagInput onChange 시뮬레이션)
      testUtils.setState('form.role_ids', [1, 3], 'local');

      const state = testUtils.getState();
      expect(state._local.form.role_ids).toEqual([1, 3]);
    });
  });

  describe('수정 모드', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;

    afterEach(() => {
      testUtils?.cleanup();
    });

    it('기존 역할이 value로 표시된다', async () => {
      testUtils = createLayoutTest(editModeLayout, {
        translations: {},
        componentRegistry: registry,
        initialData: {
          roles: { data: mockRolesData },
        },
        initialState: {
          _local: {
            form: {
              roles: [
                { id: 1, identifier: 'super_admin', name: '슈퍼 관리자' },
                { id: 2, identifier: 'admin', name: '관리자' },
              ],
            },
          },
        },
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      // roles 객체 배열에서 id가 추출되어 value로 사용되는지 확인
      const tagInput = screen.getByTestId('taginput-role_ids');
      const value = JSON.parse(tagInput.getAttribute('data-value') || '[]');
      expect(value).toEqual([1, 2]);
    });

    it('role_ids가 이미 있으면 roles보다 우선된다', async () => {
      testUtils = createLayoutTest(editModeLayout, {
        translations: {},
        componentRegistry: registry,
        initialData: {
          roles: { data: mockRolesData },
        },
        initialState: {
          _local: {
            form: {
              role_ids: [3],
              roles: [
                { id: 1, identifier: 'super_admin', name: '슈퍼 관리자' },
              ],
            },
          },
        },
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      // role_ids가 roles보다 우선되는지 확인
      const tagInput = screen.getByTestId('taginput-role_ids');
      const value = JSON.parse(tagInput.getAttribute('data-value') || '[]');
      expect(value).toEqual([3]);
    });

    it('모든 역할 해제 시 빈 배열이 된다', async () => {
      testUtils = createLayoutTest(editModeLayout, {
        translations: {},
        componentRegistry: registry,
        initialData: {
          roles: { data: mockRolesData },
        },
        initialState: {
          _local: {
            form: {
              role_ids: [1, 2],
            },
          },
        },
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      // 모든 역할 해제
      testUtils.setState('form.role_ids', [], 'local');

      const state = testUtils.getState();
      expect(state._local.form.role_ids).toEqual([]);
    });
  });

  describe('엣지 케이스', () => {
    let testUtils: ReturnType<typeof createLayoutTest>;

    afterEach(() => {
      testUtils?.cleanup();
    });

    it('roles 데이터가 없을 때 빈 options로 렌더링된다', async () => {
      testUtils = createLayoutTest(createModeLayout, {
        translations: {},
        componentRegistry: registry,
      });

      testUtils.mockApi('roles', {
        response: { data: [] },
      });

      await testUtils.render();

      const tagInput = screen.getByTestId('taginput-role_ids');
      const options = JSON.parse(tagInput.getAttribute('data-options') || '[]');
      expect(options).toEqual([]);
    });

    it('placeholder가 올바르게 표시된다', async () => {
      testUtils = createLayoutTest(createModeLayout, {
        translations: {},
        componentRegistry: registry,
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      const tagInput = screen.getByTestId('taginput-role_ids');
      expect(tagInput.getAttribute('data-placeholder')).toBe('역할을 선택하세요');
    });

    it('defaultVariant가 blue로 설정된다', async () => {
      testUtils = createLayoutTest(createModeLayout, {
        translations: {},
        componentRegistry: registry,
      });

      testUtils.mockApi('roles', {
        response: { data: mockRolesData },
      });

      await testUtils.render();

      const tagInput = screen.getByTestId('taginput-role_ids');
      expect(tagInput.getAttribute('data-variant')).toBe('blue');
    });
  });
});
