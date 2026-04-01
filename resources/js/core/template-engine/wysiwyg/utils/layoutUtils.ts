/**
 * layoutUtils.ts
 *
 * G7 위지윅 레이아웃 편집기의 레이아웃 조작 유틸리티
 *
 * 역할:
 * - 컴포넌트 트리 탐색 및 조회
 * - 컴포넌트 CRUD 연산
 * - 컴포넌트 이동/복제
 * - 고유 ID 생성
 */

import type {
  ComponentDefinition,
  ComponentSearchResult,
  DropPosition,
  LayoutTreeNodeInfo,
} from '../types/editor';

// ============================================================================
// ID 생성
// ============================================================================

/**
 * 고유 ID 생성
 *
 * @param prefix ID 접두사 (컴포넌트 이름 권장)
 * @returns 고유 ID
 */
export function generateUniqueId(prefix: string = 'component'): string {
  const timestamp = Date.now().toString(36);
  const randomPart = Math.random().toString(36).substring(2, 8);
  const normalizedPrefix = prefix.toLowerCase().replace(/[^a-z0-9]/g, '_');
  return `${normalizedPrefix}_${timestamp}_${randomPart}`;
}

/**
 * ID 중복 여부 확인
 *
 * @param components 컴포넌트 배열
 * @param id 확인할 ID
 * @returns 중복 여부
 */
export function isIdDuplicate(
  components: (ComponentDefinition | string)[],
  id: string
): boolean {
  for (const component of components) {
    if (typeof component === 'string') continue;

    if (component.id === id) {
      return true;
    }

    if (component.children) {
      if (isIdDuplicate(component.children, id)) {
        return true;
      }
    }
  }

  return false;
}

// ============================================================================
// 컴포넌트 검색
// ============================================================================

/**
 * ID로 컴포넌트 찾기
 *
 * @param components 컴포넌트 배열
 * @param id 찾을 컴포넌트 ID
 * @param parentId 부모 컴포넌트 ID (재귀용)
 * @param path 현재 경로 (재귀용)
 * @returns 검색 결과 또는 null
 */
export function findComponentById(
  components: (ComponentDefinition | string)[],
  id: string,
  parentId: string | null = null,
  path: string[] = []
): ComponentSearchResult | null {
  for (let i = 0; i < components.length; i++) {
    const component = components[i];

    if (typeof component === 'string') continue;

    if (component.id === id) {
      return {
        component,
        parentId,
        index: i,
        path: [...path, component.id],
      };
    }

    if (component.children) {
      const result = findComponentById(
        component.children,
        id,
        component.id,
        [...path, component.id]
      );
      if (result) {
        return result;
      }
    }
  }

  return null;
}

/**
 * 이름으로 컴포넌트 찾기 (첫 번째 일치)
 *
 * @param components 컴포넌트 배열
 * @param name 찾을 컴포넌트 이름
 * @returns 컴포넌트 또는 null
 */
export function findComponentByName(
  components: (ComponentDefinition | string)[],
  name: string
): ComponentDefinition | null {
  for (const component of components) {
    if (typeof component === 'string') continue;

    if (component.name === name) {
      return component;
    }

    if (component.children) {
      const result = findComponentByName(component.children, name);
      if (result) {
        return result;
      }
    }
  }

  return null;
}

/**
 * 부모 컴포넌트 찾기
 *
 * @param components 컴포넌트 배열
 * @param childId 자식 컴포넌트 ID
 * @returns 부모 컴포넌트 또는 null
 */
export function findParentComponent(
  components: (ComponentDefinition | string)[],
  childId: string
): ComponentDefinition | null {
  for (const component of components) {
    if (typeof component === 'string') continue;

    if (component.children) {
      // 자식 배열에서 찾기
      const hasChild = component.children.some(
        (c) => typeof c !== 'string' && c.id === childId
      );

      if (hasChild) {
        return component;
      }

      // 재귀적으로 찾기
      const result = findParentComponent(component.children, childId);
      if (result) {
        return result;
      }
    }
  }

  return null;
}

