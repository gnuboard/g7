/**
 * @file loadingNegativeState.test.tsx
 * @description 관리자 기본 템플릿 — 받는 중에는 받은 뒤에만 뜻이 있는 요소를 보이지 않거나 흐린다
 *
 * 화면이 먼저 그려진 뒤 받는 데이터소스(progressive·background — 전략을 적지 않으면 progressive)는 받기 전 값이
 * `undefined` 다. 조건이 `!ds?.data?.x`·`(ds?.data ?? []).length === 0`·`!_global.currentUser?.uuid` 처럼 빈 값을
 * 「없음」·「비회원」으로 읽으면 받는 중에도 참이 되어, 회원에게 로그인 버튼이, 데이터가 있는데 「없습니다」가 먼저
 * 보였다가 바뀐다. 받은 뒤에만 뜻이 있는 요소는 `ds !== undefined && …` 로 받은 뒤에만 그리고, 숫자는
 * `blur_until_loaded` 로 받는 동안 흐린다.
 *
 * 레이아웃 JSON 전부(조각은 넣는 자리에서 펼쳐)를 엔진의 실제 조건 평가기로 받는 중 상태에서 평가한다.
 * 「없음」 안내·받기 실패·표 빈 문구는 이 템플릿의 기존 전수 테스트(list-loading-empty-state 등)가 잠근다.
 *
 * @vitest-environment jsdom
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { evaluateIfCondition } from '@core/template-engine/helpers/RenderHelpers';
import { findBlurBranchesInLayoutRows } from '@core/template-engine/__tests__/utils/blurBranchRowUtils';

type Node = Record<string, any>;

/** 템플릿 루트 */
const PKG_ROOT = path.resolve(__dirname, '../..');

/**
 * 트리에서 조건에 맞는 노드를 모두 찾는다.
 *
 * @param node 시작 노드
 * @param predicate 조건
 * @param out 결과 배열
 * @returns 찾은 노드 목록
 */
function findAll(node: any, predicate: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((child) => findAll(child, predicate, out));
    return out;
  }
  if (predicate(node)) out.push(node);
  for (const value of Object.values(node)) findAll(value, predicate, out);
  return out;
}

