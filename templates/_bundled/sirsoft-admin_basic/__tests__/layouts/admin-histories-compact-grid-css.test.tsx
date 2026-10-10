/**
 * @file admin-histories-compact-grid-css.test.tsx
 * @description 이력 표 촘촘 서식(`.histories-compact-grid`)이 표 칸의 기본 여백을 실제로 이기는지 잠근다.
 *
 * 7.1.0 전수 실측 K2 관측 O-K2-7: 신고 상세 「처리 이력」 표가 1024px 에서 칸보다 2px 넓어
 * 가로 스크롤이 생겼다(표 673.29px, 칸 671px). 원인은 촘촘 서식 규칙이 `@layer components`
 * 안에 있어, 표 칸(td·th)에 붙은 유틸리티 클래스 `px-6 py-4`(`@layer utilities`)에 밀려 한 번도
 * 적용되지 않았던 것이다. 캐스케이드 층 순서상 유틸리티 층이 컴포넌트 층을 이기므로 명시도로는
 * 풀 수 없다 — 규칙을 층 밖(층 없는 규칙은 모든 층을 이긴다)에 둬야 한다.
 *
 * jsdom 은 캐스케이드 층을 계산하지 않으므로 여기서는 「규칙이 층 밖에 선언돼 있는가」를 단언하고,
 * 실제 여백(12px)과 표 폭은 브라우저 실측으로 확인한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const css = fs.readFileSync(path.resolve(__dirname, '../../src/styles/main.css'), 'utf8');

/**
 * 주어진 위치를 감싸는 `@layer` 이름 목록을 돌려준다(주석·문자열은 단순화해 무시).
 *
 * @param source CSS 원문
 * @param index 규칙 시작 위치
 * @returns 바깥부터 안쪽 순서의 층 이름
 */
function enclosingLayers(source: string, index: number): string[] {
  const stack: Array<string | null> = [];
  const stripped = source.slice(0, index).replace(/\/\*[\s\S]*?\*\//g, (m) => ' '.repeat(m.length));
  let headerStart = 0;
  for (let i = 0; i < stripped.length; i++) {
    const ch = stripped[i];
    if (ch === '{') {
      const header = stripped.slice(headerStart, i).trim();
      const layer = header.match(/@layer\s+([\w-]+)\s*$/);
      stack.push(layer ? layer[1] : null);
      headerStart = i + 1;
    } else if (ch === '}') {
      stack.pop();
      headerStart = i + 1;
    } else if (ch === ';') {
      headerStart = i + 1;
    }
  }
  return stack.filter((name): name is string => name !== null);
}

describe('[case:histories-compact-grid-beats-utilities] 이력 표 촘촘 서식', () => {
  /**
   * @scenario placement=unlayered
   * @effects compact_padding_beats_cell_utility_padding
   */
  it('`.histories-compact-grid` 의 칸 여백 규칙은 캐스케이드 층 밖에 있다', () => {
    const match = /\.histories-compact-grid\s+:is\(th,\s*td\)\s*\{([\s\S]*?)\}/.exec(css);
    expect(match).not.toBeNull();
    expect(enclosingLayers(css, match!.index)).toEqual([]);
  });

  /**
   * @scenario placement=unlayered
   * @effects compact_padding_beats_cell_utility_padding
   */
  it('칸 여백은 좌우 px-3·위아래 py-2 다', () => {
    const body = /\.histories-compact-grid\s+:is\(th,\s*td\)\s*\{([\s\S]*?)\}/.exec(css)?.[1] ?? '';
    expect(body).toContain('px-3');
    expect(body).toContain('py-2');
  });
});
