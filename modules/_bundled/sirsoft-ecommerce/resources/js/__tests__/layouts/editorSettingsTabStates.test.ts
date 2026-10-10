/**
 * @file editorSettingsTabStates.test.ts
 * @description 레이아웃 편집기 「상태」 고르개가 쇼핑몰 환경설정의 탭을 모두 고를 수 있는지
 *
 * 편집기에서 쇼핑몰 환경설정을 열면 상단 「상태:」 고르개로 보여 줄 탭을 고른다. 1.3.0 에서 마일리지 설정이
 * 별도 화면(마일리지·예치금 설정)에서 환경설정의 「마일리지」 탭으로 옮겨 왔는데, 고르개에는 그 탭 상태가 없어
 * 편집기에서 마일리지 탭을 열어 고칠 방법이 없었다(2026-10-07 실측 — 탭 11개, 고르개 10개).
 * 화면의 탭 항목(id)마다 같은 탭을 여는 상태(`query.tab`·`activeEcommerceSettingsTab`)가 있어야 한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');
const LAYOUT = join(MODULE_ROOT, 'resources/layouts/admin/admin_ecommerce_settings.g7.html');
const ADMIN_EDITOR_LANG = resolve(MODULE_ROOT, '../../../templates/_bundled/sirsoft-admin_basic/lang/partial');

type Json = Record<string, any>;

const spec: Json = JSON.parse(readFileSync(join(MODULE_ROOT, 'editor-spec.json'), 'utf8'));

/**
 * 환경설정 화면 상태 묶음의 항목들을 찾습니다.
 *
 * @return 상태 항목 목록
 */
function settingsStateItems(): Json[] {
    const found: Json[] = [];
    const walk = (node: any): void => {
        if (Array.isArray(node)) {
            node.forEach(walk);

            return;
        }
        if (node && typeof node === 'object') {
            if (node.scope?.kind === 'route' && node.scope?.match === '*/admin/ecommerce/settings' && Array.isArray(node.items)) {
                found.push(...node.items);
            }
            Object.values(node).forEach(walk);
        }
    };
    walk(spec.states);

    return found;
}

const markup = readFileSync(LAYOUT, 'utf8');
const nav = markup.match(/<tab-navigation\b[^>]*data-ref="tab_navigation"[^>]*>([\s\S]*?)<\/tab-navigation>/)?.[1] ?? '';
const tabIds = [...nav.matchAll(/<item id="([a-z_]+)"/g)].map((m) => m[1]);

describe('편집기 「상태」 고르개 — 쇼핑몰 환경설정 탭', () => {
    /**
     * @scenario case=c01
     * @effects settings_tab_states_exist
     */
    it('환경설정 화면의 탭 항목을 읽었다', () => {
        expect(tabIds).toContain('mileage');
        expect(tabIds.length).toBeGreaterThan(5);
    });

    /**
     * @scenario case=c02
     * @effects settings_tab_states_exist
     */
    it.each(tabIds)('「%s」 탭을 여는 상태가 있다', (tabId) => {
        const item = settingsStateItems().find((i) => i.initialState?.query?.tab === tabId);
        expect(item, `tab=${tabId}`).toBeDefined();
        expect(item?.initialState?.global?.activeEcommerceSettingsTab).toBe(tabId);
    });

    /**
     * @scenario case=c03
     * @effects settings_tab_states_exist
     */
    it('상태 이름 번역 키가 관리자 편집기 문구(ko·en)에 있다', () => {
        for (const locale of ['ko', 'en']) {
            const lang: Json = JSON.parse(readFileSync(join(ADMIN_EDITOR_LANG, locale, 'editor.json'), 'utf8'));
            for (const item of settingsStateItems()) {
                const key = String(item.label).replace(/^\$t:editor\./, '');
                const value = key.split('.').reduce((acc: any, k) => (acc == null ? undefined : acc[k]), lang);
                expect(typeof value, `${locale}: ${item.label}`).toBe('string');
            }
        }
    });
});
