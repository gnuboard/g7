/**
 * @file swiperMock.tsx
 * @description jsdom 용 `swiper/react` 대역 — 실제 Swiper 는 레이아웃 크기를 재서 넘기므로(무한 반복·자동 재생 시각) jsdom
 * 에서는 결과가 흔들린다. 단위 시험은 HeroSlider 가 Swiper 에 무엇을 넘기고(옵션), 언제 무엇을 부르는지(start·stop·
 * slideNext·slidePrev), 바뀐 장 번호를 어떻게 받는지만 이 대역으로 보고, 실제 넘김은 E2E 가 실브라우저에서 본다.
 *
 * 쓰는 법: `vi.mock('swiper/react', () => import('../swiperMock'))` + `swiperState` 로 마지막 옵션·인스턴스를 읽는다.
 */
import React, { useEffect, useRef } from 'react';
import { vi } from 'vitest';

/** 대역 인스턴스 — HeroSlider 가 쓰는 표면만 흉내 낸다 */
export interface FakeSwiper {
  destroyed: boolean;
  realIndex: number;
  autoplay: { running: boolean; start: ReturnType<typeof vi.fn>; stop: ReturnType<typeof vi.fn> };
  slideNext: ReturnType<typeof vi.fn>;
  slidePrev: ReturnType<typeof vi.fn>;
}

/** 마지막으로 그린 Swiper 의 옵션과 인스턴스 */
export const swiperState: { props: Record<string, any> | null; instance: FakeSwiper | null } = { props: null, instance: null };

/**
 * 대역 Swiper — 자식(슬라이드)을 그대로 그리고, 마운트 때 onSwiper 로 인스턴스를 넘긴다.
 *
 * @param props Swiper 옵션과 자식
 * @returns 슬라이드를 감싼 div
 */
export const Swiper: React.FC<Record<string, any>> = (props) => {
  const ref = useRef<FakeSwiper | null>(null);
  swiperState.props = props;

  if (!ref.current) {
    const instance: FakeSwiper = {
      destroyed: false,
      realIndex: 0,
      autoplay: {
        running: !!props.autoplay,
        start: vi.fn(() => { instance.autoplay.running = true; }),
        stop: vi.fn(() => { instance.autoplay.running = false; }),
      },
      slideNext: vi.fn(() => move(instance, 1)),
      slidePrev: vi.fn(() => move(instance, -1)),
    };
    ref.current = instance;
  }
  swiperState.instance = ref.current;

  useEffect(() => {
    swiperState.props?.onSwiper?.(ref.current);
    return () => {
      if (ref.current) {
        ref.current.destroyed = true;
      }
    };
  }, []);

  return <div className={`swiper ${props.className ?? ''}`.trim()}>{props.children}</div>;
};

/**
 * 장을 옮기고 onRealIndexChange 를 부릅니다 — loop 면 끝에서 처음으로 돈다, 아니면 끝에서 멈춘다.
 *
 * @param instance 대역 인스턴스
 * @param step 옮길 방향
 */
function move(instance: FakeSwiper, step: 1 | -1): void {
  const props = swiperState.props ?? {};
  const count = React.Children.count(props.children);
  const next = instance.realIndex + step;
  instance.realIndex = props.loop ? (next + count) % count : Math.max(0, Math.min(count - 1, next));
  props.onRealIndexChange?.(instance);
}

/**
 * 대역 슬라이드.
 *
 * @param props 자식
 * @returns 슬라이드 div
 */
export const SwiperSlide: React.FC<{ children?: React.ReactNode }> = ({ children }) => <div className="swiper-slide">{children}</div>;
