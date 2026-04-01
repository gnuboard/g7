/**
 * setThemeHandler 테스트
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setThemeHandler } from '../setThemeHandler';

describe('setThemeHandler', () => {
  // localStorage mock
  let localStorageMock: { [key: string]: string } = {};
  let setItemSpy: ReturnType<typeof vi.spyOn>;

  // DOM mock
  let classListAddSpy: ReturnType<typeof vi.fn>;
  let classListRemoveSpy: ReturnType<typeof vi.fn>;
  let setAttributeSpy: ReturnType<typeof vi.fn>;

  // matchMedia mock
  let matchMediaMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    // localStorage mock 설정
    localStorageMock = {};

    vi.spyOn(Storage.prototype, 'getItem').mockImplementation((key: string) => localStorageMock[key] || null);
    setItemSpy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation((key: string, value: string) => {
      localStorageMock[key] = value;
    });
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation((key: string) => {
      delete localStorageMock[key];
    });

    // DOM mock 설정
    classListAddSpy = vi.fn();
    classListRemoveSpy = vi.fn();
    setAttributeSpy = vi.fn();

    Object.defineProperty(document, 'documentElement', {
      value: {
        classList: {
          add: classListAddSpy,
          remove: classListRemoveSpy,
        },
        setAttribute: setAttributeSpy,
      },
      writable: true,
    });

    // matchMedia mock 설정 (기본: 라이트 모드)
    matchMediaMock = vi.fn().mockReturnValue({
      matches: false,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    });
    Object.defineProperty(window, 'matchMedia', {
      value: matchMediaMock,
      writable: true,
    });

    // console 스파이 설정
    vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('정상 동작', () => {
    it('light 테마를 설정해야 함', async () => {
      const action = {
        type: 'click',
        handler: 'setTheme',
        target: 'light',
      };

      await setThemeHandler(action);

      expect(setItemSpy).toHaveBeenCalledWith('g7_color_scheme', 'light');
      expect(setAttributeSpy).toHaveBeenCalledWith('data-theme', 'light');
      expect(classListRemoveSpy).toHaveBeenCalledWith('dark');
      expect(console.log).toHaveBeenCalledWith('[Handler:SetTheme]', 'Theme changed to:', 'light');
    });

    it('dark 테마를 설정해야 함', async () => {
      const action = {
        type: 'click',
        handler: 'setTheme',
        target: 'dark',
      };

      await setThemeHandler(action);

      expect(setItemSpy).toHaveBeenCalledWith('g7_color_scheme', 'dark');
      expect(setAttributeSpy).toHaveBeenCalledWith('data-theme', 'dark');
      expect(classListAddSpy).toHaveBeenCalledWith('dark');
      expect(console.log).toHaveBeenCalledWith('[Handler:SetTheme]', 'Theme changed to:', 'dark');
    });

    it('auto 테마를 설정하고 시스템이 라이트 모드일 때 light를 적용해야 함', async () => {
      // 시스템 라이트 모드
      matchMediaMock.mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      });

      const action = {
        type: 'click',
        handler: 'setTheme',
        target: 'auto',
      };

      await setThemeHandler(action);

      expect(setItemSpy).toHaveBeenCalledWith('g7_color_scheme', 'auto');
      expect(setAttributeSpy).toHaveBeenCalledWith('data-theme', 'light');
      expect(classListRemoveSpy).toHaveBeenCalledWith('dark');
    });

    it('auto 테마를 설정하고 시스템이 다크 모드일 때 dark를 적용해야 함', async () => {
      // 시스템 다크 모드
      matchMediaMock.mockReturnValue({
        matches: true,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      });

      const action = {
        type: 'click',
        handler: 'setTheme',
        target: 'auto',
      };

      await setThemeHandler(action);

      expect(setItemSpy).toHaveBeenCalledWith('g7_color_scheme', 'auto');
      expect(setAttributeSpy).toHaveBeenCalledWith('data-theme', 'dark');
      expect(classListAddSpy).toHaveBeenCalledWith('dark');
    });
  });

  describe('예외 처리', () => {
    it('지원하지 않는 테마는 경고를 출력하고 적용하지 않아야 함', async () => {
      const action = {
        type: 'click',
        handler: 'setTheme',
        target: 'sepia', // 지원하지 않는 테마
      };

      await setThemeHandler(action);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetTheme]', 'Unsupported theme:', 'sepia');
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(setAttributeSpy).not.toHaveBeenCalled();
    });

    it('테마 값이 없으면 경고를 출력하고 적용하지 않아야 함', async () => {
      const action = {
        type: 'click',
        handler: 'setTheme',
        target: undefined,
      };

      await setThemeHandler(action);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetTheme]', 'Invalid theme:', undefined);
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(setAttributeSpy).not.toHaveBeenCalled();
    });

    it('템플릿 문자열이 그대로 전달되면 경고를 출력하고 적용하지 않아야 함', async () => {
      const action = {
        type: 'click',
        handler: 'setTheme',
        target: '{{theme}}', // 템플릿 문자열 그대로
      };

      await setThemeHandler(action);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetTheme]', 'Invalid theme:', undefined);
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(setAttributeSpy).not.toHaveBeenCalled();
    });

    it('빈 문자열은 경고를 출력하고 적용하지 않아야 함', async () => {
      const action = {
        type: 'click',
        handler: 'setTheme',
        target: '',
      };

      await setThemeHandler(action);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetTheme]', 'Invalid theme:', undefined);
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(setAttributeSpy).not.toHaveBeenCalled();
    });

    it('localStorage 저장 실패 시 오류를 출력하고 테마를 적용하지 않아야 함', async () => {
      // localStorage.setItem이 실패하도록 모의
      const setItemError = new Error('Storage quota exceeded');
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw setItemError;
      });

      const action = {
        type: 'click',
        handler: 'setTheme',
        target: 'dark',
      };

      await setThemeHandler(action);

      expect(console.error).toHaveBeenCalledWith(
        '[Handler:SetTheme]',
        'Failed to save theme to localStorage:',
        setItemError
      );
      // 저장 실패 시 테마 적용도 하지 않음
      expect(setAttributeSpy).not.toHaveBeenCalled();
    });
  });

  describe('context 파라미터', () => {
    it('context가 undefined여도 정상 동작해야 함', async () => {
      const action = {
        type: 'click',
        handler: 'setTheme',
        target: 'light',
      };

      await setThemeHandler(action, undefined);

      expect(setItemSpy).toHaveBeenCalledWith('g7_color_scheme', 'light');
      expect(setAttributeSpy).toHaveBeenCalledWith('data-theme', 'light');
    });

    it('context가 빈 객체여도 정상 동작해야 함', async () => {
      const action = {
        type: 'click',
        handler: 'setTheme',
        target: 'dark',
      };

      await setThemeHandler(action, {});

      expect(setItemSpy).toHaveBeenCalledWith('g7_color_scheme', 'dark');
      expect(setAttributeSpy).toHaveBeenCalledWith('data-theme', 'dark');
    });
  });
});
