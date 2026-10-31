// e2e:allow 실제 굽기 산출(JSON)의 이탈 가드 선언·우회 자리를 실물 엔진(LeaveGuard·Router·ActionDispatcher)에 태워 잰다 — 브라우저 축(사이드바·뒤로가기·새로고침)은 7.1.1 출시 전 재실측이 덮는다
/**
 * @file leave-guard-unsaved-changes.test.ts
 * @description 플러그인 환경설정 화면 — 저장하지 않은 변경이 있을 때 다른 화면으로 떠나면 확인한다 (이탈 가드, 7.1.1 출시 전)
 *
 * 편집 중에 사이드바·링크·뒤로가기·새로고침으로 다른 화면으로 가도 아무 확인 없이 입력이 사라졌다(엔진에 이탈 확인 장치가
 * 없었다). 엔진 engine-v1.82.0 이 레이아웃 `meta.leave_guard` 를 떠나는 순간 읽어 확인한다. 이 시험은 실제 굽기 산출(JSON)을
 * 실물 엔진에 태워 다음을 잰다.
 *   ① 선언 — 화면마다 정한 변경 조건이 `meta.leave_guard` 에 있다
 *   ② 변경이 있으면 다른 화면으로 가는 이동이 확인을 띄우고, 거절하면 주소가 그대로다 · 새로고침·닫기(beforeunload)도 막는다
 *   ③ 변경이 없거나 저장 성공 처리(변경 표시 끔) 뒤면 묻지 않는다
 *   ④ 같은 화면 안 이동(쿼리만 바뀜 — 탭·필터·항목 선택)은 묻지 않는다
 *   ⑤ 취소 단추·저장 성공 뒤 이동만 우회하고(묻지 않고 떠남), 그 밖의 이동(목록·다른 화면 링크)은 우회하지 않는다
 * 확장마다 독립 패키지라 판정 함수를 공용 파일에서 가져오지 않고 이 파일에 둔다.
 *
 * @scenario-manifest tests/scenarios/leave-guard-unsaved-changes.yaml
 * @vitest-environment jsdom
 */

import fs from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { configureLeaveGuard, endLeaveApproval, shouldConfirmLeave } from '../../../../../../../resources/js/core/routing/LeaveGuard';
import { Router } from '../../../../../../../resources/js/core/routing/Router';
import { ActionDispatcher } from '../../../../../../../resources/js/core/template-engine/ActionDispatcher';
import { DataBindingEngine } from '../../../../../../../resources/js/core/template-engine/DataBindingEngine';

/** 굽기 산출 레이아웃 디렉터리 */
const LAYOUTS = path.resolve(__dirname, '../../../layouts/admin');

type Node = Record<string, any>;
type Seed = { _local?: Record<string, any>; _global?: Record<string, any> };
type Move = { handler: string; owner: string | null; inSuccess: boolean; action: Node };
type Case = {
    label: string;
    file: string;
    guard: string;
    from: string;
    dirty: Seed;
    clean: Seed[];
    /** 우회해야 하는 이동의 소유 노드 — `id`(또는 `href:주소`), 저장 성공 안이면 `success:id` */
    bypass: string[];
    /** 자동바인딩 변경 추적(data-track-changes)을 켜야 하는 폼 감싸개 id */
    trackChanges?: string;
};

const CASES: Case[] = [
    {
        "label": "플러그인 환경설정",
        "file": "plugin_settings.json",
        "guard": "{{_local.hasChanges === true}}",
        "from": "/admin/plugins/sirsoft-daum_postcode/settings",
        "dirty": {
            "_local": {
                "hasChanges": true
            }
        },
        "clean": [
            {
                "_local": {
                    "hasChanges": false
                }
            },
            {
                "_local": {}
            }
        ],
        "bypass": [
            "cancel_button"
        ]
    }
];

/** 파셜 참조를 실제 조각으로 풀어 한 화면 트리로 만든다 */
function resolvePartials(node: unknown): unknown {
    if (Array.isArray(node)) return node.map(resolvePartials);
    if (!node || typeof node !== 'object') return node;
    const n = node as Node;
    if (typeof n.partial === 'string') {
        const file = path.join(LAYOUTS, n.partial);

        return fs.existsSync(file) ? resolvePartials(JSON.parse(fs.readFileSync(file, 'utf8'))) : { __missingPartial: n.partial };
    }
    const out: Node = {};
    for (const [k, v] of Object.entries(n)) out[k] = resolvePartials(v);

    return out;
}

function loadLayout(file: string): Node {
    return resolvePartials(JSON.parse(fs.readFileSync(path.join(LAYOUTS, file), 'utf8'))) as Node;
}

/** 다른 화면으로 가는 이동(쿼리만 바꾸는 replace 제외)을 소유 노드·저장 성공 안 여부와 함께 모은다 */
function collectMoves(node: unknown, owner: string | null = null, inSuccess = false, acc: Move[] = []): Move[] {
    if (Array.isArray(node)) {
        node.forEach((child) => collectMoves(child, owner, inSuccess, acc));

        return acc;
    }
    if (!node || typeof node !== 'object') return acc;
    const n = node as Node;
    let own = owner;
    if (typeof n.name === 'string' && n.type) own = n.id ?? (n.props?.href ? `href:${n.props.href}` : owner);
    if (typeof n.handler === 'string') {
        const p = n.params ?? {};
        const isMove = (n.handler === 'navigate' && p.replace !== true) || n.handler === 'navigateBack' || (n.handler === 'openWindow' && p.target === '_self');
        if (isMove) acc.push({ handler: n.handler, owner: own, inSuccess, action: n });
    }
    for (const [k, v] of Object.entries(n)) collectMoves(v, own, inSuccess || k === 'onSuccess', acc);

    return acc;
}

