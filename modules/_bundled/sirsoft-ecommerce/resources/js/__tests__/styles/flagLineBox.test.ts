/**
 * 국기 상자의 높이
 *
 * 국기(`ecommerce-flag ecommerce-flag-xx`)는 빈 `<span>` 에 배경 그림을 깔고, `::before` 가 넣는 글자 하나로
 * 줄 상자를 만들어 높이(줄 높이 1em)를 얻는다 — flag-icons 의 `fi` 와 같은 방식이다. 그 글자는 접히지 않는
 * 공백(U+00A0)이어야 한다. 일반 공백(U+0020)은 줄 안에서 접혀 줄 상자가 생기지 않아, 가로폭만 있고 높이가 0 인
 * 상자(예: 19×0)가 되어 국기가 하나도 보이지 않는다. 브라우저 배치는 jsdom 이 재지 않으므로 규칙 내용으로 단언한다.
 *
 * 원본 CSS 와 실제로 내려가는 산출 CSS(dist/css/module.css) 를 함께 본다 — 원본만 고치고 다시 굽지 않으면
 * 화면은 여전히 높이 0 이다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');

const ARTIFACTS: Record<'source' | 'dist', string> = {
    source: resolve(MODULE_ROOT, 'resources/css/main.css'),
    dist: resolve(MODULE_ROOT, 'dist/css/module.css'),
};

/**
 * CSS 문자열 리터럴(따옴표 안쪽)을 실제 글자로 풉니다.
 *
 * `\00a0`·`\a0 ` 같은 16진 이스케이프(뒤따르는 공백 하나는 구분자)와 `\"` 같은 글자 이스케이프를 처리합니다.
 *
 * @param raw 따옴표를 뺀 문자열 본문
 * @return 풀린 글자열
 */
function decodeCssString(raw: string): string {
    return raw.replace(/\\([0-9a-fA-F]{1,6})[ \t\n]?|\\([\s\S])/g, (_m, hex: string | undefined, ch: string | undefined) =>
        hex !== undefined ? String.fromCodePoint(parseInt(hex, 16)) : (ch as string),
    );
}

/**
 * 선택자 하나의 선언 본문들을 모읍니다(압축 산출물·여러 줄 원본 모두).
 *
 * @param css CSS 전문
 * @param selectorPattern 선택자 정규식 원문(앞뒤 경계 포함)
 * @return 일치한 규칙들의 선언 본문
 */
function ruleBodies(css: string, selectorPattern: string): string[] {
    const re = new RegExp(`(?:^|[}\\s;])${selectorPattern}\\s*\\{([^}]*)\\}`, 'g');

    return [...css.matchAll(re)].map((m) => m[1]);
}

/**
 * `.ecommerce-flag::before`(또는 `:before`) 규칙의 content 값을 풀어 돌려줍니다.
 *
 * @param css CSS 전문
 * @return 규칙마다 풀린 content 글자열
 */
function flagBeforeContents(css: string): string[] {
    return ruleBodies(css, '\\.ecommerce-flag::?before')
        .map((body) => body.match(/content\s*:\s*(["'])((?:\\[\s\S]|(?!\1)[^\\])*)\1/))
        .filter((m): m is RegExpMatchArray => m !== null)
        .map((m) => decodeCssString(m[2]));
}

/**
 * 산출물 하나의 국기 `::before` 글자가 U+00A0 하나뿐인지 단언합니다.
 *
 * @param artifact 원본 또는 산출 CSS
 */
function expectNonCollapsibleFlagContent(artifact: keyof typeof ARTIFACTS): void {
    const contents = flagBeforeContents(readFileSync(ARTIFACTS[artifact], 'utf-8'));

    expect(contents.length).toBeGreaterThan(0);

    for (const content of contents) {
        expect([...content].map((c) => c.codePointAt(0)!.toString(16))).toEqual(['a0']);
    }
}

/**
 * 산출물 하나의 국기 상자가 인라인 블록·줄 높이 1em 인지 단언합니다.
 *
 * @param artifact 원본 또는 산출 CSS
 */
function expectFlagBoxLineHeight(artifact: keyof typeof ARTIFACTS): void {
    const bodies = ruleBodies(readFileSync(ARTIFACTS[artifact], 'utf-8'), '\\.ecommerce-flag').join(';');

    expect(bodies).toMatch(/display\s*:\s*inline-block/);
    expect(bodies).toMatch(/line-height\s*:\s*1em/);
}

describe('국기 상자의 높이', () => {
    /**
     * @scenario artifact=source
     * @effects flag_pseudo_content_is_non_collapsible_space
     */
    it('원본 CSS — ::before 가 넣는 글자는 접히지 않는 공백(U+00A0) 하나다', () => {
        expectNonCollapsibleFlagContent('source');
    });

    /**
     * @scenario artifact=dist
     * @effects flag_pseudo_content_is_non_collapsible_space
     */
    it('산출 CSS — ::before 가 넣는 글자는 접히지 않는 공백(U+00A0) 하나다', () => {
        expectNonCollapsibleFlagContent('dist');
    });

    /**
     * @scenario artifact=source
     * @effects flag_box_keeps_line_height
     */
    it('원본 CSS — 국기 상자는 인라인 블록·줄 높이 1em 을 유지한다', () => {
        expectFlagBoxLineHeight('source');
    });

    /**
     * @scenario artifact=dist
     * @effects flag_box_keeps_line_height
     */
    it('산출 CSS — 국기 상자는 인라인 블록·줄 높이 1em 을 유지한다', () => {
        expectFlagBoxLineHeight('dist');
    });
});
