/**
 * @file formfield-error-border.test.tsx
 * @description FormField 컴포넌트 에러 시 적색 테두리 자동 적용 테스트
 *
 * 테스트 대상:
 * - FormField에 error prop이 있을 때 form-field-error 클래스가 적용되는지
 * - FormField에 error prop이 없을 때 form-field-error 클래스가 없는지
 * - 유효성 검증 실패 시 에러 메시지가 표시되는지
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { FormField } from '../../src/components/composite/FormField';

// 테스트용 기본 컴포넌트
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
}> = ({ className, children }) => (
  <label className={className}>{children}</label>
);

const TestInput: React.FC<{
  type?: string;
  name?: string;
  className?: string;
  value?: string;
  'data-testid'?: string;
}> = ({ type, name, className, value, 'data-testid': testId }) => (
  <input type={type} name={name} className={className} value={value ?? ''} readOnly data-testid={testId || `input-${name}`} />
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
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    FormField: { component: FormField, metadata: { name: 'FormField', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// 에러가 있는 폼 레이아웃
const layoutWithErrors = {
  version: '1.0.0',
  layout_name: 'test_formfield_error',
  state: {
    form: { name: '' },
    errors: { name: ['쿠폰명은 필수입니다.'] },
  },
  components: [
    {
      id: 'field_with_error',
      type: 'composite',
      name: 'FormField',
      props: {
        label: '쿠폰명',
        required: true,
        error: "{{_local.errors?.name?.[0] ?? ''}}",
        'data-testid': 'formfield-with-error',
      },
      children: [
        {
          type: 'basic',
          name: 'Input',
          props: {
            name: 'name',
            className: 'input w-full',
            'data-testid': 'input-name',
          },
        },
      ],
    },
    {
      id: 'field_without_error',
      type: 'composite',
      name: 'FormField',
      props: {
        label: '설명',
        error: "{{_local.errors?.description?.[0] ?? ''}}",
        'data-testid': 'formfield-without-error',
      },
      children: [
        {
          type: 'basic',
          name: 'Input',
          props: {
            name: 'description',
            className: 'input w-full',
            'data-testid': 'input-description',
          },
        },
      ],
    },
  ],
};

// 에러가 없는 폼 레이아웃
const layoutWithoutErrors = {
  version: '1.0.0',
  layout_name: 'test_formfield_no_error',
  state: {
    form: { name: '테스트 쿠폰' },
    errors: null,
  },
  components: [
    {
      id: 'field_normal',
      type: 'composite',
      name: 'FormField',
      props: {
        label: '쿠폰명',
        required: true,
        error: "{{_local.errors?.name?.[0] ?? ''}}",
        'data-testid': 'formfield-normal',
      },
      children: [
        {
          type: 'basic',
          name: 'Input',
          props: {
            name: 'name',
            className: 'input w-full',
            'data-testid': 'input-name-normal',
          },
        },
      ],
    },
  ],
};

describe('FormField 에러 시 적색 테두리 자동 적용', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  afterEach(() => {
    testUtils?.cleanup();
  });

  describe('에러가 있는 필드', () => {
    beforeEach(() => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(layoutWithErrors, { componentRegistry: registry });
    });

    it('에러가 있는 FormField에 form-field-error 클래스가 적용된다', async () => {
      await testUtils.render();

      // form-field-error 클래스가 존재하는지 확인
      const container = document.querySelector('.form-field-error');
      expect(container).not.toBeNull();
    });

    it('에러 메시지 텍스트가 표시된다', async () => {
      await testUtils.render();

      const errorText = screen.getByText('쿠폰명은 필수입니다.');
      expect(errorText).toBeInTheDocument();
    });

    it('에러가 없는 FormField에는 form-field-error 클래스가 없다', async () => {
      await testUtils.render();

      // input-description의 부모 FormField에는 form-field-error가 없어야 함
      const descriptionInput = screen.getByTestId('input-description');
      const parentDiv = descriptionInput.closest('.form-field-error');
      expect(parentDiv).toBeNull();
    });
  });

  describe('에러가 없는 필드', () => {
    beforeEach(() => {
      const registry = setupTestRegistry();
      testUtils = createLayoutTest(layoutWithoutErrors, { componentRegistry: registry });
    });

    it('에러가 null일 때 form-field-error 클래스가 적용되지 않는다', async () => {
      await testUtils.render();

      const errorContainer = document.querySelector('.form-field-error');
      expect(errorContainer).toBeNull();
    });

    it('에러 메시지가 표시되지 않는다', async () => {
      await testUtils.render();

      const errorText = screen.queryByText('쿠폰명은 필수입니다.');
      expect(errorText).toBeNull();
    });
  });

  describe('에러 유무에 따른 클래스 차이', () => {
    it('에러가 있는 레이아웃과 없는 레이아웃의 form-field-error 클래스 적용이 다르다', async () => {
      // 에러 있는 레이아웃
      const registry1 = setupTestRegistry();
      const utils1 = createLayoutTest(layoutWithErrors, { componentRegistry: registry1 });
      await utils1.render();
      expect(document.querySelector('.form-field-error')).not.toBeNull();
      utils1.cleanup();

      // 에러 없는 레이아웃
      const registry2 = setupTestRegistry();
      const utils2 = createLayoutTest(layoutWithoutErrors, { componentRegistry: registry2 });
      testUtils = utils2;
      await utils2.render();
      expect(document.querySelector('.form-field-error')).toBeNull();
    });
  });
});