describe('sirsoft-admin_basic — 받는 중 노출 전수', () => {
  /**
   * 화면이 먼저 그려진 뒤 받는 데이터(progressive·background·전략 미지정 소스, 그 initGlobal/initLocal 키,
   * 로그인 사용자 `_global.currentUser`)를 모두 `undefined` 로 둔 「받는 중」 상태에서, 받은 뒤에만 뜻이 있는
   * 요소가 보이면 안 된다. 엔진의 실제 조건 평가기로 트리를 내려가며 조상이 보이는 노드만 본다.
   *
   *  1. 보이는 노드의 `if` 가 받는 데이터에 기대면서 참이다 — 받는 중 값을 「없음」·「비회원」·「미동의」로 읽은 것.
   *     식에 `=== undefined` 가 있으면(받는 중 표시를 일부러 그린 것) 뺀다.
   *  1-2. 조건이 여러 데이터에 기대면, 하나가 먼저 와 나머지 마디가 참이 된 순간에도 보이면 안 된다 — 받는 데이터 하나만
   *     기대는 마디가 받는 중에 참이면(`X?.data?.y !== true`) 그 데이터는 `X !== undefined &&` 로 기다려야 한다.
   *  1-1. 회원/비회원 분기(`!_global.currentUser…`)는 조상과 무관하게 받는 중 가드(`_global.currentUser !== undefined &&`)가 있어야 한다.
   *  2. 보이는 글자가 받는 데이터를 `?? 0` 으로 메운 숫자(또는 `(… ?? []).length` 개수)를 그리는데, 자신·조상에 그 소스를 기다리는
   *     `blur_until_loaded` 가 없다 — 받기 전 「0」이 먼저 보인다.
   */
  /** 받는 중에 보여도 되는 노드 — `파일#노드` → 사유 */
  const EXPOSE_ALLOW = new Map<string, string>([
    ['layouts/admin_role_form.json#menus_readonly_hint','_local.form 은 blocking 소스(role)가 그리기 전에 채운다 — 받는 중으로 잡힌 clone_source 는 복제 진입에서만 받는다'],
    ['layouts/admin_user_form.json#password_admin_assigned_notice', '관리자가 비밀번호 칸에 입력한 뒤에만 보인다 — 입력하는 시점에는 로그인 관리자 정보(current_user)가 이미 와 있다'],
    ['layouts/admin_user_form.json#field_current_password', '관리자가 비밀번호 칸에 입력한 뒤에만 보인다 — 입력하는 시점에는 로그인 관리자 정보(current_user)가 이미 와 있다'],
  ]);
  /** 이 패키지 밖(호스트 화면)에서 받는 데이터소스 id — 확장 주입 파일·외부 베이스를 잇는 레이아웃이 읽는다 */
  const HOST_IDS: string[] = [];
  /** 이 패키지 밖에서 받는 `_global` 키 */
  const HOST_GLOBALS: string[] = [];
  const ROOTS: string[] = ['layouts'];

  const exposeFiles: string[] = [];
  const collect = (dir: string) => {
    if (!fs.existsSync(dir)) return;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) { if (e.name !== '__tests__' && e.name !== 'node_modules') collect(full); }
      else if (e.name.endsWith('.json')) exposeFiles.push(full);
    }
  };
  ROOTS.forEach((r) => collect(path.join(PKG_ROOT, r)));
  const relOf = (f: string) => path.relative(PKG_ROOT, f).split(path.sep).join('/');
  const layouts = new Map<string, Node>(exposeFiles.map((f) => [relOf(f), JSON.parse(fs.readFileSync(f, 'utf8'))]));

  /** 조각 경로(예: partials 아래 조각 파일)를 패키지 기준 경로로 바꾼다 */
  const resolvePartial = (from: string, ref: string): string | null => {
    for (const root of ROOTS) {
      const cand = `${root}/${ref}`.replace(/\/+/g, '/');
      if (layouts.has(cand)) return cand;
    }
    const sibling = path.posix.join(path.posix.dirname(from), ref);
    return layouts.has(sibling) ? sibling : null;
  };
  /** extends 베이스 이름을 패키지 기준 경로로 바꾼다 */
  const resolveBase = (name: string): string | null => {
    for (const root of ROOTS) for (const cand of [`${root}/${name}.json`, `${root}/admin/${name}.json`]) if (layouts.has(cand)) return cand;
    return null;
  };

  // 조각 → 그 조각을 넣는 파일(모달로 넣은 것은 따로 표시 — 열기 전에는 그려지지 않는다)
  const includers = new Map<string, Set<string>>();
  const modalPartials = new Set<string>();
  for (const [file, json] of layouts) {
    const visitRefs = (node: any, inModals: boolean) => {
      if (!node || typeof node !== 'object') return;
      if (Array.isArray(node)) return node.forEach((c) => visitRefs(c, inModals));
      if (typeof node.partial === 'string') {
        const target = resolvePartial(file, node.partial);
        if (target) {
          if (!includers.has(target)) includers.set(target, new Set());
          includers.get(target)!.add(file);
          if (inModals) modalPartials.add(target);
        }
      }
      for (const [k, v] of Object.entries(node)) if (k !== 'data_sources') visitRefs(v, inModals || k === 'modals');
    };
    visitRefs(json, false);
  }

  type Scope = { ids: Set<string>; globals: Set<string>; locals: Set<string>; computed: Map<string, string> };
  /** `_global.x`·`_local.x` 를 채우는 데이터소스 id — 흐림이 그 소스를 기다리는지 볼 때 쓴다 */
  const ORIGIN = new Map<string, Set<string>>();
  /** 이름 또는 그 이름을 채우는 소스가 집합에 있는지 — `_local.form` 은 그것을 채우는 소스가 오면 함께 온다 */
  const within = (set: Set<string>, r: string) => set.has(r) || [...(ORIGIN.get(r) ?? [])].some((id) => set.has(id));
  const ownScope = (json: Node): Scope => {
    const s: Scope = { ids: new Set(), globals: new Set(), locals: new Set(), computed: new Map() };
    // 계산값(_computed.x)은 받는 데이터로 만든 식이다 — 조건에서 쓰이면 식을 펼쳐 같은 규칙으로 본다
    for (const holder of findAll(json, (n) => n.computed && typeof n.computed === 'object' && !Array.isArray(n.computed))) {
      for (const [k, v] of Object.entries(holder.computed)) if (typeof v === 'string' && v.trim().startsWith('{{')) s.computed.set(k, v.trim().slice(2, -2));
    }
    for (const ds of findAll(json, (n) => Array.isArray(n.data_sources)).flatMap((n) => n.data_sources)) {
      if (!ds?.id || ds.loading_strategy === 'blocking') continue;
      s.ids.add(ds.id);
      const ig = ds.initGlobal ?? ds.init_global;
      const keep = (kind: 'globals' | 'locals', key: string) => {
        s[kind].add(key);
        const ref = `${kind === 'globals' ? '_global' : '_local'}.${key}`;
        if (!ORIGIN.has(ref)) ORIGIN.set(ref, new Set());
        ORIGIN.get(ref)!.add(ds.id);
      };
      if (typeof ig === 'string' && ig) keep('globals', ig.split('.')[0]);
      else if (ig && typeof ig === 'object') Object.keys(ig).forEach((k) => keep('globals', k.split('.')[0]));
      const il = ds.initLocal ?? ds.init_local;
      if (typeof il === 'string' && il) keep('locals', il.split('.')[0]);
      else if (il && typeof il === 'object') Object.keys(il).filter((k) => !k.startsWith('_')).forEach((k) => keep('locals', k.split('.')[0]));
    }
    return s;
  };
  const merge = (a: Scope, b: Scope) => {
    b.ids.forEach((x) => a.ids.add(x)); b.globals.forEach((x) => a.globals.add(x)); b.locals.forEach((x) => a.locals.add(x));
    b.computed.forEach((v, k) => { if (!a.computed.has(k)) a.computed.set(k, v); });
  };
  const cloneScope = (s: Scope): Scope => ({ ids: new Set(s.ids), globals: new Set(s.globals), locals: new Set(s.locals), computed: new Map(s.computed) });
  /** 식 안의 `_computed.x` 를 그 계산식으로 바꾼다(계산값이 계산값을 읽는 경우까지 세 번) */
  const expandComputed = (expr: string, s: Scope): string => {
    let out = expr;
    for (let i = 0; i < 3; i++) {
      const next = out.replace(/(\$|_)computed\??\.(\w+)\b/g, (m, _p, k) => (s.computed.has(k) ? `(${s.computed.get(k)})` : m));
      if (next === out) break;
      out = next;
    }
    return out;
  };
  const scopeCache = new Map<string, Scope>();
  /**
   * 파일이 그려질 때 받는 중인 데이터 — 자기 선언 + extends 베이스 + 그 조각을 넣는 모든 파일의 선언.
   * 넣는 파일을 찾지 못한 조각은 패키지 전체 선언을 쓴다(넓게 잡아 놓치지 않는다).
   */
  const scopeOf = (file: string, seen = new Set<string>()): Scope => {
    if (scopeCache.has(file)) return scopeCache.get(file)!;
    const s = ownScope(layouts.get(file) as Node);
    HOST_GLOBALS.forEach((g) => s.globals.add(g));
    if (seen.has(file)) return s;
    seen.add(file);
    const json = layouts.get(file) as Node;
    if (typeof json.extends === 'string') {
      const base = resolveBase(json.extends);
      if (base) merge(s, scopeOf(base, seen));
      else HOST_IDS.forEach((x) => s.ids.add(x));
    }
    if (/(^|\/)extensions\//.test(file)) {
      HOST_IDS.forEach((x) => s.ids.add(x));
      for (const [f, j] of layouts) if (/(^|\/)extensions\//.test(f)) merge(s, ownScope(j));
    }
    const inc = includers.get(file);
    if (inc && inc.size) inc.forEach((f) => merge(s, scopeOf(f, seen)));
    else if (/\/partials\//.test(file)) for (const j of layouts.values()) merge(s, ownScope(j));
    scopeCache.set(file, s);
    return s;
  };

  const esc = (x: string) => x.replace(/[$.*+?^(){}|[\]\\]/g, '\\$&');
  /** 식이 받는 데이터를 읽는지 — 읽는 이름 목록 */
  const loadingRefs = (raw: string, s: Scope): string[] => {
    // 따옴표 안 글자('schedules' 같은 탭 이름)는 소스 이름이 아니다 — $t: 키·{{ }} 밖 글자도 빼고 식만 본다
    const expr = (raw.match(/\{\{[\s\S]*?\}\}/g)?.join(' ') ?? raw).replace(/'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"/g, "''");
    const hits: string[] = [];
    s.ids.forEach((id) => { if (new RegExp(`(^|[^\\w.$])${esc(id)}\\b`).test(expr)) hits.push(id); });
    s.globals.forEach((g) => { if (new RegExp(`_global\\??\\.${esc(g)}\\b`).test(expr)) hits.push(`_global.${g}`); });
    s.locals.forEach((l) => { if (new RegExp(`_local\\??\\.${esc(l)}\\b`).test(expr)) hits.push(`_local.${l}`); });
    return hits;
  };
  /** 받는 중 컨텍스트에서 조건을 엔진으로 평가한다 — 받는 데이터는 모두 undefined, 화면 상태는 비어 있다 */
  const trueWhileLoadingIf = (cond: string): boolean => {
    const ctx: any = { _global: {}, _local: {}, _computed: {}, query: {}, route: {}, $parent: { _local: {}, _global: {} } };
    try { return evaluateIfCondition(cond, ctx, dataBindingEngine); } catch { return false; }
  };
  /** 식을 최상위 연산자(`||` 또는 `&&`)로 나눈다 — 괄호·대괄호·중괄호·따옴표 안은 나누지 않는다 */
  const splitTop = (expr: string, op: '||' | '&&'): string[] => {
    const parts: string[] = [];
    let depth = 0, quote = '', cur = '';
    for (let i = 0; i < expr.length; i++) {
      const ch = expr[i];
      if (quote) { cur += ch; if (ch === quote && expr[i - 1] !== '\\') quote = ''; continue; }
      if (ch === '"' || ch === "'" || ch === '`') { quote = ch; cur += ch; continue; }
      if ('([{'.includes(ch)) depth++;
      if (')]}'.includes(ch)) depth--;
      if (depth === 0 && expr.startsWith(op, i)) { parts.push(cur.trim()); cur = ''; i += op.length - 1; continue; }
      cur += ch;
    }
    parts.push(cur.trim());
    return parts.filter(Boolean);
  };
  /** 최상위 삼항이 있는지 */
  const hasTopTernary = (expr: string): boolean => {
    let depth = 0, quote = '';
    for (let i = 0; i < expr.length; i++) {
      const ch = expr[i];
      if (quote) { if (ch === quote && expr[i - 1] !== '\\') quote = ''; continue; }
      if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue; }
      if ('([{'.includes(ch)) depth++;
      if (')]}'.includes(ch)) depth--;
      if (depth === 0 && ch === '?' && expr[i + 1] !== '.' && expr[i + 1] !== '?' && expr[i - 1] !== '?') return true;
    }
    return false;
  };
  const evalExpr = (expr: string) => trueWhileLoadingIf(`{{${expr}}}`);
  /** 화면이 아닌 키 — 동작(클릭·응답 처리)·생애주기·계산값·데이터소스·모달의 조건은 그리기와 무관하다 */
  const SKIP_KEYS = new Set(['data_sources', 'modals', 'if', 'text', 'actions', 'lifecycle', 'init_actions', 'onSuccess', 'onError', 'computed', 'named_actions', 'errorHandling', 'defines']);
  /** 주소 상태(query·route)를 읽는 식 — 그 주소로 들어오면 참이 될 수 있다 */
  const URL_STATE = /(^|[^\w.$])(query|route)\??\.\w/;
  /**
   * 조건을 마디(disjunct → conjunct)로 나눠, 받는 데이터 하나만 기대는 마디가 받는 중에 거짓이면 그 데이터는
   * 「그 마디가 기다린다」고 본다. 기다리지 않는 받는 데이터가 받는 중에 참이 되는 마디가 있으면 —
   * 다른 데이터가 먼저 와 나머지 마디가 참이 된 순간 노드가 보인다(제보된 쿠키 배너 결함의 꼴).
   *
   * @returns 모든 갈래에서 기다리는 데이터 목록, 받는 중에 참이 되는 마디 목록
   */
  /** 식 전체를 감싼 괄호를 벗긴다 */
  const unwrapParens = (expr: string): string => {
    let e = expr.trim();
    for (;;) {
      if (!e.startsWith('(') || !e.endsWith(')')) return e;
      let depth = 0, quote = '', closesAtEnd = true;
      for (let i = 0; i < e.length; i++) {
        const ch = e[i];
        if (quote) { if (ch === quote && e[i - 1] !== '\\') quote = ''; continue; }
        if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue; }
        if (ch === '(') depth++;
        if (ch === ')') { depth--; if (depth === 0 && i < e.length - 1) { closesAtEnd = false; break; } }
      }
      if (!closesAtEnd) return e;
      e = e.slice(1, -1).trim();
    }
  };
  const analyzeCond = (cond: string, scope: Scope, ready: Set<string>): { required: Set<string>; partial: string[] } => {
    const body = unwrapParens(cond.trim().replace(/^\{\{/, '').replace(/\}\}$/, ''));
    if (hasTopTernary(body)) return { required: new Set(), partial: [] };
    const disjuncts = splitTop(body, '||');
    let required: Set<string> | null = null;
    const partial: string[] = [];
    for (const d of disjuncts) {
      const conjuncts = splitTop(unwrapParens(d), '&&').map(unwrapParens);
      const waits = new Set<string>();
      for (const c of conjuncts) {
        const refs = loadingRefs(c, scope);
        if (refs.length === 1 && !evalExpr(c)) waits.add(refs[0]);
        // 여러 소스의 존재를 함께 확인하는 마디(`(a ?? b) != null`)는 그 소스들을 모두 기다린다
        else if (refs.length > 1 && !evalExpr(c) && /(!==?\s*(undefined|null))|(!=\s*null)/.test(c)) refs.forEach((r) => waits.add(r));
      }
      // 받는 데이터 없이 거짓인 마디가 있으면 이 갈래는 받는 중과 무관하게 닫혀 있다
      const closed = conjuncts.some((c) => !loadingRefs(c, scope).length && !URL_STATE.test(c) && !evalExpr(c));
      if (!closed) {
        for (const c of conjuncts) {
          const refs = loadingRefs(c, scope);
          if (!refs.length || /===\s*undefined/.test(c)) continue;
          const nested = !hasTopTernary(c) && (splitTop(c, '||').length > 1 || splitTop(c, '&&').length > 1);
          if (nested) {
            // 마디 안에 다시 갈래·마디가 있으면 안쪽까지 같은 규칙으로 본다
            const sub = analyzeCond(`{{${c}}}`, scope, new Set([...ready, ...waits]));
            partial.push(...sub.partial);
            if (!evalExpr(c)) sub.required.forEach((r) => waits.add(r));
            continue;
          }
          const pend = refs.filter((r) => !within(ready, r) && !within(waits, r) && r !== '_global.currentUser');
          if (pend.length && evalExpr(c)) partial.push(`${pend.join(',')} ⇐ ${c}`);
        }
      }
      required = required === null ? waits : new Set([...required].filter((x) => waits.has(x)));
    }
    return { required: required ?? new Set(), partial };
  };
  /** 노드의 흐림이 소스 id 를 기다리는지 */
  const blurWaits = (node: Node, id: string): boolean => {
    const b = node.blur_until_loaded;
    if (!b) return false;
    if (b === true || typeof b === 'string') return true;
    if (typeof b === 'object' && b.enabled) {
      if (!b.data_sources) return true;
      const list = Array.isArray(b.data_sources) ? b.data_sources : [b.data_sources];
      return [...(ORIGIN.get(id) ?? [])].some((o) => list.includes(o)) || list.includes(id.replace(/^_(global|local)\./, '')) || list.includes(id);
    }
    return false;
  };

  /**
   * @scenario load_state=loading
   * @effects loading_hides_post_load_only_state, loading_blurs_zero_counts
   */
  it('받는 중에 받은 뒤에만 뜻이 있는 요소가 보이지 않고 숫자는 흐려진다', () => {
    const offenders: string[] = [];
    let checked = 0;
    const visit = (node: any, file: string, scope: Scope, blurChain: Node[], depth: number, ready: Set<string>) => {
      if (!node || typeof node !== 'object' || depth > 40) return;
      if (Array.isArray(node)) return node.forEach((c) => visit(c, file, scope, blurChain, depth, ready));
      if (node.iteration) return; // 반복 항목은 받기 전에 그려지지 않는다
      if (node.name === 'Modal') return; // 모달은 열기 전에 그려지지 않는다
      const label = `${file}#${node.id ?? node.name ?? '?'}`;
      let nextReady = ready;
      const blurChainHere = node.blur_until_loaded ? [...blurChain, node] : blurChain;
      if (typeof node.if === 'string' && node.if.trim().startsWith('{{')) {
        const cond = expandComputed(node.if, scope);
        const refs = loadingRefs(cond, scope);
        if (!trueWhileLoadingIf(cond)) {
          // 받는 데이터에 기대지 않는 거짓 조건 — 숨김(탭·펼침처럼 화면 상태로 여는 영역 포함, 열기 전에는 그려지지 않는다).
          // 받는 데이터에 기대면 그 데이터가 온 뒤 보이므로, 조건이 기다리는 데이터는 「받은 것」으로 치고 자손을 계속 본다.
          if (!refs.length) {
            // 주소(query·route)로 정해지는 갈래(`?tab=privacy` 탭 등)는 그 주소로 바로 들어오면 첫 화면이다 — 계속 본다
            if (URL_STATE.test(cond)) {
              for (const [k, v] of Object.entries(node)) if (!SKIP_KEYS.has(k)) visit(v, file, scope, node.blur_until_loaded ? [...blurChain, node] : blurChain, depth + 1, ready);
              if (typeof node.partial === 'string') { const t = resolvePartial(file, node.partial); if (t) { const s = cloneScope(scope); merge(s, ownScope(layouts.get(t) as Node)); visit((layouts.get(t) as Node).components ?? layouts.get(t), t, s, blurChain, depth + 1, ready); } }
            }
            return;
          }
          // 받는 동안 흐림이 기다리는 데이터는 흐린 채 보인다 — 계획 목표(그리지 않거나 흐린다)를 지킨 것으로 본다
          const blurred = refs.filter((r) => blurChainHere.some((n) => blurWaits(n, r)));
          const { required, partial } = analyzeCond(cond, scope, new Set([...ready, ...blurred]));
          if (partial.length) {
            checked += 1;
            if (!EXPOSE_ALLOW.has(label)) offenders.push(`[부분도착] ${label} {${partial.join(' | ')}} ${node.if}`);
          }
          nextReady = new Set([...ready, ...required]);
        } else {
          const pending = refs.filter((r) => !within(ready, r) && !blurChainHere.some((n) => blurWaits(n, r)));
          // 받는 데이터와 무관한 갈래가 참이면(`탭이 dimensions 가 아님` 등) 받는 중과 상관없이 보이는 노드다
          const body = unwrapParens(cond.trim().replace(/^\{\{/, '').replace(/\}\}$/, ''));
          // 주소 갈래는 그 주소로 들어오면 거짓일 수 있으므로 「무관」이 아니다. 받는 데이터를 읽는 갈래는 주소 마디를 참으로 두고
          // 나머지 마디가 받는 중에 모두 참이면 보인다(`?tab=dimensions&dimension=x` 로 들어온 받는 중).
          const disj = hasTopTernary(body) ? [body] : splitTop(body, '||');
          const independent = disj.some((d) => !loadingRefs(d, scope).length && !URL_STATE.test(d) && evalExpr(d));
          const showsWhilePending = disj.some((d) => {
            if (!pending.some((r) => loadingRefs(d, scope).includes(r))) return false;
            const cs = hasTopTernary(d) ? [d] : splitTop(unwrapParens(d), '&&').map(unwrapParens);
            return cs.every((c) => (URL_STATE.test(c) && !loadingRefs(c, scope).length) || evalExpr(c));
          });
          if (pending.length && !independent && showsWhilePending) {
            checked += 1;
            if (!/===\s*undefined/.test(cond) && !EXPOSE_ALLOW.has(label)) offenders.push(`[노출] ${label} (${pending.join(',')}) ${node.if}`);
          }
        }
      }
      if (typeof node.if === 'string' && /(^|[^!])!\s*_global\??\.currentUser\b/.test(node.if) && !/currentUser\s*[!=]==\s*undefined/.test(node.if)
        && scope.globals.has('currentUser') && !EXPOSE_ALLOW.has(label)) {
        // 회원/비회원 분기 — 조상이 다른 데이터를 받은 뒤 보여도 로그인 사용자는 아직 받는 중일 수 있다.
        // 받는 중(undefined)을 비회원으로 읽지 않도록 `_global.currentUser !== undefined &&` 가드가 있어야 한다.
        checked += 1;
        if (!offenders.some((o) => o.startsWith(`[노출] ${label} `) && o.endsWith(node.if))) offenders.push(`[회원분기] ${label} ${node.if}`);
      }
      const chain = node.blur_until_loaded ? [...blurChain, node] : blurChain;
      // `?? 0` 뿐 아니라 받는 데이터를 `?? []` 로 메운 목록의 개수(`(… ?? []).length`)도 받기 전 「0건」 을 그린다
      if (typeof node.text === 'string' && /\?\?\s*0\b|\?\?\s*\[\]\s*\)\s*\.length\b/.test(expandComputed(node.text, scope))) {
        const refs = loadingRefs(expandComputed(node.text, scope), scope);
        if (refs.length) {
          checked += 1;
          const unblurred = refs.filter((r) => !within(nextReady, r) && !chain.some((n) => blurWaits(n, r)));
          if (unblurred.length && !EXPOSE_ALLOW.has(label)) offenders.push(`[숫자] ${label} ${unblurred.join(',')} ${node.text}`);
        }
      }
      if (typeof node.partial === 'string') {
        // 조각은 넣는 자리의 조상 조건·흐림 아래에서 그려진다 — 그 자리에서 펼친다
        const target = resolvePartial(file, node.partial);
        if (target) {
          const inner = layouts.get(target) as Node;
          const s = cloneScope(scope);
          merge(s, ownScope(inner));
          visit(inner.components ?? inner, target, s, chain, depth + 1, nextReady);
        }
      }
      for (const [k, v] of Object.entries(node)) if (!SKIP_KEYS.has(k)) visit(v, file, scope, chain, depth + 1, nextReady);
    };
    for (const [file, json] of layouts) {
      if (includers.get(file)?.size) continue; // 넣는 파일 쪽에서 펼친다
      visit(json.components ?? json, file, scopeOf(file), [], 0, new Set());
    }
    expect(layouts.size).toBeGreaterThan(0);
    expect(offenders).toEqual([]);
  });
});

