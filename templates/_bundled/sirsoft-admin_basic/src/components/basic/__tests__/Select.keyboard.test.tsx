/**
 * 사용자 정의 Select 를 키보드만으로 고르기
 *
 * 회귀: 목록을 열어도 ↓·↑ 로 항목에 닿지 못하고 Tab 은 문서 끝 포털에 있는 항목을 건너뛰어,
 * 키보드만 쓰는 운영자가 관리자 화면 선택칸(설정·상품 등록 등)을 고를 수 없었다. 사용자 템플릿 Basic 의 Select 와 같은 방식으로
 * 항목에 실제 초점을 옮긴다 — ↓↑ 순환·Home/End·Esc 닫고 단추로·Tab 닫고 단추 다음으로.
 *
 * @scenario key_path=trigger_arrow_down
 * @effects option_focusable_by_keyboard
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import React from 'react';
import { Select } from '../Select';

const OPTIONS = [
  { value: '', label: '용량 선택', disabled: true },
  { value: '350', label: '350ml' },
  { value: '500', label: '500ml', disabled: true },
  { value: '750', label: '750ml' },
];

function renderSelect(props: Record<string, unknown> = {}) {
  const onChange = vi.fn();
  render(<Select aria-label="용량" options={OPTIONS} value="" onChange={onChange} {...props} />);
  const trigger = screen.getByRole('button', { name: /용량/ });
  return { onChange, trigger };
}

const focusedLabel = () => (document.activeElement as HTMLElement | null)?.textContent?.trim();

afterEach(() => cleanup());

describe('Select — 키보드로 항목 고르기', () => {
  /**
   * @scenario key_path=trigger_arrow_down
   * @effects option_focusable_by_keyboard
   */
  it('닫힌 단추에서 ↓ 를 누르면 목록이 열리고 첫 번째 고를 수 있는 항목으로 초점이 간다', () => {
    const { trigger } = renderSelect();
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });

    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(focusedLabel()).toBe('350ml');
  });

  /**
   * @scenario key_path=trigger_arrow_up
   * @effects option_focusable_by_keyboard
   */
  it('닫힌 단추에서 ↑ 를 누르면 마지막 고를 수 있는 항목으로 초점이 간다', () => {
    const { trigger } = renderSelect();
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowUp' });

    expect(focusedLabel()).toBe('750ml');
  });

  /**
   * @scenario key_path=trigger_arrow_down_selected
   * @effects option_focusable_by_keyboard
   */
  it('고른 값이 있으면 ↓ 로 열 때 그 항목으로 초점이 간다', () => {
    const { trigger } = renderSelect({ value: '750' });
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });

    expect(screen.getByRole('listbox').contains(document.activeElement)).toBe(true);
    expect(focusedLabel()).toBe('750ml');
  });

  /**
   * @scenario key_path=opened_then_arrow
   * @effects option_focusable_by_keyboard
   */
  it('Enter·클릭으로 연 뒤 단추에서 ↓ 를 누르면 항목으로 들어간다', () => {
    const { trigger } = renderSelect();
    trigger.focus();
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');

    fireEvent.keyDown(trigger, { key: 'ArrowDown' });
    expect(focusedLabel()).toBe('350ml');
  });

  /**
   * @scenario key_path=in_list_navigation
   * @effects disabled_option_skipped
   */
  it('목록 안에서 ↓·↑ 는 잠긴 항목을 건너뛰며 순환하고 Home·End 는 양 끝으로 간다', () => {
    const { trigger } = renderSelect();
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });
    const list = screen.getByRole('listbox');

    fireEvent.keyDown(document.activeElement as Element, { key: 'ArrowDown' });
    expect(focusedLabel()).toBe('750ml'); // 500ml(잠김) 건너뜀
    fireEvent.keyDown(document.activeElement as Element, { key: 'ArrowDown' });
    expect(focusedLabel()).toBe('350ml'); // 순환
    fireEvent.keyDown(document.activeElement as Element, { key: 'ArrowUp' });
    expect(focusedLabel()).toBe('750ml');
    fireEvent.keyDown(document.activeElement as Element, { key: 'Home' });
    expect(focusedLabel()).toBe('350ml');
    fireEvent.keyDown(document.activeElement as Element, { key: 'End' });
    expect(focusedLabel()).toBe('750ml');
    expect(list.contains(document.activeElement)).toBe(true);
  });

  /**
   * @scenario key_path=option_activate
   * @effects option_focusable_by_keyboard
   */
  it('초점이 간 항목을 누르면(Enter·Space 의 단추 기본 동작) 그 값으로 바뀌고 단추로 초점이 돌아온다', () => {
    const { trigger, onChange } = renderSelect();
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });
    fireEvent.keyDown(document.activeElement as Element, { key: 'ArrowDown' });
    fireEvent.click(document.activeElement as Element);

    expect(onChange).toHaveBeenCalledTimes(1);
    expect(onChange.mock.calls[0][0].target.value).toBe('750');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(trigger);
  });

  /**
   * @scenario key_path=escape
   * @effects escape_does_not_close_outer_modal
   */
  it('목록 안에서 Esc 는 목록만 닫고 단추로 초점을 돌린다 — 바깥(모달 등) Esc 처리로 번지지 않는다', () => {
    const outer = vi.fn();
    document.addEventListener('keydown', outer);
    try {
      const { trigger } = renderSelect();
      trigger.focus();
      fireEvent.keyDown(trigger, { key: 'ArrowDown' });
      fireEvent.keyDown(document.activeElement as Element, { key: 'Escape' });

      expect(trigger.getAttribute('aria-expanded')).toBe('false');
      expect(document.activeElement).toBe(trigger);
      expect(outer.mock.calls.filter(([e]) => (e as KeyboardEvent).key === 'Escape')).toHaveLength(0);
    } finally {
      document.removeEventListener('keydown', outer);
    }
  });

  /**
   * @scenario key_path=tab
   * @effects tab_returns_to_trigger
   */
  it('목록 안에서 Tab 은 목록을 닫고 단추로 초점을 돌린다(이어지는 기본 Tab 이 단추 다음 칸으로 간다)', () => {
    const { trigger } = renderSelect();
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'ArrowDown' });
    expect(screen.getByRole('listbox').contains(document.activeElement)).toBe(true);
    fireEvent.keyDown(document.activeElement as Element, { key: 'Tab' });

    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(trigger);
  });

  /**
   * @scenario key_path=aria_controls
   * @effects aria_controls_only_when_open
   */
  it('열려 있을 때만 단추의 aria-controls 가 실제 목록을 가리킨다', () => {
    const { trigger } = renderSelect();
    expect(trigger.hasAttribute('aria-controls')).toBe(false);

    fireEvent.click(trigger);
    const controls = trigger.getAttribute('aria-controls');
    expect(controls).toBeTruthy();
    expect(document.getElementById(controls as string)?.getAttribute('role')).toBe('listbox');
  });

  /**
   * @scenario key_path=search_box_arrow_down
   * @effects option_focusable_by_keyboard
   */
  it('검색칸이 있는 목록에서는 검색칸에서 ↓ 로 첫 항목에 들어가고, 검색칸의 Home·End 는 글자 편집에 남긴다', () => {
    const { trigger } = renderSelect({ searchable: true });
    fireEvent.click(trigger);
    const search = screen.getByRole('searchbox');
    search.focus();

    fireEvent.keyDown(search, { key: 'Home' });
    expect(document.activeElement).toBe(search);
    fireEvent.keyDown(search, { key: 'ArrowDown' });
    expect(focusedLabel()).toBe('350ml');
  });
});