function findById(node: unknown, id: string): Node | null {
    if (Array.isArray(node)) {
        for (const child of node) {
            const hit = findById(child, id);
            if (hit) return hit;
        }

        return null;
    }
    if (!node || typeof node !== 'object') return null;
    if ((node as Node).id === id) return node as Node;
    for (const v of Object.values(node as Node)) {
        const hit = findById(v, id);
        if (hit) return hit;
    }

    return null;
}

/** 운영과 같은 모양의 상태 창구 — G7Core.state.get() 은 곧 _global, getLocal() 은 페이지 _local */
function seed(state: Seed): void {
    const g = { ...(state._global ?? {}) };
    const l = { ...(state._local ?? {}) };
    (window as any).G7Core = { ...((window as any).G7Core ?? {}), state: { get: () => ({ ...g, _local: l }), getLocal: () => l } };
}

/** 화면을 세운다 — 라우터를 만들면 엔진이 beforeunload 확인 리스너를 한 번 건다 */
function useLayout(layout: Node, from: string): Router {
    window.history.replaceState({}, '', from);
    configureLeaveGuard({
        getLayoutJson: () => layout,
        getDataContext: () => ({ route: {}, query: {} }),
        getBindingEngine: () => new DataBindingEngine(),
        translate: (key: string) => key,
    });

    return new Router('test-template');
}

describe('플러그인 환경설정 화면 — 저장하지 않은 변경 이탈 확인', () => {
    let confirmSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        endLeaveApproval();
        confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    });

    afterEach(() => {
        confirmSpy.mockRestore();
        configureLeaveGuard(null);
        endLeaveApproval();
        window.history.replaceState({}, '', '/');
    });

    // @scenario surface=edit_screen
    // @effects layout_declares_leave_guard
    it.each(CASES)('$label — meta.leave_guard 선언', ({ file, guard, trackChanges }) => {
        const layout = loadLayout(file);
        expect(layout.meta?.leave_guard).toBe(guard);
        if (trackChanges) {
            // 변경 추적이 없으면 조건(hasChanges)이 영영 거짓이라 가드가 무력하다
            expect(findById(layout, trackChanges)?.trackChanges, `${trackChanges} 의 data-track-changes`).toBe(true);
        }
    });

    // @scenario surface=edit_screen
    // @effects dirty_leave_asks_and_stays_on_decline
    it.each(CASES)('$label — 변경이 있으면 다른 화면으로 갈 때 묻고, 거절하면 머문다', ({ file, from, dirty }) => {
        const router = useLayout(loadLayout(file), from);
        seed(dirty);

        router.navigate('/g54-elsewhere');

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(window.location.pathname).toBe(from);

        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented, '새로고침·닫기 확인').toBe(true);
    });

    // @scenario surface=edit_screen
    // @effects clean_or_saved_leave_does_not_ask
    it.each(CASES)('$label — 변경이 없거나 저장 처리 뒤면 묻지 않는다', ({ file, from, clean }) => {
        useLayout(loadLayout(file), from);
        for (const state of clean) {
            seed(state);
            expect(shouldConfirmLeave('/g54-elsewhere'), JSON.stringify(state)).toBe(false);
            const unload = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(unload);
            expect(unload.defaultPrevented).toBe(false);
        }
    });

    // @scenario surface=edit_screen
    // @effects same_screen_move_does_not_ask
    it.each(CASES)('$label — 같은 화면 안 이동(쿼리만 바뀜)은 묻지 않는다', ({ file, from, dirty }) => {
        useLayout(loadLayout(file), from);
        seed(dirty);
        expect(shouldConfirmLeave(`${from}?tab=g54&id=2`)).toBe(false);
        expect(shouldConfirmLeave('/g54-elsewhere')).toBe(true);
    });

    // @scenario surface=edit_screen
    // @effects only_cancel_and_saved_moves_bypass
    it.each(CASES)('$label — 취소·저장 성공 뒤 이동만 우회한다', ({ file, bypass }) => {
        const moves = collectMoves(loadLayout(file));
        const expected = new Set(bypass);
        const seen = new Set<string>();
        for (const m of moves) {
            const key = m.inSuccess ? `success:${m.owner}` : String(m.owner);
            const should = expected.has(key);
            if (should) seen.add(key);
            expect(m.action.params?.bypassLeaveGuard === true, `${m.handler} @ ${key}`).toBe(should);
        }
        expect([...seen].sort(), '우회 자리 누락').toEqual([...expected].sort());
    });

    // @scenario surface=edit_screen
    // @effects bypass_move_leaves_without_asking
    it.each(CASES.filter((c) => c.bypass.length > 0))('$label — 우회 이동은 변경이 있어도 묻지 않고 떠난다', async ({ file, from, dirty }) => {
        const layout = loadLayout(file);
        const moves = collectMoves(layout).filter((m) => m.handler === 'navigate' && m.action.params?.bypassLeaveGuard === true);
        expect(moves.length).toBeGreaterThan(0);
        useLayout(layout, from);
        seed(dirty);
        for (const m of moves) {
            window.history.replaceState({}, '', from);
            endLeaveApproval();
            const router = new Router('test-template');
            const dispatcher = new ActionDispatcher({ navigate: (p: string) => router.navigate(p) });
            const action = { ...m.action, type: 'click', params: { ...m.action.params, path: '/g54-elsewhere', query: undefined, mergeQuery: false } };
            await dispatcher.dispatchAction(action as never, { navigate: (p: string) => router.navigate(p), state: {}, data: { route: {}, query: {} }, setState: () => {} } as never);
            expect(confirmSpy, `우회 ${m.owner}`).not.toHaveBeenCalled();
            expect(window.location.pathname).toBe('/g54-elsewhere');
        }
    });
});
