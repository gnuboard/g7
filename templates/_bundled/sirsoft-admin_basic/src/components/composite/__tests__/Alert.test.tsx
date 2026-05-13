import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Alert } from '../Alert';

describe('Alert 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('메시지가 렌더링된다', () => {
      render(<Alert type="info" message="테스트 메시지" />);
      expect(screen.getByText('테스트 메시지')).toBeTruthy();
    });

    it('role="alert" 속성이 있다', () => {
      const { container } = render(<Alert type="info" message="테스트 메시지" />);
      const alertElement = container.querySelector('[role="alert"]');
      expect(alertElement).toBeTruthy();
    });

    it('aria-live="polite" 속성이 있다', () => {
      const { container } = render(<Alert type="info" message="테스트 메시지" />);
      const alertElement = container.querySelector('[aria-live="polite"]');
      expect(alertElement).toBeTruthy();
    });
  });

  describe('type별 렌더링 - info', () => {
    it('info 타입은 파란색 스타일이 적용된다', () => {
      const { container } = render(<Alert type="info" message="정보 메시지" />);
      const alertElement = container.querySelector('[role="alert"]');
      expect(alertElement?.className).toContain('bg-blue-100');
      expect(alertElement?.className).toContain('border-blue-400');
    });

    it('info 타입은 info-circle 아이콘이 표시된다', () => {
      const { container } = render(<Alert type="info" message="정보 메시지" />);
      const iconElement = container.querySelector('.fa-info-circle');
      expect(iconElement).toBeTruthy();
    });

    it('info 아이콘에 올바른 aria-label이 있다', () => {
      const { container } = render(<Alert type="info" message="정보 메시지" />);
      const iconElement = container.querySelector('[aria-label="info icon"]');
      expect(iconElement).toBeTruthy();
    });
  });

  describe('type별 렌더링 - success', () => {
    it('success 타입은 초록색 스타일이 적용된다', () => {
      const { container } = render(<Alert type="success" message="성공 메시지" />);
      const alertElement = container.querySelector('[role="alert"]');
      expect(alertElement?.className).toContain('bg-green-100');
      expect(alertElement?.className).toContain('border-green-400');
    });

    it('success 타입은 check-circle 아이콘이 표시된다', () => {
      const { container } = render(<Alert type="success" message="성공 메시지" />);
      const iconElement = container.querySelector('.fa-check-circle');
      expect(iconElement).toBeTruthy();
    });

    it('success 아이콘에 올바른 aria-label이 있다', () => {
      const { container } = render(<Alert type="success" message="성공 메시지" />);
      const iconElement = container.querySelector('[aria-label="success icon"]');
      expect(iconElement).toBeTruthy();
    });
  });

  describe('type별 렌더링 - warning', () => {
    it('warning 타입은 노란색 스타일이 적용된다', () => {
      const { container } = render(<Alert type="warning" message="경고 메시지" />);
      const alertElement = container.querySelector('[role="alert"]');
      expect(alertElement?.className).toContain('bg-yellow-100');
      expect(alertElement?.className).toContain('border-yellow-400');
    });

    it('warning 타입은 exclamation-triangle 아이콘이 표시된다', () => {
      const { container } = render(<Alert type="warning" message="경고 메시지" />);
      const iconElement = container.querySelector('.fa-exclamation-triangle');
      expect(iconElement).toBeTruthy();
    });

    it('warning 아이콘에 올바른 aria-label이 있다', () => {
      const { container } = render(<Alert type="warning" message="경고 메시지" />);
      const iconElement = container.querySelector('[aria-label="warning icon"]');
      expect(iconElement).toBeTruthy();
    });
  });

  describe('type별 렌더링 - error', () => {
    it('error 타입은 빨간색 스타일이 적용된다', () => {
      const { container } = render(<Alert type="error" message="에러 메시지" />);
      const alertElement = container.querySelector('[role="alert"]');
      expect(alertElement?.className).toContain('bg-red-100');
      expect(alertElement?.className).toContain('border-red-400');
    });

    it('error 타입은 times-circle 아이콘이 표시된다', () => {
      const { container } = render(<Alert type="error" message="에러 메시지" />);
      const iconElement = container.querySelector('.fa-times-circle');
      expect(iconElement).toBeTruthy();
    });

    it('error 아이콘에 올바른 aria-label이 있다', () => {
      const { container } = render(<Alert type="error" message="에러 메시지" />);
      const iconElement = container.querySelector('[aria-label="error icon"]');
      expect(iconElement).toBeTruthy();
    });
  });

  describe('dismissible 옵션', () => {
    it('dismissible이 false일 때 닫기 버튼이 렌더링되지 않는다', () => {
      const { container } = render(
        <Alert type="info" message="메시지" dismissible={false} />
      );
      const closeButton = container.querySelector('[aria-label="Close alert"]');
      expect(closeButton).toBeNull();
    });

    it('dismissible이 true일 때 닫기 버튼이 렌더링된다', () => {
      const { container } = render(
        <Alert type="info" message="메시지" dismissible={true} />
      );
      const closeButton = container.querySelector('[aria-label="Close alert"]');
      expect(closeButton).toBeTruthy();
    });

    it('dismissible 기본값은 false이다', () => {
      const { container } = render(<Alert type="info" message="메시지" />);
      const closeButton = container.querySelector('[aria-label="Close alert"]');
      expect(closeButton).toBeNull();
    });

    it('닫기 버튼에 times 아이콘이 표시된다', () => {
      const { container } = render(
        <Alert type="info" message="메시지" dismissible={true} />
      );
      const closeIcon = container.querySelector('.fa-times');
      expect(closeIcon).toBeTruthy();
    });
  });

  describe('onDismiss 콜백', () => {
    it('닫기 버튼 클릭 시 onDismiss 콜백이 호출된다', () => {
      const onDismiss = vi.fn();
      const { container } = render(
        <Alert type="info" message="메시지" dismissible={true} onDismiss={onDismiss} />
      );

      const closeButton = container.querySelector('[aria-label="Close alert"]') as HTMLElement;
      expect(closeButton).toBeTruthy();

      fireEvent.click(closeButton);
      expect(onDismiss).toHaveBeenCalledTimes(1);
    });

    it('dismissible이 false면 onDismiss는 호출되지 않는다', () => {
      const onDismiss = vi.fn();
      const { container } = render(
        <Alert type="info" message="메시지" dismissible={false} onDismiss={onDismiss} />
      );

      // 닫기 버튼이 없으므로 클릭할 수 없음
      const closeButton = container.querySelector('[aria-label="Close alert"]');
      expect(closeButton).toBeNull();
      expect(onDismiss).not.toHaveBeenCalled();
    });

    it('닫기 버튼을 여러 번 클릭하면 onDismiss가 여러 번 호출된다', () => {
      const onDismiss = vi.fn();
      const { container } = render(
        <Alert type="info" message="메시지" dismissible={true} onDismiss={onDismiss} />
      );

      const closeButton = container.querySelector('[aria-label="Close alert"]') as HTMLElement;

      fireEvent.click(closeButton);
      fireEvent.click(closeButton);
      fireEvent.click(closeButton);

      expect(onDismiss).toHaveBeenCalledTimes(3);
    });
  });

  describe('사용자 정의 Props', () => {
    it('사용자 정의 클래스가 적용된다', () => {
      const { container } = render(
        <Alert type="info" message="메시지" className="custom-alert" />
      );
      const alertElement = container.querySelector('[role="alert"]');
      expect(alertElement?.className).toContain('custom-alert');
    });

    it('사용자 정의 클래스가 기본 클래스와 함께 적용된다', () => {
      const { container } = render(
        <Alert type="info" message="메시지" className="custom-class" />
      );
      const alertElement = container.querySelector('[role="alert"]');
      expect(alertElement?.className).toContain('custom-class');
      expect(alertElement?.className).toContain('bg-blue-100');
      expect(alertElement?.className).toContain('border-blue-400');
    });
  });

  describe('복합 시나리오', () => {
    it('모든 type별 dismissible 알림이 정상 동작한다', () => {
      const onDismissInfo = vi.fn();
      const onDismissSuccess = vi.fn();
      const onDismissWarning = vi.fn();
      const onDismissError = vi.fn();

      const { container, rerender } = render(
        <Alert type="info" message="정보" dismissible={true} onDismiss={onDismissInfo} />
      );

      let closeButton = container.querySelector('[aria-label="Close alert"]') as HTMLElement;
      fireEvent.click(closeButton);
      expect(onDismissInfo).toHaveBeenCalledTimes(1);

      rerender(
        <Alert type="success" message="성공" dismissible={true} onDismiss={onDismissSuccess} />
      );
      closeButton = container.querySelector('[aria-label="Close alert"]') as HTMLElement;
      fireEvent.click(closeButton);
      expect(onDismissSuccess).toHaveBeenCalledTimes(1);

      rerender(
        <Alert type="warning" message="경고" dismissible={true} onDismiss={onDismissWarning} />
      );
      closeButton = container.querySelector('[aria-label="Close alert"]') as HTMLElement;
      fireEvent.click(closeButton);
      expect(onDismissWarning).toHaveBeenCalledTimes(1);

      rerender(
        <Alert type="error" message="에러" dismissible={true} onDismiss={onDismissError} />
      );
      closeButton = container.querySelector('[aria-label="Close alert"]') as HTMLElement;
      fireEvent.click(closeButton);
      expect(onDismissError).toHaveBeenCalledTimes(1);
    });

    it('긴 메시지도 정상적으로 표시된다', () => {
      const longMessage = '이것은 매우 긴 메시지입니다. '.repeat(10);
      const { container } = render(<Alert type="info" message={longMessage} />);
      // p 태그에서 메시지 확인
      const messageElement = container.querySelector('p');
      expect(messageElement?.textContent).toBe(longMessage);
    });
  });

  describe('다크 모드 클래스', () => {
    it('다크 모드 클래스가 포함된다', () => {
      const { container } = render(<Alert type="info" message="메시지" />);
      const alertElement = container.querySelector('[role="alert"]');
      expect(alertElement?.className).toContain('dark:bg-blue-900');
      expect(alertElement?.className).toContain('dark:border-blue-600');
    });
  });
});