/**
 * 모든 컴포넌트 ID 수집
 *
 * @param components 컴포넌트 배열
 * @returns ID 배열
 */
export function collectAllIds(
  components: (ComponentDefinition | string)[]
): string[] {
  const ids: string[] = [];

  for (const component of components) {
    if (typeof component === 'string') continue;

    ids.push(component.id);

    if (component.children) {
      ids.push(...collectAllIds(component.children));
    }
  }

  return ids;
}

// ============================================================================
// 컴포넌트 추가
// ============================================================================

/**
 * 컴포넌트 추가
 *
 * @param components 원본 컴포넌트 배열
 * @param newComponent 추가할 컴포넌트
 * @param targetId 대상 컴포넌트 ID
 * @param position 추가 위치
 * @returns 새 컴포넌트 배열
 */
export function addComponentToLayout(
  components: (ComponentDefinition | string)[],
  newComponent: ComponentDefinition,
  targetId: string,
  position: DropPosition
): (ComponentDefinition | string)[] {
  // 루트 레벨에 추가하는 경우
  if (!targetId) {
    return [...components, newComponent];
  }

  return components.map((component) => {
    if (typeof component === 'string') return component;

    // 대상 컴포넌트를 찾은 경우
    if (component.id === targetId) {
      switch (position) {
        case 'before':
          // 이 레벨에서 처리할 수 없음 (부모 레벨에서 처리)
          return component;

        case 'after':
          // 이 레벨에서 처리할 수 없음 (부모 레벨에서 처리)
          return component;

        case 'inside':
        case 'last-child':
          return {
            ...component,
            children: [...(component.children || []), newComponent],
          };

        case 'first-child':
          return {
            ...component,
            children: [newComponent, ...(component.children || [])],
          };

        default:
          return component;
      }
    }

    // 자식에서 재귀적으로 처리
    if (component.children) {
      const newChildren: (ComponentDefinition | string)[] = [];

      for (let i = 0; i < component.children.length; i++) {
        const child = component.children[i];

        if (typeof child !== 'string' && child.id === targetId) {
          if (position === 'before') {
            newChildren.push(newComponent);
            newChildren.push(child);
          } else if (position === 'after') {
            newChildren.push(child);
            newChildren.push(newComponent);
          } else {
            // inside, first-child, last-child는 자식 레벨에서 처리
            newChildren.push(child);
          }
        } else {
          newChildren.push(child);
        }
      }

      // 자식들을 재귀적으로 처리
      const processedChildren = addComponentToLayout(
        newChildren,
        newComponent,
        targetId,
        position
      );

      // children이 변경되었는지 확인
      const childrenChanged =
        JSON.stringify(processedChildren) !== JSON.stringify(component.children);

      if (childrenChanged) {
        return {
          ...component,
          children: processedChildren,
        };
      }
    }

    return component;
  });
}

// ============================================================================
// 컴포넌트 제거
// ============================================================================

/**
 * 컴포넌트 제거
 *
 * @param components 원본 컴포넌트 배열
 * @param id 제거할 컴포넌트 ID
 * @returns 새 컴포넌트 배열
 */
export function removeComponentFromLayout(
  components: (ComponentDefinition | string)[],
  id: string
): (ComponentDefinition | string)[] {
  return components
    .filter((component) => {
      if (typeof component === 'string') return true;
      return component.id !== id;
    })
    .map((component) => {
      if (typeof component === 'string') return component;

      if (component.children) {
        return {
          ...component,
          children: removeComponentFromLayout(component.children, id),
        };
      }

      return component;
    });
}

// ============================================================================
// 컴포넌트 업데이트
// ============================================================================

/**
 * 컴포넌트 업데이트
 *
 * @param components 원본 컴포넌트 배열
 * @param id 업데이트할 컴포넌트 ID
 * @param updates 업데이트 내용
 * @returns 새 컴포넌트 배열
 */
