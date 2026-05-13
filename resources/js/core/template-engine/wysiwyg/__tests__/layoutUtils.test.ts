/**
 * layoutUtils.test.ts
 *
 * G7 위지윅 레이아웃 편집기 - 레이아웃 유틸리티 테스트
 *
 * 테스트 항목:
 * 1. findComponentById - ID로 컴포넌트 찾기 (ComponentSearchResult 반환)
 * 2. getPathToComponent - 컴포넌트 경로 찾기
 * 3. findParentComponent - 부모 컴포넌트 찾기
 * 4. updateComponentInLayout - 컴포넌트 업데이트
 * 5. addComponentToLayout - 컴포넌트 추가
 * 6. removeComponentFromLayout - 컴포넌트 제거
 * 7. moveComponentInLayout - 컴포넌트 이동
 * 8. duplicateComponentInLayout - 컴포넌트 복제
 * 9. generateUniqueId - ID 생성
 * 10. flattenComponents - 컴포넌트 평탄화
 * 11. filterComponentsByType - 타입별 필터링
 * 12. filterComponentsByName - 이름별 필터링
 */

import { describe, it, expect } from 'vitest';
import {
  findComponentById,
  getPathToComponent,
  findParentComponent,
  updateComponentInLayout,
  addComponentToLayout,
  removeComponentFromLayout,
  moveComponentInLayout,
  duplicateComponentInLayout,
  generateUniqueId,
  flattenComponents,
  filterComponentsByType,
  filterComponentsByName,
  isIdDuplicate,
  collectAllIds,
  isDescendant,
  deepCloneComponent,
  calculateLayoutStats,
  findCommonAncestor,
  findComponentByPath,
  ensureComponentId,
  updateComponentByPath,
} from '../utils/layoutUtils';
import type { ComponentDefinition } from '../types/editor';

// 테스트용 샘플 컴포넌트 트리
const createSampleComponents = (): ComponentDefinition[] => [
  {
    id: 'root-1',
    type: 'layout',
    name: 'Container',
    props: { className: 'container' },
    children: [
      {
        id: 'header-1',
        type: 'composite',
        name: 'PageHeader',
        props: { title: 'Test Page' },
        children: [
          {
            id: 'title-1',
            type: 'basic',
            name: 'H1',
            text: 'Hello World',
          },
        ],
      },
      {
        id: 'content-1',
        type: 'layout',
        name: 'Grid',
        props: { cols: 2 },
        children: [
          {
            id: 'card-1',
            type: 'composite',
            name: 'Card',
            props: { title: 'Card 1' },
          },
          {
            id: 'card-2',
            type: 'composite',
            name: 'Card',
            props: { title: 'Card 2' },
          },
        ],
      },
    ],
  },
  {
    id: 'footer-1',
    type: 'basic',
    name: 'Div',
    props: { className: 'footer' },
  },
];

