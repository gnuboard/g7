import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { HighlightText } from '../shared/HighlightText';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/** 트리 행이 표현하는 파일 1건 — 백엔드 응답 스키마는 바꾸지 않고 레이아웃이 이 모양으로 옮겨 준다. */
export interface FileTreeNode {
  /** 노드 키 — `layout:<name>` 또는 `ext:<id>` */
  key: string;
  kind: 'layout' | 'extension';
  /** 경로 모양 이름 (`partials/admin_user_list/_modal_form`) */
  path: string;
  /** 표시 이름 — 없으면 `path` 의 마지막 세그먼트 */
  label?: string;
  /** 확장 조각의 묶음 라벨 (모듈·플러그인 이름) */
  group?: string;
  /** 확장 조각 묶음의 종류 — 아이콘이 갈린다 */
  groupKind?: 'module' | 'plugin';
  badges?: Array<{ text: string; tone?: 'amber' | 'indigo' | 'red' | 'gray' }>;
  /** 보조 정보 — 크기·수정일 */
  meta?: { size?: string; updatedAt?: string; disabled?: boolean };
}

/** 한 파일에서 찾은 내용 매치. */
export interface FileTreeContentMatch {
  key: string;
  count?: number;
  lines?: Array<{ line: number; column?: number; preview?: string }>;
}

/** 파일을 열 때 함께 넘기는 좌표. */
export interface FileTreeReveal {
  line: number;
  column?: number;
}

export interface FileTreeProps {
  nodes?: FileTreeNode[];
  /** 지금 열려 있는 파일의 키 */
  selectedKey?: string;
  /** 미저장 파일 키 — ● 점 + 강조 + 상위 폴더 개수 배지 */
  dirtyKeys?: string[];
  /** 제어형 펼침 경로. 주지 않으면 내부 상태 + localStorage 로 관리한다 */
  expandedPaths?: string[];
  /** 파일명 필터·강조에 쓸 검색어 */
  searchKeyword?: string;
  /** 서버가 준 내용 검색 결과 */
  contentMatches?: FileTreeContentMatch[];
  /** 아직 파일이 없는 가상 폴더 (PO 결정 D-2) */
  pendingFolders?: string[];
  /** 행 높이 — 터치 환경은 `touch`(44px) */
  rowSize?: 'compact' | 'touch';
  /** 펼침 상태를 적어 둘 localStorage 키 (비제어일 때만) */
  storageKey?: string;
  /** 파일 조작 버튼을 보일지 (권한 없으면 false) */
  canManage?: boolean;
  /** 목록을 불러오는 중 */
  isLoading?: boolean;
  onSelect?: (key: string, revealAt?: FileTreeReveal) => void;
  onToggle?: (expandedPaths: string[]) => void;
  onCreateFile?: (parentPath: string) => void;
  onRename?: (key: string) => void;
  onDelete?: (key: string) => void;
  /** 검색 결과가 0건일 때 [검색 지우기] */
  onClearSearch?: () => void;
  className?: string;
  id?: string;
  editorAttrs?: EditorAttrs;
}

/** 내부 트리 노드 — 폴더 또는 파일. */
type TreeItem = TreeFolder | TreeFile;

interface TreeFolder {
  kind: 'folder';
  /** 펼침 상태의 키 — 구역 접두가 붙어 레이아웃/확장의 같은 이름이 섞이지 않는다 */
  id: string;
  label: string;
  /** 폴더가 나타내는 경로 (새 파일의 부모로 쓴다) */
  path: string;
  depth: number;
  children: TreeItem[];
  /** 모듈·플러그인 묶음 머리 */
  groupKind?: 'module' | 'plugin';
  /** 아직 파일이 없는 가상 폴더 */
  pending?: boolean;
}

interface TreeFile {
  kind: 'file';
  id: string;
  label: string;
  depth: number;
  node: FileTreeNode;
}

/** 구역 — 최상위 두 묶음. */
interface TreeSection {
  id: string;
  labelKey: string;
  items: TreeItem[];
  fileCount: number;
}

