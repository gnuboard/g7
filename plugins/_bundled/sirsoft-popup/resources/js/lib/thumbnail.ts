/**
 * 팝업 이미지의 반응형 srcset·sizes — 코어 공개 API `G7Core.thumbnail` 만 쓴다.
 *
 * 판정(같은 출처 + 서버가 내려 준 서빙 경로)과 `?size=` 덧붙임 규칙은 코어 한 곳이 갖는다. 플러그인 번들은 코어와 별도 IIFE 라
 * import 할 수 없고, 규칙 사본을 두면 대상 판정이 갈린다. 후보는 코어 `contentSrcsetWithin(url, 원본 가로, { includeOriginal: true })`
 * 가 만든다 — 숫자 요청 규격(md·lg·xl 처럼 운영자가 「숫자 요청 대상」으로 켠 규격) 중 **원본보다 작은 것** + 원본(제 가로).
 *
 *  - 원본보다 큰 후보(예: 원본 750 에 1280w)를 두면 서버는 키우지 않고 원본을 보내는데 브라우저는 1280px 로 알고 고른다 — 후보가
 *    거짓말을 하게 되므로 뺀다. 원본과 같은 가로의 후보도 원본과 기술자가 겹쳐 뺀다.
 *  - 원본을 맨 끝 후보로 두는 까닭: 고해상도 화면의 넓은 팝업은 지금처럼 원본을 받아야 화질이 줄지 않는다.
 *  - 코어 함수 `contentSrcsetWithin` 이 없거나, 서빙 경로가 아니거나, 원본 가로를 모르면 undefined — 호출자는 srcset 을 붙이지 않고
 *    원본 하나를 쓴다. 템플릿 본문 이미지(`contentImages.ts`)와 같은 정책이고, 후보를 여기서 거르지 않는다. 움직이는 GIF 는 서버가
 *    원본을 그대로 보낸다(`bypass:animated`) — 표시 크기는 CSS 가 정해 모양은 같다.
 *
 * PC·모바일 이미지는 기기별로 따로 올린 각자의 원본이라 각자 이 함수를 부른다(각자의 가로로 후보가 정해진다).
 *
 * 공개 자산 디스크(#878): 응답의 `direct_url` 은 원본 한 장의 직접 주소(public·CDN)다. 축소 후보는 언제나 공개 API 주소
 * (`url?size=`, 서버가 공개 썸네일 디스크로 302)로 만들고, **마지막 원본 후보만** 직접 주소로 바꾼다 — 고해상도 화면이 고르는
 * 원본이 PHP 를 거치지 않게. 그린 크기 측정 등 같은 출처 비교 키는 계속 `url` 을 쓴다.
 */

import { MOBILE_MAX_WIDTH } from './popupVisibility';

/** `direct_url` 을 가질 수 있는 이미지 한 장 */
export interface DirectMedia {
  /** 공개 API 주소 (썸네일 기준) */
  url?: string | null;

  /** 원본 직접 주소 (공개 자산 디스크가 아니면 null) */
  direct_url?: string | null;
}

/**
 * 원본 한 장을 그릴 주소 — 직접 주소가 있으면 그것, 없으면 공개 API 주소.
 *
 * @param media 이미지
 * @returns 주소 (둘 다 없으면 undefined)
 */
export function mediaSrc(media: DirectMedia | null | undefined): string | undefined {
  if (typeof media?.direct_url === 'string' && media.direct_url !== '') {
    return media.direct_url;
  }

  return typeof media?.url === 'string' && media.url !== '' ? media.url : undefined;
}

/**
 * srcset 주소의 공백을 코어와 같은 방식으로 이스케이프합니다(코어 `srcsetUrl` 과 같은 규칙).
 *
 * @param url 주소
 * @returns 이스케이프한 주소
 */
function srcsetUrl(url: string): string {
  return url.replace(/ /g, '%20');
}

/** 코어 썸네일 API 의 원본 가로 상한 srcset 함수 (없으면 null) */
function contentSrcsetWithinApi(): ((url: unknown, maxWidth: unknown, options: { includeOriginal: boolean }) => unknown) | null {
  const thumbnail = (globalThis as { G7Core?: { thumbnail?: { contentSrcsetWithin?: unknown } } })?.G7Core?.thumbnail;

  return thumbnail && typeof thumbnail.contentSrcsetWithin === 'function'
    ? (thumbnail.contentSrcsetWithin as (url: unknown, maxWidth: unknown, options: { includeOriginal: boolean }) => unknown)
    : null;
}

/**
 * 원본 주소와 가로로 가로 기술자(`w`) srcset 을 만듭니다.
 *
 * 원본 직접 주소(`original`)를 주면 코어 결과의 마지막 원본 후보(`{url} {가로}w`)만 그 주소로 바꾼다. 꼬리가 정확히 그
 * 모양일 때만 바꾸고 그 밖은 손대지 않는다(코어 출력 형식이 바뀌면 원본 후보는 API 주소로 남는다 — 안전한 쪽).
 *
 * @param url 원본 주소 (사이트 마운트 접두사를 붙인 공개 API 주소 — 썸네일 기준)
 * @param width 원본 가로(px)
 * @param original 원본 한 장의 직접 주소 (없거나 url 과 같으면 바꾸지 않는다)
 * @returns srcset (붙일 후보가 없거나 코어 함수가 없으면 undefined)
 */
