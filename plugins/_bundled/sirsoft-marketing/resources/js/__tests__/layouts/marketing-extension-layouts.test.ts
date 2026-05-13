/**
 * sirsoft-marketing 플러그인 Extension JSON 레이아웃 구조 검증 테스트
 *
 * @description
 * Phase 2 잔여 테스트 시나리오:
 * - *_enabled=false 시 항목 숨김 if 조건
 * - slug 설정 여부에 따른 약관 보기 버튼 표시/숨김
 * - apiCall onSuccess 후 상태 저장 경로
 * - channels iteration 구조
 * - Object.assign setState 패턴
 * - modals 구조 (marketing_terms_modal)
 * - 다국어 키 네임스페이스
 * - 다크모드 클래스
 */

import { describe, it, expect } from 'vitest';

import registerLayout from '../../../extensions/user-marketing-register.json';
import profileLayout from '../../../extensions/user-marketing-profile.json';
import profileViewLayout from '../../../extensions/user-marketing-profile-view.json';
import formLayout from '../../../extensions/user-marketing-form.json';
import detailLayout from '../../../extensions/user-marketing-detail.json';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

// ─────────────────────────────────────────────
// 헬퍼
// ─────────────────────────────────────────────

function findById(node: any, id: string): any | null {
    if (!node || typeof node !== 'object') return null;
    if (node.id === id) return node;
    for (const key of ['children', 'components', 'injections']) {
        const children = node[key];
        if (Array.isArray(children)) {
            for (const child of children) {
                const found = findById(child, id);
                if (found) return found;
            }
        }
    }
    // injections 내부 components
    if (node.position !== undefined && node.components) {
        for (const c of node.components) {
            const found = findById(c, id);
            if (found) return found;
        }
    }
    return null;
}

function findAll(node: any, predicate: (n: any) => boolean): any[] {
    const results: any[] = [];
    if (!node || typeof node !== 'object') return results;
    if (predicate(node)) results.push(node);
    for (const key of ['children', 'components']) {
        const children = node[key];
        if (Array.isArray(children)) {
            for (const child of children) {
                results.push(...findAll(child, predicate));
            }
        }
    }
    return results;
}

function findAllRecursive(node: any, predicate: (n: any) => boolean): any[] {
    const results: any[] = [];
    if (!node || typeof node !== 'object') return results;
    if (Array.isArray(node)) {
        for (const item of node) {
            results.push(...findAllRecursive(item, predicate));
        }
        return results;
    }
    if (predicate(node)) results.push(node);
    for (const val of Object.values(node)) {
        if (val && typeof val === 'object') {
            results.push(...findAllRecursive(val, predicate));
        }
    }
    return results;
}

function collectTexts(node: any): string[] {
    return findAllRecursive(node, (n) => typeof n.text === 'string' && n.text.startsWith('$t:'))
        .map((n) => n.text);
}

function collectClassNames(node: any): string[] {
    return findAllRecursive(node, (n) => typeof n?.props?.className === 'string')
        .map((n) => n.props.className as string);
}

function findActions(node: any, handlerName: string): any[] {
    const results: any[] = [];
    if (!node || typeof node !== 'object') return results;
    if (Array.isArray(node)) {
        for (const item of node) results.push(...findActions(item, handlerName));
        return results;
    }
    if (node.handler === handlerName) results.push(node);
    for (const val of Object.values(node)) {
        if (val && typeof val === 'object') {
            results.push(...findActions(val, handlerName));
        }
    }
    return results;
}

// ─────────────────────────────────────────────
// user-marketing-register.json
// ─────────────────────────────────────────────

