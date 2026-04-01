/**
 * @file admin-role-form-identifier.test.tsx
 * @description 역할 생성/수정 폼 - identifier 필드 렌더링 테스트
 *
 * 테스트 대상:
 * - identifier Input 필드가 렌더링되는지
 * - 생성 모드에서 identifier가 활성화(editable)되는지
 * - 수정 모드에서 identifier가 비활성화(disabled)되는지
 * - 힌트 텍스트가 모드에 따라 올바르게 표시되는지
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

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <label className={className}>{children || text}</label>
);

const TestInput: React.FC<{
  name?: string;
  type?: string;
  placeholder?: string;
  maxLength?: number;
  disabled?: boolean;
  error?: string;
  value?: string;
  'data-testid'?: string;
}> = ({ name, type, placeholder, disabled, value, 'data-testid': testId }) => (
  <input
    name={name}
    type={type}
    placeholder={placeholder}
    disabled={disabled}
    value={value ?? ''}
    data-testid={testId || `input-${name}`}
    readOnly
  />
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 테스트용 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// identifier 필드만 포함한 최소 레이아웃 JSON
const identifierFieldLayout = {
  version: '1.0.0',
  layout_name: 'admin_role_form_identifier_test',
  slots: {
    content: [
      {
        id: 'identifier_field',
        type: 'basic',
        name: 'Div',
        props: {
          className: 'space-y-1',
        },
        children: [
          {
            type: 'basic',
            name: 'Label',
            props: {
              className: 'block text-sm font-medium text-gray-700',
            },
            text: 'Identifier',
          },
          {
            id: 'identifier_input',
            type: 'basic',
            name: 'Input',
            props: {
              name: 'identifier',
              type: 'text',
              placeholder: 'e.g. content_manager',
              maxLength: 100,
              disabled: '{{!!route?.id}}',
            },
          },
          {
            type: 'basic',
            name: 'Span',
            if: '{{!route?.id}}',
            props: {
              className: 'text-gray-500 text-xs mt-1',
            },
            text: 'Only lowercase letters, numbers, and underscores allowed.',
          },
          {
            type: 'basic',
            name: 'Span',
            if: '{{!!route?.id}}',
            props: {
              className: 'text-gray-500 text-xs mt-1',
            },
            text: 'Identifier cannot be changed after creation.',
          },
        ],
      },
    ],
  },
};

describe('admin_role_form - identifier 필드 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  it('생성 모드에서 identifier Input이 활성화 상태로 렌더링된다', async () => {
    const testUtils = createLayoutTest(identifierFieldLayout, {
      componentRegistry: registry,
      routeParams: {},
    });

    await testUtils.render();

    const input = screen.getByTestId('input-identifier');
    expect(input).toBeInTheDocument();
    expect(input).not.toBeDisabled();

    testUtils.cleanup();
  });

  it('수정 모드에서 identifier Input이 비활성화 상태로 렌더링된다', async () => {
    const testUtils = createLayoutTest(identifierFieldLayout, {
      componentRegistry: registry,
      routeParams: { id: '5' },
    });

    await testUtils.render();

    const input = screen.getByTestId('input-identifier');
    expect(input).toBeInTheDocument();
    expect(input).toBeDisabled();

    testUtils.cleanup();
  });

  it('생성 모드에서 생성 힌트 텍스트가 표시된다', async () => {
    const testUtils = createLayoutTest(identifierFieldLayout, {
      componentRegistry: registry,
      routeParams: {},
    });

    await testUtils.render();

    expect(screen.getByText('Only lowercase letters, numbers, and underscores allowed.')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('수정 모드에서 수정 불가 힌트 텍스트가 표시된다', async () => {
    const testUtils = createLayoutTest(identifierFieldLayout, {
      componentRegistry: registry,
      routeParams: { id: '5' },
    });

    await testUtils.render();

    expect(screen.getByText('Identifier cannot be changed after creation.')).toBeInTheDocument();

    testUtils.cleanup();
  });
});
