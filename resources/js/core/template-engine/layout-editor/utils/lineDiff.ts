/**
 * lineDiff.ts — 경량 라인 단위 diff 엔진
 *
 * 두 텍스트(레이아웃 버전 content 의 pretty-print JSON)를 라인 단위로 비교해 GitHub
 * Unified diff 스타일의 행 목록을 만든다. 외부 diff 라이브러리 도입 없이(번들 비대화
 * 회피 + 편집기 CSS 라이브러리 비종속 원칙과 동일 정책) 표준 LCS(최장 공통 부분수열)
 * 알고리즘으로 자체 구현한다.
 *
 * 출력은 hunk(변경 묶음) 배열이며, 각 hunk 는 context/add/remove 행을 담는다. 변경 없는
 * 영역은 hunk 사이에서 접히고(context 라인 수로 제한), GitHub 처럼 `@@ -a,b +c,d @@`
 * 헤더를 동반한다.
 *
 * @since engine-v1.50.0
 */

/** diff 행 종류. */
export type DiffLineKind = 'context' | 'add' | 'remove';

/** diff 행 1건 — Unified diff 의 한 줄. */
export interface DiffLine {
  kind: DiffLineKind;
  /** 구버전(left) 라인 번호 (1-based) — add 행은 null. */
  oldLine: number | null;
  /** 신버전(right) 라인 번호 (1-based) — remove 행은 null. */
  newLine: number | null;
  /** 라인 내용(개행 제외). */
  content: string;
}

/** 변경 묶음(hunk) — GitHub `@@ ... @@` 단위. */
export interface DiffHunk {
  /** `@@ -oldStart,oldCount +newStart,newCount @@` 헤더 문자열. */
  header: string;
  oldStart: number;
  oldCount: number;
  newStart: number;
  newCount: number;
  lines: DiffLine[];
}

/** diff 결과 전체. */
export interface DiffResult {
  hunks: DiffHunk[];
  /** 추가된 총 라인 수. */
  addedCount: number;
  /** 삭제된 총 라인 수. */
  removedCount: number;
  /** 변경 없음(완전 동일) 여부. */
  identical: boolean;
}

/** 원시 diff op — LCS backtrack 결과. */
type RawOp =
  | { kind: 'context'; oldIndex: number; newIndex: number; content: string }
  | { kind: 'remove'; oldIndex: number; content: string }
  | { kind: 'add'; newIndex: number; content: string };

/**
 * 두 라인 배열의 LCS 길이 테이블을 만든다(표준 DP).
 *
 * 큰 입력(수천 라인)에서도 O(n·m) 메모리이지만 레이아웃 content 는 보통 수백 라인이라
 * 충분하다. 매우 큰 입력은 호출 측에서 MAX_LINES 로 가드한다.
 */
function buildLcsTable(a: string[], b: string[]): number[][] {
  const n = a.length;
  const m = b.length;
  // (n+1) x (m+1) 테이블
  const dp: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(m + 1).fill(0));
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      if (a[i] === b[j]) {
        dp[i][j] = dp[i + 1][j + 1] + 1;
      } else {
        dp[i][j] = Math.max(dp[i + 1][j], dp[i][j + 1]);
      }
    }
  }
  return dp;
}

/** LCS 테이블을 backtrack 해 원시 op 시퀀스를 만든다. */
function backtrack(a: string[], b: string[], dp: number[][]): RawOp[] {
  const ops: RawOp[] = [];
  let i = 0;
  let j = 0;
  const n = a.length;
  const m = b.length;
  while (i < n && j < m) {
    if (a[i] === b[j]) {
      ops.push({ kind: 'context', oldIndex: i, newIndex: j, content: a[i] });
      i++;
      j++;
    } else if (dp[i + 1][j] >= dp[i][j + 1]) {
      ops.push({ kind: 'remove', oldIndex: i, content: a[i] });
      i++;
    } else {
      ops.push({ kind: 'add', newIndex: j, content: b[j] });
      j++;
    }
  }
  while (i < n) {
    ops.push({ kind: 'remove', oldIndex: i, content: a[i] });
    i++;
  }
  while (j < m) {
    ops.push({ kind: 'add', newIndex: j, content: b[j] });
    j++;
  }
  return ops;
}

