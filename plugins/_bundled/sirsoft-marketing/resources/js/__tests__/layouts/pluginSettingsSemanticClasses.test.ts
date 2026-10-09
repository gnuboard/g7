// e2e:allow 클래스 이름 교정의 구조 검증 — 같은 화면은 Playwright 브라우저로 1440·390 칸 배치·제목 색·오류 상자 색을 재실측해 이력(20261008_HHMM_710_KLM_M2r)에 남김
/**
 * @file pluginSettingsSemanticClasses.test.ts
 * @description 마케팅 동의 환경설정 화면의 의미 클래스가 제자리에 붙어 있는지
 *
 * 회귀 배경: 관리자 템플릿 의미 클래스 도입(#408) 때 치환이 한 칸씩 밀렸다. 섹션 제목(「마케팅 채널」「법적 동의 항목」)에
 * 오류 강조 클래스(`text-error-strong`)가 붙어 붉은 14px 로 보였고, 저장 오류 상자의 제목에 옅은 오류 클래스,
 * 오류 목록 줄에 섹션 제목 클래스(`section-heading-md`)가 붙어 있었다.
 * 원래 매핑: 입력 줄 = `grid-3col-responsive`, 섹션 제목 = `section-heading-md`, 오류 제목 = `text-error-strong`,
 * 오류 목록 줄 = `text-error-soft` (7.1.0 전수 실측 #408 클래스 밀림 후속).
 *
 * @scenario-manifest tests/scenarios/plugin-settings-semantic-classes.yaml
 */

import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

type LayoutNode = {
    id?: string;
    name?: string;
    text?: string;
    props?: { className?: string };
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

const nodes = flatten(pluginSettingsLayout);
const cls = (n: LayoutNode) => (typeof n.props?.className === 'string' ? n.props.className : '');
const HEADINGS = ['H2', 'H3', 'H4'];

describe('마케팅 동의 환경설정 — 의미 클래스 위치', () => {
    /**
     * @scenario plugin=sirsoft-marketing, element=field_row
     *
     * @effects settings_rows_use_three_column_grid
     */
    it('라벨(2칸)·입력칸(1칸)으로 나뉜 약관 슬러그 입력 줄은 3열 그리드 클래스를 쓴다', () => {
        const rows = nodes.filter((n) => {
            const kids = Array.isArray(n.children) ? n.children : [];
            const hasSpans = kids.some((k) => cls(k).includes('lg:col-span-1')) && kids.some((k) => cls(k).includes('lg:col-span-2'));
            return hasSpans && flatten(kids).some((k) => k.name === 'Input');
        });
        expect(rows.length).toBeGreaterThanOrEqual(3);
        for (const row of rows) {
            expect({ id: row.id, className: cls(row).split(' ')[0] }).toEqual({ id: row.id, className: 'grid-3col-responsive' });
        }
    });

    /**
     * @scenario plugin=sirsoft-marketing, element=section_title
     *
     * @effects section_titles_use_heading_class
     */
    it('섹션 제목(채널·법적 동의 항목)은 섹션 제목 클래스를 쓰고 오류 강조색을 쓰지 않는다', () => {
        const headings = nodes.filter(
            (n) => n.name === 'H3' && typeof n.text === 'string' && /\.settings\.section\./.test(n.text),
        );
        expect(headings.length).toBe(2);
        for (const heading of headings) {
            expect({ text: heading.text, className: cls(heading) }).toEqual({ text: heading.text, className: 'section-heading-md' });
        }
    });

    /**
     * @scenario plugin=sirsoft-marketing, element=error_box
     *
     * @effects error_box_uses_error_classes
     */
    it('저장 오류 상자는 제목에 강한 오류색, 목록 줄에 옅은 오류색을 쓴다', () => {
        const title = nodes.find((n) => typeof n.text === 'string' && n.text.endsWith('.validation_error'));
        expect(cls(title ?? {})).toBe('text-error-strong');

        const item = nodes.find((n) => n.name === 'Li' && n.text === '{{message}}');
        expect(cls(item ?? {})).toBe('text-error-soft');
    });

    /**
     * @scenario plugin=sirsoft-marketing, element=misplaced
     *
     * @effects semantic_classes_not_misplaced
     */
    it('섹션 제목 클래스는 제목에만, 오류 강조색은 오류 문구에만 쓴다', () => {
        const headingMisuse = nodes.filter((n) => cls(n) === 'section-heading-md' && !HEADINGS.includes(n.name ?? ''));
        expect(headingMisuse.map((n) => n.id ?? n.name)).toEqual([]);

        const strongOnSection = nodes.filter(
            (n) => cls(n).split(' ')[0] === 'text-error-strong' && typeof n.text === 'string' && /\.section\./.test(n.text),
        );
        expect(strongOnSection.map((n) => n.text)).toEqual([]);
    });
});
