/**
 * @file PageHeader.wrap.test.tsx
 * @description PageHeader 좁은 폭 버튼 줄바꿈 (7.1.0 영역 J D-J9, 관측 O-I1-8)
 */

import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { PageHeader } from '../PageHeader';

// 7.1.0 영역 J D-J9: 태블릿(900px)에서 머리말 버튼이 많으면 줄어들며 글자 단위로 꺾였다(「취\n소」·「저\n장」).
// 머리 줄은 줄바꿈을 허용하고, 버튼 묶음은 버튼이 줄지 않게(nowrap·shrink-0) 다음 줄로 내린다.
describe('PageHeader — 좁은 폭 버튼 줄바꿈', () => {
  // @scenario control=page_header
  // @effects page_header_buttons_wrap_as_units
  it('머리 줄은 flex-wrap, 제목 칸은 min-w-0 이다', () => {
    const { container } = render(<PageHeader title="코드 편집" />);
    const row = container.querySelector('.flex-between') as HTMLElement;
    expect(row.className).toContain('flex-wrap');
    expect((row.firstElementChild as HTMLElement).className).toContain('min-w-0');
  });

  // @scenario control=page_header
  // @effects page_header_buttons_wrap_as_units
  it('스타일에 버튼 묶음 줄바꿈과 버튼 글자 한 줄 규칙이 있다', () => {
    // eslint-disable-next-line @typescript-eslint/no-var-requires
    const css: string = require('fs').readFileSync(require('path').resolve(__dirname, '../../../styles/main.css'), 'utf8');
    expect(css).toMatch(/\.page-actions \{\s*@apply flex flex-wrap items-center gap-3;/);
    expect(css).toMatch(/\.page-actions > \* \{\s*@apply shrink-0 whitespace-nowrap;/);
  });
});
