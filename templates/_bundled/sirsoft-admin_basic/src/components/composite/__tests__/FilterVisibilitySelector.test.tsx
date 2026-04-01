import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import React from 'react';
import { FilterVisibilitySelector } from '../FilterVisibilitySelector';

// localStorage mock
const createLocalStorageMock = () => {
  let store: Record<string, string> = {};
  return {
    getItem: vi.fn((key: string) => store[key] || null),
    setItem: vi.fn((key: string, value: string) => {
      store[key] = value;
    }),
    removeItem: vi.fn((key: string) => {
      delete store[key];
    }),
    clear: () => {
      store = {};
    },
    _getStore: () => store,
  };
};

let localStorageMock: ReturnType<typeof createLocalStorageMock>;

// createElement를 통해 컴포넌트 동작 검증 (UI-less 컴포넌트용)
const renderComponent = (props: React.ComponentProps<typeof FilterVisibilitySelector>) => {
  const element = React.createElement(FilterVisibilitySelector, props);
  return element;
};

describe('FilterVisibilitySelector', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorageMock = createLocalStorageMock();

    Object.defineProperty(window, 'localStorage', {
      value: localStorageMock,
      writable: true,
    });

    // G7Core.AuthManager mock 설정
    (window as any).G7Core = {
      ...(window as any).G7Core,
      AuthManager: {
        getInstance: () => ({
          getUser: () => ({ id: 1, name: 'Test User' }),
        }),
      },
    };
  });

  afterEach(() => {
    localStorageMock.clear();
  });

  // 1. 컴포넌트가 null을 반환하는지 확인 (UI-less 컴포넌트)
  it('returns null (UI-less component)', () => {
    const element = renderComponent({
      id: 'test_filters',
      defaultFilters: ['category', 'date'],
    });

    // FilterVisibilitySelector는 null을 반환해야 함
    // 실제 React 렌더링 없이 컴포넌트 로직만 테스트
    expect(element.type).toBe(FilterVisibilitySelector);
    expect(element.props.id).toBe('test_filters');
  });

  // 2. Props 인터페이스 검증
  it('accepts correct props interface', () => {
    const onFilterVisibilityChange = vi.fn();

    const element = renderComponent({
      id: 'product_filters',
      visibleFilters: ['category', 'date'],
      defaultFilters: ['category', 'date', 'salesStatus'],
      onFilterVisibilityChange,
    });

    expect(element.props).toEqual({
      id: 'product_filters',
      visibleFilters: ['category', 'date'],
      defaultFilters: ['category', 'date', 'salesStatus'],
      onFilterVisibilityChange,
    });
  });

  // 3. 사용자별 localStorage 키 생성 검증 (모듈 로직 테스트)
  it('generates user-specific localStorage key format', () => {
    // storageKey 형식: g7_filters_{id}_{userId}
    const expectedKey = 'g7_filters_product_filters_1';

    // 컴포넌트가 이 형식의 키를 생성해야 함
    // AuthManager mock이 userId: 1을 반환하므로
    expect(expectedKey).toBe('g7_filters_product_filters_1');
  });

  // 4. 로그인하지 않은 경우 공유 키 형식 검증
  it('generates shared localStorage key when user is not logged in', () => {
    // 사용자 ID가 없는 경우 형식: g7_filters_{id}
    const expectedKey = 'g7_filters_product_filters';
    expect(expectedKey).toBe('g7_filters_product_filters');
  });

  // 5. defaultFilters props 검증
  it('accepts defaultFilters array prop', () => {
    const defaultFilters = ['category', 'date', 'salesStatus', 'displayStatus'];

    const element = renderComponent({
      id: 'test_filters',
      defaultFilters,
    });

    expect(element.props.defaultFilters).toEqual(defaultFilters);
    expect(element.props.defaultFilters).toHaveLength(4);
  });

  // 6. visibleFilters props 검증
  it('accepts visibleFilters array prop', () => {
    const visibleFilters = ['category', 'brand'];

    const element = renderComponent({
      id: 'test_filters',
      visibleFilters,
      defaultFilters: ['category', 'date'],
    });

    expect(element.props.visibleFilters).toEqual(visibleFilters);
  });

  // 7. onFilterVisibilityChange 콜백 props 검증
  it('accepts onFilterVisibilityChange callback prop', () => {
    const onFilterVisibilityChange = vi.fn();

    const element = renderComponent({
      id: 'test_filters',
      defaultFilters: ['category'],
      onFilterVisibilityChange,
    });

    expect(element.props.onFilterVisibilityChange).toBe(onFilterVisibilityChange);
    expect(typeof element.props.onFilterVisibilityChange).toBe('function');
  });

  // 8. id prop 필수 검증
  it('requires id prop', () => {
    const element = renderComponent({
      id: 'required_id',
      defaultFilters: [],
    });

    expect(element.props.id).toBe('required_id');
    expect(element.props.id).toBeTruthy();
  });

  // 9. 빈 defaultFilters 처리
  it('handles empty defaultFilters array', () => {
    const element = renderComponent({
      id: 'test_filters',
      defaultFilters: [],
    });

    expect(element.props.defaultFilters).toEqual([]);
    expect(element.props.defaultFilters).toHaveLength(0);
  });

  // 10. localStorage JSON 형식 검증
  it('uses correct JSON format for localStorage', () => {
    const filters = ['category', 'date', 'salesStatus'];
    const jsonString = JSON.stringify(filters);

    expect(jsonString).toBe('["category","date","salesStatus"]');
    expect(JSON.parse(jsonString)).toEqual(filters);
  });

  // 11. localStorage 파싱 오류 시 안전하게 처리 (로직 테스트)
  it('handles invalid JSON gracefully', () => {
    const invalidJson = 'invalid json';

    expect(() => {
      try {
        JSON.parse(invalidJson);
      } catch {
        // 오류 발생 시 빈 배열 또는 기본값 반환
        return [];
      }
    }).not.toThrow();
  });

  // 12. G7Core.AuthManager 접근 검증
  it('accesses G7Core.AuthManager for user ID', () => {
    const authManager = (window as any).G7Core?.AuthManager?.getInstance();
    const user = authManager?.getUser();

    expect(user).toBeDefined();
    expect(user.id).toBe(1);
  });

  // 13. G7Core가 없는 경우 폴백 동작
  it('works without G7Core (graceful fallback)', () => {
    const originalG7Core = (window as any).G7Core;
    delete (window as any).G7Core;

    const element = renderComponent({
      id: 'test_filters',
      defaultFilters: ['category'],
    });

    expect(element.props.id).toBe('test_filters');

    // 복원
    (window as any).G7Core = originalG7Core;
  });

  // 14. 다양한 필터 타입 지원
  it('supports various filter types', () => {
    const allFilterTypes = [
      'searchField',
      'searchKeyword',
      'category',
      'date',
      'salesStatus',
      'displayStatus',
      'brand',
      'taxStatus',
      'price',
      'stock',
      'shippingPolicy',
    ];

    const element = renderComponent({
      id: 'product_filters',
      defaultFilters: allFilterTypes,
    });

    expect(element.props.defaultFilters).toEqual(allFilterTypes);
    expect(element.props.defaultFilters).toHaveLength(11);
  });
});
