/**
 * @file admin-order-excel-download-handler.test.ts
 * @description 관리자 주문 목록 엑셀 내려받기 창 — 모듈이 소유한 downloadFile 로 요청·저장하는지 (레이아웃 JSON 트리 분석)
 *
 * 결함: 내려받기 버튼이 `apiCall`(responseType: blob) 성공 사슬에서 접두어 없는 `downloadFile` 을 불렀다.
 *   그런 핸들러는 코어에도 관리자 템플릿에도 없어(레이아웃 검사 「미등록 핸들러」 경고) 파일이 저장되지 않았고,
 *   코어 apiCall 은 응답을 JSON 으로만 읽어 blob 을 넘겨주지도 못한다.
 * 수정: 요청과 저장을 이 모듈의 `sirsoft-ecommerce.downloadFile` 한 액션으로 합치고, 성공하면 진행 표시 해제·
 *   성공 안내·창 닫기, 실패하면 진행 표시 해제·실패 안내를 띄운다.
 */

import { describe, it, expect } from 'vitest';

import excelModal from '../../../layouts/admin/partials/admin_ecommerce_order_list/_modal_excel_download.json';
import orderDatagrid from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json';
import { handlerMap } from '../../handlers';
import { downloadFileHandler } from '../../handlers/downloadFile';

type Action = Record<string, any>;

/**
 * 트리에서 조건에 맞는 액션 객체를 모두 모은다 (sequence·onSuccess·onError 안까지).
 */
function collectActions(node: unknown, predicate: (a: Action) => boolean): Action[] {
    const out: Action[] = [];
    const walk = (cur: unknown): void => {
        if (Array.isArray(cur)) {
            cur.forEach(walk);
            return;
        }
        if (cur && typeof cur === 'object') {
            const obj = cur as Action;
            if (typeof obj.handler === 'string' && predicate(obj)) {
                out.push(obj);
            }
            Object.values(obj).forEach(walk);
        }
    };
    walk(node);
    return out;
}

const toArray = (v: unknown): Action[] => (Array.isArray(v) ? v : v ? [v as Action] : []);

describe('관리자 주문 목록 — 엑셀 내려받기 창', () => {
    /**
     * @scenario outcome=success
     * @effects excel_modal_calls_module_owned_download_handler
     */
    it('내려받기는 이 모듈의 downloadFile 이 POST 본문과 파일명을 받아 처리한다', () => {
        const actions = collectActions(excelModal, (a) => a.handler === 'sirsoft-ecommerce.downloadFile');
        expect(actions).toHaveLength(1);
        expect(handlerMap.downloadFile).toBe(downloadFileHandler);

        const params = actions[0].params as Record<string, unknown>;
        expect(params.url).toBe('/api/modules/sirsoft-ecommerce/admin/orders/export-excel');
        expect(params.method).toBe('POST');
        expect(params.filename).toMatch(/^orders_\{\{\$date\.format\('YYYYMMDD_HHmmss'\)\}\}\.xlsx$/);
        // 본문 한 식에 두 갈래(검색결과 / 선택 주문) 구성이 모두 있다. 범위는 창을 열 때 목록이 넘긴
        // _global.excelDownloadContext 에서 읽는다(모달은 부모의 _local 을 보지 못한다)
        expect(params.body).toContain('type: _global.excelDownloadContext?.type');
        expect(params.body).toContain('order_ids:');
        expect(params.body).toContain('filters:');
    });

    /**
     * @scenario outcome=success
     * @effects excel_modal_calls_module_owned_download_handler
     */
    it('접두어 없는 downloadFile 과 blob 을 기대하는 apiCall 은 남지 않는다', () => {
        expect(collectActions(excelModal, (a) => a.handler === 'downloadFile')).toHaveLength(0);
        expect(
            collectActions(excelModal, (a) => a.handler === 'apiCall' && a.params?.responseType === 'blob')
        ).toHaveLength(0);
    });

    /**
     * @scenario outcome=success
     * @effects success_clears_progress_and_closes_modal
     */
    it('성공하면 진행 표시를 풀고 성공 안내를 띄운 뒤 창을 닫는다', () => {
        const [action] = collectActions(excelModal, (a) => a.handler === 'sirsoft-ecommerce.downloadFile');
        const handlers = toArray(action.onSuccess).map((a) => a.handler);

        expect(handlers).toEqual(['setState', 'toast', 'closeModal']);
        expect(toArray(action.onSuccess)[0].params.isDownloading).toBe(false);
    });

    /**
     * @scenario outcome=failure
     * @effects failure_clears_progress_and_shows_error_toast
     */
    it('실패하면 진행 표시를 풀고 실패 안내를 띄운다', () => {
        const [action] = collectActions(excelModal, (a) => a.handler === 'sirsoft-ecommerce.downloadFile');
        const onError = toArray(action.onError);

        expect(onError[0].handler).toBe('setState');
        expect(onError[0].params.isDownloading).toBe(false);
        expect(onError[1].handler).toBe('toast');
        expect(onError[1].params.message).toBe('$t:sirsoft-ecommerce.admin.order.excel.download_failed');
    });

    /**
     * 브라우저 실측(#703) — 창의 건수 안내가 「총 {{count}}개의 주문서가 다운로드됩니다:count=0」 원문으로 보였다.
     * 번역 파라미터 구분자는 `|` 인데 `:` 로 적혀 있어 키 전체가 조회에 실패했다. 고친 뒤에도 「총 0개」였다 —
 * 건수를 응답에 없는 data.total 에서 읽고 있었다(실제 122건).
     *
     * @scenario outcome=success
     * @effects excel_modal_count_hint_passes_translation_params
     */
    it('범위 안내 문구는 건수를 번역 파라미터(|count=)로 넘긴다', () => {
        const texts: string[] = [];
        const walk = (cur: unknown): void => {
            if (Array.isArray(cur)) { cur.forEach(walk); return; }
            if (cur && typeof cur === 'object') {
                const obj = cur as Action;
                if (typeof obj.text === 'string' && obj.text.includes('.excel.option_')) texts.push(obj.text);
                Object.values(obj).forEach(walk);
            }
        };
        walk(excelModal);

        const hints = texts.filter((t) => /option_(search|selected)_desc/.test(t));
        expect(hints).toHaveLength(2);
        // 안내는 창을 열 때 목록이 넘긴 건수를 읽는다.
        expect(hints.find((t) => t.includes('option_search_desc'))).toContain('_global.excelDownloadContext?.total');
        // 그 건수는 목록 응답의 data.pagination.total 이다 (주문 표 머리의 total_count 와 같은 경로) — data.total 은 없어 늘 0 이었다.
        const seeds = JSON.stringify(orderDatagrid).match(/"excelDownloadContext":"[^"]*"/g) ?? [];
        expect(seeds.length).toBeGreaterThan(0);
        seeds.forEach((seed) => expect(seed).toContain('total: (orders?.data?.pagination?.total ?? 0)'));
        hints.forEach((t) => {
            expect(t).toMatch(/^\$t:sirsoft-ecommerce\.admin\.order\.excel\.option_(search|selected)_desc\|count=\{\{.+\}\}$/);
        });
    });
});
