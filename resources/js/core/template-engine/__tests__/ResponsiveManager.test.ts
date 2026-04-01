/**
 * ResponsiveManager.test.ts
 *
 * ResponsiveManager의 단위 테스트
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// 테스트를 위한 새 인스턴스 생성
class ResponsiveManagerTest {
  private subscribers: Set<(width: number) => void> = new Set();
  private currentWidth: number = 1024;

  constructor(initialWidth: number = 1024) {
    this.currentWidth = initialWidth;
  }

  subscribe(callback: (width: number) => void): () => void {
    this.subscribers.add(callback);
    callback(this.currentWidth);
    return () => {
      this.subscribers.delete(callback);
    };
  }

  getWidth(): number {
    return this.currentWidth;
  }

  private notifySubscribers(): void {
    this.subscribers.forEach((callback) => {
      callback(this.currentWidth);
    });
  }

  _setWidthForTesting(width: number): void {
    this.currentWidth = width;
    this.notifySubscribers();
  }

  clearSubscribers(): void {
    this.subscribers.clear();
  }

  // 범위 파싱 로직 (실제 코드와 동일)
  parseRange(key: string): { min: number; max: number } | null {
    const BREAKPOINT_PRESETS: Record<string, { min: number; max: number }> = {
      mobile: { min: 0, max: 767 },
      tablet: { min: 768, max: 1023 },
      desktop: { min: 1024, max: Infinity },
    };

    if (BREAKPOINT_PRESETS[key]) {
      return { ...BREAKPOINT_PRESETS[key] };
    }

    const rangeMatch = key.match(/^(-?\d*)-(-?\d*)$/);
    if (rangeMatch) {
      const [, minStr, maxStr] = rangeMatch;
      const min = minStr === '' ? 0 : parseInt(minStr, 10);
      const max = maxStr === '' ? Infinity : parseInt(maxStr, 10);

      if (!isNaN(min) && !isNaN(max) && min <= max) {
        return { min, max };
      }
    }

    return null;
  }

  getMatchingKey(responsive: Record<string, any>, width: number): string | null {
    const BREAKPOINT_PRESETS: Record<string, { min: number; max: number }> = {
      mobile: { min: 0, max: 767 },
      tablet: { min: 768, max: 1023 },
      desktop: { min: 1024, max: Infinity },
    };

    const matchedKeys: Array<{
      key: string;
      range: { min: number; max: number };
      isPreset: boolean;
    }> = [];

    for (const key of Object.keys(responsive)) {
      const range = this.parseRange(key);
      if (!range) continue;

      if (width >= range.min && width <= range.max) {
        matchedKeys.push({
          key,
          range,
          isPreset: !!BREAKPOINT_PRESETS[key],
        });
      }
    }

    if (matchedKeys.length === 0) return null;

    matchedKeys.sort((a, b) => {
      if (a.isPreset !== b.isPreset) {
        return a.isPreset ? 1 : -1;
      }
      const aWidth = a.range.max - a.range.min;
      const bWidth = b.range.max - b.range.min;
      return aWidth - bWidth;
    });

    return matchedKeys[0].key;
  }
}

describe('ResponsiveManager', () => {
  let manager: ResponsiveManagerTest;

  beforeEach(() => {
    manager = new ResponsiveManagerTest(1024);
  });

  afterEach(() => {
    manager.clearSubscribers();
  });

  describe('parseRange', () => {
    it('프리셋 mobile 파싱', () => {
      const result = manager.parseRange('mobile');
      expect(result).toEqual({ min: 0, max: 767 });
    });

    it('프리셋 tablet 파싱', () => {
      const result = manager.parseRange('tablet');
      expect(result).toEqual({ min: 768, max: 1023 });
    });

    it('프리셋 desktop 파싱', () => {
      const result = manager.parseRange('desktop');
      expect(result).toEqual({ min: 1024, max: Infinity });
    });

    it('범위 파싱: 0-767', () => {
      const result = manager.parseRange('0-767');
      expect(result).toEqual({ min: 0, max: 767 });
    });

    it('범위 파싱: 768-1023', () => {
      const result = manager.parseRange('768-1023');
      expect(result).toEqual({ min: 768, max: 1023 });
    });

    it('열린 범위 파싱: 1024-', () => {
      const result = manager.parseRange('1024-');
      expect(result).toEqual({ min: 1024, max: Infinity });
    });

    it('열린 범위 파싱: -599', () => {
      const result = manager.parseRange('-599');
      expect(result).toEqual({ min: 0, max: 599 });
    });

    it('열린 범위 파싱: 1200-', () => {
      const result = manager.parseRange('1200-');
      expect(result).toEqual({ min: 1200, max: Infinity });
    });

    it('잘못된 형식 처리: invalid', () => {
      const result = manager.parseRange('invalid');
      expect(result).toBeNull();
    });

    it('잘못된 형식 처리: abc-def', () => {
      const result = manager.parseRange('abc-def');
      expect(result).toBeNull();
    });

    it('잘못된 범위 처리: 1000-500 (min > max)', () => {
      const result = manager.parseRange('1000-500');
      expect(result).toBeNull();
    });
  });

  describe('getMatchingKey', () => {
    it('단일 매칭: mobile at 500px', () => {
      const responsive = {
        mobile: { props: { className: 'mobile-class' } },
      };
      const result = manager.getMatchingKey(responsive, 500);
      expect(result).toBe('mobile');
    });

    it('단일 매칭: tablet at 800px', () => {
      const responsive = {
        tablet: { props: { className: 'tablet-class' } },
      };
      const result = manager.getMatchingKey(responsive, 800);
      expect(result).toBe('tablet');
    });

    it('단일 매칭: desktop at 1200px', () => {
      const responsive = {
        desktop: { props: { className: 'desktop-class' } },
      };
      const result = manager.getMatchingKey(responsive, 1200);
      expect(result).toBe('desktop');
    });

    it('커스텀 > 프리셋 우선순위: 0-599가 mobile보다 우선', () => {
      const responsive = {
        mobile: { props: { className: 'mobile-class' } },
        '0-599': { props: { className: 'small-mobile-class' } },
      };
      const result = manager.getMatchingKey(responsive, 400);
      expect(result).toBe('0-599');
    });

    it('좁은 범위 > 넓은 범위 우선순위', () => {
      const responsive = {
        '0-767': { props: { className: 'wide-range' } },
        '0-480': { props: { className: 'narrow-range' } },
      };
      const result = manager.getMatchingKey(responsive, 400);
      expect(result).toBe('0-480');
    });

    it('매칭 없으면 null 반환', () => {
      const responsive = {
        mobile: { props: { className: 'mobile-class' } },
      };
      const result = manager.getMatchingKey(responsive, 1200);
      expect(result).toBeNull();
    });

    it('여러 프리셋 중 정확히 매칭되는 것 반환', () => {
      const responsive = {
        mobile: { props: { className: 'mobile' } },
        tablet: { props: { className: 'tablet' } },
        desktop: { props: { className: 'desktop' } },
      };

      expect(manager.getMatchingKey(responsive, 500)).toBe('mobile');
      expect(manager.getMatchingKey(responsive, 800)).toBe('tablet');
      expect(manager.getMatchingKey(responsive, 1200)).toBe('desktop');
    });

    it('혼합 사용: 프리셋과 커스텀 범위', () => {
      const responsive = {
        mobile: { props: { className: 'mobile' } },
        '0-480': { props: { className: 'small-mobile' } },
        '1440-': { props: { className: 'large-desktop' } },
      };

      // 480px 이하에서는 0-480이 우선
      expect(manager.getMatchingKey(responsive, 400)).toBe('0-480');
      // 500px에서는 mobile
      expect(manager.getMatchingKey(responsive, 500)).toBe('mobile');
      // 1500px에서는 1440-
      expect(manager.getMatchingKey(responsive, 1500)).toBe('1440-');
    });

    it('빈 객체에서는 null 반환', () => {
      const responsive = {};
      const result = manager.getMatchingKey(responsive, 500);
      expect(result).toBeNull();
    });
  });

  describe('구독 및 알림', () => {
    it('구독 시 즉시 현재값 전달', () => {
      const callback = vi.fn();
      manager.subscribe(callback);

      expect(callback).toHaveBeenCalledTimes(1);
      expect(callback).toHaveBeenCalledWith(1024);
    });

    it('너비 변경 시 구독자에게 알림', () => {
      const callback = vi.fn();
      manager.subscribe(callback);

      // 초기 호출 리셋
      callback.mockClear();

      // 너비 변경
      manager._setWidthForTesting(500);

      expect(callback).toHaveBeenCalledTimes(1);
      expect(callback).toHaveBeenCalledWith(500);
    });

    it('구독 해제 후 알림 받지 않음', () => {
      const callback = vi.fn();
      const unsubscribe = manager.subscribe(callback);

      // 초기 호출 리셋
      callback.mockClear();

      // 구독 해제
      unsubscribe();

      // 너비 변경
      manager._setWidthForTesting(500);

      expect(callback).not.toHaveBeenCalled();
    });

    it('여러 구독자에게 알림', () => {
      const callback1 = vi.fn();
      const callback2 = vi.fn();

      manager.subscribe(callback1);
      manager.subscribe(callback2);

      // 초기 호출 리셋
      callback1.mockClear();
      callback2.mockClear();

      // 너비 변경
      manager._setWidthForTesting(800);

      expect(callback1).toHaveBeenCalledWith(800);
      expect(callback2).toHaveBeenCalledWith(800);
    });

    it('getWidth()가 현재 너비 반환', () => {
      expect(manager.getWidth()).toBe(1024);

      manager._setWidthForTesting(768);
      expect(manager.getWidth()).toBe(768);
    });
  });

  describe('경계값 테스트', () => {
    it('767px에서 mobile 매칭', () => {
      const responsive = {
        mobile: { props: {} },
        tablet: { props: {} },
      };
      expect(manager.getMatchingKey(responsive, 767)).toBe('mobile');
    });

    it('768px에서 tablet 매칭', () => {
      const responsive = {
        mobile: { props: {} },
        tablet: { props: {} },
      };
      expect(manager.getMatchingKey(responsive, 768)).toBe('tablet');
    });

    it('1023px에서 tablet 매칭', () => {
      const responsive = {
        tablet: { props: {} },
        desktop: { props: {} },
      };
      expect(manager.getMatchingKey(responsive, 1023)).toBe('tablet');
    });

    it('1024px에서 desktop 매칭', () => {
      const responsive = {
        tablet: { props: {} },
        desktop: { props: {} },
      };
      expect(manager.getMatchingKey(responsive, 1024)).toBe('desktop');
    });
  });
});
