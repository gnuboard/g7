/**
 * @file HeroSliderMedia.test.tsx
 * @description 영상 배너 — 영상 파일(소리 없음·화면 안 재생·기기별 파일·끝나면 다음 장·한 장이면 반복·실패면 간격 넘김),
 * 움직임 줄이기(멈춘 채 시작), 일시정지 단추(자동 넘김과 영상 함께). (매니페스트 hero-media.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { HeroSlider, type HeroBanner } from '../../components/composite/HeroSlider';
import { swiperState } from '../swiperMock';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

/** 배너 하나를 만든다. */
const banner = (id: number, extra: Partial<HeroBanner> = {}): HeroBanner => ({
  id,
  title: `제목 ${id}`,
  image_alt: `대체 ${id}`,
  link_url: null,
  images: { pc: { url: `/img/pc-${id}.jpg`, width: 1920, height: 640 }, mobile: null },
  ...extra,
});

const videoBanner = (id: number, mobile = false): HeroBanner => banner(id, {
  media_type: 'video',
  videos: { pc: { url: `/vid/pc-${id}.mp4`, mime: 'video/mp4' }, mobile: mobile ? { url: `/vid/m-${id}.mp4`, mime: 'video/mp4' } : null },
});

/** 화면 조건(움직임 줄이기)을 흉내 낸다. */
const mockMedia = ({ reduce = false } = {}) => {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: reduce && query.includes('prefers-reduced-motion'),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia;
};

/** 휴대폰 폭을 흉내 낸다 — 기기는 엔진 반응형 폭(`G7Core.useResponsive`)으로 판정한다. */
const mockPhoneWidth = () => {
  (window as any).G7Core.useResponsive = () => ({ width: 390 });
};

