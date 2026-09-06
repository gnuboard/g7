import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Pagination } from '../Pagination';

/**
 * 마지막 페이지를 모르는 목록의 페이지 번호 목록 회귀
 *
 * 총 건수가 상한을 넘겨 `last_page` 가 `null` 이면 계산할 수 없는 값은 마지막 페이지
 * 번호 하나뿐이다. 1 부터 현재 페이지까지는 확실히 존재하고, 다음 페이지 존재 여부는
 * 서버가 `has_more_pages` 로 알린다. 그런데 종전 구현은 번호 목록을 통째로 비우고
 * 현재 페이지 숫자 하나만 남겨, 운영자가 앞 페이지로 직접 뛰어갈 방법을 잃었다
 * (활동 로그 화면 실측: "총 10000건 이상" 인데 페이저에 "1" 하나).
 *
 * 합의된 동작(CHANGELOG 7.0.10): 마지막 페이지로 바로 뛰는 버튼만 감춘다.
 */
describe('Pagination — last_page 를 모를 때의 페이지 번호 목록', () => {
  // 접근성 이름은 「N페이지」 라벨이므로 번호 버튼은 표시 텍스트로 찾는다
  const pageButton = (n: number) =>
    screen.queryAllByRole('button').find((b) => b.textContent?.trim() === String(n)) ?? null;

  it('1 부터 현재 페이지까지의 번호가 그대로 그려진다', () => {
    render(
      <Pagination currentPage={5} totalPages={null} hasMorePages={true} onPageChange={vi.fn()} />
    );

    for (let n = 1; n <= 5; n++) {
      expect(pageButton(n)).toBeInTheDocument();
    }
    expect(pageButton(5)).toHaveAttribute('aria-current', 'page');
  });

  it('다음 페이지가 있으면 그 번호 하나와 계속됨 표시가 붙고, 그 너머는 그리지 않는다', () => {
    render(
      <Pagination currentPage={5} totalPages={null} hasMorePages={true} onPageChange={vi.fn()} />
    );

    expect(pageButton(6)).toBeInTheDocument();
    expect(pageButton(7)).not.toBeInTheDocument();
    expect(screen.getByText('...')).toBeInTheDocument();
  });

  it('다음 페이지가 없으면 현재 페이지가 끝이다 — 계속됨 표시도 없다', () => {
    render(
      <Pagination currentPage={3} totalPages={null} hasMorePages={false} onPageChange={vi.fn()} />
    );

    expect(pageButton(3)).toBeInTheDocument();
    expect(pageButton(4)).not.toBeInTheDocument();
    expect(screen.queryByText('...')).not.toBeInTheDocument();
  });

  it('첫 페이지에서도 번호가 버튼으로 그려진다', () => {
    render(
      <Pagination currentPage={1} totalPages={null} hasMorePages={true} onPageChange={vi.fn()} />
    );

    expect(pageButton(1)).toBeInTheDocument();
    expect(pageButton(2)).toBeInTheDocument();
  });

  it('깊은 페이지에서는 앞쪽을 접고 현재 페이지 주변만 남긴다', () => {
    render(
      <Pagination
        currentPage={12}
        totalPages={null}
        hasMorePages={true}
        maxVisiblePages={5}
        onPageChange={vi.fn()}
      />
    );

    expect(pageButton(1)).toBeInTheDocument();
    expect(pageButton(2)).not.toBeInTheDocument();
    expect(pageButton(10)).toBeInTheDocument();
    expect(pageButton(11)).toBeInTheDocument();
    expect(pageButton(12)).toBeInTheDocument();
    expect(pageButton(13)).toBeInTheDocument();
    expect(pageButton(14)).not.toBeInTheDocument();
    expect(screen.getAllByText('...')).toHaveLength(2);
  });

  it('앞 페이지 번호와 다음 페이지 번호를 클릭하면 그 페이지로 이동한다', () => {
    const onPageChange = vi.fn();
    render(
      <Pagination currentPage={5} totalPages={null} hasMorePages={true} onPageChange={onPageChange} />
    );

    fireEvent.click(pageButton(3)!);
    expect(onPageChange).toHaveBeenCalledWith(3);

    fireEvent.click(pageButton(6)!);
    expect(onPageChange).toHaveBeenCalledWith(6);
  });

  it('마지막 페이지 점프 버튼만 감춰진다', () => {
    render(
      <Pagination
        currentPage={5}
        totalPages={null}
        hasMorePages={true}
        showFirstLast={true}
        onPageChange={vi.fn()}
      />
    );

    expect(screen.queryByLabelText('마지막 페이지')).not.toBeInTheDocument();
    expect(screen.getByLabelText('이전 페이지')).toBeInTheDocument();
    expect(screen.getByLabelText('다음 페이지')).toBeInTheDocument();
  });
});