describe('user-marketing-register.json', () => {
    const layout = registerLayout as any;

    it('target_layout이 auth/register이다', () => {
        expect(layout.target_layout).toBe('auth/register');
    });

    it('marketing_settings data_source가 정의되어 있다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'marketing_settings');
        expect(ds).toBeDefined();
        expect(ds.endpoint).toBe('/api/plugins/sirsoft-marketing/settings');
        expect(ds.auth_required).toBe(false);
    });

    it('marketing_consent_enabled 조건부 렌더링 if 조건이 있다', () => {
        const section = findById(layout, 'register_marketing_consent_section');
        expect(section).not.toBeNull();
        // marketing_consent 섹션 상위 Div의 if 조건
        const divWithIf = findAllRecursive(section, (n) => typeof n.if === 'string' && n.if.includes('marketing_consent_enabled'));
        expect(divWithIf.length).toBeGreaterThan(0);
        expect(divWithIf[0].if).toContain('marketing_settings?.data?.marketing_consent_enabled === true');
    });

    it('약관 보기 버튼이 marketing_consent_terms_slug_set 조건으로 감싸져 있다', () => {
        const section = findById(layout, 'register_marketing_consent_section');
        const viewBtns = findAllRecursive(section, (n) =>
            n.name === 'Button' && typeof n.if === 'string' && n.if.includes('terms_slug_set')
        );
        expect(viewBtns.length).toBeGreaterThan(0);
        expect(viewBtns[0].if).toContain('terms_slug_set === true');
    });

    it('channels iteration이 item_var를 사용한다', () => {
        const section = findById(layout, 'register_marketing_consent_section');
        const iterNodes = findAllRecursive(section, (n) => n.iteration?.item_var !== undefined);
        expect(iterNodes.length).toBeGreaterThan(0);
        for (const node of iterNodes) {
            expect(node.iteration.item_var).toBeDefined();
            expect(node.iteration.item_var).not.toBe('item');
        }
    });

    it('channels iteration source에 fallback이 있다', () => {
        const section = findById(layout, 'register_marketing_consent_section');
        const iterNodes = findAllRecursive(section, (n) => n.iteration?.source !== undefined);
        for (const node of iterNodes) {
            expect(node.iteration.source).toContain('??');
        }
    });

    it('marketing_consent 체크박스 change 액션이 Object.assign 패턴을 사용한다', () => {
        const section = findById(layout, 'register_marketing_consent_section');
        const setStateActions = findActions(section, 'setState');
        const masterSetState = setStateActions.find((a: any) =>
            typeof a.params?.registerForm === 'string' &&
            a.params.registerForm.includes('Object.assign')
        );
        expect(masterSetState).toBeDefined();
        // 전체 폼 교체가 아닌 Object.assign으로 병합
        expect(masterSetState.params.registerForm).toContain('_local.registerForm');
    });

    it('third_party_consent_enabled 조건부 렌더링이 있다', () => {
        const section = findById(layout, 'register_marketing_consent_section');
        const divWithIf = findAllRecursive(section, (n) =>
            typeof n.if === 'string' && n.if.includes('third_party_consent_enabled')
        );
        expect(divWithIf.length).toBeGreaterThan(0);
    });

    it('info_disclosure_enabled 조건부 렌더링이 있다', () => {
        const section = findById(layout, 'register_marketing_consent_section');
        const divWithIf = findAllRecursive(section, (n) =>
            typeof n.if === 'string' && n.if.includes('info_disclosure_enabled')
        );
        expect(divWithIf.length).toBeGreaterThan(0);
    });

    it('apiCall onSuccess에서 $response 없이 response.data를 사용한다', () => {
        const apiCalls = findActions(layout, 'apiCall');
        for (const action of apiCalls) {
            const successActions = Array.isArray(action.onSuccess) ? action.onSuccess : [];
            for (const cb of successActions) {
                const paramsStr = JSON.stringify(cb.params ?? {});
                expect(paramsStr).not.toContain('$response');
                if (paramsStr.includes('marketingTermsPageData')) {
                    expect(paramsStr).toContain('response.data');
                }
            }
        }
    });

    it('marketing_terms_modal이 modals에 정의되어 있다', () => {
        const modals = layout.modals;
        expect(Array.isArray(modals)).toBe(true);
        const modal = modals.find((m: any) => m.id === 'marketing_terms_modal');
        expect(modal).toBeDefined();
        expect(modal.name).toBe('Modal');
    });

    it('모달 닫기 버튼이 closeModal 핸들러를 사용한다', () => {
        const modals = layout.modals;
        const closeActions = findActions(modals, 'closeModal');
        expect(closeActions.length).toBeGreaterThan(0);
    });

    it('다국어 키가 sirsoft-marketing 네임스페이스를 사용한다', () => {
        const texts = collectTexts(layout);
        for (const text of texts) {
            expect(text).toMatch(/^\$t:(sirsoft-marketing|common)\./);
        }
    });

    it('다크모드 클래스가 bg-white 단독 사용 없이 사용된다', () => {
        const classNames = collectClassNames(layout);
        for (const cls of classNames) {
            if (cls.includes('bg-white') && !cls.includes('bg-white/')) {
                expect(cls).toContain('dark:bg-');
            }
        }
    });
});

