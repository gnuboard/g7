// e2e:allow 실제 ActionDispatcher 로 성공 갈래 판정 단언 — 브라우저 축은 리허설 A 재실측(관리자 템플릿 활성화 뒤 새로 고침)이 덮는다.
/**
 * @file admin-template-activate-success-refresh.test.ts
 * @description 템플릿 활성화 성공 갈래 — 관리자 템플릿을 켰을 때만 새로 고친다 (창 수정의 후속)
 *
 * 수정으로 성공 갈래 첫머리에서 `selectedTemplate` 을 곧바로 비운 뒤, 새로 고침 판정이 창 상태(`_global`)를 읽어
 * 판정 시점에 따라 비워진 값을 보았다 — 리허설 A 실측에서 관리자 템플릿을 켜도 새로 고침이 한 번도 일어나지 않았다
 * (1차 재수정은 판정 문맥을 「클릭 때 사본」 으로 가정한 시험만 통과했다). 판정과 「언어팩 창 닫힌 뒤 새로 고침」 값을
 * 상태가 아니라 활성화 응답(`response.data.template.type`, TemplateResource)으로 가른다. 이 시험은 문맥 `_global` 을
 * 판정 시점의 저장소 값으로 두어 실측과 같은 조건에서 잰다.
 *
 * 레이아웃의 실제 성공 갈래 동작을 엔진(`ActionDispatcher`)에 넣어 차례로 돌린다.
 *
 * @scenario-manifest tests/scenarios/admin-template-activate-success-refresh.yaml
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import activateModal from '../../layouts/partials/admin_template_list/_modal_activate.json';

type Json = Record<string, any>;

/**
 * 템플릿 활성화 api-call 의 성공 갈래를 찾는다.
 *
 * @param x 트리
 * @returns 성공 갈래 동작 목록
 */
function successActions(x: unknown): Json[] {
    if (Array.isArray(x)) {
        for (const c of x) {
            const found = successActions(c);
            if (found.length) return found;
        }

        return [];
    }
    if (x && typeof x === 'object') {
        const n = x as Json;
        if (n.handler === 'apiCall' && String(n.target).includes('/templates/activate')) return n.onSuccess as Json[];
        for (const v of Object.values(n)) {
            const found = successActions(v);
            if (found.length) return found;
        }
    }

    return [];
}

describe('템플릿 활성화 성공 갈래 — 새로 고침 판정', () => {
    let store: Json;
    let dispatcher: ActionDispatcher;
    let refreshed: number;
    let reloadedExtensions: number;

    beforeEach(() => {
        refreshed = 0;
        reloadedExtensions = 0;
        (window as any).G7Core = { state: { get: () => store, getLocal: () => ({}) } };
        dispatcher = new ActionDispatcher({ navigate: vi.fn() });
        dispatcher.setGlobalStateUpdater((updates: Json) => {
            const { __setStateId, __mergeMode, ...rest } = updates;
            store = { ...store, ...rest };
        });
        for (const name of ['closeModal', 'toast', 'refetchDataSource', 'openModal']) {
            dispatcher.registerHandler(name, async () => undefined);
        }
        // 확장 다시 싣기 — 관리자 템플릿을 켜면 곧 새로 고치므로 건너뛴다(방금 꺼진 관리자 템플릿 파일을 다시 받으면 404)
        dispatcher.registerHandler('reloadExtensions', async () => {
            reloadedExtensions += 1;
        });
        dispatcher.registerHandler('refresh', async () => {
            refreshed += 1;
        });
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * 성공 갈래를 차례로 돌린다 — 문맥의 `_global` 은 판정 시점의 저장소 값이다. 리허설 A 실측에서 분기 판정이 앞 단계가
     * 비운 `selectedTemplate` 을 null 로 보아 새로 고침이 한 번도 일어나지 않았다 — 클릭 때 사본으로 두면 그 결함을 놓친다.
     * 응답은 실제 활성화 응답 모양(`data.template` = TemplateResource, `data.pending_language_packs`)이다.
     *
     * @param type 켜는 템플릿 종류
     * @param previous 앞서 켠 템플릿 종류(저장소에 남은 옛 값)
     * @return void 없음
     */
    async function activate(type: 'admin' | 'user', previous: string | null = null): Promise<void> {
        store = { selectedTemplate: { identifier: 't', type, name: 'T' }, isTemplateActivating: true, activatedTemplateType: previous };
        const data = { response: { data: { template: { identifier: 't', type }, pending_language_packs: [] } }, current_user: { data: { permissions: [] } } };
        Object.defineProperty(data, '_global', { get: () => store, enumerable: true });
        const context = { data };
        for (const action of successActions(activateModal)) {
            await dispatcher.dispatchAction(action as any, context as any);
        }
    }

    /**
     * @scenario template=admin
     * @effects admin_template_activation_refreshes_once, admin_template_activation_skips_extension_reload
     * @return void 처음 관리자 템플릿을 켜면 한 번 새로 고치고 창 상태가 비면 통과
     */
    it('처음 관리자 템플릿을 켜면 새로 고친다', async () => {
        await activate('admin');
        expect(refreshed).toBe(1);
        expect(reloadedExtensions).toBe(0);
        expect(store.selectedTemplate).toBeNull();
        expect(store.reactivateLanguagePacksRefreshAfter).toBe(true);
    });

    /**
     * @scenario template=user
     * @effects user_template_activation_does_not_refresh
     * @return void 관리자 → 사용자 순으로 켜도 사용자 템플릿에서는 새로 고치지 않으면 통과
     */
    it('관리자 템플릿을 켠 뒤 사용자 템플릿을 켜면 새로 고치지 않는다', async () => {
        await activate('user', 'admin');
        expect(refreshed).toBe(0);
        expect(reloadedExtensions).toBe(1);
        expect(store.reactivateLanguagePacksRefreshAfter).toBe(false);
    });
});
