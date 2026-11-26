/**
 * 배너 이미지의 반응형 srcset — 코어 공개 API `G7Core.thumbnail` 만 쓴다.
 *
 * 판정(같은 출처 + 서버가 내려 준 서빙 경로)과 `?size=` 덧붙임 규칙은 코어 한 곳이 갖는다. 플러그인 번들은 코어와 별도 IIFE 라
 * import 할 수 없고, 규칙 사본을 두면 대상 판정이 갈린다. 후보는 코어 `contentSrcsetWithin(url, 원본 가로, { includeOriginal: true })`
 * 가 만든다 — 숫자 요청 규격(md·lg·xl 처럼 운영자가 「숫자 요청 대상」으로 켠 규격) 중 **원본보다 작은 것** + 원본(제 가로).
 *
 *  - 원본보다 큰 후보(예: 원본 750 에 1280w)를 두면 서버는 키우지 않고 원본을 보내는데 브라우저는 1280px 로 알고 고른다 — 후보가
 *    거짓말을 하게 되므로 뺀다. 원본과 같은 가로의 후보도 원본과 기술자가 겹쳐 뺀다.
 *  - 원본을 맨 끝 후보로 두는 까닭: 고해상도 화면의 넓은 배너는 지금처럼 원본을 받아야 화질이 줄지 않는다.
 *  - 코어 함수 `contentSrcsetWithin` 이 없거나, 서빙 경로가 아니거나(외부·CDN 직접 주소), 원본 가로를 모르면 undefined — 호출자는
 *    srcset 을 붙이지 않고 원본 하나를 쓴다. 템플릿 본문 이미지(`contentImages.ts`)와 같은 정책이고, 후보를 여기서 거르지 않는다.
 *
 * PC·모바일 이미지는 기기별로 따로 올린 각자의 원본이라 각자 이 함수를 부른다(각자의 가로로 후보가 정해진다).
 *
 * 공개 자산 디스크: 응답의 `direct_url` 은 원본 한 장의 직접 주소(public·CDN)다. 축소 후보는 언제나 공개 API 주소
 * (`url?size=`, 서버가 공개 썸네일 디스크로 302)로 만들고, **마지막 원본 후보만** 직접 주소로 바꾼다 — 고해상도 화면이 고르는
 * 원본이 PHP 를 거치지 않게. 같은 출처가 필요한 자리(캔버스 색 추출 등)는 계속 `url` 을 쓴다.
 */

/** `direct_url` 을 가질 수 있는 미디어 한 장 */
export interface DirectMedia {
  /** 공개 API 주소 (썸네일 기준) */
  url?: string | null;

  /** 원본 직접 주소 (공개 자산 디스크가 아니면 null) */
  direct_url?: string | null;
}

/**
 * 원본 한 장을 그릴 주소 — 직접 주소가 있으면 그것, 없으면 공개 API 주소.
 *
 * @param media 미디어
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

/** 슬라이더 상자(`section.hs-slider`)의 배치 크기(px) */
export interface SliderBox {
  width: number;
  height: number;
}

/**
 * 상자를 잴 수 없을 때의 슬라이더 이미지 sizes — 배너는 화면 폭을 넘지 않는다(본문 폭이면 그보다 좁다).
 * 넓은 화면에서 한 단계 큰 후보를 고를 수는 있어도 화질이 모자라지는 않는다.
 */
export const SLIDER_IMAGE_SIZES = '100vw';

/**
 * 슬라이더 이미지의 sizes — 실제로 그려지는 폭.
 *
 * 본문 폭(`settings.width = content`)일 때의 상자 폭은 확장점을 연 템플릿이 정해(예: 1920 화면의 본문 1216px) 플러그인 설정
 * 힌트(`slot_widths` 는 content·full 뿐)로는 모른다. 그래서 그려진 상자를 재어 준다. 이미지는 상자를 덮어(`object-fit: cover`)
 * 그리므로 이미지가 상자보다 가로로 길면 높이에 맞춰 늘어난 폭 `상자 높이 × 이미지 가로 ÷ 세로` 가 실제 폭이다(둘 중 큰 값).
 * 상자를 재지 못했으면(배치 전·숨김) 상한 `100vw` 다.
 *
 * @param box 잰 상자 크기 (못 쟀으면 null)
 * @param image 고른 주 이미지의 원본 크기
 * @returns sizes 값
 */
export function sliderImageSizes(box: SliderBox | null | undefined, image?: { width?: number | null; height?: number | null } | null): string {
  if (!box || !(box.width > 0)) {
    return SLIDER_IMAGE_SIZES;
  }

  let width = box.width;

  if (box.height > 0 && image?.width && image?.height && image.width > 0 && image.height > 0) {
    width = Math.max(width, (box.height * image.width) / image.height);
  }

  return `${Math.ceil(width)}px`;
}
