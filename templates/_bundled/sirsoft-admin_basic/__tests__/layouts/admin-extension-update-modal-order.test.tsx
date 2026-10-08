/**
 * @file admin-extension-update-modal-order.test.tsx
 * @description 업데이트 창의 「검색 인덱스」 상자가 「레이아웃 처리 방식」 묶음 밖에 있다 (7.1.0 전수 실측 #736 O-F8)
 *
 * 모듈·플러그인 업데이트 창에서 검색 인덱스 재생성 상자가 「레이아웃 처리 방식」 제목과 그 선택지(덮어쓰기·남기기)
 * 사이에 끼어 있어, 제목 바로 아래에 엉뚱한 상자가 오고 선택지가 그 아래로 밀렸다. 두 묶음은 서로 다른 결정이므로
 * 검색 인덱스 상자는 레이아웃 처리 방식 묶음 다음의 형제로 둔다.
 */

import { describe, it, expect } from 'vitest';
import moduleUpdate from '../../layouts/partials/admin_module_list/_modal_update.json';
import pluginUpdate from '../../layouts/partials/admin_plugin_list/_modal_update.json';

type Json = Record<string, any>;

/** 조건을 만족하는 첫 노드와 그 조상 경로를 찾는다. */
function findWithAncestors(tree: unknown, pred: (n: Json) => boolean, ancestors: Json[] = []): Json[] | null {
  if (Array.isArray(tree)) {
    for (const child of tree) {
      const found = findWithAncestors(child, pred, ancestors);
      if (found) return found;
    }
    return null;
  }
  if (tree && typeof tree === 'object') {
    const node = tree as Json;
    if (pred(node)) return [...ancestors, node];
    for (const value of Object.values(node)) {
      const found = findWithAncestors(value, pred, typeof node.name === 'string' ? [...ancestors, node] : ancestors);
      if (found) return found;
    }
  }
  return null;
}

/** 검색 인덱스 상자와 레이아웃 처리 방식 묶음이 서로를 품지 않는 형제인지 검사한다. */
function expectSiblingBlocks(layout: unknown, type: 'modules' | 'plugins', indexId: string): void {
  const indexPath = findWithAncestors(layout, (n) => n.id === indexId);
  expect(indexPath, `${indexId} 상자가 있어야 합니다`).not.toBeNull();
  const indexAncestors = indexPath!.slice(0, -1);

  const titlePath = findWithAncestors(layout, (n) => n.text === `$t:admin.${type}.modals.layout_strategy_title`);
  expect(titlePath, '레이아웃 처리 방식 제목이 있어야 합니다').not.toBeNull();
  const strategyBox = titlePath![titlePath!.length - 2];

  // 레이아웃 처리 방식 묶음(제목의 부모) 안에 검색 인덱스 상자가 들어 있으면 안 된다
  expect(indexAncestors.includes(strategyBox)).toBe(false);
  // 같은 부모 아래에서 레이아웃 처리 방식 묶음 뒤에 온다
  const parent = indexAncestors[indexAncestors.length - 1];
  const siblings: Json[] = parent.children ?? [];
  const strategyIndex = siblings.indexOf(strategyBox);
  const searchIndex = siblings.indexOf(indexPath![indexPath!.length - 1]);
  expect(strategyIndex).toBeGreaterThanOrEqual(0);
  expect(searchIndex).toBeGreaterThan(strategyIndex);
}

describe('업데이트 창 묶음 배치', () => {
  /**
   * @scenario surface=module_update
   * @effects search_index_box_outside_layout_strategy
   */
  it('모듈 업데이트 창: 검색 인덱스 상자는 레이아웃 처리 방식 묶음 다음 형제다', () => {
    expectSiblingBlocks(moduleUpdate, 'modules', 'module_rebuild_search_index');
  });

  /**
   * @scenario surface=plugin_update
   * @effects search_index_box_outside_layout_strategy
   */
  it('플러그인 업데이트 창: 검색 인덱스 상자는 레이아웃 처리 방식 묶음 다음 형제다', () => {
    expectSiblingBlocks(pluginUpdate, 'plugins', 'plugin_rebuild_search_index');
  });
});