export function updateComponentInLayout(
  components: (ComponentDefinition | string)[],
  id: string,
  updates: Partial<ComponentDefinition>
): (ComponentDefinition | string)[] {
  return components.map((component) => {
    if (typeof component === 'string') return component;

    if (component.id === id) {
      // props 병합 처리
      const newProps = updates.props
        ? { ...component.props, ...updates.props }
        : component.props;

      return {
        ...component,
        ...updates,
        props: newProps,
      };
    }

    if (component.children) {
      return {
        ...component,
        children: updateComponentInLayout(component.children, id, updates),
      };
    }

    return component;
  });
}

// ============================================================================
// 컴포넌트 이동
// ============================================================================

/**
 * 컴포넌트 이동
 *
 * @param components 원본 컴포넌트 배열
 * @param sourceId 이동할 컴포넌트 ID
 * @param targetId 대상 컴포넌트 ID
 * @param position 이동 위치
 * @returns 새 컴포넌트 배열
 */
export function moveComponentInLayout(
  components: (ComponentDefinition | string)[],
  sourceId: string,
  targetId: string,
  position: DropPosition
): (ComponentDefinition | string)[] {
  // 소스 컴포넌트 찾기
  const sourceResult = findComponentById(components, sourceId);
  if (!sourceResult) {
    return components;
  }

  // 자기 자신 안으로 이동 방지
  if (sourceId === targetId) {
    return components;
  }

  // 자식 컴포넌트 안으로 이동 방지
  if (isDescendant(components, sourceId, targetId)) {
    return components;
  }

  const sourceComponent = sourceResult.component;

  // 1. 소스 컴포넌트 제거
  const withoutSource = removeComponentFromLayout(components, sourceId);

  // 2. 새 위치에 추가
  return addComponentToLayout(withoutSource, sourceComponent, targetId, position);
}

/**
 * 자손 여부 확인
 *
 * @param components 컴포넌트 배열
 * @param parentId 부모 ID
 * @param childId 자식 ID
 * @returns 자손 여부
 */
export function isDescendant(
  components: (ComponentDefinition | string)[],
  parentId: string,
  childId: string
): boolean {
  const parentResult = findComponentById(components, parentId);
  if (!parentResult) return false;

  const parent = parentResult.component;
  if (!parent.children) return false;

  return isIdDuplicate(parent.children, childId);
}

// ============================================================================
// 컴포넌트 복제
// ============================================================================

/**
 * 컴포넌트 딥 복제 (새 ID 부여)
 *
 * @param component 원본 컴포넌트
 * @returns 복제된 컴포넌트
 */
export function deepCloneComponent(
  component: ComponentDefinition
): ComponentDefinition {
  const cloned: ComponentDefinition = {
    ...component,
    id: generateUniqueId(component.name),
    props: component.props ? { ...component.props } : undefined,
    data_binding: component.data_binding
      ? { ...component.data_binding }
      : undefined,
    style: component.style ? { ...component.style } : undefined,
    actions: component.actions
      ? JSON.parse(JSON.stringify(component.actions))
      : undefined,
    iteration: component.iteration
      ? { ...component.iteration }
      : undefined,
    lifecycle: component.lifecycle
      ? JSON.parse(JSON.stringify(component.lifecycle))
      : undefined,
    responsive: component.responsive
      ? JSON.parse(JSON.stringify(component.responsive))
      : undefined,
  };

  // 자식 컴포넌트도 재귀적으로 복제
  if (component.children) {
    cloned.children = component.children.map((child) => {
      if (typeof child === 'string') return child;
      return deepCloneComponent(child);
    });
  }

  return cloned;
}

/**
 * 레이아웃 내에서 컴포넌트 복제
 *
 * @param components 원본 컴포넌트 배열
 * @param id 복제할 컴포넌트 ID
 * @returns 새 컴포넌트 배열과 새 ID
 */
export function duplicateComponentInLayout(
  components: (ComponentDefinition | string)[],
  id: string
): { components: (ComponentDefinition | string)[]; newId: string } | null {
  const result = findComponentById(components, id);
  if (!result) return null;

  const cloned = deepCloneComponent(result.component);

  // 복제본을 원본 바로 다음에 추가
  const newComponents = addComponentToLayout(
    components,
    cloned,
    id,
    'after'
  );

  return {
    components: newComponents,
    newId: cloned.id,
  };
}

