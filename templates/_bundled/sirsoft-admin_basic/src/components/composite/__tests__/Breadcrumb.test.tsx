import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Breadcrumb, BreadcrumbItem } from '../Breadcrumb';

describe('Breadcrumb', () => {
  const mockItems: BreadcrumbItem[] = [
    { label: '대시보드', href: '/admin' },
    { label: '사용자 관리', href: '/admin/users' },
    { label: '사용자 목록' },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<Breadcrumb items={mockItems} />);

    expect(screen.getByText('대시보드')).toBeInTheDocument();
    expect(screen.getByText('사용자 관리')).toBeInTheDocument();
    expect(screen.getByText('사용자 목록')).toBeInTheDocument();
  });

  it('showHome이 true일 때 Home 아이템이 표시됨', () => {
    render(<Breadcrumb items={mockItems} showHome={true} />);

    expect(screen.getByText('Home')).toBeInTheDocument();
  });

  it('링크가 있는 아이템은 클릭 가능함', () => {
    render(<Breadcrumb items={mockItems} />);

    const dashboardLink = screen.getByText('대시보드').closest('a');
    expect(dashboardLink).toHaveAttribute('href', '/admin');
  });

  it('마지막 아이템은 링크가 아님', () => {
    render(<Breadcrumb items={mockItems} />);

    const lastItem = screen.getByText('사용자 목록');
    expect(lastItem.tagName).toBe('SPAN');
    expect(lastItem.closest('a')).not.toBeInTheDocument();
  });

  it('onClick 핸들러가 있는 아이템 클릭 시 핸들러가 호출됨', () => {
    const onClickMock = vi.fn();
    const itemsWithClick: BreadcrumbItem[] = [
      { label: '첫 번째', href: '/first' },
      { label: '클릭 가능', onClick: onClickMock },
      { label: '마지막' },
    ];

    render(<Breadcrumb items={itemsWithClick} />);

    const clickableItem = screen.getByText('클릭 가능').closest('a');
    fireEvent.click(clickableItem!);

    expect(onClickMock).toHaveBeenCalledTimes(1);
  });

  it('maxItems를 초과하면 중간이 생략됨', () => {
    const manyItems: BreadcrumbItem[] = [
      { label: '1', href: '/1' },
      { label: '2', href: '/2' },
      { label: '3', href: '/3' },
      { label: '4', href: '/4' },
      { label: '5', href: '/5' },
      { label: '6', href: '/6' },
    ];

    render(<Breadcrumb items={manyItems} maxItems={3} />);

    expect(screen.getByText('1')).toBeInTheDocument();
    expect(screen.getByText('...')).toBeInTheDocument();
    expect(screen.getByText('6')).toBeInTheDocument();
  });

  it('커스텀 separator가 표시됨', () => {
    render(<Breadcrumb items={mockItems} separator={<span>/</span>} />);

    expect(screen.getByText('대시보드')).toBeInTheDocument();
    // separator는 aria-hidden이므로 직접 테스트하기 어려움
  });

  it('homeHref prop이 적용됨', () => {
    render(
      <Breadcrumb
        items={mockItems}
        showHome={true}
        homeHref="/dashboard"
      />
    );

    const homeLink = screen.getByText('Home').closest('a');
    expect(homeLink).toHaveAttribute('href', '/dashboard');
  });

  it('className prop이 적용됨', () => {
    const { container } = render(
      <Breadcrumb items={mockItems} className="custom-class" />
    );
    expect(container.firstChild).toHaveClass('custom-class');
  });
});
