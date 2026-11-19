import React, { useEffect, useRef, useState } from 'react';

/**
 * 배너 문구 배치·등장 효과·메뉴 겹침 신호 — 사용자 슬라이더(HeroSlider)와 편집 미리보기가 함께 쓴다.
 *
 * 문구 띠(`.hs-slider__text`, 문구 배경 포함)는 슬라이드 안에서 세로 위치(y%)로 올리고 내린다. 띠 안의 문구 상자(`.hs-slider__textbox`)는
 * 폭(w%)과 가로 위치(x%)로 놓는다. 위치 0 은 왼쪽·위 끝, 100 은 오른쪽·아래 끝, 50 은 가운데라 상자가 배너 밖으로 나가지 않는다.
 * 기본값(x 0 · y 100 · 폭 100)은 종전의 「아래쪽 전폭 문구」와 같은 화면이다.
 */

/** 기기 */
export type HeroDevice = 'pc' | 'mobile';

/** 문구 등장 효과 — 서버 Enum `TextAnimation` 과 같다 */
export type HeroTextAnimation = 'none' | 'rise' | 'fade' | 'left' | 'right' | 'zoom' | 'blur' | 'letters' | 'sequence';

/** 등장 효과 한 줄의 재생 시간(초) — 서버 Enum `AnimationSpeed` */
export const ANIMATION_SECONDS: Record<string, number> = { slow: 1, normal: 0.6, fast: 0.35 };

/** 줄마다 늦추는 시간(초) — 머리글 → 제목 → 설명 → 버튼 */
export const LINE_STAGGER = 0.2;

/** 등장 효과 목록(없음 제외) */
const ANIMATIONS: readonly HeroTextAnimation[] = ['rise', 'fade', 'left', 'right', 'zoom', 'blur', 'letters', 'sequence'];

/**
 * 「순차 등장」(sequence) — 머리글 → 제목 → 설명이 0.3초 간격으로 짧게(보통 0.4초) 아래에서 올라온다. 다른 효과보다 짧고 간격이 넓어
 * 묶음 단위로 차례차례 보인다. 속도·시작 지연은 다른 효과와 같이 조절한다.
 */
export const SEQUENCE_SECONDS: Record<string, number> = { slow: 0.6, normal: 0.4, fast: 0.25 };

/** 「순차 등장」 줄 간격(초) */
export const SEQUENCE_STAGGER = 0.3;

/** 그림(미디어) 등장 효과 — 서버 Enum `MediaAnimation` 과 같다 */
export type HeroMediaAnimation = 'none' | 'fade' | 'right';

/** 그림 등장 효과 재생 시간(초) — 문구보다 느리게(보통 0.8초) */
export const MEDIA_SECONDS: Record<string, number> = { slow: 1.2, normal: 0.8, fast: 0.5 };

/** 표현값 중 문구에 쓰는 것 — 서버 `Banner::DEFAULT_PRESENTATION` 의 문구 키 */
export interface HeroTextPresentation {
  text_pc_x?: number;
  text_pc_y?: number;
  text_mobile_x?: number;
  text_mobile_y?: number;
  text_width?: number;
  /** 기기별 문구 폭(%) — 비우면(null) 공통 text_width */
  text_width_pc?: number | null;
  text_width_mobile?: number | null;
  animation?: HeroTextAnimation | string;
  animation_speed?: 'slow' | 'normal' | 'fast' | string;
  animation_delay?: number;
  tone?: 'dark' | 'light' | string;
}

/** 한 기기의 문구 자리 */
export interface TextSpot {
  x: number;
  y: number;
}

/**
 * 0~100 정수로 맞춥니다 — 숫자가 아니면 기본값.
 *
 * @param value 값
 * @param fallback 기본값
 * @param min 최솟값
 * @returns 맞춘 값
 */
export function percent(value: unknown, fallback: number, min = 0): number {
  const number = Number(value);
  if (value === null || value === undefined || value === '' || !Number.isFinite(number)) {
    return fallback;
  }
  return Math.min(100, Math.max(min, Math.round(number)));
}

/**
 * 기기의 문구 자리를 꺼냅니다(기본 왼쪽 아래).
 *
 * @param presentation 표현값
 * @param device 기기
 * @returns 가로·세로 위치(%)
 */
export function textSpot(presentation: HeroTextPresentation | null | undefined, device: HeroDevice): TextSpot {
  return {
    x: percent(presentation?.[`text_${device}_x`], 0),
    y: percent(presentation?.[`text_${device}_y`], 100),
  };
}

