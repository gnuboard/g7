/**
 * @file ActionMenu.disabledReason.test.tsx
 * @description 잠긴 메뉴 항목의 안내 문구(disabledReason) — 왜 잠겼는지 항목 안에 보이고 title 로도 읽힌다
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ActionMenu, ActionMenuItem } from '../ActionMenu';

describe('ActionMenu disabledReason', () => {
  // @scenario item_state=disabled,reason=present
  // @effects action_menu_disabled_item_shows_reason
  it('잠긴 항목은 안내 문구를 함께 보이고 title 로 싣는다', async () => {
    const user = userEvent.setup();
    const items: ActionMenuItem[] = [
      { id: 'delete', label: '삭제', disabled: true, disabledReason: '본인 계정은 삭제할 수 없습니다', onClick: vi.fn() },
    ];
    render(<ActionMenu items={items} />);
    await user.click(screen.getByRole('button'));

    const item = screen.getByText('삭제').closest('[role="menuitem"]') as HTMLElement;
    expect(item).toBeDisabled();
    expect(item).toHaveAttribute('title', '본인 계정은 삭제할 수 없습니다');
    expect(item).toHaveTextContent('본인 계정은 삭제할 수 없습니다');
  });

  // @scenario item_state=enabled,reason=present
  // @effects action_menu_enabled_item_hides_reason
  it('잠기지 않은 항목은 안내 문구를 보이지 않는다', async () => {
    const user = userEvent.setup();
    const items: ActionMenuItem[] = [
      { id: 'delete', label: '삭제', disabled: false, disabledReason: '본인 계정은 삭제할 수 없습니다', onClick: vi.fn() },
    ];
    render(<ActionMenu items={items} />);
    await user.click(screen.getByRole('button'));

    const item = screen.getByText('삭제').closest('[role="menuitem"]') as HTMLElement;
    expect(item).not.toHaveAttribute('title');
    expect(screen.queryByText('본인 계정은 삭제할 수 없습니다')).toBeNull();
  });

  // @scenario item_disabled_field=unresolved
  // @effects action_menu_unresolved_disabled_field_fails_closed
  it('행 값으로 풀리지 않은 disabledField 가 남아 있으면 잠근다 — DataGrid 밖에서 쓰여도 조용히 열리지 않는다', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<ActionMenu items={[{ id: 'delete', label: '삭제', disabledField: 'abilities.can_delete', onClick }]} />);
    await user.click(screen.getByRole('button'));

    const item = screen.getByText('삭제').closest('[role="menuitem"]') as HTMLElement;
    expect(item).toBeDisabled();
  });
});
