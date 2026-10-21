// e2e:allow 늦은 응답은 브라우저에서 요청 지연을 걸어야만 재현된다 — 이 시험은 실제 조각의 응답 콜백을 엔진 액션 실행기로 돌려 「닫힌 뒤·다른 창에 도착」을 결정적으로 만든다. 브라우저 축은 7.1.1 출시 전 캠페인 재실측(편집 창을 열자마자 닫고 `G7Core.state.get().bz_tpl_modal`)이 덮는다.
/**
 * @description 알림 설정 [편집] 창 — 알림톡 상세 응답이 창을 닫거나 다른 알림을 연 뒤 도착하면 이름 공간에 쓰지 않는다 (7.1.1)
 *
 * 창이 열리면 조각이 `_global.bz_tpl_modal` 을 시딩하고 저장된 행의 상세를 받아 온다. 닫히면 unmount 가 이름 공간을 null 로 비운다.
 * 상세가 그 뒤에 도착하면 점 경로 쓰기가 이름 공간을 되살렸고, 닫고 다른 알림을 연 뒤 도착하면 앞 알림의 상태·내용이 새 창에
 * 섞였다. 액션 `if` 는 요청 시점 문맥이라 쓸 수 없어 값 식(평가 시점 최신 `_global`)에서 「열린 창의 알림 종류 = 요청한 알림 종류」 를
 * 견준다. 엔진 무변경.
 *
 * @scenario-manifest tests/scenarios/edit-modal-late-detail-response.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import sections from '../../../extensions/notification_template_form_sections.json';

const REQUESTED = 'order_paid';

/**
 * 트리에서 target 이 주어진 문자열을 담은 GET apiCall 을 모두 찾는다.
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
    if (x.handler === 'apiCall' && String(x.target ?? '').includes(needle) && String(x.params?.method ?? 'GET') === 'GET') out.push(x);
    Object.values(x).forEach((v) => apiCalls(v, needle, out));

    return out;
}

// 창이 열릴 때(mount) 부르는 상세 조회 — 요청한 알림 종류는 조각 문맥(extensionPointProps)에서 오므로 시험에서는 고정값으로 바꿔 끼운다
const detail = apiCalls(sections, '/admin/templates/{{bizppurioTemplates')[0];
const callbacks = JSON.parse(
    JSON.stringify({ onSuccess: detail?.onSuccess ?? detail?.success, onError: detail?.onError ?? detail?.fail })
        .split('extensionPointProps.definition?.type').join(`'${REQUESTED}'`),
);

/**
 * 주어진 전역 상태로 빈 화면을 띄우고, 조각의 상세 콜백을 단 요청을 응답과 함께 돌린 뒤 전역 상태를 돌려준다.
 *
 * @param global 처음 전역 상태
 * @param status 응답 상태
 * @param body 응답 본문
 * @returns 실행 뒤 `_global`
 */
async function runWith(global: Record<string, any>, status: number, body: any): Promise<Record<string, any>> {
    const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/bz-late-detail', components: [{ id: 'root', type: 'basic', name: 'Div' }] } as any,
        { locale: 'ko', initialState: { _global: global } },
    );
    await utils.render();
    globalThis.fetch = vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })) as any;
    await utils.triggerAction({ type: 'click', handler: 'apiCall', target: '/api/test/bz-detail', params: { method: 'GET' }, ...callbacks } as any);
    const out = utils.getState()._global;
    utils.cleanup();

    return out;
}

const OK = { success: true, data: { template: { status: 'approved', template_code: 'g7_aaaa_1', content: { templateName: '결제 완료', templateContent: '#{이름}님' }, inspection_detail: [] } } };
const FAIL = { success: false, message: 'x' };

const realFetch = globalThis.fetch;

afterEach(() => {
    globalThis.fetch = realFetch;
    document.body.innerHTML = '';
});

describe('알림톡 상세 응답 — 창이 그대로일 때만 쓴다 (7.1.1)', () => {
    // @scenario window=closed, outcome=success
    // @scenario window=closed, outcome=fail
    // @effects edit_modal_late_detail_response_not_written_to_closed_or_other_window
    it.each([['성공', 200, OK], ['실패', 500, FAIL]] as const)('창을 닫은 뒤(%s 응답) — 이름 공간이 null 그대로다', async (_l, status, body) => {
        expect(detail).toBeTruthy();
        const g = await runWith({ bz_tpl_modal: null }, status, body);
        expect(g.bz_tpl_modal).toBeNull();
    });

    // @scenario window=reopened_other, outcome=success
    // @scenario window=reopened_other, outcome=fail
    // @effects edit_modal_late_detail_response_not_written_to_closed_or_other_window
    it.each([['성공', 200, OK], ['실패', 500, FAIL]] as const)('다른 알림을 연 뒤(%s 응답) — 새 창의 상태·내용이 그대로다', async (_l, status, body) => {
        const other = { notification_type: 'order_cancelled', status: 'draft', loading: true, template_code: null, content: { templateName: '주문 취소', templateContent: '' } };
        const g = await runWith({ bz_tpl_modal: other }, status, body);
        expect(g.bz_tpl_modal).toEqual(other);
    });

    // @scenario window=same_open, outcome=success
    // @effects edit_modal_late_detail_response_not_written_to_closed_or_other_window
    it('같은 창이 열려 있으면 종전대로 상태·내용을 담고 다른 칸을 지킨다', async () => {
        const g = await runWith({ bz_tpl_modal: { notification_type: REQUESTED, id: 7, status: 'draft', loading: true, request_comment: '메모', content: { templateName: '' } } }, 200, OK);
        expect(g.bz_tpl_modal.status).toBe('approved');
        expect(g.bz_tpl_modal.loading).toBe(false);
        expect(g.bz_tpl_modal.template_code).toBe('g7_aaaa_1');
        expect(g.bz_tpl_modal.content.templateName).toBe('결제 완료');
        expect(g.bz_tpl_modal.content.templateMessageType).toBe('BA');
        expect(g.bz_tpl_modal.id).toBe(7);
        expect(g.bz_tpl_modal.request_comment).toBe('메모');
    });

    // @scenario window=same_open, outcome=fail
    // @effects edit_modal_late_detail_response_not_written_to_closed_or_other_window
    it('같은 창이 열려 있으면 실패 응답은 종전대로 불러오는 중만 끈다', async () => {
        const g = await runWith({ bz_tpl_modal: { notification_type: REQUESTED, status: 'draft', loading: true } }, 500, FAIL);
        expect(g.bz_tpl_modal).toEqual({ notification_type: REQUESTED, status: 'draft', loading: false });
    });
});
