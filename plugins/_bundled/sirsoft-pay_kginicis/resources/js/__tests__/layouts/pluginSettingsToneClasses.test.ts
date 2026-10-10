// e2e:allow 색 클래스 교정의 구조 검증 — 브라우저 재실측(1440·375 × 라이트·다크 × ko·en computed color)은 7.1.0 T1 이력에 기록
/**
 * @file pluginSettingsToneClasses.test.ts
 * @description KG이니시스 결제 환경설정 화면의 안내 글자색을 플러그인이 자기 CSS 로 제공하는지
 *
 * 회귀 배경: 관리자 템플릿 의미 클래스 도입(#408) 때 글자색 치환이 한 칸씩 밀렸다. 라이브 모드 경고 본문이 초록,
 * CBT 연결 점검의 「정상」이 노랑, 파란 CBT 도우미 카드 설명이 노랑, 주황 연결 진단 카드 설명이 파랑으로 보였다.
 * 결정(2026-10-09): 확장이 템플릿에 의존하지 않게 확장 자체가 클래스를 제공한다 — 접두 `pay-kginicis-` 의
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
const PREFIX = 'pay-kginicis-';
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

/**
 * 주어진 노드를 자식으로 갖는 부모 노드.
 *
 * @param child 자식 노드
 * @returns 부모 노드
 */
