// e2e:allow 레이아웃 엔진 렌더(실물 ActionDispatcher 의 조건 분기·apiCall 실패 경로·창 스택) — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(게시글 [신고] 창 422 실패 뒤 창 유지·사유 보존·칸 아래 오류)이 덮는다.
/**
 * @file board-report-modal-fail-keeps-open.test.tsx
 * @description 사용자 게시판 [신고] 창 — 제출이 실패해도 창과 입력한 사유가 남고, 서버 검증 오류가 칸 아래에 보인다 (7.1.1)
 *
 * 종전에는 제출 실패(409·403·422) 때 창을 닫고 토스트만 남겼다(#413-60 의 「모달 닫고 토스트」). 그래서 422(검증 오류)로 실패하면
 * 적어 둔 사유가 창과 함께 사라져 다시 써야 했다 — 같은 기능의 관리자 창은 실패해도 창과 값을 유지한다. 관리자 창과 같게
 * 실패 시 창을 유지하고 오류 토스트를 띄우며, 422 의 칸별 오류(`error.errors`)를 칸 아래에 보인다. 성공 시 닫힘·처음 열기 빈 값·
 * 취소 뒤 초기화는 그대로다.
 *
 * @scenario-manifest tests/scenarios/board-report-modal-fail-keeps-open.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import React from 'react';
import { fireEvent } from '@testing-library/react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
    waitFor,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import reportModal from '../../../layouts/partials/board/show/modals/_modal_report.json';

const ENDPOINT = '/api/modules/sirsoft-board/boards/free/posts/5/reports';
const POST = { data: { id: 5, board: { report_types: [{ value: 'spam', label: '스팸' }, { value: 'abuse', label: '욕설' }] } } };

/**
 * 부품 레지스트리 — 창은 늘 그린다(시험 렌더에는 창 표시 토글이 없다). 열림은 창 스택으로 판정한다.
 *
 * @returns 레지스트리
 */
function makeRegistry(): MockComponentRegistry {
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
    registry.register('composite', 'Modal', ({ title, children }: { title?: string; children?: React.ReactNode }) =>
        React.createElement('section', { role: 'dialog', 'aria-label': title }, children));
    registry.register('composite', 'Avatar', () => null);

    return registry;
}

/**
 * 신고 창을 연 상태로 렌더하고, 제출 요청의 응답을 정한다.
 *
 * @param registry 부품 레지스트리
 * @param reply 제출 요청에 줄 응답(상태·본문)
 * @returns 테스트 유틸
 */
async function renderOpenReportModal(registry: MockComponentRegistry, reply: { status: number; body: Record<string, unknown> }) {
    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'test/board-report-modal-fail',
            components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'page' }, children: [reportModal] }],
        } as any,
        {
            componentRegistry: registry as any,
            locale: 'ko',
            initialData: { post: POST },
            initialState: {
                _global: {
                    currentUser: { uuid: 'u-1' },
                    // 창이 열린 상태 — 엔진의 openModal/closeModal 은 전역 창 스택에 쓴다
                    modalStack: ['board_report_modal'],
                    reportModal: { type: 'post', apiEndpoint: ENDPOINT },
                    reportForm: { reason_type: 'spam', reason_detail: '광고 글입니다' },
                },
            },
        },
    );
    await utils.render();
    const base = globalThis.fetch as any;
    (globalThis as any).fetch = Object.assign(async (url: string, init?: RequestInit) => {
        if (String(url).includes('/reports')) {
            return {
                ok: reply.status < 400,
                status: reply.status,
                json: async () => reply.body,
                text: async () => JSON.stringify(reply.body),
                headers: new Headers({ 'Content-Type': 'application/json' }),
            } as Response;
        }

        return base(url, init);
    }, { mock: base.mock });

    return utils;
}

/** 엔진 toast 처리기가 쌓는 전역 토스트 */
const toastsOf = (utils: { getState: () => any }): Array<{ type: string; message: string }> => utils.getState()._global.toasts ?? [];
/** 엔진 창 스택(전역) */
const stackOf = (utils: { getState: () => any }): string[] => utils.getState()._global.modalStack ?? [];

const submitButton = (): HTMLElement => {
    const found = Array.from(document.querySelectorAll('button')).find((b) => /submit_report|신고하기/.test(b.textContent ?? ''));
    if (!found) throw new Error('제출 단추 없음');

    return found as HTMLElement;
};

describe('사용자 게시판 [신고] 창 — 제출 실패 뒤 (7.1.1)', () => {
    let registry: MockComponentRegistry;

    beforeEach(() => {
        registry = makeRegistry();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // @scenario result=validation_failed
    // @effects report_modal_stays_open_on_failure, report_reason_kept_on_failure, report_field_error_shown_below_field
    it('422 로 실패하면 창이 열린 채 사유가 남고, 칸별 오류가 칸 아래에 보인다', async () => {
        const utils = await renderOpenReportModal(registry, {
            status: 422,
            body: { success: false, message: '입력값을 확인해 주세요.', errors: { reason_detail: ['상세 사유는 10자 이상이어야 합니다.'] } },
        });

        fireEvent.click(submitButton());
        await waitFor(() => expect(toastsOf(utils).some((t) => t.type === 'error')).toBe(true));
        await waitFor(() => expect(utils.getState()._global.reportSubmitting).toBe(false));

        expect(stackOf(utils)).toEqual(['board_report_modal']);
        expect(utils.getState()._global.reportForm).toMatchObject({ reason_type: 'spam', reason_detail: '광고 글입니다' });

        await utils.rerender();
        expect(screen.getByText('상세 사유는 10자 이상이어야 합니다.')).toBeTruthy();
        utils.cleanup();
    });

    // @scenario result=conflict
    // @effects report_modal_stays_open_on_failure
    it('409(이미 신고함) 도 창을 닫지 않고 오류 토스트를 띄운다 — 관리자 창과 같은 동작', async () => {
        const utils = await renderOpenReportModal(registry, { status: 409, body: { success: false, message: '이미 신고한 게시글입니다.' } });

        fireEvent.click(submitButton());
        await waitFor(() => expect(toastsOf(utils).some((t) => t.type === 'error')).toBe(true));
        await waitFor(() => expect(utils.getState()._global.reportSubmitting).toBe(false));
        expect(stackOf(utils)).toEqual(['board_report_modal']);
        utils.cleanup();
    });

    // @scenario result=succeeded
    // @effects report_modal_closes_and_resets_on_success
    it('성공하면 창을 닫고 사유를 비운다(종전 동작 유지)', async () => {
        const utils = await renderOpenReportModal(registry, { status: 200, body: { success: true, message: '신고되었습니다.', data: {} } });

        fireEvent.click(submitButton());
        await waitFor(() => expect(toastsOf(utils).some((t) => t.type === 'success')).toBe(true));
        await waitFor(() => expect(stackOf(utils)).toEqual([]));
        expect(utils.getState()._global.reportForm).toMatchObject({ reason_type: '', reason_detail: '' });
        expect(utils.getState()._global.reportErrors ?? null).toBeNull();
        utils.cleanup();
    });
});