// ============================================================================
// 트리 노드 정보
// ============================================================================

/**
 * 트리 노드 정보 생성
 *
 * @param component 컴포넌트
 * @param depth 깊이 레벨
 * @param injectedSources 주입된 컴포넌트 출처 맵
 * @returns 트리 노드 정보
 */
export function createTreeNodeInfo(
  component: ComponentDefinition,
  depth: number = 0,
  injectedSources?: Map<string, string>
): LayoutTreeNodeInfo {
  const childCount = component.children
    ? component.children.filter((c) => typeof c !== 'string').length
    : 0;

  const extensionSource = injectedSources?.get(component.id);

  return {
    id: component.id,
    name: component.name,
    type: component.type as 'basic' | 'composite' | 'layout' | 'extension_point',
    hasCondition: !!component.if,
    hasIteration: !!component.iteration,
    isFromExtension: !!extensionSource,
    extensionSource,
    childCount,
    depth,
  };
}

/**
 * 모든 트리 노드 정보 생성
 *
 * @param components 컴포넌트 배열
 * @param depth 현재 깊이
 * @param injectedSources 주입된 컴포넌트 출처 맵
 * @returns 트리 노드 정보 배열
 */
export function createAllTreeNodeInfos(
  components: (ComponentDefinition | string)[],
  depth: number = 0,
  injectedSources?: Map<string, string>
): LayoutTreeNodeInfo[] {
  const result: LayoutTreeNodeInfo[] = [];

  for (const component of components) {
    if (typeof component === 'string') continue;

    result.push(createTreeNodeInfo(component, depth, injectedSources));

    if (component.children) {
      result.push(
        ...createAllTreeNodeInfos(component.children, depth + 1, injectedSources)
      );
    }
  }

  return result;
}

// ============================================================================
// 레이아웃 평탄화/재구성
// ============================================================================

/**
 * 컴포넌트 트리를 평탄화
 *
 * @param components 컴포넌트 배열
 * @returns 평탄화된 컴포넌트 배열
 */
export function flattenComponents(
  components: (ComponentDefinition | string)[]
): ComponentDefinition[] {
  const result: ComponentDefinition[] = [];

  for (const component of components) {
    if (typeof component === 'string') continue;

    result.push(component);

    if (component.children) {
      result.push(...flattenComponents(component.children));
    }
  }

  return result;
}

/**
 * 특정 타입의 컴포넌트만 필터링
 *
 * @param components 컴포넌트 배열
 * @param type 필터링할 타입
 * @returns 필터링된 컴포넌트 배열
 */
export function filterComponentsByType(
  components: (ComponentDefinition | string)[],
  type: 'basic' | 'composite' | 'layout' | 'extension_point'
): ComponentDefinition[] {
  const flattened = flattenComponents(components);
  return flattened.filter((c) => c.type === type);
}

/**
 * 특정 이름의 컴포넌트만 필터링
 *
 * @param components 컴포넌트 배열
 * @param name 필터링할 이름
 * @returns 필터링된 컴포넌트 배열
 */
export function filterComponentsByName(
  components: (ComponentDefinition | string)[],
  name: string
): ComponentDefinition[] {
  const flattened = flattenComponents(components);
  return flattened.filter((c) => c.name === name);
}

// ============================================================================
// 경로 관련 유틸리티
// ============================================================================

/**
 * 컴포넌트까지의 경로 ID 배열 가져오기
 *
 * @param components 컴포넌트 배열
 * @param id 대상 컴포넌트 ID
 * @returns 경로 ID 배열 (루트 → 대상)
 */
export function getPathToComponent(
  components: (ComponentDefinition | string)[],
  id: string
): string[] {
  const result = findComponentById(components, id);
  return result ? result.path : [];
}

