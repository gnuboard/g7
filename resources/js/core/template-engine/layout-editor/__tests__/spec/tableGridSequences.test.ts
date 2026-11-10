/**
 * tableGridSequences.test.ts — 표 편집 연산을 이어 붙여도 격자가 늘 유효한가(속성 시험)
 *
 * 결함(#869 λ 실측): 병합 → 열 추가 → 병합 시작 행 삭제 순서에서 칸 하나가 빠지고 구조 경고(hole)가 났다.
 * 한 연산씩 재는 시험(tableGridModel, tableGridSpanEdges)은 각자 통과해도 연산을 잇는 순서에서 드러나는 결함이
 * 있어, 작은 표에 병합, 열 추가, 행 추가, 행 삭제, 열 삭제를 정해진 시드의 무작위 순서로 이어 붙이고 매 단계
 * 격자가 겹침이나 구멍 없이 유효한지, 행 수와 열 수가 연산의 뜻대로 바뀌는지 잰다. 실패하면 시드와 연산 기록을
 * 메시지에 남긴다.
 */

import { describe, it, expect } from 'vitest';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { treeToGrid } from '../../spec/tableGridModel';
import { addColumn, addRow, mergeCells, moveColumn, moveRow, removeColumn, removeRow, unmergeCell } from '../../spec/tableGridMutations';

const PARAMS = null; // 표준 HTML 어휘 기본값

function td(text: string): EditorNode {
  return { type: 'basic', name: 'Td', text };
}
/** rows × cols 크기의 Tbody 표. */
function grid(rows: number, cols: number): EditorNode {
  return {
    type: 'basic',
    name: 'Table',
    children: [
      {
        type: 'basic',
        name: 'Tbody',
        children: Array.from({ length: rows }, (_, r) => ({
          type: 'basic',
          name: 'Tr',
          children: Array.from({ length: cols }, (__, c) => td(`${r}${c}`)),
        })),
      },
    ],
  };
}

/** 맨 위에 머리글 섹션(Thead, Th 셀 한 행)을 붙인 표. */
function withHeader(t: EditorNode): EditorNode {
  const body = (t.children as EditorNode[])[0]!;
  const firstRow = (body.children as EditorNode[])[0]!;
  const cols = (firstRow.children as EditorNode[]).length;
  const thead: EditorNode = { type: 'basic', name: 'Thead', children: [{ type: 'basic', name: 'Tr', children: Array.from({ length: cols }, (_, c) => ({ type: 'basic', name: 'Th', text: `h${c}` })) }] };
  return { ...t, children: [thead, ...((t.children ?? []) as EditorNode[])] };
}

