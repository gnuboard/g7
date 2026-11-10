/**
 * @file ExtensionCardGrid.toggle-pending.test.tsx
 * @description 확장 목록 카드 스위치 — 서버 작업이 끝날 때까지 값을 바꾸지 않고, 그동안 잠근 채 손잡이 자리에 스피너 (#882)
 *
 * 종전에는 누르는 즉시 스위치가 뒤집히고 그 뒤에 서버 작업이 돌아, 실패·거절돼도 「켜졌다」고 읽혔다. 이제 누르면
 * 원래 값에 머물고(누른 카드만 잠금) 서버 값이 바뀌거나 재동기 신호(resyncKey — 실패·409·확인 창 열림)가 오면 진행 표시를 끈다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { ExtensionCardGrid } from '../../src/components/composite/ExtensionCardGrid';
import { Toggle } from '../../src/components/composite/Toggle';

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => key,
    locale: { current: () => 'ko' },
    state: { subscribe: () => () => {} },
    createChangeEvent: ({ checked, name }: { checked: boolean; name?: string }) => ({ target: { checked, name } }),
  };
});

afterEach(() => {
  delete (window as any).G7Core;
});

/** 카드 두 장(페이지 켜짐·이커머스 켜짐)을 그린다. */
function cards(pageChecked: boolean, resyncKey: number, onToggle = vi.fn(), holdUntilResync = false) {
  return (
    <ExtensionCardGrid
      viewMode="gallery"
      resyncKey={resyncKey}
      holdUntilResync={holdUntilResync}
      onToggle={onToggle}
      items={[
        { id: 'sirsoft-page', name: '페이지', toggle: { checked: pageChecked, label: '페이지' } },
        { id: 'sirsoft-ecommerce', name: '이커머스', toggle: { checked: true, label: '이커머스' } },
      ]}
    />
  );
}

const sw = (container: HTMLElement, name: string) => container.querySelector(`[role=switch][aria-label="${name}"]`) as HTMLElement;

