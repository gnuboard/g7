import React, { useEffect, useRef, useState, useSyncExternalStore } from 'react';

/** 공개 API 의 영상 파일 하나 */
export interface HeroVideo {
  /** 공개 영상 주소 (범위 요청을 받는다) */
  url: string;

  /** 영상 직접 주소 (공개 자산 디스크 행만, 아니면 null) — 있으면 이 주소로 재생해 범위 요청을 웹서버·CDN 이 받는다 (#878) */
  direct_url?: string | null;

  /** MIME (video/mp4·video/webm) */
  mime?: string | null;
}

/** 슬라이드 미디어가 슬라이더에 알리는 상태 — 재생이 시작됐는지, 끝났는지, 재생할 수 없는지 */
export interface MediaCallbacks {
  /** 끝까지 재생했다 */
  onEnded: () => void;

  /** 재생할 수 없다(파일 오류) — 슬라이더는 이 장을 이미지처럼 간격으로 넘긴다 */
  onFailed: () => void;
}

/**
 * 기기 판정 경계(px, 이하) — 엔진 반응형 프리셋 mobile(0~767)·portable(0~1023)과 같은 값이다(support/editor.ts 의 프리셋 표,
 * 시험이 같은지 잠근다). 슬라이더의 주 이미지(PC·모바일)도 이 판정으로 고른다.
 */
export const SCREEN_MAX_WIDTH = { mobile: 767, portable: 1023 } as const;

/** 미리보기 기기 고정 값 */
export type HeroForcedDevice = 'pc' | 'mobile';

/** 기기 판정 — 휴대폰(0~767px)·휴대 기기(0~1023px, 휴대폰 포함) */
export interface HeroScreen {
  mobile: boolean;
  portable: boolean;
}

/**
 * 화면 폭을 지켜봅니다 — 엔진 반응형 폭(`G7Core.useResponsive()`, 레이아웃 편집기 미리보기에서는 기기 폭)이 먼저고, 훅이 없을 때만
 * 창 폭(`window.innerWidth`, 창 폭이 바뀌면 다시 읽는다). `matchMedia` 는 편집기 미리보기에서도 창 폭을 보고 소수 배율 화면의 경계
 * 폭에서 1px 어긋나 쓰지 않는다(docs/frontend/responsive-layout.md 「레이아웃 편집기 미리보기에서의 화면 폭」).
 *
 * @returns 화면 폭(px)
 */
export function useScreenWidth(): number {
  const useResponsive = (window as { G7Core?: { useResponsive?: () => { width?: number } | null } }).G7Core?.useResponsive;
  // G7Core 는 페이지가 뜰 때 한 번 정해진다 — 렌더마다 훅 호출 여부가 바뀌지 않는다
  const responsive = useResponsive?.();
  const engineWidth = typeof responsive?.width === 'number' ? responsive.width : null;
  const [windowWidth, setWindowWidth] = useState(() => (typeof window !== 'undefined' ? window.innerWidth : SCREEN_MAX_WIDTH.portable + 1));

  useEffect(() => {
    if (engineWidth !== null || typeof window === 'undefined') {
      return undefined;
    }
    const onResize = () => setWindowWidth(window.innerWidth);
    onResize();
    window.addEventListener('resize', onResize);

    return () => window.removeEventListener('resize', onResize);
  }, [engineWidth !== null]);

  return engineWidth ?? windowWidth;
}

/**
 * 기기를 판정합니다 — 미리보기 기기 고정이 있으면 그 기기(PC 고정은 휴대 기기도 아니다 — PC 미리보기 iframe 1366px 와 같다), 없으면 폭.
 *
 * @param width 화면 폭(px)
 * @param forceDevice 미리보기 기기 고정
 * @returns 기기 판정
 */
export function screenOf(width: number, forceDevice?: HeroForcedDevice): HeroScreen {
  if (forceDevice) {
    const mobile = forceDevice === 'mobile';
    return { mobile, portable: mobile };
  }

  return { mobile: width <= SCREEN_MAX_WIDTH.mobile, portable: width <= SCREEN_MAX_WIDTH.portable };
}

/**
 * 지금 기기를 구독합니다 — 화면을 돌리거나 창 폭(편집기 미리보기 기기)을 바꾸면 슬라이더 모양·영상 파일도 그 기기 것으로 바꾼다.
 *
 * @param forceDevice 미리보기 기기 고정
 * @returns 기기 판정
 */
export function useHeroScreen(forceDevice?: HeroForcedDevice): HeroScreen {
  return screenOf(useScreenWidth(), forceDevice);
}

interface HeroVideoMediaProps extends MediaCallbacks {
  /** PC·모바일 영상 */
  sources: { pc?: HeroVideo | null; mobile?: HeroVideo | null };