/**
 * 문구 띠·상자에 줄 CSS 변수를 만듭니다 — 미리보기는 기기를 고정하므로 두 기기 칸에 같은 값을 넣는다.
 *
 * @param presentation 표현값
 * @param forceDevice 고정 기기
 * @param override 끄는 중인 자리(편집 미리보기)
 * @returns CSS 변수
 */
export function textVars(presentation: HeroTextPresentation | null | undefined, forceDevice?: HeroDevice, override?: TextSpot | null): React.CSSProperties {
  const spot = (device: HeroDevice) => (override && (forceDevice ?? 'pc') === device ? override : textSpot(presentation, device));
  const pc = spot(forceDevice ?? 'pc');
  const mobile = spot(forceDevice ?? 'mobile');
  return {
    '--hs-tx-pc': pc.x,
    '--hs-ty-pc': pc.y,
    '--hs-tx-mobile': mobile.x,
    '--hs-ty-mobile': mobile.y,
    // 문구 배경 그라데이션은 문구가 놓인 쪽으로 짙어진다 — 아래쪽(기본)이면 종전과 같이 위에서 아래로
    '--hs-shade-pc': pc.y < 50 ? 'to top' : 'to bottom',
    '--hs-shade-mobile': mobile.y < 50 ? 'to top' : 'to bottom',
    '--hs-tw': percent(presentation?.text_width, 100, 30),
    // 기기별 문구 폭 — 비운 기기는 공통 폭(옛 배너 그대로). 미리보기의 기기 고정은 두 칸에 그 기기 값을 넣는다
    '--hs-tw-pc': textWidth(presentation, forceDevice ?? 'pc'),
    '--hs-tw-mobile': textWidth(presentation, forceDevice ?? 'mobile'),
  } as React.CSSProperties;
}

/**
 * 기기의 문구 폭(%)을 꺼냅니다 — 그 기기 값이 없으면 공통 text_width, 그것도 없으면 100.
 *
 * @param presentation 표현값
 * @param device 기기
 * @returns 30~100 정수
 */
export function textWidth(presentation: HeroTextPresentation | null | undefined, device: HeroDevice): number {
  const own = presentation?.[`text_width_${device}`];
  return percent(own === null || own === undefined ? presentation?.text_width : own, 100, 30);
}

/** 그림 등장 효과 설정 */
export interface MediaMotion {
  /** 효과 이름(없음이면 'none') */
  name: HeroMediaAnimation;
  /** 재생 시간(초) */
  duration: number;
  /** 시작 지연(초) — 문구 시작 지연과 같다 */
  delay: number;
}

/**
 * 표현값에서 그림 등장 효과를 꺼냅니다 — 모르는 값·움직임 줄이기면 없음.
 *
 * @param presentation 표현값
 * @param reduced 움직임 줄이기
 * @returns 그림 등장 효과
 */
export function mediaMotion(presentation: (HeroTextPresentation & { media_animation?: string | null }) | null | undefined, reduced: boolean): MediaMotion {
  const name = presentation?.media_animation;
  const delay = Number(presentation?.animation_delay);
  return {
    name: !reduced && (name === 'fade' || name === 'right') ? name : 'none',
    duration: MEDIA_SECONDS[String(presentation?.animation_speed)] ?? MEDIA_SECONDS.normal,
    delay: Number.isFinite(delay) ? Math.min(2, Math.max(0, Math.round(delay * 10) / 10)) : 0,
  };
}

/** 등장 효과 설정 */
export interface TextMotion {
  /** 효과 이름(없음이면 'none') */
  name: HeroTextAnimation;
  /** 한 줄 재생 시간(초) */
  duration: number;
  /** 첫 줄 시작 지연(초) */
  delay: number;
}

/**
 * 표현값에서 등장 효과를 꺼냅니다 — 모르는 값·움직임 줄이기면 없음.
 *
 * @param presentation 표현값
 * @param reduced 움직임 줄이기
 * @returns 등장 효과
 */
export function textMotion(presentation: HeroTextPresentation | null | undefined, reduced: boolean): TextMotion {
  const name = presentation?.animation as HeroTextAnimation;
  const delay = Number(presentation?.animation_delay);
  const seconds = name === 'sequence' ? SEQUENCE_SECONDS : ANIMATION_SECONDS;
  return {
    name: !reduced && ANIMATIONS.includes(name) ? name : 'none',
    duration: seconds[String(presentation?.animation_speed)] ?? seconds.normal,
    delay: Number.isFinite(delay) ? Math.min(2, Math.max(0, Math.round(delay * 10) / 10)) : 0,
  };
}

