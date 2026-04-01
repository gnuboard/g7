/**
 * setLocaleHandler 테스트
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setLocaleHandler } from '../setLocaleHandler';

describe('setLocaleHandler', () => {
  // localStorage mock
  let localStorageMock: { [key: string]: string } = {};
  let setItemSpy: ReturnType<typeof vi.spyOn>;
  let reloadSpy: ReturnType<typeof vi.fn>;

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

    // window.location.reload mock
    reloadSpy = vi.fn();
    delete (window as any).location;
    (window as any).location = { reload: reloadSpy };

    // console 스파이 설정
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  describe('정상 동작', () => {
    it('이벤트 데이터에서 locale 값을 추출하여 localStorage에 저장하고 페이지를 리로드해야 함 (ko)', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}',
      };

      const context = {
        event: {
          target: { value: 'ko' },
        },
      };

      await setLocaleHandler(action, context);

      expect(setItemSpy).toHaveBeenCalledWith('g7_locale', 'ko');
      expect(localStorageMock['g7_locale']).toBe('ko');
      expect(reloadSpy).toHaveBeenCalled();
    });

    it('이벤트 데이터에서 locale 값을 추출하여 localStorage에 저장하고 페이지를 리로드해야 함 (en)', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}',
      };

      const context = {
        event: {
          target: { value: 'en' },
        },
      };

      await setLocaleHandler(action, context);

      expect(setItemSpy).toHaveBeenCalledWith('g7_locale', 'en');
      expect(localStorageMock['g7_locale']).toBe('en');
      expect(reloadSpy).toHaveBeenCalled();
    });

    it('React Select 형태의 이벤트 데이터를 처리해야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}',
      };

      const context = {
        event: {
          value: 'ko', // React Select는 value를 직접 전달
        },
      };

      await setLocaleHandler(action, context);

      expect(setItemSpy).toHaveBeenCalledWith('g7_locale', 'ko');
      expect(localStorageMock['g7_locale']).toBe('ko');
      expect(reloadSpy).toHaveBeenCalled();
    });

    it('action.target에 직접 locale이 지정된 경우 처리해야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: 'ko', // 템플릿 문자열이 아닌 직접 값
      };

      await setLocaleHandler(action, undefined);

      expect(setItemSpy).toHaveBeenCalledWith('g7_locale', 'ko');
      expect(localStorageMock['g7_locale']).toBe('ko');
      expect(reloadSpy).toHaveBeenCalled();
    });
  });

  describe('예외 처리', () => {
    it('지원하지 않는 locale은 경고를 출력하고 localStorage에 저장하지 않아야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}',
      };

      const context = {
        event: {
          target: { value: 'fr' }, // 지원하지 않는 언어
        },
      };

      await setLocaleHandler(action, context);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetLocale]', 'Unsupported locale:', 'fr');
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('locale 값이 없으면 경고를 출력하고 localStorage에 저장하지 않아야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}',
      };

      const context = {
        event: {
          target: { value: undefined },
        },
      };

      await setLocaleHandler(action, context);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetLocale]', 'Invalid locale:', undefined);
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('템플릿 문자열이 그대로 전달되면 경고를 출력하고 localStorage에 저장하지 않아야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}', // 템플릿 문자열 그대로
      };

      // 이벤트 데이터 없음
      await setLocaleHandler(action, undefined);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetLocale]', 'Invalid locale:', undefined);
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('locale 값이 문자열이 아니면 경고를 출력하고 localStorage에 저장하지 않아야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}',
      };

      const context = {
        event: {
          target: { value: 123 }, // 숫자
        },
      };

      await setLocaleHandler(action, context);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetLocale]', 'Invalid locale:', 123);
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('빈 문자열은 경고를 출력하고 localStorage에 저장하지 않아야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}',
      };

      const context = {
        event: {
          target: { value: '' },
        },
      };

      await setLocaleHandler(action, context);

      expect(console.warn).toHaveBeenCalledWith('[Handler:SetLocale]', 'Invalid locale:', '');
      expect(setItemSpy).not.toHaveBeenCalled();
      expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('localStorage 저장 실패 시 오류를 출력하고 리로드하지 않아야 함', async () => {
      // localStorage.setItem이 실패하도록 모의
      const setItemError = new Error('Storage quota exceeded');
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw setItemError;
      });

      const action = {
        type: 'change',
        handler: 'setLocale',
        target: '{{event.target.value}}',
      };

      const context = {
        event: {
          target: { value: 'ko' },
        },
      };

      await setLocaleHandler(action, context);

      expect(console.error).toHaveBeenCalledWith(
        '[Handler:SetLocale]',
        'Failed to save locale to localStorage:',
        setItemError
      );
      expect(window.location.reload).not.toHaveBeenCalled();
    });
  });

  describe('다양한 이벤트 데이터 형식', () => {
    it('context.event.target.value가 우선순위가 가장 높아야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: 'en', // 직접 값 지정
      };

      const context = {
        event: {
          target: { value: 'ko' }, // 이벤트 데이터 우선
          value: 'en', // React Select 형식도 있음
        },
      };

      await setLocaleHandler(action, context);

      // context.event.target.value가 우선되어야 함
      expect(setItemSpy).toHaveBeenCalledWith('g7_locale', 'ko');
      expect(localStorageMock['g7_locale']).toBe('ko');
      expect(reloadSpy).toHaveBeenCalled();
    });

    it('context.event.value가 두 번째 우선순위를 가져야 함', async () => {
      const action = {
        type: 'change',
        handler: 'setLocale',
        target: 'en', // 직접 값 지정
      };

      const context = {
        event: {
          value: 'ko', // React Select 형식
        },
      };

      await setLocaleHandler(action, context);

      // context.event.value가 action.target보다 우선되어야 함
      expect(setItemSpy).toHaveBeenCalledWith('g7_locale', 'ko');
      expect(localStorageMock['g7_locale']).toBe('ko');
      expect(reloadSpy).toHaveBeenCalled();
    });
  });
});
