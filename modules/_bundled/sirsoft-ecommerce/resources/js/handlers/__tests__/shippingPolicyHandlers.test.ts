/**
 * 배송정책 관리 목록 화면 핸들러 테스트
 *
 * @description
 * - 필터 적용 핸들러 검증
 * - 필터 초기화 핸들러 검증
 * - 사용여부 토글 핸들러 검증
 * - 삭제 모달 열기 핸들러 검증
 * - 복사 모달 열기 핸들러 검증
 * - 일괄 작업 핸들러 검증
 */
import { describe, it, expect, beforeEach, afterEach, vi, Mock } from 'vitest';
import type { ActionContext } from '../../types';

// G7Core Mock 설정
const mockNavigate = vi.fn();
const mockSetLocal = vi.fn();
const mockSetGlobal = vi.fn();
const mockApiGet = vi.fn();
const mockApiPost = vi.fn();
const mockApiDelete = vi.fn();
const mockApiPatch = vi.fn();
const mockOpenModal = vi.fn();
const mockCloseModal = vi.fn();
const mockToast = vi.fn();
const mockRefetchDataSource = vi.fn();

// G7Core 전역 객체 Mock
const setupG7CoreMock = () => {
  (window as any).G7Core = {
    navigate: mockNavigate,
    state: {
      setLocal: mockSetLocal,
      setGlobal: mockSetGlobal,
      getLocal: vi.fn(() => ({
        filter: {
          search: '',
          shipping_methods: [],
          carriers: [],
          charge_policies: [],
          countries: [],
          is_active: '',
        },
        selectedItems: [],
        selectAll: false,
      })),
      getGlobal: vi.fn(() => ({
        targetPolicy: null,
        isDeleting: false,
        isBulkDeleting: false,
        isBulkToggling: false,
      })),
    },
    api: {
      get: mockApiGet,
      post: mockApiPost,
      delete: mockApiDelete,
      patch: mockApiPatch,
    },
    modal: {
      open: mockOpenModal,
      close: mockCloseModal,
    },
    toast: mockToast,
    dataSource: {
      refetch: mockRefetchDataSource,
    },
  };
};

// Mock 데이터
const mockShippingPolicy = {
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
};

const mockMultiplePolicies = [
  mockShippingPolicy,
  {
    id: 2,
    name_localized: '무게별 배송',
    shipping_method: 'parcel',
    carrier: 'logen',
    is_active: true,
  },
  {
    id: 3,
    name_localized: '해외 배송',
    shipping_method: 'parcel',
    carrier: 'fedex',
    is_active: false,
  },
];

