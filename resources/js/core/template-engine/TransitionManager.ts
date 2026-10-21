/**
 * Transition 상태를 관리하는 싱글톤 모듈
 * React Transition API와 연동하여 페이지 전환 시 로딩 상태를 추적합니다.
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('TransitionManager');

type TransitionCallback = (isPending: boolean) => void;

type RefreshingCallback = (sourceIds: ReadonlySet<string>) => void;

class TransitionManager {
  private isPending: boolean = false;
  private subscribers: Set<TransitionCallback> = new Set();
  /**
   * 값을 그대로 둔 채 다시 받는 중인 데이터 소스 id — 같은 화면의 쿼리 갱신(쪽 넘김·검색)처럼 앞 값을 지우지 않고 받는 소스.
   * `blur_until_loaded` 의 `data_sources` 판정이 값(`undefined`)만 봐서는 「받는 중」을 모르므로 엔진이 알린다 (engine-v1.82.0)
   */
  private refreshingSources: ReadonlySet<string> = new Set();
  private refreshingSubscribers: Set<RefreshingCallback> = new Set();

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
   * 값을 그대로 둔 채 다시 받는 중인 데이터 소스 목록을 바꿉니다 (엔진 내부 — TemplateApp 쿼리 갱신·같은 화면 전환).
   *
   * @param sourceIds 다시 받는 중인 데이터 소스 id
   * @since engine-v1.82.0
   */
  setRefreshingSources(sourceIds: Iterable<string>): void {
    const next = new Set(sourceIds);
    const prev = this.refreshingSources;
    if (next.size === prev.size && Array.from(next).every((id) => prev.has(id))) return;
    this.refreshingSources = next;
    this.refreshingSubscribers.forEach((callback) => {
      callback(next);
    });
  }

  /**
   * 값을 그대로 둔 채 다시 받는 중인 데이터 소스 목록을 반환합니다.
   *
   * @since engine-v1.82.0
   */
  getRefreshingSources(): ReadonlySet<string> {
    return this.refreshingSources;
  }

  /**
   * 다시 받는 중 목록 변경을 구독합니다.
   *
   * @param callback 목록이 바뀔 때 부를 함수
   * @returns 구독 해제 함수
   * @since engine-v1.82.0
   */
  subscribeRefreshing(callback: RefreshingCallback): () => void {
    this.refreshingSubscribers.add(callback);

    return () => {
      this.refreshingSubscribers.delete(callback);
    };
  }

  /**
   * 모든 구독을 해제합니다.
   */
  clearSubscribers(): void {
    this.subscribers.clear();
    this.refreshingSubscribers.clear();
  }
}

// 싱글톤 인스턴스 export
export const transitionManager = new TransitionManager();