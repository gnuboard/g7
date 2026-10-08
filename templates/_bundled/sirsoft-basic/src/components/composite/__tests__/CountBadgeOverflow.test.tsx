/**
 * @file CountBadgeOverflow.test.tsx
 * @description 머리글 알림 수·장바구니 수 배지 「99+」 넘침 회귀 (7.1.0 실측 D-N2)
 *
 * 결함: 두 배지가 고정 폭 `w-5`(20px) 원이라, 12px 「99+」(약 23px)가 원 양옆으로 삐져나왔다
 *   (라이브 실측: 글자 폭 23.3px > 배지 20px). 최소 폭 `min-w-5` + 좌우 여백 `px-1` 로
 *   숫자 폭에 맞춰 늘어나게 한다(한두 자리 숫자는 종전처럼 원).
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { render, screen, cleanup } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { NotificationCenter } from '../NotificationCenter';
import Header from '../Header';

beforeEach(() => {
    cleanup();
    (window as any).IntersectionObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    (window as any).G7Core = {
        t: (key: string) => key,
        useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
        dispatch: vi.fn(),
        state: { getLocal: () => ({}), getGlobal: () => ({}) },
        getSlotContext: () => ({
            getSlotComponents: () => [],
            subscribeToSlot: () => () => {},
            isEnabled: true,
        }),
    };
    (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
    vi.clearAllMocks();
    delete (window as any).G7Core;
    delete (window as any).__slotContextValue;
});

/**
 * 배지 요소의 클래스 목록을 돌려줍니다.
 *
 * @param text 배지 글자
 * @returns 클래스 이름 배열
 */
function badgeClasses(text: string): string[] {
    return screen.getByText(text).className.split(/\s+/);
}

describe('머리글 수 배지 99+ 넘침 (D-N2)', () => {
    /**
     * @scenario surface=notification_badge
     * @effects count_badge_grows_with_text
     */
    it('알림 수 배지는 최소 폭으로 늘어나 99+ 가 원 밖으로 넘치지 않는다', () => {
        render(<NotificationCenter unreadCount={150} />);
        const classes = badgeClasses('99+');
        expect(classes).toContain('min-w-5');
        expect(classes).toContain('px-1');
        expect(classes).not.toContain('w-5');
    });

    /**
     * @scenario surface=cart_badge
     * @effects count_badge_grows_with_text
     */
    it('장바구니 수 배지도 같은 방식으로 늘어난다', () => {
        render(<Header siteName="S" availableLocales={['ko', 'en']} currentLocale="ko" cartCount={150} />);
        const classes = badgeClasses('99+');
        expect(classes).toContain('min-w-5');
        expect(classes).toContain('px-1');
        expect(classes).not.toContain('w-5');
    });
});
