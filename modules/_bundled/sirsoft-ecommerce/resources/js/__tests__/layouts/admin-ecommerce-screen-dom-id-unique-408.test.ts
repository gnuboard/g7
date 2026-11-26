// e2e:allow 노드 id 이름 바꾸기만 하는 정적 레이아웃 계약 — 화면 DOM id 중복 0 은 코어 크롤 E2E(all-routes-dom-id-unique.spec.ts)와 Chrome MCP 실측이 잰다
/**
 * @file admin-ecommerce-screen-dom-id-unique-408.test.ts
 * @description 이커머스 관리자 화면의 노드 id 유일성
 *
 * @scenario-manifest tests/scenarios/screen-dom-id-unique-408.yaml
 *
 * 노드 id(`data-ref`)는 대부분 DOM id 로 그대로 나간다. 한 화면에 같이 그려지는 메인 레이아웃과 조각이
 * 같은 id 를 쓰면 `getElementById`·`<label for>`·확장 주입이 첫 요소에만 걸린다. 세 화면을 고쳤다.
 *  - 환경설정 「본인인증 정책」 탭 머리: `page_header` → `identity_policies_header` (메인 PageHeader 와 겹침)
 *  - 상품 등록/수정 식별코드 구획 상품코드 칸: `field_product_code` → `field_identification_product_code` (기본정보 칸과 겹침)
 *  - 무통장 입금 일괄 매칭 창의 카드 목록: `mobile_card_*` → `bulk_match_mobile_card_*` (본문 카드 목록과 겹침)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

type Node = Record<string, any>;

/**
 * 모듈 루트(module.json 기준)를 위로 훑어 찾는다.
 *
 * @returns 모듈 루트 절대경로
 */
function moduleRoot(): string {
    let current = path.dirname(fileURLToPath(import.meta.url));

    for (let depth = 0; depth < 10; depth++) {
        if (fs.existsSync(path.join(current, 'module.json'))) {
            return current;
        }
        current = path.dirname(current);
    }

    throw new Error('module.json 을 가진 모듈 루트를 찾지 못했습니다.');
}

/**
 * 관리자 레이아웃 `.json` 을 읽는다.
 *
 * @param relative `resources/layouts/admin/` 아래 상대 경로
 * @returns 파싱한 레이아웃
 */
function layout(relative: string): Node {
    return JSON.parse(fs.readFileSync(path.join(moduleRoot(), 'resources/layouts/admin', relative), 'utf-8'));
}

/**
 * 트리의 화면 노드(type·name 을 가진 객체) id 를 모두 모은다. 데이터 소스·액션 안의 id 는 DOM id 가 아니라 뺀다.
 *
 * @param node 탐색 대상
 * @param out 누적 배열
 * @returns 노드 id 목록
 */
function nodeIds(node: unknown, out: string[] = []): string[] {
    if (Array.isArray(node)) {
        node.forEach((v) => nodeIds(v, out));

        return out;
    }
    if (!node || typeof node !== 'object') return out;
    const n = node as Node;
    if (typeof n.id === 'string' && typeof n.type === 'string' && typeof n.name === 'string') out.push(n.id);
    for (const [k, v] of Object.entries(n)) {
        if (k === 'data_sources' || k === 'actions') continue;
        nodeIds(v, out);
    }

    return out;
}

/**
 * 메인 레이아웃이 포함하는 조각(`partial`) 경로를 모은다.
 *
 * @param node 메인 레이아웃
 * @returns 조각 상대 경로 목록(중복 제거)
 */
function partialRefs(node: Node): string[] {
    return [...new Set(JSON.stringify(node).match(/"partial":"[^"]+"/g)?.map((m) => m.slice(11, -1)) ?? [])];
}

/**
 * 목록에서 두 번 이상 나온 값을 돌려준다.
 *
 * @param ids id 목록
 * @returns 겹친 id 목록
 */
function duplicates(ids: string[]): string[] {
    return [...new Set(ids.filter((id, i) => ids.indexOf(id) !== i))];
}

describe('환경설정 본인인증 정책 탭 — 머리 노드 id', () => {
    const main = layout('admin_ecommerce_settings.json');
    const tab = layout('partials/admin_ecommerce_settings/_tab_identity_policies.json');

    /**
     * @scenario screen=ecommerce_settings_identity_tab
     * @effects identity_tab_header_id_renamed
     */
    it('탭 조각의 머리 노드 id 는 identity_policies_header 이고 page_header 는 없다', () => {
        expect(nodeIds(tab)).toContain('identity_policies_header');
        expect(nodeIds(tab)).not.toContain('page_header');
    });

    /**
     * @scenario screen=ecommerce_settings_identity_tab
     * @effects main_and_partial_node_ids_unique
     */
    it('메인 화면과 탭 조각을 합친 노드 id 에 겹치는 것이 없다', () => {
        expect(nodeIds(main)).toContain('page_header');
        expect(duplicates([...nodeIds(main), ...nodeIds(tab)])).toEqual([]);
    });
});

describe('상품 등록/수정 — 식별코드 상품코드 칸 id', () => {
    const main = layout('admin_ecommerce_product_form.json');
    const partials = partialRefs(main);

    /**
     * @scenario screen=product_form
     * @effects sibling_partial_id_renamed
     */
    it('식별코드 조각은 field_identification_product_code, 기본정보 조각은 field_product_code 를 쓴다', () => {
        expect(partials).toContain('partials/admin_ecommerce_product_form/_partial_identification_codes.json');
        const identification = nodeIds(layout('partials/admin_ecommerce_product_form/_partial_identification_codes.json'));
        const basicInfo = nodeIds(layout('partials/admin_ecommerce_product_form/_partial_basic_info.json'));
        expect(identification).toContain('field_identification_product_code');
        expect(identification).not.toContain('field_product_code');
        expect(basicInfo).toContain('field_product_code');
    });

    /**
     * @scenario screen=product_form
     * @effects main_and_partial_node_ids_unique
     */
    it('메인 화면과 포함 조각 전부를 합친 노드 id 에 겹치는 것이 없다', () => {
        const ids = [...nodeIds(main), ...partials.flatMap((p) => nodeIds(layout(p)))];
        expect(duplicates(ids)).toEqual([]);
    });
});

describe('무통장 입금 — 일괄 매칭 창 카드 목록 id', () => {
    const main = layout('admin_ecommerce_deposit_index.json');
    const bulk = layout('partials/admin_ecommerce_deposit_index/_modal_bulk_match.json');
    const renamed = ['mobile_card_list', 'mobile_card_1', 'mobile_card_2'];

    /**
     * @scenario screen=deposit_index
     * @effects sibling_partial_id_renamed
     */
    it('창 쪽은 bulk_match_ 접두를 붙이고, 본문 쪽은 원래 이름을 유지한다', () => {
        expect(partialRefs(main)).toContain('partials/admin_ecommerce_deposit_index/_modal_bulk_match.json');
        for (const id of renamed) {
            expect(nodeIds(main)).toContain(id);
            expect(nodeIds(bulk)).toContain(`bulk_match_${id}`);
            expect(nodeIds(bulk)).not.toContain(id);
        }
    });

    /**
     * @scenario screen=deposit_index
     * @effects main_and_partial_node_ids_unique
     */
    it('본문과 일괄 매칭 창을 합친 노드 id 에 겹치는 것이 없다 (창이 열린 화면)', () => {
        expect(duplicates([...nodeIds(main), ...nodeIds(bulk)])).toEqual([]);
    });
});
