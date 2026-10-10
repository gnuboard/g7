/**
 * @file range-date-input-names.test.ts
 * @description 기간 입력칸(같은 부모 아래 날짜 칸 둘 이상)은 칸마다 이름(aria-label)을 단다
 *
 * 입력칸에 곁 라벨 이름을 자동으로 잇는 훅(useAdjacentLabel)은 라벨 하나 뒤에 칸이 둘 이상이면 어느 칸에도
 * 잇지 않는다 — 「가입일」 을 시작·종료 두 칸에 똑같이 달면 둘을 구별할 수 없기 때문이다. 그래서 목록 필터의
 * 기간 시작·종료 칸은 이름 없이 「날짜」 로만 읽혔다(브라우저 측정, 2026-10-09 — 회원·활동 로그·본인인증 로그·
 * 알림·스케줄·개인정보 요청 화면). 기간 칸은 레이아웃에서 칸마다 직접 이름을 단다
 * (`$t:common.range_start|label=…` / `$t:common.range_end|label=…`).
 *
 * 측정은 닫힌 탭·모달을 보지 못하므로, 레이아웃 소스(.g7.html, 조각 포함)를 전수로 훑는다.
 *
 * 기간 칸 밖으로도 같은 규칙을 넓힌다(c03) — 훅의 「라벨 하나 뒤 칸 둘 이상」 판정을 시험 함수로 옮겨 레이아웃
 * 전체에서 그런 칸이 칸마다 이름(aria-label·aria-labelledby·placeholder·label[for])을 갖는지 본다.
 *
 * 시나리오 매니페스트: `tests/scenarios/range-date-input-names.yaml`
 * @effects range_date_inputs_named, label_group_controls_named
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');
const DATE_TYPES = new Set(['date', 'datetime-local']);
const NAME_ATTRS = ['aria-label', 'aria-labelledby'];

interface TagNode {
  tag: string;
  attrs: Record<string, string>;
  line: number;
  children: TagNode[];
}

/** 레이아웃 소스(.g7.html) 파일을 조각까지 모두 모은다. */
function sourceFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return sourceFiles(full);
    return entry.name.endsWith('.g7.html') ? [full] : [];
  });
}

/**
 * 태그 트리만 만든다(글자·주석은 버린다). 속성값 안의 `>`(화살표 함수 등)를 태그 끝으로 보지 않도록
 * 따옴표를 따라 읽는다.
 */
