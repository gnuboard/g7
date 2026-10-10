import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { FileTabs, type FileTabItem } from '../FileTabs';

/**
 * FileTabs — 열린 파일 탭 줄.
 *
 * 편집기가 한 번에 한 파일만 보여 주더라도 **무엇이 열려 있고 그중 무엇이 저장되지 않았는지**는
 * 늘 보여야 한다. 미저장 표시는 트리와 같은 규약(점 + `aria-label`)을 쓴다 — 두 자리의 표기가
 * 갈리면 사용자는 같은 사실을 두 번 배워야 한다.
 */

const TABS: FileTabItem[] = [
  { key: 'layout:admin_user_list', label: 'admin_user_list', dirty: true },
  { key: 'layout:partials/a/_modal_form', label: '_modal_form', title: 'partials/a/_modal_form' },
  { key: 'ext:12', label: 'user_form' },
];

describe('FileTabs', () => {
  /** @effects file_tabs_render_open_files */
  it('열린 파일을 탭으로 세운다', () => {
    render(<FileTabs tabs={TABS} activeKey="layout:admin_user_list" />);

    expect(screen.getAllByRole('tab')).toHaveLength(3);
    expect(screen.getByText('admin_user_list')).toBeInTheDocument();
  });

  /** @effects file_tabs_render_nothing_when_empty */
  it('열린 파일이 없으면 아무것도 그리지 않는다 — 빈 줄을 남기지 않는다', () => {
    const { container } = render(<FileTabs tabs={[]} />);

    expect(container.firstChild).toBeNull();
  });

  /** @effects file_tabs_mark_active_tab */
  it('활성 탭 하나만 aria-selected 다', () => {
    render(<FileTabs tabs={TABS} activeKey="ext:12" />);

    const selected = screen.getAllByRole('tab').filter((el) => el.getAttribute('aria-selected') === 'true');

    expect(selected).toHaveLength(1);
    expect(selected[0].textContent).toContain('user_form');
  });

  /** @effects file_tabs_mark_dirty_with_dot_and_label */
  it('미저장 탭에 점과 접근성 라벨이 붙는다', () => {
    render(<FileTabs tabs={TABS} activeKey="layout:admin_user_list" />);

    const dots = screen.getAllByLabelText('admin.templates.layout_edit.tree.unsaved');

    expect(dots).toHaveLength(1);
  });

  /** @effects file_tabs_select_on_click */
  it('탭을 누르면 그 파일을 고른다', () => {
    const onSelect = vi.fn();

    render(<FileTabs tabs={TABS} activeKey="layout:admin_user_list" onSelect={onSelect} />);

    fireEvent.click(screen.getByText('user_form'));

    expect(onSelect).toHaveBeenCalledWith('ext:12');
  });

  /**
   * 닫기는 선택과 **다른 동작**이다.
   *
   * 전파를 막지 않으면 닫으면서 동시에 그 탭을 여는 모순이 생긴다.
   *
   * @effects file_tabs_close_does_not_also_select
   */
  it('닫기 버튼은 탭 선택을 함께 일으키지 않는다', () => {
    const onSelect = vi.fn();
    const onClose = vi.fn();

    render(<FileTabs tabs={TABS} activeKey="layout:admin_user_list" onSelect={onSelect} onClose={onClose} />);

    fireEvent.click(screen.getAllByLabelText('admin.templates.layout_edit.tabs.close')[0]);

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onSelect).not.toHaveBeenCalled();
  });

  /** @effects file_tabs_keyboard_activates_tab */
  it('Enter 로도 탭을 고른다', () => {
    const onSelect = vi.fn();

    render(<FileTabs tabs={TABS} activeKey="layout:admin_user_list" onSelect={onSelect} />);

    const tab = screen.getAllByRole('tab')[2];
    fireEvent.keyDown(tab, { key: 'Enter' });

    expect(onSelect).toHaveBeenCalledWith('ext:12');
  });

  /** @effects file_tabs_show_full_path_in_title */
  it('전체 경로는 툴팁으로 남긴다 — 탭 폭이 좁아 이름만 보이기 때문이다', () => {
    render(<FileTabs tabs={TABS} activeKey="layout:admin_user_list" />);

    expect(screen.getByTitle('partials/a/_modal_form')).toBeInTheDocument();
  });

  /** @effects file_tabs_cap_width_at_180px */
  it('탭 폭은 180px 에서 잘라 말줄임한다 (§7.6)', () => {
    render(<FileTabs tabs={TABS} activeKey="ext:12" />);

    const className = screen.getAllByRole('tab')[0].className;

    expect(className).toContain('max-w-[180px]');
    expect(screen.getByText('admin_user_list').className).toContain('truncate');
  });

  /** @effects file_tabs_use_touch_height_on_touch_size */
  it('터치 크기에서는 탭이 더 높다', () => {
    render(<FileTabs tabs={TABS} activeKey="ext:12" size="touch" />);

    expect(screen.getAllByRole('tab')[0].className).toContain('h-10');
  });

  /**
   * 활성 탭은 **보여야** 한다.
   *
   * 탭 줄은 한 줄 가로 스크롤이라(§7.5), 열린 파일이 많으면 지금 편집 중인 탭이 시야 밖에 숨는다.
   * 활성 키가 바뀔 때마다 그 탭을 시야로 끌어온다 — 있으면 쓰고 없으면 넘어가는 편의 계약이지만,
   * 끌어오는 호출 자체가 빠지면 사용자는 어느 파일을 고치는지 스크롤로 찾아야 한다.
   *
   * @effects file_tabs_scroll_active_tab_into_view
   */
  it('활성 탭이 바뀌면 그 탭을 시야로 끌어온다', () => {
    const scrollIntoView = vi.fn();
    const hadOwn = Object.prototype.hasOwnProperty.call(Element.prototype, 'scrollIntoView');
    const original = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = scrollIntoView;

    try {
      const { rerender } = render(<FileTabs tabs={TABS} activeKey="layout:admin_user_list" />);

      expect(scrollIntoView).toHaveBeenCalledTimes(1);

      rerender(<FileTabs tabs={TABS} activeKey="ext:12" />);

      expect(scrollIntoView).toHaveBeenCalledTimes(2);
      expect(scrollIntoView).toHaveBeenLastCalledWith({ block: 'nearest', inline: 'nearest' });
      // 끌어온 것은 새 활성 탭이다 — 이전 탭을 끌어오면 반대 방향으로 스크롤된다.
      expect((scrollIntoView.mock.contexts[1] as HTMLElement).textContent).toContain('user_form');
    } finally {
      if (hadOwn) {
        Element.prototype.scrollIntoView = original;
      } else {
        delete (Element.prototype as any).scrollIntoView;
      }
    }
  });

  /** @effects file_tabs_use_spec_tokens_for_active_and_inactive */
  it('활성 탭은 흰 배경 + 파랑 2px 밑줄, 비활성 탭은 gray-600 글자다 (§7.6 토큰표)', () => {
    render(<FileTabs tabs={TABS} activeKey="layout:admin_user_list" />);

    const tabs = screen.getAllByRole('tab');
    const active = tabs.find((el) => el.getAttribute('aria-selected') === 'true')!;
    const inactive = tabs.find((el) => el.getAttribute('aria-selected') !== 'true')!;

    expect(active.className).toContain('bg-white');
    expect(active.className).toContain('dark:bg-gray-800');
    expect(active.className).toContain('shadow-[inset_0_-2px_0_0_#3b82f6]');
    expect(inactive.className).toContain('text-gray-600');
    expect(inactive.className).toContain('dark:text-gray-400');
    // 비활성 배경은 줄 컨테이너가 소유한다
    expect(screen.getByRole('tablist').className).toContain('bg-gray-50');
    expect(screen.getByRole('tablist').className).toContain('dark:bg-gray-900');
  });
});
