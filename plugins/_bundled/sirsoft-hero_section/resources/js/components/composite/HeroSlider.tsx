import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Swiper, SwiperSlide } from 'swiper/react';
import { Autoplay, EffectFade, EffectCube, EffectFlip, EffectCoverflow, EffectCreative } from 'swiper/modules';
import type { Swiper as SwiperInstance, SwiperOptions } from 'swiper';
// Swiper CSS 는 확장 CSS 에 넣지 않고 동봉 자산으로 따로 싣는다 — support/swiperStyles 참조
import { useSwiperStyles } from '../../support/swiperStyles';
import { sitePath } from '../../apiUrl';
import { mediaSrc, responsiveSrcset, sliderImageSizes, type SliderBox } from '../../support/thumbnail';
import { HeroVideoMedia, useHeroScreen, type HeroVideo } from '../../support/media';
import { LetterText, lineDelay, textMotion, textOverflows, textSpot, textVars, useHeaderOverlay, useTextDrag, type HeroTextPresentation, type TextSpot } from '../../support/text';

export type { HeroVideo } from '../../support/media';
export type { HeroTextPresentation, TextSpot } from '../../support/text';

/** 공개 API(`GET /api/plugins/sirsoft-hero_section/hero`)의 이미지 한 장 */
export interface HeroImage {
  /** 공개 이미지 주소 (공개 API — 썸네일 `?size=`·같은 출처 자리의 기준) */
  url: string;

  /** 원본 직접 주소 (공개 자산 디스크 행만, 아니면 null) — 원본 한 장(`src`·srcset 원본 후보)에만 쓴다 (#878) */
  direct_url?: string | null;

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

  /** 머리글 — 제목 위 짧은 한 줄 */
  eyebrow?: string;

  /** 머리글 표시 여부 */
  eyebrow_visible?: boolean;

  /** 머리글 색 (#RRGGBB) */
  eyebrow_color?: string;

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

  /** PC·모바일 이미지 — 미디어가 영상이면 대표 이미지(받는 동안·움직임 줄이기) */
  images?: { pc?: HeroImage | null; mobile?: HeroImage | null };

  /** 미디어 종류 — 없으면 이미지 */
  media_type?: 'image' | 'video';

  /** PC·모바일 영상 파일 (미디어 종류가 영상 파일일 때, 모바일이 없으면 PC 영상) */
  videos?: { pc?: HeroVideo | null; mobile?: HeroVideo | null } | null;

  /**
   * 기기별 이미지 초점(%)·선택적 문구 배경과 문구 자리(기기별 가로·세로 %, 폭 %)·등장 효과(효과·속도·시작 지연)·바탕 밝기(메뉴 글자색)
   */
  presentation?: ({ pc_x?: number; pc_y?: number; mobile_x?: number; mobile_y?: number; overlay?: 'none' | 'dark' | 'light' } & HeroTextPresentation) | null;
}

/** 전환 효과 — Swiper 효과 이름과 같고, none 은 0ms 슬라이드 */
export type HeroEffect = 'slide' | 'fade' | 'cube' | 'flip' | 'coverflow' | 'creative' | 'none';

/** 위치별 슬라이더 설정 */
export interface HeroSliderSettings {
  /** 자동 재생 */
  autoplay?: boolean;

  /** 전환 간격(초) */
  interval_seconds?: number;

  /** 전환 효과 */
  effect?: HeroEffect;

  /** 표시 폭 — 본문 폭(content, 기본)이면 `contentClass` 상자 안에, 화면 전체(full)면 끝까지 그리고 문구·컨트롤만 그 상자에 맞춘다 */
  width?: 'content' | 'full';

  /**
   * 메뉴 겹치기 — 화면 전체 폭일 때만 쓴다. 켜면 문서 뿌리에 `data-header-overlay="dark|light"`(지금 장의 바탕 밝기)를 달아 사이트 상단
   * 메뉴가 배너 위에 투명하게 겹치게 하고, 문구는 템플릿이 알려 준 메뉴 높이(`--g7-header-height`)만큼 아래에서 시작한다.
   */
  header_overlay?: boolean;

  /**
   * 페이징 모양 — 점(dots, 기본) · 진행 막대 점(progress, 지금 장 점이 길어지고 자동 넘김까지 남은 시간이 찬다) ·
   * 배너 이름 탭(tabs, 장마다 배너 제목과 진행 막대, 휴대폰에서는 막대만) · 번호(fraction, 「01 / 05」). 모르는 값은 점.
   */
  pagination?: HeroPagination;
}

/** 페이징 모양 — 서버 Enum `SlidePagination` 과 같다 */
export type HeroPagination = 'dots' | 'progress' | 'tabs' | 'fraction';

const PAGINATIONS: readonly HeroPagination[] = ['dots', 'progress', 'tabs', 'fraction'];

/**
 * 장 번호를 두 자리로 맞춥니다(「01 / 05」).
 *
 * @param n 장 번호(1부터)
 * @returns 두 자리 이상 문자열
 */
const twoDigits = (n: number): string => String(n).padStart(2, '0');

/**
 * 문구 바탕 클래스 — 「없음」은 바탕 규칙이 없으므로 클래스를 붙이지 않는다(자기 CSS 에 없는 이름을 걸지 않는다).
 *
 * @param overlay 배너의 문구 바탕
 * @returns 앞 공백을 붙인 클래스 또는 빈 문자열
 */
const overlayClass = (overlay?: 'none' | 'dark' | 'light'): string => {
  if (overlay === 'dark') return ' hs-slider__text--dark';
  if (overlay === 'light') return ' hs-slider__text--light';
  return '';
};

export interface HeroSliderProps {
  /** 노출 배너 (공개 API 순서 그대로) */
  banners?: HeroBanner[];

  /** 위치 슬라이더 설정 */
  settings?: HeroSliderSettings;

  /** 위치 키 (`home_hero`·`shop_hero`·`board_hero` 등) — 오버라이드·테스트 식별용, 브라우저 기억 키 */
  position?: string;

  /**
   * 동적 위치의 대상 — 확장점 조각이 화면 경로 값(게시판 slug·상품 코드 등)을 넘긴다. 주면 브라우저 기억을 위치 + 대상마다 따로 두고,
   * 기억이 「배너 있음」일 때만 받는 동안 스켈레톤을 그린다(대상마다 첫 로드 힌트를 줄 수 없어 첫 방문은 자리를 잡지 않는다).
   */
  target?: string;

  /**
   * 미리보기용 기기 고정 — 지정하면 화면 폭과 관계없이 그 기기 이미지·영상·모양을 쓴다(mobile 이면 루트에 `hs-slider--mobile`·
   * `hs-slider--portable`, pc 면 둘 다 없음). 없으면 엔진 반응형 폭으로 판정한다.
   */
  forceDevice?: 'pc' | 'mobile';

  /**
   * 템플릿 본문 상자 클래스(지금 화면 폭에 맞는 값) — 확장점이 넘긴다(`extensionPointProps.contentClass`, 데스크톱은
   * `contentClassDesktop`). 본문 폭이면 슬라이더 바깥, 화면 전체면 문구 층·넘김 단추에 쓴다. 비우면 둘 다 감싸지 않는다.
   */
  contentClass?: string;

  /** 사용자 정의 클래스 */
  className?: string;

