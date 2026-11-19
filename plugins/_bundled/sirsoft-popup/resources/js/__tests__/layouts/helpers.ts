/**
 * 팝업 레이아웃 테스트 공통 도우미 — 구운 레이아웃 JSON 트리에서 노드·액션·문자열을 찾는다.
 */

export type AnyNode = Record<string, any>;

/** 트리에서 id 가 같은 첫 노드를 찾는다(children·slots·modals·actions 를 모두 훑는다). */
export function findById(node: unknown, id: string): AnyNode | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findById(child, id);
      if (found) return found;
    }
    return null;
  }
  if (!node || typeof node !== 'object') return null;
  const obj = node as AnyNode;
  if (obj.id === id && (obj.name || obj.type)) return obj;
  for (const value of Object.values(obj)) {
    const found = findById(value, id);
    if (found) return found;
  }
  return null;
}

/** 조건을 만족하는 노드를 모두 모은다. */
export function collect(node: unknown, test: (n: AnyNode) => boolean, out: AnyNode[] = []): AnyNode[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, test, out));
    return out;
  }
  if (!node || typeof node !== 'object') return out;
  const obj = node as AnyNode;
  if (test(obj)) out.push(obj);
  Object.values(obj).forEach((child) => collect(child, test, out));
  return out;
}

/** 트리의 문자열 값 전부. */
export function strings(node: unknown, out: string[] = []): string[] {
  if (typeof node === 'string') out.push(node);
  else if (Array.isArray(node)) node.forEach((child) => strings(child, out));
  else if (node && typeof node === 'object') Object.values(node).forEach((child) => strings(child, out));
  return out;
}

/** 노드 아래 핸들러 이름이 같은 액션 전부. */
export function actionsOf(node: unknown, handler: string): AnyNode[] {
  return collect(node, (n) => n.handler === handler);
}

/** 레이아웃이 쓰는 이 플러그인 번역 키(`$t:`·`$t('…')`) 전부 — 동적 접두(`…status.` + 값)는 뺀다. */
export function translationKeys(layout: unknown): string[] {
  const keys = new Set<string>();
  for (const text of strings(layout)) {
    for (const m of text.matchAll(/\$t:(?:defer:)?sirsoft-popup\.([A-Za-z0-9_.]+)/g)) keys.add(m[1].replace(/\.$/, ''));
    for (const m of text.matchAll(/\$t\(['"]sirsoft-popup\.([A-Za-z0-9_.]+)['"]/g)) keys.add(m[1].replace(/\.$/, ''));
  }
  return [...keys];
}

/** 점 경로로 번역 값을 꺼낸다. */
export function pick(dict: unknown, dotted: string): unknown {
  return dotted.split('.').reduce<unknown>((acc, seg) => (acc && typeof acc === 'object' ? (acc as AnyNode)[seg] : undefined), dict);
}
