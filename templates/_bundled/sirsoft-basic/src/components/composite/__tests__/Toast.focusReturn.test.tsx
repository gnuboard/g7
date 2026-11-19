/**
 * Toast — 닫으면 포커스를 알림 직전 자리로 돌린다 (#869 키보드 접근)
 *
 * 닫기 단추로 알림을 닫으면 단추가 사라지며 포커스가 body 로 빠졌다. 알림이 뜨기 직전에 포커스가 있던 요소가 아직 문서에
 * 있고 보이면 그리로, 없으면 본문 시작점(main, role=main, #main_content, h1 순)으로 돌린다. 닫는 순간 포커스가 그 알림 안에
 * 있을 때만 돌리고, 사용자가 알림 밖으로 옮겼으면 건드리지 않는다. 시간이 다 돼 사라질 때도 같다.
 *
 * 조합 요약(마커 아님, 평문): case=previous|landmark|moved_away|auto_dismiss|stacked
 * 효과 요약(마커 아님, 평문): toast_close_returns_focus
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { Toast, ToastItem } from '../Toast';

const HIDE_MS = 400;

describe('Toast 닫기 포커스 복귀', () => {
  let original: any;
  beforeEach(() => {
    vi.useFakeTimers();
    original = (window as any).G7Core?.state?.update;
    if ((window as any).G7Core?.state) (window as any).G7Core.state.update = vi.fn();
  });
  afterEach(() => {
    vi.useRealTimers();
    if ((window as any).G7Core?.state) (window as any).G7Core.state.update = original;
    document.body.innerHTML = '';
  });

  const one: ToastItem[] = [{ id: 't1', type: 'success', message: '저장했습니다' }];
  const closeButtons = () => screen.getAllByRole('button').filter((b) => b.closest('[role="alert"]'));

  function Page({ toasts, withOpener = true }: { toasts: ToastItem[]; withOpener?: boolean }) {
    return (
      <div>
        {withOpener && <button id="opener">저장</button>}
        <button id="other">다른 곳</button>
        <div id="main_content">본문</div>
        <Toast toasts={toasts} duration={60000} />
      </div>
    );
  }

  // @scenario case=previous
  // @effects toast_close_returns_focus
  it('닫기 단추로 닫으면 알림 직전 포커스 요소로 돌아간다', () => {
    const { rerender } = render(<Page toasts={[]} />);
    (document.getElementById('opener') as HTMLElement).focus();
    rerender(<Page toasts={one} />);
    const close = closeButtons()[0];
    close.focus();
    fireEvent.click(close);
    expect(document.activeElement?.id).toBe('opener');
  });

  // @scenario case=landmark
  // @effects toast_close_returns_focus
  it('직전 요소가 사라졌으면 본문 시작점(#main_content)으로 간다', () => {
    const { rerender } = render(<Page toasts={[]} />);
    (document.getElementById('opener') as HTMLElement).focus();
    rerender(<Page toasts={one} />);
    rerender(<Page toasts={one} withOpener={false} />);
    const close = closeButtons()[0];
    close.focus();
    fireEvent.click(close);
    expect(document.activeElement?.id).toBe('main_content');
  });

  // @scenario case=moved_away
  // @effects toast_close_returns_focus
  it('사용자가 알림 밖으로 포커스를 옮겼으면 건드리지 않는다', () => {
    const { rerender } = render(<Page toasts={[]} />);
    (document.getElementById('opener') as HTMLElement).focus();
    rerender(<Page toasts={one} />);
    (document.getElementById('other') as HTMLElement).focus();
    fireEvent.click(closeButtons()[0]);
    expect(document.activeElement?.id).toBe('other');
  });

  // @scenario case=auto_dismiss
  // @effects toast_close_returns_focus
  it('시간이 다 돼 사라질 때도 포커스가 알림 안에 있었으면 직전 요소로 돌아간다', () => {
    const shortOne: ToastItem[] = [{ id: 't2', type: 'info', message: '알림', duration: 1000 } as ToastItem];
    const { rerender } = render(<Page toasts={[]} />);
    (document.getElementById('opener') as HTMLElement).focus();
    rerender(<Page toasts={shortOne} />);
    closeButtons()[0].focus();
    act(() => {
      vi.advanceTimersByTime(1000 + HIDE_MS);
    });
    expect(document.activeElement?.id).toBe('opener');
  });

  // @scenario case=stacked
  // @effects toast_close_returns_focus
  it('쌓인 알림은 각자 자기 직전 포커스를 기억한다', () => {
    const { rerender } = render(<Page toasts={[]} />);
    (document.getElementById('opener') as HTMLElement).focus();
    rerender(<Page toasts={one} />);
    (document.getElementById('other') as HTMLElement).focus();
    const two: ToastItem[] = [...one, { id: 't3', type: 'error', message: '실패' }];
    rerender(<Page toasts={two} />);
    const [first, second] = closeButtons();
    second.focus();
    fireEvent.click(second);
    expect(document.activeElement?.id).toBe('other');
    first.focus();
    fireEvent.click(first);
    expect(document.activeElement?.id).toBe('opener');
  });
});