  /**
   * 배너를 받는 중 — 확장점 조각이 공개 API 응답 전(`undefined`)이면 true 로 넘긴다. 받는 동안 배너가 없으면 같은 비율·폭의
   * 스켈레톤을 그려 받은 뒤 아래 본문이 밀리지 않게 한다. 직전 방문에서 그 위치가 0건이었으면 스켈레톤도 그리지 않는다.
   */
  loading?: boolean;

  /**
   * 받는 동안 스켈레톤의 표시 폭 — 확장점 조각이 첫 로드 때 받은 플러그인 설정 힌트(`_global.plugins['sirsoft-hero_section'].slot_widths`)
   * 를 넘긴다. 없으면 브라우저에 남긴 직전 모양, 그것도 없으면 본문 폭.
   */
  skeletonWidth?: 'content' | 'full' | '';

  /**
   * 받는 동안 메뉴를 겹칠지 — 확장점 조각이 플러그인 설정 힌트(`slot_overlays`)를 넘긴다. 켜져 있으면 스켈레톤도 메뉴 밑으로 들어가
   * 배너가 와도 화면이 위아래로 밀리지 않는다. 없으면 브라우저에 남긴 직전 모양.
   */
  skeletonOverlay?: boolean;

  /**
   * 받는 동안 자리를 잡을지 — 확장점 조각이 첫 로드 플러그인 설정 힌트(`slot_banners`)로 넘긴다. 첫 접속은 브라우저 기억이 없으므로
   * 이 값이 false 면 배너가 없는 위치에 스켈레톤을 그리지 않는다. 브라우저 기억이 0건이어도 그리지 않는다(둘 중 하나라도 없음이면 생략).
   */
  skeletonBanners?: boolean;

  /**
   * 「관리자에서 수정」 단추를 보일지 — 확장점 조각이 공개 응답의 수정 권한(`abilities.can_update`)을 넘긴다. 켜면 오른쪽 위에
   * 지금 보이는 배너의 관리자 수정 화면을 새 탭으로 여는 링크를 그린다. 편집 미리보기(`forceDevice`)에서는 그리지 않는다.
   */
  editable?: boolean;

  /**
   * 편집 미리보기 전용 — 주면 문구 상자를 끌거나 화살표 키로 옮길 수 있고, 놓을 때 고정 기기(`forceDevice`)의 새 자리를 알린다.
   * 사이트 화면에서는 넘기지 않는다.
   */
  onTextMove?: (spot: TextSpot, device: 'pc' | 'mobile') => void;

  /** 편집 미리보기 전용 — 값이 바뀌면 문구 등장 효과를 처음부터 다시 재생한다 */
  replay?: number;

  /** DOM id */
  id?: string;

  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: Record<string, unknown>;
}

/** 플러그인 식별자 — 문구 키 앞에 붙는다 */
const PLUGIN = 'sirsoft-hero_section';

/**
 * 효과별 Swiper 옵션 — 효과 이름·전환 시간·효과 세부값. 그림자는 끈다(배너 영역 밖으로 번져 잘리거나 사진을 어둡게 덮는다).
 * 모르는 값은 slide 로 그린다.
 */
const EFFECTS: Record<HeroEffect, Partial<SwiperOptions>> = {
  slide: { effect: 'slide', speed: 500 },
  fade: { effect: 'fade', speed: 600, fadeEffect: { crossFade: true } },
  cube: { effect: 'cube', speed: 800, cubeEffect: { shadow: false, slideShadows: false } },
  flip: { effect: 'flip', speed: 700, flipEffect: { slideShadows: false } },
  coverflow: { effect: 'coverflow', speed: 600, coverflowEffect: { rotate: 40, stretch: 0, depth: 150, modifier: 1, slideShadows: false } },
  creative: { effect: 'creative', speed: 700, creativeEffect: { prev: { translate: ['-20%', 0, -1] }, next: { translate: ['100%', 0, 0] } } },
  none: { effect: 'slide', speed: 0 },
};

/** Swiper 기본 화살표 아이콘(navigation 모듈과 같은 SVG) — 이전 단추는 CSS 가 180° 돌린다 */
/** 「관리자에서 수정」 연필 아이콘 */
const PENCIL = (
  <svg className="hs-slider__admin-edit-icon" width="12" height="12" viewBox="0 0 16 16" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <path d="M12.15 1.15a1.5 1.5 0 0 1 2.12 0l.58.58a1.5 1.5 0 0 1 0 2.12L5.6 13.1 2 14l.9-3.6 9.25-9.25Z" />
  </svg>
);

const ARROW = (
  <svg className="hs-slider__arrow-icon" width="11" height="20" viewBox="0 0 11 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <path d="M0.38296 20.0762C0.111788 19.805 0.111788 19.3654 0.38296 19.0942L9.19758 10.2796L0.38296 1.46497C0.111788 1.19379 0.111788 0.754138 0.38296 0.482966C0.654131 0.211794 1.09379 0.211794 1.36496 0.482966L10.4341 9.55214C10.8359 9.9539 10.8359 10.6053 10.4341 11.007L1.36496 20.0762C1.09379 20.3474 0.654131 20.3474 0.38296 20.0762Z" fill="currentColor" />
  </svg>
);

/** 기기별 고정 표시 비율 — 배너가 넘어가도 영역 높이를 유지한다 */
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
 * 장 번호 문구(「3개 중 1번째」)를 만듭니다 — 슬라이드 이름과 넘길 때 알림이 같은 문구를 쓴다.
 *
 * @param index 0부터 센 장 번호
 * @param count 전체 장 수
 * @returns 화면 읽기 프로그램이 읽을 장 번호
 */
function slideLabel(index: number, count: number): string {
  return t('sirsoft-hero_section.slider.slide_label', '{{count}}개 중 {{n}}번째', { n: String(index + 1), count: String(count) });
}

/** 넘길 때 알린 장 번호를 지우기까지의 시간 — 알림은 바뀐 순간 읽히고, 남겨 두면 훑어 읽기에서 슬라이드 이름과 겹쳐 두 번 읽힌다 */
const ANNOUNCE_CLEAR_MS = 3000;

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
 * 배너가 재생할 수 있는 미디어(영상 파일)를 가졌는지 확인합니다.
 *
 * @param banner 배너
 * @returns 영상이면 'video', 아니면 null
 */
function mediaOf(banner?: HeroBanner): 'video' | null {
  return banner?.media_type === 'video' && banner.videos?.pc?.url ? 'video' : null;
}

/**
 * 링크 속성을 만듭니다 — 새 창이면 opener·referrer 를 끊는다.
 *
 * 브라우저가 그대로 여는 주소라 사이트 안 경로(`/…`)에는 하위 폴더 마운트 접두사를 붙인다(`sitePath` — 멱등,
 * `http(s)://` 바깥 주소는 그대로).
 *
 * @param banner 배너
 * @returns a 태그 속성 (링크가 없으면 null)
 */
function linkProps(banner: HeroBanner): { href: string; target?: string; rel?: string } | null {
  if (!banner.link_url) {
    return null;
  }
  const href = sitePath(banner.link_url);

  return banner.link_new_window
    ? { href, target: '_blank', rel: 'noopener noreferrer' }
    : { href };
}

/**
 * 배너의 이미지·영상 주소(대표 이미지·모바일 이미지·영상 파일)에 사이트 마운트 접두사를 붙입니다.
 *
 * `<img src|srcset>`·`<video src|poster>` 는 라우터를 거치지 않고 브라우저가 그대로 받는 주소라, 하위 폴더 설치
 * (`https://host/g7/`)에서 접두사가 없으면 사이트 밖을 부른다. 서버도 접두사를 붙여 내지만, 붙이기 전에 담긴 공개 캐시와
 * 다른 확장이 필터로 넣은 값이 있으므로 여기서도 붙인다(`sitePath` 는 멱등이고 바깥 주소는 그대로 둔다).
 *
 * @param banner 배너
 * @returns 주소를 고친 배너 (고칠 것이 없으면 같은 객체)
 */
