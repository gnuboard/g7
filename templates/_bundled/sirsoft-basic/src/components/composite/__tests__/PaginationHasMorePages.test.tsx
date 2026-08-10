import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Pagination } from '../Pagination';

/**
 * hasMorePages 와 last_page 의 우선순위 회귀 (#519)
 *
 * 총 건수가 정확해 `last_page` 를 아는 목록은 페이지 산술이 언제나 옳다. 그때 응답에
 * `has_more_pages` 가 없어 레이아웃이 `?? false` 로 채워 넣더라도 그 false 가 "다음" 을
 * 막아서는 안 된다. `has_more_pages` 가 필요한 자리는 `last_page` 를 모르는 목록뿐이다.
 *
 * 실측 회귀 (2026-08-03): `/user/orders` 응답의 pagination 에 `has_more_pages` 가 없어
 * 1/2 페이지인데 다음 버튼이 비활성이었다. 마이페이지 주문·문의·내글 3 화면이 같은 증상.
 */
describe('Pagination — hasMorePages 와 last_page 의 우선순위', () => {
  const next = () => screen.getByLabelText('다음 페이지');

  it('last_page 를 알면 hasMorePages=false 여도 다음이 열려 있다', () => {
    render(
      <Pagination currentPage={1} totalPages={2} hasMorePages={false} onPageChange={vi.fn()} />
    );

    expect(next()).not.toBeDisabled();
  });

  it('last_page 를 알고 마지막 페이지면 다음이 막힌다', () => {
    render(
      <Pagination currentPage={2} totalPages={2} hasMorePages={false} onPageChange={vi.fn()} />
    );

    expect(next()).toBeDisabled();
  });

  it('last_page 가 null 이면 hasMorePages 가 다음 이동을 정한다', () => {
    const { rerender } = render(
      <Pagination currentPage={5} totalPages={null} hasMorePages={true} onPageChange={vi.fn()} />
    );
    expect(next()).not.toBeDisabled();

    rerender(
      <Pagination currentPage={5} totalPages={null} hasMorePages={false} onPageChange={vi.fn()} />
    );
    expect(next()).toBeDisabled();
  });

  it('last_page 가 null 이고 hasMorePages 도 없으면 다음이 막힌다', () => {
    render(<Pagination currentPage={1} totalPages={null} onPageChange={vi.fn()} />);

    expect(next()).toBeDisabled();
  });
});
