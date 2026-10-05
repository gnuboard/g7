/**
 * @file Button.repeatClick.test.tsx
 * @description admin_basic Button — 연타 잠금(`lockRepeatClick`) 회귀 테스트 (#736 S2 P10)
 *
 * 실행 버튼의 `disabled="{{…submitting}}"` 는 첫 클릭의 상태 변경이 화면에 그려진 뒤에야 걸려, 그 사이의 두 번째 클릭
 * (더블클릭)이 요청을 한 번 더 보냈다(비밀번호 만료 「지금 실행」 확인 창). 버튼이 첫 클릭 즉시 스스로 잠그고,
 * 화면이 `disabled` 를 그리면 그 판정에 넘긴다.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, fireEvent, act } from '@testing-library/react';
import { Button } from '../Button';

afterEach(() => {
  vi.useRealTimers();
});

describe('admin_basic Button — 연타 잠금', () => {
  /**
   * @scenario lock=on, rerender=none
   * @effects repeat_click_ignored_before_disabled_renders
   */
  it('잠금을 켜면 화면이 다시 그려지기 전의 두 번째 클릭은 무시한다', () => {
    const onClick = vi.fn();
    const { getByRole } = render(<Button lockRepeatClick onClick={onClick}>실행</Button>);

    fireEvent.click(getByRole('button'));
    fireEvent.click(getByRole('button'));

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario lock=on, rerender=disabled_then_enabled
   * @effects lock_hands_over_to_disabled_and_reopens
   */
  it('disabled 가 그려졌다 풀리면(실패 후 다시 시도) 다시 누를 수 있다', () => {
    const onClick = vi.fn();
    const { getByRole, rerender } = render(<Button lockRepeatClick onClick={onClick}>실행</Button>);

    fireEvent.click(getByRole('button'));
    rerender(<Button lockRepeatClick disabled onClick={onClick}>실행</Button>);
    rerender(<Button lockRepeatClick disabled={false} onClick={onClick}>실행</Button>);
    fireEvent.click(getByRole('button'));

    expect(onClick).toHaveBeenCalledTimes(2);
  });

  /**
   * @scenario lock=on, rerender=none
   * @effects lock_released_by_fallback_timer
   */
  it('disabled 가 끝내 오지 않아도 잠시 뒤 잠금이 풀린다', () => {
    vi.useFakeTimers();
    const onClick = vi.fn();
    const { getByRole } = render(<Button lockRepeatClick onClick={onClick}>실행</Button>);

    fireEvent.click(getByRole('button'));
    act(() => {
      vi.advanceTimersByTime(1600);
    });
    fireEvent.click(getByRole('button'));

    expect(onClick).toHaveBeenCalledTimes(2);
  });

  /**
   * @scenario lock=off, rerender=none
   * @effects default_button_unchanged
   */
  it('잠금을 켜지 않은 버튼은 종전대로 클릭마다 부르고, 속성이 DOM 에 새지 않는다', () => {
    const onClick = vi.fn();
    const { getByRole } = render(<Button onClick={onClick}>실행</Button>);

    fireEvent.click(getByRole('button'));
    fireEvent.click(getByRole('button'));

    expect(onClick).toHaveBeenCalledTimes(2);
    expect(getByRole('button').getAttribute('lockrepeatclick')).toBeNull();
  });
});
