/**
 * @file cartHandlers.test.ts
 * @description 장바구니 핸들러 테스트
 *
 * 테스트 케이스 (#129~#140, 12개)
 * - toggleCartItemSelection: #129~#131 (3개)
 * - toggleAllCartItems: #132~#133 (2개)
 * - recalculateCart: #134~#136 (3개)
 * - updateCartItem: #137~#140 (4개)
 *
 * 핸들러는 ActionDispatcher의 (action, context) 시그니처를 따릅니다.
 * - action.params에서 파라미터를 읽음
 * - G7Core.state.setLocal()으로 로컬 상태 설정
 * - G7Core.state.get()으로 전역 상태 조회
 * - G7Core.toast.show()로 토스트 표시
 * - G7Core.t()로 번역
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  toggleCartItemSelectionHandler,
  selectAllCartItemsHandler,
  setCartOptionHandler,
  openCartDeleteModalHandler,
  openCartOptionModalHandler,
  recalculateCartHandler,
} from '../cartHandlers';

/**
 * G7Core Mock 설정
 */
const mockG7Core = {
  state: {
    get: vi.fn(() => ({})),
    getLocal: vi.fn(() => ({})),
    setLocal: vi.fn(),
  },
  toast: {
    show: vi.fn(),
  },
  t: vi.fn((key: string) => key),
  createLogger: vi.fn(() => ({
    log: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
  })),
};