describe('확장 목록 카드 스위치 — 응답 전까지 값 유지 + 손잡이 스피너 (#882)', () => {
  /**
   * @scenario case=card_toggle_pending
   * @effects card_toggle_keeps_value_until_server_confirms
   */
  it('누르면 값은 그대로, 누른 카드만 잠기고 스피너가 보인다', () => {
    const onToggle = vi.fn();
    const { container } = render(cards(true, 0, onToggle));

    fireEvent.click(sw(container, '페이지'));

    expect(onToggle).toHaveBeenCalledTimes(1);
    expect(onToggle.mock.calls[0][0]).toBe(false);
    expect(sw(container, '페이지').getAttribute('aria-checked')).toBe('true');
    expect(sw(container, '페이지').getAttribute('aria-busy')).toBe('true');
    expect(sw(container, '페이지').querySelector('[data-testid="toggle-pending-spinner"]')).not.toBeNull();
    // 진행 중에는 비활성 모양 — 금지 커서·흐린 트랙(스피너는 흐리지 않는다). 종전에는 트랙이 그대로 진해 눌러질 것처럼 보였다
    expect(sw(container, '페이지').getAttribute('aria-disabled')).toBe('true');
    expect(sw(container, '페이지').className).toContain('cursor-not-allowed');
    expect(sw(container, '페이지').querySelector('.toggle-switch-track')?.className).toContain('opacity-50');
    expect(sw(container, '페이지').querySelector('[data-testid="toggle-pending-spinner"]')?.className).not.toContain('opacity-50');
    // 다른 카드는 잠기지 않는다
    expect(sw(container, '이커머스').getAttribute('aria-busy')).toBeNull();
    expect(sw(container, '이커머스').getAttribute('aria-disabled')).toBeNull();
    expect(sw(container, '이커머스').className).toContain('cursor-pointer');
    expect(sw(container, '이커머스').querySelector('.toggle-switch-track')?.className).not.toContain('opacity-50');
  });

  /**
   * @scenario case=card_toggle_pending
   * @effects card_toggle_keeps_value_until_server_confirms
   */
  it('진행 중에 다시 눌러도 작업을 다시 보내지 않는다', () => {
    const onToggle = vi.fn();
    const { container } = render(cards(true, 0, onToggle));

    fireEvent.click(sw(container, '페이지'));
    fireEvent.click(sw(container, '페이지'));
    fireEvent.keyDown(sw(container, '페이지'), { key: ' ' });

    expect(onToggle).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario case=card_toggle_pending
   * @effects card_toggle_follows_server_value_after_work
   */
  it('서버 값이 바뀌면(목록 다시 받기) 그 값으로 바뀌고 스피너가 꺼진다', () => {
    const { container, rerender } = render(cards(true, 0));
    fireEvent.click(sw(container, '페이지'));

    rerender(cards(false, 0));

    expect(sw(container, '페이지').getAttribute('aria-checked')).toBe('false');
    expect(sw(container, '페이지').getAttribute('aria-busy')).toBeNull();
    expect(sw(container, '페이지').querySelector('[data-testid="toggle-pending-spinner"]')).toBeNull();
  });

  /**
   * @scenario case=card_toggle_pending
   * @effects card_toggle_follows_server_value_after_work
   */
  it('재동기 신호(실패·409·확인 창 열림)가 오면 원래 값 그대로 스피너만 꺼진다', () => {
    const { container, rerender } = render(cards(true, 0));
    fireEvent.click(sw(container, '페이지'));

    rerender(cards(true, 1));

    expect(sw(container, '페이지').getAttribute('aria-checked')).toBe('true');
    expect(sw(container, '페이지').getAttribute('aria-busy')).toBeNull();
    expect(sw(container, '페이지').getAttribute('aria-disabled')).toBeNull();
    expect(sw(container, '페이지').className).toContain('cursor-pointer');
    expect(sw(container, '페이지').querySelector('.toggle-switch-track')?.className).not.toContain('opacity-50');
  });

  /**
   * 모듈·플러그인 목록은 켜기 뒤 화면 파일(JS·CSS)을 몇 초 더 받는다 — 그 사이 다시 끄면 받던 파일이 404 가 됐다(#882 결정:
   * 파일까지 받은 뒤 해제). holdUntilResync 를 켠 목록은 서버 값이 바뀌어도 진행 표시를 두고, 화면이 후속 작업을 마친 뒤
   * 올리는 재동기 신호에서 끈다.
   *
   * @scenario case=card_toggle_pending
   * @effects card_toggle_holds_until_post_success_work_done
   */
  it('holdUntilResync 면 서버 값이 바뀌어도 재동기 신호 전까지 잠금·스피너를 유지한다', () => {
    const onToggle = vi.fn();
    const { container, rerender } = render(cards(true, 0, onToggle, true));
    fireEvent.click(sw(container, '페이지'));

    rerender(cards(false, 0, onToggle, true));

    expect(sw(container, '페이지').getAttribute('aria-checked')).toBe('false');
    expect(sw(container, '페이지').getAttribute('aria-busy')).toBe('true');
    expect(sw(container, '페이지').querySelector('[data-testid="toggle-pending-spinner"]')).not.toBeNull();
    fireEvent.click(sw(container, '페이지'));
    expect(onToggle).toHaveBeenCalledTimes(1);

    rerender(cards(false, 1, onToggle, true));

    expect(sw(container, '페이지').getAttribute('aria-busy')).toBeNull();
    expect(sw(container, '페이지').querySelector('[data-testid="toggle-pending-spinner"]')).toBeNull();
  });

  /**
   * @scenario case=card_toggle_pending
   * @effects toggle_default_behavior_preserved
   */
  it('다른 화면의 Toggle(선택 속성 없음)은 종전처럼 누르면 바로 바뀐다', () => {
    const onChange = vi.fn();
    const { container } = render(<Toggle checked={false} name="plain" ariaLabel="plain" onChange={onChange} />);

    fireEvent.click(sw(container, 'plain'));

    expect(sw(container, 'plain').getAttribute('aria-checked')).toBe('true');
    expect(onChange).toHaveBeenCalledTimes(1);
  });
});