/** 정해진 시드의 의사 난수(mulberry32). */
function rng(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function shape(t: EditorNode): { rows: number; cols: number; valid: boolean; issues: string[] } {
  const g = treeToGrid(t, PARAMS);
  return { rows: g.rows.length, cols: g.colCount, valid: g.valid, issues: g.issues };
}

describe('표 편집 연산 순서 — λ 실측 재현', () => {
  /**
   * @scenario subject=table_grid_sequences
   * @effects grid_merge_add_col_remove_origin_row_stays_valid
   */
  it('세로 병합 → 병합 왼쪽에 열 추가 → 병합 시작 행 삭제: 칸이 빠지지 않고 격자가 유효하다', () => {
    let t = grid(3, 3);
    const merged = mergeCells(t, PARAMS, 0, 1, 1, 1); // (0,1)-(1,1) 세로 병합
    expect(merged.ok).toBe(true);
    t = addColumn(merged.table, PARAMS, 0); // 0열과 병합 열 사이에 새 열
    expect(shape(t)).toMatchObject({ rows: 3, cols: 4, valid: true });
    t = removeRow(t, PARAMS, 0); // 병합이 시작하는 행 삭제
    const s = shape(t);
    expect(s.issues).toEqual([]);
    expect(s).toMatchObject({ rows: 2, cols: 4, valid: true });
  });
});

describe('표 편집 연산 순서 — 머리글 섹션을 가로지르는 병합', () => {
  /**
   * @scenario subject=table_grid_sequences
   * @effects grid_merge_across_sections_rejected
   */
  it('머리글(Thead) 행과 본문(Tbody) 행을 잇는 세로 병합은 거부하고 표를 바꾸지 않는다', () => {
    const t = withHeader(grid(2, 3));
    const res = mergeCells(t, PARAMS, 0, 1, 1, 1);
    expect(res.ok).toBe(false);
    expect(res.reason).toBe('section');
    expect(res.table).toBe(t);
  });

  /**
   * @scenario subject=table_grid_sequences
   * @effects grid_merge_add_col_remove_origin_row_stays_valid
   */
  it('λ 실측 순서(머리글 표): 병합 → 열 추가 → 병합 시작 행 삭제 뒤에도 구멍이 없다', () => {
    let t = withHeader(grid(2, 3));
    const merged = mergeCells(t, PARAMS, 0, 2, 1, 2);
    t = merged.ok ? merged.table : t;
    t = addColumn(t, PARAMS, -1);
    t = removeRow(t, PARAMS, 0);
    expect(shape(t).issues).toEqual([]);
  });
});

describe('표 편집 연산 순서 — origin 셀이 하나도 없는(통째로 덮인) 행', () => {
  /**
   * @scenario subject=table_grid_sequences
   * @effects grid_fully_absorbed_row_keeps_its_grid_row
   */
  it('2×2 병합이 한 행을 통째로 덮은 표에 그 병합을 가로지르는 열을 넣으면 병합만 넓어지고 덮인 행에 칸이 생기지 않는다', () => {
    // 1행(10~11 병합, rowSpan 2)이 2행을 통째로 덮어 2행 Tr 은 셀이 없다.
    let t = grid(4, 3);
    t = mergeCells(t, PARAMS, 1, 0, 2, 1).table;
    t = removeColumn(t, PARAMS, 2);
    expect(shape(t)).toMatchObject({ rows: 4, cols: 2, valid: true });
    t = addColumn(t, PARAMS, 0); // 병합 블록 안 경계
    const s = shape(t);
    expect(s.issues).toEqual([]);
    expect(s).toMatchObject({ rows: 4, cols: 3, valid: true });
  });

  /**
   * @scenario subject=table_grid_sequences
   * @effects grid_fully_absorbed_row_keeps_its_grid_row
   */
  it('통째로 덮인 행이 낀 병합 블록을 아래로 옮겨도 덮인 행이 블록과 함께 움직인다', () => {
    let t = grid(4, 2);
    t = mergeCells(t, PARAMS, 0, 0, 1, 1).table; // 0행 블록이 1행을 통째로 덮는다
    t = moveRow(t, PARAMS, 0, 1);
    const s = shape(t);
    expect(s.issues).toEqual([]);
    expect(s).toMatchObject({ rows: 4, cols: 2, valid: true });
    expect(treeToGrid(t, PARAMS).rows[1]!.cells[0]!.rowSpan).toBe(2);
  });
});

describe('표 편집 연산 순서 — 무작위 이어 붙이기(속성)', () => {
  const SEEDS = Array.from({ length: 40 }, (_, i) => i + 1);
  /**
   * @scenario subject=table_grid_sequences
   * @effects grid_random_op_sequences_keep_grid_valid
   */
  it.each(SEEDS)('시드 %i: 연산 40개(병합, 해제, 행과 열의 추가, 삭제, 이동)를 이어도 매 단계 격자가 유효하고 행과 열 수가 뜻대로 바뀐다', (seed) => {
    const rand = rng(seed);
    const pick = (n: number) => Math.floor(rand() * n);
    let t = seed % 2 === 0 ? withHeader(grid(3, 3)) : grid(3, 3);
    const log: string[] = [];
    for (let step = 0; step < 40; step++) {
      const before = shape(t);
      const op = pick(8);
      let next = t;
      let expectRows = before.rows;
      let expectCols = before.cols;
      if (op === 0) {
        const r0 = pick(before.rows), c0 = pick(before.cols);
        const r1 = Math.min(before.rows - 1, r0 + pick(2)), c1 = Math.min(before.cols - 1, c0 + pick(2));
        const res = mergeCells(t, PARAMS, r0, c0, r1, c1);
        log.push(`merge(${r0},${c0},${r1},${c1})${res.ok ? '' : ' x'}`);
        next = res.ok ? res.table : t;
      } else if (op === 1) {
        if (before.cols >= 6) continue;
        const at = pick(before.cols + 1) - 1; // -1 은 맨 앞 삽입
        log.push(`addColumn(${at})`);
        next = addColumn(t, PARAMS, at);
        expectCols += 1;
      } else if (op === 2) {
        if (before.rows >= 6) continue;
        const at = pick(before.rows + 1) - 1; // -1 은 맨 앞 삽입
        log.push(`addRow(${at})`);
        next = addRow(t, PARAMS, at);
        expectRows += 1;
      } else if (op === 3) {
        const at = pick(before.rows);
        log.push(`removeRow(${at})`);
        next = removeRow(t, PARAMS, at);
        if (before.rows > 1) expectRows -= 1;
      } else if (op === 4) {
        const at = pick(before.cols);
        log.push(`removeColumn(${at})`);
        next = removeColumn(t, PARAMS, at);
        if (before.cols > 1) expectCols -= 1;
      } else if (op === 5) {
        const r = pick(before.rows), c = pick(before.cols);
        log.push(`unmerge(${r},${c})`);
        next = unmergeCell(t, PARAMS, r, c);
      } else if (op === 6) {
        const at = pick(before.rows), dir = rand() < 0.5 ? -1 : 1;
        log.push(`moveRow(${at},${dir})`);
        next = moveRow(t, PARAMS, at, dir as -1 | 1);
      } else {
        const at = pick(before.cols), dir = rand() < 0.5 ? -1 : 1;
        log.push(`moveColumn(${at},${dir})`);
        next = moveColumn(t, PARAMS, at, dir as -1 | 1);
      }
      const after = shape(next);
      const trail = `seed=${seed} ${log.join(' → ')}`;
      expect(after.issues, trail).toEqual([]);
      expect(after.valid, trail).toBe(true);
      expect(after.rows, `${trail} (행 수)`).toBe(expectRows);
      // 열 삭제는 그 열을 다 덮는 가로 병합만 줄이므로 열 수가 뜻대로(1 감소) 바뀌어야 한다.
      expect(after.cols, `${trail} (열 수)`).toBe(expectCols);
      t = next;
    }
  });
});
