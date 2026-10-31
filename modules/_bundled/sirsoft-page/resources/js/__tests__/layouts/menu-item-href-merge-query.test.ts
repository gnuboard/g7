/**
 * 페이지 레이아웃 — 행 메뉴·카드 메뉴 항목 href 의 목록 조건 잠금 시험 (7.1.1 출시 전 점검)
 *
 * @description
 * 행 메뉴(DataGrid rowActions)·카드 메뉴(ActionMenu items)의 보통 클릭은 `onRowAction`/`onItemClick` 의 navigate 로 가고,
 * 가운데 클릭·Ctrl+클릭·새 탭은 항목 `href` 로 간다. 보통 클릭이 `mergeQuery: true`(지금 목록의 쪽·검색 조건을 싣는다)인데
 * 항목에 `mergeQuery` 가 없으면 새 탭만 조건 없는 주소로 열린다(관리자 템플릿은 항목의 `mergeQuery` 를 href 에 반영한다).
 * 규칙: 같은 노드에서 보통 클릭 갈래가 mergeQuery 로 가는 항목은 항목에도 `mergeQuery: true` 가 있다. 굽기 산출물(JSON) 전수.
 *
 * @scenario-manifest tests/scenarios/menu-item-href-merge-query.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/** 확장 루트 */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
/** 모집단 디렉터리(확장 루트 기준) */
const ROOTS = ['resources/layouts', 'resources/extensions'];
/** 항목 목록 prop → 보통 클릭 이벤트 */
const MENU_PROPS: Record<string, string> = { rowActions: 'onRowAction', items: 'onItemClick' };

/**
 * 디렉터리 아래 .json 을 모두 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns .json 절대경로 목록
 */
function collectJson(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return collectJson(full);
        return entry.name.endsWith('.json') ? [full] : [];
    });
}

/**
 * 동작 안쪽 navigate 중 mergeQuery 가 참인 것이 있는가(sequence·조건 갈래 포함).
 *
 * @param a 동작
 * @returns 병합 이동 여부
 */
function mergesQuery(a: unknown): boolean {
    if (Array.isArray(a)) return a.some(mergesQuery);
    if (!a || typeof a !== 'object') return false;
    const o = a as Record<string, any>;
    if (o.handler === 'navigate' && o.params?.mergeQuery === true) return true;
    return Object.values(o).some(mergesQuery);
}

/**
 * 레이아웃 하나에서 보통 클릭과 href 의 병합이 어긋난 항목을 찾는다.
 *
 * @param json 레이아웃 JSON
 * @param rel 보고용 경로
 * @returns 판정한 항목 수와 위반 목록
 */
function judge(json: unknown, rel: string): { checked: number; missing: string[] } {
    let checked = 0;
    const missing: string[] = [];
    (function visit(x: unknown) {
        if (Array.isArray(x)) return x.forEach(visit);
        if (!x || typeof x !== 'object') return;
        const node = x as Record<string, any>;
        for (const [prop, event] of Object.entries(MENU_PROPS)) {
            const items = node.props?.[prop];
            if (!Array.isArray(items)) continue;
            const handler = (node.actions ?? []).find((a: any) => a?.event === event);
            const cases = handler?.cases ?? {};
            for (const item of items) {
                if (!item || typeof item.href !== 'string' || !item.id || !cases[item.id]) continue;
                if (!mergesQuery(cases[item.id])) continue;
                checked++;
                if (item.mergeQuery !== true) missing.push(`${rel}: ${prop}#${item.id} ${item.href}`);
            }
        }
        Object.values(node).forEach(visit);
    })(json);
    return { checked, missing };
}

describe('행·카드 메뉴 항목 href 의 목록 조건', () => {
    const results = ROOTS.flatMap((r) => collectJson(path.join(ROOT, r))).map((f) =>
        judge(JSON.parse(fs.readFileSync(f, 'utf8')), path.relative(ROOT, f).split(path.sep).join('/')),
    );

    /**
     * @scenario surface=row_actions
     */
    it('모집단(보통 클릭이 목록 조건을 싣는 href 항목)이 비어 있지 않다', () => {
        expect(results.reduce((n, r) => n + r.checked, 0)).toBeGreaterThan(0);
    });

    /**
     * @scenario surface=row_actions
     * @effects menu_href_keeps_list_query
     */
    it('보통 클릭이 목록 조건을 싣는 항목은 href 에도 mergeQuery 가 있다', () => {
        expect(results.flatMap((r) => r.missing)).toEqual([]);
    });
});
