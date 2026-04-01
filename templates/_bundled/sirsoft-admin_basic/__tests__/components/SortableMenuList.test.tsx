/**
 * @file SortableMenuList.test.tsx
 * @description SortableMenuList 헬퍼 함수 테스트 — flattened tree + 크로스 depth 이동 + 순환 참조 방지
 *
 * 테스트 대상:
 * - flattenTree: 트리 → 평탄 배열 변환 (확장 상태 반영)
 * - removeItemFromTree: 트리에서 아이템 제거
 * - insertItemIntoParent: 특정 부모 아래 index 기반 삽입
 * - reorderInParent: 같은 부모 내 순서 변경
 * - collectDescendantIds: 자손 ID 수집 (순환 참조 방지)
 * - generateOrderData: 트리 → API 전송용 order 데이터
 * - MenuOrderData 인터페이스 (moved_items optional)
 */

import { describe, it, expect } from 'vitest';
import {
  MenuItem,
  MenuOrderData,
  flattenTree,
  findItemById,
  collectDescendantIds,
  removeItemFromTree,
  insertItemIntoParent,
  reorderInParent,
  generateOrderData,
} from '../../src/components/composite/SortableMenuList';

// 테스트용 메뉴 데이터 생성 헬퍼
function createMenuItem(overrides: Partial<MenuItem> & { id: number }): MenuItem {
  return {
    name: `Menu ${overrides.id}`,
    slug: `menu-${overrides.id}`,
    url: `/menu-${overrides.id}`,
    icon: 'fas fa-folder',
    order: 1,
    is_active: true,
    parent_id: null,
    module_id: null,
    children: [],
    ...overrides,
  };
}

// 공통 샘플 트리
const sampleTree: MenuItem[] = [
  createMenuItem({
    id: 1,
    children: [
      createMenuItem({ id: 11, parent_id: 1 }),
      createMenuItem({ id: 12, parent_id: 1 }),
    ],
  }),
  createMenuItem({
    id: 2,
    children: [
      createMenuItem({ id: 21, parent_id: 2 }),
    ],
  }),
  createMenuItem({ id: 3 }),
];