export function responsiveSrcset(url: string | null | undefined, width: number | null | undefined, original?: string | null): string | undefined {
  if (typeof url !== 'string' || url === '' || typeof width !== 'number' || !Number.isFinite(width) || width <= 0) {
    return undefined;
  }

  const core = contentSrcsetWithinApi();
  const result = core ? core(url, width, { includeOriginal: true }) : null;

  if (typeof result !== 'string' || result === '') {
    return undefined;
  }

  if (typeof original === 'string' && original !== '' && original !== url) {
    const tail = `${srcsetUrl(url)} ${Math.floor(width)}w`;
    if (result.endsWith(tail)) {
      return `${result.slice(0, result.length - tail.length)}${srcsetUrl(original)} ${Math.floor(width)}w`;
    }
  }

  return result;
}

/** 레이어 배치 — sizes 를 정하는 값 */
export interface PopupImageSizesInput {
  /** 휴대폰 배치인지 */
  isMobile: boolean;
  /** 휴대폰 하단 시트형인지 */
  isSheet: boolean;
  /** PC 레이어 설정 폭(px) */
  pcWidth: number;
  /** PC 레이어 설정 본문 높이(px) — 비율을 알 때 폭 상한을 줄인다 */
  pcHeight?: number;
  /** 이미지 비율(가로 ÷ 세로) — 알면 본문 높이에 맞춰 줄어드는 폭까지 계산한다 */
  ratio?: number | null;
  /** 관리자 미리보기인지 (기기 크기 틀 안이라 화면 폭으로 줄이지 않는다) */
  preview: boolean;
  /**
   * 레이어가 헤더·GDPR 배너 사이에 창이 들어가도록 줄인 본문 높이 상한(px) — `PopupLayer` 의 `fit` 상태(`--g7pp-fit-h`).
   * 없으면(하단 시트·미리보기·아직 재지 않음) 넣지 않는다
   */
  fitHeight?: number | null;
}

/** 휴대폰 가운데 창·하단 시트의 본문 높이 상한(px) — CSS `--g7pp-body-h` 의 고정값과 같다(main.css) */
const MOBILE_BODY_MAX = { center: 420, sheet: 380 } as const;

/**
 * 팝업 이미지의 sizes — 레이어가 이미지를 그리는 폭.
 *
 * 레이어 본문 폭 계산(캡슐 점 개수)과 같은 기하를 쓴다: PC 는 설정 폭이되 화면 좌우 16px 여백 안, 휴대폰 가운데 창은 좌우 24px 여백,
 * 하단 시트는 화면 폭. 이미지는 본문 칸에 잘리지 않게(`contain`) 담기므로 실제 폭은 `min(칸 폭, 칸 높이 × 이미지 비율)` 이다 —
 * 비율을 알면 칸 높이(main.css 의 `.g7pp-body` 높이 식: PC 설정 높이, 가운데 창 `min(70vh, 420px)`, 하단 시트 `min(60vh, 380px)`,
 * 모두 `100vh - 116px` 안)를 곱한 값으로 폭을 줄인다. 세로로 긴 이미지가 375 하단 시트에서 표시 285px 인데 화면 폭(100vw)으로
 * 한 단계 큰 후보를 고르던 것을 막는다. 헤더·GDPR 배너를 피해 레이어가 더 줄이는 높이(`--g7pp-fit-h`, `fitHeight`)도 같은 식의
 * 세 번째 항으로 넣는다 — CSS `.g7pp-body` 높이가 `min(--g7pp-body-h, 100vh - 116px, --g7pp-fit-h)` 라, 빼면 PC 에서 320px 로 그리면서
 * 390px 로 계산해 한 단계 큰 후보(1280w)를 골랐다. 비율을 모르면 칸 폭만 준다.
 * 미리보기는 관리자 화면 안의 기기 틀이라 화면 폭·높이(`vw`·`vh`)가 틀 크기가 아니다 — PC 는 설정 폭, 휴대폰은 휴대폰 판정 상한 폭을
 * 상한으로 두고 칸 높이 상한 × 비율과 견준 숫자를 준다.
 *
 * @param input 레이어 배치
 * @returns sizes 값
 */
export function popupImageSizes(input: PopupImageSizesInput): string {
  const ratio = typeof input.ratio === 'number' && Number.isFinite(input.ratio) && input.ratio > 0 ? Number(input.ratio.toFixed(4)) : null;
  const pcWidth = Math.max(1, Math.round(input.pcWidth));
  const pcHeight = typeof input.pcHeight === 'number' && input.pcHeight > 0 ? Math.round(input.pcHeight) : null;

  if (input.preview) {
    const widthCap = input.isMobile ? MOBILE_MAX_WIDTH : pcWidth;
    const heightCap = input.isMobile ? MOBILE_BODY_MAX[input.isSheet ? 'sheet' : 'center'] : pcHeight;

    return `${ratio !== null && heightCap !== null ? Math.min(widthCap, Math.ceil(heightCap * ratio)) : widthCap}px`;
  }

  const fit = typeof input.fitHeight === 'number' && Number.isFinite(input.fitHeight) && input.fitHeight > 0 ? `, ${Math.round(input.fitHeight)}px` : '';
  let bodyHeight: string | null;
  if (input.isMobile) {
    bodyHeight = input.isSheet ? `min(60vh, ${MOBILE_BODY_MAX.sheet}px, 100vh - 116px${fit})` : `min(70vh, ${MOBILE_BODY_MAX.center}px, 100vh - 116px${fit})`;
  } else {
    bodyHeight = pcHeight !== null ? `min(${pcHeight}px, 100vh - 116px${fit})` : null;
  }
  const cap = ratio !== null && bodyHeight !== null ? `calc(${bodyHeight} * ${ratio})` : null;
  const within = (width: string) => (cap ? `min(${width}, ${cap})` : width);

  if (input.isMobile) {
    return within(input.isSheet ? '100vw' : 'calc(100vw - 48px)');
  }

  return `(max-width: ${pcWidth + 32}px) ${within('calc(100vw - 32px)')}, ${within(`${pcWidth}px`)}`;
}
