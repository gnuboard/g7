/**
 * 미리보기 매체 격리 — `@media` 조건식 분석·변환 (순수 함수)
 *
 * 폭 조건은 미리보기 프레임 컨테이너 조건으로 옮기고, 폭이 아닌 조건(hover·prefers-*·orientation·
 * resolution 등)은 바깥 `@media` 로 남긴다. 선택자 이름이 아니라 조건식만 보므로 CSS 제작 도구
 * (일반 CSS·Bootstrap·Tailwind v3/v4·CSS-in-JS)와 무관하다.
 *
 * 길이 단위: `@media` 의 rem/em 은 브라우저 기본 글꼴 크기 기준인데, `@container` 의 rem 은 루트 글꼴
 * 크기, em 은 컨테이너 글꼴 크기 기준이다. 그대로 옮기면 판정이 어긋나므로 px 로 바꾼다.
 *
 * @since engine-v1.75.2
 */

/** 변환 결과의 항목 하나 — 원래 쉼표 목록의 한 항목에 대응 */
export interface MediaQueryEntry {
  /** `@container g7le-preview` 뒤에 붙일 조건 (예: `(min-width: 768px)`) */
  containerCondition: string;
  /** 폭이 아닌 조건을 담은 바깥 `@media` 조건, 없으면 null */
  outerMedia: string | null;
}

export type MediaQueryAnalysis =
  | { kind: 'none' }
  | { kind: 'convertible'; entries: MediaQueryEntry[] }
  | { kind: 'unsupported'; reason: string };

/** 폭 기능 이름 (device-* 는 width 로 바꾼다) */
const WIDTH_FEATURES: Record<string, string> = {
  'width': 'width',
  'min-width': 'min-width',
  'max-width': 'max-width',
  'device-width': 'width',
  'min-device-width': 'min-width',
  'max-device-width': 'max-width',
};

/** 미리보기와 무관한 매체 유형 — 그 항목은 변환 결과에서 뺀다 */
const IRRELEVANT_MEDIA_TYPES = new Set(['print', 'speech', 'tty', 'tv', 'projection', 'handheld', 'braille', 'embossed', 'aural']);

/** 항목 분석 결과 */
type EntryResult =
  | { kind: 'irrelevant' }
  | { kind: 'no-width' }
  | { kind: 'width'; entry: MediaQueryEntry }
  | { kind: 'unsupported'; reason: string };

/**
 * 최상위(괄호 밖) 구분자로 나눈다.
 *
 * @param text 원문
 * @param separator 구분 정규식(전역 플래그 없이, 최상위 위치에서 시험)
 * @returns 조각
 */
function splitTopLevel(text: string, separator: RegExp): string[] {
  const parts: string[] = [];
  let depth = 0;
  let start = 0;

  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (char === '(') depth++;
    else if (char === ')') depth = Math.max(0, depth - 1);
    else if (depth === 0) {
      const match = separator.exec(text.slice(i));
      if (match && match.index === 0) {
        parts.push(text.slice(start, i));
        i += match[0].length - 1;
        start = i + 1;
      }
    }
  }

  parts.push(text.slice(start));

  return parts.map((part) => part.trim());
}

/**
 * 길이 값을 px 로 바꾼다.
 *
 * @param value 길이(`48rem`, `768px`, `0`)
 * @param remPx rem/em 의 px 값(브라우저 기본 글꼴 크기)
 * @returns px 문자열, 변환할 수 없으면 null
 */
function toPx(value: string, remPx: number): string | null {
  const match = /^(-?(?:\d+\.?\d*|\.\d+))(px|rem|em)?$/i.exec(value.trim());
  if (!match) return null;

  const number = parseFloat(match[1]);
  const unit = (match[2] ?? '').toLowerCase();

  if (unit === '') {
    return number === 0 ? '0' : null;
  }

  const px = unit === 'px' ? number : number * remPx;

  return `${Number(px.toFixed(4))}px`;
}

/**
 * 괄호 하나의 기능식을 분석한다.
 *
 * @param inner 괄호 안쪽(`min-width: 48rem`, `width >= 48rem`, `hover: hover`)
 * @param remPx rem 기준 px
 * @returns 폭 조건(변환된 괄호식) / 비폭 / 변환 불가
 */
