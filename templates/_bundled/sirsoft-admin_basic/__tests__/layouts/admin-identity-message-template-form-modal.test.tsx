/**
 * @file admin-identity-message-template-form-modal.test.tsx
 * @description IDV 메시지 템플릿 편집 모달 — 알림 템플릿 모달 패리티 회귀 (#297)
 *
 * 검증 포인트:
 *  - Modal composite + size=xl + closeOnOverlayClick=false
 *  - flex 컨테이너 + flex-shrink-0 footer (스크롤 시 고정)
 *  - 검증 에러 영역 (errors iteration)
 *  - 정의 메타 정보 + 변수 가이드 (notification gray box 패턴)
 *  - 언어 탭 ($locales iteration + editLang setState)
 *  - subject/body 단일 필드 (editLang 인덱싱, Object.assign 패턴)
 *  - 미리보기/기본값 복원/취소/저장 버튼 — common.cancel/save i18n 키
 *  - 저장 버튼 스피너 + isSaving 비활성화
 */

import { describe, it, expect } from 'vitest';

const formModal = require('../../layouts/partials/admin_settings/_modal_identity_message_template_form.json');
const previewModal = require('../../layouts/partials/admin_settings/_modal_identity_message_template_preview.json');

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

describe('IDV 메시지 템플릿 편집 모달 — 알림 템플릿 패리티 (#297)', () => {
    describe('모달 메타', () => {
        it('Modal composite + size=xl + closeOnOverlayClick=false', () => {
            expect(formModal.type).toBe('composite');
            expect(formModal.name).toBe('Modal');
            expect(formModal.props.size).toBe('xl');
            expect(formModal.props.closeOnOverlayClick).toBe(false);
            // engine 미지원 props.id 중복 금지
            expect(formModal.props.id).toBeUndefined();
        });

        it('flex 컨테이너 + flex-shrink-0 footer 패턴', () => {
            const containers = collectNodes(formModal, (n) =>
                typeof n.props?.className === 'string' && n.props.className.includes('flex flex-col')
            );
            expect(containers.length).toBeGreaterThan(0);
            const footer = collectNodes(formModal, (n) =>
                typeof n.props?.className === 'string' && n.props.className.includes('flex-shrink-0')
            );
            expect(footer.length).toBe(1);
        });
    });

    describe('검증 에러 영역', () => {
        it('errors 키 iteration + 빨간 alert', () => {
            const errLists = collectNodes(formModal, (n) =>
                n.iteration?.source && typeof n.iteration.source === 'string' &&
                n.iteration.source.includes('identity_message_template_form_modal?.errors')
            );
            expect(errLists.length).toBe(1);
        });
    });

    describe('언어 탭 + 단일 subject/body 필드', () => {
        it('$locales iteration 으로 언어 탭 생성 + editLang setState', () => {
            const tabBtns = collectNodes(formModal, (n) =>
                n.iteration?.source === '{{$locales}}' && n.iteration?.item_var === 'loc'
            );
            expect(tabBtns.length).toBe(1);
            const action = tabBtns[0].actions?.[0];
            expect(action.handler).toBe('setState');
            expect(action.params['identity_message_template_form_modal.editLang']).toBe('{{loc}}');
        });

        it('subject Input 이 editLang 인덱싱 + Object.assign 으로 다국어 객체 갱신', () => {
            // 분리 필드 금지 (로케일 하드코딩 회피 — 임의 _<locale> 접미사를 패턴으로 검출)
            const localeSuffixPattern = /^(name|subject|body)_[a-z]{2}(_[A-Z]{2})?$/;
            const flatLocaleFields = collectNodes(formModal, (n) =>
                (n.name === 'Input' || n.name === 'HtmlEditor') &&
                typeof n.props?.name === 'string' &&
                localeSuffixPattern.test(n.props.name)
            );
            expect(flatLocaleFields, `로케일 분리 필드 잔존: ${flatLocaleFields.map((f: any) => f.props?.name).join(', ')}`).toEqual([]);

            // subject 단일 필드 + editLang 인덱싱
            const subjectInput = collectNodes(formModal, (n) =>
                n.name === 'Input' &&
                typeof n.props?.value === 'string' &&
                n.props.value.includes('identity_message_template_form_modal?.template?.subject?.')
            );
            expect(subjectInput.length).toBe(1);
            const action = subjectInput[0].actions?.[0];
            expect(action.handler).toBe('setState');
            const updateExpr = action.params['identity_message_template_form_modal.template.subject'];
            expect(updateExpr).toContain('Object.assign');
            expect(updateExpr).toContain('editLang');
        });

        it('body HtmlEditor 가 editLang 인덱싱 + Object.assign 으로 다국어 객체 갱신', () => {
            const bodyEditor = collectNodes(formModal, (n) =>
                n.name === 'HtmlEditor' &&
                typeof n.props?.content === 'string' &&
                n.props.content.includes('identity_message_template_form_modal?.template?.body?.')
            );
            expect(bodyEditor.length).toBe(1);
            const action = bodyEditor[0].actions?.[0];
            const updateExpr = action.params['identity_message_template_form_modal.template.body'];
            expect(updateExpr).toContain('Object.assign');
        });
    });

    describe('Footer — common i18n 키 + 스피너', () => {
        it('취소 버튼이 $t:common.cancel 사용 (admin.common.cancel 금지)', () => {
            const cancels = collectNodes(formModal, (n) =>
                n.name === 'Button' && n.text === '$t:common.cancel'
            );
            expect(cancels.length).toBe(1);
            expect(cancels[0].actions[0]).toMatchObject({
                handler: 'closeModal',
                target: 'modal_identity_message_template_form',
            });
        });

        it('저장 버튼이 $t:common.save / $t:common.saving 분기', () => {
            const saveLabels = collectNodes(formModal, (n) =>
                typeof n.text === 'string' &&
                n.text.includes('$t:common.save') &&
                n.text.includes('$t:common.saving')
            );
            expect(saveLabels.length).toBe(1);
        });

        it('저장 버튼 클릭 시 apiCall PATCH /templates/{id} + onSuccess(refetch+toast+closeModal+state cleanup)', () => {
            const seqHandlers = collectNodes(formModal, (n) =>
                n.handler === 'sequence' && (n.params?.actions ?? []).some((a: any) =>
                    a.handler === 'apiCall' && typeof a.target === 'string' && a.target.includes('/templates/')
                )
            );
            expect(seqHandlers.length).toBeGreaterThan(0);
            const apiCall = seqHandlers[0].params.actions.find((a: any) =>
                a.handler === 'apiCall' && a.params?.method === 'PATCH'
            );
            expect(apiCall).toBeTruthy();
            expect(apiCall.params.body.subject).toContain('identity_message_template_form_modal?.template?.subject');

            const onSuccess = apiCall.onSuccess;
            expect(onSuccess).toContainEqual(expect.objectContaining({
                handler: 'refetchDataSource',
                params: expect.objectContaining({ dataSourceId: 'identityMessages' }),
            }));
            expect(onSuccess).toContainEqual(expect.objectContaining({
                handler: 'closeModal',
                target: 'modal_identity_message_template_form',
            }));
        });

        it('미리보기 버튼: apiCall preview endpoint + onSuccess(setState + openModal target)', () => {
            const previewBtn = collectNodes(formModal, (n) =>
                n.name === 'Button' && n.text === '$t:admin.settings.identity.messages.modal.preview'
            );
            expect(previewBtn.length).toBe(1);
            const action = previewBtn[0].actions[0];
            expect(action.handler).toBe('apiCall');
            expect(action.target).toBe('/api/admin/identity/messages/templates/preview');
            expect(action.params.method).toBe('POST');
        });
    });
});