/** 배지 색 — 색만으로 구분하지 않도록 글자도 함께 싣는다. */
const BADGE_TONE: Record<string, string> = {
  amber: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
  indigo: 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400',
  red: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
  gray: 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300',
};

/** 한 파일 아래 펼쳐 보이는 내용 매치 줄 수 상한 — 그 이상은 파일을 열어 보는 편이 빠르다 */
const MAX_VISIBLE_MATCH_LINES = 5;

/**
 * 평면 노드 배열을 폴더 트리로 옮깁니다.
 *
 * 백엔드는 `partials/admin_user_list/_modal_form` 같은 **경로 모양 문자열**을 줄 뿐 폴더라는
 * 개념이 없다(디스크에도 없다). 폴더는 여기서 이름의 `/` 로 만들어진다 — 그래서 파일을 지우면
 * 빈 폴더도 함께 사라지고, 새 폴더는 그 안에 파일이 저장될 때까지 `pendingFolders` 로만 산다.
 *
 * @param nodes 평면 파일 목록
 * @param pendingFolders 아직 파일이 없는 폴더 경로
 * @returns 최상위 트리 항목
 */
function buildLayoutTree(nodes: FileTreeNode[], pendingFolders: string[]): TreeItem[] {
  const roots: TreeItem[] = [];
  const folders = new Map<string, TreeFolder>();

  const ensureFolder = (path: string, depth: number, pending: boolean): TreeFolder => {
    const existing = folders.get(path);

    if (existing) {
      if (!pending) {
        existing.pending = false;
      }

      return existing;
    }

    const segments = path.split('/');
    const label = segments[segments.length - 1];
    const folder: TreeFolder = {
      kind: 'folder',
      id: `layout-folder:${path}`,
      label,
      path,
      depth,
      children: [],
      pending,
    };

    folders.set(path, folder);

    if (segments.length === 1) {
      roots.push(folder);
    } else {
      ensureFolder(segments.slice(0, -1).join('/'), depth - 1, pending).children.push(folder);
    }

    return folder;
  };

  for (const path of pendingFolders) {
    if (path) {
      ensureFolder(path, path.split('/').length - 1, true);
    }
  }

  for (const node of nodes) {
    const segments = node.path.split('/');
    const label = node.label ?? segments[segments.length - 1];
    const file: TreeFile = { kind: 'file', id: node.key, label, depth: segments.length - 1, node };

    if (segments.length === 1) {
      roots.push(file);
      continue;
    }

    ensureFolder(segments.slice(0, -1).join('/'), segments.length - 2, false).children.push(file);
  }

  return sortItems(roots);
}

/**
 * 확장 주입 조각을 묶음(모듈·플러그인) 아래로 모읍니다.
 *
 * 조각은 경로가 없다 — 어느 확장이 주었는지가 유일한 구분이라 그 라벨로 한 단계만 묶는다
 * (현행 좌측 목록의 구조를 그대로 보존한다).
 *
 * @param nodes 확장 조각 목록
 * @returns 묶음 폴더 배열
 */
function buildExtensionTree(nodes: FileTreeNode[]): TreeItem[] {
  const groups = new Map<string, TreeFolder>();
  const ungrouped: TreeItem[] = [];

  for (const node of nodes) {
    const file: TreeFile = { kind: 'file', id: node.key, label: node.label ?? node.path, depth: 1, node };

    if (!node.group) {
      ungrouped.push({ ...file, depth: 0 });
      continue;
    }

    let group = groups.get(node.group);

    if (!group) {
      group = {
        kind: 'folder',
        id: `ext-group:${node.group}`,
        label: node.group,
        path: node.group,
        depth: 0,
        children: [],
        groupKind: node.groupKind ?? 'module',
      };
      groups.set(node.group, group);
    }

    group.children.push(file);
  }

  return sortItems([...groups.values(), ...ungrouped]);
}

/**
 * 폴더 먼저, 그다음 파일 — 각각 이름순으로 정렬합니다 (재귀).
 *
 * @param items 정렬할 항목
 * @returns 정렬된 항목
 */
