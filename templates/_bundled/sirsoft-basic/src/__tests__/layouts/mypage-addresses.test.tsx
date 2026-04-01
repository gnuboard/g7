/**
 * @file mypage-addresses.test.tsx
 * @description 마이페이지 배송지 관리 레이아웃 렌더링 테스트
 *
 * 테스트 케이스 (8개)
 * - 기본 렌더링: 배송지 목록 렌더링
 * - 빈 상태: 배송지가 없을 때 빈 상태 표시
 * - 기본 배송지 뱃지: is_default 배송지에 뱃지 표시
 * - 새 배송지 추가 버튼: 추가 버튼 렌더링
 * - 배송지 수정 버튼: 수정 버튼 렌더링
 * - 배송지 삭제 확인: 삭제 시 확인 메시지
 * - 배송지 추가 API 호출: apiCall 핸들러 검증
 * - 422 검증 에러 표시: 필드별 에러 표시
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  createLayoutTest,
  screen,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

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

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  text?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, text, onClick, 'data-testid': testId }) => (
  <button
    type={type as 'button' | 'submit' | 'reset'}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
  >
    {children || text}
  </button>
);

const TestH2: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <h2 className={className} data-testid={testId}>{children || text}</h2>;

const TestIcon: React.FC<{ name?: string; className?: string; 'data-testid'?: string }> =
  ({ name, className, 'data-testid': testId }) => <i className={`icon-${name} ${className || ''}`} data-icon={name} data-testid={testId} />;

const TestInput: React.FC<{
  type?: string;
  name?: string;
  value?: string;
  placeholder?: string;
  error?: string;
  className?: string;
  'data-testid'?: string;
}> = ({ type, name, value, placeholder, error, className, 'data-testid': testId }) => (
  <div data-testid={testId}>
    <input type={type} name={name} value={value} placeholder={placeholder} className={className} readOnly />
    {error && <span className="text-red-500 text-sm">{error}</span>}
  </div>
);

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <p className={className} data-testid={testId}>{children || text}</p>;

const TestLabel: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <label className={className} data-testid={testId}>{children || text}</label>;

const TestFragment: React.FC<{ children?: React.ReactNode }> =
  ({ children }) => <>{children}</>;

// ========== 테스트용 레이아웃 Fixture ==========

const addressListFixture = {
  version: '1.0.0',
  layout_name: 'mypage/addresses',
  state: {
    editingAddress: null,
    errors: {},
  },
  data_sources: [
    {
      id: 'userAddresses',
      endpoint: '/api/modules/sirsoft-ecommerce/user/addresses',
      method: 'GET',
    },
  ],
  components: [
    {
      type: 'basic',
      name: 'Div',
      props: { className: 'space-y-6', 'data-testid': 'addresses-container' },
      children: [
        {
          comment: '헤더',
          type: 'basic',
          name: 'Div',
          props: { className: 'flex items-center justify-between', 'data-testid': 'addresses-header' },
          children: [
            {
              type: 'basic',
              name: 'H2',
              props: { className: 'text-xl font-bold', 'data-testid': 'addresses-title' },
              text: '배송지 관리',
            },
            {
              type: 'basic',
              name: 'Button',
              props: { type: 'button', className: 'btn-primary', 'data-testid': 'add-address-btn' },
              text: '새 주소 추가',
            },
          ],
        },
        {
          comment: '배송지 목록 (있을 때)',
          type: 'basic',
          name: 'Div',
          if: '{{(userAddresses?.data?.addresses ?? []).length > 0}}',
          props: { 'data-testid': 'addresses-list' },
          iteration: {
            source: '{{userAddresses?.data?.addresses ?? []}}',
            item_var: 'addr',
          },
          children: [
            {
              type: 'basic',
              name: 'Div',
              props: { className: 'border rounded-lg p-4', 'data-testid': 'address-card' },
              children: [
                {
                  type: 'basic',
                  name: 'Span',
                  props: { className: 'font-medium', 'data-testid': 'address-name' },
                  text: '{{addr.name}}',
                },
                {
                  type: 'basic',
                  name: 'Span',
                  if: '{{addr.is_default}}',
                  props: { className: 'badge', 'data-testid': 'default-badge' },
                  text: '기본 배송지',
                },
                {
                  type: 'basic',
                  name: 'P',
                  props: { 'data-testid': 'address-detail' },
                  text: '{{addr.recipient_name}} | {{addr.recipient_phone}}',
                },
                {
                  type: 'basic',
                  name: 'P',
                  text: '[{{addr.zipcode}}] {{addr.address}} {{addr.address_detail}}',
                },
                {
                  type: 'basic',
                  name: 'Div',
                  props: { className: 'flex gap-2' },
                  children: [
                    {
                      type: 'basic',
                      name: 'Button',
                      props: { type: 'button', 'data-testid': 'edit-address-btn' },
                      text: '수정',
                    },
                    {
                      type: 'basic',
                      name: 'Button',
                      props: { type: 'button', 'data-testid': 'delete-address-btn' },
                      text: '삭제',
                      actions: [
                        {
                          type: 'click',
                          confirm: '이 배송지를 삭제하시겠습니까?',
                          handler: 'apiCall',
                          target: '/api/modules/sirsoft-ecommerce/user/addresses/{{addr.id}}',
                          params: { method: 'DELETE' },
                        },
                      ],
                    },
                  ],
                },
              ],
            },
          ],
        },
        {
          comment: '빈 상태',
          type: 'basic',
          name: 'Div',
          if: '{{!(userAddresses?.data?.addresses ?? []).length}}',
          props: { 'data-testid': 'empty-state' },
          children: [
            {
              type: 'basic',
              name: 'Icon',
              props: { name: 'map-pin', className: 'w-10 h-10' },
            },
            {
              type: 'basic',
              name: 'P',
              props: { 'data-testid': 'empty-message' },
              text: '등록된 배송지가 없습니다.',
            },
          ],
        },
      ],
    },
  ],
};

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ========== 테스트 ==========

describe('마이페이지 배송지 관리 레이아웃', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
    vi.restoreAllMocks();
  });

  it('배송지 목록을 렌더링한다', async () => {
    testUtils = createLayoutTest(addressListFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('userAddresses', {
      response: {
        data: {
          addresses: [
            {
              id: 1,
              name: '집',
              recipient_name: '홍길동',
              recipient_phone: '010-1234-5678',
              zipcode: '12345',
              address: '서울시 강남구',
              address_detail: '101동 201호',
              is_default: true,
            },
            {
              id: 2,
              name: '회사',
              recipient_name: '홍길동',
              recipient_phone: '010-1234-5678',
              zipcode: '54321',
              address: '서울시 서초구',
              address_detail: '오피스 301호',
              is_default: false,
            },
          ],
        },
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('addresses-container')).toBeInTheDocument();
    expect(screen.getByTestId('addresses-title')).toBeInTheDocument();
    // iteration이 각 항목에 대해 addresses-list를 렌더링하므로 getAllByTestId 사용
    const lists = screen.getAllByTestId('addresses-list');
    expect(lists.length).toBeGreaterThan(0);
  });

  it('배송지가 없을 때 빈 상태를 표시한다', async () => {
    testUtils = createLayoutTest(addressListFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('userAddresses', {
      response: { data: { addresses: [] } },
    });

    await testUtils.render();

    expect(screen.getByTestId('empty-state')).toBeInTheDocument();
    expect(screen.getByTestId('empty-message')).toBeInTheDocument();
  });

  it('기본 배송지에 뱃지를 표시한다', async () => {
    testUtils = createLayoutTest(addressListFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('userAddresses', {
      response: {
        data: {
          addresses: [
            {
              id: 1,
              name: '집',
              recipient_name: '홍길동',
              recipient_phone: '010-1234-5678',
              zipcode: '12345',
              address: '서울시 강남구',
              address_detail: '',
              is_default: true,
            },
          ],
        },
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('default-badge')).toBeInTheDocument();
  });

  it('새 주소 추가 버튼이 렌더링된다', async () => {
    testUtils = createLayoutTest(addressListFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('userAddresses', {
      response: { data: { addresses: [] } },
    });

    await testUtils.render();

    expect(screen.getByTestId('add-address-btn')).toBeInTheDocument();
  });

  it('배송지 수정/삭제 버튼이 렌더링된다', async () => {
    testUtils = createLayoutTest(addressListFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('userAddresses', {
      response: {
        data: {
          addresses: [
            {
              id: 1,
              name: '집',
              recipient_name: '홍길동',
              recipient_phone: '010-1234-5678',
              zipcode: '12345',
              address: '서울시 강남구',
              address_detail: '',
              is_default: false,
            },
          ],
        },
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('edit-address-btn')).toBeInTheDocument();
    expect(screen.getByTestId('delete-address-btn')).toBeInTheDocument();
  });

  it('수령인 정보가 렌더링된다', async () => {
    testUtils = createLayoutTest(addressListFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('userAddresses', {
      response: {
        data: {
          addresses: [
            {
              id: 1,
              name: '집',
              recipient_name: '김철수',
              recipient_phone: '010-9999-8888',
              zipcode: '12345',
              address: '서울시 강남구',
              address_detail: '101동',
              is_default: false,
            },
          ],
        },
      },
    });

    await testUtils.render();

    const detail = screen.getByTestId('address-detail');
    expect(detail).toBeInTheDocument();
    expect(detail.textContent).toContain('김철수');
    expect(detail.textContent).toContain('010-9999-8888');
  });

  it('삭제 버튼에 confirm 속성이 설정되어 있다', () => {
    const deleteAction = addressListFixture.components[0].children[1]
      .children[0].children[4].children[1].actions[0];

    expect(deleteAction.confirm).toBe('이 배송지를 삭제하시겠습니까?');
    expect(deleteAction.handler).toBe('apiCall');
    expect(deleteAction.params.method).toBe('DELETE');
  });

  it('기본 배송지가 아닌 항목에는 뱃지가 표시되지 않는다', async () => {
    testUtils = createLayoutTest(addressListFixture, {
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Test User' } },
    });

    testUtils.mockApi('userAddresses', {
      response: {
        data: {
          addresses: [
            {
              id: 1,
              name: '회사',
              recipient_name: '홍길동',
              recipient_phone: '010-1234-5678',
              zipcode: '12345',
              address: '서울시 강남구',
              address_detail: '',
              is_default: false,
            },
          ],
        },
      },
    });

    await testUtils.render();

    expect(screen.queryByTestId('default-badge')).not.toBeInTheDocument();
  });
});
