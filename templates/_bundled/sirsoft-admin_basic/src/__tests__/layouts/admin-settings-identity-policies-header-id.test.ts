// e2e:allow 노드 id 이름 바꾸기만 하는 정적 레이아웃 계약 — 화면 DOM id 중복 0 은 코어 크롤 E2E(all-routes-dom-id-unique.spec.ts)와 Chrome MCP 실측이 잰다
/**
 * @file admin-settings-identity-policies-header-id.test.ts
 * @description 코어 환경설정 「본인인증」 탭의 정책 구획 머리 노드 id
 *
 * @scenario-manifest tests/scenarios/screen-dom-id-unique-408.yaml
 *
 * 탭 조각의 머리 `<div>` 가 `page_header` 였는데, 메인 화면의 `<page-header data-ref="page_header">` 도
 * 같은 id 를 DOM 에 출력해 탭을 연 화면에서 `#page_header` 가 둘이 됐다. 탭 머리를
 * `identity_policies_header` 로 바꾸고, 메인 화면과 탭 조각을 합친 노드 id 에 겹침이 없음을 잠근다.
 */

import { describe, it, expect } from 'vitest';
import { loadLayout } from './analyticsLayoutTestUtils';

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

/**
 * 레이아웃이 포함하는 조각(`partial`) 경로를 모은다.
 *
 * @param node 레이아웃
 * @returns 조각 상대 경로 목록(중복 제거)
 */
function partialRefs(node: Node): string[] {
  return [...new Set(JSON.stringify(node).match(/"partial":"[^"]+"/g)?.map((m) => m.slice(11, -1)) ?? [])];
}

describe('코어 환경설정 본인인증 탭 — 정책 구획 머리 노드 id', () => {
  // 메인 화면 → 본인인증 탭(_tab_identity) → 하위 구획(기본·제공자·정책·메시지) 순으로 포함된다
  const main = loadLayout('admin_settings.json');
  const identityTab = loadLayout('partials/admin_settings/_tab_identity.json');
  const sections = partialRefs(identityTab);
  const policyTab = loadLayout('partials/admin_settings/_tab_identity_policies.json');

  /**
   * @scenario screen=core_settings_identity_tab
   * @effects identity_tab_header_id_renamed
   */
  it('정책 구획 조각의 머리 노드 id 는 identity_policies_header 이고 page_header 는 없다', () => {
    expect(partialRefs(main)).toContain('partials/admin_settings/_tab_identity.json');
    expect(sections).toContain('partials/admin_settings/_tab_identity_policies.json');
    expect(nodeIds(policyTab)).toContain('identity_policies_header');
    expect(nodeIds(policyTab)).not.toContain('page_header');
  });

  /**
   * @scenario screen=core_settings_identity_tab
   * @effects main_and_tab_node_ids_unique
   */
  it('메인 화면과 본인인증 탭 조각 전부를 합친 노드 id 에 겹치는 것이 없다', () => {
    const ids = [...nodeIds(main), ...nodeIds(identityTab), ...sections.flatMap((p) => nodeIds(loadLayout(p)))];
    expect(nodeIds(main)).toContain('page_header');
    expect(ids.filter((id, i) => ids.indexOf(id) !== i)).toEqual([]);
  });
});