function withSiteMedia(banner: HeroBanner): HeroBanner {
  // 직접 주소(`direct_url`)도 같은 규칙으로 고친다 — 드라이버가 낸 절대 주소는 `sitePath` 가 그대로 둔다
  const fix = <T extends { url?: string; direct_url?: string | null } | null | undefined>(media: T): T => {
    if (!media) {
      return media;
    }
    const url = typeof media.url === 'string' ? sitePath(media.url) : media.url;
    const direct = typeof media.direct_url === 'string' && media.direct_url !== '' ? sitePath(media.direct_url) : media.direct_url;

    return url !== media.url || direct !== media.direct_url ? { ...media, url, direct_url: direct } : media;
  };
  const images = banner.images ? { pc: fix(banner.images.pc), mobile: fix(banner.images.mobile) } : banner.images;
  const videos = banner.videos ? { pc: fix(banner.videos.pc), mobile: fix(banner.videos.mobile) } : banner.videos;
  const same = images?.pc === banner.images?.pc && images?.mobile === banner.images?.mobile
    && videos?.pc === banner.videos?.pc && videos?.mobile === banner.videos?.mobile;
  return same ? banner : { ...banner, images, videos };
}

/**
 * 히어로섹션 슬라이더 — 사용자 템플릿 확장점(`home_hero`·`shop_hero`)에 끼워지는 플러그인 소유 컴포넌트.
 *
 * 넘김(전환 효과 slide·fade·cube·flip·coverflow·creative·none, 자동 재생, 무한 반복, 손가락 넘기기, PC 마우스 끌기)은
 * Swiper 가 맡는다. 좌우 화살표·점 표시는 Swiper 기본 모양(navigation·pagination CSS·아이콘)이지만 이름 붙은 button 으로
 * 이 컴포넌트가 그리고, 일시정지 단추·화면 읽기용 장 번호·캐러셀 접근성 표시도 이 컴포넌트가 그린다. 화살표는 데스크톱(1024px
 * 이상)에서만 보인다 — 좁은 화면에서는 문구를 가리므로 손가락 넘기기와 점으로 넘긴다.
 * 배너가 없으면 아무것도 그리지 않는다(구좌 숨김). 1장이면 컨트롤·자동 재생·넘기기가 없다. PC·모바일 이미지는
 * 기기 판정(`useHeroScreen` — 미리보기 기기 고정, 다음 엔진 반응형 폭)으로 하나를 고르고, 기기별 고정 표시 비율로 영역 높이를 잡아 화면 밀림을 막는다. 첫 장은 즉시, 나머지는 지연
 * 로딩하되 다음 장은 전환 전에 미리 받는다. 자동 재생은 마우스·포커스·일시정지 단추·움직임 줄이기에서 멈추고,
 * 자동으로 넘어가는 동안에는 장 번호를 화면 읽기 프로그램이 매번 읽지 않게 한다.
 * 표시 폭(`settings.width`)이 본문 폭이면 확장점이 넘긴 본문 상자(`contentClass`) 안에 그리고, 화면 전체면 끝까지 그리되
 * 문구·넘김 단추만 그 상자에 맞춘다(화면 전체는 높이 상한 640px — 그보다 넓은 화면은 초점 기준으로 위아래를 자른다).
 * 미디어가 영상 파일인 배너는 활성 장일 때 소리 없이 재생하고, 그동안은 간격 넘김을 멈췄다가 영상이 끝나면 다음 장으로
 * 넘긴다(한 장뿐이면 반복). 움직임 줄이기에서는 영상을 멈춘 채 시작하고 일시정지 단추로 재생한다. 재생할 수 없는 영상은
 * 이미지처럼 간격으로 넘긴다. 일시정지 단추는 자동 넘김과 영상 재생을 함께 멈춘다.
 * 템플릿은 자기 오버라이드 폴더(템플릿 루트 기준 src/components/overrides/sirsoft-hero_section/)의 HeroSlider.tsx 로 이 컴포넌트를 대체할 수 있다(같은 props).
 */
