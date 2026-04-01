/**
 * @file storageHandlers.test.ts
 * @description 스토리지 핸들러 테스트
 *
 * 테스트 케이스 (#141~#148, 8개)
 * - localStorage 핸들러: #141~#144 (4개)
 * - initCartKey/옵션 관련: #145~#148 (4개)
 *
 * 핸들러는 ActionDispatcher의 (action, context) 시그니처를 따릅니다.
 * - action.params에서 파라미터를 읽음
 * - G7Core.state.set()으로 전역 상태 설정
 * - G7Config.user로 현재 사용자 확인
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  initCartKeyHandler,
  getCartKeyHandler,
  clearCartKeyHandler,
  regenerateCartKeyHandler,
  saveToStorageHandler,
  loadFromStorageHandler,
} from '../storageHandlers';

/**
 * localStorage Mock 설정
 */
const mockLocalStorage = (() => {
  let store: Record<string, string> = {};
  return {
    getItem: vi.fn((key: string) => store[key] || null),
    setItem: vi.fn((key: string, value: string) => {
      store[key] = value;
    }),
    removeItem: vi.fn((key: string) => {
      delete store[key];
    }),
    clear: vi.fn(() => {
      store = {};
    }),
    get store() {
      return store;
    },
    reset() {
      store = {};
      this.getItem.mockClear();
      this.setItem.mockClear();
      this.removeItem.mockClear();
      this.clear.mockClear();
    },
  };
})();

/**
 * G7Core Mock 설정
 */
const mockG7Core = {
  state: {
    get: vi.fn(() => ({})),
    set: vi.fn(),
  },
  createLogger: vi.fn(() => ({
    log: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
  })),
};

/**
 * G7Config Mock 설정
 */
let mockG7Config: { user: any } = { user: null };

describe('storageHandlers', () => {
  beforeEach(() => {
    // localStorage Mock 설정
    mockLocalStorage.reset();
    Object.defineProperty(window, 'localStorage', {
      value: mockLocalStorage,
      writable: true,
    });

    // G7Core Mock 설정
    mockG7Core.state.get.mockClear();
    mockG7Core.state.set.mockClear();
    (window as any).G7Core = mockG7Core;

    // G7Config Mock 설정 (비로그인 상태 기본값)
    mockG7Config = { user: null };
    (window as any).G7Config = mockG7Config;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
    delete (window as any).G7Config;
  });

  describe('localStorage 핸들러', () => {
    // #141: saveToStorage
    it('#141 saveToStorage가 localStorage.setItem을 호출한다', () => {
      // Given
      const action = {
        params: {
          key: 'g7_cart_key',
          value: 'ck_abc123',
        },
      };

      // When
      saveToStorageHandler(action);

      // Then
      expect(mockLocalStorage.setItem).toHaveBeenCalledWith('g7_cart_key', 'ck_abc123');
    });

    // #142: loadFromStorage
    it('#142 loadFromStorage가 localStorage.getItem 값을 반환한다', () => {
      // Given
      mockLocalStorage.setItem('g7_cart_key', 'ck_xyz789');
      const action = { params: { key: 'g7_cart_key' } };

      // When
      const result = loadFromStorageHandler(action);

      // Then
      expect(result).toBe('ck_xyz789');
    });

    // #143: clearCartKey (removeItem 호출)
    it('#143 clearCartKey가 localStorage.removeItem을 호출한다', () => {
      // Given
      mockLocalStorage.setItem('g7_cart_key', 'ck_abc123');

      // When
      clearCartKeyHandler();

      // Then
      expect(mockLocalStorage.removeItem).toHaveBeenCalledWith('g7_cart_key');
      expect(mockG7Core.state.set).toHaveBeenCalledWith({ cartKey: null });
    });

    // #144: 존재하지 않는 키 조회
    it('#144 존재하지 않는 키 조회 시 null을 반환한다', () => {
      // Given
      const action = { params: { key: 'unknown_key' } };

      // When
      const result = loadFromStorageHandler(action);

      // Then
      expect(result).toBeNull();
    });
  });

  describe('initCartKey 핸들러', () => {
    // #145: 비로그인 사용자 - 기존 cartKey 로드
    it('#145 기존 cartKey가 있으면 로드한다', () => {
      // Given
      mockG7Config.user = null;
      mockLocalStorage.setItem('g7_cart_key', 'existing-cart-key');

      // When
      initCartKeyHandler();

      // Then
      expect(mockG7Core.state.set).toHaveBeenCalledWith({ cartKey: 'existing-cart-key' });
    });

    // #146: 비로그인 사용자 - 새 cartKey 생성
    it('#146 기존 cartKey가 없으면 새로 생성한다', () => {
      // Given
      mockG7Config.user = null;
      // localStorage는 비어있음

      // When
      initCartKeyHandler();

      // Then
      // 새로운 UUID가 생성되어 저장됨
      expect(mockLocalStorage.setItem).toHaveBeenCalled();
      expect(mockG7Core.state.set).toHaveBeenCalledWith(expect.objectContaining({
        cartKey: expect.any(String),
      }));
    });

    // #147: 로그인 사용자도 cartKey 생성 (API 헤더에 포함 필요)
    it('#147 로그인 사용자도 cartKey를 생성한다', () => {
      // Given
      mockG7Config.user = { id: 1, name: 'User' };
      // localStorage는 비어있음

      // When
      initCartKeyHandler();

      // Then: 새로운 cartKey가 생성됨 (ck_ + 32자 영숫자 형식)
      expect(mockLocalStorage.setItem).toHaveBeenCalled();
      expect(mockG7Core.state.set).toHaveBeenCalledWith(expect.objectContaining({
        cartKey: expect.stringMatching(/^ck_[a-zA-Z0-9]{32}$/),
      }));
    });

    // #148: regenerateCartKey - 새 키 생성
    it('#148 regenerateCartKey가 새로운 키를 생성한다', () => {
      // Given
      mockG7Config.user = null;
      mockLocalStorage.setItem('g7_cart_key', 'old-key');

      // When
      regenerateCartKeyHandler();

      // Then
      // 새로운 UUID가 생성되어 저장됨 (old-key와 다름)
      expect(mockLocalStorage.setItem).toHaveBeenCalled();
      const lastCallArgs = mockLocalStorage.setItem.mock.calls[mockLocalStorage.setItem.mock.calls.length - 1];
      expect(lastCallArgs[0]).toBe('g7_cart_key');
      expect(lastCallArgs[1]).not.toBe('old-key');
      expect(mockG7Core.state.set).toHaveBeenCalledWith(expect.objectContaining({
        cartKey: expect.any(String),
      }));
    });
  });

  describe('getCartKeyHandler', () => {
    it('현재 저장된 cartKey를 반환한다', () => {
      // Given
      mockLocalStorage.setItem('g7_cart_key', 'test-cart-key');

      // When
      const result = getCartKeyHandler();

      // Then
      expect(result).toBe('test-cart-key');
    });

    it('저장된 cartKey가 없으면 null을 반환한다', () => {
      // When
      const result = getCartKeyHandler();

      // Then
      expect(result).toBeNull();
    });
  });

  describe('loadFromStorage with defaultValue', () => {
    it('값이 없을 때 defaultValue를 반환한다', () => {
      // Given
      const action = { params: { key: 'nonexistent', defaultValue: 'fallback' } };

      // When
      const result = loadFromStorageHandler(action);

      // Then
      expect(result).toBe('fallback');
    });
  });
});