describe('cartHandlers', () => {
  beforeEach(() => {
    // G7Core Mock 설정
    mockG7Core.state.get.mockClear();
    mockG7Core.state.getLocal.mockClear();
    mockG7Core.state.setLocal.mockClear();
    mockG7Core.toast.show.mockClear();
    mockG7Core.t.mockClear();
    (window as any).G7Core = mockG7Core;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  describe('toggleCartItemSelectionHandler', () => {
    // #129: 선택된 아이템 해제
    it('#129 선택된 아이템을 해제한다', () => {
      // Given
      mockG7Core.state.get.mockReturnValue({
        cartItems: {
          data: {
            items: [{ id: 1 }, { id: 2 }, { id: 3 }],
          },
        },
      });
      const action = {
        params: {
          itemId: 1,
          selectedItems: [1, 2, 3],
        },
      };

      // When
      toggleCartItemSelectionHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(expect.objectContaining({
        selectedItems: [2, 3],
        allSelected: false,
      }));
    });

    // #130: 미선택 아이템 선택
    it('#130 미선택 아이템을 선택한다', () => {
      // Given
      mockG7Core.state.get.mockReturnValue({
        cartItems: {
          data: {
            items: [{ id: 1 }, { id: 2 }, { id: 3 }],
          },
        },
      });
      const action = {
        params: {
          itemId: 3,
          selectedItems: [1, 2],
        },
      };

      // When
      toggleCartItemSelectionHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(expect.objectContaining({
        selectedItems: [1, 2, 3],
        allSelected: true,
      }));
    });

    // #131: 선택 변경 후 selectedCount 업데이트
    it('#131 선택 변경 후 selectedCount가 업데이트된다', () => {
      // Given
      mockG7Core.state.get.mockReturnValue({
        cartItems: {
          data: {
            items: [{ id: 1 }, { id: 2 }],
          },
        },
      });
      const action = {
        params: {
          itemId: 1,
          selectedItems: [1, 2],
        },
      };

      // When
      toggleCartItemSelectionHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(expect.objectContaining({
        selectedCount: 1,
      }));
    });
  });

  describe('selectAllCartItemsHandler (toggleAllCartItems)', () => {
    // #132: 전체 선택 상태에서 해제
    it('#132 전체 선택 상태에서 해제한다', () => {
      // Given
      const action = {
        params: {
          allItemIds: [1, 2, 3],
          isSelected: false,
        },
      };

      // When
      selectAllCartItemsHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
        selectedItems: [],
        allSelected: false,
        selectedCount: 0,
      });
    });

    // #133: 전체 미선택 상태에서 선택
    it('#133 전체 미선택 상태에서 선택한다', () => {
      // Given
      const action = {
        params: {
          allItemIds: [1, 2, 3],
          isSelected: true,
        },
      };

      // When
      selectAllCartItemsHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
        selectedItems: [1, 2, 3],
        allSelected: true,
        selectedCount: 3,
      });
    });
  });

  describe('recalculateCartHandler', () => {
    // #134: 선택 아이템 있을 때 계산 요청
    it('#134 선택 아이템이 있으면 isCalculating을 true로 설정한다', () => {
      // Given
      const action = {
        params: {
          selectedItems: [1, 2],
        },
      };

      // When
      recalculateCartHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isCalculating: true });
    });

    // #135: 선택 아이템 없을 때
    it('#135 선택 아이템이 없으면 isCalculating을 false로 설정한다', () => {
      // Given
      const action = {
        params: {
          selectedItems: [],
        },
      };

      // When
      recalculateCartHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isCalculating: false });
    });

    // #136: selectedItems가 null/undefined인 경우
    it('#136 selectedItems가 없으면 isCalculating을 false로 설정한다', () => {
      // Given
      const action = {
        params: {
          selectedItems: null as unknown as number[],
        },
      };

      // When
      recalculateCartHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isCalculating: false });
    });
  });

  describe('setCartOptionHandler (updateCartItem 옵션 설정)', () => {
    // #137: 옵션 값 설정
    it('#137 옵션 값을 설정한다', () => {
      // Given
      mockG7Core.state.getLocal.mockReturnValue({ selectedOptions: { color: 'red' } });
      const action = {
        params: {
          optionName: 'size',
          optionValue: 'XL',
        },
      };

      // When
      setCartOptionHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
        selectedOptions: {
          color: 'red',
          size: 'XL',
        },
      });
    });

    // #138: 기존 옵션 덮어쓰기
    it('#138 기존 옵션을 덮어쓴다', () => {
      // Given
      mockG7Core.state.getLocal.mockReturnValue({ selectedOptions: { color: 'red', size: 'M' } });
      const action = {
        params: {
          optionName: 'size',
          optionValue: 'L',
        },
      };

      // When
      setCartOptionHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
        selectedOptions: {
          color: 'red',
          size: 'L',
        },
      });
    });
  });

  describe('openCartDeleteModalHandler', () => {
    // #139: 삭제 확인 모달 열기
    it('#139 삭제 확인 모달을 연다', () => {
      // Given
      const action = {
        params: {
          targetIds: [1, 2],
        },
      };

      // When
      openCartDeleteModalHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
        deleteModal: {
          visible: true,
          targetIds: [1, 2],
        },
      });
    });

    // #140: 빈 targetIds일 때 경고 토스트
    it('#140 targetIds가 비어있으면 경고 토스트를 표시한다', () => {
      // Given
      const action = {
        params: {
          targetIds: [],
        },
      };

      // When
      openCartDeleteModalHandler(action);

      // Then
      expect(mockG7Core.toast.show).toHaveBeenCalledWith(
        'shop.cart.select_items_to_delete',
        'warning'
      );
      expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
    });
  });

  describe('openCartOptionModalHandler', () => {
    it('옵션 변경 모달을 열고 초기값을 설정한다', () => {
      // Given
      const targetItem = {
        id: 1,
        product_name: '테스트 상품',
        thumbnail: '/img/test.jpg',
        unit_price_formatted: '₩10,000',
        options: { color: 'red', size: 'M' },
        available_options: { color: ['red', 'blue'], size: ['M', 'L'] },
        quantity: 2,
        stock: 10,
      };
      const action = {
        params: { targetItem },
      };

      // When
      openCartOptionModalHandler(action);

      // Then
      expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
        optionModal: {
          visible: true,
          targetItem,
        },
        selectedOptions: { color: 'red', size: 'M' },
        selectedQuantity: 2,
      });
    });
  });
});
