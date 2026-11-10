/**
 * @file admin-branding-template-settings.test.ts
 * @description 관리자 사이드바·모바일 헤더 로고가 관리자 템플릿 설정 로고를 먼저 쓰고, 비면 사이트 로고로 돌아가는지 잠근다 (#434).
 *
 * - 로고 = `_global.templateSettings?.branding?.logo_url`(관리자 전용 변형) → 없으면 환경설정 `site_logo_url`
 * - 데스크톱 사이드바·모바일 헤더 두 곳이 같은 식을 쓴다 — 한쪽만 고치면 뷰포트에 따라 다른 로고가 보인다
 * - 로고가 둘 다 없을 때만 사이트 이름 글자를 보인다(보임 조건이 로고 조건의 정확한 부정)
 *
 * 시나리오: case=settings_layout
 *
 * 효과: template_settings_exposed_to_global, admin_logo_prefers_template_setting
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

type Node = Record<string, any>;

/**
 * 레이아웃 트리의 모든 노드를 펼친다.
 *
 * @param tree 탐색 시작 노드
 * @param out 모은 노드
 * @returns 노드 목록
 */
const nodes = (tree: unknown, out: Node[] = []): Node[] => {
    if (Array.isArray(tree)) tree.forEach((child) => nodes(child, out));
    else if (tree && typeof tree === 'object') {
        out.push(tree as Node);
        Object.values(tree as Node).forEach((value) => nodes(value, out));
    }

    return out;
};

const base = JSON.parse(fs.readFileSync(path.join(ROOT, 'layouts/_admin_base.json'), 'utf-8'));
const source = fs.readFileSync(path.join(ROOT, 'layouts/_admin_base.g7.html'), 'utf-8');
const all = nodes(base);

const LOGO = '(_global.templateSettings?.branding?.logo_url || _global.settings?.general?.site_logo_url)';

/**
 * 로고 그림 노드와, 같은 부모 아래 짝인 사이트 이름 글자 노드를 찾는다.
 *
 * @param logoId 로고 그림 노드 id
 * @returns 로고·이름 노드
 */
const logoPair = (logoId: string): { logo: Node; name: Node } => {
    const parent = all.find((n) => Array.isArray(n.children) && n.children.some((c: Node) => c?.id === logoId));
    expect(parent, `${logoId} 의 부모`).toBeTruthy();
    const logo = parent!.children.find((c: Node) => c?.id === logoId);
    const name = parent!.children.find((c: Node) => c?.name === 'Span' && c !== logo);
    expect(name, `${logoId} 옆 사이트 이름`).toBeTruthy();

    return { logo, name };
};

describe('_admin_base — 관리자 로고는 템플릿 설정 로고 → 사이트 로고 (#434)', () => {
    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global, admin_logo_prefers_template_setting
    it.each([
        ['데스크톱 사이드바', 'logo_image'],
        ['모바일 헤더', 'mobile_logo_image'],
    ])('%s 로고는 관리자 템플릿 설정 branding.logo_url 을 먼저 쓰고 비면 사이트 로고로 돌아간다', (_label, logoId) => {
        const { logo, name } = logoPair(logoId);

        expect(logo.name).toBe('Img');
        expect(logo.if).toBe(`{{${LOGO}}}`);
        expect(logo.props.src).toBe(`{{${LOGO}}}`);
        // 사이트 이름 글자는 두 로고가 모두 없을 때만
        expect(name.if).toBe(`{{!${LOGO}}}`);
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global, admin_logo_prefers_template_setting
    it('원본(.g7.html)과 컴파일 결과 모두 로고 식이 정확히 두 곳(사이드바·모바일)에만 있고, 사이트 로고만 보는 옛 식은 없다', () => {
        const compiled = JSON.stringify(base);
        // 그림 if·src 2개씩 + 이름 글자 if 1개씩 = 곳마다 3번
        expect(compiled.split(`{{${LOGO}}}`).length - 1).toBe(4);
        expect(compiled.split(`{{!${LOGO}}}`).length - 1).toBe(2);
        expect(source.split(`{{${LOGO}}}`).length - 1).toBe(4);
        expect(source.split(`{{!${LOGO}}}`).length - 1).toBe(2);

        // 템플릿 설정을 거치지 않고 사이트 로고만 직접 보는 바인딩이 남아 있지 않다
        const bare = compiled.replaceAll(LOGO, '');
        expect(bare).not.toContain('site_logo_url');
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global, admin_logo_prefers_template_setting
    it('레이아웃이 읽는 branding.logo_url 은 선언의 이미지 칸(branding.logo)에서 계산되어 화면에 노출된다', () => {
        const declaration = JSON.parse(fs.readFileSync(path.join(ROOT, 'config/settings/defaults.json'), 'utf-8'));

        expect(declaration.schema.branding.logo.type).toBe('image');
        expect(declaration.frontend_schema.branding.expose).toBe(true);
        expect(declaration.frontend_schema.branding.fields.logo_url.expose).toBe(true);
    });
});