export const HeroSlider: React.FC<HeroSliderProps> = ({
  banners: givenBanners = [],
  settings = {},
  position,
  target,
  forceDevice,
  contentClass = '',
  loading = false,
  skeletonWidth = '',
  skeletonOverlay,
  skeletonBanners,
  editable = false,
  onTextMove,
  replay = 0,
  className = '',
  id,
  editorAttrs,
}) => {
  const banners = useMemo(() => givenBanners.map(withSiteMedia), [givenBanners]);
  const count = banners.length;
  // Swiper CSS 가 실리기 전에는 장들이 세로로 쌓였다가 펴지며 아래 본문을 밀어내므로 같은 자리의 스켈레톤을 둔다
  const stylesReady = useSwiperStyles();
  // 직전 방문의 그 위치(동적이면 위치 + 대상) 모양(배너 유무·표시 폭) — 받는 동안의 스켈레톤을 실제와 같은 폭으로, 0건 자리는 아예 그리지 않게
  const dynamic = target !== undefined;
  const slot = memorySlot(position, target);
  const remembered = useMemo(() => readMemory(slot), [slot]);
  const width = settings.width;
  const overlaySetting = settings.header_overlay === true;
  const firstTone = toneOf(banners[0]);
  useEffect(() => {
    // 응답이 온 때만 기억한다(공개 API 는 늘 표시 폭을 함께 준다) — 받기에 실패한 방문을 「0건」으로 기억하면 다음 방문에 스켈레톤 없이 배너가 끼어든다
    if (!loading && width !== undefined) {
      writeMemory(slot, { banners: count, width: width === 'full' ? 'full' : 'content', overlay: overlaySetting && width === 'full', tone: firstTone });
    }
  }, [loading, slot, count, width, overlaySetting, firstTone]);
  const full = settings.width === 'full';
  const box = contentClass.trim();
  // 화면 전체 폭이면 문구·넘김 단추를 본문 상자에 맞춘다 — 넓은 화면에서 글이 화면 끝에 붙지 않고 본문과 같은 줄에 선다
  const textBox = full ? box : '';
  const reduced = useMemo(prefersReducedMotion, []);
  const requested = settings.effect ?? 'slide';
  const effect: HeroEffect = reduced ? 'none' : (requested in EFFECTS ? requested : 'slide');
  const interval = Math.max(1, Number(settings.interval_seconds ?? 5)) * 1000;
  const canAutoplay = (settings.autoplay ?? true) && count > 1 && !reduced;
  const pagination: HeroPagination = PAGINATIONS.includes(settings.pagination as HeroPagination) ? settings.pagination as HeroPagination : 'dots';

  const [index, setIndex] = useState(0);
  // 영상이 있는 배너 묶음 — 움직임 줄이기면 멈춘 채 시작하고 단추로 재생한다. 사이트는 받는 중(배너 없음)으로 먼저 그리므로
  // 첫 값으로 굳히지 않고, 방문자가 단추를 누르기 전까지는 지금 배너로 다시 판단한다
  const hasMedia = banners.some((banner) => mediaOf(banner) !== null);
  const [pausedChoice, setPausedChoice] = useState<boolean | null>(null);
  const paused = pausedChoice ?? (reduced && hasMedia);
  // 재생할 수 없던 배너 · 이번 차례에 끝까지 본 배너
  const [failedMedia, setFailedMedia] = useState<ReadonlySet<number>>(() => new Set());
  const [endedMedia, setEndedMedia] = useState<ReadonlySet<number>>(() => new Set());
  const [hovered, setHovered] = useState(false);
  const [focusWithin, setFocusWithin] = useState(false);
  const [swiper, setSwiper] = useState<SwiperInstance | null>(null);
  const regionRef = useRef<HTMLElement>(null);
  // 마우스·탭으로 누르며 들어온 포커스는 자동 넘김을 붙잡지 않는다 — 키보드를 쓰면 다시 포커스로 멈춘다(누르고 나간 뒤 넘김이 멈춘 채 남지 않게)
  const pointerInput = useRef(false);
  useEffect(() => {
    const doc = regionRef.current?.ownerDocument;
    if (!doc) {
      return;
    }
    const onKey = () => {
      pointerInput.current = false;
    };
    doc.addEventListener('keydown', onKey, true);
    return () => doc.removeEventListener('keydown', onKey, true);
  }, [count > 0, stylesReady]);

  // 배너 목록이 바뀌면(언어 전환·재조회) Swiper 를 새로 만들어 첫 장으로
  const key = banners.map((b) => b.id).join(',');
  useEffect(() => {
    setIndex(0);
  }, [key]);

  const activeBanner = banners[index];
  // 메뉴 겹침 — 사이트 화면의 화면 전체 폭에서만(본문 폭이면 메뉴 양옆이 배너 밖이라 글자가 묻힌다). 편집 미리보기(기기 고정)는 신호를 내지 않는다.
  // 받는 동안에는 힌트(없으면 직전 방문 모양)를 따르고, 바탕 밝기는 직전 첫 장(없으면 사이트 다크 모드면 어두움)으로 둔다
  const skeletonFull = (skeletonWidth || remembered?.width) === 'full';
  // 첫 로드 힌트와 직전 방문 기억 중 하나라도 「없음」이면 자리를 잡지 않는다 — 첫 접속은 기억이 없어 힌트가 막고, 기간이 끝나 힌트가
  // 낡아도(저장이 있어야 갱신된다) 저장소를 쓸 수 있는 재방문은 기억이 막는다. 틀려도 배너는 그대로 그려지고 스켈레톤만 건너뛴다.
  // 동적 위치는 힌트가 없으므로 그 대상의 기억이 「배너 있음」일 때만 잡는다 — 배너 없는 게시판마다 빈 자리가 깜빡이지 않게
  const waiting = count === 0 && loading && skeletonBanners !== false && (dynamic ? (remembered?.banners ?? 0) > 0 : remembered?.banners !== 0);
  const overlayOn = !forceDevice && (waiting
    ? skeletonFull && (skeletonOverlay ?? remembered?.overlay === true)
    : full && overlaySetting && count > 0);
  useHeaderOverlay(overlayOn ? (waiting ? (remembered?.tone ?? siteTone()) : toneOf(activeBanner)) : null);
  /** 이 배너의 영상이 지금 재생 차례를 맡는지 — 재생할 수 없거나 이번 차례에 끝났으면 이미지처럼 간격으로 넘긴다 */
  const mediaRuns = (banner?: HeroBanner) => {
    const media = mediaOf(banner);
    return !!banner && media !== null && !failedMedia.has(banner.id) && !endedMedia.has(banner.id);
  };
  const activeMediaRuns = mediaRuns(activeBanner);

  // 장이 바뀌면 그 장의 「끝까지 봄」 표시를 지워 다시 처음부터 재생한다
  useEffect(() => {
    const id = activeBanner?.id;
    if (id !== undefined) {
      setEndedMedia((previous) => (previous.has(id) ? new Set([...previous].filter((value) => value !== id)) : previous));
    }
  }, [activeBanner?.id]);

  // 관리자 미리보기(iframe 문서)에서는 Swiper 가 붙지 않는다 — Swiper 14 의 mount 가 요소를 바깥 창의 HTMLElement 로만 확인해
  // iframe 요소면 조용히 초기화를 건너뛴다. 그 인스턴스로 넘기면(자동 넘김 타이머 포함) 요소가 없어 TypeError 가 난다.
  // 그래서 바깥 문서가 아니면 Swiper 를 부르지 않고 장 번호만 바꿔 CSS(hs-slider--static, --hs-index)로 그 장을 보인다(전환 효과·자동 넘김 없음)
  const [framed, setFramed] = useState(false);
  useLayoutEffect(() => {
    const doc = regionRef.current?.ownerDocument;
    setFramed(!!doc && doc !== document);
  }, [count > 0, stylesReady]);
  // 이미지 sizes 에 줄 실제 상자 크기 — 본문 폭이면 템플릿이 정한 상자라 재어야 안다. 재기 전(undefined)에는 장이 이미지 주소를 붙이지 않아
  // 화면 폭(100vw)으로 받기 시작하지 않는다(layout effect 라 칠하기 전에 같은 작업에서 잰 값으로 다시 그린다). 못 재면 null → 100vw.
  const [sliderBox, setSliderBox] = useState<SliderBox | null | undefined>(undefined);
  useLayoutEffect(() => {
    const element = regionRef.current;
    if (!element) {
      return undefined;
    }
    // 변환(확대·축소 미리보기)과 무관한 배치 크기를 쓴다
    const measure = () => {
      const width = Math.round(element.offsetWidth);
      const height = Math.round(element.offsetHeight);
      setSliderBox((previous) => {
        if (!(width > 0)) {
          return previous ?? null;
        }
        return previous && previous.width === width && previous.height === height ? previous : { width, height };
      });
    };
    measure();
    if (typeof ResizeObserver === 'undefined') {
      return undefined;
    }
    const observer = new ResizeObserver(measure);
    observer.observe(element);
    return () => observer.disconnect();
  }, [count > 0, stylesReady]);
  /** Swiper 를 불러도 되는지 — 파기되지 않았고 요소에 실제로 붙었을 때만 */
  const swiperReady = (instance: SwiperInstance | null): instance is SwiperInstance => !!instance && !instance.destroyed && instance.initialized !== false;

  // 자동 재생은 Swiper 가 돌리고, 멈춤 조건(일시정지 단추·마우스·포커스·활성 장 영상 재생 중)은 여기서 건다
  const autoplaying = canAutoplay && !framed && !paused && !hovered && !focusWithin && !activeMediaRuns;
  useEffect(() => {
    if (framed || !swiperReady(swiper) || !swiper.autoplay) {
      return;
    }
    if (autoplaying) {
      swiper.autoplay.start();
    } else {
      swiper.autoplay.stop();
    }
  }, [swiper, autoplaying, framed]);

  // 장 번호 알림 — 방문자가 넘긴 때만(자동으로 넘어가는 동안은 읽지 않는다) 잠깐 채운다. 장 번호는 슬라이드 이름에 늘 있으므로
  // 알림 칸을 비워 두지 않으면 훑어 읽기에서 같은 번호를 두 번 듣는다
  const live = !(autoplaying || activeMediaRuns);
  const [announcement, setAnnouncement] = useState('');
  const announcedIndex = useRef(index);
  useEffect(() => {
    if (announcedIndex.current === index) {
      return;
    }
    announcedIndex.current = index;
    if (!live) {
      setAnnouncement('');
      return;
    }
    setAnnouncement(slideLabel(index, count));
    const timer = window.setTimeout(() => setAnnouncement(''), ANNOUNCE_CLEAR_MS);
    return () => window.clearTimeout(timer);
  }, [index]);

  const goTo = useCallback((target: number) => {
    if (framed) {
      setIndex(target);
    } else if (swiperReady(swiper)) {
      swiper.slideToLoop(target);
    }
  }, [swiper, framed]);

  const go = useCallback((direction: -1 | 1) => {
    if (framed) {
      setIndex((current) => (current + direction + count) % count);
      return;
    }
    if (!swiperReady(swiper)) {
      return;
    }
    if (direction < 0) {
      swiper.slidePrev();
    } else {
      swiper.slideNext();
    }
  }, [swiper, framed, count]);

  /** 영상이 끝났다 — 자동 넘김이 막히지 않았으면 바로 다음 장, 막혔으면 표시만 해 두고 간격 넘김에 맡긴다 */
  const onMediaEnded = (banner: HeroBanner) => {
    if (banner.id !== activeBanner?.id) {
      return;
    }
    setEndedMedia((previous) => new Set(previous).add(banner.id));
    if (canAutoplay && !paused && !hovered && !focusWithin) {
      go(1);
    }
  };
  const onMediaFailed = (banner: HeroBanner) => setFailedMedia((previous) => (previous.has(banner.id) ? previous : new Set(previous).add(banner.id)));
  const toggleLabel = hasMedia
    ? (paused ? t('sirsoft-hero_section.slider.media_play', '배너 다시 재생') : t('sirsoft-hero_section.slider.media_pause', '배너 일시정지'))
    : (paused ? t('sirsoft-hero_section.slider.play', '자동 넘김 다시 시작') : t('sirsoft-hero_section.slider.pause', '자동 넘김 일시정지'));

  // 기기 모양(영역 비율·초점·문구 자리·탭·화살표)은 CSS 폭 미디어가 아니라 엔진 폭 판정의 루트 클래스로 고른다 — 레이아웃 편집기
  // 미리보기 기기 폭·엔진 responsive 와 같은 경계(휴대폰 0~767px, 휴대 기기 0~1023px). 미리보기 기기 고정이 먼저다
  const heroScreen = useHeroScreen(forceDevice);
  const deviceClass = `${heroScreen.mobile ? ' hs-slider--mobile' : ''}${heroScreen.portable ? ' hs-slider--portable' : ''}`;

  const pcRatio = FALLBACK_RATIO.pc;
  const mobileRatio = FALLBACK_RATIO.mobile;
  const style = {
    '--hs-ratio-pc': forceDevice === 'mobile' ? mobileRatio : pcRatio,
    '--hs-ratio-mobile': forceDevice === 'pc' ? pcRatio : mobileRatio,
    ...(framed ? { '--hs-index': index } : {}),
  } as React.CSSProperties;

  if (count === 0 || !stylesReady) {
    if (count === 0 && !waiting) {
      return null;
    }

    // 받는 중(또는 Swiper CSS 를 싣는 중) — 슬라이더와 같은 비율·폭(본문 폭이면 본문 상자, 화면 전체면 끝까지)의 자리를 먼저 잡는다.
    // 배너를 이미 받았으면 그 위치 설정의 폭을 따른다. 장식이라 읽지 않는다
    const wide = count > 0 ? full : skeletonFull;
    const skeleton = (
      <section
        className={`hs-slider hs-slider--skeleton${wide ? ' hs-slider--full' : ''}${deviceClass}${overlayOn ? ' hs-slider--header-overlay' : ''} ${className}`.trim()}
        style={style}
        aria-hidden="true"
        data-position={position}
      >
        <div className={`hs-slider__text${wide && box ? ' hs-slider__text--boxed' : ''}`}>
          {wrapBox(wide ? box : '', (
            <div className="hs-slider__skeleton-lines">
              <span className="hs-slider__skeleton-line hs-slider__skeleton-line--title" />
              <span className="hs-slider__skeleton-line hs-slider__skeleton-line--description" />
              <span className="hs-slider__skeleton-line hs-slider__skeleton-line--button" />
            </div>
          ))}
        </div>
      </section>
    );

    return !wide && box ? <div className={box} data-hs-width="content">{skeleton}</div> : skeleton;
  }

  const onKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      go(-1);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      go(1);
    }
  };

  const controls = (count > 1 || hasMedia) && (
        <div className={`hs-slider__controls${textBox ? ' hs-slider__controls--boxed' : ''}`}>
          {/* 장 번호는 점 표시가 보여 주므로 화면에서는 감추고, 방문자가 넘긴 때만 화면 읽기 프로그램에 알린다 */}
          {count > 1 && <span className="hs-slider__counter" aria-live={live ? 'polite' : 'off'}>{announcement}</span>}
          {(canAutoplay || hasMedia) && (
            <button
              type="button"
              className="hs-slider__control"
              data-hs-control="toggle"
              aria-pressed={paused}
              aria-label={toggleLabel}
              onClick={() => setPausedChoice(!paused)}
            >
              <span aria-hidden="true">{paused ? '▶' : '❚❚'}</span>
            </button>
          )}
        </div>
  );

  // 관리자에서 수정 — 수정 권한이 있는 회원에게만, 지금 보이는 배너의 관리자 수정 화면을 새 탭으로 연다(상품 상세의 같은 단추와 같은 동작)
  const editLabel = t('sirsoft-hero_section.slider.admin_edit', '관리자에서 수정');
  const editLink = editable && !forceDevice && activeBanner && (
    <a
      className="hs-slider__admin-edit"
      href={sitePath(`/admin/plugins/sirsoft-hero_section/banners/${activeBanner.id}/edit`)}
      target="_blank"
      rel="noopener noreferrer"
      title={editLabel}
    >
      {PENCIL}
      <span className="hs-slider__admin-edit-label">{editLabel}</span>
    </a>
  );

  const slider = (
    <section
      id={id}
      {...editorAttrs}
      ref={regionRef}
      className={`hs-slider hs-slider--${effect}${full ? ' hs-slider--full' : ''} hs-slider--pagination-${pagination}${framed ? ' hs-slider--static' : ''}${deviceClass}${count > 1 ? ' hs-slider--controlled' : ''}${autoplaying ? ' hs-slider--autoplaying' : ''}${overlayOn ? ' hs-slider--header-overlay' : ''} ${className}`.trim()}
      style={style}
      role="region"
      aria-roledescription={t('sirsoft-hero_section.slider.carousel_role', '캐러셀')}
      aria-label={t('sirsoft-hero_section.slider.label', '메인 배너')}
      data-position={position}
      // 휴대폰 탭은 흉내 mouseenter 만 오고 mouseleave 가 오지 않아 hover 로 보지 않는다 — 마우스·펜 포인터만 센다
      onPointerEnter={(event) => event.pointerType !== 'touch' && setHovered(true)}
      onPointerLeave={(event) => event.pointerType !== 'touch' && setHovered(false)}
      onPointerDownCapture={() => {
        pointerInput.current = true;
        setFocusWithin(false);
      }}
      onFocus={() => !pointerInput.current && setFocusWithin(true)}
      onBlur={(event) => {
        if (!regionRef.current?.contains(event.relatedTarget as Node | null)) {
          setFocusWithin(false);
        }
      }}
      onKeyDown={count > 1 ? onKeyDown : undefined}
    >
      <Swiper
        key={`${key}:${effect}`}
        className="hs-slider__track"
        // 장 줄에 자기 클래스를 더한다(Swiper 는 swiper-wrapper 도 그대로 붙인다) — 미리보기 정적 모드가 이 줄을 옮긴다
        wrapperClass="hs-slider__wrapper"
        modules={[Autoplay, EffectFade, EffectCube, EffectFlip, EffectCoverflow, EffectCreative]}
        loop={count > 1}
        {...EFFECTS[effect]}
        allowTouchMove={count > 1}
        simulateTouch={count > 1}
        grabCursor={count > 1}
        autoplay={canAutoplay ? { delay: interval, disableOnInteraction: false } : false}
        onSwiper={setSwiper}
        onRealIndexChange={(instance: SwiperInstance) => setIndex(instance.realIndex)}
        // 진행 막대 — 자동 넘김까지 지난 비율(0~1)을 CSS 변수로만 흘린다(매 프레임 다시 그리지 않게)
        onAutoplayTimeLeft={(_instance: SwiperInstance, _left: number, remaining: number) => {
          regionRef.current?.style.setProperty('--hs-progress', String(Math.min(1, Math.max(0, 1 - remaining))));
        }}
      >
        {banners.map((banner, i) => (
          <SwiperSlide key={banner.id}>
            <HeroSlide
              banner={banner}
              index={i}
              count={count}
              active={i === index}
              eager={i === index || i === (index + 1) % count}
              forceDevice={forceDevice}
              mobileScreen={heroScreen.mobile}
              box={sliderBox ?? null}
              boxPending={sliderBox === undefined}
              textBox={textBox}
              playing={i === index && !paused && mediaRuns(banner)}
              loop={count === 1}
              onMediaEnded={() => onMediaEnded(banner)}
              onMediaFailed={() => onMediaFailed(banner)}
              reduced={reduced}
              replay={replay}
              onTextMove={onTextMove && forceDevice ? (spot) => onTextMove(spot, forceDevice) : undefined}
            />
          </SwiperSlide>
        ))}
      </Swiper>

      {count > 1 && (
        <>
          {/* Swiper 기본 화살표·점 모양(navigation·pagination CSS 선언을 손 CSS 로 옮겨 자기 클래스에 둠)을 쓰되, 단추는 이름 붙은
              button 으로 직접 그린다 — Swiper 모듈은 요소를 바깥 문서에서 찾아 편집 미리보기(iframe)에서는 붙지 않는다 */}
          <button type="button" className="hs-slider__arrow hs-slider__prev" aria-label={t('sirsoft-hero_section.slider.previous', '이전 배너')} onClick={() => go(-1)}>{ARROW}</button>
          <button type="button" className="hs-slider__arrow hs-slider__next" aria-label={t('sirsoft-hero_section.slider.next', '다음 배너')} onClick={() => go(1)}>{ARROW}</button>
          {pagination === 'fraction' ? (
            // 번호 — 장 번호는 슬라이드 이름이 읽어 주므로 화면 읽기에서는 감춘다
            <div className="hs-slider__pagination hs-slider__fraction" aria-hidden="true">
              <span className="hs-slider__fraction-current">{twoDigits(index + 1)}</span>
              <span>/</span>
              <span data-hs-fraction="total">{twoDigits(count)}</span>
            </div>
          ) : pagination === 'tabs' ? (
            // 배너 이름 탭 — 이름은 보이는 글자(휴대폰에서는 화면 읽기용으로만 남김)가 단추 이름이다
            <div className="hs-slider__pagination hs-slider__tabs">
              {banners.map((banner, i) => (
                <button
                  key={banner.id}
                  type="button"
                  className={`hs-slider__tab${i === index ? ' hs-slider__tab--active' : ''}`}
                  aria-current={i === index ? 'true' : undefined}
                  onClick={() => goTo(i)}
                >
                  <span className="hs-slider__tab-track" aria-hidden="true">{i === index && <span className="hs-slider__bullet-fill" />}</span>
                  <span className="hs-slider__tab-label">{banner.title || banner.image_alt || t('sirsoft-hero_section.slider.go_to', '{{n}}번째 배너로 이동', { n: String(i + 1) })}</span>
                </button>
              ))}
            </div>
          ) : (
            <div className={`hs-slider__pagination hs-slider__bullets hs-slider__pagination--${pagination}`}>
              {banners.map((banner, i) => (
                <button
                  key={banner.id}
                  type="button"
                  className={`hs-slider__bullet${i === index ? ' hs-slider__bullet--active' : ''}`}
                  aria-label={t('sirsoft-hero_section.slider.go_to', '{{n}}번째 배너로 이동', { n: String(i + 1) })}
                  aria-current={i === index ? 'true' : undefined}
                  onClick={() => goTo(i)}
                >
                  {pagination === 'progress' && i === index && <span className="hs-slider__bullet-fill" aria-hidden="true" />}
                </button>
              ))}
            </div>
          )}
        </>
      )}

      {controls && (textBox
        // 화면 전체 폭 — 단추를 본문 상자 오른쪽 끝에 맞춘다. 층은 클릭을 막지 않고 단추만 받는다
        ? <div className="hs-slider__controls-layer"><div className={`hs-slider__controls-box ${textBox}`}>{controls}</div></div>
        : controls)}

      {editLink && (
        // 오른쪽 위 — 화면 전체 폭이면 본문 상자 오른쪽 끝에 맞춘다. 층은 클릭을 막지 않고 링크만 받는다
        <div className="hs-slider__edit-layer">
          <div className={`hs-slider__edit-box ${textBox || 'hs-slider__edit-box--inset'}`}>{editLink}</div>
        </div>
      )}
    </section>
  );

  // 본문 폭 — 템플릿 본문 상자 안에 그린다(상자를 안 넘겼으면 감싸지 않고 자리 폭을 그대로 쓴다)
  return !full && box ? <div className={box} data-hs-width="content">{slider}</div> : slider;
};

