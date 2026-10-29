/**
 * 이미지 위 글 상자(`popups.overlays`) — 형·한도·스타일 계산.
 *
 * 위치(x·y)·폭(w)·글자 크기(size)는 이미지 폭·높이 대비 % 라 이미지 표시 크기를 따라 함께 커지고 작아진다.
 * 서식은 언어 공통이고 글만 언어별이다. 서버의 같은 한도는 `src/Support/Overlays.php` — 한쪽만 바꾸지 않는다.
 */
import type React from 'react';

/** 기기별 최대 상자 수 */
export const MAX_BOXES = 10;

/** 언어별 글 최대 길이 */
export const MAX_TEXT = 200;

/** 폭 하한(%) */
export const MIN_WIDTH = 5;

/** 글자 크기 범위(이미지 폭 대비 %) */
export const MIN_SIZE = 2;
export const MAX_SIZE = 20;

/** 바탕 진하기 상한(%) — 100 이면 바탕이 이미지를 가린다 */
export const MAX_OPACITY = 90;

/** 정렬 */
export type OverlayAlign = 'left' | 'center' | 'right';

/** 서식 — 언어 공통 */
export interface OverlayFormat {
  /** 왼쪽 위치 (이미지 폭 대비 %) */
  x: number;

  /** 위쪽 위치 (이미지 높이 대비 %) */
  y: number;

  /** 폭 (이미지 폭 대비 %) — 높이는 글에 따라 자동 */
  w: number;

  /** 글자 크기 (이미지 폭 대비 %) */
  size: number;

  /** 글자 색 `#RRGGBB` */
  color: string;

  /** 굵게 */
  bold: boolean;

  /** 정렬 */
  align: OverlayAlign;

  /** 반투명 바탕 — opacity 는 진하기 %(0 투명 ~ 90), null 이면 바탕 없음 */
  bg: { color: string; opacity: number } | null;

  /** 글자 그림자 */
  shadow: boolean;
}

/** 저장되는 상자 — 글은 언어별 */
export interface OverlayBox extends OverlayFormat {
  /** 영숫자 8자 */
  id: string;

  /** 언어 → 글 */
  text: Record<string, string>;
}

/** 공개 응답 상자 — 서버가 요청 언어로 고른 글 하나 */
export interface PublicOverlayBox extends OverlayFormat {
  id: string;
  text: string;
}

/** 기기별 배치 — `mobile` 이 null 이면 PC 배치를 쓴다 */
export interface Overlays<B> {
  pc: B[];
  mobile: B[] | null;
}

/**
 * 새 상자 id 를 만듭니다(영숫자 8자, 이미 있는 id 는 피한다).
 *
 * @param taken 이미 쓰는 id
 * @returns 새 id
 */
export function newId(taken: string[] = []): string {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  for (;;) {
    const id = Array.from({ length: 8 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
    if (!taken.includes(id)) return id;
  }
}

/**
 * 기본 서식의 새 상자 — 이미지 가운데 쯤에 둔다.
 *
 * @param taken 이미 쓰는 id
 * @returns 새 상자
 */
export function newBox(taken: string[] = []): OverlayBox {
  return { id: newId(taken), x: 10, y: 40, w: 80, text: {}, size: 6, color: '#FFFFFF', bold: true, align: 'center', bg: null, shadow: true };
}

/**
 * 값을 범위 안으로 자르고 소수 1자리로 맞춥니다.
 *
 * @param value 값
 * @param min 하한
 * @param max 상한
 * @returns 자른 값
 */
export function clampPct(value: number, min: number, max: number): number {
  return Math.round(Math.min(Math.max(value, min), Math.max(min, max)) * 10) / 10;
}

/**
 * `#RRGGBB` 와 진하기(%)를 `rgba()` 로 바꿉니다.
 *
 * @param hex 색
 * @param opacity 진하기 0~100
 * @returns rgba 문자열
 */
export function rgba(hex: string, opacity: number): string {
  const n = parseInt(hex.replace('#', ''), 16) || 0;

  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${Math.round(opacity) / 100})`;
}

/**
 * 상자 한 개의 위치·서식 스타일 — 사용자 레이어·미리보기·편집기가 같은 함수로 그린다.
 * 글자 크기는 CSS 변수로 넘기고 `.g7pp-overlay` 가 이미지 프레임 폭 단위(`cqw`)로 바꾼다.
 *
 * @param box 서식
 * @returns 인라인 스타일
 */
export function overlayStyle(box: OverlayFormat): React.CSSProperties {
  return {
    left: `${box.x}%`,
    top: `${box.y}%`,
    width: `${box.w}%`,
    ['--g7pp-ov-size' as string]: String(box.size),
    color: box.color,
    fontWeight: box.bold ? 700 : 400,
    textAlign: box.align,
    backgroundColor: box.bg ? rgba(box.bg.color, box.bg.opacity) : undefined,
    textShadow: box.shadow ? '0 1px 3px rgba(0, 0, 0, 0.6)' : undefined,
  };
}

/**
 * 이미지에 맞는 배치를 고릅니다 — 모바일 이미지를 쓰는 휴대폰이면 모바일 배치(없으면 PC 배치), 그 밖은 PC 배치.
 *
 * @param overlays 기기별 배치
 * @param usesMobileImage 지금 모바일 이미지를 보여 주는지
 * @returns 상자 목록
 */
export function boxesFor<B>(overlays: Overlays<B> | null | undefined, usesMobileImage: boolean): B[] {
  if (!overlays) return [];

  return (usesMobileImage ? (overlays.mobile ?? overlays.pc) : overlays.pc) ?? [];
}
