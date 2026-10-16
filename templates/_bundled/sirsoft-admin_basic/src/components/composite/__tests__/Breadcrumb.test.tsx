/**
 * Breadcrumb — 경로 표시와 항목 링크
 *
 * 주소(href)가 있는 항목은 `<a href>`(가운데 클릭·Ctrl+클릭 새 탭), 주소 없이 동작(onClick)만 있는 항목은 단추다.
 * 주소 없는 `<a href="#">` 는 쓰지 않는다(#757 링크 단계 — 새 탭으로 열면 같은 화면 맨 위가 열린다).
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Breadcrumb, BreadcrumbItem } from '../Breadcrumb';
import { isPlainLeftClick as engineIsPlainLeftClick } from '@core/template-engine/navigationLinks';

/** 클릭 이벤트를 보내고 기본 동작이 막혔는지 돌려준다 */
const click = (el: Element, init: MouseEventInit = {}): boolean => {
  const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  el.dispatchEvent(ev);

  return ev.defaultPrevented;
};

describe('Breadcrumb', () => {
  const mockItems: BreadcrumbItem[] = [
    { label: '대시보드', href: '/admin' },
    { label: '사용자 관리', href: '/admin/users' },
    { label: '사용자 목록' },
  ];

  // @scenario component=breadcrumb
  // @effects breadcrumb_renders_trail
  it('컴포넌트가 렌더링됨', () => {
    render(<Breadcrumb items={mockItems} />);

    expect(screen.getByText('대시보드')).toBeInTheDocument();
    expect(screen.getByText('사용자 관리')).toBeInTheDocument();
    expect(screen.getByText('사용자 목록')).toBeInTheDocument();
  });

  // @scenario component=breadcrumb
  // @effects breadcrumb_renders_trail
  it('showHome이 true일 때 Home 아이템이 표시됨', () => {
    render(<Breadcrumb items={mockItems} showHome={true} />);

    expect(screen.getByText('Home')).toBeInTheDocument();
  });

  // @scenario component=breadcrumb
  // @effects breadcrumb_renders_trail
  it('링크가 있는 아이템은 클릭 가능함', () => {
    render(<Breadcrumb items={mockItems} />);

    const dashboardLink = screen.getByText('대시보드').closest('a');
    expect(dashboardLink).toHaveAttribute('href', '/admin');
  });

  // @scenario component=breadcrumb
  // @effects breadcrumb_renders_trail
  it('마지막 아이템은 링크가 아님', () => {
    render(<Breadcrumb items={mockItems} />);

    const lastItem = screen.getByText('사용자 목록');
    expect(lastItem.tagName).toBe('SPAN');
    expect(lastItem.closest('a')).not.toBeInTheDocument();
  });

  // @scenario component=breadcrumb
  // @effects breadcrumb_action_item_is_button
  it('onClick 핸들러가 있는 아이템 클릭 시 핸들러가 호출됨', () => {
    const onClickMock = vi.fn();
    const itemsWithClick: BreadcrumbItem[] = [
      { label: '첫 번째', href: '/first' },
      { label: '클릭 가능', onClick: onClickMock },
      { label: '마지막' },
    ];

    render(<Breadcrumb items={itemsWithClick} />);

    const clickableItem = screen.getByRole('button', { name: '클릭 가능' });
    fireEvent.click(clickableItem);

    expect(onClickMock).toHaveBeenCalledTimes(1);
  });

  // @scenario component=breadcrumb
  // @effects breadcrumb_renders_trail
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

  // @scenario component=breadcrumb
  // @effects breadcrumb_renders_trail
  it('커스텀 separator가 표시됨', () => {
    render(<Breadcrumb items={mockItems} separator={<span>/</span>} />);

    expect(screen.getByText('대시보드')).toBeInTheDocument();
    // separator는 aria-hidden이므로 직접 테스트하기 어려움
  });

  // @scenario component=breadcrumb
  // @effects breadcrumb_renders_trail
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

  // @scenario component=breadcrumb
  // @effects breadcrumb_renders_trail
  it('className prop이 적용됨', () => {
    const { container } = render(
      <Breadcrumb items={mockItems} className="custom-class" />
    );
    expect(container.firstChild).toHaveClass('custom-class');
  });

  describe('항목 링크 (#757)', () => {
    beforeEach(() => {
      (window as any).G7Core = { t: (key: string) => key, navigation: { isPlainLeftClick: engineIsPlainLeftClick } };
    });
    afterEach(() => {
      delete (window as any).G7Core;
    });

    // @scenario component=breadcrumb
    // @effects breadcrumb_action_item_is_button
    it('주소 없이 동작만 있는 항목은 단추이고, 주소 없는 링크(href="#")를 그리지 않는다', () => {
      const onClick = vi.fn();
      const { container } = render(
        <Breadcrumb items={[{ label: '목록', onClick }, { label: '현재' }]} />
      );
      const button = screen.getByRole('button', { name: '목록' });
      expect(button).toHaveAttribute('type', 'button');
      expect(container.querySelector('a[href="#"]')).toBeNull();
      expect(container.querySelectorAll('a')).toHaveLength(0);
      fireEvent.click(button);
      expect(onClick).toHaveBeenCalledTimes(1);
    });

    // @scenario component=breadcrumb
    // @effects plain_click_spa_navigate
    it('주소와 동작이 함께 있으면 실제 주소 링크 — 보통 클릭은 기본 이동을 막고 동작을 한 번 부른다', () => {
      const onClick = vi.fn();
      render(<Breadcrumb items={[{ label: '회원', href: '/admin/users', onClick }, { label: '현재' }]} />);
      const link = screen.getByRole('link', { name: '회원' });
      expect(link).toHaveAttribute('href', '/admin/users');
      expect(click(link)).toBe(true);
      expect(onClick).toHaveBeenCalledTimes(1);
    });

    // @scenario component=breadcrumb
    // @effects modified_click_opens_browser_default
    it('주소와 동작이 함께 있어도 Ctrl·Meta·Shift 클릭은 막지도 동작을 부르지도 않는다(새 탭)', () => {
      const onClick = vi.fn();
      render(<Breadcrumb items={[{ label: '회원', href: '/admin/users', onClick }, { label: '현재' }]} />);
      const link = screen.getByRole('link', { name: '회원' });
      for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }]) {
        expect(click(link, init)).toBe(false);
      }
      expect(onClick).not.toHaveBeenCalled();
    });
  });
});
