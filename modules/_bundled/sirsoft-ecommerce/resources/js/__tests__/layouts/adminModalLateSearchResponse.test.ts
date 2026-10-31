// e2e:allow 늦은 응답은 브라우저에서 요청 지연을 걸어야만 재현된다 — 이 시험은 실제 레이아웃의 응답 콜백을 엔진 액션 실행기로 돌려 「닫힌 뒤 도착」을 결정적으로 만든다. 브라우저 축은 7.1.1 출시 전 캠페인 재실측(검색 입력 0.1초 안에 X·취소·Esc 로 닫고 1.5초 뒤 `G7Core.state.get().<키>`)이 덮는다.
/**
 * @file adminModalLateSearchResponse.test.ts
 * @description 이커머스 관리자 창의 검색 요청이 창이 닫힌 **뒤에** 도착해도 닫힌 창의 이름 공간을 되살리지 않는다 (7.1.1)
 *
 * 마일리지 수동 지급/차감 창·쿠폰 직접 발급 창·알림 문안 편집 창은 입력할 때마다 회원(·역할) 검색을 부르고, 결과를 창 고유
 * 전역 이름 공간에 담는다. 창을 닫으면 본문 unmount 가 그 이름 공간을 null 로 비우는데, 검색이 그 뒤에 도착하면
 * 점 경로 쓰기(`mileageManual.memberSearchResults=…`)가 부모를 새로 만들어 닫힌 창의 이름 공간이 다시 생겼다(7.1.1 출시 전
 * 브라우저 실측 — `{memberSearchResults: [], memberSearchResultsKeyword: null}`). 알림 문안 창은 이 경로로 회원 이름·이메일이 남는다.
 * 쿠폰 직접 발급 창은 옛 응답 갈래의 `?? []` 가 닫힌 창의 결과 키를 빈 배열로 되살렸다.
 *
 * 액션 `if` 는 요청 시점 문맥(`_global` 사본)을 보므로 닫힘을 알 수 없다 — 값 식의 `_global` 만 평가 시점 최신 상태라,
 * 이름 공간이 null 이면 null 을 그대로 쓰게 값 식에서 가른다(엔진 무변경).
 *
 * @scenario-manifest tests/scenarios/admin-modal-close-clears-namespace.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';

import directIssue from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_direct_issue.json';
import manualTransaction from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json';
import notificationTemplate from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_notification_template_edit.json';

/**
 * 트리에서 target 이 주어진 문자열을 담은 apiCall 을 모두 찾는다.
 *
 * @param x 탐색 대상
 * @param needle target 에 들어 있어야 할 문자열
 * @param out 결과
 * @returns apiCall 액션 목록
 */
function apiCalls(x: any, needle: string, out: any[] = []): any[] {
    if (Array.isArray(x)) {
        x.forEach((v) => apiCalls(v, needle, out));

        return out;
    }
    if (!x || typeof x !== 'object') return out;
    if (x.handler === 'apiCall' && String(x.target ?? '').includes(needle)) out.push(x);
    Object.values(x).forEach((v) => apiCalls(v, needle, out));

    return out;
}

/**
 * 레이아웃의 검색 요청과 같은 응답 콜백을 단 apiCall 을 만든다(인증·조건·질의는 떼고 콜백만 그대로).
 *
 * @param source 레이아웃의 apiCall
 * @returns 실행할 apiCall
 */
function replay(source: any): any {
    return {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test/late-search',
        params: { method: 'GET' },
        onSuccess: source.onSuccess ?? source.success,
        onError: source.onError ?? source.fail,
    };
}

/**
 * 응답을 흉내 내는 fetch.
 *
 * @param status HTTP 상태
 * @param body 응답 본문
 */
function mockFetch(status: number, body: any): void {
    globalThis.fetch = vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })) as any;
}

/**
 * 주어진 전역 상태로 빈 화면을 띄우고, 레이아웃 콜백을 단 요청을 응답과 함께 돌린 뒤 전역 상태를 돌려준다.
 *
 * @param global 처음 전역 상태
 * @param action 실행할 apiCall
 * @param status 응답 상태
 * @param body 응답 본문
 * @returns 실행 뒤 `_global`
 */
async function runWith(global: Record<string, any>, action: any, status: number, body: any): Promise<Record<string, any>> {
    const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/late-search-response', components: [{ id: 'root', type: 'basic', name: 'Div' }] } as any,
        { locale: 'ko', initialState: { _global: global } },
    );
    await utils.render();
    mockFetch(status, body);
    await utils.triggerAction(action);
    const out = utils.getState()._global;
    utils.cleanup();

    return out;
}

const MEMBER = { uuid: 'u1', name: '홍길동', email: 'h@example.com', ecommerce_mileage: { available: 1000 } };
const USER_RESPONSE = { success: true, data: [MEMBER], meta: { keyword: '홍' } };
const ROLE_RESPONSE = { success: true, data: { data: [{ identifier: 'manager', name: '매니저' }] } };

const realFetch = globalThis.fetch;

afterEach(() => {
    globalThis.fetch = realFetch;
    document.body.innerHTML = '';
});

