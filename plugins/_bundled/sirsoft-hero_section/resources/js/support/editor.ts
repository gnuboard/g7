import type React from 'react';

/** 활성 템플릿의 기본 컴포넌트를 우선 쓰고, 독립 미리보기·테스트는 HTML 기본 요소로 대체합니다. */
export function basic(name: string, fallback: React.ElementType): React.ElementType {
  return (window as any).G7Core?.getComponentRegistry?.()?.getComponent?.(name) ?? fallback;
}

/** 플러그인 번역 키를 조회합니다. */
export function translate(key: string, params?: Record<string, string | number>): string {
  const full = `sirsoft-hero_section.${key}`;
  return (window as any).G7Core?.t?.(full, params) ?? full;
}

/** 공개 응답과 같은 순서(요청·사이트 기본·en·첫 유효값)로 문구를 고릅니다. */
export function localized(value: Record<string, string> | null | undefined, locale: string, defaultLocale: string): string {
  for (const key of [...new Set([locale, defaultLocale, 'en', ...Object.keys(value ?? {})])]) {
    if (typeof value?.[key] === 'string' && value[key].trim() !== '') return value[key];
  }
  return '';
}

/** 시간대가 없는 입력을 브라우저 시간대로 파싱하지 않고 사이트의 벽시계 문자열끼리 비교합니다. */
export function periodStatus(period: string, active: boolean, start: string, end: string, timezone: string, instant: number): string {
  if (!active) return 'inactive';
  if (period !== 'range') return 'active';
  if (!start || !end || !timezone) return 'incomplete';
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(instant));
  const part = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
  const now = `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}:${part('second')}`;
  const seconds = (value: string) => value.length === 16 ? `${value}:00` : value;
  if (now < seconds(start)) return 'scheduled';
  if (now > seconds(end)) return 'ended';
  return 'active';
}

/** 미리보기 구좌 틀의 상자 하나 — 서버가 활성 사용자 템플릿 레이아웃에서 꺼낸 정적 클래스와 반응형 덮어쓰기(className 만). */
export interface FrameBox {
  className: string;
  responsive?: Record<string, string>;
}

/** 서버 위치 설정 응답의 `editor.preview_frame`. */
export interface PreviewFrame {
  template?: string | null;
  stylesheets?: string[];
  /** `content` — 확장점이 넘긴 본문 상자(`contentClass`·`contentClassDesktop`). 템플릿이 넘기지 않았으면 null */
  positions?: Record<string, { layout: string; chain: FrameBox[]; content?: FrameBox | null } | null>;
}

/** 코어 ResponsiveManager 프리셋과 같은 값 — 코어가 이 값을 확장에 공개하지 않아 따라 적고, 시험이 코어와 같은지 잠근다. */
const PRESETS: Record<string, [number, number]> = {
  mobile: [0, 767],
  tablet: [768, 1023],
  desktop: [1024, Infinity],
  portable: [0, 1023],
};

/** 반응형 키를 [min, max] 로 풉니다(프리셋·`min-max`·`-max`·`min-`). 잘못된 키는 null. */
function range(key: string): [number, number] | null {
  if (PRESETS[key]) return PRESETS[key];
  const match = key.match(/^(-?\d*)-(-?\d*)$/);
  if (!match) return null;
  const min = match[1] === '' ? 0 : parseInt(match[1], 10);
  const max = match[2] === '' ? Infinity : parseInt(match[2], 10);
  return !isNaN(min) && !isNaN(max) && min <= max ? [min, max] : null;
}

/** 폭에 맞는 반응형 키 — 사용자 범위가 프리셋보다, 좁은 범위가 넓은 범위보다 우선(코어 getMatchingKey 와 같은 규칙). */
export function matchResponsiveKey(responsive: Record<string, unknown>, width: number): string | null {
  const matched = Object.keys(responsive)
    .map((key) => ({ key, r: range(key), preset: !!PRESETS[key] }))
    .filter((m): m is { key: string; r: [number, number]; preset: boolean } => !!m.r && width >= m.r[0] && width <= m.r[1])
    .sort((a, b) => (a.preset !== b.preset ? (a.preset ? 1 : -1) : (a.r[1] - a.r[0]) - (b.r[1] - b.r[0])));
  return matched[0]?.key ?? null;
}

/** 그 폭에서 상자가 쓰는 클래스 — 반응형 덮어쓰기는 기본 클래스를 통째로 바꾼다(엔진과 같다). */
export function frameClass(box: FrameBox, width: number): string {
  const key = matchResponsiveKey(box.responsive ?? {}, width);
  return key !== null ? (box.responsive ?? {})[key] : box.className;
}

export type Rgb = [number, number, number];

/** 문구 배경의 가장 진한 색과 불투명도 — hero.css `.hs__text--dark`·`.hs__text--light` 그라데이션 끝값과 같다. */
export const OVERLAYS: Record<string, { rgb: Rgb; alpha: number }> = {
  dark: { rgb: [0, 0, 0], alpha: 0.78 },
  light: { rgb: [255, 255, 255], alpha: 0.9 },
};

/** `#RRGGBB` 를 RGB 로 풉니다. 형식이 다르면 null. */
function hexRgb(hex: string): Rgb | null {
  const match = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex ?? '');
  return match ? [parseInt(match[1], 16), parseInt(match[2], 16), parseInt(match[3], 16)] : null;
}

/** WCAG 상대 휘도 */
function luminance([r, g, b]: Rgb): number {
  const f = (v: number) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}

/** 글자색(`#RRGGBB`)과 배경색의 WCAG 대비율. 글자색 형식이 다르면 null. */
export function contrastRatio(text: string, background: Rgb): number | null {
  const rgb = hexRgb(text);
  if (!rgb) return null;
  const [hi, lo] = [luminance(rgb), luminance(background)].sort((a, b) => b - a);
  return (hi + 0.05) / (lo + 0.05);
}

/**
 * 사진 평균색 위에 문구 배경을 절반 세기(그라데이션 가운데)로 겹친 색과 글자색의 대비 추정치.
 * 사진의 부분 밝기는 반영하지 못하므로 안내용 어림값이다 — 실제 대비라고 단정하지 않는다.
 */
export function estimateContrast(text: string, image: Rgb, overlay?: string | null): number | null {
  const layer = OVERLAYS[overlay ?? ''];
  const background = layer
    ? image.map((v, i) => v + (layer.rgb[i] - v) * layer.alpha * 0.5) as Rgb
    : image;
  return contrastRatio(text, background);
}

/** 글자색이 밝은 쪽인지(어두운 문구 배경이 어울리는지) */
export function isLightText(text: string): boolean {
  const rgb = hexRgb(text);
  return !!rgb && luminance(rgb) > 0.18;
}

/** 이미지의 평균색을 어림합니다. 불러오지 못하거나 읽을 수 없으면(다른 출처 등) null. */
export function sampleImage(url: string): Promise<Rgb | null> {
  return new Promise((resolve) => {
    const image = new Image();
    image.onload = () => {
      try {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 16;
        const context = canvas.getContext('2d');
        if (!context) return resolve(null);
        context.drawImage(image, 0, 0, 16, 16);
        const data = context.getImageData(0, 0, 16, 16).data;
        const sum: Rgb = [0, 0, 0];
        for (let i = 0; i < data.length; i += 4) for (let c = 0; c < 3; c++) sum[c] += data[i + c];
        const count = data.length / 4;
        resolve(sum.map((v) => v / count) as Rgb);
      } catch {
        resolve(null);
      }
    };
    image.onerror = () => resolve(null);
    image.src = url;
  });
}
