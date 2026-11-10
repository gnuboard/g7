/**
 * 눌러 쓰는 비단추 요소의 키보드 접근 — 두 템플릿이 함께 가진 부품 (#869, 설계 확정 2026-11-08)
 *
 * 클릭만 받던 칸이 Tab 으로 닿지 않고 Enter·Space 로도 동작하지 않았다: 클릭을 받는 Flex·Grid, 첨부 썸네일(이미지 크게 보기),
 * 링크가 없는 알림 항목, 검색어 제안(입력칸에서 Tab 으로 넘어가면 제안 목록이 닫혔다).
 *
 * 조합 요약(마커 아님, 평문): element=flex|grid|thumbnail_zoom|notification_item|search_suggestion
 * 효과 요약(마커 아님, 평문): clickable_element_keyboard_operable
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';

vi.hoisted(() => {
  (window as any).G7Core = {
    ...((window as any).G7Core ?? {}),
    t: (key: string, params?: Record<string, unknown>) => (params?.name ? `${key}:${String(params.name)}` : key),
    api: { get: () => Promise.resolve(null) },
    createLogger: () => ({ log: () => {}, warn: () => {}, error: () => {} }),
  };
});

import { DndContext } from '@dnd-kit/core';
import { SortableContext } from '@dnd-kit/sortable';
import { Flex } from '../layout/Flex';
import { Grid } from '../layout/Grid';
import { SortableThumbnailItem } from '../composite/FileUploader/SortableThumbnailItem';
import { NotificationCenter } from '../composite/NotificationCenter';
import { SearchBar } from '../composite/SearchBar';

URL.createObjectURL = vi.fn(() => 'blob:mock-url');
URL.revokeObjectURL = vi.fn();

const press = (el: HTMLElement, key: 'Enter' | ' ') => fireEvent.keyDown(el, { key });

describe('키보드 접근 — 두 템플릿 공통 부품', () => {
  // @scenario element=flex
  // @effects clickable_element_keyboard_operable
  it('Flex: 클릭을 받으면 role=button, tabIndex=0 이고 Enter 로 동작한다', () => {
    const onClick = vi.fn();
    const flex = render(<Flex onClick={onClick}>가</Flex>).container.firstChild as HTMLElement;
    expect(flex.getAttribute('role')).toBe('button');
    expect(flex.tabIndex).toBe(0);
    press(flex, 'Enter');
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  // @scenario element=grid
  // @effects clickable_element_keyboard_operable
  it('Grid: 클릭을 받으면 role=button, tabIndex=0 이고 Space 로 동작한다', () => {
    const onClick = vi.fn();
    const grid = render(<Grid onClick={onClick}>나</Grid>).container.firstChild as HTMLElement;
    expect(grid.getAttribute('role')).toBe('button');
    expect(grid.tabIndex).toBe(0);
    press(grid, ' ');
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  // @scenario element=thumbnail_zoom
  // @effects clickable_element_keyboard_operable
  it('첨부 썸네일: 이미지 크게 보기 칸은 이름 있는 단추이고 Space 로 연다', () => {
    const onImageClick = vi.fn();
    const file = { id: 1, hash: 'h1', original_filename: 'a.png', mime_type: 'image/png', size: 8, is_image: true, preview_url: '/a.png', download_url: '/d' } as any;
    render(
      <DndContext>
        <SortableContext items={['h1']}>
          <SortableThumbnailItem file={file} onRemove={vi.fn()} onImageClick={onImageClick} onDownload={vi.fn()} />
        </SortableContext>
      </DndContext>,
    );
    const zoom = screen.getByRole('button', { name: /zoom_image_named:a\.png/ });
    expect(zoom.tabIndex).toBe(0);
    press(zoom, ' ');
    expect(onImageClick).toHaveBeenCalledTimes(1);
  });

  // @scenario element=notification_item
  // @effects clickable_element_keyboard_operable
  it('알림: 링크가 없는 항목은 Tab 으로 닿고 Enter 로 연다', () => {
    const onNotificationClick = vi.fn();
    render(<NotificationCenter notifications={[{ id: 'n1', title: '제목', message: '본문', time: '1m', read: false }]} unreadCount={1} onNotificationClick={onNotificationClick} />);
    fireEvent.click(screen.getAllByRole('button')[0]);
    // 줄이 아니라 본문 칸이 단추다 — 줄 안 삭제 단추와 중첩되지 않는다
    const row = document.querySelector('[data-notification-id="n1"]') as HTMLElement;
    expect(row.getAttribute('role')).toBeNull();
    const item = row.querySelector('[role="button"][tabindex="0"]') as HTMLElement;
    expect(item).not.toBeNull();
    expect(item.querySelector('button')).toBeNull();
    expect(item.tabIndex).toBe(0);
    press(item, 'Enter');
    expect(onNotificationClick).toHaveBeenCalledTimes(1);
  });

  // @scenario element=search_suggestion
  // @effects clickable_element_keyboard_operable
  it('검색어 제안: 입력칸에서 Tab 으로 제안에 가도 목록이 닫히지 않고 Enter 로 고른다', () => {
    vi.useFakeTimers();
    const onSuggestionClick = vi.fn();
    render(<SearchBar value="ab" onChange={() => {}} suggestions={[{ id: 1, text: 'abc' }]} showSuggestions onSuggestionClick={onSuggestionClick} />);
    const input = screen.getByRole('searchbox');
    fireEvent.focus(input);
    const suggestion = screen.getByText('abc').closest('[role="button"]') as HTMLElement;
    expect(suggestion).not.toBeNull();
    expect(suggestion.tabIndex).toBe(0);
    fireEvent.blur(input);
    fireEvent.focus(suggestion);
    act(() => { vi.advanceTimersByTime(300); });
    expect(screen.queryByText('abc'), '제안으로 포커스를 옮겼는데 목록이 닫혔습니다').not.toBeNull();
    press(screen.getByText('abc').closest('[role="button"]') as HTMLElement, 'Enter');
    expect(onSuggestionClick).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
  });
});
