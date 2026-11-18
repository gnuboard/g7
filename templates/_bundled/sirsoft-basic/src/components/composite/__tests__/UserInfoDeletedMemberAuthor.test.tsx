// e2e:allow 부품 단위 렌더 단언 — 브라우저 축은(관리자가 삭제한 회원의 글 목록·상세 작성자 칸)이 덮는다.
/**
 * @file UserInfoDeletedMemberAuthor.test.tsx
 * @description 계정이 지워진 회원 작성자(`author.is_guest === false` 이고 uuid 없음)는 비회원 배지 없이 「탈퇴한 사용자」로 그린다
 *
 * 서버는 회원 번호가 남았는데 계정이 없는 글·댓글 작성자를 `{uuid: null, name: null, is_guest: false}` 로 준다(7.1.1 계약 —
 * board-deleted-account-author-display). 부품은 uuid 가 없으면 비회원으로 보아 이름 빈 칸 + 「비회원」 배지를 그렸다.
 *
 * @scenario-manifest tests/scenarios/userinfo-deleted-member-author.yaml
 */

import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { UserInfo } from '../UserInfo';

beforeEach(() => {
    (window as any).G7Core = {
        t: vi.fn((key: string) => ({ 'userinfo.guest_badge': '비회원', 'userinfo.withdrawn_user': '탈퇴한 사용자' } as Record<string, string>)[key] ?? key),
        navigate: vi.fn(),
    };
});

afterEach(() => {
    delete (window as any).G7Core;
});

describe('UserInfo — 계정이 지워진 회원 작성자', () => {
    /**
     * @scenario author=deleted_member
     * @effects deleted_member_author_shown_without_guest_badge
     * @return void 「탈퇴한 사용자」 가 보이고 「비회원」 배지가 없으면 통과
     */
    it('is_guest:false 인데 uuid 가 없으면 비회원 배지 없이 「탈퇴한 사용자」', () => {
        render(<UserInfo author={{ uuid: null as any, name: null as any, is_guest: false } as any} />);

        expect(screen.getByText('탈퇴한 사용자')).toBeTruthy();
        expect(screen.queryByText('비회원')).toBeNull();
    });

    /**
     * 대조군 — 진짜 비회원 글은 종전대로 이름과 「비회원」 배지.
     *
     * @scenario author=guest
     * @effects guest_author_keeps_badge
     * @return void 비회원 이름과 배지가 보이면 통과
     */
    it('비회원(is_guest:true) 은 이름과 「비회원」 배지', () => {
        render(<UserInfo author={{ uuid: null as any, name: '손님', is_guest: true } as any} />);

        expect(screen.getByText('손님')).toBeTruthy();
        expect(screen.getByText('비회원')).toBeTruthy();
    });
});
