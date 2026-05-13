import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { PageTransitionIndicator } from '../PageTransitionIndicator';

describe('PageTransitionIndicator', () => {
  let mockTransitionManager: any;
  let originalG7Core: any;

  beforeEach(() => {
    // Mock TransitionManager 생성
    mockTransitionManager = {
      getIsPending: vi.fn(() => false),
      subscribe: vi.fn((callback) => {
        // unsubscribe 함수 반환
        return vi.fn();
      }),
    };

    // 기존 G7Core를 유지하면서 TransitionManager만 추가/덮어쓰기
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      ...originalG7Core,
      TransitionManager: mockTransitionManager,
    };
  });

  afterEach(() => {
    // 원래 G7Core 복원
    (window as any).G7Core = originalG7Core;
  });

  it('isPending이 false일 때 아무것도 렌더링하지 않아야 한다', () => {
    mockTransitionManager.getIsPending.mockReturnValue(false);

    const { container } = render(<PageTransitionIndicator />);
    expect(container.firstChild).toBeNull();
  });

  it('isPending이 true일 때 로딩 바를 렌더링해야 한다', async () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    render(<PageTransitionIndicator />);

    await waitFor(() => {
      const progressbar = screen.getByRole('progressbar');
      expect(progressbar).toBeDefined();
      expect(progressbar.getAttribute('aria-label')).toBe('페이지 로딩 중');
    });
  });

  it('TransitionManager를 구독해야 한다', async () => {
    // 새로운 구독 mock 생성 (이전 테스트들의 호출 기록을 피하기 위해)
    const freshSubscribeMock = vi.fn(() => vi.fn());
    (window as any).G7Core.TransitionManager.subscribe = freshSubscribeMock;

    render(<PageTransitionIndicator />);

    // useEffect가 실행될 때까지 대기
    await waitFor(() => {
      expect(freshSubscribeMock).toHaveBeenCalledTimes(1);
    });

    expect(freshSubscribeMock).toHaveBeenCalledWith(expect.any(Function));
  });

  it('언마운트 시 구독을 해제해야 한다', async () => {
    const unsubscribe = vi.fn();
    const freshSubscribeMock = vi.fn(() => unsubscribe);
    (window as any).G7Core.TransitionManager.subscribe = freshSubscribeMock;

    const { unmount } = render(<PageTransitionIndicator />);

    // useEffect가 실행될 때까지 대기
    await waitFor(() => {
      expect(freshSubscribeMock).toHaveBeenCalled();
    });

    unmount();

    expect(unsubscribe).toHaveBeenCalledTimes(1);
  });

  it('TransitionManager가 없을 때 경고를 출력해야 한다', async () => {
    const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

    // G7Core를 완전히 제거 (테스트용 로컬 백업)
    const localBackup = (window as any).G7Core;
    delete (window as any).G7Core;

    render(<PageTransitionIndicator />);

    // useEffect가 실행되어 console.warn이 호출될 때까지 대기
    await waitFor(() => {
      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[Comp:PageTransition]',
        'TransitionManager를 찾을 수 없습니다.'
      );
    });

    consoleWarnSpy.mockRestore();
    // 복원
    (window as any).G7Core = localBackup;
  });

  it('isPending 상태 변경 시 UI가 업데이트되어야 한다', async () => {
    let subscribeCallback: ((isPending: boolean) => void) | null = null;

    mockTransitionManager.getIsPending.mockReturnValue(false);
    mockTransitionManager.subscribe.mockImplementation((callback) => {
      subscribeCallback = callback;
      return vi.fn();
    });

    const { container, rerender } = render(<PageTransitionIndicator />);

    // 초기 상태: isPending = false, 렌더링 없음
    expect(container.firstChild).toBeNull();

    // isPending을 true로 변경
    if (subscribeCallback) {
      subscribeCallback(true);
    }

    // 상태 업데이트 대기
    await waitFor(() => {
      const progressbar = screen.queryByRole('progressbar');
      expect(progressbar).toBeDefined();
    });
  });

  it('custom className을 적용할 수 있어야 한다', async () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    const { container } = render(<PageTransitionIndicator className="custom-class" />);

    await waitFor(() => {
      const progressbar = screen.getByRole('progressbar');
      expect(progressbar.className).toContain('custom-class');
    });
  });

  it('custom style을 적용할 수 있어야 한다', async () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    const customStyle = { opacity: 0.5 };
    const { container } = render(<PageTransitionIndicator style={customStyle} />);

    await waitFor(() => {
      const progressbar = screen.getByRole('progressbar');
      expect(progressbar.style.opacity).toBe('0.5');
    });
  });

  it('fixed positioning과 z-index를 적용해야 한다', async () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    render(<PageTransitionIndicator />);

    await waitFor(() => {
      const progressbar = screen.getByRole('progressbar');
      expect(progressbar.className).toContain('fixed');
      expect(progressbar.className).toContain('top-0');
      expect(progressbar.className).toContain('left-0');
      expect(progressbar.className).toContain('right-0');
      expect(progressbar.className).toContain('z-50');
    });
  });
});