describe('sirsoft-admin_basic — 상세 창이 목록 행으로 먼저 그려지는 동안 「없음」 노출 전수', () => {
  /**
   * 확장 상세 창은 목록이 골라 둔 행을 전역(`_global.selectedX`)에 담아 열고, 창이 열린 **뒤에** 상세 소스가
   * 같은 전역을 덮어쓴다(데이터소스 `initGlobal`). 그 사이 창은 목록 행으로 그려지는데, 목록 응답에는 상세에만
   * 있는 필드(코어 요구 버전·권한·역할·메뉴·언어팩·컴포넌트 등)가 없다. 그 필드를 「없음」으로 읽는 조건은
   * 상세를 받기 전에 참이 되어 「권한 없음」이 잠깐 보였다가 실제 값으로 바뀐다.
   *
   * 모집단: 루트가 Modal 이고 `initGlobal` 로 전역을 덮는 api 소스를 가진 레이아웃 중, 여는 쪽이 그 전역에 목록 행
   * (`"{{$args[n]}}"`)을 담는 것 전부. 목록 행의 필드는 그 소스 주소(`/api/admin/{복수형}/…`)에 대응하는 PHP 리소스의
   * `toArray()` 키에서 읽는다(손 목록 아님). 조상이 보이는 노드만 내려가고, 반복 항목은 뺀다(받기 전에 그려지지 않는다).
   *  1. 목록 행만 있는 상태에서, 상세 전용 필드에 기대는 `if` 가 참이면 안 된다.
   *  2. 상세를 받은 뒤(상세 전용 필드가 모두 비었을 때)에는 그 노드가 참이어야 한다 — 가드가 「없음」을 영영 숨기지 않는다.
   */
  const LAYOUT_ROOT = path.join(PKG_ROOT, 'layouts');
  const RESOURCE_DIR = path.resolve(PKG_ROOT, '../../..', 'app/Http/Resources');
  const walkJson = (dir: string): string[] => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    return e.isDirectory() ? walkJson(p) : e.name.endsWith('.json') ? [p] : [];
  });
  const allLayoutText = walkJson(LAYOUT_ROOT).map((f) => fs.readFileSync(f, 'utf8')).join('\n');
  /** 여는 쪽이 이 전역에 목록 행을 담는가 */
  const seededFromRow = (key: string): boolean => new RegExp(`"${key}":\\s*"\\{\\{\\$args\\[\\d\\]\\}\\}"`).test(allLayoutText);
  /** PHP 리소스의 한 메서드 본문에서 최상위 배열 키를 읽는다 */
  const phpKeys = (file: string, method: string): Set<string> => {
    const src = fs.readFileSync(file, 'utf8');
    const start = src.indexOf(`public function ${method}(`);
    if (start < 0) return new Set();
    const end = src.indexOf('\n    }\n', start);
    const body = src.slice(start, end < 0 ? undefined : end);
    return new Set([...body.matchAll(/^\s{12}'([a-z_]+)'\s*=>/gm)].map((m) => m[1]));
  };
  const modals = walkJson(LAYOUT_ROOT).flatMap((f) => {
    const json = JSON.parse(fs.readFileSync(f, 'utf8'));
    if (json.name !== 'Modal') return [];
    return (json.data_sources ?? [])
      .filter((ds: any) => ds.type === 'api' && typeof ds.initGlobal === 'string' && seededFromRow(ds.initGlobal) && /^\/api\/admin\/[a-z-]+s\//.test(ds.endpoint ?? ''))
      .map((ds: any) => {
        // `/api/admin/language-packs/…` → LanguagePackResource.php (케밥 → 파스칼)
        const plural = (ds.endpoint as string).match(/^\/api\/admin\/([a-z-]+)s\//)![1];
        const resource = path.join(RESOURCE_DIR, `${plural.split('-').map((w) => w[0].toUpperCase() + w.slice(1)).join('')}Resource.php`);
        return { file: path.relative(PKG_ROOT, f).replace(/\\/g, '/'), json, key: ds.initGlobal as string, resource };
      });
  });

  /**
   * @scenario load_state=list_row_before_detail
   * @effects detail_modal_hides_empty_notice_until_detail_arrives
   */
  it('상세를 받기 전에는 상세 전용 필드의 「없음」이 보이지 않고, 받은 뒤 비었으면 보인다', () => {
    expect(modals.length).toBeGreaterThan(0);
    const before: string[] = [];
    const after: string[] = [];
    let checked = 0;
    for (const m of modals) {
      expect(fs.existsSync(m.resource), `${m.file} → ${m.resource}`).toBe(true);
      const listKeys = phpKeys(m.resource, 'toArray');
      expect(listKeys.size, m.resource).toBeGreaterThan(0);
      const detailKeys = phpKeys(m.resource, 'toDetailArray');
      expect(detailKeys.size, m.resource).toBeGreaterThan(0);
      const refRe = new RegExp(`_global\\??\\.${m.key}\\??\\.([a-z_]+)`, 'g');
      // 목록 행은 얕은 값이다 — 키가 있어도 비어서 올 수 있다(플러그인 목록의 권한·역할·훅은 늘 빈 배열). 그래서 목록 행의
      // 모든 필드를 비운 상태를 「받기 전」으로 둔다. 「없음」은 목록 값이 아니라 상세를 받은 뒤 판정해야 한다.
      const row: Record<string, unknown> = Object.fromEntries([...listKeys].map((k) => [k, null]));
      // 받은 뒤 — 상세 응답(toDetailArray)의 필드가 모두 비어 있는 확장. 상세에 없는 키로 가드하면 영영 숨어 red 가 된다
      const detailRow: Record<string, unknown> = { ...row, ...Object.fromEntries([...detailKeys].map((k) => [k, null])) };
      const ctxOf = (g: Record<string, unknown>): any => ({ _global: { [m.key]: g }, _local: {}, _computed: {}, query: {}, route: {}, $parent: { _local: {}, _global: {} } });
      const evalIf = (cond: string, g: Record<string, unknown>) => { try { return evaluateIfCondition(cond, ctxOf(g), dataBindingEngine); } catch { return false; } };
      const visit = (node: any): void => {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) return node.forEach(visit);
        if (node.iteration) return; // 반복 항목은 목록 행 상태에서 그려지지 않는다
        if (typeof node.if === 'string') {
          const detailOnly = [...new Set([...(node.if as string).matchAll(refRe)].map((x) => x[1]))];
          const shownBefore = evalIf(node.if, row);
          // 「없음」 안내 — 문구 키가 `….no_…` 인 노드(자기 text 또는 자식 Span 의 text)
          const notice = [node.text, ...findAll(node.children ?? [], (n) => typeof n.text === 'string').map((n) => n.text)]
            .some((t) => typeof t === 'string' && /^\$t:[\w.-]+\.no_[a-z_]+$/.test(t));
          if (detailOnly.length && notice) {
            checked += 1;
            const label = `${m.file}#${node.id ?? node.name} ${node.if}`;
            if (shownBefore) before.push(label);
            if (!evalIf(node.if, detailRow)) after.push(label);
          }
          if (!shownBefore) return; // 목록 행 상태에서 숨은 노드의 자손은 그려지지 않는다
        }
        for (const v of Object.values(node)) visit(v);
      };
      visit(m.json.children ?? []);
    }
    expect(checked).toBeGreaterThan(0);
    expect(before).toEqual([]);
    expect(after).toEqual([]);
  });

  /**
   * 역할 폼 권한 개수 배지 — 복제 진입에서 `clone_source` 를 받는 동안만 흐리고, 등록·수정·복제 도착 뒤에는 흐리지 않는다.
   * 데이터소스 이름 흐림(`sources="clone_source"`)은 복제가 아닐 때 소스가 꺼져(if 거짓) 값이 영영 undefined 라 흐림이 안 풀리므로
   * 표현식 흐림으로 복제일 때만 건다(엔진 `DynamicRenderer` 문자열 흐림 = `evaluateExpression` 의 참거짓).
   *
   * @scenario load_state=loading
   * @effects loading_blurs_zero_counts
   */
  it('역할 폼 권한 개수 배지는 복제 진입의 받는 중에만 흐린다', () => {
    const role = JSON.parse(fs.readFileSync(path.join(PKG_ROOT, 'layouts/admin_role_form.json'), 'utf8'));
    const badge = findAll(role, (n) => n.id === 'permissions_selected_count')[0];
    expect(typeof badge?.blur_until_loaded).toBe('string');
    const expr = String(badge.blur_until_loaded).replace(/^\{\{|\}\}$/g, '');
    const blurred = (ctx: Record<string, unknown>) => Boolean(dataBindingEngine.evaluateExpression(expr, { _global: {}, _local: {}, ...ctx } as any, { skipCache: true }));
    expect(blurred({ query: {}, route: {} }), '등록').toBe(false);
    expect(blurred({ query: {}, route: { id: '7' }, role: { data: {} } }), '수정').toBe(false);
    expect(blurred({ query: { clone_from: '3' }, route: {} }), '복제 받는 중').toBe(true);
    expect(blurred({ query: { clone_from: '3' }, route: {}, clone_source: { data: {} } }), '복제 도착').toBe(false);
  });
});

