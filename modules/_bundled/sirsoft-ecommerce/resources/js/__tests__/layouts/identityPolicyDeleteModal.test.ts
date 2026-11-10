/**
 * 이커머스 환경설정 > 본인인증 정책 탭 — 삭제 핸들러 회귀 테스트.
 *
 * 회귀 사례 (#297):
 *   삭제 버튼이 등록되지 않은 `confirm` 핸들러를 호출하여 "Unknown action handler: confirm" 토스트
 *   에러로 삭제 자체가 실행되지 않던 문제. setState + openModal 시퀀스 + 별도 확인 모달 패턴으로 교체.
 */

import { describe, it, expect } from 'vitest';

const tabPartial = require('../../../layouts/admin/partials/admin_ecommerce_settings/_tab_identity_policies.json');
const deleteModal = require('../../../layouts/admin/partials/admin_ecommerce_settings/_modal_identity_policy_delete.json');
const settingsLayout = require('../../../layouts/admin/admin_ecommerce_settings.json');

function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const result: any[] = [];
    const visit = (n: any) => {
        if (!n || typeof n !== 'object') return;
        if (Array.isArray(n)) {
            n.forEach(visit);
            return;
        }
        if (predicate(n)) result.push(n);
        if (n.children) visit(n.children);
        if (n.props?.footer) visit(n.props.footer); // 고정 하단 (#869 P1 — 바닥 단추가 Modal 고정 하단으로 옮김)
        if (n.actions) visit(n.actions);
        if (n.params) visit(n.params);
        if (n.onSuccess) visit(n.onSuccess);
        if (n.onError) visit(n.onError);
    };
    visit(node);
    return result;
}

describe('이커머스 본인인증 정책 삭제 — confirm 핸들러 미사용, 모달 + 스피너 패턴 (#297)', () => {
    /**
     * 등록되지 않은 confirm 핸들러를 부르면 삭제가 실행되지 않으므로 탭 어디에도 남아 있으면 안 된다.
     *
     * @scenario surface=tab_partial
     * @effects identity_policy_tab_has_no_confirm_handler
     */
    it('탭 partial 에 confirm 핸들러가 존재하지 않는다', () => {
        expect(JSON.stringify(tabPartial)).not.toMatch(/"handler"\s*:\s*"confirm"/);
    });

    /**
     * PC 표와 모바일 카드 두 곳의 삭제 버튼이 모두 대상 id 를 세우고 확인 모달을 연다.
     *
     * @scenario surface=tab_partial
     * @effects delete_button_sets_target_id_and_opens_delete_modal
     */
    it('삭제 버튼은 setState ecommerceIdentityPolicyDeleteId + openModal ecommerce_identity_policy_delete_modal 시퀀스 사용 (PC + 모바일)', () => {
        const sequences = collectNodes(tabPartial, (n) => n.handler === 'sequence');
        const deleteSequences = sequences.filter((seq) => {
            const actions = seq.actions ?? [];
            const hasSetDeleteId = actions.some(
                (a: any) =>
                    a.handler === 'setState' && a.params?.ecommerceIdentityPolicyDeleteId !== undefined,
            );
            const hasOpenDeleteModal = actions.some(
                (a: any) =>
                    a.handler === 'openModal' && a.target === 'ecommerce_identity_policy_delete_modal',
            );
            return hasSetDeleteId && hasOpenDeleteModal;
        });
        expect(deleteSequences.length).toBeGreaterThanOrEqual(2);
    });

    /**
     * @scenario surface=settings_layout
     * @effects delete_modal_registered_in_settings_modals
     */
    it('삭제 모달이 부모 레이아웃의 modals 배열에 등록되어 있다', () => {
        const partials = (settingsLayout.modals ?? []).map((m: any) => m.partial);
        expect(partials).toContain(
            'partials/admin_ecommerce_settings/_modal_identity_policy_delete.json',
        );
    });

    /**
     * @scenario surface=delete_modal
     * @effects delete_modal_calls_delete_api_and_refetches_policies
     */
    it('삭제 모달은 ecommerceIdentityPolicyDeleteId 를 사용한 DELETE API 호출 + ecommerceIdentityPolicies 데이터소스 refetch 를 수행', () => {
        const apiCalls = collectNodes(deleteModal, (n) => n.handler === 'apiCall');
        const matching = apiCalls.find(
            (c) =>
                typeof c.target === 'string' &&
                c.target.includes('{{_global.ecommerceIdentityPolicyDeleteId}}') &&
                c.params?.method === 'DELETE',
        );
        expect(matching).toBeTruthy();

        const refetches = collectNodes(matching, (n) => n.handler === 'refetchDataSource');
        expect(
            refetches.some((r) => r.params?.dataSourceId === 'ecommerceIdentityPolicies'),
        ).toBe(true);
    });

    /**
     * 삭제 진행 중에는 취소와 삭제 버튼이 모두 잠기고 스피너가 그 값으로 나타난다.
     *
     * @scenario surface=delete_modal
     * @effects delete_modal_locks_buttons_and_shows_spinner_while_deleting
     */
    it('삭제 모달은 _global.ecommerceIdentityPolicyDeleting 기준으로 disabled + 스피너 노출', () => {
        const stringified = JSON.stringify(deleteModal);
        expect(stringified).toMatch(/_global\.ecommerceIdentityPolicyDeleting/);
        const icons = collectNodes(deleteModal, (n) => n.name === 'Icon' && n.props?.name === 'spinner');
        expect(icons.length).toBeGreaterThan(0);
        // 스피너는 진행 중 값으로만 나타난다
        icons.forEach((icon) => expect(icon.if).toContain('_global.ecommerceIdentityPolicyDeleting'));
        // 취소와 삭제 버튼 둘 다 진행 중 값으로 잠긴다 — 한쪽만 잠기면 진행 중에 다시 누르거나 닫을 수 있다
        const buttons = collectNodes(deleteModal, (n) => n.name === 'Button');
        expect(buttons.length).toBe(2);
        buttons.forEach((button) =>
            expect(String(button.props?.disabled ?? '')).toContain('_global.ecommerceIdentityPolicyDeleting'),
        );
    });
});