interface HeroSlideProps {
  banner: HeroBanner;
  index: number;
  count: number;
  active: boolean;
  eager: boolean;
  forceDevice?: 'pc' | 'mobile';

  /** 휴대폰 화면인지 — 슬라이더의 기기 판정(`useHeroScreen`: 기기 고정 → 엔진 반응형 폭 → 창 폭). 주 이미지를 이 판정으로 고른다 */
  mobileScreen?: boolean;
  /** 화면 전체 폭일 때 문구를 맞출 본문 상자 클래스 (비우면 감싸지 않는다) */
  textBox?: string;

  /** 슬라이더 상자의 잰 크기 — 이미지 sizes 를 정한다. 없으면(잴 수 없음) 화면 폭 상한(100vw)을 준다 */
  box?: SliderBox | null;

  /** 상자를 아직 재기 전인지 — 그동안은 이미지 주소를 붙이지 않는다(곧 같은 작업 안에서 잰 값으로 다시 그린다) */
  boxPending?: boolean;

  /** 영상을 지금 재생할지 */
  playing?: boolean;

  /** 한 장뿐이라 영상을 반복할지 */
  loop?: boolean;

  /** 영상이 끝났다 */
  onMediaEnded?: () => void;

  /** 영상을 재생할 수 없다 */
  onMediaFailed?: () => void;

