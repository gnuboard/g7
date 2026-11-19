/**
 * 이미지 썸네일 경로 실측 도우미 (이슈 #869).
 *
 * 이미지 서빙 주소에 `?size={규격}`·`?w={숫자}` 를 붙이면 서버가 규격대로 파생본을 만들어 내준다.
 * 응답 머리 `X-G7-Thumbnail: hit | miss | bypass:{사유}` 로 결과를 알리고, 공개 썸네일 디스크가 CDN 계열
 * (공개 주소를 가진 디스크)이면 파생본 주소로 302 를 보낸다. 이 도우미는 그 응답을 한 번에 읽는다:
 *
 *   - 302 는 따라가지 않고 그 응답의 머리(`X-G7-Thumbnail`·`Cache-Control`)를 먼저 읽은 뒤 `Location` 의 본문을 받는다.
 *     Playwright 기본값(리다이렉트 자동 추적)으로 받으면 썸네일 머리가 사라져 hit/miss 를 잴 수 없다.
 *   - 본문 바이트에서 그림 크기를 직접 읽는다(PNG·GIF·JPEG·WebP 머리). 브라우저 디코딩에 기대지 않으므로
 *     권한 주소(서명·토큰)도 같은 방식으로 잴 수 있다.
 *
 * 코어와 확장 spec 이 함께 쓴다 — 확장은 `../../../../../../tests/Playwright/fixtures/image-thumbnail` 으로 가져온다.
 */
import { expect, type APIRequestContext } from '@playwright/test';

/** 썸네일 응답 한 건의 측정값 */
export type ThumbnailProbe = {
  /** 첫 응답 상태 (302 이면 302) */
  status: number;
  /** `X-G7-Thumbnail` 값 (없으면 null) */
  thumbnail: string | null;
  /** 첫 응답의 `Cache-Control` */
  cacheControl: string | null;
  /** 첫 응답의 `Content-Disposition` */
  disposition: string | null;
  /** 첫 응답의 `X-Content-Type-Options` (파일 응답 보강 — `nosniff`) */
  nosniff: string | null;
  /** 302 의 `Location` (302 가 아니면 null) */
  location: string | null;
  /** 본문을 낸 응답의 `Content-Type` */
  contentType: string | null;
  /** 본문 (302 면 Location 의 본문) */
  body: Buffer;
  /** 본문에서 읽은 그림 크기 (그림이 아니면 null) */
  dimensions: { width: number; height: number } | null;
};

/**
 * 그림 바이트의 가로·세로를 머리에서 읽는다.
 *
 * @param buf 파일 바이트
 * @returns 크기, 모르는 형식이면 null
 */
export function imageDimensions(buf: Buffer): { width: number; height: number } | null {
  if (buf.length < 24) return null;

  // PNG — 시그니처 8바이트 뒤 IHDR 의 가로·세로(빅엔디언)
  if (buf.readUInt32BE(0) === 0x89504e47) {
    return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
  }

  // GIF — 논리 화면 크기(리틀엔디언)
  if (buf.toString('ascii', 0, 3) === 'GIF') {
    return { width: buf.readUInt16LE(6), height: buf.readUInt16LE(8) };
  }

  // WebP — RIFF....WEBP 뒤 청크 종류별
  if (buf.toString('ascii', 0, 4) === 'RIFF' && buf.toString('ascii', 8, 12) === 'WEBP') {
    const chunk = buf.toString('ascii', 12, 16);
    if (chunk === 'VP8X') {
      return { width: 1 + buf.readUIntLE(24, 3), height: 1 + buf.readUIntLE(27, 3) };
    }
    if (chunk === 'VP8L') {
      const bits = buf.readUInt32LE(21);
      return { width: 1 + (bits & 0x3fff), height: 1 + ((bits >> 14) & 0x3fff) };
    }
    if (chunk === 'VP8 ') {
      return { width: buf.readUInt16LE(26) & 0x3fff, height: buf.readUInt16LE(28) & 0x3fff };
    }
    return null;
  }

  // JPEG — SOFn 마커(C0~CF, C4·C8·CC 제외)의 세로·가로
  if (buf[0] === 0xff && buf[1] === 0xd8) {
    let offset = 2;
    while (offset + 9 < buf.length) {
      if (buf[offset] !== 0xff) {
        offset += 1;
        continue;
      }
      const marker = buf[offset + 1];
      if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
        offset += 2;
        continue;
      }
      const length = buf.readUInt16BE(offset + 2);
      if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
        return { width: buf.readUInt16BE(offset + 7), height: buf.readUInt16BE(offset + 5) };
      }
      offset += 2 + length;
    }
  }

  return null;
}

/**
 * 썸네일 주소 한 건을 받아 머리와 그림 크기를 잰다.
 *
 * @param request Playwright API 요청 컨텍스트 (baseURL 을 쓴다)
 * @param url 서빙 주소 (상대 경로 가능)
 * @param headers 추가 요청 머리 (예: Authorization)
 * @returns 측정값
 */