describe('layoutUtils', () => {
  describe('findComponentById', () => {
    it('최상위 컴포넌트를 찾을 수 있어야 함', () => {
      const components = createSampleComponents();
      const result = findComponentById(components, 'root-1');

      expect(result).not.toBeNull();
      expect(result?.component.id).toBe('root-1');
      expect(result?.component.name).toBe('Container');
      expect(result?.parentId).toBeNull();
      expect(result?.index).toBe(0);
      expect(result?.path).toEqual(['root-1']);
    });

    it('중첩된 컴포넌트를 찾을 수 있어야 함', () => {
      const components = createSampleComponents();
      const result = findComponentById(components, 'title-1');

      expect(result).not.toBeNull();
      expect(result?.component.id).toBe('title-1');
      expect(result?.component.name).toBe('H1');
      expect(result?.parentId).toBe('header-1');
      expect(result?.path).toEqual(['root-1', 'header-1', 'title-1']);
    });

    it('존재하지 않는 ID는 null을 반환해야 함', () => {
      const components = createSampleComponents();
      const result = findComponentById(components, 'non-existent');

      expect(result).toBeNull();
    });

    it('빈 배열에서는 null을 반환해야 함', () => {
      const result = findComponentById([], 'any-id');
      expect(result).toBeNull();
    });
  });

  describe('getPathToComponent', () => {
    it('컴포넌트 경로를 배열로 반환해야 함', () => {
      const components = createSampleComponents();
      const path = getPathToComponent(components, 'title-1');

      expect(path).toEqual(['root-1', 'header-1', 'title-1']);
    });

    it('최상위 컴포넌트의 경로는 자기 자신만 포함해야 함', () => {
      const components = createSampleComponents();
      const path = getPathToComponent(components, 'root-1');

      expect(path).toEqual(['root-1']);
    });

    it('존재하지 않는 컴포넌트는 빈 배열을 반환해야 함', () => {
      const components = createSampleComponents();
      const path = getPathToComponent(components, 'non-existent');

      expect(path).toEqual([]);
    });
  });

  describe('findParentComponent', () => {
    it('부모 컴포넌트를 찾을 수 있어야 함', () => {
      const components = createSampleComponents();
      const parent = findParentComponent(components, 'title-1');

      expect(parent).not.toBeNull();
      expect(parent?.id).toBe('header-1');
    });

    it('최상위 컴포넌트의 부모는 null이어야 함', () => {
      const components = createSampleComponents();
      const parent = findParentComponent(components, 'root-1');

      expect(parent).toBeNull();
    });

    it('존재하지 않는 컴포넌트의 부모는 null이어야 함', () => {
      const components = createSampleComponents();
      const parent = findParentComponent(components, 'non-existent');

      expect(parent).toBeNull();
    });
  });

  describe('updateComponentInLayout', () => {
    it('컴포넌트를 업데이트할 수 있어야 함', () => {
      const components = createSampleComponents();
      const updated = updateComponentInLayout(components, 'card-1', {
        props: { title: 'Updated Card' },
      });

      const updatedResult = findComponentById(updated, 'card-1');
      expect(updatedResult?.component.props?.title).toBe('Updated Card');
    });

    it('원본 배열을 변경하지 않아야 함 (불변성)', () => {
      const components = createSampleComponents();
      const original = findComponentById(components, 'card-1');

      updateComponentInLayout(components, 'card-1', {
        props: { title: 'Updated' },
      });

      expect(original?.component.props?.title).toBe('Card 1');
    });

    it('존재하지 않는 ID 업데이트 시 원본 그대로 반환해야 함', () => {
      const components = createSampleComponents();
      const updated = updateComponentInLayout(components, 'non-existent', {
        props: { title: 'Test' },
      });

      // 원본과 동일한 구조여야 함
      expect(JSON.stringify(updated)).toEqual(JSON.stringify(components));
    });
  });

  describe('addComponentToLayout', () => {
    it('컴포넌트를 자식으로 추가할 수 있어야 함 (inside)', () => {
      const components = createSampleComponents();
      const newComponent: ComponentDefinition = {
        id: 'new-card',
        type: 'composite',
        name: 'Card',
        props: { title: 'New Card' },
      };

      const updated = addComponentToLayout(
        components,
        newComponent,
        'content-1',
        'inside'
      );
      const parentResult = findComponentById(updated, 'content-1');

      expect(parentResult?.component.children).toHaveLength(3);
      expect(
        parentResult?.component.children?.find(
          (c) => typeof c !== 'string' && c.id === 'new-card'
        )
      ).toBeDefined();
    });

    it('첫 번째 자식으로 컴포넌트를 추가할 수 있어야 함 (first-child)', () => {
      const components = createSampleComponents();
      const newComponent: ComponentDefinition = {
        id: 'new-card',
        type: 'composite',
        name: 'Card',
        props: { title: 'New Card' },
      };

      const updated = addComponentToLayout(
        components,
        newComponent,
        'content-1',
        'first-child'
      );
      const parentResult = findComponentById(updated, 'content-1');
      const firstChild = parentResult?.component.children?.[0];

      expect(typeof firstChild !== 'string' && firstChild?.id).toBe('new-card');
    });

    it('컴포넌트 앞에 추가할 수 있어야 함 (before)', () => {
      const components = createSampleComponents();
      const newComponent: ComponentDefinition = {
        id: 'new-card',
        type: 'composite',
        name: 'Card',
        props: { title: 'New Card' },
      };

      const updated = addComponentToLayout(
        components,
        newComponent,
        'card-2',
        'before'
      );
      const parentResult = findComponentById(updated, 'content-1');

      // card-1, new-card, card-2 순서
      expect(parentResult?.component.children).toHaveLength(3);
      const children = parentResult?.component.children?.filter(
        (c) => typeof c !== 'string'
      ) as ComponentDefinition[];
      expect(children[1]?.id).toBe('new-card');
      expect(children[2]?.id).toBe('card-2');
    });

    it('컴포넌트 뒤에 추가할 수 있어야 함 (after)', () => {
      const components = createSampleComponents();
      const newComponent: ComponentDefinition = {
        id: 'new-card',
        type: 'composite',
        name: 'Card',
        props: { title: 'New Card' },
      };

      const updated = addComponentToLayout(
        components,
        newComponent,
        'card-1',
        'after'
      );
      const parentResult = findComponentById(updated, 'content-1');

      // card-1, new-card, card-2 순서
      expect(parentResult?.component.children).toHaveLength(3);
      const children = parentResult?.component.children?.filter(
        (c) => typeof c !== 'string'
      ) as ComponentDefinition[];
      expect(children[0]?.id).toBe('card-1');
      expect(children[1]?.id).toBe('new-card');
    });
  });

  describe('removeComponentFromLayout', () => {
    it('컴포넌트를 제거할 수 있어야 함', () => {
      const components = createSampleComponents();
      const updated = removeComponentFromLayout(components, 'card-1');

      const removed = findComponentById(updated, 'card-1');
      expect(removed).toBeNull();

      const parentResult = findComponentById(updated, 'content-1');
      expect(parentResult?.component.children).toHaveLength(1);
    });

    it('최상위 컴포넌트를 제거할 수 있어야 함', () => {
      const components = createSampleComponents();
      const updated = removeComponentFromLayout(components, 'footer-1');

      expect(updated).toHaveLength(1);
      const removed = findComponentById(updated, 'footer-1');
      expect(removed).toBeNull();
    });
  });

  describe('moveComponentInLayout', () => {
    it('컴포넌트를 다른 부모로 이동할 수 있어야 함', () => {
      const components = createSampleComponents();
      const updated = moveComponentInLayout(
        components,
        'card-1',
        'header-1',
        'inside'
      );

      // card-1이 header-1의 자식으로 이동
      const newParentResult = findComponentById(updated, 'header-1');
      expect(
        newParentResult?.component.children?.some(
          (c) => typeof c !== 'string' && c.id === 'card-1'
        )
      ).toBe(true);

      // content-1에서는 제거
      const oldParentResult = findComponentById(updated, 'content-1');
      expect(
        oldParentResult?.component.children?.some(
          (c) => typeof c !== 'string' && c.id === 'card-1'
        )
      ).toBe(false);
    });

    it('자기 자신 안으로 이동하면 변경 없어야 함', () => {
      const components = createSampleComponents();
      const updated = moveComponentInLayout(
        components,
        'content-1',
        'content-1',
        'inside'
      );

      expect(JSON.stringify(updated)).toEqual(JSON.stringify(components));
    });

    it('자손 컴포넌트 안으로 이동하면 변경 없어야 함', () => {
      const components = createSampleComponents();
      // root-1을 card-1 안으로 이동 시도 (card-1은 root-1의 자손)
      const updated = moveComponentInLayout(
        components,
        'root-1',
        'card-1',
        'inside'
      );

      expect(JSON.stringify(updated)).toEqual(JSON.stringify(components));
    });
  });

  describe('duplicateComponentInLayout', () => {
    it('컴포넌트를 복제할 수 있어야 함', () => {
      const components = createSampleComponents();
      const result = duplicateComponentInLayout(components, 'card-1');

      expect(result).not.toBeNull();
      expect(result?.newId).not.toBe('card-1');

      const original = findComponentById(result!.components, 'card-1');
      const duplicate = findComponentById(result!.components, result!.newId);

      expect(original).not.toBeNull();
      expect(duplicate).not.toBeNull();
      expect(duplicate?.component.props?.title).toBe(
        original?.component.props?.title
      );
    });

    it('존재하지 않는 컴포넌트 복제 시 null 반환', () => {
      const components = createSampleComponents();
      const result = duplicateComponentInLayout(components, 'non-existent');

      expect(result).toBeNull();
    });
  });

  describe('generateUniqueId', () => {
    it('고유한 ID를 생성해야 함', () => {
      const id1 = generateUniqueId('Button');
      const id2 = generateUniqueId('Button');

      expect(id1).not.toBe(id2);
      expect(id1).toMatch(/^button_/);
      expect(id2).toMatch(/^button_/);
    });

    it('컴포넌트 이름을 소문자로 변환해야 함', () => {
      const id = generateUniqueId('PageHeader');
      expect(id).toMatch(/^pageheader_/);
    });

    it('기본 접두사를 사용할 수 있어야 함', () => {
      const id = generateUniqueId();
      expect(id).toMatch(/^component_/);
    });
  });

  describe('flattenComponents', () => {
    it('모든 컴포넌트를 평탄화된 배열로 반환해야 함', () => {
      const components = createSampleComponents();
      const flattened = flattenComponents(components);

      // root-1, header-1, title-1, content-1, card-1, card-2, footer-1
      expect(flattened).toHaveLength(7);
      expect(flattened.map((c) => c.id)).toContain('title-1');
      expect(flattened.map((c) => c.id)).toContain('card-2');
    });
  });

  describe('filterComponentsByType', () => {
    it('특정 타입의 모든 컴포넌트를 찾아야 함', () => {
      const components = createSampleComponents();
      const composites = filterComponentsByType(components, 'composite');

      expect(composites).toHaveLength(3); // PageHeader, Card, Card
      expect(composites.every((c) => c.type === 'composite')).toBe(true);
    });
  });

  describe('filterComponentsByName', () => {
    it('특정 이름의 모든 컴포넌트를 찾아야 함', () => {
      const components = createSampleComponents();
      const cards = filterComponentsByName(components, 'Card');

      expect(cards).toHaveLength(2);
      expect(cards.every((c) => c.name === 'Card')).toBe(true);
    });
  });

  describe('isIdDuplicate', () => {
    it('중복 ID를 감지해야 함', () => {
      const components = createSampleComponents();
      expect(isIdDuplicate(components, 'card-1')).toBe(true);
      expect(isIdDuplicate(components, 'non-existent')).toBe(false);
    });
  });

  describe('collectAllIds', () => {
    it('모든 컴포넌트 ID를 수집해야 함', () => {
      const components = createSampleComponents();
      const ids = collectAllIds(components);

      expect(ids).toHaveLength(7);
      expect(ids).toContain('root-1');
      expect(ids).toContain('title-1');
      expect(ids).toContain('footer-1');
    });
  });

  describe('isDescendant', () => {
    it('자손 관계를 정확히 판별해야 함', () => {
      const components = createSampleComponents();

      expect(isDescendant(components, 'root-1', 'card-1')).toBe(true);
      expect(isDescendant(components, 'content-1', 'card-1')).toBe(true);
      expect(isDescendant(components, 'header-1', 'card-1')).toBe(false);
      expect(isDescendant(components, 'footer-1', 'card-1')).toBe(false);
    });
  });

  describe('deepCloneComponent', () => {
    it('컴포넌트를 깊은 복사해야 함', () => {
      const original: ComponentDefinition = {
        id: 'test-1',
        type: 'composite',
        name: 'Card',
        props: { title: 'Test' },
        children: [
          {
            id: 'child-1',
            type: 'basic',
            name: 'Button',
            props: { label: 'Click' },
          },
        ],
      };

      const cloned = deepCloneComponent(original);

      // ID가 다름
      expect(cloned.id).not.toBe(original.id);
      expect(cloned.children?.[0]).not.toBeUndefined();
      const firstChild = cloned.children?.[0];
      expect(typeof firstChild !== 'string' && firstChild?.id).not.toBe(
        'child-1'
      );

      // 구조는 동일
      expect(cloned.name).toBe(original.name);
      expect(cloned.props?.title).toBe(original.props?.title);
    });
  });

  describe('findCommonAncestor', () => {
    it('공통 조상을 찾아야 함', () => {
      const components = createSampleComponents();

      // card-1과 card-2의 공통 조상은 content-1
      const ancestor = findCommonAncestor(components, 'card-1', 'card-2');
      expect(ancestor).toBe('content-1');

      // card-1과 title-1의 공통 조상은 root-1
      const ancestor2 = findCommonAncestor(components, 'card-1', 'title-1');
      expect(ancestor2).toBe('root-1');
    });
  });

  describe('calculateLayoutStats', () => {
    it('레이아웃 통계를 계산해야 함', () => {
      const components = createSampleComponents();
      const stats = calculateLayoutStats(components);

      expect(stats.totalComponents).toBe(7);
      expect(stats.maxDepth).toBe(2);
      expect(stats.byType.basic).toBe(2); // H1, Div
      expect(stats.byType.composite).toBe(3); // PageHeader, Card, Card
      expect(stats.byType.layout).toBe(2); // Container, Grid
    });
  });

  // =========================================================================
  // 인덱스 경로 기반 유틸리티 테스트 (WYSIWYG 편집기용)
  // =========================================================================

  describe('findComponentByPath', () => {
    it('최상위 컴포넌트를 인덱스 경로로 찾을 수 있어야 함', () => {
      const components = createSampleComponents();
      const result = findComponentByPath(components, '0');

      expect(result).not.toBeNull();
      expect(result?.id).toBe('root-1');
      expect(result?.name).toBe('Container');
    });

    it('중첩된 컴포넌트를 인덱스 경로로 찾을 수 있어야 함', () => {
      const components = createSampleComponents();
      // root-1 > header-1 > title-1
      const result = findComponentByPath(components, '0.children.0.children.0');

      expect(result).not.toBeNull();
      expect(result?.id).toBe('title-1');
      expect(result?.name).toBe('H1');
    });

    it('두 번째 레벨 자식을 인덱스 경로로 찾을 수 있어야 함', () => {
      const components = createSampleComponents();
      // root-1 > content-1 > card-2
      const result = findComponentByPath(components, '0.children.1.children.1');

      expect(result).not.toBeNull();
      expect(result?.id).toBe('card-2');
      expect(result?.name).toBe('Card');
    });

    it('두 번째 최상위 컴포넌트를 찾을 수 있어야 함', () => {
      const components = createSampleComponents();
      const result = findComponentByPath(components, '1');

      expect(result).not.toBeNull();
      expect(result?.id).toBe('footer-1');
      expect(result?.name).toBe('Div');
    });

    it('존재하지 않는 인덱스는 null을 반환해야 함', () => {
      const components = createSampleComponents();
      expect(findComponentByPath(components, '99')).toBeNull();
      expect(findComponentByPath(components, '0.children.99')).toBeNull();
    });

    it('잘못된 경로 형식은 null을 반환해야 함', () => {
      const components = createSampleComponents();
      expect(findComponentByPath(components, '')).toBeNull();
      expect(findComponentByPath(components, 'invalid')).toBeNull();
    });

    it('children이 없는 컴포넌트의 children 접근 시 null 반환', () => {
      const components = createSampleComponents();
      // footer-1은 children이 없음
      expect(findComponentByPath(components, '1.children.0')).toBeNull();
    });
  });

  describe('updateComponentByPath', () => {
    it('인덱스 경로로 컴포넌트를 업데이트할 수 있어야 함', () => {
      const components = createSampleComponents();
      const updated = updateComponentByPath(components, '0.children.1.children.0', {
        props: { title: 'Updated Card' },
      });

      const result = findComponentByPath(updated, '0.children.1.children.0');
      expect(result?.props?.title).toBe('Updated Card');
    });

    it('최상위 컴포넌트를 업데이트할 수 있어야 함', () => {
      const components = createSampleComponents();
      const updated = updateComponentByPath(components, '0', {
        props: { className: 'updated-container' },
      });

      const result = findComponentByPath(updated, '0');
      expect(result?.props?.className).toBe('updated-container');
    });

    it('원본 배열을 변경하지 않아야 함 (불변성)', () => {
      const components = createSampleComponents();
      const originalTitle = findComponentByPath(components, '0.children.1.children.0')?.props?.title;

      updateComponentByPath(components, '0.children.1.children.0', {
        props: { title: 'Updated' },
      });

      const afterUpdate = findComponentByPath(components, '0.children.1.children.0');
      expect(afterUpdate?.props?.title).toBe(originalTitle);
    });

    it('존재하지 않는 경로 업데이트 시 원본 그대로 반환해야 함', () => {
      const components = createSampleComponents();
      const updated = updateComponentByPath(components, '99', {
        props: { title: 'Test' },
      });

      expect(JSON.stringify(updated)).toEqual(JSON.stringify(components));
    });
  });

  describe('ensureComponentId', () => {
    // ID가 없는 컴포넌트를 가진 테스트용 데이터
    const createComponentsWithoutId = (): ComponentDefinition[] => [
      {
        id: 'root-1',
        type: 'layout',
        name: 'Container',
        props: {},
        children: [
          {
            id: '', // ID 없음
            type: 'basic',
            name: 'Button',
            props: { label: 'Click' },
          } as ComponentDefinition,
          {
            id: 'card-1',
            type: 'composite',
            name: 'Card',
            props: { title: 'Card' },
          },
        ],
      },
    ];

    it('ID가 없는 컴포넌트에 ID를 부여해야 함', () => {
      const components = createComponentsWithoutId();
      const result = ensureComponentId(components, '0.children.0');

      expect(result).not.toBeNull();
      expect(result?.id).toMatch(/^button_/); // generateUniqueId 패턴
      expect(result?.id).not.toBe('');

      // 업데이트된 컴포넌트 확인
      const updated = findComponentByPath(result!.components, '0.children.0');
      expect(updated?.id).toBe(result?.id);
    });

    it('이미 ID가 있는 컴포넌트는 기존 ID를 유지해야 함', () => {
      const components = createComponentsWithoutId();
      const result = ensureComponentId(components, '0.children.1');

      expect(result).not.toBeNull();
      expect(result?.id).toBe('card-1');

      // 컴포넌트 배열이 동일해야 함 (변경 없음)
      expect(result?.components).toBe(components);
    });

    it('존재하지 않는 경로는 null을 반환해야 함', () => {
      const components = createComponentsWithoutId();
      const result = ensureComponentId(components, '99');

      expect(result).toBeNull();
    });

    it('원본 배열을 변경하지 않아야 함 (불변성)', () => {
      const components = createComponentsWithoutId();
      const originalId = findComponentByPath(components, '0.children.0')?.id;

      ensureComponentId(components, '0.children.0');

      const afterEnsure = findComponentByPath(components, '0.children.0');
      expect(afterEnsure?.id).toBe(originalId);
    });
  });
});
