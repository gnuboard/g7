/**
 * S16 결함 수정 회귀 봉인 (W3 몫 — 계획서 대장 F7·F8·F12·F13·F14·F15)
 *
 * W3 이관에서 **동작 변경을 수반한다는 이유로 제외**됐던 16파일의 결함이다. 각 항목은 이관 게이트가
 * 잡아 주지 못하는 종류거나(F12 는 escape 0 인 채 등록 집합만 바뀐다) 사용자 화면에 드러나는
 * 실버그(F15)라, 수정 후 되살아나지 않도록 레이아웃 JSON 을 직접 단언한다.
 *
 * 여기서 보는 것은 **소스 데이터의 정합성**이지 렌더 결과가 아니다 — 렌더는 각 화면 테스트가 본다.
 */
import { describe, expect, it } from 'vitest';

import panelMenuList from '../../layouts/partials/admin_menu_list/_panel_menu_list.json';
import panelDetail from '../../layouts/partials/admin_menu_list/_panel_detail.json';
import panelForm from '../../layouts/partials/admin_menu_list/_panel_form.json';
import panelView from '../../layouts/partials/admin_menu_list/_panel_view.json';
import scheduleHistory from '../../layouts/partials/admin_schedule_list/_modal_history.json';
import tabSchedules from '../../layouts/partials/admin_schedule_list/_tab_schedules.json';
import tabLanguagePacks from '../../layouts/partials/admin_settings/_tab_language_packs.json';
import languagePackList from '../../layouts/admin_language_pack_list.json';
import adminBase from '../../layouts/_admin_base.json';
import modalVersionHistory from '../../layouts/partials/admin_template_layout_edit/_modal_version_history.json';
import modalExtVersionHistory from '../../layouts/partials/admin_template_layout_edit/_modal_extension_version_history.json';
import modalExtPreviewLayout from '../../layouts/partials/admin_template_layout_edit/_modal_extension_preview_layout.json';
import tabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import tabUser from '../../layouts/partials/admin_template_list/_tab_user.json';

/**
 * 템플릿의 모든 레이아웃 JSON — 파일 목록을 손으로 들지 않는다.
 *
 * 손으로 들면 "그 파일만" 검사하게 되고, 같은 결함이 다른 파일에 생겨도 조용히 지나간다.
 */
const allLayouts = import.meta.glob('../../layouts/**/*.json', { eager: true, import: 'default' }) as Record<
    string,
    any
>;

/** 노드 트리를 깊이 우선 순회한다 (children·slots·props 안의 노드 배열 포함). */
function walk(node: any, visit: (n: any) => void): void {
    if (Array.isArray(node)) {
        node.forEach((child) => walk(child, visit));

        return;
    }

    if (!node || typeof node !== 'object') return;

    visit(node);

    Object.values(node).forEach((value) => walk(value, visit));
}

/** 트리 안의 모든 액션 객체를 모은다 (actions·lifecycle·onSuccess 등 중첩 포함). */
function collectActions(layout: any): any[] {
    const actions: any[] = [];

    walk(layout, (node) => {
        if (typeof node.handler === 'string') actions.push(node);
    });

    return actions;
}

describe('F15 — 미등록 핸들러 `if` (예약 작업 일괄 상태변경 무동작)', () => {
    /** @effects dead_if_handler_is_absent_from_every_action */
    it('`if` 는 액션 핸들러가 아니다 — 어떤 액션도 handler:"if" 를 쓰지 않는다', () => {
        // ActionDispatcher 의 ActionType 유니온에 `if` 가 없다. `if` 는 액션의 **키**(조건)이지
        // 핸들러명이 아니므로, handler:"if" 액션은 dispatch 에서 아무 갈래도 타지 못하고 조용히 끝난다.
        const offenders = collectActions(tabSchedules).filter((a) => a.handler === 'if');

        expect(offenders).toEqual([]);
    });

    /** @effects bulk_status_change_routes_through_conditions */
    it('일괄 상태변경은 conditions 로 분기해 bulk-status 를 호출한다', () => {
        const conditionActions = collectActions(tabSchedules).filter((a) => a.handler === 'conditions');
        const bulkStatus = conditionActions.find((a) =>
            JSON.stringify(a).includes('/api/admin/schedules/bulk-status'),
        );

        expect(bulkStatus).toBeDefined();
        expect(Array.isArray(bulkStatus.conditions)).toBe(true);
        expect(bulkStatus.conditions[0].if).toBe('{{_global.bulkStatusAction}}');
    });
});

