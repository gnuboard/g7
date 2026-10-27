import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Swiper, SwiperSlide } from 'swiper/react';
import { Autoplay, EffectFade } from 'swiper/modules';
import type { Swiper as SwiperInstance } from 'swiper';
import 'swiper/css';
import 'swiper/css/effect-fade';

/** 공개 API(`GET /api/plugins/sirsoft-hero_section/hero`)의 이미지 한 장 */
export interface HeroImage {
  /** 공개 이미지 주소 */
  url: string;

  /** 가로 픽셀 */
  width?: number | null;

  /** 세로 픽셀 */
  height?: number | null;
}

/** 공개 API 의 배너 한 건 — 문구는 서버가 요청 언어로 골라 둔 값이다 */
export interface HeroBanner {
  /** 배너 ID */
  id: number;

  /** 제목 */
  title?: string;

  /** 설명 */
  description?: string;

  /** 버튼 문구 — 있으면 버튼만 링크, 없으면 슬라이드 전체가 링크 */
  button_text?: string;

  /** 이미지 대체 텍스트 (서버가 제목으로 대체해 둔다) */
  image_alt?: string;

  /** 제목 표시 여부 */
  title_visible?: boolean;

  /** 제목 색 (#RRGGBB) */
  title_color?: string;

  /** 제목 정렬 */
  title_align?: 'left' | 'center' | 'right';

  /** 설명 표시 여부 */
  description_visible?: boolean;

  /** 설명 색 (#RRGGBB) */
  description_color?: string;

  /** 설명 정렬 */
  description_align?: 'left' | 'center' | 'right';

  /** 이동할 주소 */
  link_url?: string | null;

  /** 새 창 열기 */
  link_new_window?: boolean;

  /** PC·모바일 이미지 */
  images?: { pc?: HeroImage | null; mobile?: HeroImage | null };
}

/** 위치별 슬라이더 설정 */
export interface HeroSliderSettings {
  /** 자동 재생 */
  autoplay?: boolean;

  /** 전환 간격(초) */
  interval_seconds?: number;

  /** 전환 효과 */
  effect?: 'slide' | 'fade' | 'none';
}

export interface HeroSliderProps {
  /** 노출 배너 (공개 API 순서 그대로) */
  banners?: HeroBanner[];

  /** 위치 슬라이더 설정 */
  settings?: HeroSliderSettings;

  /** 위치 키 (`home_hero`·`shop_hero`) — 오버라이드·테스트 식별용 */
  position?: string;

  /** 미리보기용 기기 고정 — 지정하면 화면 폭과 관계없이 그 기기 이미지를 쓴다 */
  forceDevice?: 'pc' | 'mobile';

  /** 사용자 정의 클래스 */
  className?: string;

  /** DOM id */
  id?: string;

  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: Record<string, unknown>;
}

/** 플러그인 식별자 — 문구 키 앞에 붙는다 */
const PLUGIN = 'sirsoft-hero_section';

/** 권장 비율 — 이미지 크기 정보가 없을 때 영역 높이를 미리 잡는다 */
const FALLBACK_RATIO = { pc: '1920 / 640', mobile: '750 / 1000' };

/**
 * 플러그인 문구를 꺼냅니다 — 번역기가 없거나 키를 못 찾으면 기본값.
 *
 * @param key 문구 키 전체(`sirsoft-hero_section.…`) — 정적 검사가 쓰는 키를 찾을 수 있게 줄이지 않는다
 * @param fallback 기본값
 * @param params 치환 값
 * @returns 문구
 */
function t(key: string, fallback: string, params?: Record<string, string>): string {
  const core = (window as { G7Core?: { t?: (k: string, p?: Record<string, string>) => string } }).G7Core;
  const translated = core?.t?.(key, params);

  if (translated && translated !== key && !translated.startsWith(`${PLUGIN}.`)) {
    return translated;
  }

  return Object.entries(params ?? {}).reduce((text, [name, value]) => text.replace(`{{${name}}}`, value), fallback);
}

/**
 * 이미지 크기로 aspect-ratio 값을 만듭니다.
 *
 * @param image 이미지
 * @param fallback 크기가 없을 때 쓸 비율
 * @returns CSS aspect-ratio 값
 */
function ratioOf(image: HeroImage | null | undefined, fallback: string): string {
  return image?.width && image?.height ? `${image.width} / ${image.height}` : fallback;
}

/**
 * 사용자가 움직임 줄이기를 켰는지 확인합니다.
 *
 * @returns 움직임 줄이기면 true
 */
function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * 링크 속성을 만듭니다 — 새 창이면 opener·referrer 를 끊는다.
 *
 * @param banner 배너
 * @returns a 태그 속성 (링크가 없으면 null)
 */
