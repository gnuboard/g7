// e2e:allow 이커머스 전수 실측 S5 — Playwright MCP 브라우저로 화면 재현·재실측(이력 「Chrome MCP 정밀 점검 매트릭스」·결함 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file UserProfileMenuButtonName.test.tsx
 * @description 관리자 머리말의 사용자 메뉴 버튼이 읽을 수 있는 이름과 펼침 상태를 갖는지
 *
 * 관리자 머리말은 이름·이메일 글자 위에 `UserProfile`(`dropdownOnly`)의 투명 버튼을 덮어 사용자 메뉴를 연다.
 * 이 버튼에 글자도 이름도 없어 화면 낭독기가 모든 관리자 화면에서 이름 없는 「버튼」 으로만 읽었고,
 * 메뉴가 열렸는지도 알리지 않았다(이커머스 전수 실측 S5-D33, 2026-10-07 — 쿠폰 목록 머리말).
 * 이름은 「사용자 메뉴」(`common.user_menu`)다 — 7.1.0 영역 J 에서 같은 버튼에 붙인 문구를 그대로 쓴다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { UserProfile, User } from '../UserProfile';

const user: User = { id: 1, name: 'E130 관리자', email: 'admin@example.test' };

describe('사용자 메뉴 버튼 이름', () => {
    beforeEach(() => {
        (window as any).G7Core = { t: (key: string) => (key === 'common.user_menu' ? '사용자 메뉴' : key) };
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario case=c01
     * @effects user_menu_button_named
     */
    it('글자 없이 덮는 버튼(dropdownOnly)은 「사용자 메뉴」 를 이름으로 단다', () => {
        render(<UserProfile user={user} dropdownOnly showChevron={false} />);

        expect(screen.getByRole('button', { name: '사용자 메뉴' })).toBeInTheDocument();
    });

    /**
     * @scenario case=c02
     * @effects user_menu_announces_expanded
     */
    it('메뉴가 열렸는지 aria-expanded 로 알린다', () => {
        render(<UserProfile user={user} dropdownOnly showChevron={false} />);

        const button = screen.getByRole('button', { name: '사용자 메뉴' });
        expect(button.getAttribute('aria-haspopup')).toBe('menu');
        expect(button.getAttribute('aria-expanded')).toBe('false');

        fireEvent.click(button);

        expect(button.getAttribute('aria-expanded')).toBe('true');
    });

    /**
     * @scenario case=c03
     * @effects user_menu_button_named
     */
    it('이름이 보이는 기본 형태는 보이는 이름으로 읽힌다(aria-label 로 덮지 않는다)', () => {
        render(<UserProfile user={user} />);

        const button = screen.getByRole('button', { name: /E130 관리자/ });
        expect(button.getAttribute('aria-label')).toBeNull();
        expect(button.getAttribute('aria-expanded')).toBe('false');
    });
});
