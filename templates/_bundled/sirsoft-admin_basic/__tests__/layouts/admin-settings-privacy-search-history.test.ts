/**
 * 환경설정 개인정보 탭 — 「최근 검색어」 카드 레이아웃 계약 (코어 7.1.5)
 *
 * @description
 * 사용 여부 토글(`privacy.search_history_enabled`)·자동 저장 안내·범위 행 목록(`privacy.search_history_scopes`
 * = `[{scope, max_count, retention_days}]`)·보유기간 대상 행(`search-history`)을 고정한다. 범위 id 에 점이 있어
 * 폼 경로(`name=`)로 묶을 수 없으므로, 행마다 `data-each` 의 `idx` 로 그 행만 바꾼 목록을 통째로 되쓴다.
 * 범위 이름·소유 확장은 서버 `_meta.search_scopes` 에서 읽는다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { actionsOf, findById, flattenNodes } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import privacyTab from '../../layouts/partials/admin_settings/_tab_privacy.json';

/**
 * 저장소 루트를 위로 훑어 찾는다 (artisan 이 있는 곳).
 *
 * @returns 저장소 루트 절대경로
 */
function repoRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 12; depth++) {
        if (fs.existsSync(path.join(current, 'artisan'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('저장소 루트를 찾지 못했습니다.');
}

/**
 * 점 경로로 중첩 객체 값을 읽는다.
 *
 * @param source 원본 객체
 * @param dotted 점으로 이은 경로
 * @returns 값 또는 undefined
 */
function readPath(source: any, dotted: string): any {
    return dotted.split('.').reduce((acc, key) => (acc == null ? undefined : acc[key]), source);
}

/**
 * 노드 아래 모든 문자열에서 `$t:` 키를 모은다 (`|` 인자 앞까지).
 *
 * @param node 시작 노드
 * @returns 번역 키 목록
 */
function translationKeys(node: unknown): string[] {
    const keys = new Set<string>();
    const visit = (value: unknown) => {
        if (typeof value === 'string') {
            for (const match of value.matchAll(/\$t:([a-z0-9_.]+)/gi)) keys.add(match[1]);
            for (const match of value.matchAll(/\$t\('([a-z0-9_.]+)'/gi)) keys.add(match[1]);
        } else if (Array.isArray(value)) {
            value.forEach(visit);
        } else if (value && typeof value === 'object') {
            Object.values(value).forEach(visit);
        }
    };
    visit(node);

    return [...keys];
}

const card = findById(privacyTab, 'card_search_history');

describe('개인정보 탭 「최근 검색어」 카드', () => {
    /**
     * @scenario case=admin_card_layout
     *
     * @effects admin_card_binds_enabled_toggle_and_scope_rows
     */
    it('사용 여부 토글은 폼 경로로, 범위 행은 목록을 돌며 그 행만 숫자로 바꿔 되쓴다', () => {
        expect(card).not.toBeNull();

        const toggle = flattenNodes(card).find((n: any) => n.name === 'Toggle');
        expect(toggle?.props?.name).toBe('privacy.search_history_enabled');

        const row = findById(card, 'search_history_scope_row_{{idx}}');
        expect(row?.iteration).toEqual({ source: '_local.form?.privacy?.search_history_scopes ?? []', item_var: 'row', index_var: 'idx' });

        const inputs = flattenNodes(row).filter((n: any) => n.name === 'Input');
        expect(inputs).toHaveLength(2);
        for (const [input, field] of [[inputs[0], 'max_count'], [inputs[1], 'retention_days']] as const) {
            expect(input.props?.name).toBeUndefined();
            const change = actionsOf(input).find((a: any) => a.type === 'change');
            expect(change?.handler).toBe('setState');
            expect(change?.params?.target).toBe('local');
            expect(change?.params?.hasChanges).toBe(true);
            expect(change?.params?.['form.privacy.search_history_scopes']).toContain(`i === idx ? {...r, ${field}: Number($event.target.value)} : r`);
            expect(input.props?.className).toContain(`'privacy.search_history_scopes.' + idx + '.${field}'`);
        }

        const label = flattenNodes(row).find((n: any) => n.name === 'Span' && String(n.text).includes('_meta?.search_scopes'));
        expect(label?.text).toContain('?.label ?? row.scope');
    });

    /**
     * @scenario case=admin_card_layout
     *
     * @effects admin_card_target_row_uses_search_history_handler
     */
    it('대상 행은 보유기간 핸들러 search-history 의 건수·명단·직권 실행을 쓴다', () => {
        const viewButton = findById(card, 'view_search_history_targets');
        const runButton = findById(card, 'run_search_history');

        expect(JSON.stringify(viewButton)).toContain("path: 'retention-targets/search-history'");
        expect(JSON.stringify(runButton)).toContain('/api/admin/privacy/manual-runs/retention:search-history/preview');
        expect(String(runButton?.props?.disabled)).toContain('_local.hasChanges');
        expect(findById(card, 'search_history_target_list_row')?.if).toContain("privacyPreview?.data?.retention?.['search-history']");
    });

    /**
     * @scenario case=admin_card_layout
     *
     * @effects admin_card_i18n_keys_in_ko_en_ja
     */
    it('카드가 쓰는 번역 키가 ko·en 과 일본어 언어팩에 모두 있다', () => {
        const root = repoRoot();
        const bundles = [
            path.join(root, 'templates/_bundled/sirsoft-admin_basic/lang/partial/ko/admin.json'),
            path.join(root, 'templates/_bundled/sirsoft-admin_basic/lang/partial/en/admin.json'),
            path.join(root, 'lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json'),
        ].map((file) => JSON.parse(fs.readFileSync(file, 'utf-8')));

        const keys = translationKeys(card).filter((key) => key.startsWith('admin.'));
        expect(keys).toContain('admin.settings.privacy.search_history.title');
        expect(keys).toContain('admin.settings.privacy.targets.rows.search_history');

        for (const bundle of bundles) {
            for (const key of [...keys, 'admin.settings.privacy.targets.title.search_history']) {
                expect(typeof readPath({ admin: bundle.admin ?? bundle }, key), key).toBe('string');
            }
        }
    });
});
