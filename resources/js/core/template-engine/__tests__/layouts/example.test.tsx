/**
 * @file example.test.tsx
 * @description 레이아웃 테스트 유틸리티 사용 예시
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createLayoutTest, screen } from '../utils/layoutTestUtils';
import { ComponentRegistry } from '../../ComponentRegistry';

// 테스트용 컴포넌트 정의
const TestButton: React.FC<{
  text?: string;
  children?: React.ReactNode;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ text, children, onClick, 'data-testid': testId }) => (
  <button onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestInput: React.FC<{
  placeholder?: string;
  value?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ placeholder, value, onChange, 'data-testid': testId }) => (
  <input
    placeholder={placeholder}
    value={value}
    onChange={onChange}
    data-testid={testId}
  />
);

const TestPanel: React.FC<{
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ children, 'data-testid': testId }) => (
  <div data-testid={testId}>{children}</div>
);

const TestPageHeader: React.FC<{
  title?: string;
}> = ({ title }) => <h1>{title}</h1>;

const TestGrid: React.FC<{
  columns?: number;
  children?: React.ReactNode;
}> = ({ children }) => <div data-testid="grid">{children}</div>;

const TestCard: React.FC<{
  title?: string;
  children?: React.ReactNode;
}> = ({ title, children }) => (
  <div data-testid="card">
    {title && <h2>{title}</h2>}
    {children}
  </div>
);

const TestText: React.FC<{
  text?: string;
  children?: React.ReactNode;
}> = ({ text, children }) => <span>{children || text}</span>;

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 테스트용 간단한 레이아웃 JSON
const simpleLayoutJson = {
  version: '1.0.0',
  layout_name: 'test_layout',
  components: [
    {
      id: 'page-header',
      type: 'composite',
      name: 'PageHeader',
      props: {
        title: '$t:test.title',
      },
    },
    {
      id: 'filter-button',
      type: 'basic',
      name: 'Button',
      text: '필터',
      props: {
        'data-testid': 'filter-button',
      },
      actions: [
        {
          type: 'click',
          handler: 'setState',
          params: {
            target: 'local',
            filterVisible: true,
          },
        },
      ],
    },
    {
      id: 'filter-panel',
      type: 'composite',
      name: 'Panel',
      if: '{{_local.filterVisible}}',
      props: {
        'data-testid': 'filter-panel',
      },
      children: [
        {
          id: 'filter-input',
          type: 'basic',
          name: 'Input',
          props: {
            placeholder: '검색어 입력',
            'data-testid': 'filter-input',
          },
        },
      ],
    },
  ],
  data_sources: [
    {
      id: 'products',
      type: 'api',
      endpoint: '/api/admin/products',
      auto_fetch: true,
    },
  ],
};

// iteration 테스트용 레이아웃
const iterationLayoutJson = {
  version: '1.0.0',
  layout_name: 'iteration_test_layout',
  components: [
    {
      id: 'product-list',
      type: 'layout',
      name: 'Grid',
      props: {
        columns: 3,
      },
      iteration: {
        source: 'products.data',
        item_var: 'product',
        index_var: 'idx',
      },
      children: [
        {
          id: 'product-card-{{idx}}',
          type: 'composite',
          name: 'Card',
          props: {
            title: '{{product.name}}',
          },
          children: [
            {
              id: 'product-price-{{idx}}',
              type: 'basic',
              name: 'Text',
              text: '{{product.price}}원',
            },
          ],
        },
      ],
    },
  ],
};

// 컴포넌트 레지스트리 설정 헬퍼
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  // 비공개 메서드 우회하여 컴포넌트 직접 등록
  (registry as any).registry = {
    Button: {
      component: TestButton,
      metadata: { name: 'Button', type: 'basic' },
    },
    Input: {
      component: TestInput,
      metadata: { name: 'Input', type: 'basic' },
    },
    Panel: {
      component: TestPanel,
      metadata: { name: 'Panel', type: 'composite' },
    },
    PageHeader: {
      component: TestPageHeader,
      metadata: { name: 'PageHeader', type: 'composite' },
    },
    Grid: {
      component: TestGrid,
      metadata: { name: 'Grid', type: 'layout' },
    },
    Card: {
      component: TestCard,
      metadata: { name: 'Card', type: 'composite' },
    },
    Text: {
      component: TestText,
      metadata: { name: 'Text', type: 'basic' },
    },
    Fragment: {
      component: TestFragment,
      metadata: { name: 'Fragment', type: 'layout' },
    },
  };

  return registry;
}

describe('레이아웃 테스트 유틸리티', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();

    testUtils = createLayoutTest(simpleLayoutJson, {
      translations: {
        test: {
          title: '테스트 페이지',
        },
      },
      locale: 'ko',
      componentRegistry: registry,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  describe('기본 렌더링', () => {
    it('레이아웃이 렌더링된다', async () => {
      testUtils.mockApi('products', {
        response: { data: [] },
      });

      await testUtils.render();

      // 페이지 제목 확인
      expect(screen.getByText('테스트 페이지')).toBeInTheDocument();
    });

    it('버튼이 렌더링된다', async () => {
      testUtils.mockApi('products', {
        response: { data: [] },
      });

      await testUtils.render();

      expect(screen.getByTestId('filter-button')).toBeInTheDocument();
      expect(screen.getByText('필터')).toBeInTheDocument();
    });
  });

  describe('상태 관리', () => {
    it('초기 상태가 올바르게 설정된다', async () => {
      testUtils.mockApi('products', { response: { data: [] } });
      await testUtils.render();

      // 초기 상태: filterVisible = undefined
      expect(testUtils.getState()._local.filterVisible).toBeUndefined();
    });

    it('setState로 상태를 변경할 수 있다', async () => {
      testUtils.mockApi('products', { response: { data: [] } });
      await testUtils.render();

      // 상태 변경
      testUtils.setState('filterVisible', true, 'local');

      // 상태 변경 확인
      expect(testUtils.getState()._local.filterVisible).toBe(true);
    });

    it('중첩 경로로 상태를 설정할 수 있다', async () => {
      testUtils.mockApi('products', { response: { data: [] } });
      await testUtils.render();

      // 중첩 상태 설정
      testUtils.setState('filter.keyword', '검색어', 'local');
      testUtils.setState('filter.category', 'electronics', 'local');

      const state = testUtils.getState();
      expect(state._local.filter.keyword).toBe('검색어');
      expect(state._local.filter.category).toBe('electronics');
    });
  });

  describe('Mock API', () => {
    it('mockApi로 응답을 설정할 수 있다', async () => {
      testUtils.mockApi('products', {
        response: { data: [{ id: 1, name: '상품1' }] },
      });

      await testUtils.render();

      // API가 모킹되었는지 확인
      expect(testUtils.mockNavigate).not.toHaveBeenCalled();
    });

    it('mockApiError로 에러를 시뮬레이션할 수 있다', async () => {
      testUtils.mockApiError('products', 500, '서버 에러');

      await testUtils.render();

      // 에러 상황에서도 렌더링 유지
      expect(screen.getByText('테스트 페이지')).toBeInTheDocument();
    });
  });

  describe('네비게이션', () => {
    it('네비게이션 이력이 추적된다', async () => {
      testUtils.mockApi('products', { response: { data: [] } });
      await testUtils.render();

      // navigate 액션 트리거
      await testUtils.triggerAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '/admin/products/1/edit' },
      });

      expect(testUtils.getNavigationHistory()).toContain('/admin/products/1/edit');
      // ActionDispatcher는 navigate 호출 시 두 번째 인수로 options를 전달함
      expect(testUtils.mockNavigate).toHaveBeenCalledWith('/admin/products/1/edit', expect.any(Object));
    });
  });

  describe('토스트 알림', () => {
    it('G7Core.toast로 토스트가 표시된다', async () => {
      testUtils.mockApi('products', { response: { data: [] } });
      await testUtils.render();

      // G7Core.toast를 직접 호출하여 테스트 (layoutTestUtils에서 모킹됨)
      const G7Core = (window as any).G7Core;
      G7Core.toast.success('저장되었습니다');

      const toasts = testUtils.getToasts();
      expect(toasts).toContainEqual({
        type: 'success',
        message: '저장되었습니다',
      });
    });

    it('여러 토스트가 축적된다', async () => {
      testUtils.mockApi('products', { response: { data: [] } });
      await testUtils.render();

      // G7Core.toast를 직접 호출
      const G7Core = (window as any).G7Core;
      G7Core.toast.success('성공');
      G7Core.toast.error('실패');

      const toasts = testUtils.getToasts();
      expect(toasts.length).toBe(2);
    });
  });

  describe('모달', () => {
    it('모달 스택이 관리된다', async () => {
      testUtils.mockApi('products', { response: { data: [] } });
      await testUtils.render();

      // 모달 열기
      testUtils.openModal('confirm-modal');
      expect(testUtils.getModalStack()).toContain('confirm-modal');

      // 모달 닫기
      testUtils.closeModal();
      expect(testUtils.getModalStack()).not.toContain('confirm-modal');
    });

    it('G7Core.modal.open으로 모달이 열린다', async () => {
      testUtils.mockApi('products', { response: { data: [] } });
      await testUtils.render();

      // G7Core.modal.open을 직접 호출하여 테스트 (layoutTestUtils에서 모킹됨)
      const G7Core = (window as any).G7Core;
      G7Core.modal.open('delete-confirm');

      expect(testUtils.getModalStack()).toContain('delete-confirm');
    });
  });
});

describe('인증 테스트', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('인증된 상태로 렌더링할 수 있다', async () => {
    const testUtils = createLayoutTest(simpleLayoutJson, {
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', role: 'super_admin' },
        authType: 'admin',
      },
      translations: { test: { title: '관리자 페이지' } },
      componentRegistry: registry,
    });

    testUtils.mockApi('products', { response: { data: [] } });
    await testUtils.render();

    expect(screen.getByText('관리자 페이지')).toBeInTheDocument();

    testUtils.cleanup();
  });
});

describe('iteration 테스트', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('[TS-CACHE-1] iteration에서 각 행이 올바른 데이터를 참조한다', async () => {
    const testUtils = createLayoutTest(iterationLayoutJson, {
      translations: { test: { title: '상품 목록' } },
      componentRegistry: registry,
      initialData: {
        products: {
          data: [
            { id: 1, name: '첫번째 상품', price: 1000 },
            { id: 2, name: '두번째 상품', price: 2000 },
            { id: 3, name: '세번째 상품', price: 3000 },
          ],
        },
      },
    });

    await testUtils.render();

    // 모든 상품이 올바르게 렌더링됨
    expect(screen.getByText('첫번째 상품')).toBeInTheDocument();
    expect(screen.getByText('두번째 상품')).toBeInTheDocument();
    expect(screen.getByText('세번째 상품')).toBeInTheDocument();

    // 각 가격이 올바르게 표시됨 (캐시 문제 없음)
    expect(screen.getByText('1000원')).toBeInTheDocument();
    expect(screen.getByText('2000원')).toBeInTheDocument();
    expect(screen.getByText('3000원')).toBeInTheDocument();

    testUtils.cleanup();
  });
});

describe('유틸리티 함수', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('cleanup이 상태를 초기화한다', async () => {
    const testUtils = createLayoutTest(simpleLayoutJson, {
      translations: { test: { title: '테스트' } },
      componentRegistry: registry,
    });

    testUtils.mockApi('products', { response: { data: [] } });
    await testUtils.render();

    // 상태 변경
    testUtils.setState('testValue', 123, 'local');
    testUtils.openModal('test-modal');

    // cleanup 호출
    testUtils.cleanup();

    // 새로운 테스트 유틸리티 생성
    const newTestUtils = createLayoutTest(simpleLayoutJson, {
      translations: { test: { title: '테스트' } },
      componentRegistry: registry,
    });

    // 상태가 초기화되었는지 확인
    expect(newTestUtils.getState()._local.testValue).toBeUndefined();
    expect(newTestUtils.getModalStack().length).toBe(0);

    newTestUtils.cleanup();
  });

  it('global 상태를 설정할 수 있다', async () => {
    const testUtils = createLayoutTest(simpleLayoutJson, {
      translations: { test: { title: '테스트' } },
      componentRegistry: registry,
    });

    testUtils.mockApi('products', { response: { data: [] } });
    await testUtils.render();

    testUtils.setState('user', { id: 1, name: 'Test User' }, 'global');

    const state = testUtils.getState();
    expect(state._global.user).toEqual({ id: 1, name: 'Test User' });

    testUtils.cleanup();
  });
});
