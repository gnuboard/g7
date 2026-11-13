/**
 * 히어로섹션 레이아웃 테스트 공통 도우미 — 구운 레이아웃 JSON 트리에서 노드·액션·문자열을 찾는다.
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
    for (const m of text.matchAll(/\$t:(?:defer:)?sirsoft-hero_section\.([A-Za-z0-9_.]+)/g)) keys.add(m[1].replace(/\.$/, ''));
    for (const m of text.matchAll(/\$t\(['"]sirsoft-hero_section\.([A-Za-z0-9_.]+)['"]/g)) keys.add(m[1].replace(/\.$/, ''));
  }
  return [...keys];
}

/** 점 경로로 번역 값을 꺼낸다. */
export function pick(dict: unknown, dotted: string): unknown {
  return dotted.split('.').reduce<unknown>((acc, seg) => (acc && typeof acc === 'object' ? (acc as AnyNode)[seg] : undefined), dict);
}

/**
 * 레이아웃 computed 를 선언 순서대로 평가한다 — 앞 computed 를 `_computed` 로 이어 받는 연쇄까지 실제 화면과 같이 푼다.
 * `scope` 에는 식이 읽는 데이터 소스·`query`·`_local` 등을 넣는다. `$t` 는 키(와 인자)를 그대로 돌려준다.
 */
export function evaluateComputed(layout: AnyNode, scope: Record<string, unknown>): Record<string, any> {
  const computed: Record<string, any> = {};
  const $t = (key: string, params?: unknown) => (params ? `${key}:${JSON.stringify(params)}` : key);
  const full: Record<string, unknown> = { $t, $localized: (v: unknown) => v, position_settings: undefined, banners: undefined, target_info: undefined, preview: undefined, placements: undefined, target_settings: undefined, form_target_settings: undefined, query: {}, _local: {}, _global: {}, ...scope };
  for (const [name, source] of Object.entries(layout.computed ?? {})) {
    const expr = String(source).replace(/^\{\{/, '').replace(/\}\}$/, '');
    computed[name] = Function(...Object.keys(full), '_computed', `return (${expr})`)(...Object.values(full), computed);
  }
  return computed;
}

/** 바인딩 식(`{{…}}`)을 scope 와 `_computed` 로 평가한다. */
export function evaluateBinding(binding: string, scope: Record<string, unknown>, computed: Record<string, unknown> = {}): any {
  const $t = (key: string, params?: unknown) => (params ? `${key}:${JSON.stringify(params)}` : key);
  const full: Record<string, unknown> = { $t, $localized: (v: unknown) => v, ...scope };
  return Function(...Object.keys(full), '_computed', `return (${binding.replace(/^\{\{/, '').replace(/\}\}$/, '')})`)(...Object.values(full), computed);
}

/** 글자 속 바인딩(`…?a={{x}}&b={{y}}`)을 모두 풀어 실제 문자열로 만든다 — 링크 주소가 값을 어떻게 싣는지 본다. */
export function interpolate(template: string, scope: Record<string, unknown>, computed: Record<string, unknown> = {}): string {
  return template.replace(/\{\{([\s\S]+?)\}\}/g, (_, expr) => String(evaluateBinding(`{{${expr}}}`, scope, computed) ?? ''));
}
