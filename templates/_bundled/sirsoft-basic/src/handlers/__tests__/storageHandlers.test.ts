/**
 * @file storageHandlers.test.ts
 * @description 스토리지 핸들러 테스트
 *
 * 테스트 케이스 (141~148, 8개)
 * - localStorage 핸들러: 141~144 (4개)
 * - initCartKey/옵션 관련: 145~148 (4개)
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
  initGuestOrderTokenHandler,
  saveGuestOrderTokenHandler,
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
 * sessionStorage Mock 설정 (비회원 주문 토큰용)
 */
const mockSessionStorage = (() => {
  let store: Record<string, string> = {};
  return {
    getItem: vi.fn((key: string) => store[key] ?? null),
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
    subscribe: vi.fn(() => vi.fn()),
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

    // sessionStorage Mock 설정 (비회원 주문 토큰)
    mockSessionStorage.reset();
    Object.defineProperty(window, 'sessionStorage', {
      value: mockSessionStorage,
      writable: true,
    });

    // G7Core Mock 설정
    mockG7Core.state.get.mockClear();
    mockG7Core.state.set.mockClear();
    mockG7Core.state.subscribe.mockClear();
    mockG7Core.state.subscribe.mockImplementation(() => vi.fn());
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
    // 141: saveToStorage
    it('141 saveToStorage가 localStorage.setItem을 호출한다', () => {
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

    // 142: loadFromStorage
    it('142 loadFromStorage가 localStorage.getItem 값을 반환한다', () => {
      // Given
      mockLocalStorage.setItem('g7_cart_key', 'ck_xyz789');
      const action = { params: { key: 'g7_cart_key' } };

      // When
      const result = loadFromStorageHandler(action);

      // Then
      expect(result).toBe('ck_xyz789');
    });

    // 143: clearCartKey (removeItem 호출)
    it('143 clearCartKey가 localStorage.removeItem을 호출한다', () => {
      // Given
      mockLocalStorage.setItem('g7_cart_key', 'ck_abc123');

      // When
      clearCartKeyHandler();

      // Then
      expect(mockLocalStorage.removeItem).toHaveBeenCalledWith('g7_cart_key');
      expect(mockG7Core.state.set).toHaveBeenCalledWith({ cartKey: null });
    });

    // 144: 존재하지 않는 키 조회
    it('144 존재하지 않는 키 조회 시 null을 반환한다', () => {
      // Given
      const action = { params: { key: 'unknown_key' } };

      // When
      const result = loadFromStorageHandler(action);

      // Then
      expect(result).toBeNull();
    });
  });

  // initCartKeyHandler 구현: localStorage 에 키 있으면 로드, 없으면 백엔드 API 로 발급.
  // 로컬 UUID 생성이 아니라 /api/modules/sirsoft-ecommerce/cart/key 호출 방식.
  describe('initCartKey 핸들러', () => {
    // 145: 기존 cartKey 로드 (로그인/비로그인 공통)
    it('145 기존 cartKey가 있으면 API 호출 없이 로드한다', async () => {
      // Given
      mockG7Config.user = null;
      mockLocalStorage.setItem('g7_cart_key', 'existing-cart-key');
      const fetchSpy = vi.spyOn(global, 'fetch');

      // When
      await initCartKeyHandler();

      // Then
      expect(fetchSpy).not.toHaveBeenCalled();
      expect(mockG7Core.state.set).toHaveBeenCalledWith({ cartKey: 'existing-cart-key' });
    });

    // 146: 비로그인 — 기존 cartKey 없으면 백엔드 API 에서 발급
    it('146 기존 cartKey가 없으면 백엔드 API로 발급하여 저장한다', async () => {
      // Given
      mockG7Config.user = null;
      const issuedKey = 'ck_issued_from_api';
      const fetchSpy = vi.spyOn(global, 'fetch').mockResolvedValue({
        ok: true,
        json: async () => ({ data: { cart_key: issuedKey } }),
      } as any);

      // When
      await initCartKeyHandler();

      // Then
      expect(fetchSpy).toHaveBeenCalledWith(
        '/api/modules/sirsoft-ecommerce/cart/key',
        expect.objectContaining({ method: 'POST' })
      );
      expect(mockLocalStorage.setItem).toHaveBeenCalledWith('g7_cart_key', issuedKey);
      expect(mockG7Core.state.set).toHaveBeenCalledWith({ cartKey: issuedKey });
    });

    // 147: 로그인 사용자도 cartKey 발급 경로는 동일 (API 헤더 포함 필요)
    it('147 로그인 사용자도 cartKey가 없으면 API로 발급한다', async () => {
      // Given
      mockG7Config.user = { id: 1, name: 'User' };
      const issuedKey = 'ck_logged_in_key';
      vi.spyOn(global, 'fetch').mockResolvedValue({
        ok: true,
        json: async () => ({ data: { cart_key: issuedKey } }),
      } as any);

      // When
      await initCartKeyHandler();

      // Then
      expect(mockLocalStorage.setItem).toHaveBeenCalledWith('g7_cart_key', issuedKey);
      expect(mockG7Core.state.set).toHaveBeenCalledWith({ cartKey: issuedKey });
    });

    // 148: regenerateCartKey — 비로그인 사용자는 API 로 새 키 발급
    it('148 regenerateCartKey가 비로그인 사용자에게 새로운 키를 발급한다', async () => {
      // Given
      mockG7Config.user = null;
      mockLocalStorage.setItem('g7_cart_key', 'old-key');
      const newKey = 'ck_newly_issued';
      vi.spyOn(global, 'fetch').mockResolvedValue({
        ok: true,
        json: async () => ({ data: { cart_key: newKey } }),
      } as any);

      // When
      await regenerateCartKeyHandler();

      // Then: 새 키가 setItem 으로 저장됨 (old-key 와 다름)
      const setItemCalls = mockLocalStorage.setItem.mock.calls;
      const last = setItemCalls[setItemCalls.length - 1];
      expect(last[0]).toBe('g7_cart_key');
      expect(last[1]).toBe(newKey);
      expect(last[1]).not.toBe('old-key');
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

  describe('비회원 주문 토큰 — initGuestOrderToken (verify→상세 SPA 전이 토큰 보존)', () => {
    const FUTURE = new Date(Date.now() + 30 * 60 * 1000).toISOString();
    const PAST = new Date(Date.now() - 60 * 1000).toISOString();

    it('sessionStorage 에 유효 토큰이 있으면 그 값으로 _global.guestOrderToken 을 set 한다 (새로고침/재방문 경로)', () => {
      mockSessionStorage.store['g7_guest_order_token'] = 'tok-from-session';
      mockSessionStorage.store['g7_guest_order_expires_at'] = FUTURE;
      mockG7Core.state.get.mockReturnValue({ guestOrderToken: null });

      initGuestOrderTokenHandler();

      expect(mockG7Core.state.set).toHaveBeenCalledWith({ guestOrderToken: 'tok-from-session' });
    });

    it('sessionStorage 가 비었지만 _global 에 in-memory 토큰이 있으면 보존한다 (조회 폼 verify→상세 SPA 전이, sessionStorage 미영속 환경 포함)', () => {
      // 회귀 핵심: 베이스 init_actions 재실행 시 빈 sessionStorage 를 읽어
      // saveGuestOrderToken 이 _global 에 동기 set 한 유효 토큰을 null 로 덮어쓰면
      // 상세 데이터소스가 X-Guest-Order-Token 없이 호출 → 404.
      mockG7Core.state.get.mockReturnValue({ guestOrderToken: 'tok-in-memory' });

      initGuestOrderTokenHandler();

      // null 로 덮어쓰지 않는다 — set 이 호출되지 않거나, 호출돼도 토큰을 비우지 않음
      const nulledCall = mockG7Core.state.set.mock.calls.find(
        (c) => c[0] && Object.prototype.hasOwnProperty.call(c[0], 'guestOrderToken') && c[0].guestOrderToken == null
      );
      expect(nulledCall, 'in-memory _global 토큰을 null 로 덮어쓰면 안 됨 (SPA 전이 토큰 누락 404 회귀)').toBeUndefined();
    });

    it('sessionStorage 도 비고 _global 도 비면 guestOrderToken 을 null 로 명시 설정한다 (비인증 방문자)', () => {
      mockG7Core.state.get.mockReturnValue({ guestOrderToken: null });

      initGuestOrderTokenHandler();

      expect(mockG7Core.state.set).toHaveBeenCalledWith({ guestOrderToken: null });
    });

    it('sessionStorage 토큰이 만료됐으면 폐기하고, in-memory 토큰이 있어도 보존하지 않는다 (만료 우선)', () => {
      mockSessionStorage.store['g7_guest_order_token'] = 'tok-expired';
      mockSessionStorage.store['g7_guest_order_expires_at'] = PAST;
      mockG7Core.state.get.mockReturnValue({ guestOrderToken: 'tok-in-memory' });

      initGuestOrderTokenHandler();

      // 만료 → sessionStorage 정리 + _global 도 null
      expect(mockSessionStorage.removeItem).toHaveBeenCalledWith('g7_guest_order_token');
      expect(mockG7Core.state.set).toHaveBeenCalledWith({ guestOrderToken: null });
    });
  });

  describe('비회원 주문 토큰 — saveGuestOrderToken', () => {
    it('token/orderNumber/expiresAt 3개 키를 sessionStorage 에 동기 기록하고 _global.guestOrderToken 을 set 한다', () => {
      const action = { params: { token: 'tok-1', orderNumber: 'ORD-1', expiresAt: '2099-01-01T00:00:00Z' } };

      saveGuestOrderTokenHandler(action);

      expect(mockSessionStorage.setItem).toHaveBeenCalledWith('g7_guest_order_token', 'tok-1');
      expect(mockSessionStorage.setItem).toHaveBeenCalledWith('g7_guest_order_number', 'ORD-1');
      expect(mockSessionStorage.setItem).toHaveBeenCalledWith('g7_guest_order_expires_at', '2099-01-01T00:00:00Z');
      expect(mockG7Core.state.set).toHaveBeenCalledWith({ guestOrderToken: 'tok-1' });
    });
  });

  // initPreferredCurrency 핸들러는 이커머스 모듈로 이전되었다.
  // 테스트: modules/_bundled/sirsoft-ecommerce/resources/js/__tests__/handlers/initPreferredCurrency.test.ts
});
