/**
 * 비즈뿌리오 플러그인 레이아웃 렌더 테스트 환경 설정.
 *
 * jest-dom matcher(toBeInTheDocument 등)를 로드하고, jsdom 전역 mock 을 준비한다.
 * 코어 레이아웃 렌더 테스트(createLayoutTest)를 사용하는 테스트가 소비한다.
 */

import '@testing-library/jest-dom';
import { vi } from 'vitest';

if (typeof window !== 'undefined') {
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
}