  /** 움직임 줄이기 — 문구 등장 효과를 끈다 */
  reduced?: boolean;

  /** 값이 바뀌면 문구 등장 효과를 다시 재생한다(편집 미리보기) */
  replay?: number;

  /** 문구 상자를 옮겼다(편집 미리보기 — 고정 기기의 새 자리) */
  onTextMove?: (spot: TextSpot) => void;
}

/** 아무것도 하지 않는 콜백 */
const noop = () => {};

/** 위치별 직전 모양 — 받는 동안의 스켈레톤이 쓴다 */
interface SlotMemory {
  /** 직전에 받은 배너 수 */
  banners: number;

  /** 직전 표시 폭 */
  width: 'content' | 'full';

  /** 직전에 메뉴를 겹쳤는지 */
  overlay?: boolean;

  /** 직전 첫 장의 바탕 밝기 */
  tone?: 'dark' | 'light';
}

/** 저장 키 접두 — 플러그인 식별자로 다른 확장과 섞이지 않게 */
const MEMORY_KEY = `${PLUGIN}:slot:`;

/**
 * 기억 자리 이름 — 정적 위치는 위치 키 그대로(옛 기억과 같은 키), 동적 위치는 `{위치}:{대상}`.
 *
 * @param position 위치 키
 * @param target 동적 위치의 대상 (정적 위치는 undefined)
 * @returns 기억 자리 이름 (위치가 없거나 동적 위치의 대상이 비었으면 undefined — 기억하지 않는다)
 */
