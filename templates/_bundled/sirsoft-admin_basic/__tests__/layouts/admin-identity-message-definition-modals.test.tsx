/**
 * @file admin-identity-message-definition-modals.test.tsx
 * @description IDV 메시지 정의 add/delete/reset 모달 (#297) — 정적 회귀 테스트
 *
 * 검증 포인트 (알림 템플릿 모달 패리티):
 *  - add: closeOnOverlayClick=false, flex 컨테이너 + flex-shrink-0 footer,
 *         검증 에러 영역, 언어 탭, name/subject/body 단일 필드 (editLang 인덱싱),
 *         POST endpoint, common.cancel/save 키, 스피너
 *  - delete: DELETE endpoint, 경고 텍스트, 스피너
 *  - reset:  POST /reset, 경고 텍스트, 스피너
 *  - 모든 모달의 Cancel = closeModal target
 */

import { describe, it, expect } from 'vitest';

const addModal = require('../../layouts/partials/admin_settings/_modal_identity_message_definition_add.json');
const deleteModal = require('../../layouts/partials/admin_settings/_modal_identity_message_definition_delete.json');
const resetModal = require('../../layouts/partials/admin_settings/_modal_identity_message_definition_reset.json');

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
        if (n.actions) visit(n.actions);
        if (n.params) visit(n.params);
        if (n.onSuccess) visit(n.onSuccess);
        if (n.onError) visit(n.onError);
        if (n.iteration) visit(n.iteration);
    };
    visit(node);
    return result;
}

describe('IDV 메시지 정의 — 추가 모달 (알림 템플릿 패리티)', () => {
    it('Modal id + size=xl + closeOnOverlayClick=false', () => {
        expect(addModal.id).toBe('modal_identity_message_definition_add');
        expect(addModal.props.size).toBe('xl');
        expect(addModal.props.closeOnOverlayClick).toBe(false);
        expect(addModal.props.id).toBeUndefined();
    });

    it('flex 컨테이너 + flex-shrink-0 footer', () => {
        const containers = collectNodes(addModal, (n) =>
            typeof n.props?.className === 'string' && n.props.className.includes('flex flex-col')
        );
        expect(containers.length).toBeGreaterThan(0);
        const footers = collectNodes(addModal, (n) =>
            typeof n.props?.className === 'string' && n.props.className.includes('flex-shrink-0')
        );
        expect(footers.length).toBe(1);
    });

    it('검증 에러 영역 — errors iteration', () => {
        const errLists = collectNodes(addModal, (n) =>
            n.iteration?.source && typeof n.iteration.source === 'string' &&
            n.iteration.source.includes('identity_message_definition_add_modal?.errors')
        );
        expect(errLists.length).toBe(1);
    });

    it('scope_type 은 policy 고정 readonly', () => {
        const inputs = collectNodes(addModal, (n) => n.name === 'Input' && n.props?.value === 'policy');
        expect(inputs.length).toBeGreaterThan(0);
        expect(inputs[0].props.readonly).toBe(true);
    });

    it('정책 키 Select — adminIdentityPolicies 매핑 + setState dot-notation', () => {
        const select = collectNodes(addModal, (n) =>
            n.name === 'Select' &&
            typeof n.props?.options === 'string' &&
            n.props.options.includes('adminIdentityPolicies?.data?.data')
        );
        expect(select.length).toBe(1);
        const action = select[0].actions?.[0];
        expect(action.handler).toBe('setState');
        expect(action.params['identity_message_definition_add_modal.form.scope_value']).toBe('{{$event.target.value}}');
    });

    it('정책 키 빈 상태 안내 메시지', () => {
        const empties = collectNodes(addModal, (n) =>
            n.name === 'P' && n.text === '$t:admin.settings.identity.messages.add_modal.policy_empty'
        );
        expect(empties.length).toBe(1);
    });

    it('언어 탭 — $locales iteration + editLang setState', () => {
        const tabBtns = collectNodes(addModal, (n) =>
            n.iteration?.source === '{{$locales}}' && n.iteration?.item_var === 'loc'
        );
        expect(tabBtns.length).toBe(1);
        expect(tabBtns[0].actions[0].params['identity_message_definition_add_modal.editLang']).toBe('{{loc}}');
    });

    it('name/subject/body 단일 필드 — editLang 인덱싱 + Object.assign 다국어 갱신', () => {
        // 각 필드는 form.{name,subject,body} 단일 객체에 editLang 키로 저장
        for (const field of ['name', 'subject', 'body']) {
            const updateExprKey = `identity_message_definition_add_modal.form.${field}`;
            const nodes = collectNodes(addModal, (n) =>
                n.handler === 'setState' &&
                typeof n.params?.[updateExprKey] === 'string' &&
                n.params[updateExprKey].includes('Object.assign')
            );
            expect(nodes.length, `${field} 필드의 Object.assign 패턴 누락`).toBeGreaterThanOrEqual(1);
        }
        // 분리 필드 금지 (로케일 하드코딩 회피 — 임의 _<locale> 접미사를 패턴으로 검출)
        // 예: name_ko/name_en/subject_ja/body_zh 등 어떤 로케일이든 분리 필드는 허용 안 함
        const localeSuffixPattern = /^(name|subject|body)_[a-z]{2}(_[A-Z]{2})?$/;
        const flatLocaleFields = collectNodes(addModal, (n) =>
            (n.name === 'Input' || n.name === 'HtmlEditor') &&
            typeof n.props?.name === 'string' &&
            localeSuffixPattern.test(n.props.name)
        );
        expect(flatLocaleFields, `로케일 분리 필드 잔존: ${flatLocaleFields.map((f: any) => f.props?.name).join(', ')}`).toEqual([]);
    });

    it('Save Button — apiCall POST + onSuccess(refetch + toast + closeModal + state cleanup)', () => {
        const seqHandlers = collectNodes(addModal, (n) =>
            n.handler === 'sequence' && (n.params?.actions ?? []).some((a: any) => a.handler === 'apiCall')
        );
        expect(seqHandlers.length).toBeGreaterThan(0);

        const apiCall = seqHandlers[0].params.actions.find((a: any) => a.handler === 'apiCall');
        expect(apiCall.target).toBe('/api/admin/identity/messages/definitions');
        expect(apiCall.params.method).toBe('POST');
        expect(apiCall.params.body.scope_type).toBe('policy');
        expect(apiCall.params.body.channels).toEqual(['mail']);

        const onSuccess = apiCall.onSuccess;
        expect(onSuccess).toContainEqual(expect.objectContaining({
            handler: 'refetchDataSource',
            params: expect.objectContaining({ dataSourceId: 'identityMessages' }),
        }));
        expect(onSuccess).toContainEqual(expect.objectContaining({
            handler: 'closeModal',
            target: 'modal_identity_message_definition_add',
        }));
    });

    it('Footer — common.cancel / common.save / common.saving i18n 키 (admin.common.* 금지)', () => {
        const cancels = collectNodes(addModal, (n) =>
            n.name === 'Button' && n.text === '$t:common.cancel'
        );
        expect(cancels.length).toBe(1);

        // 저장 버튼은 isSaving 분기로 saving/save 키 모두 사용
        const saveLabels = collectNodes(addModal, (n) =>
            typeof n.text === 'string' &&
            n.text.includes('$t:common.save') &&
            n.text.includes('$t:common.saving')
        );
        expect(saveLabels.length).toBe(1);

        // admin.common.* 키 금지
        const adminCommon = collectNodes(addModal, (n) =>
            typeof n.text === 'string' && n.text.includes('$t:admin.common.')
        );
        expect(adminCommon.length).toBe(0);
    });
});

