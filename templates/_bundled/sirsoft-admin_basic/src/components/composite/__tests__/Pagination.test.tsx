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
    expect(currentPage).toHaveClass('bg-blue-500', 'text-white');
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
});
