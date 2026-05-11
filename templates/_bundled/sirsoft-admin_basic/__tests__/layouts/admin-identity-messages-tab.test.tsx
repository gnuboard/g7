/**
 * @file admin-identity-messages-tab.test.tsx
 * @description IDV 메시지 정의 탭 (#297) — 알림 템플릿 패리티 회귀 테스트
 *
 * 검증 포인트:
 *  - 채널 서브탭 (mail) 노출 + activeIdentityMessageChannel setState
 *  - 총건수 + 페이지당 셀렉터 + "정의 추가" Button
 *  - chevron Button → expandedIdentityMessages 토글
 *  - 카드 헤더: 이름 + scope_type:scope_value 배지 + 활성/비활성 pill + 기본 badge(if)
 *  - subject 1줄 + 변수 배지(if length>0)
 *  - 우측 액션: Toggle + 편집 + 기본값 복원(if !is_default) + 삭제(if can_delete && scope_type=policy)
 *  - 펼침 영역 HtmlContent 본문 미리보기
 *  - 빈 상태 / Pagination
 *  - 데이터소스 identityMessages params 페이지네이션 + adminIdentityPolicies 신규
 */

import { describe, it, expect } from 'vitest';

const tabPartial = require('../../layouts/partials/admin_settings/_tab_identity_messages.json');
const tabIdentity = require('../../layouts/partials/admin_settings/_tab_identity.json');
const adminSettings = require('../../layouts/admin_settings.json');

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