/**
 * 문구 영역(`.hs-slider__scroll`)이 실제로 넘치는지 확인합니다 — 등장 효과의 이동(transform)은 빼고 줄의 배치 높이로 잰다.
 * `scrollHeight` 는 「떠오르기」·「글자 하나씩」처럼 줄을 아래에서 올리는 효과가 재생되는 동안 밀린 만큼을 더해, 넘치지 않는 문구도
 * 그 순간에는 넘친다고 본다(스크롤 막대가 잠깐 생기고, 그때 잰 값이 굳으면 탭으로 들어가는 영역으로 남는다).
 *
 * @param element 문구 영역
 * @returns 줄 배치 높이가 영역 높이를 넘으면 true
 */
export function textOverflows(element: HTMLElement): boolean {
  if (element.scrollHeight <= element.clientHeight + 1) {
    return false;
  }
  const lines = Array.from(element.children) as HTMLElement[];
  if (lines.length === 0) {
    return false;
  }
  const first = lines[0];
  const last = lines[lines.length - 1];
  const style = element.ownerDocument.defaultView?.getComputedStyle(element);
  const padding = (parseFloat(style?.paddingTop ?? '') || 0) + (parseFloat(style?.paddingBottom ?? '') || 0);
  return last.offsetTop + last.offsetHeight - first.offsetTop + padding > element.clientHeight + 1;
}

/**
 * 줄 순번의 등장 지연 CSS 변수를 만듭니다.
 *
 * @param motion 등장 효과
 * @param line 줄 순번(0부터, 그려지는 줄만 센다)
 * @returns CSS 변수
 */
export function lineDelay(motion: TextMotion, line: number): React.CSSProperties {
  const stagger = motion.name === 'sequence' ? SEQUENCE_STAGGER : LINE_STAGGER;
  return { '--hs-line-delay': `${Math.round((motion.delay + line * stagger) * 100) / 100}s` } as React.CSSProperties;
}

/**
 * 문구를 글자로 나눕니다 — 이모지·결합 문자가 쪼개지지 않게 가능한 곳에서는 글자 단위 분할기를 쓴다.
 *
 * @param text 문구
 * @returns 글자 목록
 */
function graphemes(text: string): string[] {
  const Segmenter = (Intl as unknown as { Segmenter?: new (locale?: string, options?: { granularity: string }) => { segment: (input: string) => Iterable<{ segment: string }> } }).Segmenter;
  return Segmenter ? Array.from(new Segmenter(undefined, { granularity: 'grapheme' }).segment(text), (part) => part.segment) : Array.from(text);
}

/**
 * 「글자 하나씩」 효과의 문구 — 화면 읽기 프로그램에는 원문을 한 번만 읽히고(숨김 원문), 보이는 글자는 읽지 않는다. 낱말은 줄바꿈되지
 * 않게 묶어 글자 사이에서 줄이 끊기지 않는다. 전체가 1.2초 안에 끝나도록 글자 간격을 줄인다(최대 0.05초).
 *
 * @param props 문구
 * @returns 글자 묶음
 */
export const LetterText: React.FC<{ text: string }> = ({ text }) => {
  const letters = graphemes(text);
  const step = Math.min(0.05, 1.2 / Math.max(1, letters.length));
  let index = 0;
  const words = text.split(/(\s+)/);
  return (
    <>
      <span className="hs-slider__sr">{text}</span>
      <span aria-hidden="true" style={{ '--hs-letter-step': `${Math.round(step * 1000) / 1000}s` } as React.CSSProperties}>
        {words.map((word, w) => (/^\s+$/.test(word) || word === ''
          ? word
          : (
            <span key={w} className="hs-slider__word">
              {graphemes(word).map((letter, l) => (
                <span key={l} className="hs-slider__letter" style={{ '--hs-letter': index++ } as React.CSSProperties}>{letter}</span>
              ))}
            </span>
          )))}
      </span>
    </>
  );
};

/** 지금 메뉴 겹침 신호를 낸 슬라이더 — 둘 이상이 겹쳐 그려져도 나중에 낸 것만 지운다 */
let overlayOwner: symbol | null = null;

/** 문서 뿌리에 다는 메뉴 겹침 신호 속성 — 템플릿 헤더가 읽는다(값: 배너 바탕 밝기 dark|light) */
export const HEADER_OVERLAY_ATTRIBUTE = 'data-header-overlay';

/**
 * 사이트 상단 메뉴에 「배너가 메뉴 밑으로 들어간다」고 알립니다 — `<html data-header-overlay="dark|light">`. 값은 지금 장의 바탕
 * 밝기이고, 장이 바뀌면 바뀐다. 슬라이더가 사라지거나 겹침을 끄면 속성을 지운다. 이 신호를 읽는 템플릿(sirsoft-basic)은 메뉴를
 * 투명하게 해 배너 위에 올리고, 읽지 않는 템플릿은 화면이 그대로다.
 *
 * @param tone 바탕 밝기 (null 이면 겹치지 않음)
 */
