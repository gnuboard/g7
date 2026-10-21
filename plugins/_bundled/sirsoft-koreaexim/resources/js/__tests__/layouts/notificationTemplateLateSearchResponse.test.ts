// e2e:allow 늦은 응답은 브라우저에서 요청 지연을 걸어야만 재현된다 — 이 시험은 실제 레이아웃의 응답 콜백을 엔진 액션 실행기로 돌려 「닫힌 뒤 도착」을 결정적으로 만든다. 브라우저 축은 7.1.1 출시 전 캠페인 재실측(회원 검색 입력 직후 창을 닫고 `G7Core.state.get().koreaexim_notification_template_form_modal` 확인)이 덮는다.
/**
 * @description 한국수출입은행 플러그인 설정 → 알림 문안 편집 창 — 역할·회원 검색이 창이 닫힌 **뒤에** 도착해도 닫힌 창의 이름 공간을 되살리지 않는다 (7.1.1)
 *
 * 창은 검색 결과를 `_global.koreaexim_notification_template_form_modal.userSearchResults`(이름·이메일)·`roleSearchResults` 에 담고, 닫히면 본문 unmount 가 이름 공간을
 * null 로 비운다. 검색(300ms 대기 뒤 요청)이 그 뒤에 도착하면 점 경로 쓰기가 부모를 새로 만들어 회원 이름·이메일을 담은 이름 공간이
 * 다시 생겼다(이커머스 마일리지 수동 창 늦은 응답 관찰과 같은 꼴). 액션 `if` 는 요청 시점 문맥을 보므로, 값 식(평가 시점 최신
 * `_global`)에서 이름 공간이 null 이면 null 을 그대로 쓴다. 엔진 무변경.
 *
 * @scenario-manifest tests/scenarios/notification-template-edit-close-clears.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import modalLayout from '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json';

const NS = 'koreaexim_notification_template_form_modal';

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
 * 레이아웃의 검색 요청과 같은 응답 콜백을 단 apiCall 을 만든다(인증·조건·질의·대기는 떼고 콜백만 그대로).
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
    globalThis.fetch = vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })) as any;
    await utils.triggerAction(action);
    const out = utils.getState()._global;
    utils.cleanup();

    return out;
}

const realFetch = globalThis.fetch;

afterEach(() => {
    globalThis.fetch = realFetch;
    document.body.innerHTML = '';
});

const CASES = [
    { label: '회원 검색 성공', needle: '/api/admin/users/search', status: 200, body: { success: true, data: [{ uuid: 'u1', name: '홍길동', email: 'h@example.com' }], meta: { keyword: '홍' } }, key: 'userSearchResults' },
    { label: '회원 검색 실패', needle: '/api/admin/users/search', status: 500, body: { success: false, message: 'x' }, key: 'userSearchResults' },
    { label: '역할 검색 성공', needle: '/api/admin/roles', status: 200, body: { success: true, data: { data: [{ identifier: 'manager', name: '매니저' }] } }, key: 'roleSearchResults' },
    { label: '역할 검색 실패', needle: '/api/admin/roles', status: 500, body: { success: false, message: 'x' }, key: 'roleSearchResults' },
];

describe('알림 문안 편집 창 — 역할·회원 검색이 닫힌 뒤 도착 (7.1.1)', () => {
    // @scenario close=late_response
    // @effects notification_template_late_search_response_not_written_after_close
    it.each(CASES)('$label — 이름 공간이 null 이면 null 그대로다', async ({ needle, status, body }) => {
        const calls = apiCalls(modalLayout, needle);
        expect(calls.length).toBeGreaterThan(0);
        for (const call of calls) {
            const g = await runWith({ [NS]: null }, replay(call), status, body);
            expect(g[NS]).toBeNull();
        }
    });

    // @scenario close=late_response
    // @effects notification_template_late_search_response_not_written_after_close
    it.each(CASES)('$label — 열린 창에서는 결과 칸만 바꾸고 다른 칸을 지킨다', async ({ needle, status, body, key }) => {
        const call = apiCalls(modalLayout, needle)[0];
        const g = await runWith({ [NS]: { template: { subject: '제목' }, recipients: [{ type: 'role' }] } }, replay(call), status, body);
        expect(Array.isArray(g[NS][key])).toBe(true);
        expect(g[NS][key].length).toBe(status === 200 ? 1 : 0);
        expect(g[NS].template).toEqual({ subject: '제목' });
        expect(g[NS].recipients).toEqual([{ type: 'role' }]);
    });
});