function memorySlot(position?: string, target?: string): string | undefined {
  if (!position) {
    return undefined;
  }
  if (target === undefined) {
    return position;
  }

  return target === '' ? undefined : `${position}:${target}`;
}

/**
 * 자리의 직전 모양을 읽습니다 — 저장소를 쓸 수 없거나(개인정보 동의 전 차단·사생활 모드) 값이 없으면 null.
 *
 * @param slot 기억 자리 이름 ({@link memorySlot})
 * @returns 직전 모양
 */
function readMemory(slot?: string): SlotMemory | null {
  if (!slot || typeof window === 'undefined') {
    return null;
  }
  try {
    const value = JSON.parse(window.localStorage.getItem(MEMORY_KEY + slot) ?? 'null');
    return value && typeof value.banners === 'number'
      ? { banners: value.banners, width: value.width === 'full' ? 'full' : 'content', overlay: value.overlay === true, tone: value.tone === 'light' ? 'light' : 'dark' }
      : null;
  } catch {
    return null;
  }
}

/**
 * 자리의 지금 모양을 기억합니다 — 저장할 수 없으면 조용히 넘긴다(다음 방문은 기본 스켈레톤).
 *
 * @param slot 기억 자리 이름 ({@link memorySlot})
 * @param memory 지금 모양
 */
function writeMemory(slot: string | undefined, memory: SlotMemory): void {
  if (!slot || typeof window === 'undefined') {
    return;
  }
  try {
    window.localStorage.setItem(MEMORY_KEY + slot, JSON.stringify(memory));
  } catch {
    // 저장소 차단·용량 초과 — 기억 없이도 동작한다
  }
}

/**
 * 배너의 바탕 밝기를 꺼냅니다(기본 어두움 — 흰 메뉴 글자).
 *
 * @param banner 배너
 * @returns 바탕 밝기
 */
function toneOf(banner?: HeroBanner): 'dark' | 'light' {
  return banner?.presentation?.tone === 'light' ? 'light' : 'dark';
}

/**
 * 배너를 받기 전의 바탕 밝기 — 스켈레톤 색이 사이트 다크 모드를 따르므로 그에 맞춘다(다크면 어두움, 아니면 밝음).
 *
 * @returns 바탕 밝기
 */