function parseTags(source: string): TagNode {
  const root: TagNode = { tag: '#root', attrs: {}, line: 0, children: [] };
  const stack: TagNode[] = [root];
  let i = 0;
  const lineAt = (pos: number) => source.slice(0, pos).split('\n').length;

  while (i < source.length) {
    if (source.startsWith('<!--', i)) {
      const end = source.indexOf('-->', i + 4);
      i = end < 0 ? source.length : end + 3;
      continue;
    }
    if (source[i] !== '<') {
      i++;
      continue;
    }
    const start = i;
    let j = i + 1;
    let quote: string | null = null;
    while (j < source.length) {
      const ch = source[j];
      if (quote) {
        if (ch === quote) quote = null;
      } else if (ch === '"' || ch === "'") {
        quote = ch;
      } else if (ch === '>') {
        break;
      }
      j++;
    }
    const raw = source.slice(start + 1, j);
    i = j + 1;

    if (raw.startsWith('/')) {
      const name = raw.slice(1).trim();
      for (let k = stack.length - 1; k > 0; k--) {
        if (stack[k].tag === name) {
          stack.length = k;
          break;
        }
      }
      continue;
    }

    const selfClosing = raw.endsWith('/');
    const body = selfClosing ? raw.slice(0, -1) : raw;
    const nameMatch = body.match(/^[A-Za-z][\w.-]*/);
    if (!nameMatch) continue;
    const attrs: Record<string, string> = {};
    const attrRe = /([^\s=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'))?/g;
    let m: RegExpExecArray | null;
    const rest = body.slice(nameMatch[0].length);
    while ((m = attrRe.exec(rest))) attrs[m[1]] = m[2] ?? m[3] ?? '';

    const node: TagNode = { tag: nameMatch[0], attrs, line: lineAt(start), children: [] };
    stack[stack.length - 1].children.push(node);
    if (!selfClosing) stack.push(node);
  }
  return root;
}

/** 같은 부모 아래 날짜 칸이 둘 이상인 묶음에서, 이름이 없는 칸을 「파일:줄」 로 모은다. */
function unnamedRangeInputs(node: TagNode, file: string, found: string[]): string[] {
  const dates = node.children.filter((c) => c.tag === 'input' && DATE_TYPES.has(c.attrs.type ?? ''));
  if (dates.length >= 2) {
    dates
      .filter((c) => !NAME_ATTRS.some((a) => (c.attrs[a] ?? '').trim() !== ''))
      .forEach((c) => found.push(`${path.relative(LAYOUT_ROOT, file).replace(/\\/g, '/')}:${c.line}`));
  }
  node.children.forEach((c) => unnamedRangeInputs(c, file, found));
  return found;
}

/*
 * ── 라벨 하나 뒤 칸 둘 이상 (훅 규칙 이식) ──
 * useAdjacentLabel 은 곁 라벨 하나 뒤에 칸이 둘 이상이면 어느 칸에도 이름을 잇지 않는다. 그 규칙을 그대로 옮겨
 * 레이아웃 전체(컴파일된 .json — .g7.html 과 `layout:decompile --sync` 로 짝)에서 그런 칸이 칸마다 스스로
 * 이름(aria-label·aria-labelledby·placeholder·label[for])을 갖는지 본다. 기간 칸에 국한하지 않는다 — 나란한
 * 숫자 칸(분석 설정 상위 목록 건수)·종류 고르기 + 기간(회원 상태 변경일)도 같은 이유로 이름이 비었다.
 */
type LayoutNode = { name?: string; props?: Record<string, unknown>; children?: unknown };

const CONTROL_NAMES = new Set(['Input', 'Select', 'Textarea']);
const MAX_CONTROL_DEPTH = 3;

/** 레이아웃 .json 을 조각까지 모두 모은다. */
function layoutJsonFiles(dir: string): string[] {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return /node_modules|__tests__/.test(entry.name) ? [] : layoutJsonFiles(full);
    return entry.name.endsWith('.json') ? [full] : [];
  });
}

const classOf = (n: LayoutNode | undefined) => String(n?.props?.className ?? '');
const childrenOf = (n: LayoutNode | undefined): LayoutNode[] => (Array.isArray(n?.children) ? (n!.children as LayoutNode[]) : []);
const isControl = (n: LayoutNode | undefined) =>
  !!n && CONTROL_NAMES.has(n.name ?? '') && n.props?.type !== 'hidden' && !/\bsr-only\b/.test(classOf(n));
const isLabelEl = (n: LayoutNode | undefined) =>
  !!n && (n.name === 'Label' || /\b(form-label|filter-label|text-heading)\b/.test(classOf(n)));

/** props 를 뺀 하위 트리에서 조건에 맞는 노드가 있는지. */
function hasDeep(n: unknown, pred: (x: LayoutNode) => boolean): boolean {
  if (!n || typeof n !== 'object') return false;
  if (Array.isArray(n)) return n.some((x) => hasDeep(x, pred));
  if (pred(n as LayoutNode)) return true;
  return Object.entries(n).some(([k, v]) => k !== 'props' && v && typeof v === 'object' && hasDeep(v, pred));
}

/** 라벨 자리 — 라벨 자신, 또는 칸 없이 라벨만 품은 묶음. 다음 라벨 자리에서 범위가 끊긴다. */
const isLabelSlot = (n: LayoutNode) => isLabelEl(n) || (!hasDeep(n, isControl) && hasDeep(n, isLabelEl));

/** 라벨 뒤 형제 하나 안의 칸을 깊이 3까지 모은다(안쪽 Label·라벨 자리를 품은 묶음은 그 라벨 몫이라 멈춘다). */
function controlsUnder(n: LayoutNode, depth: number, acc: LayoutNode[]): LayoutNode[] {
  if (!n || typeof n !== 'object' || depth > MAX_CONTROL_DEPTH) return acc;
  if (isControl(n)) {
    acc.push(n);
    return acc;
  }
  if (n.name === 'Label') return acc;
  if (childrenOf(n).some(isLabelSlot)) return acc;
  childrenOf(n).forEach((c) => controlsUnder(c, depth + 1, acc));
  return acc;
}

