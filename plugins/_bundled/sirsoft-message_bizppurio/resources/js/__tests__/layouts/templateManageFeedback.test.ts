/**
 * @file templateManageFeedback.test.ts
 * @description 알림 템플릿 관리 탭 — 검수 신청 실패는 빠진 항목을 하나하나 보여 주고, 삭제 완료 안내는 카카오측을 못 지운 실제 사유를 따른다
 *
 * 2026-10-07 실측: 초안을 「검수 신청」하면 서버는 `errors` 에 빠진 항목(템플릿명·카테고리)을 모두 싣는데,
 * 이 탭의 작성 창과 목록은 첫 문장 + 「(외 1개 오류)」만 보여 줘 무엇을 더 채워야 하는지 알 수 없었다.
 * 같은 날 삭제는 서버가 `kakao_skip_reason` 에 「비즈뿌리오 서버에 연결할 수 없습니다」를 실었는데
 * 화면은 늘 「카카오측 템플릿은 상태 제약으로 남아 있습니다」라고 알렸다.
 */

import { describe, expect, it } from 'vitest';
import layout from '../../../layouts/admin/plugin_settings.json';
import { callbacksOf, findActions, flattenNodes } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/** 작성 창의 저장·검수 신청 호출(생성 POST · 수정 PUT · 신청 POST) */
const modalCalls = findActions(layout, (action) =>
    action.handler === 'apiCall'
    && /\/admin\/templates(\/\{\{_global\.bz_tpl_modal\?\.id\}\}|\/\{\{response\.data\?\.template\?\.id\}\}\/request|\/\{\{_global\.bz_tpl_modal\?\.id\}\}\/request)?$/.test(String(action.target ?? '')),
);

/** 목록 행의 [검수 신청] 호출 */
const rowRequestCalls = findActions(layout, (action) =>
    action.handler === 'apiCall' && String(action.target ?? '').endsWith('/admin/templates/{{bzRow.id}}/request'),
);

/** 삭제 확인 창의 DELETE 호출 */
const deleteCalls = findActions(layout, (action) =>
    action.handler === 'apiCall' && action.params?.method === 'DELETE' && String(action.target ?? '').includes('bz_delete_modal'),
);

describe('알림 템플릿 관리 탭 — 검수 신청 실패 안내', () => {
    /**
     * @scenario surface=plugin_settings_modal
     * @effects inspection_errors_listed_per_field
     */
    it('작성 창의 모든 저장·신청 실패가 항목별 오류(bz_tpl_modal.errors)를 남긴다', () => {
        expect(modalCalls.length).toBeGreaterThanOrEqual(6);
        for (const call of modalCalls) {
            const onError = JSON.stringify(callbacksOf(call, 'onError'));
            expect(onError, `${call.target} 실패 처리에 항목별 오류가 없다`).toContain('bz_tpl_modal.errors');
        }
    });

    /**
     * @scenario surface=plugin_settings_modal
     * @effects inspection_errors_listed_per_field
     */
    it('작성 창이 항목별 오류를 목록으로 그린다', () => {
        const listItems = flattenNodes(layout).filter((node: any) =>
            String(node?.iteration?.source ?? '').includes('bz_tpl_modal?.errors'),
        );
        expect(listItems.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario surface=plugin_settings_row
     * @effects inspection_errors_listed_per_field
     */
    it('목록 행 [검수 신청] 실패 안내가 빠진 항목 전부를 싣는다', () => {
        expect(rowRequestCalls).toHaveLength(1);
        const onError = JSON.stringify(callbacksOf(rowRequestCalls[0], 'onError'));
        expect(onError).toContain('Object.values(error.errors');
    });
});

describe('알림 템플릿 관리 탭 — 삭제 완료 안내', () => {
    /**
     * @scenario surface=plugin_settings_delete
     * @effects delete_toast_follows_kakao_skip_reason
     */
    it('카카오측을 못 지운 사유(kakao_skip_reason)에 따라 안내를 가른다', () => {
        expect(deleteCalls).toHaveLength(1);
        const onSuccess = JSON.stringify(callbacksOf(deleteCalls[0], 'onSuccess'));
        expect(onSuccess).toContain('kakao_skip_reason');
        expect(onSuccess).toContain('done_not_registered');
        expect(onSuccess).toContain('done_kakao_failed');
    });
});
