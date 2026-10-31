// e2e:allow 확장점 조각이 데이터소스를 싣는지(어느 화면에 실리는가)는 레이아웃 파일 대조로 잠근다 — 화면 동작은 브라우저 실측으로 확인했다
/**
 * 비즈뿌리오 [편집] 모달 섹션의 데이터소스는 확장점 조각이 함께 싣는다.
 *
 * 섹션(notification_template_form_sections)과 하단 저장 버튼(notification_template_form_footer_actions)은 확장점이라
 * 그 확장점을 둔 모든 화면에 주입된다. 그런데 섹션이 읽는 데이터소스(bizppurioTemplates 등)는 코어·게시판·쇼핑몰 설정
 * 오버레이에만 있어, 같은 모달을 둔 GDPR·환율 플러그인 설정 화면에서는 알림톡 템플릿 상태와 작성 셀렉트가 늘 비어 있었다.
 * 화면 이름을 아는 오버레이를 더하는 대신(의존성 없는 확장 지목은 규정 위반), 확장점 조각이 데이터소스를 싣게 해
 * 확장점이 들어가는 모든 화면에 한 번씩 병합되게 했다.
 *
 * 번들 플러그인 레이아웃을 훑어 이 확장점을 둔 플러그인이 있는지 스스로 찾고(손 목록 없음), 조각이 섹션이 읽는
 * 데이터소스를 모두 싣는지, 오버레이가 같은 id 를 다시 선언하지 않는지(레이아웃 data_sources id 중복) 본다.
 * 요약 맵은 화면을 열 때 받으므로, 조회 권한이 없을 때(403)만 알림 없이 빈 맵으로 대신하는지도 본다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';

const PLUGIN_ROOT = resolve(__dirname, '../../../..');
const BUNDLED_PLUGINS = resolve(PLUGIN_ROOT, '..');
const EXTENSIONS_DIR = resolve(PLUGIN_ROOT, 'resources/extensions');
const EXTENSION_POINT = 'notification_template_form_sections';

function walkJson(dir: string): string[] {
    if (!existsSync(dir)) return [];
    return readdirSync(dir).flatMap((name) => {
        const full = join(dir, name);
        if (statSync(full).isDirectory()) return walkJson(full);
        return name.endsWith('.json') ? [full] : [];
    });
}

function readExtension(file: string): any {
    return JSON.parse(readFileSync(join(EXTENSIONS_DIR, file), 'utf8'));
}

/** 확장점을 둔 플러그인 설정 화면(비즈뿌리오 자신 제외) */
function hostPlugins(): string[] {
    return readdirSync(BUNDLED_PLUGINS)
        .filter((id) => id !== 'sirsoft-message_bizppurio')
        .filter((id) => walkJson(join(BUNDLED_PLUGINS, id, 'resources/layouts')).some((f) => readFileSync(f, 'utf8').includes(EXTENSION_POINT)));
}

/** 섹션과 하단 버튼이 읽는 비즈뿌리오 데이터소스 id */
function referencedDataSources(): string[] {
    const text = ['notification_template_form_sections.json', 'notification_template_form_footer_actions.json']
        .map((f) => readFileSync(join(EXTENSIONS_DIR, f), 'utf8'))
        .join('\n');
    return [...new Set(text.match(/bizppurio[A-Z][A-Za-z]+/g) ?? [])].sort();
}

describe('비즈뿌리오 섹션 데이터소스 — 확장점 조각이 싣는다', () => {
    const section = readExtension('notification_template_form_sections.json');
    const needed = referencedDataSources();
    const declared = (section.data_sources ?? []).map((d: any) => d.id);

    // @scenario surface=plugin_settings_host
    // @effects plugin_settings_hosts_carry_bizppurio_section_data_sources
    it('같은 모달을 둔 플러그인 설정 화면이 있다(GDPR, 환율)', () => {
        expect(hostPlugins()).toEqual(expect.arrayContaining(['sirsoft-gdpr', 'sirsoft-koreaexim']));
        expect(section.extension_point).toBe(EXTENSION_POINT);
    });

    // @scenario surface=plugin_settings_host
    // @effects plugin_settings_hosts_carry_bizppurio_section_data_sources
    it('확장점 조각이 섹션과 하단 버튼이 읽는 데이터소스를 모두 싣고, 요약 맵은 화면을 열 때 받는다', () => {
        expect(needed).toEqual(expect.arrayContaining(['bizppurioTemplates', 'bizppurioCategories', 'bizppurioProfiles']));
        for (const ds of needed) expect(declared, ds).toContain(ds);

        const templates = section.data_sources.find((d: any) => d.id === 'bizppurioTemplates');
        expect(templates.auto_fetch).toBe(true);
    });

    // @scenario surface=plugin_settings_host
    // @effects plugin_settings_hosts_carry_bizppurio_section_data_sources
    it('요약 맵 조회 권한이 없으면(403) 오류 알림 없이 빈 맵으로 대신하고, 다른 실패는 알림을 남긴다', () => {
        // 화면을 열 때 받으므로 메시지 조회 권한이 없는 관리자가 GDPR, 환율 설정을 열어도 403 알림이 뜨지 않아야 한다.
        // 묵음은 403 에만 건다. 서버 오류까지 삼키면 운영자가 요약이 왜 비었는지 알 길이 없다.
        const templates = section.data_sources.find((d: any) => d.id === 'bizppurioTemplates');
        expect(templates.errorHandling?.['403']?.handler).toBe('suppress');
        expect(templates.errorHandling?.default).toBeUndefined();
        expect(templates.fallback?.data?.templates).toEqual({});
    });

    // @scenario surface=plugin_settings_host
    // @effects plugin_settings_hosts_carry_bizppurio_section_data_sources
    it('오버레이는 같은 데이터소스 id 를 다시 선언하지 않는다(한 레이아웃 안 id 중복 방지)', () => {
        const overlays = readdirSync(EXTENSIONS_DIR)
            .filter((f) => f.endsWith('.json') && f !== 'notification_template_form_sections.json')
            .map(readExtension)
            .filter((o) => typeof o.target_layout === 'string');

        expect(overlays.length).toBeGreaterThan(0);
        for (const overlay of overlays) {
            const ids = (overlay.data_sources ?? []).map((d: any) => d.id);
            for (const ds of declared) expect(ids, `${overlay.target_layout} 가 ${ds} 를 다시 선언`).not.toContain(ds);
        }
    });
});
