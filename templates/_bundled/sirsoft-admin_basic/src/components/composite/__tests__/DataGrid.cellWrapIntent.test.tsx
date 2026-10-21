// e2e:allow Vitest 컴포넌트 계약 + 빌드 CSS 를 입힌 headless Chromium 측정(출시 전 측정: 표 8848px → 1092px). 화면 재실측은 게시판 글 목록 1400px 에서 한다
/**
 * DataGrid 칸 안의 줄바꿈 의도
 *
 * 데스크톱 표 칸(td)은 `whitespace-nowrap` 이라 그 값이 칸 안 글까지 상속된다. 그래서 글에 단 줄바꿈 클래스
 * (`wrap-anywhere`·`break-words`·`break-all`)가 아무 일도 하지 않았다 — 게시판 글 목록에서 띄어쓰기 없는 긴 제목이
 * 한 줄로 늘어 표가 컨테이너보다 넓어졌다(7.1.1 출시 전 실측 결함 3: 제목 열 989px·표 1602px > 1094px).
 *
 * 칸에 `data-grid-cell` 표식을 달고, main.css 의 components 층 규칙이 그 안의 줄바꿈 클래스 요소만 white-space 를
 * 되돌린다. jsdom 은 층·상속 계산을 하지 않으므로 여기서는 표식과 규칙의 짝을 잠그고, 실제 폭은 빌드 CSS 로 잰다.
 *
 * @scenario-manifest tests/scenarios/datagrid-cell-wrap-intent.yaml
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render } from '@testing-library/react';
import { DataGrid } from '../DataGrid';

const css = readFileSync(resolve(__dirname, '../../../styles/main.css'), 'utf-8');

/** main.css 에서 `@layer components { … }` 블록 본문만 꺼낸다(중괄호 짝을 센다). */
function componentsLayer(source: string): string {
  const start = source.indexOf('@layer components {');
  expect(start, '@layer components 블록이 없습니다').toBeGreaterThanOrEqual(0);
  let depth = 0;
  for (let i = source.indexOf('{', start); i < source.length; i++) {
    if (source[i] === '{') depth++;
    if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  return '';
}

describe('DataGrid 칸 안의 줄바꿈 의도', () => {
  // @scenario surface=desktop_cell
  // @effects cell_marked_for_wrap_intent
  it('데스크톱 데이터 칸은 nowrap 이고 data-grid-cell 표식을 단다', () => {
    const { container } = render(
      <DataGrid columns={[{ field: 'title', header: '제목' }, { field: 'hits', header: '조회' }]} data={[{ id: 1, title: 'LONG가가가', hits: 3 }]} />,
    );
    const cells = Array.from(container.querySelectorAll('tbody td'));
    expect(cells.length).toBeGreaterThanOrEqual(2);
    for (const td of cells) {
      expect(td.className.split(/\s+/)).toContain('data-grid-cell');
      expect(td.className.split(/\s+/)).toContain('whitespace-nowrap');
    }
  });

  // @scenario surface=desktop_cell
  // @effects wrap_classes_restore_white_space
  it('main.css components 층이 칸 안 줄바꿈 클래스 요소의 white-space 를 normal 로 되돌린다', () => {
    const layer = componentsLayer(css);
    const rule = /\.data-grid-cell\s+:is\(([^)]*)\)\s*\{\s*white-space:\s*normal;?\s*\}/.exec(layer);
    expect(rule, 'components 층에 .data-grid-cell 줄바꿈 규칙이 없습니다').not.toBeNull();
    const classes = rule![1].split(',').map((c) => c.trim());
    for (const cls of ['.wrap-anywhere', '.break-words', '.break-all']) expect(classes).toContain(cls);
  });

  // @scenario surface=desktop_cell
  // @effects explicit_nowrap_still_wins
  it('규칙은 층 밖에 두지 않는다 — 같은 요소의 truncate·whitespace-nowrap(utilities 층)이 이겨 한 줄을 지킨다', () => {
    const outside = css.replace(componentsLayer(css), '');
    expect(outside).not.toMatch(/\.data-grid-cell\s+:is\(/);
  });
});