export function useHeaderOverlay(tone: 'dark' | 'light' | null): void {
  useEffect(() => {
    if (!tone || typeof document === 'undefined') {
      return;
    }
    const token = Symbol('hero-header-overlay');
    overlayOwner = token;
    document.documentElement.setAttribute(HEADER_OVERLAY_ATTRIBUTE, tone);
    return () => {
      if (overlayOwner === token) {
        overlayOwner = null;
        document.documentElement.removeAttribute(HEADER_OVERLAY_ATTRIBUTE);
      }
    };
  }, [tone]);
}

/**
 * 편집 미리보기에서 문구 상자를 끌거나 화살표 키로 옮깁니다 — 끄는 동안은 화면에서만 움직이고 놓을 때 한 번 알린다.
 * 화살표 1% · Shift+화살표 10%. 옮길 거리가 없는 축(상자가 꽉 찬 폭·높이)은 그대로 둔다.
 *
 * @param spot 저장 전 지금 자리
 * @param onMove 옮긴 자리를 알리는 콜백 (없으면 끄기 꺼짐)
 * @returns 상자에 붙일 속성과 끄는 중인 자리
 */
export function useTextDrag(spot: TextSpot, onMove?: (spot: TextSpot) => void): {
  dragging: TextSpot | null;
  boxRef: React.RefObject<HTMLDivElement | null>;
  handlers: React.HTMLAttributes<HTMLDivElement>;
} {
  const boxRef = useRef<HTMLDivElement>(null);
  const [dragging, setDragging] = useState<TextSpot | null>(null);
  const start = useRef<{ pointer: number; x: number; y: number; spot: TextSpot; travelX: number; travelY: number } | null>(null);
  const latest = useRef<TextSpot | null>(null);

  /** 상자가 움직일 수 있는 거리(px) — 가로는 띠 안 폭에서 상자 폭을 뺀 값, 세로는 슬라이드 높이에서 띠 높이를 뺀 값 */
  const travel = (): { x: number; y: number } => {
    const box = boxRef.current;
    const parent = box?.parentElement;
    const layer = box?.closest<HTMLElement>('.hs-slider__text');
    const slide = box?.closest<HTMLElement>('.hs-slider__slide');
    if (!box || !parent || !layer || !slide) {
      return { x: 0, y: 0 };
    }
    const style = parent.ownerDocument.defaultView?.getComputedStyle(parent);
    const inner = parent.clientWidth - (parseFloat(style?.paddingLeft ?? '') || 0) - (parseFloat(style?.paddingRight ?? '') || 0);
    return { x: inner - box.offsetWidth, y: slide.clientHeight - layer.offsetHeight };
  };
  const moved = (from: TextSpot, dx: number, dy: number, distance: { x: number; y: number }): TextSpot => ({
    x: distance.x > 0 ? percent(from.x + (dx / distance.x) * 100, from.x) : from.x,
    y: distance.y > 0 ? percent(from.y + (dy / distance.y) * 100, from.y) : from.y,
  });

  if (!onMove) {
    return { dragging: null, boxRef, handlers: {} };
  }

  const handlers: React.HTMLAttributes<HTMLDivElement> = {
    onPointerDown: (event) => {
      if (event.button !== 0) {
        return;
      }
      const distance = travel();
      start.current = { pointer: event.pointerId, x: event.clientX, y: event.clientY, spot, travelX: distance.x, travelY: distance.y };
      latest.current = null;
      (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
      event.preventDefault();
    },
    onPointerMove: (event) => {
      const from = start.current;
      if (!from || from.pointer !== event.pointerId) {
        return;
      }
      const next = moved(from.spot, event.clientX - from.x, event.clientY - from.y, { x: from.travelX, y: from.travelY });
      latest.current = next;
      setDragging(next);
    },
    onPointerUp: (event) => {
      const from = start.current;
      if (!from || from.pointer !== event.pointerId) {
        return;
      }
      start.current = null;
      setDragging(null);
      if (latest.current && (latest.current.x !== from.spot.x || latest.current.y !== from.spot.y)) {
        onMove(latest.current);
      }
    },
    onPointerCancel: () => {
      start.current = null;
      setDragging(null);
    },
    onKeyDown: (event) => {
      const step = event.shiftKey ? 10 : 1;
      const delta: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
      const move = delta[event.key];
      if (!move) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      const next = { x: percent(spot.x + move[0], spot.x), y: percent(spot.y + move[1], spot.y) };
      if (next.x !== spot.x || next.y !== spot.y) {
        onMove(next);
      }
    },
  };

  return { dragging, boxRef, handlers };
}
