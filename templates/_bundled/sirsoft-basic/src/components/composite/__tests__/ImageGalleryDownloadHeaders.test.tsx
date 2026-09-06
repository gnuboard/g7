/**
 * ImageGallery 인증 다운로드의 비밀글 열람 토큰 동반 회귀 테스트
 *
 * 레이아웃의 globalHeaders 는 데이터소스와 apiCall 핸들러에만 적용되고, 코어
 * ApiClient(G7Core.api)를 직접 호출하는 이 경로는 그 배선을 타지 않는다. 그래서
 * 비밀번호로 비밀글을 연 사용자에게 화면은 다운로드 버튼을 내주는데(서버가
 * can_download=true 를 돌려준다) 실제 요청은 열람 사실을 증명하지 못해 403 이 된다.
 * 예외도 콘솔 오류도 남지 않고 다운로드만 조용히 실패한다.
 *
 * @scenario surface=image_gallery, transport=direct_api_client
 *
 * @effects gallery_download_carries_secret_view_token
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/**
 * ImageGallery 는 모듈 최상단에서 `const G7Core = window.G7Core` 로 참조를 캡처한다.
 * 그래서 전역을 세운 뒤 동적 import 해야 그 캡처가 테스트 mock 을 가리킨다 —
 * 정적 import 는 캡처가 undefined 로 굳어 요청 자체가 일어나지 않는다.
 */
async function loadDownload() {
  vi.resetModules();
  const mod = await import('../ImageGallery');

  return mod.executeImageDownload;
}

describe('executeImageDownload — 비밀글 열람 확인 토큰 동반', () => {
  let apiGetSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    apiGetSpy = vi.fn().mockResolvedValue(new Blob(['x']));
    (window as any).G7Core = {
      t: (key: string) => key,
      api: { get: apiGetSpy },
      toast: { error: vi.fn() },
    };
    (URL as any).createObjectURL = vi.fn().mockReturnValue('blob:mock');
    (URL as any).revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(vi.fn());
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  it('토큰이 있으면 인증 다운로드 요청 헤더에 실어 보낸다', async () => {
    (window as any).G7Core.state = {
      get: (key: string) => (key === '_global' ? { secretViewToken: 'tok-gallery-view' } : undefined),
    };

    const executeImageDownload = await loadDownload();

    await executeImageDownload({
      src: '/api/modules/sirsoft-board/boards/free/attachment/aaaaaaaaaaaa/preview',
      downloadUrl: '/api/modules/sirsoft-board/boards/free/attachment/aaaaaaaaaaaa',
      filename: 'a.jpg',
      downloadRequiresAuth: true,
    } as any);

    expect(apiGetSpy).toHaveBeenCalledWith(
      '/api/modules/sirsoft-board/boards/free/attachment/aaaaaaaaaaaa',
      {
        responseType: 'blob',
        headers: { 'X-Board-Secret-View-Token': 'tok-gallery-view' },
      },
    );
  });

  it('토큰이 없으면 헤더를 만들지 않는다 (빈 값 전송 금지)', async () => {
    (window as any).G7Core.state = { get: () => ({}) };

    const executeImageDownload = await loadDownload();

    await executeImageDownload({
      src: '/x',
      downloadUrl: '/api/modules/sirsoft-board/boards/free/attachment/bbbbbbbbbbbb',
      filename: 'b.jpg',
      downloadRequiresAuth: true,
    } as any);

    expect(apiGetSpy).toHaveBeenCalledWith(
      '/api/modules/sirsoft-board/boards/free/attachment/bbbbbbbbbbbb',
      { responseType: 'blob', headers: {} },
    );
  });
});
