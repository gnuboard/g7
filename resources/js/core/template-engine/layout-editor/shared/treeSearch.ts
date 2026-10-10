import React from 'react';

/**
 * treeSearch.ts — 편집기 트리들이 함께 쓰는 검색 도구.
 *
 * 레이아웃 편집기에는 트리가 둘이다 — 라우트 트리({@link ../components/RouteTreePanel})와
 * 커스텀 자산 트리({@link ../components/CustomAssetsModal}). 둘 다 "검색어로 거르고 걸린 조각을
 * 강조한다" 는 같은 일을 하는데, 강조 로직을 각자 갖고 있으면 한쪽만 고쳐진 상태가 남는다
 * (특히 대소문자 처리·빈 검색어 처리처럼 **틀려도 조용히 지나가는** 부분이 그렇다).
 *
 * 노드 **구조**는 공유하지 않는다 — 라우트 트리는 라우트·모달·확장이 섞인 도메인 트리이고
 * 자산 트리는 경로 문자열의 집합이라, 억지로 한 타입에 묶으면 양쪽 모두에 안 맞는 필드가 생긴다.
 * 공유하는 것은 **검색이라는 행위**뿐이다.
 */

/** 강조 조각에 입힐 기본 스타일 — 두 트리가 같은 노랑을 쓴다. */
const DEFAULT_MARK_STYLE: React.CSSProperties = {
  background: '#fde68a',
  color: 'inherit',
  borderRadius: 2,
  padding: '0 1px',
};

/**
 * 텍스트에서 검색어에 걸린 조각을 `<mark>` 로 감쌉니다.
 *
 * 대소문자를 무시해 찾되 **원문 그대로** 보여 준다 — 소문자로 바꿔 출력하면 파일명이 실제와 달라
 * 그대로 복사해 쓸 수 없다.
 *
 * @param text 표시 텍스트
 * @param query 정규화된 검색어 (소문자). 비었으면 원문을 그대로 돌려준다
 * @param className 강조 조각 클래스 (트리마다 다른 BEM 이름을 쓴다)
 * @returns 강조가 섞인 React 노드 (매칭이 없으면 원문 문자열)
 *
 * @since engine-v1.71.0 — `RouteTreePanel` 의 내부 함수(engine-v1.50.0)를 공용 모듈로 옮겼다
 */
export function highlightMatch(text: string, query: string, className?: string): React.ReactNode {
  if (!query) {
    return text;
  }

  const lower = text.toLocaleLowerCase();
  let matchIdx = lower.indexOf(query);

  if (matchIdx === -1) {
    return text;
  }

  const parts: React.ReactNode[] = [];
  let cursor = 0;
  let segKey = 0;

  while (matchIdx !== -1) {
    if (matchIdx > cursor) {
      parts.push(text.slice(cursor, matchIdx));
    }

    parts.push(
      React.createElement(
        'mark',
        { key: `m${segKey++}`, className, style: DEFAULT_MARK_STYLE },
        text.slice(matchIdx, matchIdx + query.length),
      ),
    );

    cursor = matchIdx + query.length;
    matchIdx = lower.indexOf(query, cursor);
  }

  if (cursor < text.length) {
    parts.push(text.slice(cursor));
  }

  return parts;
}

/**
 * 경로로 만든 트리의 노드 — 폴더 또는 파일.
 *
 * @since engine-v1.71.0
 */
export interface PathTreeNode<T> {
  /** 이 노드까지의 전체 경로 (`css/theme/dark.css`) */
  path: string;
  /** 표시 이름 — 경로의 마지막 세그먼트 */
  label: string;
  /** 깊이 (0-base) */
  depth: number;
  /** 폴더면 자식, 파일이면 빈 배열 */
  children: Array<PathTreeNode<T>>;
  /** 파일일 때만 채워진다 */
  item?: T;
}

