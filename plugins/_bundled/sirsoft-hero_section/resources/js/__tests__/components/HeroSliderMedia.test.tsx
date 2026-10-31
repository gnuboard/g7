/**
 * @file HeroSliderMedia.test.tsx
 * @description 영상 배너 — 영상 파일(소리 없음·화면 안 재생·기기별 파일·끝나면 다음 장·한 장이면 반복·실패면 간격 넘김),
 * 유튜브(동의·동의 관리 없음이면 바로, 동의 전이면 대표 이미지와 재생 단추, 동의하면 바로 바뀜, iframe 주소·끝 감지),
 * 움직임 줄이기(멈춘 채 시작), 일시정지 단추(자동 넘김과 영상 함께), 유튜브 주소 해석. (매니페스트 hero-media.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { HeroSlider, type HeroBanner } from '../../components/composite/HeroSlider';
import { marketingAllowed, youtubeIdFromUrl } from '../../support/media';
import { swiperState } from '../swiperMock';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));
vi.mock('swiper/css', () => ({}));
vi.mock('swiper/css/effect-fade', () => ({}));
vi.mock('swiper/css/navigation', () => ({}));
vi.mock('swiper/css/pagination', () => ({}));
vi.mock('swiper/css/effect-cube', () => ({}));
vi.mock('swiper/css/effect-flip', () => ({}));
vi.mock('swiper/css/effect-coverflow', () => ({}));
vi.mock('swiper/css/effect-creative', () => ({}));

const YT = 'abcdefghijk';

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

const youtubeBanner = (id: number): HeroBanner => banner(id, { media_type: 'youtube', youtube_id: YT });

/** 화면 조건(움직임 줄이기·휴대폰 폭)을 흉내 낸다. */
const mockMedia = ({ reduce = false, narrow = false } = {}) => {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: (reduce && query.includes('prefers-reduced-motion')) || (narrow && query.includes('max-width: 767px')),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia;
};

