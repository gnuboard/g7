// e2e:allow 색 클래스 교정의 구조 검증 — 브라우저 재실측(1440·375 × 라이트·다크 × ko·en computed color)은 7.1.0 T1 이력에 기록
/**
 * @file pluginSettingsToneClasses.test.ts
 * @description 나이스페이먼츠 결제 환경설정 화면의 안내 글자색을 플러그인이 자기 CSS 로 제공하는지
 *
 * 회귀 배경: 라이브 모드 경고가 관리자 템플릿의 의미 글자색 클래스에 기대고 있어, 템플릿이 그 클래스를 바꾸거나 없애면 색이
 * 함께 바뀐다(#408 때 형제 플러그인 KG이니시스에서 색이 뒤바뀜).
 * 결정(2026-10-09): 확장이 템플릿에 의존하지 않게 확장 자체가 클래스를 제공한다 — 접두 `pay-nicepayments-` 의
 * 의미 글자색 클래스를 `resources/css/main.css` 에 정의하고(다크 모드 짝 포함) 레이아웃이 그것을 쓴다.
 * 다른 결제 플러그인과 같은 이름 체계를 쓰되 서로의 클래스를 참조하지 않는다.
 *
 * 저장 오류 상자(`alert-danger` · `text-error-*`)는 모든 플러그인 설정 화면이 함께 쓰는 관리자 공용 틀이라 대상이 아니다.
 *
 * @scenario-manifest tests/scenarios/plugin-settings-tone-classes.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import pluginSettingsLayout from '../../../layouts/admin/plugin_settings.json';

const EXT_ROOT = path.resolve(__dirname, '../../../..');
const PREFIX = 'pay-nicepayments-';
const TEMPLATE_TONE = /(^|[\s'])text-(warning|success|info|danger)-(soft|strong)(?=[\s']|$)/;

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
const cls = (n: LayoutNode | undefined) => (typeof n?.props?.className === 'string' ? n.props.className : '');
const tokens = (n: LayoutNode | undefined) => cls(n).replace(/[{}'?:]/g, ' ').split(/\s+/).filter(Boolean);

/**
 * 글자가 주어진 조건을 만족하는 노드 전부.
 *
 * @param test 노드 text 판정
 * @returns 일치 노드 목록
 */
function byText(test: (text: string) => boolean): LayoutNode[] {
    return nodes.filter((n) => typeof n.text === 'string' && test(n.text));
}

/** 플러그인 CSS 원본 */
const cssSource = (() => {
    const file = path.join(EXT_ROOT, 'resources/css/main.css');
    return fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';
})();

/** 레이아웃이 쓰는 플러그인 접두 클래스 전부 */
const usedPrefixed = [
    ...new Set(nodes.flatMap((n) => cls(n).replace(/[{}'?:]/g, ' ').split(/\s+/)).filter((t) => t.startsWith(PREFIX))),
];


describe('나이스페이먼츠 결제 환경설정 — 플러그인 자체 글자색 클래스', () => {
    /**
     * @scenario plugin=sirsoft-pay_nicepayments, element=layout
     *
     * @effects no_template_tone_classes
     */
    it('레이아웃은 관리자 템플릿의 의미 글자색 클래스(text-*-soft/strong)를 쓰지 않는다', () => {
        const offenders = nodes.filter((n) => TEMPLATE_TONE.test(cls(n))).map((n) => n.text ?? n.id ?? n.name);
        expect(offenders).toEqual([]);
    });

    /**
     * @scenario plugin=sirsoft-pay_nicepayments, element=live_mode_warning
     *
     * @effects live_warning_uses_warning_tone
     */
    it('라이브 모드 경고의 제목과 본문은 경고색이다', () => {
        const title = byText((t) => t.endsWith('.settings.live_mode_warning_title'))[0];
        const body = byText((t) => t.endsWith('.settings.live_mode_warning_body'))[0];
        expect(tokens(title)).toContain(`${PREFIX}tone-warning-strong`);
        expect(tokens(body)).toContain(`${PREFIX}tone-warning`);
    });

    /**
     * @scenario plugin=sirsoft-pay_nicepayments, element=plugin_css
     *
     * @effects tone_classes_defined_with_dark_pair
     */
    it('레이아웃이 쓰는 접두 클래스는 모두 플러그인 CSS 에 라이트·다크 규칙으로 정의돼 있다', () => {
        expect(usedPrefixed.length).toBeGreaterThanOrEqual(2);
        for (const name of usedPrefixed.filter((c) => c.startsWith(`${PREFIX}tone-`))) {
            const light = new RegExp(`(^|[\\s,}])\\.${name}\\s*\\{[^}]*color\\s*:`, 'm');
            const dark = new RegExp(`\\.dark\\s+\\.${name}\\s*\\{[^}]*color\\s*:`, 'm');
            expect({ name, light: light.test(cssSource), dark: dark.test(cssSource) }).toEqual({ name, light: true, dark: true });
        }
    });

    /**
     * @scenario plugin=sirsoft-pay_nicepayments, element=plugin_css
     *
     * @effects css_is_self_contained
     */
    it('플러그인 CSS 의 선택자는 모두 자기 접두 클래스다(다른 확장·템플릿 클래스를 정의하거나 덮지 않는다)', () => {
        const body = cssSource.replace(/\/\*[\s\S]*?\*\//g, '');
        const selectors = [...body.matchAll(/([^{}]+)\{/g)].map((m) => m[1].trim()).filter((s) => !s.startsWith('@'));
        expect(selectors.length).toBeGreaterThan(0);
        for (const group of selectors) {
            for (const sel of group.split(',').map((s) => s.trim())) {
                expect(sel).toMatch(new RegExp(`^(\\.dark\\s+)?\\.${PREFIX}[a-z-]+(:(hover|focus|focus-within))?$`));
            }
        }
    });

    /**
     * @scenario plugin=sirsoft-pay_nicepayments, element=asset_pipeline
     *
     * @effects css_asset_declared_and_built
     */
    it('plugin.json 이 CSS 자산을 선언하고, 엔트리가 CSS 를 불러오며, 빌드 산출물에 클래스가 실려 있다', () => {
        const manifest = JSON.parse(fs.readFileSync(path.join(EXT_ROOT, 'plugin.json'), 'utf8'));
        expect(manifest.assets?.css).toEqual({ entry: 'resources/css/main.css', output: 'dist/css/plugin.css' });

        const entry = fs.readFileSync(path.join(EXT_ROOT, 'resources/js/index.ts'), 'utf8');
        expect(entry).toMatch(/import\s+['"]\.\.\/css\/main\.css['"]/);

        const distFile = path.join(EXT_ROOT, 'dist/css/plugin.css');
        expect(fs.existsSync(distFile)).toBe(true);
        const dist = fs.readFileSync(distFile, 'utf8');
        for (const name of usedPrefixed) expect({ name, built: dist.includes(`.${name}`) }).toEqual({ name, built: true });
    });
});
