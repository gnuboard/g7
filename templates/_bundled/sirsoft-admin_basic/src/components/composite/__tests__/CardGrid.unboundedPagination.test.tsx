/**
 * CardGrid — 총 건수를 상한까지만 센 목록의 페이저 (`serverHasMorePages`)
 *
 * 서버가 총 건수를 상한까지만 세면 마지막 페이지를 계산할 수 없어 `last_page` 가 null 로 오고, 다음 페이지가 있는지는
 * `has_more_pages` 로만 알린다. DataGrid 는 그 값을 받아 「다음」 이동을 열어 두는데, CardGrid 는 이 prop 을 받지 않고
 * null 을 1 로 채워 「1페이지뿐」 으로 그렸다 — 게시판 관리자 목록이 이 prop 을 넘기는데도 2페이지로 갈 수 없었다.
 * DataGrid 와 같은 규칙(마지막 페이지 null 보존, 다음 여부는 서버 값, 페이저 표시 조건)을 잠근다.
 */

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CardGrid } from '../CardGrid';

const cardColumns = [{ id: 'c', cellChildren: [] } as any];
const rows = [{ id: 1 }, { id: 2 }];

describe('CardGrid — 마지막 페이지를 모르는 서버 목록', () => {
  beforeEach(() => {
    (window as any).G7Core = {
      ...(window as any).G7Core,
      renderItemChildren: () => null,
      evaluateCondition: () => true,
    };
  });

  // @scenario area=pagination
  // @effects card_grid_unbounded_pagination_follows_server_has_more
  it('마지막 페이지가 null 이고 다음이 있으면 페이저를 그리고 「다음」 으로 2페이지를 부른다', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(
      <CardGrid
        data={rows}
        cardColumns={cardColumns}
        pagination
        serverSidePagination
        serverCurrentPage={1}
        serverTotalPages={null}
        serverHasMorePages
        onPageChange={onPageChange}
      />
    );

    const next = screen.getByLabelText('다음 페이지');
    expect(next).not.toBeDisabled();
    // 아는 범위(1, 다음 2)만 번호로 그리고, 마지막 페이지 번호는 그리지 않는다
    expect(screen.getByLabelText('페이지 2')).toBeInTheDocument();

    await user.click(next);
    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  // @scenario area=pagination
  // @effects card_grid_unbounded_pagination_follows_server_has_more
  it('마지막 페이지가 null 이고 다음이 없으면 「다음」 이 닫히지만 2페이지 이상이면 페이저는 남는다', () => {
    render(
      <CardGrid
        data={rows}
        cardColumns={cardColumns}
        pagination
        serverSidePagination
        serverCurrentPage={3}
        serverTotalPages={null}
        serverHasMorePages={false}
        onPageChange={vi.fn()}
      />
    );

    expect(screen.getByLabelText('다음 페이지')).toBeDisabled();
    expect(screen.getByLabelText('이전 페이지')).not.toBeDisabled();
    expect(screen.getByLabelText('페이지 3')).toHaveAttribute('aria-current', 'page');
  });

  // @scenario area=pagination
  // @effects card_grid_unbounded_pagination_follows_server_has_more
  it('1페이지이고 다음이 없고 마지막 페이지를 모르면 페이저를 감춘다(alwaysShowPagination 이 없을 때)', () => {
    render(
      <CardGrid
        data={rows}
        cardColumns={cardColumns}
        pagination
        serverSidePagination
        serverCurrentPage={1}
        serverTotalPages={null}
        serverHasMorePages={false}
        onPageChange={vi.fn()}
      />
    );

    expect(screen.queryByLabelText('다음 페이지')).not.toBeInTheDocument();
  });

  // @scenario area=pagination
  // @effects card_grid_unbounded_pagination_follows_server_has_more
  it('alwaysShowPagination 이 있으면 마지막 페이지를 몰라도 다음 여부를 서버 값으로 연다', () => {
    render(
      <CardGrid
        data={rows}
        cardColumns={cardColumns}
        pagination
        serverSidePagination
        serverCurrentPage={1}
        serverTotalPages={null}
        serverHasMorePages
        alwaysShowPagination
        onPageChange={vi.fn()}
      />
    );

    expect(screen.getByLabelText('다음 페이지')).not.toBeDisabled();
  });

  // @scenario area=pagination
  // @effects card_grid_unbounded_pagination_follows_server_has_more
  it('마지막 페이지를 알면 그 값이 먼저다 — 다음 여부가 빠져(false) 와도 1/5 페이지의 「다음」 은 열린다', () => {
    render(
      <CardGrid
        data={rows}
        cardColumns={cardColumns}
        pagination
        serverSidePagination
        serverCurrentPage={1}
        serverTotalPages={5}
        serverHasMorePages={false}
        onPageChange={vi.fn()}
      />
    );

    expect(screen.getByLabelText('다음 페이지')).not.toBeDisabled();
    expect(screen.getByText('5')).toBeInTheDocument();
  });

  // @scenario area=pagination
  // @effects card_grid_unbounded_pagination_follows_server_has_more
  it('모든 Pagination 렌더 경로가 hasMorePages 를 넘기고 totalPages 를 1 로 채우지 않는다', () => {
    const source = fs.readFileSync(path.resolve(__dirname, '../CardGrid.tsx'), 'utf8');
    const blocks: string[] = [];
    let from = 0;
    for (;;) {
      const start = source.indexOf('<Pagination', from);
      if (start === -1) break;
      const end = source.indexOf('/>', start);
      blocks.push(source.slice(start, end + 2));
      from = end + 2;
    }

    expect(blocks.length).toBeGreaterThan(0);
    for (const block of blocks) {
      expect(block).toContain('hasMorePages');
      expect(block).not.toMatch(/totalPages=\{[^}]*\?\?\s*1\s*\}/);
    }
    // 서버 값의 1 폴백도 없다 — null 은 「마지막 페이지를 모름」 이다
    expect(source).not.toMatch(/serverTotalPages\s*\?\?\s*1\b/);
  });
});