describe('F7 — lifecycle 의 죽은 type:"click"', () => {
    /** @effects lifecycle_actions_carry_no_dom_event_type */
    it('생명주기 액션에는 DOM 이벤트 타입이 없다', () => {
        const offenders: any[] = [];

        walk(panelMenuList, (node) => {
            if (!node.lifecycle || typeof node.lifecycle !== 'object') return;

            Object.values(node.lifecycle).forEach((hookActions) => {
                walk(hookActions, (action) => {
                    if (typeof action.handler === 'string' && 'type' in action) offenders.push(action);
                });
            });
        });

        expect(offenders).toEqual([]);
    });
});

describe('F8 — 죽은 키·미등록 컴포넌트', () => {
    /** @effects admin_base_drops_the_dead_data_binding_key */
    it('_admin_base 에 사어 `data_binding` 이 남아 있지 않다', () => {
        const offenders: any[] = [];

        walk(adminBase, (node) => {
            if ('data_binding' in node) offenders.push(node.id ?? node.name);
        });

        expect(offenders).toEqual([]);
    });

    /** @effects execution_history_modal_avoids_unregistered_foreach */
    it('실행 이력 모달이 미등록 컴포넌트 `Foreach` 를 쓰지 않는다', () => {
        const offenders: any[] = [];

        walk(scheduleHistory, (node) => {
            if (node.name === 'Foreach') offenders.push(node);
        });

        expect(offenders).toEqual([]);
    });
});

describe('F13/F14 — partial 참조 키', () => {
    /** @effects partial_reference_node_carries_no_redundant_type_or_name */
    it.each([
        ['admin_language_pack_list', languagePackList],
        ['_tab_language_packs', tabLanguagePacks],
    ])('%s: partial 노드에 잉여 type/name 이 없다', (_name, layout) => {
        const offenders: any[] = [];

        walk(layout, (node) => {
            if (typeof node.partial !== 'string') return;

            const extra = Object.keys(node).filter((k) => k !== 'partial' && k !== 'comment');
            if (extra.length > 0) offenders.push({ partial: node.partial, extra });
        });

        expect(offenders).toEqual([]);
    });

    /** @effects unconsumed_partial_key_is_absent_from_every_layout */
    it('소비되지 않는 `$partial` 키가 어떤 레이아웃에도 남아 있지 않다', () => {
        // `$partial` 은 `resolveAllPartials`(PHP)도 렌더러(TS)도 읽지 않는 키다. 유일한 사용처였던
        // `admin_language_packs_install_modal` 은 라우트·참조가 0이고 `extends` 없는 slots 라 렌더
        // 결과 자체가 없어 설계 결정으로 삭제했다 — 같은 키가 다시 들어오면 여기서 잡는다.
        const offenders: string[] = [];

        Object.entries(allLayouts).forEach(([path, layout]) => {
            walk(layout, (node) => {
                if ('$partial' in node) offenders.push(path);
            });
        });

        expect(offenders).toEqual([]);
    });

    /** @effects deleted_orphan_layouts_stay_deleted */
    it('삭제된 고아 레이아웃이 되살아나지 않는다', () => {
        const revived = Object.keys(allLayouts).filter((p) =>
            p.includes('admin_language_packs_install_modal') || p.includes('overrides/sirsoft-sample'),
        );

        expect(revived).toEqual([]);
    });
});

describe('F12 — bare-node partial 의 meta.is_partial 선언', () => {
    /** @effects bare_node_partial_declares_meta_is_partial */
    it.each([
        ['_panel_detail', panelDetail],
        ['_panel_form', panelForm],
        ['_panel_view', panelView],
        ['_modal_version_history', modalVersionHistory],
        ['_modal_extension_version_history', modalExtVersionHistory],
        ['_modal_extension_preview_layout', modalExtPreviewLayout],
        ['_tab_admin', tabAdmin],
        ['_tab_user', tabUser],
        // 아래 둘은 다른 결함(F7·F15)으로 먼저 제외돼 있어 F12 성격이 가려져 있었다 —
        // 그 결함을 고쳐 이관에 합류시키자 등록 집합 대조가 비로소 잡아냈다.
        ['_panel_menu_list', panelMenuList],
        ['_tab_schedules', tabSchedules],
    ])('%s: 루트가 노드 하나인 partial 은 meta.is_partial 을 선언한다', (_name, layout: any) => {
        // 선언이 없으면 설치가 이 파일을 **독립 레이아웃으로 등록**한다. 이관하면 `<partial>` 컴파일이
        // is_partial 을 주입해 등록에서 빠지므로, 등록 집합이 이관 전후로 달라진다(F12).
        expect(layout.meta?.is_partial).toBe(true);
    });
});
