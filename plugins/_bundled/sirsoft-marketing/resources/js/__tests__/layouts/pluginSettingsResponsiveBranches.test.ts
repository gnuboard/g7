/**
 * @file pluginSettingsResponsiveBranches.test.ts
 * @description 마케팅 동의 환경설정 화면 — 폭 분기 갈래가 넓은 화면 배치를 그대로 갖는지
 *
 * 화면 폭 분기는 엔진 responsive 로 쓴다. 확장 유틸리티는 화면에 실릴 때 층 없이 실려 의미 클래스 칸
 * (`@layer g7-extension`)의 `marketing-grid-2col`·`marketing-hidden` 을 이긴다. 그래서
 * - 동의 사용 줄(라벨 2칸·스위치 1칸)은 1024px 이상 갈래에 `grid-cols-3` 이 있어야 3열로 놓이고,
 * - 채널 표의 슬러그 칸·작업 칸 머리와 행의 슬러그 칸은 640px 이상 갈래에 `block` 이 있어야 보인다.
 * 이 유틸리티가 갈래에서 빠지면 넓은 화면에서 줄이 2열로 접히거나 칸이 사라진다.
 *
 * @scenario-manifest tests/scenarios/plugin-settings-responsive-branches.yaml
 */

import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';
import { applyResponsiveClassMerge } from '@core/template-engine/helpers/StyleHelpers';

type LayoutNode = {
    id?: string;
    name?: string;
    props?: { className?: string };
    responsive?: Record<string, { props?: { className?: string }; classMerge?: string }>;
    children?: LayoutNode[];
};

/**
 * 레이아웃 트리의 모든 노드를 펼친다.
 *
 * @param node 시작 노드(배열·객체 아무거나)
 * @returns 노드 목록
 */
function flatten(node: unknown): LayoutNode[] {
    const out: LayoutNode[] = [];
    const stack: unknown[] = [node];
    while (stack.length) {
        const cur = stack.pop();
        if (Array.isArray(cur)) {
            stack.push(...cur);
            continue;
        }
        if (!cur || typeof cur !== 'object') continue;
        out.push(cur as LayoutNode);
        stack.push(...Object.values(cur as Record<string, unknown>));
    }
    return out;
}

const nodes = flatten(pluginSettingsLayout).filter((n) => typeof n.name === 'string');
const tokens = (c: unknown): string[] => (typeof c === 'string' ? c.split(/\s+/) : []);
const base = (n: LayoutNode | undefined) => tokens(n?.props?.className);
/**
 * 폭 갈래의 실효 클래스 토큰 — 갈래 class(있으면 통째 교체) 위에 갈래 class-merge 를 엔진 규칙으로 덧붙인 값 (engine-v1.98.0).
 * 접두 토큰(`marketing:grid-cols-1`)끼리 충돌 교체가 되도록 서버가 내려 주는 접두 목록을 잠시 세운다.
 */
const branch = (n: LayoutNode | undefined, on: string): string[] => {
    const range = n?.responsive?.[on];
    if (!range) return [];
    const g = globalThis as { window?: { G7Config?: Record<string, unknown> } };
    const created = g.window === undefined;
    if (created) g.window = {};
    const prev = g.window!.G7Config;
    g.window!.G7Config = { ...(prev ?? {}), extensionClassPrefixes: ['marketing'] };
    try {
        return tokens(applyResponsiveClassMerge(range.props?.className ?? n?.props?.className, range.classMerge));
    } finally {
        if (created) delete g.window;
        else g.window!.G7Config = prev;
    }
};

const CONSENT_ROWS = ['field_marketing_consent_enabled', 'field_third_party_consent_enabled', 'field_info_disclosure_enabled'];

describe('마케팅 동의 환경설정 — 폭 분기 갈래', () => {
    /**
     * @scenario plugin=sirsoft-marketing, element=consent_row, width=narrow
     *
     * @effects consent_rows_two_columns_below_1024
     */
    it('동의 사용 줄은 1024px 미만(기본 갈래)에서 의미 클래스 2열 그리드다', () => {
        for (const id of CONSENT_ROWS) {
            const row = nodes.find((n) => n.id === id);
            expect({ id, grid2: base(row).includes('marketing-grid-2col'), cols3: base(row).includes('marketing:grid-cols-3') }).toEqual({
                id,
                grid2: true,
                cols3: false,
            });
        }
    });

    /**
     * @scenario plugin=sirsoft-marketing, element=consent_row, width=wide
     *
     * @effects consent_rows_three_columns_from_1024
     */
    it('동의 사용 줄은 1024px 이상 갈래에서 3열 그리드(라벨 2칸·스위치 1칸)다', () => {
        for (const id of CONSENT_ROWS) {
            const row = nodes.find((n) => n.id === id);
            expect({ id, wide: branch(row, 'desktop').includes('marketing:grid-cols-3') }).toEqual({ id, wide: true });
            const kids = row?.children ?? [];
            expect({ id, spans: kids.map((k) => branch(k, 'desktop').find((t) => t.startsWith('marketing:col-span-'))) }).toEqual({
                id,
                spans: ['marketing:col-span-2', 'marketing:col-span-1'],
            });
        }
    });

    /**
     * @scenario plugin=sirsoft-marketing, element=channel_table, width=narrow
     *
     * @effects channel_slug_columns_hidden_below_640
     */
    it('채널 표의 슬러그 칸·작업 칸 머리와 행 슬러그 칸은 640px 미만에서 숨는다', () => {
        const hiddenCols = nodes.filter((n) => base(n).includes('marketing-hidden'));
        expect(hiddenCols.length).toBe(3);
        for (const n of hiddenCols) {
            expect(base(n)).toContain('marketing:hidden');
        }
    });

    /**
     * @scenario plugin=sirsoft-marketing, element=channel_table, width=wide
     *
     * @effects channel_slug_columns_shown_from_640
     */
    it('같은 칸들은 640px 이상 갈래에서 확장 유틸리티 block 으로 보인다', () => {
        const hiddenCols = nodes.filter((n) => base(n).includes('marketing-hidden'));
        expect(hiddenCols.length).toBe(3);
        for (const n of hiddenCols) {
            expect(branch(n, '640-')).toContain('marketing:block');
        }
    });
});
