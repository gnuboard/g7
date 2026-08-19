/**
 * 공개 자산(S3/CDN) 교차 출처 URL 의 인증 요청 차단 회귀 테스트
 *
 * 공개 자산 스토리지를 켜면 첨부·이미지의 `download_url` 이 외부 origin 절대 URL 이 된다.
 * 그 URL 을 인증 XHR(Blob)로 가져오면
 *   ① CORS 헤더를 주지 않는 CDN(기본 설정 S3 버킷 등)에서 이미지가 통째로 실패하고
 *   ② 응답을 읽을 수 있는 CDN 이라면 세션 토큰이 제3자 origin 으로 전송된다.
 * 두 경로 모두 브라우저 실측으로 확인된 결함이므로, 교차 출처 URL 은
 * 인증 요청 없이 그대로 사용해야 한다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import React from 'react';

import { isCrossOriginAssetUrl } from '../../src/components/composite/assetOrigin';
import { SortableThumbnailItem } from '../../src/components/composite/FileUploader/SortableThumbnailItem';

const apiGet = vi.fn();

beforeEach(() => {
  apiGet.mockReset();
  apiGet.mockResolvedValue(new Blob(['x'], { type: 'image/png' }));
  // 전역 G7Core 객체는 setup 이 만든 것을 그대로 쓰고 api 만 주입한다
  // (컴포넌트가 모듈 로드 시점에 같은 객체 참조를 캡처하므로 교체하면 안 된다)
  (window as any).G7Core.api = { get: apiGet };
  (window as any).URL.createObjectURL = vi.fn(() => 'blob:mock-object-url');
  (window as any).URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  vi.clearAllMocks();
});

describe('isCrossOriginAssetUrl', () => {
  it('상대 경로(API 스트리밍)는 동일 출처로 판정한다', () => {
    expect(isCrossOriginAssetUrl('/api/modules/sirsoft-ecommerce/product-image/abc')).toBe(false);
  });

  it('동일 출처 절대 URL 은 동일 출처로 판정한다', () => {
    expect(isCrossOriginAssetUrl(`${window.location.origin}/storage/a.png`)).toBe(false);
  });

  it('외부 CDN 절대 URL 은 교차 출처로 판정한다', () => {
    expect(isCrossOriginAssetUrl('https://bucket.s3.ap-southeast-2.amazonaws.com/a.png')).toBe(true);
  });

  it('프로토콜 상대 URL 도 호스트가 다르면 교차 출처로 판정한다', () => {
    expect(isCrossOriginAssetUrl('//cdn.example.com/a.png')).toBe(true);
  });

  it('빈 값은 동일 출처로 간주해 기존 경로를 유지한다', () => {
    expect(isCrossOriginAssetUrl(undefined)).toBe(false);
    expect(isCrossOriginAssetUrl('')).toBe(false);
  });
});

/**
 * 썸네일 항목 렌더용 첨부 픽스처를 만듭니다.
 *
 * @param downloadUrl 첨부의 download_url
 * @returns 첨부 객체
 */
const makeAttachment = (downloadUrl: string) => ({
  id: 1,
  hash: 'h1',
  original_filename: 'probe.png',
  size: 100,
  mime_type: 'image/png',
  is_image: true,
  download_url: downloadUrl,
});

// @scenario consumer=product, disk_setting=fake_cdn, e2e=drivers_tab_card, hook=unregistered, override=follow_core, row_state=legacy_local_row
// @effects cross_origin_asset_request_omits_session_token
describe('SortableThumbnailItem 의 공개 자산 URL 처리', () => {
  it('동일 출처 URL 은 종전대로 인증 요청으로 이미지를 로드한다', async () => {
    render(
      <SortableThumbnailItem
        file={makeAttachment('/api/modules/sirsoft-ecommerce/product-image/abc') as any}
        onRemove={vi.fn()}
      />
    );

    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(1));
    expect(apiGet).toHaveBeenCalledWith(
      '/api/modules/sirsoft-ecommerce/product-image/abc',
      { responseType: 'blob' }
    );
  });

  it('교차 출처 공개 자산 URL 은 인증 요청 없이 그대로 사용한다', async () => {
    const cdnUrl = 'https://bucket.s3.ap-southeast-2.amazonaws.com/images/products/a.png';

    render(<SortableThumbnailItem file={makeAttachment(cdnUrl) as any} onRemove={vi.fn()} />);

    await waitFor(() => {
      expect(screen.getByRole('img')).toHaveAttribute('src', cdnUrl);
    });
    expect(apiGet).not.toHaveBeenCalled();
  });
});
