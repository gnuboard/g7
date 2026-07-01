/**
 * lineDiff.test.ts — (버전 비교 diff 엔진)
 *
 * 검증 대상: computeLineDiff (LCS 라인 diff) + stableStringify
 *  - 동일 입력 → identical
 *  - 라인 추가/삭제/변경 카운트 정확
 *  - hunk 헤더(@@ -a,b +c,d @@) + context 라인 경계
 *  - 라인 번호(old/new) 부여 정확
 *  - stableStringify 키 정렬(동일 내용·다른 키순 → diff 없음), 배열 순서 보존
 *  - 과대 입력 too_large 가드
 *
 * @effects line_diff_lcs_counts_added_removed_and_builds_hunks, stable_stringify_sorts_keys_preserves_array_order
 */

import { describe, it, expect } from 'vitest';
import {
  computeLineDiff,
  stableStringify,
  DIFF_MAX_LINES,
} from '../../utils/lineDiff';

describe('computeLineDiff', () => {
  it('동일 입력은 identical', () => {
    const text = 'a\nb\nc';
    const d = computeLineDiff(text, text);
    expect(d.identical).toBe(true);
    expect(d.addedCount).toBe(0);
    expect(d.removedCount).toBe(0);
    expect(d.hunks).toEqual([]);
  });

  it('라인 추가/삭제 카운트가 정확하다', () => {
    const oldText = 'line1\nline2\nline3';
    const newText = 'line1\nline2-changed\nline3\nline4';
    const d = computeLineDiff(oldText, newText);
    expect(d.identical).toBe(false);
    // line2 → line2-changed (remove 1 + add 1), line4 추가(add 1)
    expect(d.addedCount).toBe(2);
    expect(d.removedCount).toBe(1);
  });

  it('hunk 헤더와 라인 종류/번호를 부여한다', () => {
    const oldText = 'a\nb\nc';
    const newText = 'a\nB\nc';
    const d = computeLineDiff(oldText, newText, 1);
    expect(d.hunks.length).toBe(1);
    const hunk = d.hunks[0];
    expect(hunk.header).toMatch(/^@@ -\d+,\d+ \+\d+,\d+ @@$/);
    const kinds = hunk.lines.map((l) => l.kind);
    expect(kinds).toContain('remove');
    expect(kinds).toContain('add');
    expect(kinds).toContain('context');
    // remove 행은 oldLine 있고 newLine null, add 행은 반대
    const rm = hunk.lines.find((l) => l.kind === 'remove')!;
    const ad = hunk.lines.find((l) => l.kind === 'add')!;
    expect(rm.oldLine).not.toBeNull();
    expect(rm.newLine).toBeNull();
    expect(ad.oldLine).toBeNull();
    expect(ad.newLine).not.toBeNull();
  });

  it('전체 추가(빈 → 내용)는 모든 라인이 add', () => {
    const d = computeLineDiff('', 'x\ny');
    expect(d.addedCount).toBe(2);
    expect(d.removedCount).toBe(0);
    const lines = d.hunks.flatMap((h) => h.lines);
    expect(lines.every((l) => l.kind === 'add')).toBe(true);
  });

  it('큰 파일이라도 변경이 작으면 정상 표시(공통 prefix/suffix 트리밍) — too_large 아님', () => {
    // DIFF_MAX_LINES 를 훌쩍 넘는 큰 파일이지만 끝에 1줄만 추가 — 변경 영역은 1줄뿐.
    const big = Array.from({ length: DIFF_MAX_LINES + 100 }, (_, i) => `l${i}`).join('\n');
    const d = computeLineDiff(big, big + '\nextra');
    expect(d.addedCount).toBe(1); // too_large(-1) 아님
    expect(d.removedCount).toBe(0);
    // 추가된 'extra' 라인이 hunk 에 나타난다
    const addLine = d.hunks.flatMap((h) => h.lines).find((l) => l.kind === 'add');
    expect(addLine?.content).toBe('extra');
  });

  it('큰 파일의 작은 변경(가운데 1줄 수정)도 정상 — 라인 번호 정확', () => {
    const total = DIFF_MAX_LINES + 100;
    const lines = Array.from({ length: total }, (_, i) => `l${i}`);
    const a = lines.join('\n');
    const mutated = [...lines];
    const mutateAt = total - 50; // 배열 범위 안 가운데~뒤쪽
    mutated[mutateAt] = 'CHANGED';
    const d = computeLineDiff(a, mutated.join('\n'));
    expect(d.addedCount).toBe(1);
    expect(d.removedCount).toBe(1);
    // 변경 라인의 번호가 mutateAt+1 (1-based)
    const rm = d.hunks.flatMap((h) => h.lines).find((l) => l.kind === 'remove');
    expect(rm?.oldLine).toBe(mutateAt + 1);
  });

  it('변경 영역 자체가 과대하면 too_large 가드(addedCount -1)', () => {
    // 공통부 없이 전체가 다름 — 트리밍 후에도 변경 영역이 DIFF_MAX_LINES 초과.
    const a = Array.from({ length: DIFF_MAX_LINES + 1 }, (_, i) => `a${i}`).join('\n');
    const b = Array.from({ length: DIFF_MAX_LINES + 1 }, (_, i) => `b${i}`).join('\n');
    const d = computeLineDiff(a, b);
    expect(d.addedCount).toBe(-1);
    expect(d.hunks).toEqual([]);
  });
});

describe('stableStringify', () => {
  it('키 순서가 달라도 동일 직렬화(diff 노이즈 제거)', () => {
    const a = stableStringify({ b: 1, a: 2 });
    const b = stableStringify({ a: 2, b: 1 });
    expect(a).toBe(b);
  });

  it('배열 순서는 보존(컴포넌트 순서 의미)', () => {
    const a = stableStringify({ items: [1, 2, 3] });
    const b = stableStringify({ items: [3, 2, 1] });
    expect(a).not.toBe(b);
  });

  it('중첩 객체도 키 정렬', () => {
    const s = stableStringify({ z: { y: 1, x: 2 } });
    // x 가 y 보다 먼저 나와야 함
    expect(s.indexOf('"x"')).toBeLessThan(s.indexOf('"y"'));
  });

  it('동일 content·키순만 다름 → computeLineDiff identical', () => {
    const oldText = stableStringify({ components: [{ name: 'Div', id: 'a' }] });
    const newText = stableStringify({ components: [{ id: 'a', name: 'Div' }] });
    expect(computeLineDiff(oldText, newText).identical).toBe(true);
  });
});