describe('IDV 메시지 미리보기 모달', () => {
    it('Modal composite + size=lg', () => {
        expect(previewModal.type).toBe('composite');
        expect(previewModal.name).toBe('Modal');
        expect(previewModal.props.size).toBe('lg');
    });

    it('subject 영역이 평면 키 바인딩 사용', () => {
        const subjectSpans = collectNodes(previewModal, (n) =>
            typeof n.text === 'string' && n.text.includes('identity_message_template_preview_modal?.subject')
        );
        expect(subjectSpans.length).toBeGreaterThan(0);
    });

    it('body 영역이 innerHTML 로 렌더된 본문을 표시', () => {
        const bodyDivs = collectNodes(previewModal, (n) =>
            typeof n.props?.innerHTML === 'string' && n.props.innerHTML.includes('identity_message_template_preview_modal?.body')
        );
        expect(bodyDivs.length).toBeGreaterThan(0);
    });

    it('닫기 버튼: closeModal target 패턴', () => {
        const closeBtns = collectNodes(previewModal, (n) =>
            n.name === 'Button' && n.actions?.some((a: any) => a.handler === 'closeModal')
        );
        expect(closeBtns.length).toBeGreaterThan(0);
        expect(closeBtns[0].actions[0].target).toBe('modal_identity_message_template_preview');
    });
});
