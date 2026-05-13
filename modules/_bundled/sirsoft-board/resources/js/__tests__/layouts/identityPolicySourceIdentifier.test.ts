/**
 * 본인인증 정책 데이터소스/네비게이션의 source_identifier 형식 회귀 테스트.
 *
 * 회귀 사례 (#297): 모듈 본인인증 정책 탭에서 데이터소스가
 *   `source_identifier: "module:sirsoft-board"` (잘못된 접두사 포함) 으로 필터하여
 *   DB 의 `source_identifier='sirsoft-board'` (모듈 식별자만 저장) 와 매칭되지 않아
 *   정책 목록이 항상 비어있던 문제.
 */

import { describe, it, expect } from 'vitest';

const settingsLayout = require('../../../layouts/admin/admin_board_settings.json');
const identityPoliciesPartial = require('../../../layouts/admin/partials/admin_board_settings/_tab_identity_policies.json');

const MODULE_IDENTIFIER = 'sirsoft-board';
const FORBIDDEN_PREFIX_VALUE = `module:${MODULE_IDENTIFIER}`;

function findValuesByKey(node: any, targetKey: string, results: string[] = []): string[] {
    if (!node || typeof node !== 'object') return results;
    if (Array.isArray(node)) {
        node.forEach((n) => findValuesByKey(n, targetKey, results));
        return results;
    }
    for (const [k, v] of Object.entries(node)) {
        if (k === targetKey && typeof v === 'string') results.push(v);
        if (v && typeof v === 'object') findValuesByKey(v, targetKey, results);
    }
    return results;
}

describe('게시판 본인인증 정책 — source_identifier 형식 회귀 (#297)', () => {
    it('데이터소스 source_identifier 는 모듈 식별자만 사용 (module: 접두사 금지)', () => {
        const ds = (settingsLayout.data_sources ?? []).find(
            (d: any) => d.id === 'boardIdentityPolicies'
        );
        // 데이터소스가 정의되어 있다면 형식 검증
        if (ds) {
            expect(ds.params?.source_identifier).toBe(MODULE_IDENTIFIER);
        }
    });

    it('partial 내 모든 source_identifier 등장은 모듈 식별자 형식만 허용', () => {
        const all = [
            ...findValuesByKey(settingsLayout, 'source_identifier'),
            ...findValuesByKey(identityPoliciesPartial, 'source_identifier'),
        ];
        expect(all.length).toBeGreaterThan(0);
        for (const v of all) {
            expect(v).toBe(MODULE_IDENTIFIER);
        }
        expect(JSON.stringify({ settingsLayout, identityPoliciesPartial }))
            .not.toContain(FORBIDDEN_PREFIX_VALUE);
    });
});
