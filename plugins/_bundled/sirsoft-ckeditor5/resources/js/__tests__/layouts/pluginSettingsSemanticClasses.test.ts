// e2e:allow 클래스 이름 교정의 구조 검증 — 같은 화면은 Playwright 브라우저로 칸 배치·글자색을 재실측해 작업 이력에 남김
/**
 * @file pluginSettingsSemanticClasses.test.ts
 * @description 플러그인 환경설정 화면의 의미 클래스가 제자리에 붙어 있는지
 *
 * 회귀 배경: 관리자 템플릿 의미 클래스 도입(#408) 때 치환이 한 칸씩 밀려, 입력 칸 줄에
 * 섹션 제목 클래스(`section-heading-md`)가, 섹션 제목에 오류 강조 클래스(`text-error-strong`)가,
 * 오류 목록 줄에 3열 그리드 클래스(`grid-3col-responsive`)가 붙었다. 그 결과 라벨·설명과 입력이
 * 3열로 나란히 놓이지 않고 위아래로 쌓이며, 섹션 제목이 붉은 글자로 보였다.
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

describe('CKEditor5 환경설정 — 의미 클래스 위치', () => {
    /**
     * @scenario region=field_row
     * @effects settings_field_rows_use_3col_grid
     */
    it('입력 칸 줄(field_*)은 3열 그리드 클래스를 쓴다', () => {
        const fields = nodes.filter((n) => typeof n.id === 'string' && n.id.startsWith('field_'));
        expect(fields.length).toBeGreaterThanOrEqual(7);
        for (const field of fields) {
            expect({ id: field.id, className: field.props?.className }).toEqual({
                id: field.id,
                className: 'grid-3col-responsive',
            });
        }
    });

    /**
     * @scenario region=section_heading
     * @effects settings_section_heading_class_without_error_color
     */
    it('섹션 제목은 섹션 제목 클래스를 쓰고 오류 강조색을 쓰지 않는다', () => {
        const isSectionTitle = (n: LayoutNode): boolean =>
            typeof n.text === 'string' && /^\$t:sirsoft-ckeditor5\.settings\.section_[a-z]+$/.test(n.text);
        const headings = nodes.filter((n) => n.name === 'H3' && isSectionTitle(n));
        // 「미리보기」 구역 제목은 접기 단추를 품은 h3 안의 글자(span)다(7.1.1 — 머리 줄 전체가 펼침 단추). 같은 클래스 계약을 진다
        const buttonTitles = nodes
            .filter((n) => n.name === 'H3' && !n.text)
            .flatMap((h) => flatten(h.children).filter((n) => n.name === 'Span' && isSectionTitle(n)));
        expect(headings.length + buttonTitles.length).toBeGreaterThanOrEqual(4);
        for (const heading of headings) {
            expect({ text: heading.text, className: heading.props?.className }).toEqual({
                text: heading.text,
                className: 'section-heading-md',
            });
        }
        for (const title of buttonTitles) {
            expect({ text: title.text, className: title.props?.className }).toEqual({
                text: title.text,
                className: 'block section-heading-md',
            });
        }
    });

    /**
     * @scenario region=error_box
     * @effects settings_error_box_strong_title_soft_items
     */
    it('저장 오류 상자는 제목에 강한 오류색, 목록 줄에 옅은 오류색을 쓴다', () => {
        const title = nodes.find((n) => n.text === '$t:admin.plugins.settings.validation_error');
        expect(title?.props?.className).toBe('text-error-strong');

        const item = nodes.find((n) => n.name === 'Li' && n.text === '{{message}}');
        expect(item?.props?.className).toBe('text-error-soft');
    });

    /**
     * @scenario region=field_row
     * @effects settings_field_rows_use_3col_grid
     */
    it('3열 그리드 클래스는 입력 칸 줄에만 쓴다', () => {
        const misplaced = nodes.filter(
            (n) => n.props?.className === 'grid-3col-responsive' && !(typeof n.id === 'string' && n.id.startsWith('field_')),
        );
        expect(misplaced.map((n) => n.name ?? n.id)).toEqual([]);
    });
});
