import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import { Toast, ToastItem, ToastType, ToastPosition } from '../Toast';

describe('Toast', () => {
  // 원래 state.update 백업
  let originalStateUpdate: any;

  beforeEach(() => {
    vi.useFakeTimers();
    // state.update만 모킹 (G7Core 전체를 덮어쓰지 않음)
    originalStateUpdate = (window as any).G7Core?.state?.update;
    if ((window as any).G7Core?.state) {
      (window as any).G7Core.state.update = vi.fn();
    }
  });

  afterEach(() => {
    vi.useRealTimers();
    // 원래 state.update 복원
    if ((window as any).G7Core?.state) {
      (window as any).G7Core.state.update = originalStateUpdate;
    }
  });

  const mockToasts: ToastItem[] = [
    { id: 'toast_1', type: 'success', message: '성공 메시지' },
    { id: 'toast_2', type: 'error', message: '오류 메시지' },
  ];

  describe('기본 렌더링', () => {
    it('toasts 배열이 있으면 모든 토스트를 렌더링해야 함', () => {
      render(<Toast toasts={mockToasts} />);

      expect(screen.getByText('성공 메시지')).toBeInTheDocument();
      expect(screen.getByText('오류 메시지')).toBeInTheDocument();
    });

    it('toasts가 null이면 아무것도 렌더링하지 않아야 함', () => {
      const { container } = render(<Toast toasts={null} />);

      expect(container.firstChild).toBeNull();
    });

    it('toasts가 빈 배열이면 아무것도 렌더링하지 않아야 함', () => {
      const { container } = render(<Toast toasts={[]} />);

      expect(container.firstChild).toBeNull();
    });

    it('고정 위치로 렌더링되어야 함', () => {
      const { container } = render(<Toast toasts={mockToasts} />);

      const toastContainer = container.querySelector('.fixed');
      expect(toastContainer).toBeInTheDocument();
      expect(toastContainer).toHaveClass('z-[9999]');
    });
  });

  describe('스택 기능', () => {
    it('여러 토스트가 스택으로 쌓여야 함', () => {
      const toasts: ToastItem[] = [
        { id: '1', type: 'success', message: '첫 번째' },
        { id: '2', type: 'info', message: '두 번째' },
        { id: '3', type: 'warning', message: '세 번째' },
      ];

      render(<Toast toasts={toasts} />);

      expect(screen.getByText('첫 번째')).toBeInTheDocument();
      expect(screen.getByText('두 번째')).toBeInTheDocument();
      expect(screen.getByText('세 번째')).toBeInTheDocument();

      // 모든 토스트가 alert role을 가져야 함
      const alerts = screen.getAllByRole('alert');
      expect(alerts).toHaveLength(3);
    });

    it('새 토스트가 추가되면 기존 토스트와 함께 표시되어야 함', () => {
      const initialToasts: ToastItem[] = [
        { id: '1', type: 'success', message: '첫 번째' },
      ];

      const { rerender } = render(<Toast toasts={initialToasts} />);

      expect(screen.getByText('첫 번째')).toBeInTheDocument();

      // 새 토스트 추가
      const updatedToasts: ToastItem[] = [
        { id: '1', type: 'success', message: '첫 번째' },
        { id: '2', type: 'info', message: '두 번째' },
      ];

      rerender(<Toast toasts={updatedToasts} />);

      expect(screen.getByText('첫 번째')).toBeInTheDocument();
      expect(screen.getByText('두 번째')).toBeInTheDocument();
    });
  });

  describe('position prop', () => {
    const positions: ToastPosition[] = [
      'top-left',
      'top-center',
      'top-right',
      'bottom-left',
      'bottom-center',
      'bottom-right',
    ];

    positions.forEach((position) => {
      it(`position="${position}"일 때 올바른 위치 클래스를 적용해야 함`, () => {
        const { container } = render(<Toast toasts={mockToasts} position={position} />);

        const toastContainer = container.querySelector('.fixed');

        if (position.includes('top')) {
          expect(toastContainer).toHaveClass('top-4');
        } else {
          expect(toastContainer).toHaveClass('bottom-4');
        }

        if (position.includes('left')) {
          expect(toastContainer).toHaveClass('left-4');
        } else if (position.includes('right')) {
          expect(toastContainer).toHaveClass('right-4');
        } else if (position.includes('center')) {
          expect(toastContainer).toHaveClass('left-1/2');
        }
      });
    });

    it('기본 position은 bottom-center여야 함', () => {
      const { container } = render(<Toast toasts={mockToasts} />);

      const toastContainer = container.querySelector('.fixed');
      expect(toastContainer).toHaveClass('bottom-4', 'left-1/2');
    });
  });

  describe('type별 스타일', () => {
    const typeTests: Array<{ type: ToastType; bgClass: string }> = [
      { type: 'success', bgClass: 'bg-green-50' },
      { type: 'error', bgClass: 'bg-red-50' },
      { type: 'warning', bgClass: 'bg-yellow-50' },
      { type: 'info', bgClass: 'bg-blue-50' },
    ];

    typeTests.forEach(({ type, bgClass }) => {
      it(`type="${type}"일 때 올바른 스타일을 적용해야 함`, () => {
        const toasts: ToastItem[] = [
          { id: 'test_1', type, message: `${type} 메시지` },
        ];

        const { container } = render(<Toast toasts={toasts} />);

        const toastItem = container.querySelector('[role="alert"]');
        expect(toastItem).toHaveClass(bgClass);
      });
    });
  });

  describe('수동 닫기', () => {
    it('닫기 버튼 클릭 시 onRemove가 호출되어야 함', async () => {
      const handleRemove = vi.fn();
      const toasts: ToastItem[] = [
        { id: 'toast_1', type: 'success', message: '테스트 메시지' },
      ];

      render(<Toast toasts={toasts} onRemove={handleRemove} />);

      const closeButton = screen.getByLabelText('알림 닫기');
      fireEvent.click(closeButton);

      // 애니메이션 후 onRemove 호출
      act(() => {
        vi.advanceTimersByTime(300);
      });

      expect(handleRemove).toHaveBeenCalledWith('toast_1');
    });

    it('onRemove가 없으면 G7Core.state.update를 호출해야 함', async () => {
      const toasts: ToastItem[] = [
        { id: 'toast_1', type: 'success', message: '테스트 메시지' },
      ];

      render(<Toast toasts={toasts} />);

      const closeButton = screen.getByLabelText('알림 닫기');
      fireEvent.click(closeButton);

      // 애니메이션 후 G7Core.state.update 호출
      act(() => {
        vi.advanceTimersByTime(300);
      });

      expect((window as any).G7Core.state.update).toHaveBeenCalled();
    });
  });

  describe('자동 제거', () => {
    it('기본 duration(3000ms) 후 자동으로 제거되어야 함', async () => {
      const handleRemove = vi.fn();
      const toasts: ToastItem[] = [
        { id: 'toast_1', type: 'success', message: '테스트' },
      ];

      render(<Toast toasts={toasts} onRemove={handleRemove} />);

      expect(screen.getByText('테스트')).toBeInTheDocument();

      // 3초 + 애니메이션 300ms 경과
      act(() => {
        vi.advanceTimersByTime(3300);
      });

      expect(handleRemove).toHaveBeenCalledWith('toast_1');
    });

    it('duration prop이 설정된 경우 해당 시간 후 제거되어야 함', async () => {
      const handleRemove = vi.fn();
      const toasts: ToastItem[] = [
        { id: 'toast_1', type: 'success', message: '테스트' },
      ];

      render(<Toast toasts={toasts} duration={1000} onRemove={handleRemove} />);

      // 1초 + 애니메이션 300ms 경과
      act(() => {
        vi.advanceTimersByTime(1300);
      });

      expect(handleRemove).toHaveBeenCalledWith('toast_1');
    });

    it('개별 toast.duration이 설정된 경우 해당 시간 후 제거되어야 함', async () => {
      const handleRemove = vi.fn();
      const toasts: ToastItem[] = [
        { id: 'toast_1', type: 'success', message: '테스트', duration: 500 },
      ];

      render(<Toast toasts={toasts} onRemove={handleRemove} />);

      // 500ms + 애니메이션 300ms 경과
      act(() => {
        vi.advanceTimersByTime(800);
      });

      expect(handleRemove).toHaveBeenCalledWith('toast_1');
    });

    it('각 토스트가 개별 타이머로 관리되어야 함', async () => {
      const handleRemove = vi.fn();
      const toasts: ToastItem[] = [
        { id: '1', type: 'success', message: '빠른 제거', duration: 1000 },
        { id: '2', type: 'info', message: '느린 제거', duration: 3000 },
      ];

      render(<Toast toasts={toasts} onRemove={handleRemove} />);

      // 1초 + 애니메이션 경과 - 첫 번째만 제거
      act(() => {
        vi.advanceTimersByTime(1300);
      });

      expect(handleRemove).toHaveBeenCalledWith('1');
      expect(handleRemove).not.toHaveBeenCalledWith('2');

      // 추가 2초 경과 - 두 번째도 제거
      act(() => {
        vi.advanceTimersByTime(2000);
      });

      expect(handleRemove).toHaveBeenCalledWith('2');
    });
  });

  describe('커스텀 아이콘', () => {
    it('icon prop이 설정된 경우 해당 아이콘을 사용해야 함', () => {
      const toasts: ToastItem[] = [
        { id: '1', type: 'success', message: '커스텀 아이콘', icon: 'star' },
      ];

      const { container } = render(<Toast toasts={toasts} />);

      // 아이콘이 렌더링되어야 함
      const icons = container.querySelectorAll('i[role="img"]');
      expect(icons.length).toBeGreaterThanOrEqual(1);
    });

    it('icon prop이 없으면 타입별 기본 아이콘을 사용해야 함', () => {
      const toasts: ToastItem[] = [
        { id: '1', type: 'success', message: '기본 아이콘' },
      ];

      const { container } = render(<Toast toasts={toasts} />);

      const icons = container.querySelectorAll('i[role="img"]');
      expect(icons.length).toBeGreaterThanOrEqual(1);
    });
  });

  describe('접근성', () => {
    it('각 토스트에 role="alert"를 가져야 함', () => {
      render(<Toast toasts={mockToasts} />);

      const alerts = screen.getAllByRole('alert');
      expect(alerts).toHaveLength(2);
    });

    it('컨테이너에 aria-live="polite"를 가져야 함', () => {
      const { container } = render(<Toast toasts={mockToasts} />);

      const liveRegion = container.querySelector('[aria-live="polite"]');
      expect(liveRegion).toBeInTheDocument();
    });

    it('컨테이너에 aria-atomic="true"를 가져야 함', () => {
      const { container } = render(<Toast toasts={mockToasts} />);

      const atomicRegion = container.querySelector('[aria-atomic="true"]');
      expect(atomicRegion).toBeInTheDocument();
    });

    it('닫기 버튼에 aria-label이 있어야 함', () => {
      render(<Toast toasts={[mockToasts[0]]} />);

      const closeButton = screen.getByLabelText('알림 닫기');
      expect(closeButton).toBeInTheDocument();
    });
  });

  describe('ActionDispatcher 연동 시나리오', () => {
    it('_global.toasts 형식의 배열 데이터로 렌더링되어야 함', () => {
      // ActionDispatcher가 설정하는 형식
      const globalToasts: ToastItem[] = [
        {
          id: 'toast_1234567890_abc1234',
          type: 'success',
          message: '일괄 활성화가 완료되었습니다.',
        },
        {
          id: 'toast_1234567891_def5678',
          type: 'info',
          message: '데이터가 업데이트되었습니다.',
          icon: 'refresh',
          duration: 5000,
        },
      ];

      render(<Toast toasts={globalToasts} />);

      expect(screen.getByText('일괄 활성화가 완료되었습니다.')).toBeInTheDocument();
      expect(screen.getByText('데이터가 업데이트되었습니다.')).toBeInTheDocument();
    });
  });
});