function sortItems(items: TreeItem[]): TreeItem[] {
  for (const item of items) {
    if (item.kind === 'folder') {
      item.children = sortItems(item.children);
    }
  }

  return items.sort((a, b) => {
    if (a.kind !== b.kind) {
      return a.kind === 'folder' ? -1 : 1;
    }

    return a.label.localeCompare(b.label);
  });
}

/**
 * 검색어로 트리를 거릅니다 — **자식이 걸리면 부모 폴더는 남긴다**.
 *
 * 부모를 함께 남기지 않으면 `_modal_form` 이 어느 화면의 조각인지 알 수 없는 목록이 된다.
 * (이식 원본: 코어 `resources/js/core/template-engine/layout-editor/components/RouteTreePanel.tsx`
 * 의 `filterTree`(기준선 10e917c6d :631-663)와 같은 규칙이다 — 템플릿은 코어를 import 할 수
 * 없어 옮겨 적었고, 동작 계약은 양쪽 테스트가 같이 잠근다.)
 *
 * @param items 트리 항목
 * @param keyword 소문자 검색어
 * @param matchedKeys 내용 검색으로 걸린 파일 키 (이름이 안 맞아도 남긴다)
 * @returns 걸러진 트리
 */
function filterTree(items: TreeItem[], keyword: string, matchedKeys: Set<string>): TreeItem[] {
  const result: TreeItem[] = [];

  for (const item of items) {
    if (item.kind === 'file') {
      const haystack = `${item.node.path} ${item.label}`.toLowerCase();

      if (haystack.includes(keyword) || matchedKeys.has(item.node.key)) {
        result.push(item);
      }

      continue;
    }

    const children = filterTree(item.children, keyword, matchedKeys);

    // 폴더 이름 자체가 걸리면 그 안을 통째로 보여 준다 — 폴더를 검색한 사람은 그 내용물을 찾는다.
    if (item.label.toLowerCase().includes(keyword)) {
      result.push(item);
      continue;
    }

    if (children.length > 0) {
      result.push({ ...item, children });
    }
  }

  return result;
}

/**
 * 이 항목 아래의 파일 수와 미저장 파일 수를 셉니다.
 *
 * @param item 트리 항목
 * @param dirty 미저장 키 집합
 * @returns [파일 수, 미저장 수]
 */
function countDescendants(item: TreeItem, dirty: Set<string>): [number, number] {
  if (item.kind === 'file') {
    return [1, dirty.has(item.node.key) ? 1 : 0];
  }

  let files = 0;
  let dirtyCount = 0;

  for (const child of item.children) {
    const [childFiles, childDirty] = countDescendants(child, dirty);
    files += childFiles;
    dirtyCount += childDirty;
  }

  return [files, dirtyCount];
}

/**
 * localStorage 에서 펼침 경로를 읽습니다 (실패해도 기본값으로 계속).
 *
 * @param storageKey 저장 키
 * @returns 펼쳐진 경로 목록
 */
function readExpanded(storageKey?: string): string[] {
  if (!storageKey || typeof window === 'undefined') {
    return [];
  }

  try {
    const raw = window.localStorage.getItem(storageKey);
    const parsed = raw ? JSON.parse(raw) : null;

    return Array.isArray(parsed) ? parsed.filter((value): value is string => typeof value === 'string') : [];
  } catch {
    return [];
  }
}

/**
 * 레이아웃 파일 트리.
 *
 * 관리자 코드 편집기의 좌측 패널이다. 종전에는 144개 파일이 평면 한 줄짜리 목록에 늘어서 있어
 * 원하는 파일을 눈으로 찾는 것이 사실상 불가능했고, `<div>` + click 이라 키보드로는 닿지도 않았다.
 *
 * ## 접근성
 *
 * `role="tree"` 규약을 지키고 `tabindex` 는 **roving** 이다 — 활성 노드 하나만 `0`, 나머지는 `-1`.
 * 144개 파일을 Tab 으로 하나씩 지나가게 두지 않는다. 미저장 표시는 색 하나에 기대지 않고
 * 점 모양·굵기·`aria-label` 셋이 함께 선다(색각 이상·스크린리더 양쪽에 같은 사실이 전달돼야 한다).
 */