describe('마일리지 수동 지급/차감 창 — 회원 검색이 닫힌 뒤 도착', () => {
    const search = apiCalls(manualTransaction, '/api/admin/users/search');

    // @scenario surface=admin_modal, close_path=late_response
    // @effects admin_modal_late_response_does_not_revive_namespace
    it('창이 닫혀 이름 공간이 null 이면 응답이 와도 null 그대로다', async () => {
        expect(search).toHaveLength(1);
        const g = await runWith({ mileageManual: null }, replay(search[0]), 200, USER_RESPONSE);
        expect(g.mileageManual).toBeNull();
    });

    // @scenario surface=admin_modal, close_path=late_response
    // @effects admin_modal_late_response_does_not_revive_namespace
    it('열린 창에서는 종전대로 결과·도장을 담고 다른 칸을 지킨다', async () => {
        const g = await runWith(
            { mileageManual: { memberSearchKeyword: '홍', action: 'deduct', amount: '500', memberSearchResults: [], memberSearchResultsKeyword: null } },
            replay(search[0]),
            200,
            USER_RESPONSE,
        );
        expect(g.mileageManual.memberSearchResults).toHaveLength(1);
        expect(g.mileageManual.memberSearchResultsKeyword).toBe('홍');
        expect(g.mileageManual.action).toBe('deduct');
        expect(g.mileageManual.amount).toBe('500');
    });

    // @scenario surface=admin_modal, close_path=late_response
    // @effects admin_modal_late_response_does_not_revive_namespace
    it('열린 창의 옛 응답(다른 검색어)은 종전대로 지금 결과를 지킨다', async () => {
        const g = await runWith(
            { mileageManual: { memberSearchKeyword: '홍길', memberSearchResults: [{ uuid: 'u2' }], memberSearchResultsKeyword: '홍길' } },
            replay(search[0]),
            200,
            USER_RESPONSE,
        );
        expect(g.mileageManual.memberSearchResults).toEqual([{ uuid: 'u2' }]);
        expect(g.mileageManual.memberSearchResultsKeyword).toBe('홍길');
    });
});

describe('쿠폰 직접 발급 창 — 회원 검색이 닫힌 뒤 도착', () => {
    const search = apiCalls(directIssue, '/api/admin/users/search');
    const CLOSED = { directIssueSearchResults: null, directIssueSelectedUsers: null, directIssueSearchKeyword: null, directIssueSearchResultsKeyword: null };

    // @scenario surface=admin_modal, close_path=late_response
    // @effects admin_modal_late_response_does_not_revive_namespace
    it('창이 닫혀 키가 null 이면 응답이 와도 네 키 모두 null 그대로다', async () => {
        expect(search).toHaveLength(1);
        const g = await runWith({ ...CLOSED }, replay(search[0]), 200, USER_RESPONSE);
        Object.keys(CLOSED).forEach((k) => expect(g[k], k).toBeNull());
    });

    // @scenario surface=admin_modal, close_path=late_response
    // @effects admin_modal_late_response_does_not_revive_namespace
    it('열린 창(검색어 빈 문자열부터)에서는 종전대로 결과를 담는다', async () => {
        const g = await runWith(
            { directIssueSearchKeyword: '홍', directIssueSearchResults: [], directIssueSearchResultsKeyword: null, directIssueSelectedUsers: [] },
            replay(search[0]),
            200,
            USER_RESPONSE,
        );
        expect(g.directIssueSearchResults).toEqual([{ uuid: 'u1', name: '홍길동', email: 'h@example.com' }]);
        expect(g.directIssueSearchResultsKeyword).toBe('홍');
    });
});

describe('알림 문안 편집 창 — 역할·회원 검색이 닫힌 뒤 도착', () => {
    const NS = 'ecommerce_notification_template_form_modal';
    const cases = [
        { label: '회원 검색 성공', needle: '/api/admin/users/search', status: 200, body: USER_RESPONSE, key: 'userSearchResults' },
        { label: '회원 검색 실패', needle: '/api/admin/users/search', status: 500, body: { success: false, message: 'x' }, key: 'userSearchResults' },
        { label: '역할 검색 성공', needle: '/api/admin/roles', status: 200, body: ROLE_RESPONSE, key: 'roleSearchResults' },
        { label: '역할 검색 실패', needle: '/api/admin/roles', status: 500, body: { success: false, message: 'x' }, key: 'roleSearchResults' },
    ];

    // @scenario surface=admin_modal, close_path=late_response
    // @effects admin_modal_late_response_does_not_revive_namespace
    it.each(cases)('$label — 이름 공간이 null 이면 null 그대로다', async ({ needle, status, body }) => {
        const calls = apiCalls(notificationTemplate, needle);
        expect(calls.length).toBeGreaterThan(0);
        for (const call of calls) {
            const g = await runWith({ [NS]: null }, replay(call), status, body);
            expect(g[NS]).toBeNull();
        }
    });

    // @scenario surface=admin_modal, close_path=late_response
    // @effects admin_modal_late_response_does_not_revive_namespace
    it.each(cases)('$label — 열린 창에서는 결과 칸만 바꾸고 다른 칸을 지킨다', async ({ needle, status, body, key }) => {
        const call = apiCalls(notificationTemplate, needle)[0];
        const g = await runWith({ [NS]: { template: { subject: '제목' }, recipients: [{ type: 'role' }] } }, replay(call), status, body);
        expect(Array.isArray(g[NS][key])).toBe(true);
        expect(g[NS][key].length).toBe(status === 200 ? 1 : 0);
        expect(g[NS].template).toEqual({ subject: '제목' });
        expect(g[NS].recipients).toEqual([{ type: 'role' }]);
    });
});
