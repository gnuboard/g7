/**
 * 배송정책 관리 목록 화면 레이아웃 렌더링 테스트
 *
 * @description
 * - 레이아웃 JSON 구조 검증
 * - 컴포넌트 렌더링 검증
 * - 상태 바인딩 검증
 * - 조건부 렌더링 검증
 * - 액션 핸들러 검증
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import shippingPolicyListFixture from '../fixtures/admin_ecommerce_shipping_policy_list.json';

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

const TestH2: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h2 className={className}>{children || text}</h2>
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

const TestCheckbox: React.FC<{
  checked?: boolean;
  className?: string;
  onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  'data-testid'?: string;
}> = ({ checked, className, onChange, 'data-testid': testId }) => (
  <input type="checkbox" checked={checked} className={className} onChange={onChange} data-testid={testId} />
);

const TestLabel: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <label className={className}>{children || text}</label>
);

// Composite 컴포넌트 목
const TestDataGrid: React.FC<{
  columns?: any[];
  data?: any[];
  loading?: boolean;
  'data-testid'?: string;
  subRowCondition?: string;
}> = ({ data, loading, 'data-testid': testId, subRowCondition }) => (
  <div
    data-testid={testId || 'datagrid'}
    data-loading={loading ? 'true' : 'false'}
    data-subrow-condition={subRowCondition}
  >
    {loading ? '로딩 중...' : `DataGrid: ${data?.length || 0}건`}
  </div>
);

const TestPagination: React.FC<{
  total?: number;
  page?: number;
  perPage?: number;
  lastPage?: number;
}> = ({ total, page, lastPage }) => (
  lastPage && lastPage > 1 ? (
    <div data-testid="pagination">
      페이지 {page} / 전체 {total}건
    </div>
  ) : null
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

const TestToggle: React.FC<{
  checked?: boolean;
  disabled?: boolean;
  onChange?: () => void;
}> = ({ checked, disabled, onChange }) => (
  <button
    data-testid="toggle"
    data-checked={checked ? 'true' : 'false'}
    disabled={disabled}
    onClick={onChange}
  >
    {checked ? 'ON' : 'OFF'}
  </button>
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
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    Option: { component: TestOption, metadata: { name: 'Option', type: 'basic' } },
    Checkbox: { component: TestCheckbox, metadata: { name: 'Checkbox', type: 'basic' } },
    Label: { component: TestLabel, metadata: { name: 'Label', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },

    // Composite 컴포넌트
    DataGrid: { component: TestDataGrid, metadata: { name: 'DataGrid', type: 'composite' } },
    Pagination: { component: TestPagination, metadata: { name: 'Pagination', type: 'composite' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
  };

  return registry;
}


// Mock 데이터
const mockShippingPolicies = {
  data: {
    data: [
      {
        id: 1,
        name_localized: '기본 택배 배송',
        shipping_method: 'parcel',
        shipping_method_label: '택배',
        carrier: 'cj',
        carrier_label: '대한통운',
        charge_policy: 'conditional_free',
        charge_policy_label: '조건부 무료',
        countries: ['KR'],
        countries_display: '🇰🇷',
        currency_code: 'KRW',
        extra_fee_enabled: true,
        is_active: true,
        fee_summary: '5만원 미만 3,000원 / 5만원 이상 무료',
      },
      {
        id: 2,
        name_localized: '무게별 배송',
        shipping_method: 'parcel',
        shipping_method_label: '택배',
        carrier: 'logen',
        carrier_label: '로젠택배',
        charge_policy: 'range_weight',
        charge_policy_label: '구간별(무게)',
        countries: ['KR'],
        countries_display: '🇰🇷',
        currency_code: 'KRW',
        extra_fee_enabled: false,
        is_active: true,
        fee_summary: '~1.5kg: 4,000원 / 1.5~4kg: 5,000원',
      },
      {
        id: 3,
        name_localized: '무료 배송',
        shipping_method: 'parcel',
        shipping_method_label: '택배',
        carrier: 'cj',
        carrier_label: '대한통운',
        charge_policy: 'free',
        charge_policy_label: '무료',
        countries: ['KR'],
        countries_display: '🇰🇷',
        currency_code: 'KRW',
        extra_fee_enabled: false,
        is_active: false,
        fee_summary: '무료배송',
      },
    ],
    pagination: {
      current_page: 1,
      last_page: 3,
      per_page: 20,
      total: 42,
    },
  },
  loading: false,
  error: null,
};

describe('배송정책 관리 목록 화면 레이아웃', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let layoutJson: any;

  beforeEach(async () => {
    registry = setupTestRegistry();

    // fixture에서 레이아웃 로드
    layoutJson = shippingPolicyListFixture;

    testUtils = createLayoutTest(layoutJson, {
      auth: {
        isAuthenticated: true,
        user: { id: 1, name: 'Admin', role: 'super_admin' },
        authType: 'admin',
      },
      translations: {
        // 번역은 nested 구조로 제공 (TranslationEngine 규격)
        'sirsoft-ecommerce': {
          admin: {
            shipping_policy: {
              title: '배송정책 관리',
              description: '배송정책 목록을 관리합니다.',
              add: '배송정책 등록',
              search: {
                placeholder: '정책명 검색...',
              },
              filter: {
                shipping_method: '배송방법',
                carrier: '운송사',
                charge_policy: '부과정책',
                country: '배송국가',
                is_active: '사용여부',
                all: '전체',
                active: '사용',
                inactive: '미사용',
              },
              action: {
                search: '검색',
                reset: '초기화',
                edit: '수정',
                copy: '복사',
                delete: '삭제',
                bulk_delete: '선택 삭제',
                bulk_apply: '일괄 적용',
              },
              bulk: {
                selected_count: '{{count}}개 선택됨',
                select_status: '상태 선택',
                toggle_active: '사용',
                toggle_inactive: '미사용',
              },
              table: {
                column: {
                  name: '배송정책명',
                  shipping_method: '배송방법',
                  carrier: '운송사',
                  charge_policy: '부과정책',
                  countries: '배송국가',
                  currency: '기준통화',
                  extra_fee: '추가배송비',
                  is_active: '사용여부',
                  actions: '작업',
                },
              },
              pagination: {
                total: '총 {{count}}개',
              },
              sort: {
                created_at_desc: '최신순',
                created_at_asc: '오래된순',
                name_asc: '정책명순',
                name_desc: '정책명역순',
                sort_order_asc: '정렬순서',
              },
              empty: {
                title: '등록된 배송정책이 없습니다.',
                message: '새로운 배송정책을 등록하여 상품에 적용해 보세요.',
                no_results: '검색 결과가 없습니다.',
                no_results_message: '다른 검색어로 시도해 보세요.',
                button: '배송정책 등록',
                reset_filter: '필터 초기화',
              },
              modal: {
                delete: {
                  title: '배송정책 삭제',
                  message: '다음 배송정책을 삭제하시겠습니까?',
                  warning: '삭제된 정책은 복구할 수 없습니다.',
                  product_warning: '이 정책을 사용 중인 상품이 있다면 해당 상품의 배송정책이 해제됩니다.',
                },
                copy: {
                  title: '배송정책 복사',
                  message: '다음 배송정책을 복사하시겠습니까?',
                  note: '복사 후 수정 화면으로 이동합니다.',
                  confirm: '복사하기',
                },
                bulk_delete: {
                  title: '배송정책 일괄 삭제',
                  message: '선택한 {{count}}개의 배송정책을 삭제하시겠습니까?',
                },
                bulk_toggle: {
                  title: '사용여부 일괄 변경',
                  message: '선택한 {{count}}개의 배송정책의 사용여부를 변경하시겠습니까?',
                },
              },
              common: {
                cancel: '취소',
                delete: '삭제',
                deleting: '삭제 중...',
                confirm: '확인',
                change: '변경',
              },
              messages: {
                deleted: '배송정책이 삭제되었습니다.',
                toggled: '사용여부가 변경되었습니다.',
                bulk_deleted: '선택한 배송정책이 삭제되었습니다.',
                bulk_toggled: '선택한 배송정책의 사용여부가 변경되었습니다.',
              },
            },
          },
          enums: {
            shipping_method: {
              parcel: '택배',
              collect: '착불',
              quick: '퀵서비스',
              direct: '직접배송',
              pickup: '방문수령',
              other: '기타',
            },
            carrier: {
              cj: '대한통운',
              logen: '로젠택배',
              ems: 'EMS',
              dhl: 'DHL',
              fedex: 'FedEx',
              other: '기타',
            },
            charge_policy: {
              free: '무료',
              fixed: '고정',
              conditional_free: '조건부 무료',
              range_amount: '구간별(금액)',
              range_quantity: '구간별(수량)',
              range_weight: '구간별(무게)',
              range_volume: '구간별(부피)',
              range_volume_weight: '구간별(부피+무게)',
              api: '계산 API',
            },
            shipping_country: {
              KR: '🇰🇷 한국',
              US: '🇺🇸 미국',
              CN: '🇨🇳 중국',
              JP: '🇯🇵 일본',
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
      expect(info.name).toBe('admin_ecommerce_shipping_policy_list');
      expect(info.version).toBe('1.0.0');
    });

    it('데이터소스가 정의되어 있다', () => {
      const dataSources = testUtils.getDataSources();
      expect(dataSources.length).toBeGreaterThan(0);

      // shipping_policies 데이터소스 확인
      const shippingPoliciesDs = dataSources.find((ds) => ds.id === 'shipping_policies');
      expect(shippingPoliciesDs).toBeDefined();
      expect(shippingPoliciesDs?.endpoint).toContain('/shipping-policies');
      expect(shippingPoliciesDs?.auto_fetch).toBe(true);
    });

    it('초기 상태가 레이아웃에서 정의된 대로 설정된다', async () => {
      // 레이아웃 JSON에서 state 섹션 검증
      expect(layoutJson.state).toBeDefined();
      expect(layoutJson.global_state).toBeDefined();

      // 레이아웃의 state 정의 검증
      expect(layoutJson.state.filter).toBeDefined();
      expect(layoutJson.state.selectedItems).toEqual([]);
      expect(layoutJson.state.selectAll).toBe(false);

      // 레이아웃의 global_state 정의 검증
      expect(layoutJson.global_state.targetPolicy).toBeNull();
      expect(layoutJson.global_state.isDeleting).toBe(false);

      // 렌더링 (layoutTestUtils가 자동으로 초기 상태 적용)
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });
      await testUtils.render();

      // 적용된 상태 검증
      const state = testUtils.getState();
      expect(state._local.filter).toBeDefined();
      expect(state._local.selectedItems).toEqual([]);
      expect(state._global.targetPolicy).toBeNull();
    });

    it('필수 권한이 정의되어 있다', () => {
      // 권한은 layoutJson에서 직접 확인
      expect(layoutJson.permissions).toBeDefined();
      expect(layoutJson.permissions).toContain('sirsoft-ecommerce.shipping-policies.read');
    });

    it('modals가 정의되어 있다', () => {
      const modals = layoutJson.modals;
      expect(modals).toBeDefined();
      expect(Array.isArray(modals)).toBe(true);
      expect(modals.length).toBeGreaterThanOrEqual(4); // delete, copy, bulk_delete, bulk_toggle
    });
  });

  describe('컴포넌트 렌더링 검증', () => {
    it('데이터가 있을 때 레이아웃이 렌더링된다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 레이아웃이 렌더링되었는지 확인
      expect(testUtils.getState()._global).toBeDefined();
    });

    it('데이터가 있을 때 DataGrid가 렌더링된다', async () => {
      // 레이아웃이 shipping_policies?.data?.data 경로로 접근하므로
      // directMock 경로의 wrap 없는 response 특성에 맞춰 한 단계 더 감싼다
      testUtils.mockApi('shipping_policies', {
        response: { data: mockShippingPolicies.data },
      });

      const { container } = await testUtils.render();

      expect(registry.hasComponent('DataGrid')).toBe(true);
      expect(registry.hasComponent('Div')).toBe(true);
      expect(registry.hasComponent('Fragment')).toBe(true);

      expect(container.innerHTML.length).toBeGreaterThan(0);

      const datagridByText = screen.queryByText(/DataGrid:/);
      const datagridByTestId = screen.queryByTestId('datagrid');

      expect(datagridByText || datagridByTestId).toBeTruthy();
    });
  });

  describe('상태 관리 테스트', () => {
    it('필터 상태를 변경할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 필터 상태 변경 (올바른 API 사용)
      testUtils.setState('filter.search', '테스트', 'local');
      testUtils.setState('filter.shipping_methods', ['parcel'], 'local');
      testUtils.setState('filter.is_active', 'true', 'local');

      const state = testUtils.getState();
      expect(state._local.filter.search).toBe('테스트');
      expect(state._local.filter.shipping_methods).toContain('parcel');
      expect(state._local.filter.is_active).toBe('true');
    });

    it('선택된 항목 상태를 관리할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 선택 상태 변경
      testUtils.setState('selectedItems', [1, 2], 'local');

      const state = testUtils.getState();
      expect(state._local.selectedItems).toEqual([1, 2]);
    });

    it('삭제 대상 정책을 _global에 설정할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 삭제 대상 설정
      const targetPolicy = mockShippingPolicies.data.data[0];
      testUtils.setState('targetPolicy', targetPolicy, 'global');

      const state = testUtils.getState();
      expect(state._global.targetPolicy).toEqual(targetPolicy);
      expect(state._global.targetPolicy.id).toBe(1);
    });

    it('일괄 토글 값을 설정할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 일괄 토글 값 설정
      testUtils.setState('bulkToggleValue', 'true', 'local');

      const state = testUtils.getState();
      expect(state._local.bulkToggleValue).toBe('true');
    });
  });

  describe('액션 테스트', () => {
    it('등록 페이지로 이동 액션을 트리거할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // navigate 액션 트리거
      await testUtils.triggerAction({
        type: 'click',
        handler: 'navigate',
        params: { path: '/admin/ecommerce/shipping-policies/create' },
      });

      expect(testUtils.getNavigationHistory()).toContain('/admin/ecommerce/shipping-policies/create');
    });

    it('삭제 확인 모달을 열 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 삭제 대상 설정
      const targetPolicy = mockShippingPolicies.data.data[0];
      testUtils.setState('targetPolicy', targetPolicy, 'global');

      // 모달 열기
      testUtils.openModal('delete_modal');

      expect(testUtils.getModalStack()).toContain('delete_modal');
    });

    it('복사 확인 모달을 열 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 복사 대상 설정
      const targetPolicy = mockShippingPolicies.data.data[0];
      testUtils.setState('targetPolicy', targetPolicy, 'global');

      // 모달 열기
      testUtils.openModal('copy_modal');

      expect(testUtils.getModalStack()).toContain('copy_modal');
    });

    it('일괄 삭제 모달을 열 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 항목 선택
      testUtils.setState('selectedItems', [1, 2], 'local');

      // 모달 열기
      testUtils.openModal('bulk_delete_modal');

      expect(testUtils.getModalStack()).toContain('bulk_delete_modal');
    });

    it('일괄 사용여부 변경 모달을 열 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 항목 선택 및 토글 값 설정
      testUtils.setState('selectedItems', [1, 2], 'local');
      testUtils.setState('bulkToggleValue', 'true', 'local');

      // 모달 열기
      testUtils.openModal('bulk_toggle_modal');

      expect(testUtils.getModalStack()).toContain('bulk_toggle_modal');
    });
  });

  describe('데이터 소스 테스트', () => {
    it('목록 API를 모킹하고 렌더링한다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 레이아웃이 렌더링되었는지 확인
      expect(testUtils.getState()._global).toBeDefined();
    });

    it('목록이 비어있을 때 처리한다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: {
          data: [],
          pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 0,
          },
        },
      });

      await testUtils.render();

      const state = testUtils.getState();
      expect(state._local.selectedItems).toEqual([]);
    });

    it('API 에러 시에도 레이아웃은 유지된다', async () => {
      testUtils.mockApiError('shipping_policies', 500, '서버 오류');

      await testUtils.render();

      // 에러 상황에서도 레이아웃은 유지됨
      const state = testUtils.getState();
      expect(state._global).toBeDefined();
    });
  });

  describe('일괄 작업 상태 검증', () => {
    it('선택된 항목이 없을 때 초기 상태가 유지된다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      const state = testUtils.getState();
      expect(state._local.selectedItems).toEqual([]);
      expect(state._local.selectAll).toBe(false);
    });

    it('전체 선택 상태를 변경할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 전체 선택
      testUtils.setState('selectAll', true, 'local');
      testUtils.setState('selectedItems', [1, 2, 3], 'local');

      const state = testUtils.getState();
      expect(state._local.selectAll).toBe(true);
      expect(state._local.selectedItems).toEqual([1, 2, 3]);
    });

    it('개별 선택 후 전체 선택 해제하면 배열이 비워진다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 개별 선택
      testUtils.setState('selectedItems', [1, 2], 'local');
      expect(testUtils.getState()._local.selectedItems).toEqual([1, 2]);

      // 선택 해제
      testUtils.setState('selectedItems', [], 'local');
      testUtils.setState('selectAll', false, 'local');

      const state = testUtils.getState();
      expect(state._local.selectedItems).toEqual([]);
      expect(state._local.selectAll).toBe(false);
    });
  });

  describe('필터 초기화 검증', () => {
    it('필터를 초기값으로 리셋할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 필터 설정
      testUtils.setState('filter.search', '테스트', 'local');
      testUtils.setState('filter.shipping_methods', ['parcel'], 'local');
      testUtils.setState('filter.is_active', 'true', 'local');

      // 필터 초기화
      testUtils.setState('filter', {
        search: '',
        searchType: 'name',
        shipping_methods: [],
        carriers: [],
        charge_policies: [],
        countries: [],
        is_active: '',
      }, 'local');

      const state = testUtils.getState();
      expect(state._local.filter.search).toBe('');
      expect(state._local.filter.shipping_methods).toEqual([]);
      expect(state._local.filter.is_active).toBe('');
    });
  });

  describe('삭제 로딩 상태 검증', () => {
    it('삭제 중 로딩 상태를 설정할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 삭제 시작
      testUtils.setState('isDeleting', true, 'global');
      expect(testUtils.getState()._global.isDeleting).toBe(true);

      // 삭제 완료
      testUtils.setState('isDeleting', false, 'global');
      expect(testUtils.getState()._global.isDeleting).toBe(false);
    });

    it('일괄 삭제 중 로딩 상태를 설정할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 일괄 삭제 시작
      testUtils.setState('isBulkDeleting', true, 'global');
      expect(testUtils.getState()._global.isBulkDeleting).toBe(true);

      // 일괄 삭제 완료
      testUtils.setState('isBulkDeleting', false, 'global');
      expect(testUtils.getState()._global.isBulkDeleting).toBe(false);
    });

    it('일괄 토글 중 로딩 상태를 설정할 수 있다', async () => {
      testUtils.mockApi('shipping_policies', {
        response: mockShippingPolicies.data,
      });

      await testUtils.render();

      // 일괄 토글 시작
      testUtils.setState('isBulkToggling', true, 'global');
      expect(testUtils.getState()._global.isBulkToggling).toBe(true);

      // 일괄 토글 완료
      testUtils.setState('isBulkToggling', false, 'global');
      expect(testUtils.getState()._global.isBulkToggling).toBe(false);
    });
  });
});