/** 문자열을 라인 배열로 — 마지막 빈 라인은 제거(파일 끝 개행 normalize). */
function splitLines(text: string): string[] {
  const lines = text.replace(/\r\n/g, '\n').split('\n');
  // 끝 개행으로 생긴 빈 마지막 요소 제거
  if (lines.length > 0 && lines[lines.length - 1] === '') lines.pop();
  return lines;
}

/** 안전 가드 — 너무 큰 입력은 diff 를 생략(상위에서 안내). */
export const DIFF_MAX_LINES = 4000;

/**
 * 두 텍스트의 Unified diff 를 계산한다.
 *
 * @param oldText 구버전 텍스트
 * @param newText 신버전 텍스트
 * @param contextLines hunk 앞뒤로 유지할 context 라인 수 (GitHub 기본 3)
 * @return DiffResult — hunk 목록 + 추가/삭제 카운트
 */
export function computeLineDiff(
  oldText: string,
  newText: string,
  contextLines = 3,
): DiffResult {
  const a = splitLines(oldText);
  const b = splitLines(newText);

  // 공통 prefix/suffix 트리밍 — 작은 변경(엘리먼트 1개 추가 등)이면 양끝 대부분이 동일하므로,
  // LCS 입력을 가운데 실제 변경 영역으로 축소한다. 큰 레이아웃(수천 줄)도 변경이 작으면
  // LCS DP 테이블이 작아져 빠르고, too_large 가드도 변경 영역 기준으로만 판정된다(
  // checkout 처럼 6600 줄 레이아웃이 작은 수정에도 "변경 내용이 너무 커서 표시 불가" 로 뜨던 결함).
  let prefix = 0;
  const maxPrefix = Math.min(a.length, b.length);
  while (prefix < maxPrefix && a[prefix] === b[prefix]) prefix++;

  let suffix = 0;
  const maxSuffix = Math.min(a.length, b.length) - prefix;
  while (suffix < maxSuffix && a[a.length - 1 - suffix] === b[b.length - 1 - suffix]) suffix++;

  // 가운데 변경 영역(트리밍 후)
  const midA = a.slice(prefix, a.length - suffix);
  const midB = b.slice(prefix, b.length - suffix);

  // too_large 가드는 "실제 변경 영역" 기준 — 양끝 공통부 제외 후에도 변경 영역이 과대할 때만 발동.
  // (전체 크기가 아니라 변경 규모로 판정하므로, 큰 레이아웃의 작은 수정은 정상 표시된다.)
  if (midA.length > DIFF_MAX_LINES || midB.length > DIFF_MAX_LINES) {
    return { hunks: [], addedCount: -1, removedCount: -1, identical: false };
  }

  const dp = buildLcsTable(midA, midB);
  const ops = backtrack(midA, midB, dp);

  // op 시퀀스 → DiffLine 시퀀스 (라인 번호 부여). prefix 만큼 라인 번호를 오프셋한다.
  const allLines: DiffLine[] = [];
  let oldNo = prefix; // 트리밍한 prefix 다음부터 번호 매김
  let newNo = prefix;
  let addedCount = 0;
  let removedCount = 0;

  // 트리밍된 prefix 공통 라인을 context 로 복원(앞)
  for (let i = 0; i < prefix; i++) {
    allLines.push({ kind: 'context', oldLine: i + 1, newLine: i + 1, content: a[i] });
  }

  for (const op of ops) {
    if (op.kind === 'context') {
      oldNo++;
      newNo++;
      allLines.push({ kind: 'context', oldLine: oldNo, newLine: newNo, content: op.content });
    } else if (op.kind === 'remove') {
      oldNo++;
      removedCount++;
      allLines.push({ kind: 'remove', oldLine: oldNo, newLine: null, content: op.content });
    } else {
      newNo++;
      addedCount++;
      allLines.push({ kind: 'add', oldLine: null, newLine: newNo, content: op.content });
    }
  }

  // 트리밍된 suffix 공통 라인을 context 로 복원(뒤)
  for (let i = 0; i < suffix; i++) {
    const oldIdx = a.length - suffix + i;
    const newIdx = b.length - suffix + i;
    allLines.push({ kind: 'context', oldLine: oldIdx + 1, newLine: newIdx + 1, content: a[oldIdx] });
  }

  const identical = addedCount === 0 && removedCount === 0;

  // 변경 라인 인덱스 집합으로 hunk 경계 산출 (context 라인만으로 둘러쌈)
  const hunks = buildHunks(allLines, contextLines);

  return { hunks, addedCount, removedCount, identical };
}