describe('SortableMenuList 헬퍼 함수', () => {
  describe('flattenTree', () => {
    it('확장 없이 최상위만 반환한다', () => {
      const result = flattenTree(sampleTree, new Set());
      expect(result).toHaveLength(3);
      expect(result.map((f) => f.item.id)).toEqual([1, 2, 3]);
      expect(result.every((f) => f.depth === 0)).toBe(true);
    });

    it('확장된 노드의 자식을 포함한다', () => {
      const result = flattenTree(sampleTree, new Set([1]));
      expect(result).toHaveLength(5);
      expect(result.map((f) => f.item.id)).toEqual([1, 11, 12, 2, 3]);
      expect(result[1].depth).toBe(1);
      expect(result[1].parentId).toBe(1);
    });

    it('모든 노드 확장 시 전체 트리를 반환한다', () => {
      const result = flattenTree(sampleTree, new Set([1, 2]));
      expect(result).toHaveLength(6);
      expect(result.map((f) => f.item.id)).toEqual([1, 11, 12, 2, 21, 3]);
    });

    it('3단계 트리를 올바르게 평탄화한다', () => {
      const deepTree: MenuItem[] = [
        createMenuItem({
          id: 1,
          children: [
            createMenuItem({
              id: 11,
              parent_id: 1,
              children: [
                createMenuItem({ id: 111, parent_id: 11 }),
              ],
            }),
          ],
        }),
      ];
      const result = flattenTree(deepTree, new Set([1, 11]));
      expect(result).toHaveLength(3);
      expect(result[2].depth).toBe(2);
      expect(result[2].parentId).toBe(11);
    });
  });

  describe('findItemById', () => {
    it('최상위 아이템을 찾는다', () => {
      const found = findItemById(sampleTree, 2);
      expect(found).toBeTruthy();
      expect(found!.id).toBe(2);
    });

    it('자식 아이템을 찾는다', () => {
      const found = findItemById(sampleTree, 21);
      expect(found).toBeTruthy();
      expect(found!.id).toBe(21);
    });

    it('존재하지 않는 ID는 null을 반환한다', () => {
      expect(findItemById(sampleTree, 999)).toBeNull();
    });
  });

  describe('removeItemFromTree', () => {
    it('최상위 아이템을 제거한다', () => {
      const result = removeItemFromTree(sampleTree, 3);
      expect(result).toHaveLength(2);
      expect(result.find((i) => i.id === 3)).toBeUndefined();
    });

    it('자식 아이템을 제거한다', () => {
      const result = removeItemFromTree(sampleTree, 12);
      const parent = result.find((i) => i.id === 1)!;
      expect(parent.children).toHaveLength(1);
      expect(parent.children[0].id).toBe(11);
    });
  });

  describe('insertItemIntoParent', () => {
    it('최상위에 아이템을 삽입한다 (index 기반)', () => {
      const newItem = createMenuItem({ id: 99 });
      const result = insertItemIntoParent(sampleTree, newItem, null, 1);
      expect(result).toHaveLength(4);
      expect(result[1].id).toBe(99);
    });

    it('자식 레벨에 아이템을 삽입한다', () => {
      const newItem = createMenuItem({ id: 99, parent_id: 1 });
      const result = insertItemIntoParent(sampleTree, newItem, 1, 1);
      const parent = result.find((i) => i.id === 1)!;
      expect(parent.children).toHaveLength(3);
      expect(parent.children[1].id).toBe(99);
    });

    it('끝에 삽입한다 (index = children.length)', () => {
      const newItem = createMenuItem({ id: 99, parent_id: 2 });
      const result = insertItemIntoParent(sampleTree, newItem, 2, 1);
      const parent = result.find((i) => i.id === 2)!;
      expect(parent.children).toHaveLength(2);
      expect(parent.children[1].id).toBe(99);
    });
  });

  describe('reorderInParent', () => {
    it('최상위에서 순서를 변경한다', () => {
      const result = reorderInParent(sampleTree, null, 3, 1);
      expect(result[0].id).toBe(3);
      expect(result[1].id).toBe(1);
      expect(result[2].id).toBe(2);
    });

    it('자식 레벨에서 순서를 변경한다', () => {
      const result = reorderInParent(sampleTree, 1, 12, 11);
      const parent = result.find((i) => i.id === 1)!;
      expect(parent.children[0].id).toBe(12);
      expect(parent.children[1].id).toBe(11);
    });
  });

  describe('collectDescendantIds', () => {
    it('직계 자식 ID를 수집한다', () => {
      const item = sampleTree[0]; // id: 1, children: [11, 12]
      const ids = collectDescendantIds(item);
      expect(ids).toContain(11);
      expect(ids).toContain(12);
    });

    it('3단계 자손까지 수집한다', () => {
      const deepTree = createMenuItem({
        id: 1,
        children: [
          createMenuItem({
            id: 11,
            parent_id: 1,
            children: [
              createMenuItem({ id: 111, parent_id: 11 }),
            ],
          }),
        ],
      });
      const ids = collectDescendantIds(deepTree);
      expect(ids).toEqual([11, 111]);
    });

    it('자식이 없으면 빈 배열을 반환한다', () => {
      const item = createMenuItem({ id: 3 });
      expect(collectDescendantIds(item)).toEqual([]);
    });
  });

  describe('순환 참조 방지', () => {
    it('부모를 자식 아래로 이동 시 자손 ID에 포함되어 차단', () => {
      const parent = sampleTree[0]; // id: 1, children: [11, 12]
      const descendantIds = collectDescendantIds(parent);
      expect(descendantIds.includes(11)).toBe(true);
    });

    it('같은 depth 형제로 이동은 허용 (자손이 아님)', () => {
      const item2 = sampleTree[1]; // id: 2
      const descendantIds = collectDescendantIds(item2);
      expect(descendantIds.includes(3)).toBe(false);
    });
  });

  describe('generateOrderData', () => {
    it('parent_menus와 child_menus를 올바르게 생성한다', () => {
      const orderData = generateOrderData(sampleTree);

      expect(orderData.parent_menus).toEqual([
        { id: 1, order: 1 },
        { id: 2, order: 2 },
        { id: 3, order: 3 },
      ]);

      expect(orderData.child_menus[1]).toEqual([
        { id: 11, order: 1 },
        { id: 12, order: 2 },
      ]);

      expect(orderData.child_menus[2]).toEqual([
        { id: 21, order: 1 },
      ]);
    });

    it('자식이 없는 최상위 메뉴는 child_menus에 포함되지 않는다', () => {
      const orderData = generateOrderData(sampleTree);
      expect(orderData.child_menus[3]).toBeUndefined();
    });

    it('3단계 자식도 child_menus에 포함된다 (재귀)', () => {
      const deepTree: MenuItem[] = [
        createMenuItem({
          id: 1,
          children: [
            createMenuItem({
              id: 11,
              parent_id: 1,
              children: [
                createMenuItem({ id: 111, parent_id: 11 }),
                createMenuItem({ id: 112, parent_id: 11 }),
              ],
            }),
          ],
        }),
      ];

      const orderData = generateOrderData(deepTree);
      expect(orderData.child_menus[11]).toEqual([
        { id: 111, order: 1 },
        { id: 112, order: 2 },
      ]);
    });
  });

  describe('크로스 depth 이동 시나리오', () => {
    it('자식→최상위 이동 (아래→위 드래그): over 앞에 삽입', () => {
      // 시나리오: id:11 (parent:1의 자식) → 최상위 id:2 앞으로 이동
      // flat 배열: [1(idx0), 11(idx1), 12(idx2), 2(idx3), 3(idx4)] (모두 확장)
      // active(11) flatIdx=1, over(2) flatIdx=3 → activeIdx < overIdx → 아래로 드래그 → over 뒤 삽입
      const activeItem = sampleTree[0].children[0]; // id: 11, parent_id: 1

      let newItems = removeItemFromTree(sampleTree, activeItem.id);
      // 아래로 드래그 (activeIdx < overIdx) → over 뒤에 삽입
      const overIndex = newItems.findIndex((i) => i.id === 2);
      const movedItem = { ...activeItem, parent_id: null };
      newItems = insertItemIntoParent(newItems, movedItem, null, overIndex + 1);

      const orderData = generateOrderData(newItems);
      orderData.moved_items = [{ id: activeItem.id, new_parent_id: null }];

      expect(newItems.map((i) => i.id)).toEqual([1, 2, 11, 3]);
      const parent1 = newItems.find((i) => i.id === 1)!;
      expect(parent1.children.map((c) => c.id)).toEqual([12]);
      expect(orderData.moved_items).toEqual([{ id: 11, new_parent_id: null }]);
    });

    it('자식→최상위 이동 (위로 드래그): over 앞에 삽입', () => {
      // 시나리오: id:21 (parent:2의 자식) → 최상위 id:2 앞으로 이동
      // flat 배열: [1(idx0), 2(idx1), 21(idx2), 3(idx3)] (id:2만 확장)
      // active(21) flatIdx=2, over(2) flatIdx=1 → activeIdx > overIdx → 위로 드래그 → over 앞 삽입
      const activeItem = sampleTree[1].children[0]; // id: 21, parent_id: 2

      let newItems = removeItemFromTree(sampleTree, activeItem.id);
      // 위로 드래그 (activeIdx > overIdx) → over 앞에 삽입 (insertIndex += 0)
      const overIndex = newItems.findIndex((i) => i.id === 2);
      const movedItem = { ...activeItem, parent_id: null };
      newItems = insertItemIntoParent(newItems, movedItem, null, overIndex);

      expect(newItems.map((i) => i.id)).toEqual([1, 21, 2, 3]);
      const parent2 = newItems.find((i) => i.id === 2)!;
      expect(parent2.children).toHaveLength(0);
    });

    it('최상위→자식 이동: remove + insert + moved_items 생성', () => {
      const activeItem = sampleTree[2]; // id: 3, parent_id: null

      let newItems = removeItemFromTree(sampleTree, activeItem.id);
      // over(id:21)의 위치: parent(2).children에서 index 0 → 뒤에 삽입 = index 1
      const overParent = findItemById(newItems, 2)!;
      const overIndex = overParent.children.findIndex((c) => c.id === 21);
      const movedItem = { ...activeItem, parent_id: 2 };
      newItems = insertItemIntoParent(newItems, movedItem, 2, overIndex + 1);

      const orderData = generateOrderData(newItems);
      orderData.moved_items = [{ id: activeItem.id, new_parent_id: 2 }];

      expect(newItems.map((i) => i.id)).toEqual([1, 2]);
      const parent2 = newItems.find((i) => i.id === 2)!;
      expect(parent2.children.map((c) => c.id)).toEqual([21, 3]);
      expect(orderData.moved_items).toEqual([{ id: 3, new_parent_id: 2 }]);
    });
  });

  describe('MenuOrderData 인터페이스', () => {
    it('moved_items는 optional이다', () => {
      const data: MenuOrderData = {
        parent_menus: [{ id: 1, order: 1 }],
        child_menus: {},
      };
      expect(data.moved_items).toBeUndefined();
    });

    it('moved_items를 포함할 수 있다', () => {
      const data: MenuOrderData = {
        parent_menus: [{ id: 1, order: 1 }],
        child_menus: {},
        moved_items: [{ id: 5, new_parent_id: null }],
      };
      expect(data.moved_items).toHaveLength(1);
      expect(data.moved_items![0].new_parent_id).toBeNull();
    });
  });
});
