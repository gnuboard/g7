// e2e:allow 클래스 이름·CSS 정의의 구조 검증 — 다크 모드·포커스·호버 색의 브라우저 실측은 g7_2 W3 화면 실측 매트릭스 몫(7.1.0 출시 최종 수정)
/**
 * @file pluginCssSelfContained.test.ts
 * @description GDPR 환경설정 화면이 관리자 템플릿 CSS 에 없는 유틸리티 대신 플러그인 자기 CSS 의 접두 클래스를 쓰는지
 *
 * 회귀 배경: 관리자 템플릿은 자기 layouts/ 만 훑어 CSS 를 만든다. 이 플러그인 레이아웃·조각에만 있는 유틸리티
 * (다크 모드 짝·포커스·호버·임의값)는 규칙이 생성되지 않아, 빌드도 테스트도 초록인 채로 화면에서만 빠졌다
 * (예: 다크 모드에서 밝은 상자, 포커스 테두리 미해제). 결정(2026-10-09): 확장마다 자기 CSS 를 싣고 접두 클래스로 정의한다.
 *
 * @scenario-manifest tests/scenarios/plugin-css-self-contained.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const EXT_ROOT = path.resolve(__dirname, '../../../..');
const PREFIX = 'gdpr-';
const CSS_ENTRY = 'resources/css/banner.css';

/** 이번에 접두 클래스로 옮긴, 관리자 템플릿 CSS 에 규칙이 없던 토큰 */
const UNGENERATED = ['dark:focus:border-red-400', 'dark:border-gray-700/60', 'border-l-blue-500', 'dark:border-l-blue-400', 'border-l-amber-500', 'dark:border-l-amber-400', 'dark:bg-red-900/10', 'dark:text-sky-400', 'dark:bg-sky-900/20', 'dark:border-sky-800', 'dark:text-sky-300', 'dark:bg-sky-900/40'];

/** 검사 대상 레이아웃·조각 소스(.g7.html) */
const SOURCES = ['resources/layouts/admin/plugin_settings.g7.html', 'resources/layouts/admin/partials/plugin_settings/_policy_version_publish_modal.g7.html'];

/**
 * `.g7.html` 소스의 class 값에서 클래스 토큰을 모은다 — 삼항 안의 문자열 리터럴도 실제로 렌더되므로 포함한다.
 *
 * @param source 마크업 원문
 * @returns 클래스 토큰 목록
 */
function classTokens(source: string): string[] {
    const out: string[] = [];
    for (const m of source.matchAll(/\bclass="([^"]*)"/g)) {
        const raw = m[1];
        const literals = [...raw.matchAll(/'([^']*)'/g)].map((l) => l[1]);
        const plain = raw.replace(/\{\{[\s\S]*?\}\}/g, ' ');
        for (const part of [...literals, plain]) out.push(...part.split(/\s+/).filter(Boolean));
    }
    return out;
}

const tokens = SOURCES.flatMap((rel) => classTokens(fs.readFileSync(path.join(EXT_ROOT, rel), 'utf8')));
const cssSource = fs.readFileSync(path.join(EXT_ROOT, CSS_ENTRY), 'utf8');
const usedPrefixed = [...new Set(tokens.filter((t) => t.startsWith(PREFIX)))];

describe('GDPR 환경설정 — 플러그인 자기 CSS', () => {
    /**
     * @scenario element=layout
     *
     * @effects no_ungenerated_utilities_in_layout
     */
    it('레이아웃은 관리자 템플릿 CSS 에 규칙이 없는 유틸리티를 쓰지 않는다', () => {
        expect(tokens.filter((t) => UNGENERATED.includes(t))).toEqual([]);
    });

    /**
     * @scenario element=plugin_css
     *
     * @effects prefixed_classes_defined_in_plugin_css
     */
    it('레이아웃이 쓰는 접두 클래스는 모두 플러그인 CSS 에 정의돼 있다', () => {
        expect(usedPrefixed.length).toBeGreaterThan(0);
        for (const name of usedPrefixed) {
            expect({ name, defined: new RegExp(`\\.${name}(?![\\w-])`).test(cssSource) }).toEqual({ name, defined: true });
        }
    });

    /**
     * @scenario element=plugin_css
     *
     * @effects css_is_self_contained
     */
    it('플러그인 CSS 의 선택자는 모두 자기 접두 클래스다(다른 확장·템플릿 클래스를 정의하거나 덮지 않는다)', () => {
        const body = cssSource.replace(/\/\*[\s\S]*?\*\//g, '');
        const selectors = [...body.matchAll(/([^{}]+)\{/g)].map((m) => m[1].trim()).filter((s) => !s.startsWith('@'));
        expect(selectors.length).toBeGreaterThan(0);
        for (const group of selectors) {
            for (const sel of group.split(',').map((s) => s.trim())) {
                const classes = [...sel.matchAll(/\.([\w-]+)/g)].map((c) => c[1]).filter((c) => c !== 'dark');
                expect({ sel, own: classes.length > 0 && classes.every((c) => c.startsWith(PREFIX)) }).toEqual({ sel, own: true });
            }
        }
    });

    /**
     * @scenario element=asset_pipeline
     *
     * @effects css_asset_declared_and_built
     */
    it('plugin.json 이 CSS 자산을 선언하고, 빌드 산출물에 쓰는 클래스가 실려 있다', () => {
        const manifest = JSON.parse(fs.readFileSync(path.join(EXT_ROOT, 'plugin.json'), 'utf8'));
        expect(manifest.assets?.css).toEqual({ entry: CSS_ENTRY, output: 'dist/css/plugin.css' });

        const distFile = path.join(EXT_ROOT, 'dist/css/plugin.css');
        expect(fs.existsSync(distFile)).toBe(true);
        const dist = fs.readFileSync(distFile, 'utf8');
        for (const name of usedPrefixed) expect({ name, built: dist.includes(`.${name}`) }).toEqual({ name, built: true });
    });
});