describe('HeroSlider 영상 배너', () => {
  let play: ReturnType<typeof vi.fn>;
  let pause: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.useFakeTimers();
    mockMedia();
    (window as any).G7Core = { t: (key: string) => key };
    play = vi.fn(() => Promise.resolve());
    pause = vi.fn();
    Object.defineProperty(HTMLMediaElement.prototype, 'play', { configurable: true, value: play });
    Object.defineProperty(HTMLMediaElement.prototype, 'pause', { configurable: true, value: pause });
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    delete (window as any).G7Core;
  });

  // @scenario surface=video_plays_muted_inline
  // @effects video_plays_muted_with_device_source
  it('영상 파일 배너는 대표 이미지 위에 소리 없는 화면 안 재생 영상을 두고, 대표 이미지를 첫 화면으로 쓴다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} />);
    const video = container.querySelector('[data-banner-id="1"] video.hs-slider__video') as HTMLVideoElement;
    expect(video).not.toBeNull();
    expect(video.muted).toBe(true);
    expect(video.hasAttribute('playsinline')).toBe(true);
    expect(video.getAttribute('src')).toBe('/vid/pc-1.mp4');
    expect(video.getAttribute('poster')).toBe('/img/pc-1.jpg');
    expect(video.getAttribute('aria-hidden')).toBe('true');
    // 대체 텍스트는 대표 이미지가 맡는다
    expect(container.querySelector('[data-banner-id="1"] img.hs-slider__image')?.getAttribute('alt')).toBe('대체 1');
    expect(play).toHaveBeenCalled();
  });

  // @scenario surface=media_urls_carry_site_mount_prefix
  // @effects media_served_inside_site_mount
  it('하위 폴더 사이트에서 이미지·모바일 이미지·영상·대표 이미지 주소에 마운트 접두사를 붙이고, 이미 붙은 주소와 바깥 주소는 그대로 둔다', () => {
    (window as any).G7Config = { basePath: '/g7' };
    try {
      const api = '/api/plugins/sirsoft-hero_section/images';
      const image = banner(1, { images: { pc: { url: `${api}/pc1`, width: 1920, height: 640 }, mobile: { url: `${api}/m1`, width: 750, height: 1000 } } });
      const video = banner(2, {
        media_type: 'video',
        images: { pc: { url: `/g7${api}/pc2`, width: 1920, height: 640 }, mobile: null },
        videos: { pc: { url: `${api}/v2`, mime: 'video/mp4' }, mobile: { url: 'https://cdn.example.com/v2m.webm', mime: 'video/webm' } },
      });
      const { container } = render(<HeroSlider banners={[image, video]} />);
      const first = container.querySelector('[data-banner-id="1"]')!;
      expect(first.querySelector('img.hs-slider__image')?.getAttribute('src')).toBe(`/g7${api}/pc1`);
      const second = container.querySelector('[data-banner-id="2"]')!;
      expect(second.querySelector('img.hs-slider__image')?.getAttribute('src')).toBe(`/g7${api}/pc2`);
      const player = second.querySelector('video.hs-slider__video') as HTMLVideoElement;
      expect(player.getAttribute('src')).toBe(`/g7${api}/v2`);
      expect(player.getAttribute('poster')).toBe(`/g7${api}/pc2`);

      // 휴대폰 화면의 주 이미지(모바일 이미지)에도 접두사를 붙인다
      cleanup();
      mockPhoneWidth();
      const phone = render(<HeroSlider banners={[image]} />);
      expect(phone.container.querySelector('img.hs-slider__image')?.getAttribute('src')).toBe(`/g7${api}/m1`);
    } finally {
      delete (window as any).G7Config;
    }
  });

  // @scenario surface=video_device_source
  // @effects video_plays_muted_with_device_source
  it('휴대폰 폭이면 모바일 영상, 없으면 PC 영상을 재생한다', () => {
    mockPhoneWidth();
    const { container } = render(<HeroSlider banners={[videoBanner(1, true), videoBanner(2, false)]} />);
    expect(container.querySelector('[data-banner-id="1"] video')?.getAttribute('src')).toBe('/vid/m-1.mp4');
    expect(container.querySelector('[data-banner-id="2"] video')?.getAttribute('src')).toBe('/vid/pc-2.mp4');
  });

  // @scenario surface=video_poster_matches_device
  // @effects video_plays_muted_with_device_source
  it('휴대폰 폭이면 영상 대표 이미지도 모바일 이미지로 두고, 먼 장의 대표 이미지는 차례가 다가올 때 받는다', () => {
    mockPhoneWidth();
    const withMobile = { ...videoBanner(1), images: { pc: { url: '/img/pc-1.jpg', width: 1920, height: 640 }, mobile: { url: '/img/m-1.jpg', width: 750, height: 1000 } } };
    const { container } = render(<HeroSlider banners={[withMobile, videoBanner(2), videoBanner(3)]} />);
    const poster = (id: number) => container.querySelector(`[data-banner-id="${id}"] video`)?.getAttribute('poster');
    expect(poster(1)).toBe('/img/m-1.jpg');
    // 모바일 이미지가 없으면 PC 이미지(그림 요소도 같은 것을 받는다)
    expect(poster(2)).toBe('/img/pc-2.jpg');
    // 먼 장은 차례가 다가올 때까지 대표 이미지를 받지 않는다(그림 요소처럼 늦게)
    expect(poster(3)).toBeNull();
    fireEvent.click(container.querySelector('.hs-slider__next')!);
    expect(poster(3)).toBe('/img/pc-3.jpg');
  });

  // @scenario surface=video_ends_then_next
  // @effects video_drives_slide_advance
  it('활성 장이 영상이면 간격 넘김을 멈추고, 영상이 끝나면 다음 장으로 넘긴다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    expect(swiperState.instance!.autoplay.stop).toHaveBeenCalled();
    expect(swiperState.instance!.autoplay.running).toBe(false);
    fireEvent.ended(container.querySelector('[data-banner-id="1"] video')!);
    expect(swiperState.instance!.slideNext).toHaveBeenCalledTimes(1);
    // 이미지 장으로 넘어가면 간격 넘김이 다시 돈다
    expect(swiperState.instance!.autoplay.running).toBe(true);
  });

  // @scenario surface=video_ends_while_hovered
  // @effects video_drives_slide_advance
  it('마우스가 올라가 있는 동안 영상이 끝나면 넘기지 않고, 나가면 간격 넘김으로 돌아간다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true }} />);
    const region = container.querySelector('section.hs-slider')!;
    fireEvent.pointerEnter(region, { pointerType: 'mouse' });
    fireEvent.ended(container.querySelector('[data-banner-id="1"] video')!);
    expect(swiperState.instance!.slideNext).not.toHaveBeenCalled();
    fireEvent.pointerLeave(region, { pointerType: 'mouse' });
    expect(swiperState.instance!.autoplay.running).toBe(true);
  });

  // @scenario surface=single_video_loops
  // @effects video_drives_slide_advance
  it('영상 배너 한 장이면 반복 재생하고, 일시정지 단추를 둔다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1)]} />);
    expect((container.querySelector('video') as HTMLVideoElement).loop).toBe(true);
    const toggle = container.querySelector('[data-hs-control="toggle"]') as HTMLButtonElement;
    expect(toggle.getAttribute('aria-label')).toBe('배너 일시정지');
    // 장 번호·화살표는 2장 이상일 때만
    expect(container.querySelector('.hs-slider__counter')).toBeNull();
    expect(container.querySelector('.hs-slider__arrow')).toBeNull();
  });

  // @scenario surface=pause_stops_video
  // @effects pause_controls_video_playback
  it('일시정지 단추가 영상도 멈추고, 다시 누르면 재생한다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} />);
    play.mockClear();
    fireEvent.click(container.querySelector('[data-hs-control="toggle"]')!);
    expect(pause).toHaveBeenCalled();
    fireEvent.click(container.querySelector('[data-hs-control="toggle"]')!);
    expect(play).toHaveBeenCalled();
  });

  // @scenario surface=pause_keeps_video_position
  // @effects pause_controls_video_playback
  it('일시정지는 그 자리에서 멈추고(다시 재생하면 이어서 본다), 다른 장으로 넘어가면 처음으로 돌린다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} />);
    const video = container.querySelector('[data-banner-id="1"] video') as HTMLVideoElement;
    Object.defineProperty(video, 'currentTime', { configurable: true, writable: true, value: 20 });
    fireEvent.click(container.querySelector('[data-hs-control="toggle"]')!);
    expect(pause).toHaveBeenCalled();
    expect(video.currentTime).toBe(20);
    fireEvent.click(container.querySelector('.hs-slider__next')!);
    expect(video.currentTime).toBe(0);
  });

  // @scenario surface=video_play_abort_is_not_failure
  // @effects video_drives_slide_advance
  it('[case:components-misc-video-play-abort-treated-as-failure] 재생 시작 전에 멈추거나 넘겨 재생 요청이 취소(AbortError)돼도 실패로 보지 않고, 다시 재생할 수 있다', async () => {
    play.mockImplementationOnce(() => Promise.reject(new DOMException('The play() request was interrupted by a call to pause().', 'AbortError')));
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true }} />);
    await act(async () => {
      await Promise.resolve();
    });
    // 아직 영상 장이라 간격 넘김은 멈춰 있고, 단추로 다시 재생하면 재생을 요청한다
    expect(swiperState.instance!.autoplay.running).toBe(false);
    fireEvent.click(container.querySelector('[data-hs-control="toggle"]')!);
    play.mockClear();
    fireEvent.click(container.querySelector('[data-hs-control="toggle"]')!);
    expect(play).toHaveBeenCalled();
  });

  // @scenario surface=video_error_falls_back_to_interval
  // @effects video_drives_slide_advance
  it('자동 재생이 막히면(NotAllowedError) 그 장은 이미지처럼 간격으로 넘긴다', async () => {
    play.mockImplementationOnce(() => Promise.reject(new DOMException('play() failed because the user didn\'t interact', 'NotAllowedError')));
    render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true }} />);
    await act(async () => {
      await Promise.resolve();
    });
    expect(swiperState.instance!.autoplay.running).toBe(true);
  });

  // @scenario surface=video_preload_by_turn
  it('영상은 재생 차례인 장만 다 받고, 다음 장은 정보만, 그 뒤 장은 받지 않는다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), videoBanner(2), videoBanner(3)]} settings={{ autoplay: true }} />);
    expect([...container.querySelectorAll('video')].map((v) => v.getAttribute('preload'))).toEqual(['auto', 'metadata', 'none']);
  });

  // @scenario surface=video_preload_by_turn
  it('장을 넘기면 지나간 첫 장 영상도 차례가 아닌 장처럼 받지 않는다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), videoBanner(2), videoBanner(3)]} settings={{ autoplay: true }} />);
    fireEvent.click(container.querySelector('.hs-slider__next')!);
    expect([...container.querySelectorAll('video')].map((v) => v.getAttribute('preload'))).toEqual(['none', 'auto', 'metadata']);
  });

  // @scenario surface=video_preload_by_turn
  it('움직임 줄이기·데이터 절약 방문자에게는 재생하기 전까지 영상을 받지 않는다', () => {
    mockMedia({ reduce: true });
    const reduced = render(<HeroSlider banners={[videoBanner(1), videoBanner(2)]} />);
    expect([...reduced.container.querySelectorAll('video')].map((v) => v.getAttribute('preload'))).toEqual(['none', 'none']);
    reduced.unmount();

    mockMedia();
    Object.defineProperty(navigator, 'connection', { configurable: true, value: { saveData: true } });
    try {
      const saving = render(<HeroSlider banners={[videoBanner(1), videoBanner(2), videoBanner(3)]} settings={{ autoplay: true }} />);
      // 재생 차례인 장은 재생이 받는다 — 미리 받는 것만 끈다
      expect([...saving.container.querySelectorAll('video')].map((v) => v.getAttribute('preload'))).toEqual(['auto', 'none', 'none']);
    } finally {
      delete (navigator as unknown as { connection?: unknown }).connection;
    }
  });

  // @scenario surface=reduced_motion_starts_paused
  // @effects pause_controls_video_playback
  it('움직임 줄이기를 켠 방문자는 영상이 멈춘 채 시작하고 단추로 재생한다', () => {
    mockMedia({ reduce: true });
    const { container } = render(<HeroSlider banners={[videoBanner(1)]} />);
    expect(play).not.toHaveBeenCalled();
    const toggle = container.querySelector('[data-hs-control="toggle"]')!;
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    expect(toggle.getAttribute('aria-label')).toBe('배너 다시 재생');
    fireEvent.click(toggle);
    expect(play).toHaveBeenCalled();
  });

  // @scenario surface=reduced_motion_starts_paused
  // @effects pause_controls_video_playback
  it('받는 중(배너 없음)으로 먼저 그려진 뒤 영상 배너가 와도, 움직임 줄이기면 멈춘 채 시작한다', () => {
    mockMedia({ reduce: true });
    const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" />);
    rerender(<HeroSlider banners={[videoBanner(1)]} settings={{ width: 'content' }} position="home_hero" />);
    expect(play).not.toHaveBeenCalled();
    expect(container.querySelector('[data-hs-control="toggle"]')?.getAttribute('aria-label')).toBe('배너 다시 재생');
  });

  // @scenario surface=video_error_falls_back_to_interval
  // @effects video_drives_slide_advance
  it('영상을 재생할 수 없으면 그 장은 이미지처럼 간격으로 넘긴다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true }} />);
    expect(swiperState.instance!.autoplay.running).toBe(false);
    fireEvent.error(container.querySelector('[data-banner-id="1"] video')!);
    expect(swiperState.instance!.autoplay.running).toBe(true);
  });

  // @scenario surface=video_only_when_media_type_video
  // @effects video_plays_muted_with_device_source
  it('미디어 종류가 이미지면 영상 정보가 있어도 이미지로 그린다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { media_type: 'image', videos: { pc: { url: '/vid/x.mp4' } } })]} />);
    expect(container.querySelector('video')).toBeNull();
    expect(container.querySelector('[data-hs-control="toggle"]')).toBeNull();
  });
});

