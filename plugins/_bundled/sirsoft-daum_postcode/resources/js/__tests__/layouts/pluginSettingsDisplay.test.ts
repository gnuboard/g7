// e2e:allow 선택지 문구·클래스 이름 교정의 구조 검증 — 같은 화면은 Playwright 브라우저로 ko/en 선택지와 칸 배치를 재실측해 작업 이력에 남김
/**
 * @file pluginSettingsDisplay.test.ts
 * @description 우편번호 환경설정 화면의 표시 방식 선택지 문구와 의미 클래스 위치
 *
 * 회귀 배경:
 * - 표시 방식 선택지가 번역 키(`fields.display_mode.popup`·`layer`)를 두고도 값 원문
 *   `popup`·`layer` 를 그대로 보여 주었다.
 * - 관리자 템플릿 의미 클래스 도입(#408) 때 치환이 한 칸 밀려, 입력 칸 줄에 오류 글자색
 *   클래스(`text-error-soft`)가, 오류 목록 줄에 3열 그리드 클래스(`grid-3col-responsive`)가 붙었다.
 */

import { describe, it, expect } from 'vitest';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';

type LayoutNode = {
    id?: string;
    name?: string;
    text?: string;
    props?: { className?: string; value?: string };
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

/**
 * `$t:` 키를 언어 파일에서 찾는다(플러그인 접두 `sirsoft-daum_postcode.` 제거).
 *
 * @param dict 언어 파일
 * @param text 레이아웃 text 값
 * @returns 번역 문자열(없으면 undefined)
 */
function lookup(dict: unknown, text: string): string | undefined {
    const key = text.replace(/^\$t:sirsoft-daum_postcode\./, '');
    return key.split('.').reduce<unknown>(
        (acc, part) => (acc && typeof acc === 'object' ? (acc as Record<string, unknown>)[part] : undefined),
        dict,
    ) as string | undefined;
}

const nodes = flatten(pluginSettingsLayout);

describe('우편번호 환경설정 — 표시 방식 선택지', () => {
    const options = nodes.filter((n) => n.name === 'Option');

    // @scenario target=display_mode_options
    // @effects postcode_display_mode_options_use_translation_keys
    it('선택지 2개가 popup·layer 값을 가진다', () => {
        expect(options.map((o) => o.props?.value).sort()).toEqual(['layer', 'popup']);
    });

    // @scenario target=display_mode_options
    // @effects postcode_display_mode_options_use_translation_keys
    it('선택지 문구는 값 원문이 아니라 번역 키이고, ko/en 번역이 있다', () => {
        for (const option of options) {
            expect(option.text).toBe(`$t:sirsoft-daum_postcode.settings.fields.display_mode.${option.props?.value}`);
            expect(lookup(ko, option.text as string)).toBeTruthy();
            expect(lookup(en, option.text as string)).toBeTruthy();
        }
        expect(lookup(ko, '$t:sirsoft-daum_postcode.settings.fields.display_mode.popup')).toBe('팝업');
    });
});

describe('우편번호 환경설정 — 의미 클래스 위치', () => {
    // @scenario target=field_rows
    // @effects postcode_settings_semantic_classes_on_their_rows
    it('입력 칸 줄(field_*)은 3열 그리드 클래스를 쓴다', () => {
        const fields = nodes.filter((n) => typeof n.id === 'string' && n.id.startsWith('field_'));
        expect(fields.length).toBeGreaterThanOrEqual(4);
        for (const field of fields) {
            expect({ id: field.id, className: field.props?.className }).toEqual({
                id: field.id,
                className: 'grid-3col-responsive',
            });
        }
    });

    // @scenario target=error_rows
    // @effects postcode_settings_semantic_classes_on_their_rows
    it('저장 오류 목록 줄은 옅은 오류색을 쓰고, 3열 그리드는 입력 칸 줄에만 쓴다', () => {
        const item = nodes.find((n) => n.name === 'Li' && n.text === '{{message}}');
        expect(item?.props?.className).toBe('text-error-soft');

        const misplaced = nodes.filter(
            (n) => n.props?.className === 'grid-3col-responsive' && !(typeof n.id === 'string' && n.id.startsWith('field_')),
        );
        expect(misplaced.map((n) => n.name ?? n.id)).toEqual([]);
    });
});