describe('sirsoft-admin_basic — 흐림 래퍼가 배치 행의 자식 모양을 갈래마다 바꾸지 않는다', () => {
  /**
   * `blur_until_loaded` 는 대상을 `<div>` 래퍼로 감싸 그린다. 흐림을 `if` 분기 노드에 두고 그 부모가 flex·grid·space·gap·
   * justify 계열 컨테이너면, 흐림을 선언한 갈래는 래퍼 div 로·선언하지 않은 갈래는 값 요소로 행에 들어가 받기 전·뒤에 행의
   * 자식 모양이 바뀐다(engine-v1.99.2 전에는 거짓 갈래의 빈 래퍼까지 남아 양 끝 정렬의 값이 가운데로 밀렸다). 위 전수는
   * 흐림 **선언 여부**만 보므로, 흐림이 **어디에** 선언됐는지를 여기서 함께 잠근다 — 흐림은 분기들을 감싸는 하나의 노드(또는
   * 분기 없는 값 노드 자체)에 둔다.
   */
  /** 배치 행 자식인 흐림 분기 노드로 남겨 둔 자리 — [`파일#노드`, 사유] (같은 표기가 여럿이면 그 수만큼) */
  const BLUR_BRANCH_ALLOW: Array<[string, string]> = [
    ['layouts/admin_activity_log_list.json#Span', '건수 두 갈래(끝까지 셈 / 상한에 걸림) 중 끝까지 센 갈래만 흐린다 — 두 갈래를 감싸는 노드로 흐림을 옮기면 상한 갈래도 다시 받는 동안(쪽 넘김·검색) 흐려져 화면 동작이 바뀐다'],
    ['layouts/admin_identity_logs.json#Span', '건수 두 갈래(끝까지 셈 / 상한에 걸림) 중 끝까지 센 갈래만 흐린다 — 두 갈래를 감싸는 노드로 흐림을 옮기면 상한 갈래도 다시 받는 동안(쪽 넘김·검색) 흐려져 화면 동작이 바뀐다'],
    ['layouts/admin_member_notification_list.json#Span', '건수 두 갈래(끝까지 셈 / 상한에 걸림) 중 끝까지 센 갈래만 흐린다 — 두 갈래를 감싸는 노드로 흐림을 옮기면 상한 갈래도 다시 받는 동안(쪽 넘김·검색) 흐려져 화면 동작이 바뀐다'],
    ['layouts/admin_notification_log_list.json#Span', '건수 두 갈래(끝까지 셈 / 상한에 걸림) 중 끝까지 센 갈래만 흐린다 — 두 갈래를 감싸는 노드로 흐림을 옮기면 상한 갈래도 다시 받는 동안(쪽 넘김·검색) 흐려져 화면 동작이 바뀐다'],
    ['layouts/admin_user_list.json#total_count', '건수 두 갈래(끝까지 셈 / 상한에 걸림) 중 끝까지 센 갈래만 흐린다 — 두 갈래를 감싸는 노드로 흐림을 옮기면 상한 갈래도 다시 받는 동안(쪽 넘김·검색) 흐려져 화면 동작이 바뀐다'],
    ['layouts/partials/admin_schedule_list/_queue_failed_grid.json#Span', '건수 두 갈래(끝까지 셈 / 상한에 걸림) 중 끝까지 센 갈래만 흐린다 — 두 갈래를 감싸는 노드로 흐림을 옮기면 상한 갈래도 다시 받는 동안(쪽 넘김·검색) 흐려져 화면 동작이 바뀐다'],
    ['layouts/partials/admin_schedule_list/_queue_logs_grid.json#Span', '건수 두 갈래(끝까지 셈 / 상한에 걸림) 중 끝까지 센 갈래만 흐린다 — 두 갈래를 감싸는 노드로 흐림을 옮기면 상한 갈래도 다시 받는 동안(쪽 넘김·검색) 흐려져 화면 동작이 바뀐다'],
    ['layouts/partials/admin_schedule_list/_queue_pending_grid.json#Span', '건수 두 갈래(끝까지 셈 / 상한에 걸림) 중 끝까지 센 갈래만 흐린다 — 두 갈래를 감싸는 노드로 흐림을 옮기면 상한 갈래도 다시 받는 동안(쪽 넘김·검색) 흐려져 화면 동작이 바뀐다'],
    ['layouts/partials/admin_settings/_tab_info.json#Span', '메모리 행 — 받기 전 자리표시(-) 갈래만 흐린다. 세 갈래를 감싸는 노드로 옮기면 받은 값도 탭 전환 재조회 동안 흐려져 화면 동작이 바뀐다'],
    ['layouts/partials/admin_settings/_tab_info.json#Span', '디스크 행 — 메모리 행과 같은 사유'],
  ];

  /**
   * @scenario load_state=loading
   * @effects blur_wraps_branches_not_row_branch
   */
  it('흐림을 선언한 조건 분기 노드가 배치 컨테이너의 자식이 아니다(허용 목록과 같다)', () => {
    expect(findBlurBranchesInLayoutRows(PKG_ROOT, ['layouts'])).toEqual(BLUR_BRANCH_ALLOW.map(([label]) => label).sort());
  });
});
