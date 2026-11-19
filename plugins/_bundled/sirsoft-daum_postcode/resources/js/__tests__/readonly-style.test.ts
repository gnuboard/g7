/**
 * 읽기 전용 주소 칸의 표시
 *
 * 검색으로만 채우는 우편번호·주소 칸은 직접 입력할 수 없다. 예전에는 표지 클래스만 달려
 * 일반 입력칸과 똑같이 보였다 — 옅은 회색 바탕과 기본 커서로 구분하고, 다크 모드 짝을
 * 중립 표식 규격으로 둔다. 색은 템플릿 덮기 파일이 이길 수 있게 의미 칸(g7-extension)에 둔다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const css = readFileSync(resolve(__dirname, '../../css/main.css'), 'utf-8');

/**
 * `@layer g7-extension { … }` 블록 본문을 돌려줍니다.
 *
 * @return 층 블록 본문(없으면 빈 문자열)
 */
function semanticLayerBody(): string {
    const start = css.indexOf('@layer g7-extension {');

    if (start < 0) {
        return '';
    }

    let depth = 0;

    for (let i = css.indexOf('{', start); i < css.length; i++) {
        if (css[i] === '{') {
            depth++;
        } else if (css[i] === '}' && --depth === 0) {
            return css.slice(start, i + 1);
        }
    }

    return '';
}

describe('읽기 전용 주소 칸 표시', () => {
    /**
     * @scenario scheme=light
     * @effects readonly_field_has_distinct_background
     */
    it('라이트 — 의미 칸에서 바탕색과 기본 커서를 준다', () => {
        const body = semanticLayerBody();
        const rule = body.match(/\.daumpostcode-readonly\s*\{([^}]*)\}/);

        expect(rule).not.toBeNull();
        expect(rule![1]).toMatch(/background-color:\s*#f3f4f6/);
        expect(rule![1]).toMatch(/cursor:\s*default/);
    });

    /**
     * @scenario scheme=dark
     * @effects readonly_field_dark_pair_uses_neutral_marker
     */
    it('다크 — 중립 표식 규격(다크 안의 라이트 제외)으로 바탕색 짝을 둔다', () => {
        const body = semanticLayerBody();
        const dark = '.daumpostcode-readonly:is([data-g7-color-scheme="dark"] *, [data-g7-color-scheme="dark"])'
            + ':not(:where([data-g7-color-scheme="dark"] [data-g7-color-scheme="light"] *, [data-g7-color-scheme="dark"] [data-g7-color-scheme="light"]))';
        const at = body.indexOf(dark);

        expect(at).toBeGreaterThan(-1);
        expect(body.slice(at, body.indexOf('}', at))).toMatch(/background-color:\s*#1f2937/);
    });

    /**
     * @scenario scheme=dark
     * @effects readonly_field_dark_pair_uses_neutral_marker
     */
    it('템플릿 다크 클래스(.dark)를 전제하지 않는다', () => {
        expect(css).not.toMatch(/\.dark\s+\.daumpostcode-readonly/);
    });
});
