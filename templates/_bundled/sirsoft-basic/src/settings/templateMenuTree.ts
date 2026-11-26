/**
 * templateMenuTree — 템플릿 설정 `menu` 칸(사용자 화면 메뉴, 2단까지)의 트리 계산
 *
 * 편집기(`TemplateMenuField`)는 트리를 한 줄 목록으로 펴서 끌어서 놓기를 하고, 놓은 뒤 다시 트리로 묶는다.
 * 그 변환·깊이 판정·이동 규칙을 화면과 떼어 두어 단위 시험으로 잠근다. 규칙은 서버 검증(`TemplateMenu::structureErrors`)과 같다.
 *
 * - 자동 항목(`kind` 가 `link` 가 아닌 것)은 하위 메뉴를 갖지 못한다 — 하위 메뉴의 부모는 링크 항목뿐이다.
 * - 하위 메뉴가 있는 항목은 다른 항목 아래로 들어가지 못한다(3단이 되므로).
 */

export interface TemplateMenuNode {
  id: string;
  kind: string;
  label?: Record<string, string>;
  url?: string;
  new_tab?: boolean;
  is_active?: boolean;
  visibility?: 'all' | 'members' | 'guests' | 'roles';
  roles?: string[];
  children?: TemplateMenuNode[];
}

/** 펼친 한 줄 — `depth` 0 = 상위, 1 = 하위 */
export interface TemplateMenuRow {
  node: TemplateMenuNode;
  depth: number;
}

export const LINK_KIND = 'link';

/**
 * 트리를 한 줄 목록으로 폅니다(하위 메뉴는 항상 펼친 상태).
 *
 * @param items 트리
 * @param skipChildrenOf 이 id 의 하위 메뉴는 빼고 편다 — 상위 메뉴를 끄는 동안 자식이 함께 움직이게
 * @returns 줄 목록 (노드의 children 은 그대로 둔다)
 */
export const flattenMenu = (items: TemplateMenuNode[], skipChildrenOf: string | null = null): TemplateMenuRow[] => {
  const rows: TemplateMenuRow[] = [];

  for (const item of items ?? []) {
    rows.push({ node: item, depth: 0 });

    if (item.id !== skipChildrenOf) {
      for (const child of item.children ?? []) {
        rows.push({ node: child, depth: 1 });
      }
    }
  }

  return rows;
};

/**
 * 줄 목록을 트리로 다시 묶습니다. 하위 줄의 부모는 바로 앞의 상위 줄이다.
 *
 * 부모가 될 수 없는 상위 줄(자동 항목) 뒤의 하위 줄, 맨 앞의 하위 줄은 상위로 올린다.
 * `keepChildrenOf` 의 원래 자식(끄는 동안 뺀 것)은 그 노드에 그대로 붙여 둔다.
 *
 * @param rows 줄 목록
 * @param keepChildrenOf 끄는 동안 자식을 뺐던 노드 id
 * @returns 트리
 */
export const buildMenu = (rows: TemplateMenuRow[], keepChildrenOf: string | null = null): TemplateMenuNode[] => {
  const tree: TemplateMenuNode[] = [];

  for (const row of rows) {
    const parent = tree[tree.length - 1];

    if (row.depth >= 1 && parent && parent.kind === LINK_KIND && !(row.node.children?.length)) {
      parent.children = [...(parent.children ?? []), { ...row.node, children: [] }];
      continue;
    }

    const keep = row.node.id === keepChildrenOf ? (row.node.children ?? []) : [];
    tree.push({ ...row.node, children: keep });
  }

  return tree;
};

/**
 * 항목 수 (하위 메뉴 포함)
 *
 * @param items 트리
 * @returns 개수
 */
export const countMenuItems = (items: TemplateMenuNode[]): number =>
  (items ?? []).reduce((sum, item) => sum + 1 + (item.children?.length ?? 0), 0);

/**
 * 끌어서 놓을 자리의 깊이를 정합니다 — 가로로 끈 만큼 들여쓰고, 들어갈 수 없으면 상위로.
 *
 * @param rows 놓은 뒤 줄 목록 (끄는 항목 포함)
 * @param index 끄는 항목의 새 위치
 * @param baseDepth 끌기 시작할 때 깊이
 * @param offsetX 가로로 끈 거리(px)
 * @param indentWidth 한 단 들여쓰기 폭(px)
 * @param maxDepth 허용 깊이(1 또는 2)
 * @returns 0 또는 1
 */
export const projectDepth = (
  rows: TemplateMenuRow[],
  index: number,
  baseDepth: number,
  offsetX: number,
  indentWidth: number,
  maxDepth: number,
): number => {
  const wanted = Math.round(baseDepth + offsetX / indentWidth);

  return wanted >= 1 && canNestAt(rows, index, maxDepth) ? 1 : 0;
};

/**
 * 그 자리의 항목이 하위 메뉴가 될 수 있는지 — 앞 줄을 따라 올라가 만나는 상위 줄이 링크여야 한다.
 *
 * @param rows 줄 목록
 * @param index 자리
 * @param maxDepth 허용 깊이
 * @returns 될 수 있으면 true
 */
export const canNestAt = (rows: TemplateMenuRow[], index: number, maxDepth: number): boolean => {
  const row = rows[index];

  if (!row || maxDepth < 2 || (row.node.children?.length ?? 0) > 0) {
    return false;
  }

  for (let i = index - 1; i >= 0; i--) {
    if (rows[i].depth === 0) {
      return rows[i].node.kind === LINK_KIND;
    }
  }

  return false;
};

