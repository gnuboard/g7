/**
 * Transition 상태를 관리하는 싱글톤 모듈
 * React Transition API와 연동하여 페이지 전환 시 로딩 상태를 추적합니다.
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('TransitionManager');

type TransitionCallback = (isPending: boolean) => void;

class TransitionManager {
  private isPending: boolean = false;
  private subscribers: Set<TransitionCallback> = new Set();

  /**
   * Transition pending 상태를 설정합니다.
   */
  setPending(isPending: boolean): void {
    if (this.isPending === isPending) return;

    logger.log('setPending:', { from: this.isPending, to: isPending, stack: new Error().stack });
    this.isPending = isPending;
    this.notifySubscribers();
  }

  /**
   * 현재 pending 상태를 반환합니다.
   */
  getIsPending(): boolean {
    return this.isPending;
  }

  /**
   * Transition 상태 변경을 구독합니다.
   */
  subscribe(callback: TransitionCallback): () => void {
    this.subscribers.add(callback);

    // 구독 해제 함수 반환
    return () => {
      this.subscribers.delete(callback);
    };
  }

  /**
   * 모든 구독자에게 상태 변경을 알립니다.
   */
  private notifySubscribers(): void {
    logger.log('notifySubscribers:', { isPending: this.isPending, subscriberCount: this.subscribers.size });
    this.subscribers.forEach((callback) => {
      callback(this.isPending);
    });
  }

  /**
   * 모든 구독을 해제합니다.
   */
  clearSubscribers(): void {
    this.subscribers.clear();
  }
}

// 싱글톤 인스턴스 export
export const transitionManager = new TransitionManager();