// ─────────────────────────────────────────────
// user-marketing-profile.json
// ─────────────────────────────────────────────

describe('user-marketing-profile.json', () => {
    const layout = profileLayout as any;

    it('target_layout이 mypage/profile-edit이다', () => {
        expect(layout.target_layout).toBe('mypage/profile-edit');
    });

    it('marketing_settings data_source가 정의되어 있다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'marketing_settings');
        expect(ds).toBeDefined();
    });

    it('_local.profileEditForm?.marketing_consent_enabled 조건부 렌더링이 있다', () => {
        const conditionals = findAllRecursive(layout, (n) =>
            typeof n.if === 'string' && n.if.includes('marketing_consent_enabled')
        );
        expect(conditionals.length).toBeGreaterThan(0);
    });

    it('channels iteration이 item_var를 사용한다', () => {
        const iterNodes = findAllRecursive(layout, (n) => n.iteration?.item_var !== undefined);
        expect(iterNodes.length).toBeGreaterThan(0);
        for (const node of iterNodes) {
            expect(node.iteration.item_var).not.toBe('item');
        }
    });

    it('약관 보기 버튼이 terms_slug_set 조건으로 표시된다', () => {
        const viewBtns = findAllRecursive(layout, (n) =>
            n.name === 'Button' && typeof n.if === 'string' && n.if.includes('terms_slug_set')
        );
        expect(viewBtns.length).toBeGreaterThan(0);
    });

    it('apiCall onSuccess에서 marketingTermsPageData 상태를 global에 저장한다', () => {
        const apiCalls = findActions(layout, 'apiCall');
        let found = false;
        for (const action of apiCalls) {
            const successActions = Array.isArray(action.onSuccess) ? action.onSuccess : [];
            for (const cb of successActions) {
                if (cb.params?.marketingTermsPageData !== undefined && cb.params?.target === 'global') {
                    found = true;
                }
            }
        }
        expect(found).toBe(true);
    });

    it('marketing_terms_modal이 modals에 정의되어 있다', () => {
        const modals = layout.modals;
        expect(Array.isArray(modals)).toBe(true);
        const modal = modals.find((m: any) => m.id === 'marketing_terms_modal');
        expect(modal).toBeDefined();
    });

    it('다국어 키가 sirsoft-marketing 네임스페이스를 사용한다', () => {
        const texts = collectTexts(layout);
        for (const text of texts) {
            expect(text).toMatch(/^\$t:(sirsoft-marketing|common)\./);
        }
    });

    it('다크모드 클래스가 bg-white 단독 사용 없이 사용된다', () => {
        const classNames = collectClassNames(layout);
        for (const cls of classNames) {
            if (cls.includes('bg-white') && !cls.includes('bg-white/')) {
                expect(cls).toContain('dark:bg-');
            }
        }
    });
});

// ─────────────────────────────────────────────
// user-marketing-profile-view.json
// ─────────────────────────────────────────────

describe('user-marketing-profile-view.json', () => {
    const layout = profileViewLayout as any;

    it('target_layout이 mypage/profile이다', () => {
        expect(layout.target_layout).toBe('mypage/profile');
    });

    it('marketing_consent_enabled 조건부 렌더링이 있다', () => {
        const conditionals = findAllRecursive(layout, (n) =>
            typeof n.if === 'string' && n.if.includes('marketing_consent_enabled')
        );
        expect(conditionals.length).toBeGreaterThan(0);
    });

    it('channels iteration이 있고 item_var를 사용한다', () => {
        const iterNodes = findAllRecursive(layout, (n) => n.iteration?.item_var !== undefined);
        expect(iterNodes.length).toBeGreaterThan(0);
    });

    it('다국어 키가 sirsoft-marketing 네임스페이스를 사용한다', () => {
        const texts = collectTexts(layout);
        for (const text of texts) {
            expect(text).toMatch(/^\$t:(sirsoft-marketing|common)\./);
        }
    });
});

// ─────────────────────────────────────────────
// user-marketing-form.json (관리자 사용자 폼)
// ─────────────────────────────────────────────

