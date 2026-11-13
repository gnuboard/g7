/**
 * sirsoft-marketing 플러그인 Extension JSON 레이아웃 구조 검증 테스트
 *
 * @description
 * 동의 값의 입력 칸(가입, 내 정보 수정과 보기, 관리자 회원 폼)은 회원 항목 선언으로 옮겨 코어 템플릿이 그린다.
 * 이 플러그인에 남은 화면은 둘이다.
 * - 관리자 회원 상세의 동의 이력 조각(값을 그리지 않고 이력 표만 보인다)
 * - 플러그인 설정 화면(채널 편집, 저장 순서, 권한)
 */

import { describe, it, expect } from 'vitest';

import detailLayout from '../../../extensions/user-marketing-detail.json';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

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
// user-marketing-detail.json (관리자 사용자 상세)
// ─────────────────────────────────────────────

describe('user-marketing-detail.json', () => {
    const layout = detailLayout as any;

    // @scenario layout=detail
    // @effects extension_layout_contract_holds
    it('target_layout이 admin_user_detail이다', () => {
        expect(layout.target_layout).toBe('admin_user_detail');
    });

    // @scenario layout=detail
    // @effects extension_layout_contract_holds, consent_value_inputs_left_to_member_fields
    it('동의 값을 그리지 않는다 — 체크박스와 사용 여부 조건이 없다', () => {
        // 지금의 동의 값은 회원 항목으로 선언되어 코어 회원 화면이 그린다. 여기서 또 그리면 같은 값이 두 번 보인다.
        expect(findAllRecursive(layout, (n) => n.name === 'Checkbox')).toHaveLength(0);
        expect(JSON.stringify(layout)).not.toContain('_enabled');
        expect(JSON.stringify(layout)).not.toContain('terms_slug');
    });

    // @scenario layout=detail
    // @effects extension_layout_contract_holds
    it('동의 이력 표가 응답의 consent_histories 를 반복한다', () => {
        const iterNodes = findAllRecursive(layout, (n) => n.iteration?.item_var !== undefined);
        expect(iterNodes).toHaveLength(1);
        expect(iterNodes[0].iteration.item_var).toBe('history');
        expect(iterNodes[0].iteration.source).toContain('consent_histories');
    });

    // @scenario layout=detail
    // @effects extension_layout_contract_holds
    it('탭 항목과 탭 내용의 id 가 같다', () => {
        expect(JSON.stringify(layout)).toContain('"id":"ext_marketing"');
        expect(findById(layout, 'ext_marketing_content')).not.toBeNull();
    });

    // @scenario layout=detail
    // @effects extension_layout_contract_holds
    it('다국어 키가 sirsoft-marketing 또는 admin 네임스페이스를 사용한다', () => {
        const texts = collectTexts(layout);
        for (const text of texts) {
            expect(text).toMatch(/^\$t:(sirsoft-marketing|common|admin)\./);
        }
    });

    // @scenario layout=detail
    // @effects extension_layout_contract_holds
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

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('layout_name이 plugin_settings이다', () => {
        expect(layout.layout_name).toBe('plugin_settings');
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('permissions는 보기 권한(core.plugins.read)이다 — 수정 권한이 없으면 읽기 전용으로 연다(#434 W18)', () => {
        expect(layout.permissions).toEqual(['core.plugins.read']);
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('settings data_source가 정의되어 있다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'settings');
        expect(ds).toBeDefined();
        expect(ds.auth_required).toBe(true);
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('settings data_source onSuccess에서 form.channels를 local 상태에 저장한다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'settings');
        // `onSuccess` 는 콜백 배열이고 그 안의 제어 핸들러가 하위 액션을 담는다 —
        // 한 홉씩 짚으면 접힘·중첩 형태마다 깨지므로 이 파일의 트리 순회 헬퍼를 쓴다.
        const actions = findActions(ds?.onSuccess, 'setState');
        const localSetState = actions.find((a: any) =>
            a.params?.target === 'local' && a.params?.['form.channels'] !== undefined
        );
        expect(localSetState).toBeDefined();
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('settings data_source onSuccess에서 channels를 global 상태에 저장한다', () => {
        const ds = layout.data_sources?.find((d: any) => d.id === 'settings');
        // `onSuccess` 는 콜백 배열이고 그 안의 제어 핸들러가 하위 액션을 담는다 —
        // 한 홉씩 짚으면 접힘·중첩 형태마다 깨지므로 이 파일의 트리 순회 헬퍼를 쓴다.
        const actions = findActions(ds?.onSuccess, 'setState');
        const globalSetState = actions.find((a: any) =>
            a.params?.target === 'global' && a.params?.channels !== undefined
        );
        expect(globalSetState).toBeDefined();
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
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

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('channel_edit_modal이 closeOnBackdropClick: false를 사용한다', () => {
        const modals = layout.modals;
        const editModal = typeof modals === 'object' && !Array.isArray(modals)
            ? modals.channel_edit_modal
            : (modals as any[]).find((m: any) => m.id === 'channel_edit_modal');
        expect(editModal?.props?.closeOnBackdropClick).toBe(false);
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('채널 PUT API 저장 액션이 있다', () => {
        const apiCalls = findActions(layout, 'apiCall');
        const putChannels = apiCalls.find((a: any) =>
            a.target?.includes('channels') && a.params?.method === 'PUT'
        );
        expect(putChannels).toBeDefined();
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('채널 삭제 버튼이 apiCall 없이 setState로만 채널을 제거한다', () => {
        // 삭제 버튼: trash 아이콘을 가진 Button의 actions
        const deleteButtons = findAllRecursive(layout, (n: any) =>
            n.name === 'Button' &&
            n.if === '{{!channel.is_system}}' &&
            JSON.stringify(asArray(n?.children)).includes('trash')
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

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('채널 삭제 버튼이 hasChanges를 true로 설정한다', () => {
        const deleteButtons = findAllRecursive(layout, (n: any) =>
            n.name === 'Button' &&
            n.if === '{{!channel.is_system}}' &&
            JSON.stringify(asArray(n?.children)).includes('trash')
        );
        const deleteBtn = deleteButtons[0];
        const clickAction = deleteBtn.actions?.find((a: any) => a.type === 'click');

        const setStates = findActions(clickAction, 'setState');
        const hasChangesSet = setStates.find((a: any) =>
            a.params?.hasChanges === true && a.params?.target === 'local'
        );
        expect(hasChangesSet).toBeDefined();
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
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

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
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

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
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

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('모든 Button에 type 속성이 명시되어 있다', () => {
        const buttons = findAllRecursive(layout, (n) => n.name === 'Button');
        for (const btn of buttons) {
            expect(btn.props?.type, `Button(id=${btn.id}) type 누락`).toBeDefined();
        }
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('다국어 키가 sirsoft-marketing 또는 admin 네임스페이스를 사용한다', () => {
        const texts = collectTexts(layout);
        for (const text of texts) {
            expect(text).toMatch(/^\$t:(sirsoft-marketing|common|admin)\./);
        }
    });

    // @scenario layout=plugin_settings
    // @effects extension_layout_contract_holds
    it('다크모드 클래스가 bg-white 단독 사용 없이 사용된다', () => {
        const classNames = collectClassNames(layout);
        for (const cls of classNames) {
            if (cls.includes('bg-white') && !cls.includes('bg-white/')) {
                expect(cls).toContain('dark:bg-');
            }
        }
    });
});