/**
 * 트리에서 id 의 위치
 *
 * @param items 트리
 * @param id 항목 id
 * @returns `{parentIndex, index}` — 상위 항목이면 parentIndex 가 null. 없으면 null
 */
export const locateMenuItem = (items: TemplateMenuNode[], id: string): { parentIndex: number | null; index: number } | null => {
  for (let i = 0; i < (items ?? []).length; i++) {
    if (items[i].id === id) {
      return { parentIndex: null, index: i };
    }

    const children = items[i].children ?? [];

    for (let j = 0; j < children.length; j++) {
      if (children[j].id === id) {
        return { parentIndex: i, index: j };
      }
    }
  }

  return null;
};

/**
 * 오류 위치 경로 — 서버가 오류를 다는 자리(`2`, `2.children.0`)
 *
 * @param items 트리
 * @param id 항목 id
 * @returns 경로 (없으면 null)
 */
export const menuItemPath = (items: TemplateMenuNode[], id: string): string | null => {
  const at = locateMenuItem(items, id);

  if (!at) {
    return null;
  }

  return at.parentIndex === null ? String(at.index) : `${at.parentIndex}.children.${at.index}`;
};

/**
 * 항목 하나를 바꿉니다.
 *
 * @param items 트리
 * @param id 항목 id
 * @param patch 바꿀 값
 * @returns 새 트리
 */
export const updateMenuItem = (items: TemplateMenuNode[], id: string, patch: Partial<TemplateMenuNode>): TemplateMenuNode[] =>
  (items ?? []).map((item) => {
    if (item.id === id) {
      return { ...item, ...patch };
    }

    if (item.children?.some((child) => child.id === id)) {
      return { ...item, children: item.children.map((child) => (child.id === id ? { ...child, ...patch } : child)) };
    }

    return item;
  });

/**
 * 항목을 지웁니다(하위 메뉴도 함께).
 *
 * @param items 트리
 * @param id 항목 id
 * @returns 새 트리
 */
export const removeMenuItem = (items: TemplateMenuNode[], id: string): TemplateMenuNode[] =>
  (items ?? [])
    .filter((item) => item.id !== id)
    .map((item) => (item.children?.some((child) => child.id === id) ? { ...item, children: item.children.filter((child) => child.id !== id) } : item));

/**
 * 같은 단계 안에서 한 칸 위·아래로 옮깁니다.
 *
 * @param items 트리
 * @param id 항목 id
 * @param delta -1 위 · 1 아래
 * @returns 새 트리 (못 옮기면 그대로)
 */
export const moveMenuItem = (items: TemplateMenuNode[], id: string, delta: -1 | 1): TemplateMenuNode[] => {
  const at = locateMenuItem(items, id);

  if (!at) {
    return items;
  }

  const swap = <T,>(list: T[], from: number, to: number): T[] => {
    if (to < 0 || to >= list.length) {
      return list;
    }

    const next = [...list];
    [next[from], next[to]] = [next[to], next[from]];

    return next;
  };

  if (at.parentIndex === null) {
    return swap(items, at.index, at.index + delta);
  }

  return items.map((item, i) => (i === at.parentIndex ? { ...item, children: swap(item.children ?? [], at.index, at.index + delta) } : item));
};

/**
 * 바로 위 형제(링크) 아래로 들여씁니다.
 *
 * @param items 트리
 * @param id 항목 id
 * @param maxDepth 허용 깊이
 * @returns 새 트리 (못 들이면 그대로)
 */
export const indentMenuItem = (items: TemplateMenuNode[], id: string, maxDepth: number): TemplateMenuNode[] => {
  const at = locateMenuItem(items, id);

  if (!at || at.parentIndex !== null || at.index === 0 || maxDepth < 2) {
    return items;
  }

  const node = items[at.index];
  const target = items[at.index - 1];

  if ((node.children?.length ?? 0) > 0 || target.kind !== LINK_KIND) {
    return items;
  }

  return items
    .filter((_, i) => i !== at.index)
    .map((item, i) => (i === at.index - 1 ? { ...item, children: [...(item.children ?? []), { ...node, children: [] }] } : item));
};

/**
 * 하위 메뉴를 상위로 꺼냅니다 — 원래 부모 바로 뒤에 둔다.
 *
 * @param items 트리
 * @param id 항목 id
 * @returns 새 트리 (상위 항목이면 그대로)
 */
export const outdentMenuItem = (items: TemplateMenuNode[], id: string): TemplateMenuNode[] => {
  const at = locateMenuItem(items, id);

  if (!at || at.parentIndex === null) {
    return items;
  }

  const parent = items[at.parentIndex];
  const node = (parent.children ?? [])[at.index];
  const next = items.map((item, i) => (i === at.parentIndex ? { ...item, children: (item.children ?? []).filter((child) => child.id !== id) } : item));

  next.splice(at.parentIndex + 1, 0, { ...node, children: [] });

  return next;
};

/**
 * 새 항목 id — 화면에서만 만들고 서버는 형식(`[A-Za-z0-9_-]{1,40}`)과 중복만 본다.
 *
 * @returns id
 */
export const newMenuItemId = (): string => `m${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;

/**
 * 다국어 이름에서 보일 문구 하나를 고릅니다.
 *
 * @param label 다국어 이름
 * @param locale 현재 로케일
 * @returns 문구 (없으면 빈 문자열)
 */
export const pickMenuLabel = (label: Record<string, string> | string | undefined, locale: string): string => {
  if (typeof label === 'string') {
    return label;
  }

  if (!label || typeof label !== 'object') {
    return '';
  }

  return label[locale] || Object.values(label).find((value) => typeof value === 'string' && value.trim() !== '') || '';
};