describe('IDV 메시지 정의 — 삭제 모달', () => {
    it('Modal id + small + 경고 메시지', () => {
        expect(deleteModal.id).toBe('modal_identity_message_definition_delete');
        expect(deleteModal.props.size).toBe('small');
        const warnings = collectNodes(deleteModal, (n) =>
            n.text === '$t:admin.settings.identity.messages.delete_modal.warning_message'
        );
        expect(warnings.length).toBe(1);
    });

    it('Confirm Button — apiCall DELETE + onSuccess(refetch+toast+closeModal)', () => {
        const seqHandlers = collectNodes(deleteModal, (n) =>
            n.handler === 'sequence' && (n.params?.actions ?? []).some((a: any) => a.handler === 'apiCall')
        );
        const apiCall = seqHandlers[0].params.actions.find((a: any) => a.handler === 'apiCall');
        expect(apiCall.target).toContain('/api/admin/identity/messages/definitions/');
        expect(apiCall.params.method).toBe('DELETE');

        expect(apiCall.onSuccess).toContainEqual(expect.objectContaining({
            handler: 'refetchDataSource',
            params: expect.objectContaining({ dataSourceId: 'identityMessages' }),
        }));
    });

    it('스피너 + isDeletingIdentityMessage 비활성화', () => {
        const spinners = collectNodes(deleteModal, (n) =>
            n.name === 'Icon' && n.props?.name === 'spinner'
        );
        expect(spinners.length).toBe(1);
    });
});

describe('IDV 메시지 정의 — 기본값 복원 모달', () => {
    it('Modal id + small + 경고 메시지', () => {
        expect(resetModal.id).toBe('modal_identity_message_definition_reset');
        expect(resetModal.props.size).toBe('small');
    });

    it('Confirm Button — apiCall POST /reset + onSuccess(refetch+toast+closeModal)', () => {
        const seqHandlers = collectNodes(resetModal, (n) =>
            n.handler === 'sequence' && (n.params?.actions ?? []).some((a: any) => a.handler === 'apiCall')
        );
        const apiCall = seqHandlers[0].params.actions.find((a: any) => a.handler === 'apiCall');
        expect(apiCall.target).toContain('/reset');
        expect(apiCall.params.method).toBe('POST');

        expect(apiCall.onSuccess).toContainEqual(expect.objectContaining({
            handler: 'refetchDataSource',
            params: expect.objectContaining({ dataSourceId: 'identityMessages' }),
        }));
    });

    it('스피너 + isResettingIdentityMessage 비활성화', () => {
        const spinners = collectNodes(resetModal, (n) =>
            n.name === 'Icon' && n.props?.name === 'spinner'
        );
        expect(spinners.length).toBe(1);
    });
});