describe('user-marketing-form.json', () => {
    const layout = formLayout as any;

    it('target_layout이 admin_user_form이다', () => {
        expect(layout.target_layout).toBe('admin_user_form');
    });

    it('marketing_settings data_source가 정의되어 있다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'marketing_settings');
        expect(ds).toBeDefined();
        expect(ds.auth_required).toBe(true);
    });

    it('marketing_consent_enabled 조건부 렌더링이 있다', () => {
        const conditionals = findAllRecursive(layout, (n) =>
            typeof n.if === 'string' && n.if.includes('marketing_consent_enabled')
        );
        expect(conditionals.length).toBeGreaterThan(0);
    });

    it('모든 Button에 type 속성이 명시되어 있다', () => {
        const buttons = findAllRecursive(layout, (n) => n.name === 'Button');
        for (const btn of buttons) {
            expect(btn.props?.type, `Button(id=${btn.id}) type 누락`).toBeDefined();
        }
    });

    it('약관 보기 버튼이 terms_slug_set 조건으로 표시된다', () => {
        const viewBtns = findAllRecursive(layout, (n) =>
            n.name === 'Button' && typeof n.if === 'string' && n.if.includes('terms_slug_set')
        );
        expect(viewBtns.length).toBeGreaterThan(0);
    });

    it('apiCall onSuccess에서 marketingTermsPageData를 global 상태에 저장한다', () => {
        const apiCalls = findActions(layout, 'apiCall');
        let found = false;
        for (const action of apiCalls) {
            const successActions = Array.isArray(action.onSuccess) ? action.onSuccess : [];
            for (const cb of successActions) {
                if (cb.params?.marketingTermsPageData !== undefined) {
                    expect(cb.params.target).toBe('global');
                    expect(cb.params.marketingTermsPageData).toContain('response.data');
                    found = true;
                }
            }
        }
        expect(found).toBe(true);
    });

    it('marketing_terms_modal이 modals에 정의되어 있다', () => {
        const modals = layout.modals;
        expect(Array.isArray(modals)).toBe(true);
        const modal = modals.find((m: any) => m.id === 'marketing_terms_modal');
        expect(modal).toBeDefined();
    });

    it('channels iteration이 있고 item_var를 사용한다', () => {
        const iterNodes = findAllRecursive(layout, (n) => n.iteration?.item_var !== undefined);
        expect(iterNodes.length).toBeGreaterThan(0);
        for (const node of iterNodes) {
            expect(node.iteration.item_var).not.toBe('item');
        }
    });

    it('다국어 키가 sirsoft-marketing 또는 admin 네임스페이스를 사용한다', () => {
        const texts = collectTexts(layout);
        for (const text of texts) {
            expect(text).toMatch(/^\$t:(sirsoft-marketing|common|admin)\./);
        }
    });

    it('다크모드 클래스가 bg-white 단독 사용 없이 사용된다', () => {
        const classNames = collectClassNames(layout);
        for (const cls of classNames) {
            if (cls.includes('bg-white') && !cls.includes('bg-white/')) {
                expect(cls).toContain('dark:bg-');
            }
        }
    });
});

// ─────────────────────────────────────────────
// user-marketing-detail.json (관리자 사용자 상세)
// ─────────────────────────────────────────────

describe('user-marketing-detail.json', () => {
    const layout = detailLayout as any;

    it('target_layout이 admin_user_detail이다', () => {
        expect(layout.target_layout).toBe('admin_user_detail');
    });

    it('marketing_consent_enabled 조건부 렌더링이 있다', () => {
        const conditionals = findAllRecursive(layout, (n) =>
            typeof n.if === 'string' && n.if.includes('marketing_consent_enabled')
        );
        expect(conditionals.length).toBeGreaterThan(0);
    });

    it('channels iteration이 있고 item_var를 사용한다', () => {
        const iterNodes = findAllRecursive(layout, (n) => n.iteration?.item_var !== undefined);
        expect(iterNodes.length).toBeGreaterThan(0);
        for (const node of iterNodes) {
            expect(node.iteration.item_var).not.toBe('item');
        }
    });

    it('다국어 키가 sirsoft-marketing 또는 admin 네임스페이스를 사용한다', () => {
        const texts = collectTexts(layout);
        for (const text of texts) {
            expect(text).toMatch(/^\$t:(sirsoft-marketing|common|admin)\./);
        }
    });

    it('다크모드 클래스가 bg-white 단독 사용 없이 사용된다', () => {
        const classNames = collectClassNames(layout);
        for (const cls of classNames) {
            if (cls.includes('bg-white') && !cls.includes('bg-white/')) {
                expect(cls).toContain('dark:bg-');
            }
        }
    });
});

