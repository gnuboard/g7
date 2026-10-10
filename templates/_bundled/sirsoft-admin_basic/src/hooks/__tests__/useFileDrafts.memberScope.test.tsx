/**
 * @file useFileDrafts.memberScope.test.tsx
 * @description 레이아웃 편집기 초안이 관리자 회원별로 갈리는지 잠근다 (#639 ⑩).
 *
 * 배경(브라우저 실측 2026-10-05): 관리자 A 가 편집기에서 남긴 초안이 `g7le.codeEditor.{템플릿}.drafts`
 * 하나에 적혀, 같은 브라우저로 로그인한 관리자 B 의 편집기에 「저장하지 않은 편집 1건이 있습니다」
 * 배너로 떴다. 초안은 작성물이므로 회원 uuid 를 키에 넣는다(주문 목록 「컬럼 선택」과 같은 방식).
 *
 * 회원 구분 없이 남은 옛 키는 읽지 않고 지운다 — 누구 것인지 가를 수 없기 때문이다.
 * uuid 를 모르면 초안을 남기지 않는다 — 공용 키에 다시 쓰면 같은 결함이 돌아온다.
 *
 * @scenario surface=layout_editor_drafts
 *
 * @effects drafts_are_scoped_to_member_uuid, unscoped_legacy_drafts_are_discarded
 */

import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useFileDrafts } from '../useFileDrafts';

const NAMESPACE = 'g7le.codeEditor.sirsoft-basic';

/**
 * 현재 로그인 회원을 흉내 낸다.
 *
 * @param uuid 회원 uuid (null 이면 비로그인)
 */
function signInAs(uuid: string | null): void {
    (window as any).G7Core = {
        AuthManager: {
            getInstance: () => ({ getUser: () => (uuid === null ? null : { uuid }) }),
        },
    };
}

describe('useFileDrafts — 회원별 초안 (#639 ⑩)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        window.localStorage.clear();
    });

    afterEach(() => {
        vi.useRealTimers();
        delete (window as any).G7Core;
    });

    // @scenario surface=layout_editor_drafts, member=scoped
    // @effects drafts_are_scoped_to_member_uuid
    it('초안은 회원 uuid 가 들어간 키에 적는다', () => {
        signInAs('admin-a');
        const { result, unmount } = renderHook(() => useFileDrafts({ namespace: NAMESPACE, debounceMs: 10 }));

        act(() => {
            result.current.rememberDraft('layout:home', '<div>A</div>', 1);
            vi.advanceTimersByTime(20);
        });
        unmount();

        expect(window.localStorage.getItem(`${NAMESPACE}.admin-a.drafts`)).toContain('<div>A</div>');
        expect(window.localStorage.getItem(`${NAMESPACE}.drafts`)).toBeNull();
    });

    // @scenario surface=layout_editor_drafts, member=scoped
    // @effects drafts_are_scoped_to_member_uuid
    it('다른 관리자의 초안은 보이지 않는다', () => {
        window.localStorage.setItem(
            `${NAMESPACE}.admin-a.drafts`,
            JSON.stringify({ 'layout:home': { value: '<div>A</div>', savedAt: Date.now(), baseRevision: 1 } }),
        );

        signInAs('admin-b');
        const { result } = renderHook(() => useFileDrafts({ namespace: NAMESPACE }));

        expect(result.current.pendingDrafts).toEqual({});
    });

    // @scenario surface=layout_editor_drafts, member=scoped
    // @effects drafts_are_scoped_to_member_uuid
    it('회원 구분 없는 옛 키는 읽지 않고 지운다', () => {
        window.localStorage.setItem(
            `${NAMESPACE}.drafts`,
            JSON.stringify({ 'layout:home': { value: '<div>legacy</div>', savedAt: Date.now(), baseRevision: 1 } }),
        );

        signInAs('admin-b');
        const { result } = renderHook(() => useFileDrafts({ namespace: NAMESPACE }));

        expect(result.current.pendingDrafts).toEqual({});
        expect(window.localStorage.getItem(`${NAMESPACE}.drafts`)).toBeNull();
    });

    // @scenario surface=layout_editor_drafts, member=scoped
    // @effects drafts_are_scoped_to_member_uuid
    it('회원을 모르면 초안을 남기지 않는다', () => {
        signInAs(null);
        const { result, unmount } = renderHook(() => useFileDrafts({ namespace: NAMESPACE, debounceMs: 10 }));

        act(() => {
            result.current.rememberDraft('layout:home', '<div>X</div>', 1);
            vi.advanceTimersByTime(20);
        });
        unmount();

        const written = Object.keys(window.localStorage).filter((key) => key.endsWith('.drafts'));
        expect(written).toEqual([]);
    });
});