/** DiffLine 전체 시퀀스 → context 로 둘러싼 hunk 배열로 묶는다. */
function buildHunks(allLines: DiffLine[], contextLines: number): DiffHunk[] {
  const changedIdx: number[] = [];
  for (let i = 0; i < allLines.length; i++) {
    if (allLines[i].kind !== 'context') changedIdx.push(i);
  }
  if (changedIdx.length === 0) return [];

  // 변경 인덱스를 contextLines 로 확장한 구간들을 병합
  const ranges: Array<{ start: number; end: number }> = [];
  for (const idx of changedIdx) {
    const start = Math.max(0, idx - contextLines);
    const end = Math.min(allLines.length - 1, idx + contextLines);
    const last = ranges[ranges.length - 1];
    if (last && start <= last.end + 1) {
      last.end = Math.max(last.end, end);
    } else {
      ranges.push({ start, end });
    }
  }

  return ranges.map((range) => {
    const lines = allLines.slice(range.start, range.end + 1);
    // hunk 의 시작 라인 번호 / 카운트 산출
    let oldStart = 0;
    let newStart = 0;
    let oldCount = 0;
    let newCount = 0;
    for (const line of lines) {
      if (line.oldLine !== null) {
        if (oldStart === 0) oldStart = line.oldLine;
        oldCount++;
      }
      if (line.newLine !== null) {
        if (newStart === 0) newStart = line.newLine;
        newCount++;
      }
    }
    // 빈 측(완전 추가/삭제 hunk) 보정 — start 0 이면 1 로 폴백 표기
    const oldS = oldStart || (oldCount === 0 ? 0 : 1);
    const newS = newStart || (newCount === 0 ? 0 : 1);
    const header = `@@ -${oldS},${oldCount} +${newS},${newCount} @@`;
    return {
      header,
      oldStart: oldS,
      oldCount,
      newStart: newS,
      newCount,
      lines,
    };
  });
}

/**
 * 레이아웃 버전 content(객체)를 diff 비교용 안정 pretty-print JSON 으로 직렬화한다.
 *
 * 키 순서를 정렬해(동일 내용·다른 키순 = diff 없음) 의미 없는 노이즈 diff 를 줄인다.
 * 2-space 들여쓰기는 GitHub diff 가독성과 일관.
 *
 * @param value 직렬화 대상(보통 { components, data_sources, metadata, endpoint })
 * @return 정렬된 pretty JSON 문자열
 */
export function stableStringify(value: unknown): string {
  return JSON.stringify(sortKeysDeep(value), null, 2);
}

/** 객체 키를 재귀적으로 정렬(배열 순서는 보존 — 컴포넌트 순서가 의미를 가짐). */
function sortKeysDeep(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(sortKeysDeep);
  if (value && typeof value === 'object') {
    const obj = value as Record<string, unknown>;
    const sorted: Record<string, unknown> = {};
    for (const key of Object.keys(obj).sort()) {
      sorted[key] = sortKeysDeep(obj[key]);
    }
    return sorted;
  }
  return value;
}