export async function probeThumbnail(
  request: APIRequestContext,
  url: string,
  headers: Record<string, string> = {},
): Promise<ThumbnailProbe> {
  const first = await request.get(url, { headers, maxRedirects: 0, failOnStatusCode: false });
  const h = first.headers();
  let bodyResponse = first;
  let location: string | null = null;

  if (first.status() === 302) {
    location = h['location'] ?? null;
    if (location) {
      bodyResponse = await request.get(location, { failOnStatusCode: false });
    }
  }

  const body = await bodyResponse.body();

  return {
    status: first.status(),
    thumbnail: h['x-g7-thumbnail'] ?? null,
    cacheControl: h['cache-control'] ?? null,
    disposition: h['content-disposition'] ?? null,
    nosniff: h['x-content-type-options'] ?? null,
    location,
    contentType: bodyResponse.headers()['content-type'] ?? null,
    body,
    dimensions: imageDimensions(body),
  };
}

/**
 * 주소에 썸네일 질의를 붙인다 (이미 질의가 있으면 `&`).
 *
 * @param url 원 주소
 * @param query `size=sm` 같은 질의 조각
 * @returns 붙인 주소
 */
export function withThumbnailQuery(url: string, query: string): string {
  return url + (url.includes('?') ? '&' : '?') + query;
}

/**
 * 같은 주소를 두 번 받아 「첫 요청 miss → 다음 요청 hit」 을 잰다.
 *
 * 처음 만들어지는 원본(이번 실행이 올린 첨부, 방금 넣은 시드)은 첫 요청이 반드시 miss 다.
 * 이전 실행이 이미 파생본을 만들어 둔 원본(다시 넣지 않은 시드)은 첫 요청부터 hit 일 수 있어, 첫 응답을
 * 어디까지 엄격히 볼지는 호출처가 정한다. 둘째 응답은 어느 쪽이든 hit 여야 한다.
 *
 * @param request API 요청 컨텍스트
 * @param url 썸네일 주소
 * @param headers 추가 요청 머리
 * @returns [첫 응답, 둘째 응답]
 */
export async function probeTwice(
  request: APIRequestContext,
  url: string,
  headers: Record<string, string> = {},
): Promise<[ThumbnailProbe, ThumbnailProbe]> {
  const first = await probeThumbnail(request, url, headers);
  const second = await probeThumbnail(request, url, headers);

  return [first, second];
}

/** 관리자 설정의 썸네일 규격 한 줄 (`GET /api/admin/settings` 의 `data.upload.thumbnail_presets`) */
export type ThumbnailPreset = {
  name: string;
  width: number;
  height: number | null;
  fit: string;
  numeric?: boolean;
  enabled?: boolean;
  upscale?: boolean;
};

/** 썸네일에 관한 현재 설정 */
export type ThumbnailSettings = {
  /** 규격 이름 → 규격 */
  presets: Record<string, ThumbnailPreset>;
  /** 움직이는 이미지는 원본 사용 (`upload.thumbnail_animated_original`) */
  animatedOriginal: boolean;
};

/**
 * 관리자 설정 API 에서 규격과 썸네일 설정을 읽는다 — 운영자가 규격을 바꿔도 spec 이 그 값을 기대한다.
 *
 * @param request API 요청 컨텍스트
 * @param token 설정 읽기 권한이 있는 토큰
 * @returns 현재 설정
 */
export async function loadThumbnailSettings(request: APIRequestContext, token: string): Promise<ThumbnailSettings> {
  const res = await request.get('/api/admin/settings', {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(res.status(), '관리자 설정 조회').toBe(200);
  const upload = (await res.json()).data?.upload ?? {};
  const list: ThumbnailPreset[] = upload.thumbnail_presets ?? [];
  const presets = Object.fromEntries(list.map((p) => [p.name, p]));
  for (const name of ['xs', 'sm', 'md', 'lg', 'xl']) {
    expect(presets[name], `고정 규격 ${name} 이 설정에 없다`).toBeTruthy();
  }

  return { presets, animatedOriginal: upload.thumbnail_animated_original !== false };
}

/**
 * 원본 크기에 규격을 적용한 파생본 크기를 계산한다 (cover·contain 은 규격 상자, width 는 가로 맞춤 비율 유지).
 *
 * @param preset 규격
 * @param source 원본 크기
 * @returns 기대 크기
 */
export function expectedDimensions(preset: ThumbnailPreset, source: { width: number; height: number }): { width: number; height: number } {
  if (preset.fit === 'width' || preset.height === null) {
    return { width: preset.width, height: Math.round((source.height * preset.width) / source.width) };
  }
  if (preset.fit === 'contain') {
    const ratio = Math.min(preset.width / source.width, (preset.height as number) / source.height);
    return { width: Math.round(source.width * ratio), height: Math.round(source.height * ratio) };
  }

  return { width: preset.width, height: preset.height as number };
}

/**
 * `?w={숫자}` 가 고를 규격 — 숫자 요청을 받는 켜진 가로 맞춤 규격 중 가로가 w 이상인 가장 작은 것, 없으면 가장 큰 것.
 *
 * @param presets 규격 목록
 * @param w 요청 폭
 * @returns 규격
 */
export function presetForWidth(presets: Record<string, ThumbnailPreset>, w: number): ThumbnailPreset {
  const numeric = Object.values(presets)
    .filter((p) => p.numeric && p.enabled !== false)
    .sort((a, b) => a.width - b.width);
  expect(numeric.length, '숫자 요청을 받는 규격이 없다').toBeGreaterThan(0);

  return numeric.find((p) => p.width >= w) ?? numeric[numeric.length - 1];
}
