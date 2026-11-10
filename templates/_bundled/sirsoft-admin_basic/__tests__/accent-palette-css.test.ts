/**
 * @file accent-palette-css.test.ts
 * @description 템플릿 설정 포인트 색(`branding.accent`)의 선택지마다 CSS 팔레트 규칙이 있는지 잠근다 (#434).
 *
 * - 코어가 `<html data-g7-accent="{값}">` 을 싣고, 이 템플릿 CSS 가 `[data-g7-accent=…]` 에서 파랑 변수(`--color-blue-*`)를
 *   고른 색 변수로 바꾼다 — 선택지를 선언에 추가하고 CSS 를 빠뜨리면 그 색을 골라도 화면이 그대로 파랑이다
 * - 선택지 목록은 선언(config/settings/defaults.json)에서 읽는다 — 손으로 적은 목록과 갈라지지 않게
 * - 기본값(파랑)은 덮지 않는다. 빌드 CSS(dist/css/components.css)에도 규칙과 바꿔 넣을 색 변수가 남아 있어야 한다
 *
 * 시나리오: case=settings_layout
 *
 * 효과: template_settings_exposed_to_global, accent_palette_defined_per_option
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const ROOT = (() => {
    let current = path.dirname(fileURLToPath(import.meta.url));
    while (!fs.existsSync(path.join(current, 'template.json'))) current = path.dirname(current);

    return current;
})();

const declaration = JSON.parse(fs.readFileSync(path.join(ROOT, 'config/settings/defaults.json'), 'utf-8'));
const source = fs.readFileSync(path.join(ROOT, 'src/styles/semantic.css'), 'utf-8');
const built = fs.readFileSync(path.join(ROOT, 'dist/css/components.css'), 'utf-8');

const SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];
const defaultAccent: string = declaration.defaults?.branding?.accent;
const options: string[] = (declaration.schema?.branding?.accent?.options ?? []).map((option: { value: string }) => String(option.value));
const overrides = options.filter((value) => value !== defaultAccent);

/**
 * `[data-g7-accent=값]` 규칙 본문을 꺼낸다(따옴표 유무·공백·압축 빌드 모두).
 *
 * @param css CSS 글자
 * @param value 선택지 값
 * @returns 규칙 본문 목록
 */
const ruleBodies = (css: string, value: string): string[] => {
    const pattern = new RegExp(`\\[data-g7-accent=(?:"${value}"|'${value}'|${value})\\]\\s*\\{([^}]*)\\}`, 'g');

    return [...css.matchAll(pattern)].map((match) => match[1]);
};

/**
 * 규칙 본문이 파랑 변수 11단을 모두 그 색 변수로 바꾸는지 본다.
 *
 * @param body 규칙 본문
 * @param value 선택지 값
 */
const expectOverridesBlue = (body: string, value: string): void => {
    const compact = body.replace(/\s+/g, '');
    for (const shade of SHADES) {
        expect(compact, `${value} — --color-blue-${shade}`).toContain(`--color-blue-${shade}:var(--color-${value}-${shade})`);
    }
};

describe('포인트 색 팔레트 CSS — sirsoft-admin_basic (#434)', () => {
    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global, accent_palette_defined_per_option
    it('선언에서 읽은 포인트 색 선택지가 있고 기본값은 그 안에 있다', () => {
        expect(declaration.schema.branding.accent.type).toBe('enum');
        expect(options.length).toBeGreaterThan(1);
        expect(options).toContain(defaultAccent);
        expect(overrides.length).toBeGreaterThan(0);
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global, accent_palette_defined_per_option
    it('기본이 아닌 선택지마다 src/styles/semantic.css 에 파랑 변수 11단을 바꾸는 규칙이 하나씩 있다', () => {
        expect(overrides.length).toBeGreaterThan(0);
        for (const value of overrides) {
            const bodies = ruleBodies(source, value);
            expect(bodies, `[data-g7-accent="${value}"] 규칙`).toHaveLength(1);
            expectOverridesBlue(bodies[0], value);
        }
        // 기본값(파랑)은 덮지 않는다
        expect(ruleBodies(source, defaultAccent)).toHaveLength(0);
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global, accent_palette_defined_per_option
    it('빌드 CSS(dist/css/components.css)에도 선택지마다 규칙이 남고, 바꿔 넣는 색 변수가 정의돼 있다', () => {
        expect(overrides.length).toBeGreaterThan(0);
        for (const value of overrides) {
            const bodies = ruleBodies(built, value);
            expect(bodies.length, `빌드 CSS 의 [data-g7-accent=${value}]`).toBeGreaterThan(0);
            expectOverridesBlue(bodies[0], value);
            // Tailwind v4 는 쓰지 않는 색 변수를 빌드에서 뺀다 — 빠지면 파랑 변수가 무효가 되어 색이 사라진다
            for (const shade of SHADES) {
                expect(built, `빌드 CSS 의 --color-${value}-${shade} 정의`).toMatch(new RegExp(`--color-${value}-${shade}\\s*:`));
            }
        }
    });
});