function linkProps(banner: HeroBanner): { href: string; target?: string; rel?: string } | null {
  if (!banner.link_url) {
    return null;
  }

  return banner.link_new_window
    ? { href: banner.link_url, target: '_blank', rel: 'noopener noreferrer' }
    : { href: banner.link_url };
}

/**
 * 히어로섹션 슬라이더 — 사용자 템플릿 확장점(`home_hero`·`shop_hero`)에 끼워지는 플러그인 소유 컴포넌트.
 *
 * 넘김(전환 효과·자동 재생·무한 반복·손가락 넘기기)은 Swiper 가 맡고, 화면의 ‹ › 단추·「1 / 3」·일시정지 단추와
 * 캐러셀 접근성 표시는 이 컴포넌트가 그린다(Swiper 기본 화살표·점 표시는 쓰지 않는다). PC 마우스 끌기는 끈다.
 * 배너가 없으면 아무것도 그리지 않는다(구좌 숨김). 1장이면 컨트롤·자동 재생·넘기기가 없다. PC·모바일 이미지는
 * `<picture>` 로 바꾸고, 업로드 때 잰 크기로 영역 비율을 미리 잡아 화면 밀림을 막는다. 첫 장은 즉시, 나머지는 지연
 * 로딩하되 다음 장은 전환 전에 미리 받는다. 자동 재생은 마우스·포커스·일시정지 단추·움직임 줄이기에서 멈추고,
 * 자동으로 넘어가는 동안에는 장 번호를 화면 읽기 프로그램이 매번 읽지 않게 한다.
 * 템플릿은 자기 오버라이드 폴더(템플릿 루트 기준 src/components/overrides/sirsoft-hero_section/)의 HeroSlider.tsx 로 이 컴포넌트를 대체할 수 있다(같은 props).
 */
export const HeroSlider: React.FC<HeroSliderProps> = ({
  banners = [],
  settings = {},
  position,
  forceDevice,
  className = '',
  id,
  editorAttrs,
}) => {
  const count = banners.length;
  const reduced = useMemo(prefersReducedMotion, []);
  const effect = reduced ? 'none' : (settings.effect ?? 'slide');
  const interval = Math.max(1, Number(settings.interval_seconds ?? 5)) * 1000;
  const canAutoplay = (settings.autoplay ?? true) && count > 1 && !reduced;

  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const [held, setHeld] = useState(false);
  const [swiper, setSwiper] = useState<SwiperInstance | null>(null);
  const regionRef = useRef<HTMLElement>(null);

  // 배너 목록이 바뀌면(언어 전환·재조회) Swiper 를 새로 만들어 첫 장으로
  const key = banners.map((b) => b.id).join(',');
  useEffect(() => {
    setIndex(0);
  }, [key]);

  // 자동 재생은 Swiper 가 돌리고, 멈춤 조건(일시정지 단추·마우스·포커스)은 여기서 건다
  const autoplaying = canAutoplay && !paused && !held;
  useEffect(() => {
    if (!swiper || swiper.destroyed || !swiper.autoplay) {
      return;
    }
    if (autoplaying) {
      swiper.autoplay.start();
    } else {
      swiper.autoplay.stop();
    }
  }, [swiper, autoplaying]);

  const go = useCallback((direction: -1 | 1) => {
    if (!swiper || swiper.destroyed) {
      return;
    }
    if (direction < 0) {
      swiper.slidePrev();
    } else {
      swiper.slideNext();
    }
  }, [swiper]);

  if (count === 0) {
    return null;
  }

  const first = banners[0];
  const pcRatio = ratioOf(first.images?.pc, FALLBACK_RATIO.pc);
  const mobileRatio = first.images?.mobile ? ratioOf(first.images.mobile, FALLBACK_RATIO.mobile) : pcRatio;
  const style = {
    '--hs-ratio-pc': forceDevice === 'mobile' ? mobileRatio : pcRatio,
    '--hs-ratio-mobile': forceDevice === 'pc' ? pcRatio : mobileRatio,
  } as React.CSSProperties;

  const onKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      go(-1);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      go(1);
    }
  };

  return (
    <section
      id={id}
      {...editorAttrs}
      ref={regionRef}
      className={`hs hs--${effect} ${className}`.trim()}
      style={style}
      role="region"
      aria-roledescription="carousel"
      aria-label={t('sirsoft-hero_section.slider.label', '메인 배너')}
      data-position={position}
      onMouseEnter={() => setHeld(true)}
      onMouseLeave={() => setHeld(false)}
      onFocus={() => setHeld(true)}
      onBlur={(event) => {
        if (!regionRef.current?.contains(event.relatedTarget as Node | null)) {
          setHeld(false);
        }
      }}
      onKeyDown={count > 1 ? onKeyDown : undefined}
    >
      <Swiper
        key={key}
        className="hs__track"
        modules={[Autoplay, EffectFade]}
        loop={count > 1}
        effect={effect === 'fade' ? 'fade' : 'slide'}
        fadeEffect={{ crossFade: true }}
        speed={effect === 'none' ? 0 : effect === 'fade' ? 600 : 500}
        allowTouchMove={count > 1}
        simulateTouch={false}
        autoplay={canAutoplay ? { delay: interval, disableOnInteraction: false } : false}
        onSwiper={setSwiper}
        onRealIndexChange={(instance: SwiperInstance) => setIndex(instance.realIndex)}
      >
        {banners.map((banner, i) => (
          <SwiperSlide key={banner.id}>
            <HeroSlide
              banner={banner}
              index={i}
              count={count}
              active={i === index}
              eager={i === 0 || i === (index + 1) % count}
              forceDevice={forceDevice}
            />
          </SwiperSlide>
        ))}
      </Swiper>

      {count > 1 && (
        <div className="hs__controls">
          <button type="button" className="hs__control hs__prev" aria-label={t('sirsoft-hero_section.slider.previous', '이전 배너')} onClick={() => go(-1)}>
            <span aria-hidden="true">‹</span>
          </button>
          <span className="hs__counter" aria-live={autoplaying ? 'off' : 'polite'}>{`${index + 1} / ${count}`}</span>
          <button type="button" className="hs__control hs__next" aria-label={t('sirsoft-hero_section.slider.next', '다음 배너')} onClick={() => go(1)}>
            <span aria-hidden="true">›</span>
          </button>
          {canAutoplay && (
            <button
              type="button"
              className="hs__control hs__toggle"
              aria-pressed={paused}
              aria-label={paused ? t('sirsoft-hero_section.slider.play', '자동 넘김 다시 시작') : t('sirsoft-hero_section.slider.pause', '자동 넘김 일시정지')}
              onClick={() => setPaused((p) => !p)}
            >
              <span aria-hidden="true">{paused ? '▶' : '❚❚'}</span>
            </button>
          )}
        </div>
      )}
    </section>
  );
};

