/**
 * 눌러 쓰는 비단추 요소의 손 모양 커서
 *
 * Tailwind v4 는 커서를 주지 않는다. 클릭을 받는 Flex·Grid, 눌러 쓰는 카드(`card-clickable`), 본문 확대 이미지
 * (`data-g7-zoom`)가 화살표 커서였다. 부품이 클릭을 받을 때 `cursor-pointer` 를 더하고, 의미 클래스와 바탕 규칙이 커서를 준다.
 *
 * 조합 요약(마커 아님, 평문): element=flex|grid|zoom_image
 * 효과 요약(마커 아님, 평문): clickable_element_shows_pointer_cursor
 */
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { Flex } from '../layout/Flex';
import { Grid } from '../layout/Grid';

const css = fs.readFileSync(path.resolve(__dirname, '../../styles/main.css'), 'utf8');

describe('눌러 쓰는 요소의 커서', () => {
  // @scenario element=flex
  // @effects clickable_element_shows_pointer_cursor
  it('Flex 는 클릭을 받을 때만 cursor-pointer 를 더한다', () => {
    const { container, rerender } = render(<Flex onClick={() => {}}>가</Flex>);
    expect((container.firstChild as HTMLElement).className.split(/\s+/)).toContain('cursor-pointer');
    rerender(<Flex>가</Flex>);
    expect((container.firstChild as HTMLElement).className.split(/\s+/)).not.toContain('cursor-pointer');
  });

  // @scenario element=grid
  // @effects clickable_element_shows_pointer_cursor
  it('Grid 는 클릭을 받을 때만 cursor-pointer 를 더한다', () => {
    const { container, rerender } = render(<Grid onClick={() => {}}>가</Grid>);
    expect((container.firstChild as HTMLElement).className.split(/\s+/)).toContain('cursor-pointer');
    rerender(<Grid>가</Grid>);
    expect((container.firstChild as HTMLElement).className.split(/\s+/)).not.toContain('cursor-pointer');
  });

  // @scenario element=zoom_image
  // @effects clickable_element_shows_pointer_cursor
  it('본문 확대 이미지(data-g7-zoom)는 확대 커서를 받는다', () => {
    expect(css).toMatch(/\[data-g7-zoom\]\s*\{\s*cursor:\s*zoom-in;/);
  });
});