/** 라벨 하나 뒤 칸이 둘 이상인 묶음에서 스스로 이름이 없는 칸을 「파일: 이름」 으로 모은다. */
function unnamedLabelGroupControls(layout: unknown, rel: string): string[] {
  const fors = new Set<string>();
  (function collectFors(n: unknown): void {
    if (Array.isArray(n)) return n.forEach(collectFors);
    if (!n || typeof n !== 'object') return;
    const node = n as LayoutNode;
    const target = node.props?.htmlFor ?? node.props?.for;
    if (node.name === 'Label' && target) fors.add(String(target));
    Object.entries(n).forEach(([k, v]) => k !== 'props' && collectFors(v));
  })(layout);

  const named = (c: LayoutNode) => {
    const p = c.props ?? {};
    return Boolean(p['aria-label'] || p['aria-labelledby'] || p.placeholder || (p.id && fors.has(String(p.id))));
  };

  const found: string[] = [];
  (function walk(n: unknown): void {
    if (Array.isArray(n)) return n.forEach(walk);
    if (!n || typeof n !== 'object') return;
    const kids = childrenOf(n as LayoutNode);
    kids.forEach((label, i) => {
      if (!isLabelSlot(label)) return;
      if (label.props?.htmlFor || label.props?.for) return;
      if (hasDeep(label, isControl)) return;
      const following: LayoutNode[] = [];
      for (let k = i + 1; k < kids.length && !isLabelSlot(kids[k]); k++) following.push(kids[k]);
      const controls = following.flatMap((f) => controlsUnder(f, 1, []));
      if (controls.length >= 2) {
        controls
          .filter((c) => !named(c))
          .forEach((c) => found.push(`${rel}: ${c.name} ${String(c.props?.name ?? c.props?.value ?? c.props?.type)}`));
      }
    });
    Object.entries(n).forEach(([k, v]) => k !== 'props' && v && typeof v === 'object' && walk(v));
  })(layout);
  return found;
}

describe('레이아웃 기간 입력칸 이름', () => {
  /**
   * @scenario case=c01
   * @effects range_date_inputs_named
   */
  it('같은 부모 아래 날짜 칸이 둘 이상이면 칸마다 aria-label 이 있다', () => {
    const files = sourceFiles(LAYOUT_ROOT);
    expect(files.length).toBeGreaterThan(0);
    const unnamed = files.flatMap((file) => unnamedRangeInputs(parseTags(fs.readFileSync(file, 'utf-8')), file, []));
    expect(unnamed).toEqual([]);
  });

  /**
   * @scenario case=c02
   * @effects range_date_inputs_named
   */
  it('기간 칸 이름은 공용 문구 range_start·range_end 를 쓴다', () => {
    const files = sourceFiles(LAYOUT_ROOT);
    const offenders: string[] = [];
    const walk = (node: TagNode, file: string) => {
      const dates = node.children.filter((c) => c.tag === 'input' && DATE_TYPES.has(c.attrs.type ?? ''));
      if (dates.length === 2) {
        const [from, to] = dates.map((c) => c.attrs['aria-label'] ?? '');
        const ok =
          (from.startsWith('$t:common.range_start|label=') && to.startsWith('$t:common.range_end|label=')) ||
          // 분석 기간 바는 자체 문구(「시작일」·「종료일」)로 이미 이름을 단다.
          (from.startsWith('$t:admin.analytics.period.from') && to.startsWith('$t:admin.analytics.period.to'));
        if (!ok) offenders.push(`${path.relative(LAYOUT_ROOT, file).replace(/\\/g, '/')}:${dates[0].line}`);
      }
      node.children.forEach((c) => walk(c, file));
    };
    files.forEach((file) => walk(parseTags(fs.readFileSync(file, 'utf-8')), file));
    expect(offenders).toEqual([]);
  });

  /**
   * @scenario case=c03
   * @effects label_group_controls_named
   */
  it('라벨 하나 뒤 칸이 둘 이상이면 칸마다 이름(aria-label·aria-labelledby·placeholder·label[for])이 있다', () => {
    const files = layoutJsonFiles(LAYOUT_ROOT);
    expect(files.length).toBeGreaterThan(0);
    const unnamed = files.flatMap((file) =>
      unnamedLabelGroupControls(
        JSON.parse(fs.readFileSync(file, 'utf-8')),
        path.relative(LAYOUT_ROOT, file).split(path.sep).join('/'),
      ),
    );
    expect(unnamed).toEqual([]);
  });
});
