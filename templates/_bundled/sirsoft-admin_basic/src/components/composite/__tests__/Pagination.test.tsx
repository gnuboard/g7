import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Pagination } from '../Pagination';

describe('Pagination', () => {
  const mockProps = {
    currentPage: 1,
    totalPages: 10,
    onPageChange: vi.fn(),
  };

  it('컴포넌트가 렌더링됨', () => {
    render(<Pagination {...mockProps} />);

    expect(screen.getByText('1')).toBeInTheDocument();
    expect(screen.getByLabelText('이전 페이지')).toBeInTheDocument();
    expect(screen.getByLabelText('다음 페이지')).toBeInTheDocument();
  });

  it('현재 페이지에 active 스타일이 적용됨', () => {
    render(<Pagination {...mockProps} />);

    const currentPage = screen.getByText('1');
    expect(currentPage).toHaveClass('pagination-btn-active');
  });

  it('페이지 번호 클릭 시 onPageChange가 호출됨', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(<Pagination {...mockProps} onPageChange={onPageChange} />);

    const page2 = screen.getByText('2');
    await user.click(page2);

    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  it('이전 버튼 클릭 시 이전 페이지로 이동', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(
      <Pagination
        currentPage={5}
        totalPages={10}
        onPageChange={onPageChange}
      />
    );

    const prevButton = screen.getByLabelText('이전 페이지');
    await user.click(prevButton);

    expect(onPageChange).toHaveBeenCalledWith(4);
  });

  it('다음 버튼 클릭 시 다음 페이지로 이동', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(
      <Pagination
        currentPage={5}
        totalPages={10}
        onPageChange={onPageChange}
      />
    );

    const nextButton = screen.getByLabelText('다음 페이지');
    await user.click(nextButton);

    expect(onPageChange).toHaveBeenCalledWith(6);
  });

  it('첫 페이지일 때 이전 버튼이 비활성화됨', () => {
    render(<Pagination {...mockProps} currentPage={1} />);

    const prevButton = screen.getByLabelText('이전 페이지');
    expect(prevButton).toBeDisabled();
  });

  it('마지막 페이지일 때 다음 버튼이 비활성화됨', () => {
    render(<Pagination {...mockProps} currentPage={10} totalPages={10} />);

    const nextButton = screen.getByLabelText('다음 페이지');
    expect(nextButton).toBeDisabled();
  });

  it('showFirstLast가 true일 때 첫 페이지/마지막 페이지 버튼이 표시됨', () => {
    render(<Pagination {...mockProps} showFirstLast={true} />);

    expect(screen.getByLabelText('첫 페이지')).toBeInTheDocument();
    expect(screen.getByLabelText('마지막 페이지')).toBeInTheDocument();
  });

  it('첫 페이지 버튼 클릭 시 첫 페이지로 이동', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(
      <Pagination
        currentPage={5}
        totalPages={10}
        onPageChange={onPageChange}
        showFirstLast={true}
      />
    );

    const firstButton = screen.getByLabelText('첫 페이지');
    await user.click(firstButton);

    expect(onPageChange).toHaveBeenCalledWith(1);
  });

  it('마지막 페이지 버튼 클릭 시 마지막 페이지로 이동', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(
      <Pagination
        currentPage={5}
        totalPages={10}
        onPageChange={onPageChange}
        showFirstLast={true}
      />
    );

    const lastButton = screen.getByLabelText('마지막 페이지');
    await user.click(lastButton);

    expect(onPageChange).toHaveBeenCalledWith(10);
  });

  it('페이지가 많을 때 생략 부호(...)가 표시됨', () => {
    render(
      <Pagination
        currentPage={5}
        totalPages={20}
        maxVisiblePages={5}
        onPageChange={vi.fn()}
      />
    );

    const ellipses = screen.getAllByText('...');
    expect(ellipses.length).toBeGreaterThan(0);
  });

  it('현재 페이지 클릭 시 onPageChange가 호출되지 않음', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(<Pagination {...mockProps} currentPage={1} onPageChange={onPageChange} />);

    const currentPage = screen.getByText('1');
    await user.click(currentPage);

    expect(onPageChange).not.toHaveBeenCalled();
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <Pagination {...mockProps} className="custom-pagination" />
    );
    expect(container.firstChild).toHaveClass('custom-pagination');
  });

  /**
   * hasMorePages 와 last_page 의 우선순위 회귀 (#519)
   *
   * 총 건수가 정확해 last_page 를 아는 목록은 페이지 산술이 언제나 옳다. 그때 응답에
   * has_more_pages 가 없어 레이아웃이 `?? false` 로 채워 넣더라도 그 false 가 "다음" 을
   * 막아서는 안 된다. has_more_pages 가 필요한 자리는 last_page 를 모르는 목록뿐이다.
   *
   * 실측 회귀: /user/orders 응답에 has_more_pages 가 없어 1/2 페이지인데 다음이 비활성.
   */
  describe('hasMorePages 와 last_page 의 우선순위', () => {
    it('last_page 를 알면 hasMorePages=false 여도 다음이 열려 있다', () => {
      render(
        <Pagination currentPage={1} totalPages={2} hasMorePages={false} onPageChange={vi.fn()} />
      );

      expect(screen.getByLabelText('다음 페이지')).not.toBeDisabled();
    });

    it('last_page 를 알고 마지막 페이지면 다음이 막힌다', () => {
      render(
        <Pagination currentPage={2} totalPages={2} hasMorePages={false} onPageChange={vi.fn()} />
      );

      expect(screen.getByLabelText('다음 페이지')).toBeDisabled();
    });

    it('last_page 가 null 이면 hasMorePages 가 다음 이동을 정한다', () => {
      const { rerender } = render(
        <Pagination currentPage={5} totalPages={null} hasMorePages={true} onPageChange={vi.fn()} />
      );
      expect(screen.getByLabelText('다음 페이지')).not.toBeDisabled();

      rerender(
        <Pagination currentPage={5} totalPages={null} hasMorePages={false} onPageChange={vi.fn()} />
      );
      expect(screen.getByLabelText('다음 페이지')).toBeDisabled();
    });

    it('last_page 가 null 이고 hasMorePages 도 없으면 다음이 막힌다', () => {
      render(<Pagination currentPage={1} totalPages={null} onPageChange={vi.fn()} />);

      expect(screen.getByLabelText('다음 페이지')).toBeDisabled();
    });
  });
});