function siteTone(): 'dark' | 'light' {
  return typeof document !== 'undefined' && document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/**
 * 화면 전체 폭에서 문구를 본문 상자로 감쌉니다 — 상자가 없으면 그대로 둔다.
 *
 * @param box 본문 상자 클래스
 * @param node 감쌀 노드
 * @returns 감싼 노드
 */
function wrapBox(box: string, node: React.ReactNode): React.ReactNode {
  return box ? <div className={`hs-slider__box ${box}`}>{node}</div> : node;
}

/**
 * 슬라이드 한 장 — 이미지·문구·링크.
 *
 * @param props 슬라이드 속성
 * @returns 슬라이드
 */
const HeroSlide: React.FC<HeroSlideProps> = ({
  banner, index, count, active, eager, forceDevice, mobileScreen = forceDevice === 'mobile', box = null, boxPending = false, textBox = '',
  playing = false, loop = false, onMediaEnded = noop, onMediaFailed = noop,
  reduced = false, replay = 0, onTextMove,
}) => {
  const pc = banner.images?.pc ?? null;
  const mobile = banner.images?.mobile ?? null;
  const link = linkProps(banner);
  const hasButton = !!banner.button_text && !!link;
  // 버튼 문구가 없으면 슬라이드 전체가 링크다 — 링크는 슬라이드를 덮는 빈 형제로 두고 문구는 그 위(링크 밖)에 둔다.
  // 문구를 링크 안에 넣으면 넘칠 때 탭으로 들어오는 문구 영역이 링크 안에 겹친다(중첩 상호작용)
  const wholeLink = !!link && !hasButton;
  const linkRef = useRef<HTMLAnchorElement>(null);
  const alt = banner.image_alt ?? banner.title ?? '';
  const linkName = banner.title || alt;
  // 제목은 한 번만 읽힌다 — 전체 링크면 링크 이름이 제목이라 보이는 제목 줄은 화면 읽기에서 감추고, 이미지 대체 문구가 제목·링크 이름과
  // 같으면(대체 문구를 비우면 공개 응답이 제목으로 채운다) 장식 이미지로 둔다. 이전에는 링크·이미지·제목 줄로 세 번 읽혔다
  const titleShown = banner.title_visible !== false && !!banner.title;
  const titleSpokenByLink = wholeLink && titleShown && linkName === banner.title;
  const imageAlt = alt !== '' && ((titleShown && alt === banner.title) || (wholeLink && alt === linkName)) ? '' : alt;
  const focus = banner.presentation;
  const scrollRef = useRef<HTMLDivElement>(null);
  const [overflowing, setOverflowing] = useState(false);

  // 문구가 영역 높이를 넘을 때만 키보드로 들어가 스크롤해 읽게 한다 — 화면 폭·글꼴·문구가 바뀌면 다시 잰다
  useEffect(() => {
    const element = scrollRef.current;
    if (!element) {
      return;
    }
    const measure = () => setOverflowing(textOverflows(element));
    measure();
    if (typeof ResizeObserver === 'undefined') {
      return;
    }
    const observer = new ResizeObserver(measure);
    observer.observe(element);
    Array.from(element.children).forEach((child) => observer.observe(child));
    return () => observer.disconnect();
  }, [banner.title, banner.eyebrow, banner.description, banner.button_text, banner.title_visible, banner.eyebrow_visible, banner.description_visible, banner.presentation?.text_width]);
  const scrollable = overflowing
    ? { tabIndex: active ? 0 : -1, role: 'region', 'aria-label': t('sirsoft-hero_section.slider.text_label', '배너 문구') }
    : {};
  const point = (device: 'pc' | 'mobile') => `${focus?.[`${device}_x`] ?? 50}% ${focus?.[`${device}_y`] ?? 50}%`;
  const imageStyle = { '--hs-focus-pc': point(forceDevice ?? 'pc'), '--hs-focus-mobile': point(forceDevice ?? 'mobile') } as React.CSSProperties;

  // 주 이미지는 기기 판정으로 고른다 — 창 폭 `<source media>` 에 맡기면 편집기 미리보기(엔진 폭)가 좁아도 창이 넓으면
  // 모바일 비율 영역에 PC 이미지가 들어간다. 실제 브라우저에서는 엔진 폭이 창 폭이라 경계(767px)와 결과가 같다.
  const main = mobileScreen ? (mobile ?? pc) : pc;
  const media = mediaOf(banner);
  // PC·모바일 이미지는 기기별로 따로 올린 각자의 원본이라 srcset 도 고른 이미지의 가로로 만든다(코어 썸네일 `?size=`, 대상이 아니면 없음)
  // 축소 후보는 공개 API 주소(`?size=`), 마지막 원본 후보와 src 는 원본 직접 주소(있으면 — 공개 자산 디스크, #878)
  const mainSrc = main ? mediaSrc(main) : undefined;
  const mainSrcSet = main ? responsiveSrcset(main.url, main.width, mainSrc) : undefined;
  // srcset 이 있으면 상자를 잰 뒤에만 주소를 붙인다 — 재기 전 sizes(100vw)로 큰 후보를 받기 시작했다가 잰 값으로 다시 받지 않게
  const measuring = !!mainSrcSet && boxPending;
  const image = main ? (
    <picture className="hs-slider__picture">
      <img
        className="hs-slider__image"
        style={imageStyle}
        // 속성 순서 sizes → srcSet → src 를 지킨다 — React 는 처음 만들 때만 srcSet·src 를 맨 뒤에 붙이고, 갱신(잰 뒤 주소를 붙일 때,
        // 기기가 바뀔 때)은 이 순서대로 붙인다. WebKit 은 srcset 이 sizes 보다 먼저 붙으면 기본 100vw 로 원본을 한 번 더 받는다
        sizes={mainSrcSet && !measuring ? sliderImageSizes(box, main) : undefined}
        srcSet={measuring ? undefined : mainSrcSet}
        src={measuring ? undefined : mainSrc}
        alt={imageAlt}
        width={main.width ?? undefined}
        height={main.height ?? undefined}
        loading={eager ? 'eager' : 'lazy'}
        draggable={false}
        {...(index === 0 ? { fetchPriority: 'high' } : {})}
        decoding="async"
      />
    </picture>
  ) : null;

  // 문구 줄 — 머리글 → 제목 → 설명 → 버튼. 등장 효과는 그려지는 줄만 세어 0.2초씩 늦춘다(빈 줄 때문에 틈이 생기지 않게)
  const motion = textMotion(focus, reduced);
  const device = forceDevice ?? 'pc';
  const drag = useTextDrag(textSpot(focus, device), onTextMove);
  const lines: React.ReactNode[] = [];
  const line = (render: (props: { className: string; style: React.CSSProperties }) => React.ReactNode, extra = '') => {
    lines.push(render({ className: `hs-slider__line${extra}`, style: lineDelay(motion, lines.length) }));
  };
  const letters = motion.name === 'letters';
  if (banner.eyebrow_visible !== false && banner.eyebrow) {
    line(({ className, style }) => (
      <p key="eyebrow" className={`hs-slider__eyebrow ${className}`} style={{ ...style, color: banner.eyebrow_color, textAlign: banner.title_align }}>
        {letters ? <LetterText text={banner.eyebrow!} /> : banner.eyebrow}
      </p>
    ), letters ? ' hs-slider__line--letters' : '');
  }
  if (titleShown) {
    line(({ className, style }) => (
      <h2 key="title" className={`hs-slider__title ${className}`} style={{ ...style, color: banner.title_color, textAlign: banner.title_align }} aria-hidden={titleSpokenByLink || undefined}>
        {letters ? <LetterText text={banner.title!} /> : banner.title}
      </h2>
    ), letters ? ' hs-slider__line--letters' : '');
  }
  if (banner.description_visible !== false && banner.description) {
    line(({ className, style }) => (
      <p key="description" className={`hs-slider__description ${className}`} style={{ ...style, color: banner.description_color, textAlign: banner.description_align }}>{banner.description}</p>
    ));
  }
  if (hasButton) {
    line(({ className, style }) => (
      <div key="actions" className={className} data-hs-line="actions" style={{ ...style, textAlign: banner.title_align }}>
        <a className="hs-slider__button" {...link!} tabIndex={active ? undefined : -1}>{banner.button_text}</a>
      </div>
    ));
  }
  const editable = !!onTextMove;
  const shown = drag.dragging ?? textSpot(focus, device);

  const text = (
    <div
      className={`hs-slider__text${overlayClass(banner.presentation?.overlay)}${textBox ? ' hs-slider__text--boxed' : ''}`}
      style={textVars(focus, forceDevice, drag.dragging)}
    >
      {wrapBox(textBox, (
        <div
          key={`motion-${replay}`}
          ref={drag.boxRef}
          className={`hs-slider__textbox hs-slider__textbox--${motion.name}${wholeLink ? ' hs-slider__text--linked' : ''}${editable ? ' hs-slider__textbox--editable' : ''}${drag.dragging ? ' hs-slider__textbox--dragging' : ''}`}
          style={{ '--hs-anim-duration': `${motion.duration}s` } as React.CSSProperties}
          // 문구를 눌러도 링크로 간다(마우스·손가락). 키보드는 앞의 링크로 간다. 손가락 스크롤·넘기기는 click 을 만들지 않는다
          onClick={wholeLink && !editable ? () => linkRef.current?.click() : undefined}
          {...(editable ? {
            tabIndex: 0,
            role: 'group',
            'aria-roledescription': t('sirsoft-hero_section.admin.form.text_layout.drag_role', '옮길 수 있는 문구'),
            'aria-label': t('sirsoft-hero_section.admin.form.text_layout.drag_label', '문구 위치 가로 {{x}}%, 세로 {{y}}% — 끌거나 화살표 키로 옮깁니다', { x: String(shown.x), y: String(shown.y) }),
            ...drag.handlers,
          } : {})}
        >
          {/* 긴 문구는 이 안에서만 스크롤한다 — 바깥 여백(넘김 컨트롤 자리)에는 글자가 지나가지 않는다 */}
          <div className={`hs-slider__scroll${overflowing ? ' hs-slider__scroll--overflow' : ''}`} ref={scrollRef} {...(editable ? {} : scrollable)}>{lines}</div>
        </div>
      ))}
    </div>
  );

  return (
    <div
      className={`hs-slider__slide${active ? ' hs-slider__slide--active' : ''}${media ? ` hs-slider__slide--${media}` : ''}`}
      role="group"
      aria-roledescription={t('sirsoft-hero_section.slider.slide_role', '슬라이드')}
      aria-label={slideLabel(index, count)}
      aria-hidden={active ? undefined : true}
      data-banner-id={banner.id}
      data-index={index}
    >
      {wholeLink && (
        <a ref={linkRef} className="hs-slider__link" {...link!} aria-label={linkName} tabIndex={active ? undefined : -1} />
      )}
      {image}
      {media === 'video' && (
        <HeroVideoMedia
          sources={banner.videos ?? {}}
          forceDevice={forceDevice}
          posters={forceDevice ? { pc: mediaSrc(main), mobile: mediaSrc(main) } : { pc: mediaSrc(pc), mobile: mediaSrc(mobile) }}
          playing={playing}
          active={active}
          loop={loop}
          eager={eager}
          reduced={reduced}
          onEnded={onMediaEnded}
          onFailed={onMediaFailed}
        />
      )}
      {text}
    </div>
  );
};

export default HeroSlider;