// ─────────────────────────────────────────────
// plugin_settings.json (관리자 플러그인 설정)
// ─────────────────────────────────────────────

describe('plugin_settings.json', () => {
    const layout = pluginSettingsLayout as any;

    it('layout_name이 plugin_settings이다', () => {
        expect(layout.layout_name).toBe('plugin_settings');
    });

    it('permissions에 core.plugins.update가 있다', () => {
        expect(layout.permissions).toContain('core.plugins.update');
    });

    it('settings data_source가 정의되어 있다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'settings');
        expect(ds).toBeDefined();
        expect(ds.auth_required).toBe(true);
    });

    it('settings data_source onSuccess에서 form.channels를 local 상태에 저장한다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'settings');
        const actions = ds?.onSuccess?.actions ?? [];
        const localSetState = actions.find((a: any) =>
            a.params?.target === 'local' && a.params?.['form.channels'] !== undefined
        );
        expect(localSetState).toBeDefined();
    });

    it('settings data_source onSuccess에서 channels를 global 상태에 저장한다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'settings');
        const actions = ds?.onSuccess?.actions ?? [];
        const globalSetState = actions.find((a: any) =>
            a.params?.target === 'global' && a.params?.channels !== undefined
        );
        expect(globalSetState).toBeDefined();
    });

    it('channel_edit_modal이 modals에 정의되어 있다', () => {
        const modals = layout.modals;
        expect(modals).toBeDefined();
        // 객체형 modals
        if (typeof modals === 'object' && !Array.isArray(modals)) {
            expect(modals.channel_edit_modal).toBeDefined();
            expect(modals.channel_edit_modal.name).toBe('Modal');
        } else {
            const modal = (modals as any[]).find((m: any) => m.id === 'channel_edit_modal');
            expect(modal).toBeDefined();
        }
    });

    it('channel_edit_modal이 closeOnBackdropClick: false를 사용한다', () => {
        const modals = layout.modals;
        const editModal = typeof modals === 'object' && !Array.isArray(modals)
            ? modals.channel_edit_modal
            : (modals as any[]).find((m: any) => m.id === 'channel_edit_modal');
        expect(editModal?.props?.closeOnBackdropClick).toBe(false);
    });

    it('채널 PUT API 저장 액션이 있다', () => {
        const apiCalls = findActions(layout, 'apiCall');
        const putChannels = apiCalls.find((a: any) =>
            a.target?.includes('channels') && a.params?.method === 'PUT'
        );
        expect(putChannels).toBeDefined();
    });

    it('채널 삭제 버튼이 apiCall 없이 setState로만 채널을 제거한다', () => {
        // 삭제 버튼: trash 아이콘을 가진 Button의 actions
        const deleteButtons = findAllRecursive(layout, (n: any) =>
            n.name === 'Button' &&
            n.if === '{{!channel.is_system}}' &&
            JSON.stringify(n.children ?? []).includes('trash')
        );
        expect(deleteButtons.length).toBeGreaterThan(0);

        const deleteBtn = deleteButtons[0];
        const clickAction = deleteBtn.actions?.find((a: any) => a.type === 'click');
        expect(clickAction).toBeDefined();

        // apiCall이 삭제 버튼 액션에 없어야 함
        const apiCallsInDelete = findActions(clickAction, 'apiCall');
        expect(apiCallsInDelete.length).toBe(0);

        // setState로 channels를 filter하여 제거
        const setStates = findActions(clickAction, 'setState');
        const channelFilter = setStates.find((a: any) =>
            typeof a.params?.channels === 'string' &&
            a.params.channels.includes('filter')
        );
        expect(channelFilter).toBeDefined();
    });

    it('채널 삭제 버튼이 hasChanges를 true로 설정한다', () => {
        const deleteButtons = findAllRecursive(layout, (n: any) =>
            n.name === 'Button' &&
            n.if === '{{!channel.is_system}}' &&
            JSON.stringify(n.children ?? []).includes('trash')
        );
        const deleteBtn = deleteButtons[0];
        const clickAction = deleteBtn.actions?.find((a: any) => a.type === 'click');

        const setStates = findActions(clickAction, 'setState');
        const hasChangesSet = setStates.find((a: any) =>
            a.params?.hasChanges === true && a.params?.target === 'local'
        );
        expect(hasChangesSet).toBeDefined();
    });

    it('저장 버튼이 channels API를 먼저 호출한 후 settings API를 호출한다', () => {
        // save_button은 slots.content 내부에 있으므로 slots에서 탐색
        const slotsContent = layout.slots?.content ?? [];
        let saveBtn: any = null;
        for (const node of slotsContent) {
            saveBtn = findById(node, 'save_button');
            if (saveBtn) break;
        }
        expect(saveBtn).not.toBeNull();

        const clickAction = saveBtn.actions?.find((a: any) => a.type === 'click');
        const apiCalls = findActions(clickAction, 'apiCall');

        // channels API 호출이 있어야 함
        const channelsApi = apiCalls.find((a: any) =>
            a.target?.includes('sirsoft-marketing/admin/channels')
        );
        expect(channelsApi).toBeDefined();

        // channels API의 onSuccess 안에 settings API 호출이 있어야 함
        const settingsApiInSuccess = findActions(channelsApi?.onSuccess ?? [], 'apiCall');
        const settingsApi = settingsApiInSuccess.find((a: any) =>
            a.target?.includes('/settings')
        );
        expect(settingsApi).toBeDefined();
    });

    it('채널 검증 실패 시 channel_deactivate_modal이 열린다', () => {
        const slotsContent = layout.slots?.content ?? [];
        let saveBtn: any = null;
        for (const node of slotsContent) {
            saveBtn = findById(node, 'save_button');
            if (saveBtn) break;
        }
        expect(saveBtn).not.toBeNull();

        const clickAction = saveBtn.actions?.find((a: any) => a.type === 'click');
        const apiCalls = findActions(clickAction, 'apiCall');

        const channelsApi = apiCalls.find((a: any) =>
            a.target?.includes('sirsoft-marketing/admin/channels')
        );
        expect(channelsApi).toBeDefined();

        // onError에서 openModal이 있어야 함
        const openModals = findActions(channelsApi?.onError ?? [], 'openModal');
        const deactivateModal = openModals.find((a: any) =>
            a.target === 'channel_deactivate_modal'
        );
        expect(deactivateModal).toBeDefined();
    });

    it('channel_deactivate_modal 확인 버튼이 apiCall 없이 setState로만 동작한다', () => {
        const modals = layout.modals;
        const deactivateModal = typeof modals === 'object' && !Array.isArray(modals)
            ? modals.channel_deactivate_modal
            : (modals as any[]).find((m: any) => m.id === 'channel_deactivate_modal');
        expect(deactivateModal).toBeDefined();

        // 모달 내 apiCall이 없어야 함
        const apiCallsInModal = findActions(deactivateModal, 'apiCall');
        expect(apiCallsInModal.length).toBe(0);

        // setState로 channels를 복원 (enabled: false)
        const setStates = findActions(deactivateModal, 'setState');
        const channelRestore = setStates.find((a: any) =>
            typeof a.params?.channels === 'string' &&
            a.params.channels.includes('enabled: false')
        );
        expect(channelRestore).toBeDefined();
    });

    it('모든 Button에 type 속성이 명시되어 있다', () => {
        const buttons = findAllRecursive(layout, (n) => n.name === 'Button');
        for (const btn of buttons) {
            expect(btn.props?.type, `Button(id=${btn.id}) type 누락`).toBeDefined();
        }
    });

    it('다국어 키가 sirsoft-marketing 또는 admin 네임스페이스를 사용한다', () => {
        const texts = collectTexts(layout);
        for (const text of texts) {
            expect(text).toMatch(/^\$t:(sirsoft-marketing|common|admin)\./);
        }
    });

    it('다크모드 클래스가 bg-white 단독 사용 없이 사용된다', () => {
        const classNames = collectClassNames(layout);
        for (const cls of classNames) {
            if (cls.includes('bg-white') && !cls.includes('bg-white/')) {
                expect(cls).toContain('dark:bg-');
            }
        }
    });
});