/** 코어 동의 파사드를 흉내 낸다 — 동의를 바꾸면 구독자에게 알린다. */
const mockConsent = (snapshot: Record<string, unknown> | null) => {
  const listeners = new Set<() => void>();
  let current = snapshot;
  (window as any).G7Core.consent = {
    getSnapshot: () => current,
    subscribe: (listener: () => void) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
  return (next: Record<string, unknown>) => {
    current = next;
    act(() => listeners.forEach((listener) => listener()));
  };
};

const ready = (marketing: boolean) => ({ status: 'ready', categories: { necessary: true, marketing } });

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
  it('영상 파일 배너는 대표 이미지 위에 소리 없는 화면 안 재생 영상을 두고, 대표 이미지를 첫 화면으로 쓴다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} />);
    const video = container.querySelector('[data-banner-id="1"] video.hs__video') as HTMLVideoElement;
    expect(video).not.toBeNull();
    expect(video.muted).toBe(true);
    expect(video.hasAttribute('playsinline')).toBe(true);
    expect(video.getAttribute('src')).toBe('/vid/pc-1.mp4');
    expect(video.getAttribute('poster')).toBe('/img/pc-1.jpg');
    expect(video.getAttribute('aria-hidden')).toBe('true');
    // 대체 텍스트는 대표 이미지가 맡는다
    expect(container.querySelector('[data-banner-id="1"] img.hs__image')?.getAttribute('alt')).toBe('대체 1');
    expect(play).toHaveBeenCalled();
  });

  // @scenario surface=video_device_source
  it('휴대폰 폭이면 모바일 영상, 없으면 PC 영상을 재생한다', () => {
    mockMedia({ narrow: true });
    const { container } = render(<HeroSlider banners={[videoBanner(1, true), videoBanner(2, false)]} />);
    expect(container.querySelector('[data-banner-id="1"] video')?.getAttribute('src')).toBe('/vid/m-1.mp4');
    expect(container.querySelector('[data-banner-id="2"] video')?.getAttribute('src')).toBe('/vid/pc-2.mp4');
  });

  // @scenario surface=video_ends_then_next
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
  it('마우스가 올라가 있는 동안 영상이 끝나면 넘기지 않고, 나가면 간격 넘김으로 돌아간다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true }} />);
    const region = container.querySelector('section.hs')!;
    fireEvent.pointerEnter(region, { pointerType: 'mouse' });
    fireEvent.ended(container.querySelector('[data-banner-id="1"] video')!);
    expect(swiperState.instance!.slideNext).not.toHaveBeenCalled();
    fireEvent.pointerLeave(region, { pointerType: 'mouse' });
    expect(swiperState.instance!.autoplay.running).toBe(true);
  });

  // @scenario surface=single_video_loops
  it('영상 배너 한 장이면 반복 재생하고, 일시정지 단추를 둔다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1)]} />);
    expect((container.querySelector('video') as HTMLVideoElement).loop).toBe(true);
    const toggle = container.querySelector('.hs__toggle') as HTMLButtonElement;
    expect(toggle.getAttribute('aria-label')).toBe('배너 일시정지');
    // 장 번호·화살표는 2장 이상일 때만
    expect(container.querySelector('.hs__counter')).toBeNull();
    expect(container.querySelector('.hs__arrow')).toBeNull();
  });

  // @scenario surface=pause_stops_video
  it('일시정지 단추가 영상도 멈추고, 다시 누르면 재생한다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} />);
    play.mockClear();
    fireEvent.click(container.querySelector('.hs__toggle')!);
    expect(pause).toHaveBeenCalled();
    fireEvent.click(container.querySelector('.hs__toggle')!);
    expect(play).toHaveBeenCalled();
  });

  // @scenario surface=pause_keeps_video_position
  it('일시정지는 그 자리에서 멈추고(다시 재생하면 이어서 본다), 다른 장으로 넘어가면 처음으로 돌린다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} />);
    const video = container.querySelector('[data-banner-id="1"] video') as HTMLVideoElement;
    Object.defineProperty(video, 'currentTime', { configurable: true, writable: true, value: 20 });
    fireEvent.click(container.querySelector('.hs__toggle')!);
    expect(pause).toHaveBeenCalled();
    expect(video.currentTime).toBe(20);
    fireEvent.click(container.querySelector('.hs__next')!);
    expect(video.currentTime).toBe(0);
  });

  // @scenario surface=video_play_abort_is_not_failure
  it('[case:components-misc-video-play-abort-treated-as-failure] 재생 시작 전에 멈추거나 넘겨 재생 요청이 취소(AbortError)돼도 실패로 보지 않고, 다시 재생할 수 있다', async () => {
    play.mockImplementationOnce(() => Promise.reject(new DOMException('The play() request was interrupted by a call to pause().', 'AbortError')));
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true }} />);
    await act(async () => {
      await Promise.resolve();
    });
    // 아직 영상 장이라 간격 넘김은 멈춰 있고, 단추로 다시 재생하면 재생을 요청한다
    expect(swiperState.instance!.autoplay.running).toBe(false);
    fireEvent.click(container.querySelector('.hs__toggle')!);
    play.mockClear();
    fireEvent.click(container.querySelector('.hs__toggle')!);
    expect(play).toHaveBeenCalled();
  });

  // @scenario surface=video_error_falls_back_to_interval
  it('자동 재생이 막히면(NotAllowedError) 그 장은 이미지처럼 간격으로 넘긴다', async () => {
    play.mockImplementationOnce(() => Promise.reject(new DOMException('play() failed because the user didn\'t interact', 'NotAllowedError')));
    render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true }} />);
    await act(async () => {
      await Promise.resolve();
    });
    expect(swiperState.instance!.autoplay.running).toBe(true);
  });

  // @scenario surface=reduced_motion_starts_paused
  it('움직임 줄이기를 켠 방문자는 영상이 멈춘 채 시작하고 단추로 재생한다', () => {
    mockMedia({ reduce: true });
    const { container } = render(<HeroSlider banners={[videoBanner(1)]} />);
    expect(play).not.toHaveBeenCalled();
    const toggle = container.querySelector('.hs__toggle')!;
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    expect(toggle.getAttribute('aria-label')).toBe('배너 다시 재생');
    fireEvent.click(toggle);
    expect(play).toHaveBeenCalled();
  });

  // @scenario surface=reduced_motion_starts_paused
  it('받는 중(배너 없음)으로 먼저 그려진 뒤 영상 배너가 와도, 움직임 줄이기면 멈춘 채 시작한다', () => {
    mockMedia({ reduce: true });
    const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" />);
    rerender(<HeroSlider banners={[videoBanner(1)]} settings={{ width: 'content' }} position="home_hero" />);
    expect(play).not.toHaveBeenCalled();
    expect(container.querySelector('.hs__toggle')?.getAttribute('aria-label')).toBe('배너 다시 재생');
  });

  // @scenario surface=video_error_falls_back_to_interval
  it('영상을 재생할 수 없으면 그 장은 이미지처럼 간격으로 넘긴다', () => {
    const { container } = render(<HeroSlider banners={[videoBanner(1), banner(2)]} settings={{ autoplay: true }} />);
    expect(swiperState.instance!.autoplay.running).toBe(false);
    fireEvent.error(container.querySelector('[data-banner-id="1"] video')!);
    expect(swiperState.instance!.autoplay.running).toBe(true);
  });

  // @scenario surface=video_only_when_media_type_video
  it('미디어 종류가 이미지면 영상 정보가 있어도 이미지로 그린다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { media_type: 'image', videos: { pc: { url: '/vid/x.mp4' } } })]} />);
    expect(container.querySelector('video')).toBeNull();
    expect(container.querySelector('.hs__toggle')).toBeNull();
  });

  describe('유튜브', () => {
    // @scenario surface=youtube_gate_before_consent
    it('마케팅 동의 전에는 대표 이미지와 재생 단추만 두고 YouTube 를 부르지 않는다', () => {
      mockConsent(ready(false));
      const { container } = render(<HeroSlider banners={[youtubeBanner(1), banner(2)]} settings={{ autoplay: true }} />);
      expect(container.querySelector('iframe')).toBeNull();
      expect(container.querySelector('[data-banner-id="1"] img.hs__image')).not.toBeNull();
      const button = container.querySelector('.hs__youtube-play')!;
      expect(button.getAttribute('aria-label')).toBe('유튜브 영상 재생');
      // 동의 전 유튜브 장은 이미지처럼 간격으로 넘긴다
      expect(swiperState.instance!.autoplay.running).toBe(true);
    });

    // @scenario surface=youtube_loads_with_consent
    it('마케팅에 동의했거나 동의 관리가 없는 사이트면 youtube-nocookie 플레이어를 소리 없이 띄운다', () => {
      for (const snapshot of [ready(true), { status: 'none', categories: {} }]) {
        mockConsent(snapshot);
        const { container, unmount } = render(<HeroSlider banners={[youtubeBanner(1), banner(2)]} />);
        const frame = container.querySelector('iframe.hs__youtube-frame') as HTMLIFrameElement;
        expect(frame, JSON.stringify(snapshot)).not.toBeNull();
        const src = new URL(frame.src);
        expect(src.origin).toBe('https://www.youtube-nocookie.com');
        expect(src.pathname).toBe(`/embed/${YT}`);
        expect(src.searchParams.get('mute')).toBe('1');
        expect(src.searchParams.get('playsinline')).toBe('1');
        expect(src.searchParams.get('enablejsapi')).toBe('1');
        expect(frame.getAttribute('tabindex')).toBe('-1');
        expect(container.querySelector('.hs__youtube-play')).toBeNull();
        unmount();
      }
    });

    // @scenario surface=youtube_consent_change_applies
    it('동의 배너에서 동의하면 새로 고치지 않아도 플레이어로 바뀐다', () => {
      const grant = mockConsent(ready(false));
      const { container } = render(<HeroSlider banners={[youtubeBanner(1)]} />);
      expect(container.querySelector('iframe')).toBeNull();
      grant(ready(true));
      expect(container.querySelector('iframe.hs__youtube-frame')).not.toBeNull();
    });

    // @scenario surface=youtube_play_button_loads
    it('동의 전이라도 재생 단추를 누르면 그 배너만 불러온다', () => {
      mockConsent(ready(false));
      const { container } = render(<HeroSlider banners={[youtubeBanner(1), youtubeBanner(2)]} />);
      fireEvent.click(container.querySelector('[data-banner-id="1"] .hs__youtube-play')!);
      expect(container.querySelector('[data-banner-id="1"] iframe')).not.toBeNull();
      expect(container.querySelector('[data-banner-id="2"] iframe')).toBeNull();
    });

    // @scenario surface=youtube_no_consent_runtime
    it('동의 런타임이 없는 화면(관리자 미리보기)은 동의 전으로 본다', () => {
      const { container } = render(<HeroSlider banners={[youtubeBanner(1)]} />);
      expect(container.querySelector('iframe')).toBeNull();
      expect(container.querySelector('.hs__youtube-play')).not.toBeNull();
    });

    // @scenario surface=youtube_ended_message
    it('플레이어가 「끝남」을 알리면 다음 장으로 넘기고, 다른 출처의 메시지는 무시한다', () => {
      mockConsent(ready(true));
      const { container } = render(<HeroSlider banners={[youtubeBanner(1), banner(2)]} settings={{ autoplay: true }} />);
      const frame = container.querySelector('iframe') as HTMLIFrameElement;
      const ended = JSON.stringify({ event: 'onStateChange', info: 0 });
      act(() => {
        window.dispatchEvent(new MessageEvent('message', { data: ended, origin: 'https://evil.example', source: frame.contentWindow }));
      });
      expect(swiperState.instance!.slideNext).not.toHaveBeenCalled();
      act(() => {
        window.dispatchEvent(new MessageEvent('message', { data: ended, origin: 'https://www.youtube-nocookie.com', source: frame.contentWindow }));
      });
      expect(swiperState.instance!.slideNext).toHaveBeenCalledTimes(1);
    });

    // @scenario surface=pause_keeps_video_position
    it('유튜브도 일시정지는 그 자리에서 멈추고, 다른 장으로 넘어가면 처음으로 돌린다', () => {
      mockConsent(ready(true));
      const { container } = render(<HeroSlider banners={[youtubeBanner(1), banner(2)]} settings={{ autoplay: true }} />);
      const frame = container.querySelector('iframe') as HTMLIFrameElement;
      const posted = vi.spyOn(frame.contentWindow!, 'postMessage');
      const commands = () => posted.mock.calls.map(([message]) => JSON.parse(message as string).func);

      fireEvent.click(container.querySelector('.hs__toggle')!);
      expect(commands()).toContain('pauseVideo');
      expect(commands()).not.toContain('seekTo');

      fireEvent.click(container.querySelector('.hs__next')!);
      expect(commands()).toContain('seekTo');
    });

    // @scenario surface=youtube_timeout_falls_back
    it('플레이어가 10초 안에 재생을 알리지 않으면 간격 넘김으로 돌린다', () => {
      mockConsent(ready(true));
      render(<HeroSlider banners={[youtubeBanner(1), banner(2)]} settings={{ autoplay: true }} />);
      expect(swiperState.instance!.autoplay.running).toBe(false);
      act(() => {
        vi.advanceTimersByTime(10_000);
      });
      expect(swiperState.instance!.autoplay.running).toBe(true);
    });

    // @scenario surface=youtube_invalid_id_is_image
    it('영상 ID 모양이 아니면 유튜브로 그리지 않는다', () => {
      mockConsent(ready(true));
      const { container } = render(<HeroSlider banners={[banner(1, { media_type: 'youtube', youtube_id: 'x" onload="' })]} />);
      expect(container.querySelector('iframe')).toBeNull();
      expect(container.querySelector('.hs__youtube')).toBeNull();
    });
  });
});

