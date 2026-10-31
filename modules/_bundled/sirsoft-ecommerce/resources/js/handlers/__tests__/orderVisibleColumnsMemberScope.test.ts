/**
 * 주문 목록 표시 컬럼 저장값의 회원 구분 테스트 (#639 ⑩)
 *
 * 배경(브라우저 실측 2026-10-05): DataGrid 열 토글 경로(`sirsoft-ecommerce.saveVisibleColumns`)가
 * `g7_order_visible_columns` 하나에 적었고, 주문 목록 초기화가 같은 키를 그대로 읽어 같은 브라우저의
 * 다른 관리자에게 앞 사람의 설정이 실렸다. 업무 설정은 회원 uuid 를 키에 넣는다(ColumnSelector 와
 * 같은 형식). 회원 구분 없는 옛 값은 읽지 않고 지운다.
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { loadVisibleColumnsHandler, saveVisibleColumnsHandler } from '../orderHandlers';
import type { ActionContext } from '../../types';

const LEGACY_KEY = 'g7_order_visible_columns';

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

const context = {} as ActionContext;

describe('주문 목록 표시 컬럼 — 회원별 저장 (#639 ⑩)', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    // @scenario surface=order_list_columns, member=scoped
    // @effects order_columns_are_scoped_to_member_uuid
    it('저장 키에 회원 uuid 가 들어간다', () => {
        signInAs('admin-a');
        saveVisibleColumnsHandler({ handler: 'saveVisibleColumns', params: { columns: ['ordered_at', 'order_number'] } } as any, context);

        expect(localStorage.getItem(`${LEGACY_KEY}_admin-a`)).toBe(JSON.stringify(['ordered_at', 'order_number']));
        expect(localStorage.getItem(LEGACY_KEY)).toBeNull();
    });

    // @scenario surface=order_list_columns, member=scoped
    // @effects order_columns_are_scoped_to_member_uuid
    it('다른 관리자는 앞 사람의 컬럼을 읽지 않는다', () => {
        localStorage.setItem(`${LEGACY_KEY}_admin-a`, JSON.stringify(['ordered_at']));

        signInAs('admin-b');
        const columns = loadVisibleColumnsHandler({ handler: 'loadVisibleColumns', params: { defaultColumns: ['x', 'y'] } } as any, context);

        expect(columns).toEqual(['x', 'y']);
    });

    // @scenario surface=order_list_columns, member=scoped
    // @effects order_columns_are_scoped_to_member_uuid
    it('회원 구분 없는 옛 값은 읽지 않고 지운다', () => {
        localStorage.setItem(LEGACY_KEY, JSON.stringify(['ordered_at']));

        signInAs('admin-b');
        const columns = loadVisibleColumnsHandler({ handler: 'loadVisibleColumns', params: { defaultColumns: ['x'] } } as any, context);

        expect(columns).toEqual(['x']);
        expect(localStorage.getItem(LEGACY_KEY)).toBeNull();
    });

    // @scenario surface=order_list_columns, member=scoped
    // @effects order_columns_are_scoped_to_member_uuid
    it('회원을 모르면 저장하지 않는다', () => {
        signInAs(null);
        saveVisibleColumnsHandler({ handler: 'saveVisibleColumns', params: { columns: ['ordered_at'] } } as any, context);

        expect(Object.keys(localStorage).filter((key) => key.startsWith(LEGACY_KEY))).toEqual([]);
    });
});