/**
 * `a/b/c.css` 꼴의 평면 경로 목록을 폴더 트리로 옮깁니다.
 *
 * 자산도 레이아웃과 마찬가지로 **폴더라는 실체가 따로 없다** — 경로 문자열의 `/` 가 폴더다.
 * 그래서 파일이 사라지면 빈 폴더도 함께 사라진다.
 *
 * @param items 파일 목록
 * @param getPath 항목에서 경로를 뽑는 함수
 * @returns 최상위 노드 배열 (폴더 먼저, 각각 이름순)
 *
 * @since engine-v1.71.0
 */
export function buildPathTree<T>(items: readonly T[], getPath: (item: T) => string): Array<PathTreeNode<T>> {
  const roots: Array<PathTreeNode<T>> = [];
  const folders = new Map<string, PathTreeNode<T>>();

  const ensureFolder = (path: string, depth: number): PathTreeNode<T> => {
    const existing = folders.get(path);

    if (existing) {
      return existing;
    }

    const segments = path.split('/');
    const folder: PathTreeNode<T> = {
      path,
      label: segments[segments.length - 1],
      depth,
      children: [],
    };

    folders.set(path, folder);

    if (segments.length === 1) {
      roots.push(folder);
    } else {
      ensureFolder(segments.slice(0, -1).join('/'), depth - 1).children.push(folder);
    }

    return folder;
  };

  for (const item of items) {
    const path = getPath(item);
    const segments = path.split('/');
    const node: PathTreeNode<T> = {
      path,
      label: segments[segments.length - 1],
      depth: segments.length - 1,
      children: [],
      item,
    };

    if (segments.length === 1) {
      roots.push(node);
      continue;
    }

    ensureFolder(segments.slice(0, -1).join('/'), segments.length - 2).children.push(node);
  }

  return sortPathTree(roots);
}

/**
 * 폴더 먼저, 그다음 파일 — 각각 이름순으로 정렬합니다 (재귀).
 *
 * @param nodes 정렬할 노드
 * @returns 정렬된 노드 (제자리 정렬한 같은 배열)
 *
 * @since engine-v1.71.0
 */
export function sortPathTree<T>(nodes: Array<PathTreeNode<T>>): Array<PathTreeNode<T>> {
  for (const node of nodes) {
    if (node.children.length > 0) {
      sortPathTree(node.children);
    }
  }

  return nodes.sort((a, b) => {
    const aIsFolder = a.item === undefined;
    const bIsFolder = b.item === undefined;

    if (aIsFolder !== bIsFolder) {
      return aIsFolder ? -1 : 1;
    }

    return a.label.localeCompare(b.label);
  });
}

/**
 * 검색어로 트리를 거릅니다 — **자식이 걸리면 부모 폴더는 남긴다**.
 *
 * 부모를 함께 남기지 않으면 `dark.css` 가 어느 폴더의 것인지 알 수 없는 목록이 된다.
 * 폴더 이름 자체가 걸리면 그 안을 통째로 보여 준다 — 폴더를 검색한 사람은 그 내용물을 찾는다.
 *
 * @param nodes 트리 노드
 * @param query 정규화된 검색어 (소문자)
 * @param keepPaths 이름이 안 걸려도 남길 경로 (내용 검색에 걸린 파일)
 * @returns 걸러진 트리 (얕은 복사 — children 만 교체)
 *
 * @since engine-v1.71.0
 */
export function filterPathTree<T>(
  nodes: Array<PathTreeNode<T>>,
  query: string,
  keepPaths?: ReadonlySet<string>,
): Array<PathTreeNode<T>> {
  if (!query) {
    return nodes;
  }

  const out: Array<PathTreeNode<T>> = [];

  for (const node of nodes) {
    const isFolder = node.item === undefined;

    if (!isFolder) {
      if (node.path.toLocaleLowerCase().includes(query) || keepPaths?.has(node.path)) {
        out.push(node);
      }

      continue;
    }

    if (node.label.toLocaleLowerCase().includes(query)) {
      out.push(node);
      continue;
    }

    const children = filterPathTree(node.children, query, keepPaths);

    if (children.length > 0) {
      out.push({ ...node, children });
    }
  }

  return out;
}