/**
 * 두 컴포넌트의 공통 조상 찾기
 *
 * @param components 컴포넌트 배열
 * @param id1 첫 번째 컴포넌트 ID
 * @param id2 두 번째 컴포넌트 ID
 * @returns 공통 조상 ID 또는 null
 */
export function findCommonAncestor(
  components: (ComponentDefinition | string)[],
  id1: string,
  id2: string
): string | null {
  const path1 = getPathToComponent(components, id1);
  const path2 = getPathToComponent(components, id2);

  let commonAncestor: string | null = null;

  for (let i = 0; i < Math.min(path1.length, path2.length); i++) {
    if (path1[i] === path2[i]) {
      commonAncestor = path1[i];
    } else {
      break;
    }
  }

  return commonAncestor;
}

// ============================================================================
// 인덱스 경로 기반 유틸리티 (WYSIWYG 편집기용)
// ============================================================================

/**
 * 인덱스 경로로 컴포넌트 찾기
 *
 * 인덱스 경로는 "0.children.2.children.0" 형태로,
 * 컴포넌트 트리에서의 정확한 위치를 나타냅니다.
 *
 * @param components 컴포넌트 배열
 * @param path 인덱스 경로 (예: "0", "0.children.2", "0.children.2.children.0")
 * @returns 컴포넌트 또는 null
 */
export function findComponentByPath(
  components: (ComponentDefinition | string)[],
  path: string
): ComponentDefinition | null {
  if (!path) return null;

  const parts = path.split('.');
  let current: (ComponentDefinition | string)[] | ComponentDefinition = components;

  for (let i = 0; i < parts.length; i++) {
    const part = parts[i];

    if (part === 'children') {
      // children 접근
      if (typeof current === 'object' && !Array.isArray(current) && current.children) {
        current = current.children;
      } else {
        return null;
      }
    } else {
      // 인덱스 접근
      const index = parseInt(part, 10);
      if (isNaN(index)) return null;

      if (Array.isArray(current)) {
        const item = current[index];
        if (!item || typeof item === 'string') return null;
        current = item;
      } else {
        return null;
      }
    }
  }

  // 최종 결과가 컴포넌트인지 확인
  if (typeof current === 'object' && !Array.isArray(current) && 'name' in current) {
    return current as ComponentDefinition;
  }

  return null;
}

/**
 * 인덱스 경로의 컴포넌트에 ID 부여 (없는 경우에만)
 *
 * ID가 없는 컴포넌트에 영구 ID를 부여하고 업데이트된 컴포넌트 배열을 반환합니다.
 * 이미 ID가 있으면 기존 ID를 그대로 반환합니다.
 *
 * @param components 원본 컴포넌트 배열
 * @param path 인덱스 경로
 * @returns 새 컴포넌트 배열과 ID (또는 null)
 */
export function ensureComponentId(
  components: (ComponentDefinition | string)[],
  path: string
): { components: (ComponentDefinition | string)[]; id: string } | null {
  const component = findComponentByPath(components, path);
  if (!component) return null;

  // 이미 ID가 있으면 그대로 반환
  if (component.id) {
    return { components, id: component.id };
  }

  // 새 ID 생성
  const newId = generateUniqueId(component.name);

  // 경로를 따라가며 컴포넌트 업데이트
  const newComponents = updateComponentByPath(components, path, { id: newId });

  return { components: newComponents, id: newId };
}

/**
 * 인덱스 경로의 컴포넌트 업데이트
 *
 * @param components 원본 컴포넌트 배열
 * @param path 인덱스 경로
 * @param updates 업데이트할 내용
 * @returns 새 컴포넌트 배열
 */