export const FileTree: React.FC<FileTreeProps> = ({
  nodes = [],
  selectedKey,
  dirtyKeys = [],
  expandedPaths,
  searchKeyword = '',
  contentMatches = [],
  pendingFolders = [],
  rowSize = 'compact',
  storageKey,
  canManage = true,
  isLoading = false,
  onSelect,
  onToggle,
  onCreateFile,
  onRename,
  onDelete,
  onClearSearch,
  className = '',
  id,
  editorAttrs,
}) => {
  const [internalExpanded, setInternalExpanded] = useState<string[]>(() => readExpanded(storageKey));
  const [activeRowId, setActiveRowId] = useState<string | null>(null);
  const rootRef = useRef<HTMLDivElement | null>(null);

  const isControlled = Array.isArray(expandedPaths);
  const expanded = useMemo(
    () => new Set(isControlled ? expandedPaths : internalExpanded),
    [isControlled, expandedPaths, internalExpanded]
  );

  const dirty = useMemo(() => new Set(dirtyKeys), [dirtyKeys]);
  const keyword = searchKeyword.trim().toLowerCase();

  const matchesByKey = useMemo(() => {
    const map = new Map<string, FileTreeContentMatch>();

    for (const match of contentMatches) {
      if (match?.key) {
        map.set(match.key, match);
      }
    }

    return map;
  }, [contentMatches]);

  const sections = useMemo<TreeSection[]>(() => {
    const layoutNodes = nodes.filter((node) => node.kind === 'layout');
    const extensionNodes = nodes.filter((node) => node.kind === 'extension');
    const matchedKeys = new Set(matchesByKey.keys());

    let layoutItems = buildLayoutTree(layoutNodes, pendingFolders);
    let extensionItems = buildExtensionTree(extensionNodes);

    if (keyword) {
      layoutItems = filterTree(layoutItems, keyword, matchedKeys);
      extensionItems = filterTree(extensionItems, keyword, matchedKeys);
    }

    return [
      {
        id: 'section:layouts',
        labelKey: 'admin.templates.layout_edit.section_layouts',
        items: layoutItems,
        fileCount: layoutNodes.length,
      },
      {
        id: 'section:extensions',
        labelKey: 'admin.templates.layout_edit.section_extensions',
        items: extensionItems,
        fileCount: extensionNodes.length,
      },
    ];
  }, [nodes, pendingFolders, keyword, matchesByKey]);

  /**
   * 구역·폴더가 펼쳐져 있는지 판정합니다.
   *
   * 검색 중에는 걸러진 결과를 곧바로 보여야 하므로 접힘을 무시하고 전부 펼친다 — 검색해 놓고
   * 폴더를 하나씩 눌러 열어야 한다면 검색한 의미가 없다.
   */
  const isOpen = useCallback(
    (rowId: string, defaultOpen: boolean): boolean => {
      if (keyword) {
        return true;
      }

      if (expanded.has(rowId)) {
        return true;
      }

      // 저장된 목록에 없으면 기본값을 따른다 — 구역(레이아웃/확장)은 열어 두고 폴더는 접어 둔다.
      return defaultOpen && !expanded.has(`!${rowId}`);
    },
    [expanded, keyword]
  );

  const applyExpanded = useCallback(
    (next: string[]) => {
      if (!isControlled) {
        setInternalExpanded(next);

        if (storageKey && typeof window !== 'undefined') {
          try {
            window.localStorage.setItem(storageKey, JSON.stringify(next));
          } catch {
            // 접힘 상태는 편의 장치다 — 적어 두지 못해도 트리는 그대로 동작한다.
          }
        }
      }

      onToggle?.(next);
    },
    [isControlled, storageKey, onToggle]
  );

  /**
   * 펼침을 뒤집습니다.
   *
   * "기본은 열림" 인 구역을 사용자가 접었다는 사실은 목록에 `!` 접두 키로 남긴다 — 단순히
   * 목록에서 빼면 다음 렌더에서 기본값(열림)으로 되돌아가 접기가 먹히지 않는다.
   */
  const toggleRow = useCallback(
    (rowId: string, defaultOpen: boolean) => {
      const open = isOpen(rowId, defaultOpen);
      const next = new Set(isControlled ? expandedPaths ?? [] : internalExpanded);

      if (open) {
        next.delete(rowId);

        if (defaultOpen) {
          next.add(`!${rowId}`);
        }
      } else {
        next.delete(`!${rowId}`);
        next.add(rowId);
      }

      applyExpanded([...next]);
    },
    [isOpen, isControlled, expandedPaths, internalExpanded, applyExpanded]
  );

  /** 보이는 행 목록 — 방향키 이동의 순서 그대로다. */
  const visibleRows = useMemo(() => {
    const rows: Array<{ id: string; kind: 'section' | 'folder' | 'file' | 'match'; defaultOpen: boolean }> = [];

    const walk = (items: TreeItem[]): void => {
      for (const item of items) {
        if (item.kind === 'folder') {
          rows.push({ id: item.id, kind: 'folder', defaultOpen: false });

          if (isOpen(item.id, false)) {
            walk(item.children);
          }

          continue;
        }

        rows.push({ id: item.id, kind: 'file', defaultOpen: false });

        const match = matchesByKey.get(item.node.key);
        const lines = match?.lines ?? [];

        if (lines.length > 0 && keyword) {
          for (const line of lines.slice(0, MAX_VISIBLE_MATCH_LINES)) {
            rows.push({ id: `${item.node.key}@${line.line}`, kind: 'match', defaultOpen: false });
          }
        }
      }
    };

    for (const section of sections) {
      if (section.items.length === 0 && !keyword) {
        continue;
      }

      rows.push({ id: section.id, kind: 'section', defaultOpen: true });

      if (isOpen(section.id, true)) {
        walk(section.items);
      }
    }

    return rows;
  }, [sections, isOpen, matchesByKey, keyword]);

  /**
   * 선택이 **밖에서** 바뀌면(주소 복원·탭 클릭) 활성 행도 따라간다.
   *
   * 같은 선택으로는 다시 맞추지 않는다. 그러지 않으면 방향키로 옮겨 둔 포커스가, 선택과 무관한
   * 재렌더(미저장 목록 갱신·검색 결과 도착)마다 선택된 파일로 도로 끌려간다 — 트리를 키보드로
   * 훑는 동안 커서가 자꾸 제자리로 튀는 결함이 된다.
   */
  const syncedSelectionRef = useRef<string | undefined>(undefined);

  useEffect(() => {
    if (!selectedKey || syncedSelectionRef.current === selectedKey) {
      return;
    }

    syncedSelectionRef.current = selectedKey;
    setActiveRowId(selectedKey);
  }, [selectedKey]);

  const activeId = activeRowId ?? selectedKey ?? visibleRows[0]?.id ?? null;

  /**
   * 방향키로 트리를 걷습니다 (`role="tree"` 규약).
   *
   * 이동 대상은 **보이는 행**뿐이다 — 접힌 폴더의 자식으로 커서가 들어가면 화면에 없는 자리에
   * 포커스가 생겨 스크린리더가 허공을 읽는다.
   */
  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent<HTMLDivElement>) => {
      const index = visibleRows.findIndex((row) => row.id === activeId);

      if (index < 0) {
        return;
      }

      const current = visibleRows[index];
      const focusRow = (nextIndex: number): void => {
        const next = visibleRows[Math.max(0, Math.min(visibleRows.length - 1, nextIndex))];

        if (!next) {
          return;
        }

        event.preventDefault();
        setActiveRowId(next.id);

        const element = rootRef.current?.querySelector<HTMLElement>(`[data-row-id="${CSS.escape(next.id)}"]`);
        element?.focus();
      };

      switch (event.key) {
        case 'ArrowDown':
          focusRow(index + 1);
          break;
        case 'ArrowUp':
          focusRow(index - 1);
          break;
        case 'Home':
          focusRow(0);
          break;
        case 'End':
          focusRow(visibleRows.length - 1);
          break;
        case 'ArrowRight':
          if (current.kind === 'folder' || current.kind === 'section') {
            if (isOpen(current.id, current.defaultOpen)) {
              focusRow(index + 1);
            } else {
              event.preventDefault();
              toggleRow(current.id, current.defaultOpen);
            }
          }
          break;
        case 'ArrowLeft':
          if ((current.kind === 'folder' || current.kind === 'section') && isOpen(current.id, current.defaultOpen)) {
            event.preventDefault();
            toggleRow(current.id, current.defaultOpen);
          } else {
            // 부모로 — 지금 행보다 얕은 첫 폴더·구역을 거슬러 찾는다.
            for (let cursor = index - 1; cursor >= 0; cursor -= 1) {
              if (visibleRows[cursor].kind === 'folder' || visibleRows[cursor].kind === 'section') {
                focusRow(cursor);
                break;
              }
            }
          }
          break;
        case 'Enter':
        case ' ':
          event.preventDefault();

          if (current.kind === 'folder' || current.kind === 'section') {
            toggleRow(current.id, current.defaultOpen);
          } else if (current.kind === 'file') {
            onSelect?.(current.id);
          }
          break;
        default:
          break;
      }
    },
    [visibleRows, activeId, isOpen, toggleRow, onSelect]
  );

  const rowHeight = rowSize === 'touch' ? 'h-11' : 'h-8';
  const rowText = rowSize === 'touch' ? 'text-sm' : 'text-[13px]';

  /**
   * 행 하나를 그립니다 (폴더·파일 공통 껍데기).
   */
  const renderRow = (options: {
    rowId: string;
    depth: number;
    role: 'treeitem';
    expandedState?: boolean;
    selected?: boolean;
    onActivate: () => void;
    children: React.ReactNode;
    extraClass?: string;
    ariaLabel?: string;
  }): React.ReactElement => (
    <Div
      key={options.rowId}
      role={options.role}
      data-row-id={options.rowId}
      tabIndex={activeId === options.rowId ? 0 : -1}
      aria-expanded={options.expandedState}
      aria-selected={options.selected}
      aria-label={options.ariaLabel}
      className={`flex items-center gap-2 pr-2 cursor-pointer border-l-4 transition-colors ${rowHeight} ${
        options.selected
          ? 'bg-blue-50 dark:bg-blue-900/30 border-blue-500'
          : 'border-transparent hover:bg-gray-50 dark:hover:bg-gray-700'
      } focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 ${options.extraClass ?? ''}`}
      // 깊이당 16px (§7.6 치수표 `pl-4`) — 기본 8px 은 아이콘 앞 여백이다.
      style={{ paddingLeft: `${8 + options.depth * 16}px` }}
      onClick={() => {
        setActiveRowId(options.rowId);
        options.onActivate();
      }}
    >
      {options.children}
    </Div>
  );

  /**
   * 트리 항목을 재귀로 그립니다.
   */
  const renderItems = (items: TreeItem[]): React.ReactNode =>
    items.map((item) => {
      if (item.kind === 'folder') {
        const open = isOpen(item.id, false);
        const [fileCount, dirtyCount] = countDescendants(item, dirty);

        return (
          <Div key={item.id}>
            {renderRow({
              rowId: item.id,
              depth: item.depth,
              role: 'treeitem',
              expandedState: open,
              onActivate: () => toggleRow(item.id, false),
              children: (
                <>
                  <Icon
                    name={open ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right'}
                    className="text-[10px] w-3 text-gray-400 dark:text-gray-500 shrink-0"
                  />
                  <Icon
                    name={
                      item.groupKind === 'plugin'
                        ? 'fa-solid fa-plug'
                        : item.groupKind === 'module'
                          ? 'fa-solid fa-puzzle-piece'
                          : open
                            ? 'fa-regular fa-folder-open'
                            : 'fa-regular fa-folder'
                    }
                    className={`text-xs w-4 shrink-0 ${
                      item.groupKind ? 'text-gray-500 dark:text-gray-400' : 'text-amber-500 dark:text-amber-400'
                    }`}
                  />
                  <Span className={`flex-1 min-w-0 truncate ${rowText} text-gray-700 dark:text-gray-200`}>
                    <HighlightText text={item.label} keyword={searchKeyword} />
                  </Span>
                  {item.pending && (
                    <Span className="px-1.5 py-0.5 text-[10px] rounded bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 shrink-0">
                      {t('admin.templates.layout_edit.tree.pending_folder')}
                    </Span>
                  )}
                  {/* 이식 원본(계획서 §4.1 dirty 배지 관용구): 코어 RouteTreePanel.tsx 의 dirty 배지
                      (기준선 10e917c6d :362) + hooks/useLayoutDocument.ts 의 dirtyLayoutNames(:979-989).
                      템플릿은 코어를 import 할 수 없어 관용구(점 + aria-label + 자손 수)만 옮겼다. */}
                  {dirtyCount > 0 && (
                    <Span
                      className="px-1.5 py-0.5 text-[10px] font-mono rounded-full border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 shrink-0"
                      aria-label={t('admin.templates.layout_edit.tree.unsaved_count', { count: dirtyCount })}
                    >
                      {dirtyCount}
                    </Span>
                  )}
                  {dirtyCount === 0 && fileCount > 0 && (
                    <Span className="text-[10px] text-gray-400 dark:text-gray-500 shrink-0">{fileCount}</Span>
                  )}
                </>
              ),
            })}
            {open && <Div role="group">{renderItems(item.children)}</Div>}
          </Div>
        );
      }

      const node = item.node;
      const isDirty = dirty.has(node.key);
      const isSelected = selectedKey === node.key;
      const match = matchesByKey.get(node.key);
      const lines = keyword ? (match?.lines ?? []).slice(0, MAX_VISIBLE_MATCH_LINES) : [];

      return (
        <Div key={item.id}>
          {renderRow({
            rowId: item.id,
            depth: item.depth,
            role: 'treeitem',
            selected: isSelected,
            onActivate: () => onSelect?.(node.key),
            extraClass: node.meta?.disabled ? 'opacity-50' : '',
            children: (
              <>
                <Icon
                  name={node.kind === 'extension' ? 'fa-solid fa-circle-plus' : 'fa-regular fa-file-code'}
                  className="text-xs w-4 shrink-0 text-gray-400 dark:text-gray-500"
                />
                <Span
                  className={`flex-1 min-w-0 truncate ${rowText} ${
                    isDirty
                      ? 'font-semibold text-amber-700 dark:text-amber-400'
                      : 'text-gray-700 dark:text-gray-200'
                  }`}
                  title={node.path}
                >
                  <HighlightText text={item.label} keyword={searchKeyword} />
                </Span>
                {(node.badges ?? []).map((badge, index) => (
                  <Span
                    key={index}
                    className={`px-1.5 py-0.5 text-[10px] rounded shrink-0 ${BADGE_TONE[badge.tone ?? 'gray']}`}
                  >
                    {badge.text}
                  </Span>
                ))}
                {(match?.count ?? 0) > 0 && (
                  <Span className="px-1.5 py-0.5 text-[10px] font-mono rounded shrink-0 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                    {match?.count}
                  </Span>
                )}
                {isDirty && (
                  <Span
                    className="w-[7px] h-[7px] rounded-full bg-amber-500 dark:bg-amber-400 shrink-0"
                    role="img"
                    aria-label={t('admin.templates.layout_edit.tree.unsaved')}
                  />
                )}
                {canManage && node.kind === 'layout' && (
                  <Span className="flex items-center gap-1 shrink-0">
                    <Span
                      role="button"
                      tabIndex={-1}
                      aria-label={t('admin.templates.layout_edit.file.rename')}
                      title={t('admin.templates.layout_edit.file.rename')}
                      className="px-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                      onClick={(event: React.MouseEvent) => {
                        event.stopPropagation();
                        onRename?.(node.key);
                      }}
                    >
                      <Icon name="fa-solid fa-pen" className="text-[11px]" />
                    </Span>
                    <Span
                      role="button"
                      tabIndex={-1}
                      aria-label={t('admin.templates.layout_edit.file.delete')}
                      title={t('admin.templates.layout_edit.file.delete')}
                      className="px-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400"
                      onClick={(event: React.MouseEvent) => {
                        event.stopPropagation();
                        onDelete?.(node.key);
                      }}
                    >
                      <Icon name="fa-solid fa-trash" className="text-[11px]" />
                    </Span>
                  </Span>
                )}
              </>
            ),
          })}

          {lines.length > 0 && (
            <Div role="group">
              {lines.map((line) => {
                const rowId = `${node.key}@${line.line}`;

                return renderRow({
                  rowId,
                  depth: item.depth + 1,
                  role: 'treeitem',
                  onActivate: () => onSelect?.(node.key, { line: line.line, column: line.column }),
                  extraClass: 'h-7',
                  ariaLabel: t('admin.templates.layout_edit.search.goto_line', { line: line.line }),
                  children: (
                    <>
                      <Span className="font-mono text-[11px] text-gray-400 dark:text-gray-500 w-9 text-right shrink-0">
                        {line.line}
                      </Span>
                      <Span className="font-mono text-[11px] text-gray-500 dark:text-gray-400 flex-1 min-w-0 truncate">
                        <HighlightText text={line.preview ?? ''} keyword={searchKeyword} />
                      </Span>
                    </>
                  ),
                });
              })}
            </Div>
          )}
        </Div>
      );
    });

  if (isLoading) {
    return (
      <Div id={id} className={`p-3 flex flex-col gap-2 ${className}`} {...(editorAttrs ?? {})} aria-busy="true">
        {[0, 1, 2, 3, 4, 5].map((row) => (
          <Div
            key={row}
            className="h-3 rounded bg-gray-200 dark:bg-gray-700"
            style={{ width: `${45 + ((row * 13) % 45)}%` }}
          />
        ))}
      </Div>
    );
  }

  const hasVisibleFiles = visibleRows.some((row) => row.kind === 'file');

  return (
    <Div
      id={id}
      ref={rootRef}
      role="tree"
      aria-label={t('admin.templates.layout_edit.tree.aria_label')}
      className={`overflow-y-auto ${className}`}
      onKeyDown={handleKeyDown}
      {...(editorAttrs ?? {})}
    >
      {sections.map((section) => {
        if (section.items.length === 0 && !keyword) {
          return null;
        }

        const open = isOpen(section.id, true);

        return (
          <Div key={section.id}>
            {renderRow({
              rowId: section.id,
              depth: 0,
              role: 'treeitem',
              expandedState: open,
              onActivate: () => toggleRow(section.id, true),
              extraClass: 'bg-gray-50 dark:bg-gray-700/50 uppercase tracking-wide',
              children: (
                <>
                  <Icon
                    name={open ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right'}
                    className="text-[10px] w-3 text-gray-400 dark:text-gray-500 shrink-0"
                  />
                  <Span className="flex-1 min-w-0 truncate text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                    {t(section.labelKey)}
                  </Span>
                  <Span className="text-[10px] text-gray-400 dark:text-gray-500 shrink-0">{section.fileCount}</Span>
                </>
              ),
            })}
            {open && <Div role="group">{renderItems(section.items)}</Div>}
          </Div>
        );
      })}

      {!hasVisibleFiles && keyword && (
        <Div className="p-6 text-center">
          <Span className="block text-sm text-gray-500 dark:text-gray-400">
            {t('admin.templates.layout_edit.search.empty', { keyword: searchKeyword })}
          </Span>
          {onClearSearch && (
            <Span
              role="button"
              tabIndex={0}
              className="inline-block mt-3 px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
              onClick={() => onClearSearch()}
            >
              {t('admin.templates.layout_edit.search.clear')}
            </Span>
          )}
        </Div>
      )}

      {!hasVisibleFiles && !keyword && (
        <Div className="p-6 text-center">
          <Span className="block text-sm text-gray-500 dark:text-gray-400">
            {t('admin.templates.layout_edit.tree.empty')}
          </Span>
          {canManage && onCreateFile && (
            <Span
              role="button"
              tabIndex={0}
              className="inline-block mt-3 px-3 py-1.5 text-xs rounded-lg bg-gray-900 dark:bg-white text-white dark:text-gray-900 cursor-pointer"
              onClick={() => onCreateFile('')}
            >
              {t('admin.templates.layout_edit.file.new_file')}
            </Span>
          )}
        </Div>
      )}
    </Div>
  );
};
