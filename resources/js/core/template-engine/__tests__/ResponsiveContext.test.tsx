/**
 * ResponsiveContext.test.tsx
 *
 * ResponsiveContext의 단위 테스트
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { ResponsiveProvider, useResponsive, ResponsiveContextValue } from '../ResponsiveContext';
import { responsiveManager } from '../ResponsiveManager';

// ResponsiveManager mock
vi.mock('../ResponsiveManager', () => {
  let currentWidth = 1024;
  const subscribers = new Set<(width: number) => void>();

  return {
    responsiveManager: {
      getWidth: vi.fn(() => currentWidth),
      subscribe: vi.fn((callback: (width: number) => void) => {
        subscribers.add(callback);
        callback(currentWidth);
        return () => subscribers.delete(callback);
      }),
      _setWidthForTesting: (width: number) => {
        currentWidth = width;
        subscribers.forEach(cb => cb(width));
      },
      _reset: () => {
        currentWidth = 1024;
        subscribers.clear();
      },
    },
  };
});

// 테스트용 컴포넌트
const TestConsumer: React.FC = () => {
  const responsive = useResponsive();
  return (
    <div>
      <span data-testid="width">{responsive.width}</span>
      <span data-testid="isMobile">{String(responsive.isMobile)}</span>
      <span data-testid="isTablet">{String(responsive.isTablet)}</span>
      <span data-testid="isDesktop">{String(responsive.isDesktop)}</span>
      <span data-testid="matchedPreset">{responsive.matchedPreset}</span>
    </div>
  );
};

describe('ResponsiveContext', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (responsiveManager as any)._reset();
  });

  afterEach(() => {
    (responsiveManager as any)._reset();
  });

  describe('ResponsiveProvider', () => {
    it('기본값(데스크톱)으로 초기화', () => {
      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('width').textContent).toBe('1024');
      expect(screen.getByTestId('isDesktop').textContent).toBe('true');
      expect(screen.getByTestId('isMobile').textContent).toBe('false');
      expect(screen.getByTestId('isTablet').textContent).toBe('false');
      expect(screen.getByTestId('matchedPreset').textContent).toBe('desktop');
    });

    it('모바일 너비에서 올바른 값 제공', () => {
      (responsiveManager as any)._setWidthForTesting(500);

      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('width').textContent).toBe('500');
      expect(screen.getByTestId('isMobile').textContent).toBe('true');
      expect(screen.getByTestId('isTablet').textContent).toBe('false');
      expect(screen.getByTestId('isDesktop').textContent).toBe('false');
      expect(screen.getByTestId('matchedPreset').textContent).toBe('mobile');
    });

    it('태블릿 너비에서 올바른 값 제공', () => {
      (responsiveManager as any)._setWidthForTesting(800);

      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('width').textContent).toBe('800');
      expect(screen.getByTestId('isMobile').textContent).toBe('false');
      expect(screen.getByTestId('isTablet').textContent).toBe('true');
      expect(screen.getByTestId('isDesktop').textContent).toBe('false');
      expect(screen.getByTestId('matchedPreset').textContent).toBe('tablet');
    });

    it('너비 변경 시 컨텍스트 업데이트', async () => {
      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      // 초기값 확인
      expect(screen.getByTestId('matchedPreset').textContent).toBe('desktop');

      // 너비 변경
      act(() => {
        (responsiveManager as any)._setWidthForTesting(500);
      });

      // 업데이트된 값 확인
      expect(screen.getByTestId('width').textContent).toBe('500');
      expect(screen.getByTestId('matchedPreset').textContent).toBe('mobile');
    });

    it('ResponsiveManager를 구독', () => {
      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(responsiveManager.subscribe).toHaveBeenCalled();
    });
  });

  describe('useResponsive', () => {
    it('ResponsiveContextValue 반환', () => {
      let contextValue: ResponsiveContextValue | null = null;

      const CaptureContext: React.FC = () => {
        contextValue = useResponsive();
        return null;
      };

      render(
        <ResponsiveProvider>
          <CaptureContext />
        </ResponsiveProvider>
      );

      expect(contextValue).not.toBeNull();
      expect(contextValue).toHaveProperty('width');
      expect(contextValue).toHaveProperty('isMobile');
      expect(contextValue).toHaveProperty('isTablet');
      expect(contextValue).toHaveProperty('isDesktop');
      expect(contextValue).toHaveProperty('matchedPreset');
    });
  });

  describe('overrideWidth (위지윅 편집기)', () => {
    it('overrideWidth가 있으면 실제 브라우저 너비 대신 사용', () => {
      // 실제 너비는 데스크톱
      (responsiveManager as any)._setWidthForTesting(1920);

      render(
        <ResponsiveProvider overrideWidth={375}>
          <TestConsumer />
        </ResponsiveProvider>
      );

      // overrideWidth (375px - 모바일)이 사용되어야 함
      expect(screen.getByTestId('width').textContent).toBe('375');
      expect(screen.getByTestId('matchedPreset').textContent).toBe('mobile');
      expect(screen.getByTestId('isMobile').textContent).toBe('true');
    });

    it('overrideWidth로 태블릿 시뮬레이션', () => {
      (responsiveManager as any)._setWidthForTesting(1920);

      render(
        <ResponsiveProvider overrideWidth={768}>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('width').textContent).toBe('768');
      expect(screen.getByTestId('matchedPreset').textContent).toBe('tablet');
      expect(screen.getByTestId('isTablet').textContent).toBe('true');
    });

    it('overrideWidth가 없으면 실제 브라우저 너비 사용', () => {
      (responsiveManager as any)._setWidthForTesting(500);

      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('width').textContent).toBe('500');
      expect(screen.getByTestId('matchedPreset').textContent).toBe('mobile');
    });

    it('overrideWidth가 undefined이면 실제 브라우저 너비 사용', () => {
      (responsiveManager as any)._setWidthForTesting(1024);

      render(
        <ResponsiveProvider overrideWidth={undefined}>
          <TestConsumer />
        </ResponsiveProvider>
      );

      // undefined이면 실제 브라우저 너비 사용
      expect(screen.getByTestId('width').textContent).toBe('1024');
    });
  });

  describe('경계값 테스트', () => {
    it('767px에서 mobile', () => {
      (responsiveManager as any)._setWidthForTesting(767);

      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('matchedPreset').textContent).toBe('mobile');
    });

    it('768px에서 tablet', () => {
      (responsiveManager as any)._setWidthForTesting(768);

      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('matchedPreset').textContent).toBe('tablet');
    });

    it('1023px에서 tablet', () => {
      (responsiveManager as any)._setWidthForTesting(1023);

      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('matchedPreset').textContent).toBe('tablet');
    });

    it('1024px에서 desktop', () => {
      (responsiveManager as any)._setWidthForTesting(1024);

      render(
        <ResponsiveProvider>
          <TestConsumer />
        </ResponsiveProvider>
      );

      expect(screen.getByTestId('matchedPreset').textContent).toBe('desktop');
    });
  });
});
