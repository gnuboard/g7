// e2e:allow 관리자 설정 전용 partial(출처필터 제거·목적박스 유지)은 레이아웃 렌더 테스트로 검증, develop 출시 동작 머지 복구라 신규 E2E 불요
/**
 * 본인인증 정책 데이터소스/네비게이션의 source_identifier 형식 회귀 테스트.
 *
 * 회귀 사례: 모듈 본인인증 정책 탭에서 데이터소스가
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

function findNodes(node: any, predicate: (n: any) => boolean, results: any[] = []): any[] {
    if (!node || typeof node !== 'object') return results;
    if (Array.isArray(node)) {
        node.forEach((n) => findNodes(n, predicate, results));
        return results;
    }
    if (predicate(node)) results.push(node);
    for (const v of Object.values(node)) {
        if (v && typeof v === 'object') findNodes(v, predicate, results);
    }
    return results;
}

describe('게시판 본인인증 정책 — source_identifier 형식 회귀', () => {
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

describe('게시판 본인인증 정책 — 출처 필터 정리', () => {
    const ds = (settingsLayout.data_sources ?? []).find(
        (d: any) => d.id === 'boardIdentityPolicies'
    );

    it('데이터소스에 source_type 파라미터가 없다 (게시판 전용 고정 → 출처 필터 무의미)', () => {
        expect(ds).toBeTruthy();
        // source_identifier 게시판 고정은 유지, source_type 필터 파라미터는 제거
        expect(ds.params).not.toHaveProperty('source_type');
        expect(ds.params?.source_identifier).toBe(MODULE_IDENTIFIER);
    });

    it('데이터소스의 scope/search/purpose 필터는 유지된다 (회귀 방지)', () => {
        expect(ds.params).toHaveProperty('scope');
        expect(ds.params).toHaveProperty('search');
        expect(ds.params).toHaveProperty('purpose');
    });

    it('filter_card 에 출처(source_type) Select 가 없다', () => {
        const sourceTypeSelects = findNodes(
            identityPoliciesPartial,
            (n) => n?.name === 'Select' && n?.props?.name === 'filter.source_type'
        );
        expect(sourceTypeSelects).toHaveLength(0);
    });

    it('scope 필터 Select 와 search Input 은 유지된다 (회귀 방지)', () => {
        const scopeSelects = findNodes(
            identityPoliciesPartial,
            (n) => n?.name === 'Select' && n?.props?.name === 'filter.scope'
        );
        const searchInputs = findNodes(
            identityPoliciesPartial,
            (n) => n?.name === 'Input' && n?.props?.name === 'filter.search'
        );
        expect(scopeSelects.length).toBeGreaterThan(0);
        expect(searchInputs.length).toBeGreaterThan(0);
    });

    it('인증 목적 배지 카드(identity_purposes_card)는 유지되며 모듈 식별자로 필터한다', () => {
        // 인증 목적 배지 카드는 유지한다. 모듈이 등록한 목적이 있으면 배지 표시, 없으면 빈 안내.
        // 출처 필터 정리는 source_type Select 제거에 한정하고 카드 제거는 하지 않는다.
        const purposeCards = findNodes(
            identityPoliciesPartial,
            (n) => n?.id === 'identity_purposes_card'
        );
        expect(purposeCards).toHaveLength(1);

        // 박스의 배지 iteration 은 게시판 모듈이 등록한 목적만 필터해야 한다.
        const iterationSources = findValuesByKey(purposeCards[0], 'source');
        expect(iterationSources.some((s) => s.includes(`source_identifier === '${MODULE_IDENTIFIER}'`))).toBe(true);

        // 목적이 없을 때 빈 안내 문구 분기가 존재한다.
        const emptyNotices = findValuesByKey(purposeCards[0], 'text');
        expect(emptyNotices.some((t) => t.includes('purposes_empty'))).toBe(true);
    });

    it('레이아웃 어디에도 query.source_type 잔여 참조가 없다', () => {
        const serialized = JSON.stringify({ settingsLayout, identityPoliciesPartial });
        expect(serialized).not.toContain('query.source_type');
    });
});