interface HeroSlideProps {
  banner: HeroBanner;
  index: number;
  count: number;
  active: boolean;
  eager: boolean;
  forceDevice?: 'pc' | 'mobile';
}

/**
 * 슬라이드 한 장 — 이미지·문구·링크.
 *
 * @param props 슬라이드 속성
 * @returns 슬라이드
 */
const HeroSlide: React.FC<HeroSlideProps> = ({ banner, index, count, active, eager, forceDevice }) => {
  const pc = banner.images?.pc ?? null;
  const mobile = banner.images?.mobile ?? null;
  const link = linkProps(banner);
  const hasButton = !!banner.button_text && !!link;
  const alt = banner.image_alt ?? banner.title ?? '';

  const main = forceDevice === 'mobile' ? (mobile ?? pc) : pc;
  const image = main ? (
    <picture className="hs__picture">
      {!forceDevice && mobile && (
        <source media="(max-width: 767px)" srcSet={mobile.url} width={mobile.width ?? undefined} height={mobile.height ?? undefined} />
      )}
      <img
        className="hs__image"
        src={main.url}
        alt={alt}
        width={main.width ?? undefined}
        height={main.height ?? undefined}
        loading={eager ? 'eager' : 'lazy'}
        {...(index === 0 ? { fetchPriority: 'high' } : {})}
        decoding="async"
      />
    </picture>
  ) : null;

  const text = (
    <div className="hs__text">
      {banner.title_visible !== false && banner.title && (
        <h2 className="hs__title" style={{ color: banner.title_color, textAlign: banner.title_align }}>{banner.title}</h2>
      )}
      {banner.description_visible !== false && banner.description && (
        <p className="hs__description" style={{ color: banner.description_color, textAlign: banner.description_align }}>{banner.description}</p>
      )}
      {hasButton && (
        <div className="hs__actions" style={{ textAlign: banner.title_align }}>
          <a className="hs__button" {...link!} tabIndex={active ? undefined : -1}>{banner.button_text}</a>
        </div>
      )}
    </div>
  );

  const body = (
    <>
      {image}
      {text}
    </>
  );

  return (
    <div
      className={`hs__slide${active ? ' hs__slide--active' : ''}`}
      role="group"
      aria-roledescription="slide"
      aria-label={`${index + 1} / ${count}`}
      aria-hidden={active ? undefined : true}
      data-banner-id={banner.id}
      data-index={index}
    >
      {link && !hasButton ? (
        <a className="hs__link" {...link} aria-label={banner.title || alt} tabIndex={active ? undefined : -1}>{body}</a>
      ) : body}
    </div>
  );
};

export default HeroSlider;
