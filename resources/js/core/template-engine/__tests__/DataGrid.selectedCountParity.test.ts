/**
 * 선택 건수 표기 중복 가드 (저장소 전역 레이아웃 계약)
 *
 * `DataGrid` 는 `selectable` 이 켜지고 선택된 행이 있으면 **자체 선택 건수 줄**을 그린다
 * (`DataGrid.tsx` 의 카드형·표형 두 렌더 지점). 그런데 관리자 목록 화면은 대부분 그 위에
 * 일괄 처리 바(`bulk-actions-bar`)를 따로 두고 거기에도 「N개 선택됨」을 적는다. 둘이
 * 겹치면 같은 문장이 화면에 **두 번** 뜬다 — PO 제보(2026-09-15, 1:1 문의 게시글 관리).
 *
 * 해소 관례는 `selectedCountText=""` 다. 빈 문자열이면 `selectedCountText ?? t(...)` 의
 * `??` 가 폴백하지 않아 `resolvedSelectedCountText` 가 falsy 가 되고, 두 렌더 지점의
 * `&& resolvedSelectedCountText` 가드가 함께 꺼진다. 저장소의 22벌 중 17벌이 이미 이
 * 방식을 쓰고 있었고, 빠진 4벌이 중복을 냈다.
 *
 * 이 테스트는 모집단을 **저장소에서 파생**한다 — 손으로 적은 목록이 아니므로 새 관리자
 * 목록이 같은 구조로 들어오면 자동으로 검사 대상이 된다. 항목 하나를 되돌리면 red 다.
 *
 * @scenario surface=population
 * @scenario surface=parity
 * @effects selectable_layout_population_is_derived_from_repository,
 *          layout_owned_selected_count_suppresses_datagrid_builtin
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..', '..', '..', '..', '..',
);

/** 저작본(`.g7.html`)이 있는 레이아웃 뿌리 — 짝 `.json` 은 여기서 생성되므로 저작본만 본다. */
const LAYOUT_ROOTS = [
  'resources/layouts',
  'templates/_bundled',
  'modules/_bundled',
  'plugins/_bundled',
];

/**
 * 뿌리 아래 `.g7.html` 저작본을 전부 모은다.
 *
 * @param dir 훑을 디렉토리 절대경로
 * @param out 수집 결과가 쌓이는 배열
 * @returns void
 */
function collect(dir: string, out: string[]): void {
  let entries: fs.Dirent[];
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch {
    return;
  }
  for (const entry of entries) {
    // 빌드 산출물·의존성은 저작본이 아니다.
    if (entry.name === 'node_modules' || entry.name === 'dist' || entry.name === 'vendor') continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collect(full, out);
    else if (entry.name.endsWith('.g7.html')) out.push(full);
  }
}

/** `<data-grid …>` 여는 태그 하나를 통째로 뽑는다(속성 순서 무관). */
const DATA_GRID_TAG = /<data-grid\b[^>]*>/g;
/** 값이 비어 있지 않은 `selectable` — `false`·빈 값은 선택 기능이 꺼진 것이다. */
const SELECTABLE_ATTR = /\bselectable\s*=\s*"([^"]*)"/;
/** 레이아웃이 스스로 「N개 선택됨」을 적는가. */
const OWN_COUNT = /selected_count/;
/** DataGrid 내장 줄을 끄는 관례. */
const SUPPRESSED = /selectedCountText\s*=\s*""/;

describe('DataGrid 선택 건수 표기 — 레이아웃과 중복되지 않는다', () => {
  const files: string[] = [];
  for (const root of LAYOUT_ROOTS) collect(path.join(REPO_ROOT, root), files);

  /** 선택 가능한 data-grid 를 가진 저작본만 추린다. */
  const selectableLayouts = files.filter((file) => {
    const src = fs.readFileSync(file, 'utf8');
    const tags = src.match(DATA_GRID_TAG);
    if (!tags) return false;
    return tags.some((tag) => {
      const m = tag.match(SELECTABLE_ATTR);
      if (!m) return false;
      const value = m[1].trim();
      return value !== '' && value !== 'false';
    });
  });

  // @scenario surface=population
  // @effects selectable_layout_population_is_derived_from_repository
  it('모집단이 비어 있지 않다', () => {
    // 파생이 빈 배열을 돌려주면 아래 단언이 조용히 통과한다 — 그 구멍을 먼저 막는다.
    expect(selectableLayouts.length).toBeGreaterThan(10);
  });

  // @scenario surface=parity
  // @effects layout_owned_selected_count_suppresses_datagrid_builtin
  it('자체 선택 건수를 적는 레이아웃은 DataGrid 내장 줄을 끈다', () => {
    const offenders = selectableLayouts
      .filter((file) => {
        const src = fs.readFileSync(file, 'utf8');
        return OWN_COUNT.test(src) && !SUPPRESSED.test(src);
      })
      .map((file) => path.relative(REPO_ROOT, file).replace(/\\/g, '/'))
      .sort();

    expect(offenders).toEqual([]);
  });
});
