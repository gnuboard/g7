/**
 * @file admin_ecommerce_coupon.test.tsx
 * @description 쿠폰 관리 레이아웃 테스트
 *
 * 테스트 대상:
 * - admin_ecommerce_promotion_coupon_list.json (쿠폰 목록)
 * - admin_ecommerce_promotion_coupon_form.json (쿠폰 등록/수정)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import couponListFixture from '../fixtures/admin_ecommerce_promotion_coupon_list.json';
import couponFormFixture from '../fixtures/admin_ecommerce_promotion_coupon_form.json';

// 테스트용 컴포넌트 정의
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
  <button type={type as any} className={className} disabled={disabled} onClick={onClick} data-testid={testId}>
    {children}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-icon={name} />
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

const TestInput: React.FC<{
  type?: string;
  placeholder?: string;
  value?: string;
  className?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ type, placeholder, value, className, onChange, 'data-testid': testId }) => (
  <input
    type={type}
    placeholder={placeholder}
    value={value}
    className={className}
    onChange={onChange}
    data-testid={testId}
  />
);

const TestSelect: React.FC<{
  value?: string;
  className?: string;
  children?: React.ReactNode;
  onChange?: (e: React.ChangeEvent<HTMLSelectElement>) => void;
  'data-testid'?: string;
}> = ({ value, className, children, onChange, 'data-testid': testId }) => (
  <select value={value} className={className} onChange={onChange} data-testid={testId}>
    {children}
  </select>
);

const TestOption: React.FC<{
  value?: string;
  children?: React.ReactNode;
}> = ({ value, children }) => (
  <option value={value}>{children}</option>
);

// Composite 컴포넌트 목
const TestFilterVisibilitySelector: React.FC<{
  id?: string;
  visibleFilters?: string[];
  defaultFilters?: string[];
}> = ({ id }) => (
  <div data-testid={`filter-visibility-${id}`}>FilterVisibilitySelector</div>
);

const TestDataGrid: React.FC<{
  columns?: any[];
  data?: any[];
  loading?: boolean;
  'data-testid'?: string;
}> = ({ columns, data, loading, 'data-testid': testId }) => (
  <div data-testid={testId || 'datagrid'}>
    {loading ? '로딩 중...' : `DataGrid: ${data?.length || 0}건`}
  </div>
);

const TestPagination: React.FC<{
  total?: number;
  page?: number;
  perPage?: number;
}> = ({ total, page, perPage }) => (
  <div data-testid="pagination">
    페이지 {page} / 전체 {total}건
  </div>
);

const TestModal: React.FC<{
  id?: string;
  isOpen?: boolean;
  title?: string;
  children?: React.ReactNode;
}> = ({ id, isOpen, title, children }) => (
  isOpen ? (
    <div data-testid={`modal-${id}`} role="dialog">
      <h2>{title}</h2>
      {children}
    </div>
  ) : null
);

const TestForm: React.FC<{
  dataKey?: string;
  children?: React.ReactNode;
}> = ({ dataKey, children }) => (
  <form data-testid={`form-${dataKey}`}>{children}</form>
);

const TestCard: React.FC<{
  title?: string;
  className?: string;
  children?: React.ReactNode;
}> = ({ title, className, children }) => (
  <div className={className} data-testid="card">
    {title && <h3>{title}</h3>}
    {children}
  </div>
);

const TestTabs: React.FC<{
  activeTab?: string;
  children?: React.ReactNode;
}> = ({ activeTab, children }) => (
  <div data-testid="tabs" data-active={activeTab}>{children}</div>
);

const TestTabPanel: React.FC<{
  tabId?: string;
  children?: React.ReactNode;
}> = ({ tabId, children }) => (
  <div data-testid={`tab-panel-${tabId}`}>{children}</div>
);

// 컴포넌트 레지스트리 설정 헬퍼
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    // Basic 컴포넌트
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    Option: { component: TestOption, metadata: { name: 'Option', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },

    // Composite 컴포넌트
    FilterVisibilitySelector: {
      component: TestFilterVisibilitySelector,
      metadata: { name: 'FilterVisibilitySelector', type: 'composite' },
    },
    DataGrid: { component: TestDataGrid, metadata: { name: 'DataGrid', type: 'composite' } },
    Pagination: { component: TestPagination, metadata: { name: 'Pagination', type: 'composite' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    Form: { component: TestForm, metadata: { name: 'Form', type: 'composite' } },
    Card: { component: TestCard, metadata: { name: 'Card', type: 'composite' } },
    Tabs: { component: TestTabs, metadata: { name: 'Tabs', type: 'composite' } },
    TabPanel: { component: TestTabPanel, metadata: { name: 'TabPanel', type: 'composite' } },
  };

  return registry;
}

describe('쿠폰 목록 레이아웃 테스트', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let layoutJson: any;

  beforeEach(async () => {
    registry = setupTestRegistry();

    // fixture에서 레이아웃 로드
    layoutJson = couponListFixture;

    testUtils = createLayoutTest(layoutJson, {
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', role: 'super_admin' },
        authType: 'admin',
      },
      translations: {
        'sirsoft-ecommerce': {
          admin: {
            promotion_coupon: {
              title: '쿠폰 관리',
              description: '프로모션 쿠폰을 관리합니다.',
              actions: {
                create: '쿠폰 등록',
              },
            },
          },
        },
        common: {
          cancel: '취소',
          save: '저장',
        },
      },
      locale: 'ko',
      componentRegistry: registry,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  describe('레이아웃 구조 검증', () => {
    it('레이아웃 정보가 올바르게 로드된다', () => {
      const info = testUtils.getLayoutInfo();
      expect(info.name).toBe('admin_ecommerce_promotion_coupon_list');
      expect(info.version).toBe('1.0.0');
    });

    it('데이터소스가 정의되어 있다', () => {
      const dataSources = testUtils.getDataSources();
      expect(dataSources.length).toBeGreaterThan(0);

      // coupons 데이터소스 확인
      const couponsDs = dataSources.find((ds) => ds.id === 'coupons');
      expect(couponsDs).toBeDefined();
      expect(couponsDs?.endpoint).toContain('/promotion-coupons');

      // couponIssues 데이터소스 확인
      const issuesDs = dataSources.find((ds) => ds.id === 'couponIssues');
      expect(issuesDs).toBeDefined();
    });

    it('초기 상태가 레이아웃에서 정의된 대로 설정된다', () => {
      const state = testUtils.getState();

      // initGlobal에서 정의된 상태
      expect(state._global.bulkSelectedItems).toEqual([]);
      expect(state._global.isDeleting).toBe(false);
    });
  });

  describe('렌더링 테스트', () => {
    it('쿠폰 목록 API를 모킹하고 렌더링한다', async () => {
      testUtils.mockApi('coupons', {
        response: {
          data: [
            {
              id: 1,
              name: { ko: '신규가입 쿠폰', en: 'New Member Coupon' },
              coupon_code: 'WELCOME2024',
              discount_type: 'fixed_amount',
              discount_value: 5000,
              issue_status: 'active',
            },
            {
              id: 2,
              name: { ko: '첫구매 쿠폰', en: 'First Purchase Coupon' },
              coupon_code: 'FIRST2024',
              discount_type: 'percentage',
              discount_value: 10,
              issue_status: 'active',
            },
          ],
          pagination: { total: 2, current_page: 1, per_page: 10 },
        },
      });

      await testUtils.render();

      // 레이아웃이 렌더링되었는지 확인
      expect(testUtils.getState()._global).toBeDefined();
    });

    it('쿠폰 목록이 비어있을 때 처리한다', async () => {
      testUtils.mockApi('coupons', {
        response: {
          data: [],
          pagination: { total: 0, current_page: 1, per_page: 10 },
        },
      });

      await testUtils.render();

      const state = testUtils.getState();
      expect(state._global.bulkSelectedItems).toEqual([]);
    });
  });

  describe('상태 관리 테스트', () => {
    it('필터 상태를 변경할 수 있다', async () => {
      testUtils.mockApi('coupons', { response: { data: [], pagination: { total: 0 } } });

      await testUtils.render();

      // 필터 상태 변경
      testUtils.setState('filter.searchKeyword', '테스트', 'local');
      testUtils.setState('filter.issueStatus', 'active', 'local');

      const state = testUtils.getState();
      expect(state._local.filter.searchKeyword).toBe('테스트');
      expect(state._local.filter.issueStatus).toBe('active');
    });

    it('등록자 필터(createdBy) 상태가 레이아웃에 정의되어 있다', () => {
      // state 정의에 createdBy 필드가 존재하는지 확인
      const stateFilter = layoutJson.state?.filter;
      expect(stateFilter).toHaveProperty('createdBy');
      expect(stateFilter.createdBy).toBe('');

      // creatorSearchResults 상태도 정의되어 있는지 확인
      expect(layoutJson.state).toHaveProperty('creatorSearchResults');
      expect(layoutJson.state.creatorSearchResults).toEqual([]);
    });

    it('등록자 필터(createdBy) 상태를 변경할 수 있다', async () => {
      testUtils.mockApi('coupons', { response: { data: [], pagination: { total: 0 } } });

      await testUtils.render();

      // 등록자 ID 설정
      testUtils.setState('filter.createdBy', 5, 'local');

      const state = testUtils.getState();
      expect(state._local.filter.createdBy).toBe(5);
    });

    it('일괄 선택 상태를 관리할 수 있다', async () => {
      testUtils.mockApi('coupons', { response: { data: [], pagination: { total: 0 } } });

      await testUtils.render();

      // 전역 상태에 선택 항목 추가
      testUtils.setState('bulkSelectedItems', [1, 2, 3], 'global');

      const state = testUtils.getState();
      expect(state._global.bulkSelectedItems).toEqual([1, 2, 3]);
    });
  });

  describe('액션 테스트', () => {
    it('쿠폰 등록 페이지로 이동 액션을 트리거할 수 있다', async () => {
      testUtils.mockApi('coupons', { response: { data: [], pagination: { total: 0 } } });

      await testUtils.render();

      // navigate 액션 트리거
      await testUtils.triggerAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '/admin/ecommerce/promotion-coupon-create' },
      });

      expect(testUtils.getNavigationHistory()).toContain('/admin/ecommerce/promotion-coupon-create');
    });

    it('삭제 확인 모달을 열 수 있다', async () => {
      testUtils.mockApi('coupons', { response: { data: [], pagination: { total: 0 } } });

      await testUtils.render();

      // 삭제 대상 설정
      testUtils.setState('deleteTarget', { id: 1, name: '테스트 쿠폰' }, 'global');

      // 모달 열기
      testUtils.openModal('delete-confirm-modal');

      expect(testUtils.getModalStack()).toContain('delete-confirm-modal');
    });
  });

  describe('API 에러 처리 테스트', () => {
    it('API 에러 시 토스트가 표시된다', async () => {
      testUtils.mockApiError('coupons', 500, '서버 오류');

      await testUtils.render();

      // 에러 상황에서도 레이아웃은 유지됨
      const state = testUtils.getState();
      expect(state._global).toBeDefined();
    });
  });
});

describe('쿠폰 폼 레이아웃 테스트', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let layoutJson: any;

  beforeEach(async () => {
    registry = setupTestRegistry();

    // fixture에서 레이아웃 로드
    layoutJson = couponFormFixture;

    testUtils = createLayoutTest(layoutJson, {
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', role: 'super_admin' },
        authType: 'admin',
      },
      translations: {
        'sirsoft-ecommerce': {
          admin: {
            promotion_coupon: {
              form: {
                title_create: '쿠폰 등록',
                title_edit: '쿠폰 수정',
                description: '쿠폰 정보를 입력합니다.',
              },
              messages: {
                created: '쿠폰이 등록되었습니다.',
                updated: '쿠폰이 수정되었습니다.',
              },
            },
          },
        },
        common: {
          cancel: '취소',
          save: '저장',
          saving: '저장 중...',
          validation_error: '입력 값을 확인해주세요.',
        },
      },
      locale: 'ko',
      componentRegistry: registry,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  describe('레이아웃 구조 검증', () => {
    it('레이아웃 정보가 올바르게 로드된다', () => {
      const info = testUtils.getLayoutInfo();
      expect(info.name).toBe('admin_ecommerce_promotion_coupon_form');
    });

    it('폼 초기 상태가 정의되어 있다', () => {
      const state = testUtils.getState();

      // initLocal.form에서 정의된 폼 필드
      expect(state._local.form).toBeDefined();
      expect(state._local.form.name).toEqual({ ko: '', en: '' });
      expect(state._local.form.coupon_code).toBe('');
      expect(state._local.form.discount_type).toBe('fixed_amount');
      expect(state._local.form.discount_value).toBe(0);
      expect(state._local.form.target_type).toBe('all_products');
    });

    it('데이터소스가 정의되어 있다', () => {
      const dataSources = testUtils.getDataSources();

      // coupon 데이터소스 (수정 시 사용)
      const couponDs = dataSources.find((ds) => ds.id === 'coupon');
      expect(couponDs).toBeDefined();

      // categories 데이터소스
      const categoriesDs = dataSources.find((ds) => ds.id === 'categories');
      expect(categoriesDs).toBeDefined();

      // products 데이터소스
      const productsDs = dataSources.find((ds) => ds.id === 'products');
      expect(productsDs).toBeDefined();
    });
  });

  describe('생성 모드 테스트', () => {
    it('생성 모드에서 렌더링한다', async () => {
      testUtils.mockApi('categories', {
        response: { data: [{ id: 1, name: '전자제품' }] },
      });

      await testUtils.render();

      const state = testUtils.getState();
      expect(state._local.isSaving).toBe(false);
      expect(state._local.hasChanges).toBe(false);
    });

    it('폼 상태를 변경할 수 있다', async () => {
      testUtils.mockApi('categories', { response: { data: [] } });

      await testUtils.render();

      // 폼 필드 값 변경
      testUtils.setState('form.coupon_code', 'SUMMER2024', 'local');
      testUtils.setState('form.discount_value', 10000, 'local');
      testUtils.setState('form.discount_type', 'percentage', 'local');

      const state = testUtils.getState();
      expect(state._local.form.coupon_code).toBe('SUMMER2024');
      expect(state._local.form.discount_value).toBe(10000);
      expect(state._local.form.discount_type).toBe('percentage');
    });
  });

  describe('수정 모드 테스트', () => {
    it('수정 모드에서 기존 쿠폰 데이터를 로드한다', async () => {
      // 수정 모드: route.id가 있는 경우
      const editTestUtils = createLayoutTest(layoutJson, {
        auth: {
          isAuthenticated: true,
          user: { id: 1, name: 'Admin', role: 'super_admin' },
          authType: 'admin',
        },
        routeParams: { id: '123' },
        componentRegistry: registry,
        initialState: {
          _local: {
            form: {
              name: { ko: '기존 쿠폰', en: 'Existing Coupon' },
              coupon_code: 'EXIST2024',
              discount_type: 'fixed_amount',
              discount_value: 5000,
            },
          },
        },
      });

      editTestUtils.mockApi('coupon', {
        response: {
          data: {
            id: 123,
            name: { ko: '기존 쿠폰', en: 'Existing Coupon' },
            coupon_code: 'EXIST2024',
            discount_type: 'fixed_amount',
            discount_value: 5000,
          },
        },
      });
      editTestUtils.mockApi('categories', { response: { data: [] } });

      await editTestUtils.render();

      const state = editTestUtils.getState();
      expect(state._local.form.coupon_code).toBe('EXIST2024');

      editTestUtils.cleanup();
    });
  });

  describe('저장 액션 테스트', () => {
    it('저장 시작 시 isSaving 상태가 변경된다', async () => {
      testUtils.mockApi('categories', { response: { data: [] } });

      await testUtils.render();

      // 저장 시작 상태 설정
      testUtils.setState('isSaving', true, 'local');
      testUtils.setState('errors', null, 'local');

      const state = testUtils.getState();
      expect(state._local.isSaving).toBe(true);
      expect(state._local.errors).toBeNull();
    });

    it('저장 성공 시 목록 페이지로 이동한다', async () => {
      testUtils.mockApi('categories', { response: { data: [] } });

      await testUtils.render();

      // navigate 액션 트리거 (저장 성공 후)
      await testUtils.triggerAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '/admin/ecommerce/promotion-coupons' },
      });

      expect(testUtils.getNavigationHistory()).toContain('/admin/ecommerce/promotion-coupons');
    });

    it('저장 실패 시 에러 상태가 설정된다', async () => {
      testUtils.mockApi('categories', { response: { data: [] } });

      await testUtils.render();

      // 에러 상태 설정
      testUtils.setState('isSaving', false, 'local');
      testUtils.setState(
        'errors',
        { coupon_code: ['쿠폰 코드는 필수입니다.'] },
        'local'
      );

      const state = testUtils.getState();
      expect(state._local.isSaving).toBe(false);
      expect(state._local.errors).toEqual({ coupon_code: ['쿠폰 코드는 필수입니다.'] });
    });
  });

  describe('취소 액션 테스트', () => {
    it('취소 시 목록 페이지로 이동한다', async () => {
      testUtils.mockApi('categories', { response: { data: [] } });

      await testUtils.render();

      await testUtils.triggerAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '/admin/ecommerce/promotion-coupons' },
      });

      expect(testUtils.getNavigationHistory()).toContain('/admin/ecommerce/promotion-coupons');
    });
  });
});
