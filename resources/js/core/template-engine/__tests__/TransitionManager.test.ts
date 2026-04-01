import { describe, it, expect, beforeEach, vi } from 'vitest';
import { transitionManager } from '../TransitionManager';

describe('TransitionManager', () => {
  beforeEach(() => {
    // 각 테스트 전에 상태 초기화
    transitionManager.setPending(false);
    transitionManager.clearSubscribers();
  });

  describe('setPending', () => {
    it('pending 상태를 설정할 수 있어야 한다', () => {
      transitionManager.setPending(true);
      expect(transitionManager.getIsPending()).toBe(true);

      transitionManager.setPending(false);
      expect(transitionManager.getIsPending()).toBe(false);
    });

    it('동일한 값으로 설정 시 구독자에게 알리지 않아야 한다', () => {
      const callback = vi.fn();
      transitionManager.subscribe(callback);

      transitionManager.setPending(false);
      expect(callback).not.toHaveBeenCalled();
    });

    it('다른 값으로 설정 시 구독자에게 알려야 한다', () => {
      const callback = vi.fn();
      transitionManager.subscribe(callback);

      transitionManager.setPending(true);
      expect(callback).toHaveBeenCalledWith(true);
      expect(callback).toHaveBeenCalledTimes(1);
    });
  });

  describe('subscribe', () => {
    it('구독자를 등록하고 상태 변경 시 호출되어야 한다', () => {
      const callback = vi.fn();
      transitionManager.subscribe(callback);

      transitionManager.setPending(true);
      expect(callback).toHaveBeenCalledWith(true);

      transitionManager.setPending(false);
      expect(callback).toHaveBeenCalledWith(false);
      expect(callback).toHaveBeenCalledTimes(2);
    });

    it('여러 구독자를 등록할 수 있어야 한다', () => {
      const callback1 = vi.fn();
      const callback2 = vi.fn();

      transitionManager.subscribe(callback1);
      transitionManager.subscribe(callback2);

      transitionManager.setPending(true);

      expect(callback1).toHaveBeenCalledWith(true);
      expect(callback2).toHaveBeenCalledWith(true);
    });

    it('구독 해제 함수를 반환해야 한다', () => {
      const callback = vi.fn();
      const unsubscribe = transitionManager.subscribe(callback);

      transitionManager.setPending(true);
      expect(callback).toHaveBeenCalledTimes(1);

      unsubscribe();
      transitionManager.setPending(false);
      expect(callback).toHaveBeenCalledTimes(1); // 구독 해제 후 호출되지 않음
    });
  });

  describe('clearSubscribers', () => {
    it('모든 구독자를 제거해야 한다', () => {
      const callback1 = vi.fn();
      const callback2 = vi.fn();

      transitionManager.subscribe(callback1);
      transitionManager.subscribe(callback2);

      transitionManager.clearSubscribers();
      transitionManager.setPending(true);

      expect(callback1).not.toHaveBeenCalled();
      expect(callback2).not.toHaveBeenCalled();
    });
  });

  describe('getIsPending', () => {
    it('현재 pending 상태를 반환해야 한다', () => {
      expect(transitionManager.getIsPending()).toBe(false);

      transitionManager.setPending(true);
      expect(transitionManager.getIsPending()).toBe(true);

      transitionManager.setPending(false);
      expect(transitionManager.getIsPending()).toBe(false);
    });
  });

  describe('싱글톤 패턴', () => {
    it('동일한 인스턴스를 반환해야 한다', async () => {
      const { transitionManager: instance1 } = await import('../TransitionManager');
      const { transitionManager: instance2 } = await import('../TransitionManager');

      expect(instance1).toBe(instance2);
    });

    it('상태를 공유해야 한다', async () => {
      const { transitionManager: instance1 } = await import('../TransitionManager');
      const { transitionManager: instance2 } = await import('../TransitionManager');

      instance1.setPending(true);
      expect(instance2.getIsPending()).toBe(true);

      instance2.setPending(false);
      expect(instance1.getIsPending()).toBe(false);
    });
  });
});
