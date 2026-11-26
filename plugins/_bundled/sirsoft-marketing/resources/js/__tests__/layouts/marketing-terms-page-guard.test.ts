/**
 * 약관 「보기」 링크와 페이지 모듈
 *
 * @description
 * 마케팅 플러그인은 페이지 모듈을 필수가 아니라 선택 연동(`integrations`)으로 선언한다. 약관 본문은 페이지 모듈이
 * 제공하므로, 그 모듈이 꺼진 사이트에서 약관 「보기」를 누르면 `/api/modules/sirsoft-page/pages/{slug}` 가 404 로 끝난다.
 *
 * 동의 칸이 회원 항목으로 옮겨 가면서 약관 「보기」 단추도 이 플러그인의 조각이 아니라 코어 템플릿이 항목 계약
 * (`ui.link`)대로 그린다. 계약은 링크가 기대는 모듈을 `requires_module` 로 함께 알려 주고, 템플릿은 그 모듈이
 * 꺼져 있으면 단추를 그리지 않는다(서버 쪽 계약은 PHPUnit `MarketingConsentFieldsTest` 가 잠근다).
 * 그래서 이 플러그인의 레이아웃에는 페이지 모듈 API 를 부르는 곳이 남아 있지 않아야 한다.
 *
 * @scenario-manifest tests/scenarios/terms-page-module-guard.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

import pluginManifest from '../../../../plugin.json';

const PAGE_API = '/api/modules/sirsoft-page/';
const RESOURCES_DIR = path.resolve(__dirname, '../../..');

/**
 * 디렉터리 아래의 레이아웃 파일(소스와 짝 JSON)을 모은다.
 */
function layoutFiles(dir: string, out: string[] = []): string[] {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) layoutFiles(full, out);
        else if (entry.name.endsWith('.g7.html') || entry.name.endsWith('.json')) out.push(full);
    }
    return out;
}

describe('manifest — 페이지 모듈은 선택 연동', () => {
    /**
     * @scenario page_module_state=inactive
     * @effects marketing_manifest_declares_page_as_integration
     */
    it('dependencies 에 sirsoft-page 가 없고 integrations 에 버전 제약으로 있다', () => {
        expect((pluginManifest as any).dependencies?.modules?.['sirsoft-page']).toBeUndefined();
        expect((pluginManifest as any).integrations?.modules?.['sirsoft-page']).toBe('>=1.0.0');
    });
});

describe('레이아웃 — 페이지 모듈 API 를 직접 부르지 않는다', () => {
    const files = [
        ...layoutFiles(path.join(RESOURCES_DIR, 'extensions')),
        ...layoutFiles(path.join(RESOURCES_DIR, 'layouts')),
    ];

    /**
     * @scenario page_module_state=inactive
     * @effects no_layout_calls_page_module_api
     */
    it('검사할 레이아웃 파일이 있다', () => {
        expect(files.length).toBeGreaterThan(0);
    });

    /**
     * @scenario page_module_state=inactive
     * @effects no_layout_calls_page_module_api
     */
    it.each(files.map((file) => [path.relative(RESOURCES_DIR, file).split(path.sep).join('/'), file]))(
        '%s 에 페이지 모듈 API 호출이 없다',
        (_name, file) => {
            expect(fs.readFileSync(file as string, 'utf8')).not.toContain(PAGE_API);
        },
    );
});