/**
 * 공개 자산 디스크 영상(#878) — `direct_url` 이 있으면 `<video src>` 와 대표 이미지(poster)를 직접 주소로 둔다. 범위 요청(탐색·이어받기)을
 * PHP 가 아니라 웹서버·CDN 이 받는다. (매니페스트 public-asset-disk.yaml)
 */
describe('HeroSlider 공개 자산 디스크 영상', () => {
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => key };
    mockMedia();
    Object.defineProperty(HTMLMediaElement.prototype, 'play', { configurable: true, value: vi.fn(() => Promise.resolve()) });
    Object.defineProperty(HTMLMediaElement.prototype, 'pause', { configurable: true, value: vi.fn() });
  });

  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
  });

  // @scenario surface=video_src
  // @effects hero_video_src_uses_direct_url
  it('영상·대표 이미지에 direct_url 이 있으면 그 주소로 재생하고 그린다', () => {
    const cdnBanner = banner(1, {
      images: { pc: { url: '/img/pc-1.jpg', direct_url: 'https://cdn.example.com/pc-1.jpg', width: 1920, height: 640 }, mobile: null },
      media_type: 'video',
      videos: { pc: { url: '/vid/pc-1.mp4', direct_url: 'https://cdn.example.com/pc-1.mp4', mime: 'video/mp4' }, mobile: null },
    });
    const { container } = render(<HeroSlider banners={[cdnBanner]} settings={{}} />);
    const video = container.querySelector('video.hs-slider__video');

    expect(video?.getAttribute('src')).toBe('https://cdn.example.com/pc-1.mp4');
    expect(video?.getAttribute('poster')).toBe('https://cdn.example.com/pc-1.jpg');
  });

  // @scenario surface=video_src
  // @effects hero_video_src_uses_direct_url
  it('direct_url 이 없으면 종전처럼 공개 API 주소로 재생한다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1)]} settings={{}} />);

    expect(container.querySelector('video.hs-slider__video')?.getAttribute('src')).toBe('/vid/pc-1.mp4');
  });
});
