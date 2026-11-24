// e2e:allow 노드 id 이름 바꾸기만 하는 정적 레이아웃 계약 — 화면 DOM id 중복 0 은 코어 크롤 E2E(all-routes-dom-id-unique.spec.ts)와 Chrome MCP 실측이 잰다
/**
 * @file admin-board-identity-policies-header-id.test.ts
 * @description 게시판 환경설정 「본인인증 정책」 탭 머리 노드 id (#408)
 *
 * @scenario-manifest tests/scenarios/screen-dom-id-unique-408.yaml
 *
 * 탭 조각의 머리 `<div>` 가 `page_header` 였는데, 메인 화면의 `<page-header data-ref="page_header">` 도
 * 같은 id 를 DOM 에 출력해 탭을 연 화면에서 `#page_header` 가 둘이 됐다. 탭 머리를
 * `identity_policies_header` 로 바꾸고, 메인 화면과 탭 조각을 합친 노드 id 에 겹침이 없음을 잠근다.
 */

import { describe, it, expect } from 'vitest';
import settingsLayout from '../../../layouts/admin/admin_board_settings.json';
import policyTab from '../../../layouts/admin/partials/admin_board_settings/_tab_identity_policies.json';

type Node = Record<string, any>;

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

describe('게시판 본인인증 정책 탭 — 머리 노드 id', () => {
    /**
     * @scenario screen=board_settings_identity_tab
     * @effects identity_tab_header_id_renamed
     */
    it('탭 조각의 머리 노드 id 는 identity_policies_header 이고 page_header 는 없다', () => {
        const ids = nodeIds(policyTab);
        expect(ids).toContain('identity_policies_header');
        expect(ids).not.toContain('page_header');
    });

    /**
     * @scenario screen=board_settings_identity_tab
     * @effects main_and_tab_node_ids_unique
     */
    it('메인 화면과 탭 조각을 합친 노드 id 에 겹치는 것이 없다', () => {
        const ids = [...nodeIds(settingsLayout), ...nodeIds(policyTab)];
        const dups = ids.filter((id, i) => ids.indexOf(id) !== i);
        expect(nodeIds(settingsLayout)).toContain('page_header');
        expect(dups).toEqual([]);
    });
});
