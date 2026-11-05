/**
 * @file template-settings-bindings.test.ts
 * @description sirsoft-basic 템플릿 설정(#434)이 레이아웃에 연결되는 자리를 잠근다.
 *
 * - 모든 바인딩은 `_global.templateSettings?.…  ?? 기본값` — 저장하지 않았거나 옛 코어면 지금 화면과 같다
 * - 편집기와 겹치는 값(게시판 개수·저작권 문구·홈 섹션·상품 개수)은 템플릿 설정이 유일한 주인이다
 * - 선언(defaults.json)의 노출 칸·편집기 샘플(sampleGlobal)·봇 화면(seo-config)이 같은 이름을 쓴다
 *
 * @scenario case=settings_layout
 *
 * @effects template_settings_exposed_to_global
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
const read = (rel: string) => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf-8'));

type Node = Record<string, any>;
const nodes = (tree: unknown, out: Node[] = []): Node[] => {
    if (Array.isArray(tree)) tree.forEach((child) => nodes(child, out));
    else if (tree && typeof tree === 'object') {
        out.push(tree as Node);
        Object.values(tree as Node).forEach((value) => nodes(value, out));
    }

    return out;
};

const base = read('layouts/_user_base.json');
const home = read('layouts/home.json');
const declaration = read('config/settings/defaults.json');
const TS = '_global.templateSettings';

describe('_user_base — 헤더·푸터 (#434)', () => {
    const all = nodes(base);
    const header = all.find((n) => n.name === 'Header')!;
    const footer = all.find((n) => n.name === 'Footer')!;

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global
    it('데스크톱 헤더는 게시판 개수·테마 단추·메뉴·다크 로고를 템플릿 설정에서 기본값과 함께 받는다', () => {
        expect(header.props.maxVisibleBoards).toBe(`{{${TS}?.header?.max_boards ?? 5}}`);
        expect(header.props.showThemeToggle).toBe(`{{${TS}?.header?.show_theme_toggle ?? true}}`);
        // 메뉴는 보이기 조건을 거른 계산값 하나를 헤더·서랍·봇 화면이 함께 쓴다
        expect(header.props.menu).toBe('{{_computed.siteMenu}}');
        expect(base.computed.siteMenu).toContain(`${TS}?.header?.menu ??`);
        expect(header.props.logoDark).toBe(`{{${TS}?.branding?.logo_dark_url ?? ''}}`);
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global
    it('모바일 테마 단추·서랍 메뉴·모바일 다크 로고도 같은 값을 본다', () => {
        expect(all.find((n) => n.id === 'mobile_theme_btn')!.if).toBe(`{{${TS}?.header?.show_theme_toggle ?? true}}`);
        const menuSection = all.find((n) => n.id === 'mobile_drawer_menu_section')!;
        expect(JSON.stringify(menuSection)).toContain('_computed.siteMenu');
        expect(all.find((n) => n.id === 'mobile_header_logo_dark')!.if).toBe(`{{!!${TS}?.branding?.logo_dark_url}}`);
        // 새 창 링크는 opener 를 끊는다
        expect(JSON.stringify(menuSection)).toContain('noopener noreferrer');
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global
    it('푸터 소셜 링크 7종·저작권 문구·Powered by 는 템플릿 설정 — 옛 연결(_global.settings.social)은 없다', () => {
        const social = footer.props.socialLinks;
        expect(Object.keys(social)).toEqual(['youtube', 'instagram', 'facebook', 'x', 'github', 'discord', 'blog']);
        Object.entries(social).forEach(([key, value]) => expect(value).toBe(`{{${TS}?.footer?.social_${key} ?? ''}}`));
        expect(JSON.stringify(base)).not.toContain('_global.settings?.social');
        expect(footer.props.copyrightText).toBe(`{{$localized(${TS}?.footer?.copyright ?? {}) ?? ''}}`);
        expect(String(footer.props.defaultCopyrightText)).toMatch(/^\$t:footer\.copyright/);
        expect(footer.props.showPoweredBy).toBe(`{{${TS}?.footer?.show_powered_by ?? true}}`);
    });
});

describe('홈 — 섹션 보이기·상품 개수 (#434)', () => {
    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global
    it('인기·신상품 데이터소스 개수는 템플릿 설정(기본 8)이고 섹션을 숨기면 부르지 않는다', () => {
        for (const id of ['popularProducts', 'newProducts']) {
            const source = home.data_sources.find((s: Node) => s.id === id);
            expect(source.endpoint).toContain(`limit={{${TS}?.home?.product_count ?? 8}}`);
            expect(source.if).toContain(`${TS}?.home?.show_shop_sections ?? true`);
        }
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global
    it('환영 카드 칸은 설정으로 숨길 수 있다', () => {
        expect(nodes(home).find((n) => n.id === 'home_welcome_slot')!.if).toBe(`{{${TS}?.home?.show_welcome ?? true}}`);
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global, home_sections_toggle_with_module_guard
    it.each([
        ['home_stat_cards', 'show_stats'],
        ['home_feature_row', 'show_feature_row'],
        ['home_board_summaries', 'show_board_summaries'],
        ['home_shop_sections', 'show_shop_sections'],
    ])('홈 %s 는 모듈 활성 조건을 지킨 채 설정 %s(기본 true)로 숨길 수 있다', (id, key) => {
        const moduleId = key === 'show_shop_sections' ? 'sirsoft-ecommerce' : 'sirsoft-board';
        expect(nodes(home).find((n) => n.id === id)!.if).toBe(
            `{{(_global.activeModules?.includes('${moduleId}') ?? true) && (${TS}?.home?.${key} ?? true)}}`,
        );
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global, home_sections_toggle_with_module_guard
    it('게시판 섹션을 숨기면 그 섹션 데이터소스도 부르지 않는다', () => {
        const board = `(_global.activeModules?.includes('sirsoft-board') ?? true)`;
        const sourceIf = (id: string) => home.data_sources.find((s: Node) => s.id === id)!.if;

        expect(sourceIf('recent_posts')).toBe(`{{${board} && (${TS}?.home?.show_feature_row ?? true)}}`);
        expect(sourceIf('popular_boards')).toBe(`{{${board} && (${TS}?.home?.show_feature_row ?? true)}}`);
        expect(sourceIf('home_boards')).toBe(`{{${board} && (${TS}?.home?.show_board_summaries ?? true)}}`);
    });
});

describe('선언·편집기 샘플·봇 화면이 같은 이름을 쓴다 (#434)', () => {
    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global
    it('레이아웃이 읽는 설정 경로는 모두 선언에 있고 노출된다', () => {
        const used = new Set<string>();
        const text = JSON.stringify(base) + JSON.stringify(home);
        for (const match of text.matchAll(/_global\.templateSettings\?\.([a-z_]+)\?\.([a-z_]+)/g)) used.add(`${match[1]}.${match[2]}`);

        expect(used.size).toBeGreaterThan(10);
        for (const pathKey of used) {
            const [section, key] = pathKey.split('.');
            const computed = key.endsWith('_url');
            expect(computed ? declaration.schema[section][key.replace(/_url$/, '')] : declaration.schema[section][key], pathKey).toBeTruthy();
            const exposure = declaration.frontend_schema[section];
            expect(exposure?.expose, `${section} 노출`).toBe(true);
            if (exposure.fields) expect(exposure.fields[key]?.expose, `${pathKey} 노출`).toBe(true);
        }
    });

    // @scenario case=settings_layout
    // @effects template_settings_exposed_to_global
    it('편집기 샘플에 기본값이 있고, 봇 화면 헤더가 메뉴 링크를 그린다', () => {
        const sample = read('editor-spec/sampleGlobal.json');
        expect(sample.templateSettings.header.max_boards).toBe(declaration.defaults.header.max_boards);
        expect(sample.templateSettings.home.product_count).toBe(declaration.defaults.home.product_count);
        expect(sample.templateSettings.header.menu).toEqual(declaration.defaults.header.menu);

        const seo = read('seo-config.json');
        expect(JSON.stringify(seo.render_modes.header_nav)).toContain('"iterate":"seoMenuLinks"');
        expect(nodes(base).find((n) => n.name === 'Header')!.props.seoMenuLinks).toContain('_computed.siteMenu');
        expect(JSON.stringify(seo.render_modes.footer_nav)).toContain('{socialLinks.youtube}');
    });
});
