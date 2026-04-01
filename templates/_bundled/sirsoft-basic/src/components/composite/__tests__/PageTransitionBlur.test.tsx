import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { PageTransitionBlur } from '../PageTransitionBlur';

describe('PageTransitionBlur', () => {
  let mockTransitionManager: any;
  let originalG7Core: any;

  beforeEach(() => {
    mockTransitionManager = {
      getIsPending: vi.fn(() => false),
      subscribe: vi.fn(() => vi.fn()),
    };

    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      ...originalG7Core,
      TransitionManager: mockTransitionManager,
    };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  it('isPending이 false일 때 아무것도 렌더링하지 않아야 한다', () => {
    mockTransitionManager.getIsPending.mockReturnValue(false);

    const { container } = render(<PageTransitionBlur />);
    expect(container.firstChild).toBeNull();
  });

  it('isPending이 true일 때 블러 오버레이를 렌더링해야 한다', async () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    render(<PageTransitionBlur />);

    await waitFor(() => {
      const overlay = screen.getByTestId('page-transition-blur');
      expect(overlay).toBeDefined();
      expect(overlay.className).toContain('backdrop-blur-sm');
      expect(overlay.className).toContain('pointer-events-none');
      expect(overlay.className).toContain('fixed');
      expect(overlay.className).toContain('inset-0');
    });
  });

  it('다크 모드 클래스를 포함해야 한다', async () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    render(<PageTransitionBlur />);

    await waitFor(() => {
      const overlay = screen.getByTestId('page-transition-blur');
      expect(overlay.className).toContain('bg-white/30');
      expect(overlay.className).toContain('dark:bg-gray-900/30');
    });
  });

  it('TransitionManager를 구독해야 한다', async () => {
    const freshSubscribeMock = vi.fn(() => vi.fn());
    (window as any).G7Core.TransitionManager.subscribe = freshSubscribeMock;

    render(<PageTransitionBlur />);

    await waitFor(() => {
      expect(freshSubscribeMock).toHaveBeenCalledTimes(1);
    });
  });

  it('언마운트 시 구독을 해제해야 한다', async () => {
    const unsubscribe = vi.fn();
    const freshSubscribeMock = vi.fn(() => unsubscribe);
    (window as any).G7Core.TransitionManager.subscribe = freshSubscribeMock;

    const { unmount } = render(<PageTransitionBlur />);

    await waitFor(() => {
      expect(freshSubscribeMock).toHaveBeenCalled();
    });

    unmount();
    expect(unsubscribe).toHaveBeenCalledTimes(1);
  });

  it('isPending 상태 변경 시 UI가 업데이트되어야 한다', async () => {
    let subscribeCallback: ((isPending: boolean) => void) | null = null;

    mockTransitionManager.getIsPending.mockReturnValue(false);
    mockTransitionManager.subscribe.mockImplementation((callback: any) => {
      subscribeCallback = callback;
      return vi.fn();
    });

    const { container } = render(<PageTransitionBlur />);

    // 초기 상태: isPending = false, 렌더링 없음
    expect(container.firstChild).toBeNull();

    // isPending을 true로 변경
    if (subscribeCallback) {
      (subscribeCallback as (isPending: boolean) => void)(true);
    }

    await waitFor(() => {
      const overlay = screen.getByTestId('page-transition-blur');
      expect(overlay).toBeDefined();
    });
  });

  it('custom className을 적용할 수 있어야 한다', async () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    render(<PageTransitionBlur className="custom-blur" />);

    await waitFor(() => {
      const overlay = screen.getByTestId('page-transition-blur');
      expect(overlay.className).toContain('custom-blur');
    });
  });

  it('TransitionManager가 없을 때 경고를 출력해야 한다', async () => {
    const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

    const localBackup = (window as any).G7Core;
    delete (window as any).G7Core;

    render(<PageTransitionBlur />);

    await waitFor(() => {
      expect(consoleWarnSpy).toHaveBeenCalledWith(
        '[Comp:PageTransitionBlur]',
        'TransitionManager를 찾을 수 없습니다.'
      );
    });

    consoleWarnSpy.mockRestore();
    (window as any).G7Core = localBackup;
  });

  it('z-40으로 PageTransitionIndicator(z-50)보다 아래 레이어에 위치해야 한다', async () => {
    mockTransitionManager.getIsPending.mockReturnValue(true);

    render(<PageTransitionBlur />);

    await waitFor(() => {
      const overlay = screen.getByTestId('page-transition-blur');
      expect(overlay.className).toContain('z-40');
      expect(overlay.className).not.toContain('z-50');
    });
  });
});
