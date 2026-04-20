/**
 * sirsoft-ckeditor5 플러그인 레이아웃 렌더링 테스트 환경 설정
 * 코어 setup 패턴을 재사용합니다.
 */

import '@testing-library/jest-dom';
import { vi } from 'vitest';

// 전역 mocks
global.fetch = vi.fn();

// node 환경에서는 window가 없으므로 jsdom 전용 설정만 실행
if (typeof window === 'undefined') {
  // @vitest-environment node 테스트는 여기서 종료
} else {

/**
 * window.matchMedia 모킹
 */
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});

/**
 * G7Core 전역 모킹
 */
(window as any).G7Core = {
  createChangeEvent: vi.fn((data: { checked?: boolean; name?: string; value?: any }) => ({
    target: {
      checked: data.checked ?? false,
      name: data.name ?? '',
      value: data.value ?? (data.checked ? 'on' : 'off'),
      type: 'checkbox',
    },
    currentTarget: {
      checked: data.checked ?? false,
      name: data.name ?? '',
      value: data.value ?? (data.checked ? 'on' : 'off'),
      type: 'checkbox',
    },
    preventDefault: vi.fn(),
    stopPropagation: vi.fn(),
  })),
  state: {
    get: vi.fn(),
    set: vi.fn(),
    update: vi.fn(),
    subscribe: vi.fn(() => vi.fn()),
  },
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
  },
  modal: {
    open: vi.fn(),
    close: vi.fn(),
  },
  events: {
    emit: vi.fn(),
    on: vi.fn(() => vi.fn()),
    off: vi.fn(),
  },
  t: (key: string, params?: Record<string, string | number>) => {
    let translation = key;
    if (params) {
      Object.entries(params).forEach(([paramKey, paramValue]) => {
        translation = translation.replace(`{{${paramKey}}}`, String(paramValue));
      });
    }
    return translation;
  },
};

} // end of window check