function analyzeFeature(
  inner: string,
  remPx: number,
): { kind: 'width'; condition: string } | { kind: 'other' } | { kind: 'unsupported'; reason: string } {
  const text = inner.trim();

  if (text.includes('(')) {
    // 중첩 괄호 — 폭이 섞여 있으면 논리 구조를 옮길 수 없다
    return /width/i.test(text) ? { kind: 'unsupported', reason: 'nested' } : { kind: 'other' };
  }

  // 일반 문법 `name: value`
  const plain = /^([a-z-]+)\s*:\s*(.+)$/i.exec(text);
  if (plain) {
    const name = plain[1].toLowerCase();
    const mapped = WIDTH_FEATURES[name];
    if (!mapped) return { kind: 'other' };

    const px = toPx(plain[2], remPx);
    if (px === null) return { kind: 'unsupported', reason: 'value' };

    return { kind: 'width', condition: `(${mapped}: ${px})` };
  }

  // 범위 문법 `width >= X`, `X <= width < Y`
  const tokens = text.split(/\s*(<=|>=|<|>|=)\s*/).filter((token) => token.length > 0);
  if (tokens.length >= 3) {
    const nameIndex = tokens.findIndex((token) => /^[a-z-]+$/i.test(token));
    if (nameIndex < 0) return { kind: 'other' };

    const name = tokens[nameIndex].toLowerCase();
    const mapped = WIDTH_FEATURES[name];
    if (!mapped) return { kind: 'other' };
    if (mapped !== 'width') return { kind: 'unsupported', reason: 'range-prefix' };

    const converted: string[] = [];
    for (let i = 0; i < tokens.length; i++) {
      const token = tokens[i];
      if (i === nameIndex) {
        converted.push('width');
      } else if (/^(<=|>=|<|>|=)$/.test(token)) {
        converted.push(token);
      } else {
        const px = toPx(token, remPx);
        if (px === null) return { kind: 'unsupported', reason: 'value' };
        converted.push(px);
      }
    }

    return { kind: 'width', condition: `(${converted.join(' ')})` };
  }

  // 불리언 문맥(`(hover)`, `(prefers-reduced-motion)`, `(width)`)
  return WIDTH_FEATURES[text.toLowerCase()] ? { kind: 'unsupported', reason: 'boolean-width' } : { kind: 'other' };
}

/**
 * 쉼표 목록의 항목 하나를 분석한다.
 *
 * @param rawEntry 항목 원문
 * @param remPx rem 기준 px
 * @returns 항목 결과
 */
function analyzeEntry(rawEntry: string, remPx: number): EntryResult {
  let text = rawEntry.trim();
  if (text === '') return { kind: 'no-width' };

  // Tailwind v3 의 max-* 변형 `not all and (min-width: X)` → 컨테이너 조건 `not (min-width: X)`
  const tailwindNot = /^not\s+all\s+and\s+(\([^()]*\))$/i.exec(text);
  if (tailwindNot) {
    const feature = analyzeFeature(tailwindNot[1].slice(1, -1), remPx);
    if (feature.kind === 'width') {
      return { kind: 'width', entry: { containerCondition: `not ${feature.condition}`, outerMedia: null } };
    }
    return feature.kind === 'other' ? { kind: 'no-width' } : { kind: 'unsupported', reason: feature.reason };
  }

  if (/^not\b/i.test(text)) {
    return /width/i.test(text) ? { kind: 'unsupported', reason: 'not' } : { kind: 'no-width' };
  }

  // 앞머리 only + 매체 유형
  text = text.replace(/^only\s+/i, '');
  const typeMatch = /^([a-z-]+)(\s+and\s+|$)/i.exec(text);
  if (typeMatch && !text.startsWith('(')) {
    const type = typeMatch[1].toLowerCase();
    if (IRRELEVANT_MEDIA_TYPES.has(type)) return { kind: 'irrelevant' };
    if (type !== 'screen' && type !== 'all') return { kind: 'unsupported', reason: 'media-type' };
    text = text.slice(typeMatch[0].length).trim();
    if (text === '') return { kind: 'no-width' };
  }

  if (splitTopLevel(text, /^\s+or\s+/i).length > 1) {
    return /width/i.test(text) ? { kind: 'unsupported', reason: 'or' } : { kind: 'no-width' };
  }

  const widthConditions: string[] = [];
  const otherConditions: string[] = [];

  for (const part of splitTopLevel(text, /^\s+and\s+/i)) {
    if (!part.startsWith('(') || !part.endsWith(')')) {
      return { kind: 'unsupported', reason: 'syntax' };
    }

    const feature = analyzeFeature(part.slice(1, -1), remPx);
    if (feature.kind === 'unsupported') return feature;
    if (feature.kind === 'width') widthConditions.push(feature.condition);
    else otherConditions.push(part);
  }

  if (widthConditions.length === 0) return { kind: 'no-width' };

  return {
    kind: 'width',
    entry: {
      containerCondition: widthConditions.join(' and '),
      outerMedia: otherConditions.length > 0 ? otherConditions.join(' and ') : null,
    },
  };
}

/**
 * `@media` 조건 목록을 분석한다.
 *
 * - 폭 조건이 하나도 없으면 `none` (원문 유지)
 * - 남는 항목이 전부 폭 조건을 가지면 `convertible`
 * - `or`·`calc()`·`vw`·폭과 비폭이 한 괄호에 섞인 중첩·폭/비폭 항목 혼재는 `unsupported` (원문 유지, 카운트)
 *
 * @param text `media.mediaText`
 * @param remPx rem/em 의 px 값
 * @returns 분석 결과
 */
export function analyzeMediaQueryList(text: string, remPx: number): MediaQueryAnalysis {
  const entries: MediaQueryEntry[] = [];
  let noWidth = 0;

  for (const raw of splitTopLevel(text ?? '', /^,/)) {
    const result = analyzeEntry(raw, remPx);
    if (result.kind === 'unsupported') return { kind: 'unsupported', reason: result.reason };
    if (result.kind === 'irrelevant') continue;
    if (result.kind === 'no-width') {
      noWidth++;
      continue;
    }
    entries.push(result.entry);
  }

  if (entries.length === 0) return { kind: 'none' };
  if (noWidth > 0) return { kind: 'unsupported', reason: 'mixed-list' };

  return { kind: 'convertible', entries };
}
