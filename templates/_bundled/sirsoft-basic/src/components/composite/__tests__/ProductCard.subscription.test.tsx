/**
 * ProductCard 선호 통화 구독 수명 테스트
 *
 * 상품 카드는 `G7Core.state.subscribe` 로 헤더의 통화 변경을 따라간다. 코어는 구독 해제 함수를 돌려주며,
 * 카드는 마운트 동안 구독을 하나만 유지하고(리렌더마다 새로 구독하지 않는다) 언마운트 때 해제해야 한다.
 * 상품 목록은 카드를 수십 장씩 만들고 지우므로, 해제가 빠지면 페이지를 넘길 때마다 구독이 쌓인다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import ProductCard from '../ProductCard';

const product = {
  id: 7,
  name_localized: '면 손수건',
  thumbnail_url: '/img/handkerchief.jpg',
  selling_price: 3000,
  selling_price_formatted: '3,000원',
  multi_currency_selling_price: {
    KRW: { value: 3000, formatted: '3,000원' },
    USD: { value: 2.55, formatted: '$2.55' },
  },
};

/** 구독자 수를 셀 수 있는 G7Core.state 대역 — get() 은 실제처럼 _global 전체를 돌려준다. */
const makeState = (initial: Record<string, unknown>) => {
  const listeners = new Set<(state: any) => void>();
  const snapshot: Record<string, unknown> = { ...initial };
  return {
    listeners,
    state: {
      get: vi.fn(() => snapshot),
      set: (updates: Record<string, unknown>) => {
        Object.assign(snapshot, updates);
        listeners.forEach((listener) => listener(snapshot));
      },
      subscribe: vi.fn((listener: (state: any) => void) => {
        listeners.add(listener);
        return () => {
          listeners.delete(listener);
        };
      }),
    },
  };
};

describe('ProductCard — 선호 통화 구독 수명', () => {
  let originalG7Core: any;
  let store: ReturnType<typeof makeState>;

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    store = makeState({ preferredCurrency: 'KRW' });
    (window as any).G7Core = { ...originalG7Core, t: (key: string) => key, state: store.state };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  // @scenario surface=product_card_subscription
  // @effects state_subscription_released
  it('리렌더해도 구독은 하나로 유지되고 언마운트하면 구독자 수가 0 으로 돌아간다', () => {
    const { rerender, unmount } = render(<ProductCard product={product} />);
    expect(store.listeners.size).toBe(1);

    rerender(<ProductCard product={{ ...product }} />);
    rerender(<ProductCard product={{ ...product }} />);
    expect(store.state.subscribe).toHaveBeenCalledTimes(1);
    expect(store.listeners.size).toBe(1);

    unmount();
    expect(store.listeners.size).toBe(0);
  });

  // @scenario surface=product_card_subscription
  // @effects state_subscription_released
  it('카드를 여러 장 만들었다 지워도 구독이 남지 않고, 살아 있는 카드만 통화 변경을 받는다', () => {
    const cards = [1, 2, 3].map((id) => render(<ProductCard product={{ ...product, id }} />));
    expect(store.listeners.size).toBe(3);

    cards.slice(0, 2).forEach((card) => card.unmount());
    expect(store.listeners.size).toBe(1);

    act(() => store.state.set({ preferredCurrency: 'USD' }));
    expect(screen.getByText('$2.55')).toBeInTheDocument();

    cards[2].unmount();
    expect(store.listeners.size).toBe(0);
  });

  // @scenario surface=product_card_subscription
  // @effects state_subscription_released
  it('subscribe 가 해제 함수를 돌려주지 않는 구 코어에서도 언마운트가 오류 없이 끝난다', () => {
    store.state.subscribe.mockImplementation(() => undefined as any);

    const { unmount } = render(<ProductCard product={product} />);

    expect(() => unmount()).not.toThrow();
  });
});
