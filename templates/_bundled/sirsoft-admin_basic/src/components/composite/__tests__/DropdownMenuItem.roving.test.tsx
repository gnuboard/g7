/**
 * DropdownMenuItem — 메뉴 안에서는 로빙 포커스(tabIndex -1), 메뉴 밖에서는 Tab 으로 닿는다 (#869 키보드 접근)
 *
 * 메뉴(role="menu") 안 항목은 화살표로 옮기는 자리라 Tab 순서에 들지 않아야 한다(WAI-ARIA 메뉴 관례). 번들에는 메뉴 밖
 * 사용처가 없지만 등록된 부품이라 사이트 레이아웃이 메뉴 밖에 둘 수 있어, 부모 메뉴가 있을 때만 -1 로 한다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DropdownMenuItem } from '../DropdownMenuItem';

describe('DropdownMenuItem 로빙 포커스', () => {
  // @scenario context=in_menu
  // @effects menu_item_roving_tabindex
  it('메뉴 안의 일반 항목과 링크 항목은 tabIndex -1 이고 Enter 로 그대로 고른다', () => {
    const onClick = vi.fn();
    render(
      <div role="menu">
        <DropdownMenuItem label="복제" onClick={onClick} />
        <DropdownMenuItem label="편집" href="/admin/x" />
      </div>
    );
    const plain = screen.getByRole('menuitem', { name: '복제' });
    const link = screen.getByRole('menuitem', { name: '편집' });
    expect(plain.getAttribute('tabindex')).toBe('-1');
    expect(link.getAttribute('tabindex')).toBe('-1');
    plain.focus();
    expect(document.activeElement).toBe(plain);
    fireEvent.keyDown(plain, { key: 'Enter' });
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  // @scenario context=standalone
  // @effects menu_item_roving_tabindex
  it('메뉴 밖에 두면 일반 항목은 Tab 으로 닿는다(tabIndex 0)', () => {
    render(<DropdownMenuItem label="복제" onClick={() => {}} />);
    expect(screen.getByRole('menuitem', { name: '복제' }).getAttribute('tabindex')).toBe('0');
  });
});
