// e2e:allow 클래스 이름 교정의 구조 검증 — 같은 화면은 Playwright 브라우저로 칸 배치·글자색을 재실측해 이력(20261007_1905_710_KLM_M1)에 남김
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

    it('섹션 제목은 섹션 제목 클래스를 쓰고 오류 강조색을 쓰지 않는다', () => {
        const headings = nodes.filter(
            (n) => n.name === 'H3' && typeof n.text === 'string' && n.text.startsWith('$t:sirsoft-ckeditor5.settings.section_'),
        );
        expect(headings.length).toBeGreaterThanOrEqual(4);
        for (const heading of headings) {
            expect({ text: heading.text, className: heading.props?.className }).toEqual({
                text: heading.text,
                className: 'section-heading-md',
            });
        }
    });

    it('저장 오류 상자는 제목에 강한 오류색, 목록 줄에 옅은 오류색을 쓴다', () => {
        const title = nodes.find((n) => n.text === '$t:admin.plugins.settings.validation_error');
        expect(title?.props?.className).toBe('text-error-strong');

        const item = nodes.find((n) => n.name === 'Li' && n.text === '{{message}}');
        expect(item?.props?.className).toBe('text-error-soft');
    });

    it('3열 그리드 클래스는 입력 칸 줄에만 쓴다', () => {
        const misplaced = nodes.filter(
            (n) => n.props?.className === 'grid-3col-responsive' && !(typeof n.id === 'string' && n.id.startsWith('field_')),
        );
        expect(misplaced.map((n) => n.name ?? n.id)).toEqual([]);
    });
});