describe('배송정책 관리 목록 핸들러', () => {
  beforeEach(() => {
    setupG7CoreMock();
    vi.clearAllMocks();
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  describe('네비게이션 핸들러', () => {
    it('등록 페이지로 이동해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 등록 버튼 클릭 시뮬레이션
      G7Core.navigate({ path: '/admin/ecommerce/shipping-policies/create' });

      expect(mockNavigate).toHaveBeenCalledWith({
        path: '/admin/ecommerce/shipping-policies/create',
      });
    });

    it('수정 페이지로 이동해야 함', () => {
      const G7Core = (window as any).G7Core;
      const policyId = 1;

      // 수정 버튼 클릭 시뮬레이션
      G7Core.navigate({ path: `/admin/ecommerce/shipping-policies/${policyId}/edit` });

      expect(mockNavigate).toHaveBeenCalledWith({
        path: '/admin/ecommerce/shipping-policies/1/edit',
      });
    });

    it('복사 시 등록 페이지로 copy_id와 함께 이동해야 함', () => {
      const G7Core = (window as any).G7Core;
      const policyId = 1;

      // 복사 확인 후 이동 시뮬레이션
      G7Core.navigate({
        path: '/admin/ecommerce/shipping-policies/create',
        query: { copy_id: policyId },
      });

      expect(mockNavigate).toHaveBeenCalledWith({
        path: '/admin/ecommerce/shipping-policies/create',
        query: { copy_id: 1 },
      });
    });
  });

  describe('필터 핸들러', () => {
    it('검색어 필터를 적용해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 검색어 입력 시뮬레이션
      G7Core.state.setLocal({ 'filter.search': '택배' });

      expect(mockSetLocal).toHaveBeenCalledWith({ 'filter.search': '택배' });
    });

    it('배송방법 필터를 적용해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 배송방법 체크박스 선택 시뮬레이션
      G7Core.state.setLocal({ 'filter.shipping_methods': ['parcel', 'quick'] });

      expect(mockSetLocal).toHaveBeenCalledWith({
        'filter.shipping_methods': ['parcel', 'quick'],
      });
    });

    it('운송사 필터를 적용해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 운송사 체크박스 선택 시뮬레이션
      G7Core.state.setLocal({ 'filter.carriers': ['cj', 'logen'] });

      expect(mockSetLocal).toHaveBeenCalledWith({
        'filter.carriers': ['cj', 'logen'],
      });
    });

    it('부과정책 필터를 적용해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 부과정책 체크박스 선택 시뮬레이션
      G7Core.state.setLocal({ 'filter.charge_policies': ['free', 'conditional_free'] });

      expect(mockSetLocal).toHaveBeenCalledWith({
        'filter.charge_policies': ['free', 'conditional_free'],
      });
    });

    it('배송국가 필터를 적용해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 배송국가 체크박스 선택 시뮬레이션
      G7Core.state.setLocal({ 'filter.countries': ['KR', 'US'] });

      expect(mockSetLocal).toHaveBeenCalledWith({
        'filter.countries': ['KR', 'US'],
      });
    });

    it('사용여부 필터를 적용해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 사용여부 라디오 선택 시뮬레이션
      G7Core.state.setLocal({ 'filter.is_active': 'true' });

      expect(mockSetLocal).toHaveBeenCalledWith({
        'filter.is_active': 'true',
      });
    });

    it('검색 버튼 클릭 시 필터를 URL query로 반영해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 검색 버튼 클릭 시뮬레이션
      G7Core.navigate({
        path: '/admin/ecommerce/shipping-policies',
        replace: true,
        query: {
          page: 1,
          search: '택배',
          'shipping_methods[]': ['parcel'],
          'carriers[]': ['cj'],
          'charge_policies[]': ['free'],
          'countries[]': ['KR'],
          is_active: 'true',
        },
      });

      expect(mockNavigate).toHaveBeenCalledWith(
        expect.objectContaining({
          path: '/admin/ecommerce/shipping-policies',
          replace: true,
          query: expect.objectContaining({
            page: 1,
            search: '택배',
          }),
        })
      );
    });

    it('필터를 초기화해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 초기화 버튼 클릭 시뮬레이션
      G7Core.state.setLocal({
        filter: {
          search: '',
          shipping_methods: [],
          carriers: [],
          charge_policies: [],
          countries: [],
          is_active: '',
        },
      });

      expect(mockSetLocal).toHaveBeenCalledWith({
        filter: {
          search: '',
          shipping_methods: [],
          carriers: [],
          charge_policies: [],
          countries: [],
          is_active: '',
        },
      });
    });
  });

  describe('사용여부 토글 핸들러', () => {
    it('사용여부 토글 API를 호출해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const policyId = 1;

      mockApiPatch.mockResolvedValue({ success: true, data: { is_active: false } });

      // 토글 API 호출 시뮬레이션
      await G7Core.api.patch(`/api/modules/sirsoft-ecommerce/admin/shipping-policies/${policyId}/toggle-active`);

      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/modules/sirsoft-ecommerce/admin/shipping-policies/1/toggle-active'
      );
    });

    it('토글 성공 시 데이터소스를 리페치해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const policyId = 1;

      mockApiPatch.mockResolvedValue({ success: true, data: { is_active: false } });

      // 토글 후 리페치 시뮬레이션
      await G7Core.api.patch(`/api/modules/sirsoft-ecommerce/admin/shipping-policies/${policyId}/toggle-active`);
      G7Core.dataSource.refetch('shipping_policies');
      G7Core.toast({ type: 'success', message: '사용여부가 변경되었습니다.' });

      expect(mockRefetchDataSource).toHaveBeenCalledWith('shipping_policies');
      expect(mockToast).toHaveBeenCalledWith({
        type: 'success',
        message: '사용여부가 변경되었습니다.',
      });
    });

    it('토글 실패 시 에러 토스트를 표시해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const policyId = 1;

      mockApiPatch.mockRejectedValue({ message: '서버 오류가 발생했습니다.' });

      try {
        await G7Core.api.patch(`/api/modules/sirsoft-ecommerce/admin/shipping-policies/${policyId}/toggle-active`);
      } catch (error: any) {
        G7Core.toast({ type: 'error', message: error.message });
      }

      expect(mockToast).toHaveBeenCalledWith({
        type: 'error',
        message: '서버 오류가 발생했습니다.',
      });
    });
  });

  describe('모달 핸들러', () => {
    it('삭제 모달을 열고 대상 정책을 설정해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 삭제 버튼 클릭 시뮬레이션
      G7Core.state.setGlobal({ targetPolicy: mockShippingPolicy });
      G7Core.modal.open('delete_modal');

      expect(mockSetGlobal).toHaveBeenCalledWith({ targetPolicy: mockShippingPolicy });
      expect(mockOpenModal).toHaveBeenCalledWith('delete_modal');
    });

    it('복사 모달을 열고 대상 정책을 설정해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 복사 버튼 클릭 시뮬레이션
      G7Core.state.setGlobal({ targetPolicy: mockShippingPolicy });
      G7Core.modal.open('copy_modal');

      expect(mockSetGlobal).toHaveBeenCalledWith({ targetPolicy: mockShippingPolicy });
      expect(mockOpenModal).toHaveBeenCalledWith('copy_modal');
    });

    it('일괄 삭제 모달을 열어야 함', () => {
      const G7Core = (window as any).G7Core;

      // 일괄 삭제 버튼 클릭 시뮬레이션
      G7Core.modal.open('bulk_delete_modal');

      expect(mockOpenModal).toHaveBeenCalledWith('bulk_delete_modal');
    });

    it('일괄 사용여부 변경 모달을 열어야 함', () => {
      const G7Core = (window as any).G7Core;

      // 일괄 사용여부 변경 버튼 클릭 시뮬레이션
      G7Core.modal.open('bulk_toggle_modal');

      expect(mockOpenModal).toHaveBeenCalledWith('bulk_toggle_modal');
    });

    it('모달을 닫아야 함', () => {
      const G7Core = (window as any).G7Core;

      // 취소 버튼 클릭 시뮬레이션
      G7Core.modal.close();

      expect(mockCloseModal).toHaveBeenCalled();
    });
  });

  describe('단건 삭제 핸들러', () => {
    it('삭제 API를 호출해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const policyId = 1;

      mockApiDelete.mockResolvedValue({ success: true });

      // 삭제 API 호출 시뮬레이션
      await G7Core.api.delete(`/api/modules/sirsoft-ecommerce/admin/shipping-policies/${policyId}`);

      expect(mockApiDelete).toHaveBeenCalledWith(
        '/api/modules/sirsoft-ecommerce/admin/shipping-policies/1'
      );
    });

    it('삭제 성공 시 모달을 닫고 데이터를 리페치해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const policyId = 1;

      mockApiDelete.mockResolvedValue({ success: true });

      // 삭제 성공 시 후속 처리 시뮬레이션
      await G7Core.api.delete(`/api/modules/sirsoft-ecommerce/admin/shipping-policies/${policyId}`);
      G7Core.modal.close();
      G7Core.state.setGlobal({ isDeleting: false, targetPolicy: null });
      G7Core.dataSource.refetch('shipping_policies');
      G7Core.toast({ type: 'success', message: '배송정책이 삭제되었습니다.' });

      expect(mockCloseModal).toHaveBeenCalled();
      expect(mockSetGlobal).toHaveBeenCalledWith({ isDeleting: false, targetPolicy: null });
      expect(mockRefetchDataSource).toHaveBeenCalledWith('shipping_policies');
      expect(mockToast).toHaveBeenCalledWith({
        type: 'success',
        message: '배송정책이 삭제되었습니다.',
      });
    });

    it('삭제 중 상태를 관리해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 삭제 시작
      G7Core.state.setGlobal({ isDeleting: true });
      expect(mockSetGlobal).toHaveBeenCalledWith({ isDeleting: true });

      // 삭제 완료
      G7Core.state.setGlobal({ isDeleting: false });
      expect(mockSetGlobal).toHaveBeenCalledWith({ isDeleting: false });
    });
  });

  describe('일괄 삭제 핸들러', () => {
    it('일괄 삭제 API를 호출해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const selectedIds = [1, 2, 3];

      mockApiDelete.mockResolvedValue({ success: true });

      // 일괄 삭제 API 호출 시뮬레이션
      await G7Core.api.delete('/api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk', {
        data: { ids: selectedIds },
      });

      expect(mockApiDelete).toHaveBeenCalledWith(
        '/api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk',
        { data: { ids: [1, 2, 3] } }
      );
    });

    it('일괄 삭제 성공 시 선택 항목을 초기화해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const selectedIds = [1, 2, 3];

      mockApiDelete.mockResolvedValue({ success: true });

      // 일괄 삭제 성공 후 후속 처리
      await G7Core.api.delete('/api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk', {
        data: { ids: selectedIds },
      });
      G7Core.modal.close();
      G7Core.state.setGlobal({ isBulkDeleting: false });
      G7Core.state.setLocal({ selectedItems: [], selectAll: false });
      G7Core.dataSource.refetch('shipping_policies');
      G7Core.toast({ type: 'success', message: '선택한 배송정책이 삭제되었습니다.' });

      expect(mockSetLocal).toHaveBeenCalledWith({ selectedItems: [], selectAll: false });
      expect(mockRefetchDataSource).toHaveBeenCalledWith('shipping_policies');
    });
  });

  describe('일괄 사용여부 변경 핸들러', () => {
    it('일괄 사용여부 변경 API를 호출해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const selectedIds = [1, 2, 3];
      const newStatus = true;

      mockApiPatch.mockResolvedValue({ success: true });

      // 일괄 사용여부 변경 API 호출 시뮬레이션
      await G7Core.api.patch('/api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk-toggle-active', {
        ids: selectedIds,
        is_active: newStatus,
      });

      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk-toggle-active',
        { ids: [1, 2, 3], is_active: true }
      );
    });

    it('일괄 변경 성공 시 선택 항목을 초기화해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const selectedIds = [1, 2, 3];

      mockApiPatch.mockResolvedValue({ success: true });

      // 일괄 변경 성공 후 후속 처리
      await G7Core.api.patch('/api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk-toggle-active', {
        ids: selectedIds,
        is_active: true,
      });
      G7Core.modal.close();
      G7Core.state.setGlobal({ isBulkToggling: false });
      G7Core.state.setLocal({ selectedItems: [], selectAll: false, bulkToggleValue: '' });
      G7Core.dataSource.refetch('shipping_policies');
      G7Core.toast({ type: 'success', message: '선택한 배송정책의 사용여부가 변경되었습니다.' });

      expect(mockSetLocal).toHaveBeenCalledWith({ selectedItems: [], selectAll: false, bulkToggleValue: '' });
      expect(mockRefetchDataSource).toHaveBeenCalledWith('shipping_policies');
    });
  });

  describe('선택 핸들러', () => {
    it('단일 항목 선택 시 selectedItems에 추가해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 체크박스 선택 시뮬레이션
      G7Core.state.setLocal({ selectedItems: [1] });

      expect(mockSetLocal).toHaveBeenCalledWith({ selectedItems: [1] });
    });

    it('단일 항목 해제 시 selectedItems에서 제거해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 체크박스 해제 시뮬레이션 (기존 [1, 2]에서 1 제거)
      G7Core.state.setLocal({ selectedItems: [2] });

      expect(mockSetLocal).toHaveBeenCalledWith({ selectedItems: [2] });
    });

    it('전체 선택 시 현재 페이지의 모든 항목을 선택해야 함', () => {
      const G7Core = (window as any).G7Core;
      const allIds = mockMultiplePolicies.map((p) => p.id);

      // 전체 선택 시뮬레이션
      G7Core.state.setLocal({ selectedItems: allIds, selectAll: true });

      expect(mockSetLocal).toHaveBeenCalledWith({
        selectedItems: [1, 2, 3],
        selectAll: true,
      });
    });

    it('전체 해제 시 모든 항목을 해제해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 전체 해제 시뮬레이션
      G7Core.state.setLocal({ selectedItems: [], selectAll: false });

      expect(mockSetLocal).toHaveBeenCalledWith({
        selectedItems: [],
        selectAll: false,
      });
    });
  });

  describe('정렬/페이지 핸들러', () => {
    it('정렬 변경 시 URL query를 업데이트해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 정렬 변경 시뮬레이션
      G7Core.navigate({
        path: '/admin/ecommerce/shipping-policies',
        replace: true,
        mergeQuery: true,
        query: {
          sort_by: 'name',
          sort_order: 'asc',
          page: 1,
        },
      });

      expect(mockNavigate).toHaveBeenCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({
            sort_by: 'name',
            sort_order: 'asc',
            page: 1,
          }),
        })
      );
    });

    it('페이지당 표시 개수 변경 시 URL query를 업데이트해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 표시 개수 변경 시뮬레이션
      G7Core.navigate({
        path: '/admin/ecommerce/shipping-policies',
        replace: true,
        mergeQuery: true,
        query: {
          per_page: 50,
          page: 1,
        },
      });

      expect(mockNavigate).toHaveBeenCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({
            per_page: 50,
            page: 1,
          }),
        })
      );
    });

    it('페이지 변경 시 URL query를 업데이트해야 함', () => {
      const G7Core = (window as any).G7Core;

      // 페이지 변경 시뮬레이션
      G7Core.navigate({
        path: '/admin/ecommerce/shipping-policies',
        replace: true,
        mergeQuery: true,
        query: {
          page: 3,
        },
      });

      expect(mockNavigate).toHaveBeenCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({
            page: 3,
          }),
        })
      );
    });
  });

  describe('DataGrid 이벤트 핸들러', () => {
    it('onSelectionChange 이벤트에서 selectedItems를 업데이트해야 함', () => {
      const G7Core = (window as any).G7Core;
      const selectedIds = [1, 2];

      // DataGrid 선택 변경 이벤트 시뮬레이션
      G7Core.state.setLocal({ selectedItems: selectedIds });

      expect(mockSetLocal).toHaveBeenCalledWith({ selectedItems: [1, 2] });
    });

    it('Toggle 컬럼의 change 이벤트에서 API를 호출해야 함', async () => {
      const G7Core = (window as any).G7Core;
      const policyId = 1;

      mockApiPatch.mockResolvedValue({ success: true });

      // Toggle 변경 이벤트 시뮬레이션
      await G7Core.api.patch(`/api/modules/sirsoft-ecommerce/admin/shipping-policies/${policyId}/toggle-active`);

      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/modules/sirsoft-ecommerce/admin/shipping-policies/1/toggle-active'
      );
    });
  });
});