function parentOf(child: LayoutNode): LayoutNode | undefined {
    return nodes.find((n) => Array.isArray(n.children) && n.children.includes(child));
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

describe('KG이니시스 결제 환경설정 — 플러그인 자체 글자색 클래스', () => {
    /**
     * @scenario plugin=sirsoft-pay_kginicis, element=layout
     *
     * @effects no_template_tone_classes
     */
    it('레이아웃은 관리자 템플릿의 의미 글자색 클래스(text-*-soft/strong)를 쓰지 않는다', () => {
        const offenders = nodes.filter((n) => TEMPLATE_TONE.test(cls(n))).map((n) => n.text ?? n.id ?? n.name);
        expect(offenders).toEqual([]);
    });

    /**
     * @scenario plugin=sirsoft-pay_kginicis, element=live_mode_warning
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
     * @scenario plugin=sirsoft-pay_kginicis, element=cbt_helper_card
     *
     * @effects cbt_helper_uses_info_and_success_tone
     */
    it('파란 CBT 도우미 카드의 설명·링크는 안내색, 생성 결과는 성공색, 오류는 위험색이다', () => {
        const desc = byText((t) => t.endsWith('.settings.cbt_helper_description'))[0];
        expect(tokens(desc)).toContain(`${PREFIX}tone-info-strong`);

        const linkLabel = byText((t) => t.endsWith('.settings.cbt_helper_products_link'))[0];
        expect(tokens(parentOf(linkLabel!))).toContain(`${PREFIX}tone-info`);

        const success = byText((t) => t.endsWith('.settings.cbt_helper_create_success'))[0];
        expect(tokens(success)).toContain(`${PREFIX}tone-success-strong`);

        const editLink = byText((t) => t.endsWith('.settings.cbt_helper_admin_edit_link'))[0];
        expect(tokens(parentOf(editLink!))).toContain(`${PREFIX}tone-success`);

        const error = byText((t) => t === '{{_local.cbtTestProductError}}')[0];
        expect(tokens(error)).toContain(`${PREFIX}tone-danger-strong`);
    });

    /**
     * @scenario plugin=sirsoft-pay_kginicis, element=cbt_connectivity_card
     *
     * @effects cbt_connectivity_uses_warning_tone
     */
    it('주황 연결 진단 카드의 설명과 나가는 IP 는 경고색이다', () => {
        const desc = byText((t) => t.endsWith('.settings.cbt_connectivity_description'))[0];
        expect(tokens(desc)).toContain(`${PREFIX}tone-warning-strong`);

        const egress = byText((t) => t.startsWith('{{_local.cbtConnCheckResult?.egress_ip'))[0];
        expect(tokens(egress)).toContain(`${PREFIX}tone-warning`);

        const error = byText((t) => t === '{{_local.cbtConnCheckError}}')[0];
        expect(tokens(error)).toContain(`${PREFIX}tone-danger-strong`);
    });

    /**
     * @scenario plugin=sirsoft-pay_kginicis, element=cbt_check_result
     *
     * @effects cbt_ok_is_success_and_check_is_danger
     */
    it('연결 점검 결과 「정상」은 성공색, 「확인 필요」는 위험색, TCP 도달 배지도 같은 짝이다', () => {
        const oks = byText((t) => t.endsWith('.settings.cbt_callback_ok'));
        const checks = byText((t) => t.endsWith('.settings.cbt_callback_check'));
        expect(oks).toHaveLength(3);
        expect(checks).toHaveLength(3);
        for (const ok of oks) expect(tokens(ok)).toContain(`${PREFIX}tone-success`);
        for (const check of checks) expect(tokens(check)).toContain(`${PREFIX}tone-danger`);

        const tcpOk = byText((t) => t.startsWith('✓'))[0];
        const tcpFail = byText((t) => t.startsWith('✗'))[0];
        expect(tokens(tcpOk)).toContain(`${PREFIX}tone-success-strong`);
        expect(tokens(tcpFail)).toContain(`${PREFIX}tone-danger-strong`);
    });

    /**
     * @scenario plugin=sirsoft-pay_kginicis, element=cbt_check_layout
     *
     * @effects cbt_check_layout_uses_plugin_classes
     */
    it('연결 진단 결과의 칸 배치는 템플릿 CSS 에 없는 임의값 클래스 대신 플러그인 클래스를 쓴다', () => {
        // 관리자 템플릿은 자기 layouts/ 만 훑어 CSS 를 만든다 — 플러그인 레이아웃에만 있는 임의값(`grid-cols-[150px_1fr]` 등)은
        // 규칙이 생성되지 않아 콜백 점검 표가 1열로 무너지고 라벨 폭·들여쓰기가 사라졌다(7.1.0 T1 실측: gridTemplateColumns 1010px).
        const arbitrary = nodes.flatMap((n) => tokens(n)).filter((t) => /^(grid-cols|min-w|pl)-\[/.test(t));
        expect(arbitrary).toEqual([]);

        const callbackLabel = byText((t) => t.endsWith('.settings.cbt_callback_app_url_label'))[0];
        expect(tokens(parentOf(callbackLabel!))).toContain(`${PREFIX}callback-grid`);

        const egressLabel = byText((t) => t.endsWith('.settings.cbt_connectivity_egress_ip_label'))[0];
        expect(tokens(egressLabel)).toContain(`${PREFIX}label-wide`);
        const egressHint = byText((t) => t.endsWith('.settings.cbt_connectivity_hint_egress'))[0];
        expect(tokens(egressHint)).toContain(`${PREFIX}indent-wide`);
        const dnsLabel = byText((t) => t.endsWith('.settings.cbt_connectivity_dns_label'))[0];
        expect(tokens(dnsLabel)).toContain(`${PREFIX}label-narrow`);

        for (const name of usedPrefixed.filter((c) => !c.startsWith(`${PREFIX}tone-`))) {
            expect({ name, defined: new RegExp(`\\.${name}(:[a-z-]+)?\\s*\\{`).test(cssSource) }).toEqual({ name, defined: true });
        }
    });

    /**
     * @scenario plugin=sirsoft-pay_kginicis, element=plugin_css
     *
     * @effects tone_classes_defined_with_dark_pair
     */
    it('레이아웃이 쓰는 접두 글자색 클래스는 모두 플러그인 CSS 에 라이트·다크 규칙으로 정의돼 있다', () => {
        const tones = usedPrefixed.filter((c) => c.startsWith(`${PREFIX}tone-`));
        expect(tones.length).toBeGreaterThanOrEqual(6);
        for (const name of tones) {
            const light = new RegExp(`(^|[\\s,}])\\.${name}\\s*\\{[^}]*color\\s*:`, 'm');
            const dark = new RegExp(`\\.dark\\s+\\.${name}\\s*\\{[^}]*color\\s*:`, 'm');
            expect({ name, light: light.test(cssSource), dark: dark.test(cssSource) }).toEqual({ name, light: true, dark: true });
        }
    });

    /**
     * @scenario plugin=sirsoft-pay_kginicis, element=plugin_css
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
     * @scenario plugin=sirsoft-pay_kginicis, element=asset_pipeline
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