describe('IDV 메시지 정의 탭 — 알림 템플릿 패리티 (#297)', () => {
    describe('상단 영역 (헤더 + 채널 서브탭 + 정보 행)', () => {
        it('채널 서브탭에 mail 버튼 + activeIdentityMessageChannel setState', () => {
            const buttons = collectNodes(tabPartial, (n) =>
                n.name === 'Button' && (n.children ?? []).some((c: any) => c.text === '$t:admin.settings.identity.messages.channels.mail')
            );
            expect(buttons.length).toBeGreaterThan(0);
            const action = buttons[0].actions?.[0];
            expect(action).toMatchObject({
                handler: 'setState',
                params: { target: 'local', activeIdentityMessageChannel: 'mail' },
            });
        });

        it('총건수 표시: identityMessages?.data?.meta?.total 바인딩', () => {
            const totalSpans = collectNodes(tabPartial, (n) =>
                typeof n.text === 'string' && n.text.startsWith('$t:admin.settings.identity.messages.total_count')
            );
            expect(totalSpans.length).toBeGreaterThan(0);
            expect(totalSpans[0].text).toContain('count={{');
        });

        it('페이지당 셀렉터: per_page Select + navigate replace+mergeQuery (messages_per_page 네임스페이스)', () => {
            const select = collectNodes(tabPartial, (n) => n.name === 'Select' && n.props?.name === 'identityMessagesPerPage');
            expect(select.length).toBe(1);
            expect(select[0].props.options).toHaveLength(3);
            const action = select[0].actions?.[0];
            expect(action.handler).toBe('navigate');
            expect(action.params.mergeQuery).toBe(true);
            // 정책 탭의 per_page 와 충돌 회피
            expect(action.params.query.messages_per_page).toBe('{{$event.target.value}}');
            expect(action.params.query.per_page).toBeUndefined();
        });

        it('"정의 추가" Button — sequence(setState init form + openModal modal_identity_message_definition_add)', () => {
            const addBtns = collectNodes(tabPartial, (n) =>
                n.name === 'Button' && (n.children ?? []).some((c: any) => c.text === '$t:admin.settings.identity.messages.btn_add_definition')
            );
            expect(addBtns.length).toBe(1);
            const seq = addBtns[0].actions[0];
            expect(seq.handler).toBe('sequence');
            const setStateAction = seq.params.actions[0];
            expect(setStateAction.params.target).toBe('global');
            expect(setStateAction.params.identity_message_definition_add_modal?.form).toMatchObject({
                provider_id: 'g7:core.mail',
                scope_type: 'policy',
            });
            const openModal = seq.params.actions[1];
            expect(openModal).toMatchObject({
                handler: 'openModal',
                target: 'modal_identity_message_definition_add',
            });
        });
    });

    describe('정의 카드 — 헤더 + 우측 액션', () => {
        it('iteration source 가 identityMessages?.data?.data ?? [] 이며 item_var=def', () => {
            const it = collectNodes(tabPartial, (n) => n.iteration?.item_var === 'def');
            expect(it.length).toBeGreaterThan(0);
            expect(it[0].iteration.source).toContain('identityMessages?.data?.data');
        });

        it('chevron Button — expandedIdentityMessages 토글', () => {
            const chevrons = collectNodes(tabPartial, (n) =>
                n.name === 'Icon' && typeof n.props?.name === 'string' && n.props.name.includes('expandedIdentityMessages')
            );
            expect(chevrons.length).toBeGreaterThan(0);
            const button = collectNodes(tabPartial, (n) =>
                n.name === 'Button' && (n.children ?? []).some((c: any) => c.name === 'Icon' && c.props?.name?.includes('expandedIdentityMessages'))
            );
            expect(button[0].actions[0].handler).toBe('setState');
            expect(button[0].actions[0].params.target).toBe('local');
        });

        it('Toggle apiCall 패턴 + onSuccess(toast + refetchDataSource)', () => {
            const toggles = collectNodes(tabPartial, (n) => n.name === 'Toggle');
            expect(toggles.length).toBeGreaterThan(0);
            const toggleAction = toggles[0].actions?.[0];
            expect(toggleAction.handler).toBe('apiCall');
            expect(toggleAction.target).toContain('/api/admin/identity/messages/definitions/');
            expect(toggleAction.target).toContain('toggle-active');

            expect(toggleAction.onSuccess).toContainEqual(expect.objectContaining({
                handler: 'refetchDataSource',
                params: expect.objectContaining({ dataSourceId: 'identityMessages' }),
            }));
            expect(toggleAction.onSuccess).toContainEqual(expect.objectContaining({
                handler: 'toast',
                params: expect.objectContaining({ variant: 'success' }),
            }));
        });

        it('편집 Button — sequence(setState nested definition/template + openModal)', () => {
            const editBtns = collectNodes(tabPartial, (n) =>
                n.name === 'Button' && n.text === '$t:admin.settings.identity.messages.edit'
            );
            expect(editBtns.length).toBeGreaterThan(0);
            const seq = editBtns[0].actions[0];
            expect(seq.handler).toBe('sequence');
            const setStateAction = seq.params.actions[0];
            const payload = setStateAction.params.identity_message_template_form_modal;
            expect(payload).toBeDefined();
            // 알림 패턴 미러: editLang + nested definition/template 객체
            expect(payload.editLang).toBeDefined();
            expect(payload.definition).toMatchObject({ id: expect.any(String) });
            expect(payload.template).toMatchObject({ id: expect.any(String) });
            // 분리 로케일 필드 금지 (로케일 하드코딩 회피 — 임의 _<locale> 접미사 검출)
            const flatLocaleKeys = Object.keys(payload).filter((k) =>
                /^(name|subject|body)_[a-z]{2}(_[A-Z]{2})?$/.test(k)
            );
            expect(flatLocaleKeys, `로케일 분리 키 잔존: ${flatLocaleKeys.join(', ')}`).toEqual([]);
            expect(setStateAction.params.key).toBeUndefined();
            expect(setStateAction.params.value).toBeUndefined();

            const openModal = seq.params.actions[1];
            expect(openModal).toMatchObject({
                handler: 'openModal',
                target: 'modal_identity_message_template_form',
            });
        });

        it('기본값 복원 Button — if !is_default + openModal modal_identity_message_definition_reset', () => {
            const resetBtns = collectNodes(tabPartial, (n) =>
                n.name === 'Button' && n.text === '$t:admin.settings.identity.messages.btn_reset'
            );
            expect(resetBtns.length).toBe(1);
            expect(resetBtns[0].if).toBe('{{!def.is_default}}');
            const seq = resetBtns[0].actions[0];
            const setStateAction = seq.params.actions[0];
            expect(setStateAction.params.resetIdentityMessageTargetId).toBe('{{def.id}}');
            const openModal = seq.params.actions[1];
            expect(openModal.target).toBe('modal_identity_message_definition_reset');
        });

        it('삭제 Button — if can_delete && scope_type=policy + openModal modal_identity_message_definition_delete', () => {
            const deleteBtns = collectNodes(tabPartial, (n) =>
                n.name === 'Button' && n.text === '$t:admin.settings.identity.messages.btn_delete'
            );
            expect(deleteBtns.length).toBe(1);
            expect(deleteBtns[0].if).toContain('def.abilities?.can_delete');
            expect(deleteBtns[0].if).toContain("def.scope_type === 'policy'");
            const seq = deleteBtns[0].actions[0];
            const setStateAction = seq.params.actions[0];
            expect(setStateAction.params.deleteIdentityMessageTargetId).toBe('{{def.id}}');
            const openModal = seq.params.actions[1];
            expect(openModal.target).toBe('modal_identity_message_definition_delete');
        });
    });

    describe('펼침 영역 + 빈 상태 + Pagination', () => {
        it('expanded 영역에 HtmlContent body preview', () => {
            const htmlContents = collectNodes(tabPartial, (n) => n.name === 'HtmlContent');
            expect(htmlContents.length).toBeGreaterThan(0);
            expect(htmlContents[0].props.content).toContain('def.templates?.[0]?.body');
        });

        it('빈 상태 카드: identityMessages?.data?.data 길이 0', () => {
            const emptyCards = collectNodes(tabPartial, (n) =>
                n.if && typeof n.if === 'string' && n.if.includes('identityMessages?.data?.data') && n.if.includes('=== 0')
            );
            expect(emptyCards.length).toBeGreaterThan(0);
        });

        it('Pagination 은 messages_page 네임스페이스 키 사용 (정책 page 충돌 회피)', () => {
            const pagination = collectNodes(tabPartial, (n) => n.name === 'Pagination');
            expect(pagination.length).toBe(1);
            const action = pagination[0].actions?.[0];
            expect(action.event).toBe('onPageChange');
            expect(action.handler).toBe('navigate');
            // 정책 탭의 page 와 충돌 회피: messages_page 네임스페이스 사용
            expect(action.params.query.messages_page).toBe('{{$args[0]}}');
            expect(action.params.query.page).toBeUndefined();
        });
    });

    describe('상위 레이아웃 통합', () => {
        it('admin_settings.json data_sources 에 identityMessages 페이지네이션 params + adminIdentityPolicies 정의', () => {
            const idMsgs = adminSettings.data_sources.find((d: any) => d.id === 'identityMessages');
            expect(idMsgs).toBeTruthy();
            // 정책 탭과 query 키 충돌 회피: messages_page / messages_per_page 네임스페이스
            expect(idMsgs.params.page).toBe('{{query.messages_page || 1}}');
            expect(idMsgs.params.per_page).toBe('{{query.messages_per_page || 20}}');

            const adminPolicies = adminSettings.data_sources.find((d: any) => d.id === 'adminIdentityPolicies');
            expect(adminPolicies).toBeTruthy();
            expect(adminPolicies.endpoint).toBe('/api/admin/identity/policies');
            expect(adminPolicies.params.source_type).toBe('admin');
            // AdminIdentityPolicyIndexRequest 의 per_page max:100 을 초과하면
            // 페이지 새로고침 시마다 422 가 반환됨 (회귀: 2026-04-28).
            expect(adminPolicies.params.per_page).toBeLessThanOrEqual(100);
        });

        it('IDV 메시지 모달 5종이 admin_settings.json modals 섹션에 등록 (tab partial 내부 금지)', () => {
            // 모달은 isolated scope 와 modalStack 정상 관리를 위해 반드시 최상위 modals 섹션에 위치해야 함.
            // tab partial 내부에 두면 modalStack 누수로 "already open" 오류 발생 (회귀: 2026-04-28).
            const modalsPartials = (adminSettings.modals ?? [])
                .map((c: any) => c.partial)
                .filter(Boolean);
            expect(modalsPartials).toContain('partials/admin_settings/_modal_identity_message_template_form.json');
            expect(modalsPartials).toContain('partials/admin_settings/_modal_identity_message_template_preview.json');
            expect(modalsPartials).toContain('partials/admin_settings/_modal_identity_message_definition_add.json');
            expect(modalsPartials).toContain('partials/admin_settings/_modal_identity_message_definition_delete.json');
            expect(modalsPartials).toContain('partials/admin_settings/_modal_identity_message_definition_reset.json');

            // _tab_identity.json 자식에 IDV 메시지 모달 partial 이 남아있지 않아야 함
            const tabPartials = (tabIdentity.children ?? [])
                .map((c: any) => c.partial)
                .filter(Boolean);
            expect(tabPartials).not.toContain('partials/admin_settings/_modal_identity_message_template_form.json');
            expect(tabPartials).not.toContain('partials/admin_settings/_modal_identity_message_template_preview.json');
            expect(tabPartials).not.toContain('partials/admin_settings/_modal_identity_message_definition_add.json');
            expect(tabPartials).not.toContain('partials/admin_settings/_modal_identity_message_definition_delete.json');
            expect(tabPartials).not.toContain('partials/admin_settings/_modal_identity_message_definition_reset.json');
        });
    });
});