  /** 미리보기 기기 고정 */
  forceDevice?: 'pc' | 'mobile';

  /** 기기별 대표 이미지 주소 — 첫 프레임을 받기 전에 보인다. 아래 그림 요소와 같은 기기 것을 써서 이미지를 따로 더 받지 않는다 */
  posters?: { pc?: string; mobile?: string };

  /** 지금 재생할지 (활성 장이고 일시정지가 아님) */
  playing: boolean;

  /** 활성 장인지 — 일시정지는 그 자리에 멈추고, 활성 장이 아니게 되면 처음으로 돌린다 */
  active: boolean;

  /** 배너가 한 장뿐이면 반복 재생 */
  loop: boolean;

  /** 먼저 받아 둘지 (지금 장·다음 장) */
  eager: boolean;

  /** 영상 주소를 붙일지 — 아직 차례가 아닌 장은 `<video>` 자리만 두고 주소를 붙이지 않는다(기본 붙임) */
  load?: boolean;

  /** 움직임 줄이기 — 재생하기 전까지 영상을 받지 않는다 */
  reduced?: boolean;
}

/**
 * 영상을 미리 얼마나 받을지 정합니다 — 재생 차례인 장만 다 받고, 다음 장은 길이·첫 프레임 정보만, 그 뒤 장은 받지 않는다.
 * 움직임 줄이기·데이터 절약 방문자는 재생하기 전까지 받지 않는다(큰 영상이 재생되지 않는데도 내려받히지 않게).
 *
 * @param playing 지금 재생할지
 * @param eager 먼저 받아 둘 장인지 (지금 장·다음 장)
 * @param reduced 움직임 줄이기인지
 * @returns video preload 값
 */
function videoPreload(playing: boolean, eager: boolean, reduced: boolean): 'auto' | 'metadata' | 'none' {
  if (playing) {
    return 'auto';
  }

  const saveData = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection?.saveData === true;

  return eager && !reduced && !saveData ? 'metadata' : 'none';
}

/**
 * 직접 올린 영상 — 소리 없이·화면 안에서(playsInline) 재생한다. 일시정지는 그 자리에 멈추고, 활성 장이 아니면 멈추고 처음으로 돌린다.
 * 영상은 장식이라 화면 읽기에서 감추고(대체 텍스트는 아래 대표 이미지가 맡는다), 끝나면 슬라이더에 알린다.
 *
 * @param props 영상 속성
 * @returns 영상 요소
 */
export const HeroVideoMedia: React.FC<HeroVideoMediaProps> = ({ sources, forceDevice, posters, playing, active, loop, eager, load = true, reduced = false, onEnded, onFailed }) => {
  const ref = useRef<HTMLVideoElement>(null);
  const { mobile } = useHeroScreen(forceDevice);
  const source = (mobile ? sources.mobile ?? sources.pc : sources.pc) ?? null;
  // 차례가 아닌 장은 preload="none" 이어도 브라우저에 따라 주소만으로 요청이 나가므로 주소 자체를 붙이지 않는다
  const src = source && load ? (typeof source.direct_url === 'string' && source.direct_url !== '' ? source.direct_url : source.url) : undefined;
  // 대표 이미지는 지금·다음 장만 단다 — poster 는 lazy 그림과 달리 바로 받으므로 먼 장의 이미지까지 첫 화면에 받게 된다
  const poster = eager || active ? (mobile ? posters?.mobile ?? posters?.pc : posters?.pc) : undefined;

  useEffect(() => {
    const video = ref.current;
    if (!video || !source) {
      return;
    }
    if (playing) {
      // 자동 재생이 막히면(절전 모드 등) 대표 이미지·첫 프레임이 남고 간격 넘김으로 돌린다. 시작 전에 멈추거나 넘기거나 화면을 돌려
      // 재생 요청이 취소된 것(AbortError)은 실패가 아니다 — 실패로 보면 그 배너는 방문 내내 다시 재생되지 않는다
      const started = video.play();
      if (started && typeof started.catch === 'function') {
        started.catch((error: unknown) => {
          if ((error as { name?: string } | null)?.name !== 'AbortError') {
            onFailed();
          }
        });
      }
    } else {
      video.pause();
      if (!active && video.currentTime > 0 && !video.ended) {
        video.currentTime = 0;
      }
    }
  }, [playing, active, src]);

  if (!source) {
    return null;
  }

  return (
    <video
      ref={ref}
      key={src}
      className="hs-slider__video"
      src={src}
      poster={poster}
      muted
      playsInline
      loop={loop}
      preload={videoPreload(playing, eager, reduced)}
      aria-hidden="true"
      tabIndex={-1}
      disablePictureInPicture
      onEnded={onEnded}
      onError={onFailed}
    />
  );
};