export function updateComponentByPath(
  components: (ComponentDefinition | string)[],
  path: string,
  updates: Partial<ComponentDefinition>
): (ComponentDefinition | string)[] {
  if (!path) return components;

  const parts = path.split('.');

  // 재귀적으로 불변성을 유지하며 업데이트
  function updateRecursive(
    current: (ComponentDefinition | string)[],
    partIndex: number
  ): (ComponentDefinition | string)[] {
    const part = parts[partIndex];

    // 인덱스 파싱
    const index = parseInt(part, 10);
    if (isNaN(index) || index < 0 || index >= current.length) {
      return current;
    }

    const item = current[index];
    if (typeof item === 'string') return current;

    // 마지막 인덱스면 업데이트 적용
    if (partIndex === parts.length - 1) {
      const newItem = {
        ...item,
        ...updates,
        props: updates.props ? { ...item.props, ...updates.props } : item.props,
      };
      return [...current.slice(0, index), newItem, ...current.slice(index + 1)];
    }

    // 다음이 'children'인지 확인
    const nextPart = parts[partIndex + 1];
    if (nextPart === 'children' && item.children) {
      // children 내부로 재귀
      const newChildren = updateRecursive(item.children, partIndex + 2);
      const newItem = { ...item, children: newChildren };
      return [...current.slice(0, index), newItem, ...current.slice(index + 1)];
    }

    return current;
  }

  return updateRecursive(components, 0);
}

// ============================================================================
// 레이아웃 통계
// ============================================================================

/**
 * 레이아웃 통계 정보
 */
export interface LayoutStats {
  /** 총 컴포넌트 수 */
  totalComponents: number;

  /** 최대 깊이 */
  maxDepth: number;

  /** 타입별 컴포넌트 수 */
  byType: {
    basic: number;
    composite: number;
    layout: number;
    extension_point: number;
  };

  /** 조건부 렌더링 컴포넌트 수 */
  withCondition: number;

  /** 반복 렌더링 컴포넌트 수 */
  withIteration: number;

  /** 액션이 있는 컴포넌트 수 */
  withActions: number;
}

/**
 * 레이아웃 통계 계산
 *
 * @param components 컴포넌트 배열
 * @param depth 현재 깊이
 * @returns 통계 정보
 */
export function calculateLayoutStats(
  components: (ComponentDefinition | string)[],
  depth: number = 0
): LayoutStats {
  const stats: LayoutStats = {
    totalComponents: 0,
    maxDepth: depth,
    byType: {
      basic: 0,
      composite: 0,
      layout: 0,
      extension_point: 0,
    },
    withCondition: 0,
    withIteration: 0,
    withActions: 0,
  };

  for (const component of components) {
    if (typeof component === 'string') continue;

    stats.totalComponents++;

    const type = component.type as keyof typeof stats.byType;
    if (stats.byType[type] !== undefined) {
      stats.byType[type]++;
    }

    if (component.if) stats.withCondition++;
    if (component.iteration) stats.withIteration++;
    if (component.actions && component.actions.length > 0) stats.withActions++;

    if (component.children) {
      const childStats = calculateLayoutStats(component.children, depth + 1);

      stats.totalComponents += childStats.totalComponents;
      stats.maxDepth = Math.max(stats.maxDepth, childStats.maxDepth);
      stats.byType.basic += childStats.byType.basic;
      stats.byType.composite += childStats.byType.composite;
      stats.byType.layout += childStats.byType.layout;
      stats.byType.extension_point += childStats.byType.extension_point;
      stats.withCondition += childStats.withCondition;
      stats.withIteration += childStats.withIteration;
      stats.withActions += childStats.withActions;
    }
  }

  return stats;
}

// ============================================================================
// Export 기본
// ============================================================================

export default {
  generateUniqueId,
  isIdDuplicate,
  findComponentById,
  findComponentByName,
  findParentComponent,
  collectAllIds,
  addComponentToLayout,
  removeComponentFromLayout,
  updateComponentInLayout,
  moveComponentInLayout,
  isDescendant,
  deepCloneComponent,
  duplicateComponentInLayout,
  createTreeNodeInfo,
  createAllTreeNodeInfos,
  flattenComponents,
  filterComponentsByType,
  filterComponentsByName,
  getPathToComponent,
  findCommonAncestor,
  calculateLayoutStats,
  // 인덱스 경로 기반 유틸리티
  findComponentByPath,
  ensureComponentId,
  updateComponentByPath,
};
