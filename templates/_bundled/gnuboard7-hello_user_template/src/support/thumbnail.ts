/**
 * 이미지 썸네일 주소 (템플릿 공용 어댑터).
 *
 * 판정(같은 출처 + 등록된 서빙 경로)과 쿼리 덧붙임 규칙은 코어 `G7Core.thumbnail` 한 곳이 갖는다. 템플릿
 * 번들은 코어와 별도 IIFE 라 import 할 수 없고, 규칙 사본을 두면 서명 URL 이 깨지거나 외부 주소에 `size` 가
 * 붙는 차집합이 생긴다. 그래서 이 파일은 이름만 옮기고, 코어가 그 API 를 내지 않는 환경(구버전 코어·단위
 * 시험)에서는 **주소를 바꾸지 않는다** — 원본이 나가는 것은 기존 동작과 같다.
 */

/** 코어 썸네일 API (없으면 null) */
function api(): any {
  const thumbnail = (globalThis as any)?.G7Core?.thumbnail;

  return thumbnail && typeof thumbnail.url === 'function' ? thumbnail : null;
}

/**
 * 주소에 규격을 붙입니다 (대상이 아니거나 규격이 비면 그대로).
 *
 * @param url 원 주소
 * @param preset 규격 이름
 * @returns 주소
 */
export function thumbUrl<T>(url: T, preset: string | null | undefined): T | string {
  const core = api();
  if (!core || typeof url !== 'string' || !preset) {
    return url;
  }

  const result = core.url(url, preset);

  return typeof result === 'string' ? result : url;
}

/**
 * 고정 규격의 1x·2x srcset (대상이 아니면 undefined — 속성을 붙이지 않는다).
 *
 * @param url 원 주소
 * @param preset 1x 규격 이름
 * @returns srcset 또는 undefined
 */
export function thumbSrcset(url: unknown, preset: string | null | undefined): string | undefined {
  const core = api();
  if (!core || typeof url !== 'string' || !preset || typeof core.srcset !== 'function') {
    return undefined;
  }

  const result = core.srcset(url, preset);

  return typeof result === 'string' && result !== '' ? result : undefined;
}

/**
 * 썸네일을 붙여도 되는 주소인지.
 *
 * @param url 주소
 * @returns 대상이면 true (코어 API 가 없으면 false)
 */
export function isThumbnailable(url: unknown): boolean {
  const core = api();

  return !!core && typeof core.isThumbnailable === 'function' && core.isThumbnailable(url) === true;
}

/**
 * 본문 이미지 srcset — 숫자 요청 대상 규격 전부를 `w` 기술자로 (대상이 아니면 undefined).
 *
 * @param url 원 주소
 * @returns srcset 또는 undefined
 */
export function contentSrcset(url: unknown): string | undefined {
  const core = api();
  if (!core || typeof core.contentSrcset !== 'function') {
    return undefined;
  }

  const result = core.contentSrcset(url);

  return typeof result === 'string' && result !== '' ? result : undefined;
}

/**
 * 본문 이미지 규격 이름 (`upload.thumbnail_content_preset`, 코어가 없으면 `lg`).
 *
 * @returns 규격 이름
 */
export function contentPreset(): string {
  const core = api();
  const result = core && typeof core.contentPreset === 'function' ? core.contentPreset() : null;

  return typeof result === 'string' && result !== '' ? result : 'lg';
}

/**
 * 썸네일 쿼리(`size`·`w`)를 걷어낸 원본 주소 — 확대 보기용.
 *
 * @param url 주소
 * @returns 원본 주소
 */
export function stripThumb<T>(url: T): T | string {
  const core = api();
  if (!core || typeof core.strip !== 'function' || typeof url !== 'string') {
    return url;
  }

  const result = core.strip(url);

  return typeof result === 'string' ? result : url;
}

/** 첨부 응답 중 이미지 주소 필드 */
export interface AttachmentImageUrls {
  /** 미리보기 주소 (게시판·페이지 첨부 — 서명이 붙어 있을 수 있다) */
  preview_url?: string | null;
  /** 다운로드 주소 */
  download_url?: string | null;
}

/**
 * 첨부 이미지를 화면에 그릴 원본 주소 — 미리보기 주소가 있으면 그것, 없으면 다운로드 주소.
 *
 * @param file 첨부
 * @returns 주소 (둘 다 없으면 undefined)
 */
export function attachmentImageSource(file: AttachmentImageUrls | null | undefined): string | undefined {
  const preview = typeof file?.preview_url === 'string' && file.preview_url !== '' ? file.preview_url : undefined;
  const download = typeof file?.download_url === 'string' && file.download_url !== '' ? file.download_url : undefined;

  return preview ?? download;
}

/**
 * 첨부 이미지의 썸네일 주소 — 그릴 주소가 서빙 경로면 규격을 붙이고, 아니면 undefined(호출자는 종전 경로로 받는다).
 *
 * 업로더의 기존 첨부 칸은 이 주소를 `<img>` 로 바로 그린다. 종전에는 다운로드 주소의 원본을 인증 요청으로 받아
 * blob 으로 그렸다(칸마다 원본 한 장, basic 은 두 번).
 *
 * @param file 첨부
 * @param preset 규격 이름
 * @returns 썸네일 주소 또는 undefined
 */
export function attachmentThumbUrl(file: AttachmentImageUrls | null | undefined, preset: string): string | undefined {
  const source = attachmentImageSource(file);
  if (source === undefined || !isThumbnailable(source)) {
    return undefined;
  }

  const result = thumbUrl(source, preset);

  return typeof result === 'string' && result !== source ? result : undefined;
}
