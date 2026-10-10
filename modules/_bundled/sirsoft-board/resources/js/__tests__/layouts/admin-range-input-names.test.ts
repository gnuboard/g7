/**
 * 게시판 관리자 레이아웃 — 기간 입력칸 이름 계약 테스트 (#747)
 *
 * @description
 * 관리자 입력칸에 곁 라벨 이름을 잇는 훅은 라벨 하나 뒤에 칸이 둘 이상이면 어느 칸에도
 * 잇지 않는다. 그래서 같은 부모 아래 나란히 놓인 날짜 칸(시작일 ~ 종료일)은 화면 낭독기에
 * 이름 없이 읽힌다. 레이아웃이 칸마다 aria-label 을 직접 달았는지 고정한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/admin-range-input-names.yaml`
 * 기간 칸 밖으로도 넓혀, 훅의 「라벨 하나 뒤 칸 둘 이상」 판정을 시험 함수로 옮겨 레이아웃 전체
 * (확장 주입 조각 포함)에서 그런 칸이 칸마다 이름을 갖는지 고정한다.
 *
 * @effects board_range_inputs_have_aria_label
 * @effects board_label_group_controls_named
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const RANGE_TYPES = new Set(['date', 'datetime-local']);

/**
 * 레이아웃 디렉터리의 .json 을 모두 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns .json 절대경로 목록
 */
function collectJson(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return collectJson(full);
        return entry.name.endsWith('.json') ? [full] : [];
    });
}

/**
 * 같은 부모 아래 기간 칸이 둘 이상인 묶음에서 aria-label 이 없는 칸을 찾는다.
 *
 * @param node 레이아웃 노드
 * @param found 위반 칸 id 를 담을 배열
 */
function findNameless(node: unknown, found: string[]): void {
    if (Array.isArray(node)) {
        node.forEach((child) => findNameless(child, found));
        return;
    }
    if (!node || typeof node !== 'object') return;
    const children = (node as { children?: unknown }).children;
    if (Array.isArray(children)) {
        const ranges = children.filter((child) => {
            const props = (child as { props?: Record<string, unknown> })?.props;
            return typeof props?.type === 'string' && RANGE_TYPES.has(props.type);
        }) as Array<{ id?: string; props: Record<string, unknown> }>;
        if (ranges.length >= 2) {
            ranges
                .filter((child) => !child.props['aria-label'] && !child.props['aria-labelledby'])
                .forEach((child) => found.push(child.id ?? '(id 없음)'));
        }
    }
    Object.values(node).forEach((value) => findNameless(value, found));
}

/*
 * ── 라벨 하나 뒤 칸 둘 이상 (훅 규칙 이식) ──
 * 훅은 곁 라벨 하나 뒤에 칸이 둘 이상이면 어느 칸에도 이름을 잇지 않는다. 그 규칙을 그대로 옮겨 레이아웃
 * 전체(관리자 레이아웃 + 확장 주입 조각)에서 그런 칸이 칸마다 스스로 이름(aria-label·aria-labelledby·
 * placeholder·label[for])을 갖는지 본다. 기간 칸에 국한하지 않는다 — 반응 목록의 「유형 + 대상」 처럼
 * 라벨 하나에 고르기 칸 둘이 붙은 묶음도 같은 이유로 이름이 비었다.
 */
type LayoutNode = { name?: string; props?: Record<string, unknown>; children?: unknown };

const CONTROL_NAMES = new Set(['Input', 'Select', 'Textarea']);
const MAX_CONTROL_DEPTH = 3;

const classOf = (n: LayoutNode | undefined): string => String(n?.props?.className ?? '');
const childrenOf = (n: LayoutNode | undefined): LayoutNode[] =>
    Array.isArray(n?.children) ? (n!.children as LayoutNode[]) : [];
const isControl = (n: LayoutNode | undefined): boolean =>
    !!n && CONTROL_NAMES.has(n.name ?? '') && n.props?.type !== 'hidden' && !/\bsr-only\b/.test(classOf(n));
const isLabelEl = (n: LayoutNode | undefined): boolean =>
    !!n && (n.name === 'Label' || /\b(form-label|filter-label|text-heading)\b/.test(classOf(n)));

/**
 * props 를 뺀 하위 트리에서 조건에 맞는 노드가 있는지 본다.
 *
 * @param n 노드
 * @param pred 조건
 * @returns 있으면 true
 */
function hasDeep(n: unknown, pred: (x: LayoutNode) => boolean): boolean {
    if (!n || typeof n !== 'object') return false;
    if (Array.isArray(n)) return n.some((x) => hasDeep(x, pred));
    if (pred(n as LayoutNode)) return true;
    return Object.entries(n).some(([k, v]) => k !== 'props' && v && typeof v === 'object' && hasDeep(v, pred));
}

/** 라벨 자리 — 라벨 자신, 또는 칸 없이 라벨만 품은 묶음. 다음 라벨 자리에서 범위가 끊긴다. */
const isLabelSlot = (n: LayoutNode): boolean => isLabelEl(n) || (!hasDeep(n, isControl) && hasDeep(n, isLabelEl));

/**
 * 라벨 뒤 형제 하나 안의 칸을 깊이 3까지 모은다(안쪽 Label·라벨 자리를 품은 묶음은 그 라벨 몫이라 멈춘다).
 *
 * @param n 시작 노드
 * @param depth 현재 깊이
 * @param acc 칸을 담을 배열
 * @returns acc
 */
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

/**
 * 라벨 하나 뒤 칸이 둘 이상인 묶음에서 스스로 이름이 없는 칸을 「파일: 칸 이름」 으로 모은다.
 *
 * @param layout 레이아웃 JSON
 * @param rel 보고용 상대경로
 * @returns 위반 목록
 */
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

    const named = (c: LayoutNode): boolean => {
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

describe('게시판 관리자 레이아웃 — 기간 입력칸 이름', () => {
    const layoutsDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../layouts');
    const files = collectJson(layoutsDir);

    /**
     * @scenario surface=admin_filter_range
     * @effects board_range_inputs_have_aria_label
     */
    it('레이아웃이 하나 이상 검사된다', () => {
        expect(files.length).toBeGreaterThan(0);
    });

    /**
     * @scenario surface=admin_filter_range
     * @effects board_range_inputs_have_aria_label
     */
    it.each(files.map((file) => [path.relative(layoutsDir, file), file]))(
        '%s — 같은 부모 아래 date/datetime-local 둘 이상이면 칸마다 aria-label 이 있다',
        (_label, file) => {
            const found: string[] = [];
            findNameless(JSON.parse(fs.readFileSync(file as string, 'utf8')), found);
            expect(found).toEqual([]);
        },
    );

    /**
     * @scenario surface=admin_label_group
     * @effects board_label_group_controls_named
     */
    it('라벨 하나 뒤 칸이 둘 이상이면 칸마다 이름(aria-label·aria-labelledby·placeholder·label[for])이 있다', () => {
        const resourcesDir = path.resolve(layoutsDir, '..');
        const roots = [layoutsDir, path.join(resourcesDir, 'extensions')].filter((dir) => fs.existsSync(dir));
        const population = roots.flatMap((dir) => collectJson(dir));
        expect(population.length).toBeGreaterThan(files.length);
        const unnamed = population.flatMap((file) =>
            unnamedLabelGroupControls(
                JSON.parse(fs.readFileSync(file, 'utf8')),
                path.relative(resourcesDir, file).split(path.sep).join('/'),
            ),
        );
        expect(unnamed).toEqual([]);
    });
});