describe('유튜브 주소·동의 판정', () => {
  // @scenario surface=youtube_url_parsing
  it('주소창·공유·쇼츠·임베드 주소와 영상 ID 를 받고, 다른 호스트·모양은 거른다', () => {
    const cases: Array<[string, string | null]> = [
      [`https://www.youtube.com/watch?v=${YT}&t=10s`, YT],
      [`https://m.youtube.com/watch?v=${YT}`, YT],
      [`youtube.com/watch?v=${YT}`, YT],
      [`https://youtu.be/${YT}?si=abc`, YT],
      [`https://www.youtube.com/shorts/${YT}`, YT],
      [`https://www.youtube.com/embed/${YT}`, YT],
      [`https://www.youtube-nocookie.com/embed/${YT}`, YT],
      [`https://www.youtube.com/live/${YT}`, YT],
      [YT, YT],
      [`https://evil.example/watch?v=${YT}`, null],
      [`https://youtube.com.evil.example/watch?v=${YT}`, null],
      ['https://www.youtube.com/watch?v=short', null],
      ['javascript:alert(1)', null],
      ['', null],
    ];
    for (const [input, expected] of cases) {
      expect(youtubeIdFromUrl(input), input).toBe(expected);
    }
  });

  // @scenario surface=marketing_consent_rules
  it('동의 관리 없음·마케팅 동의만 바로 불러오고, 모름·미동의·런타임 없음은 막는다', () => {
    expect(marketingAllowed({ status: 'none' })).toBe(true);
    expect(marketingAllowed(ready(true))).toBe(true);
    expect(marketingAllowed(ready(false))).toBe(false);
    expect(marketingAllowed({ status: 'unknown', categories: { marketing: true } })).toBe(false);
    expect(marketingAllowed(null)).toBe(false);
  });
});
