/**
 * @file downloadAttachment.test.ts
 * @description 게시판 첨부 다운로드 핸들러 단위 검증 (이슈 #413 item 58b)
 *
 * 배경: 기존 다운로드는 레이아웃의 <a href="{{download_url}}"> 브라우저 직접 링크(GET)였다.
 * G7 은 Sanctum 토큰 전용 인증인데 <a> 네비게이션에는 토큰이 실리지 않아
 * optional.sanctum 라우트가 guest 로 통과 → 활동이력 행위자(user_id)가 NULL 로 남았다.
 *
 * 이 핸들러는 ImageGallery.downloadAuthenticatedFile 패턴을 재사용해
 * G7Core.api.get(url, { responseType: 'blob' }) 로 토큰을 동반한 요청을 보낸다.
 * 핵심은 "코어 ApiClient(api.get)를 통해 요청해야 토큰이 실린다" 이므로
 * 실제 호출 인자를 단언한다(거짓 통과 방지).
 *
 * @scenario card=user_post
 * @effects download_via_api_client_with_token,filename_preserved,download_failure_shows_error_toast,download_carries_secret_view_token
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { downloadAttachmentHandler } from '../downloadAttachment';

describe('downloadAttachmentHandler — 토큰 동반 첨부 다운로드 (이슈 #413 item 58b)', () => {
  let apiGetSpy: ReturnType<typeof vi.fn>;
  let createObjectURLSpy: ReturnType<typeof vi.fn>;
  let revokeObjectURLSpy: ReturnType<typeof vi.fn>;
  let clickSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    apiGetSpy = vi.fn().mockResolvedValue(new Blob(['file-content']));
    (window as any).G7Core = {
      api: { get: apiGetSpy },
      toast: { error: vi.fn() },
      createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
    };

    createObjectURLSpy = vi.fn().mockReturnValue('blob:mock-object-url');
    revokeObjectURLSpy = vi.fn();
    (URL as any).createObjectURL = createObjectURLSpy;
    (URL as any).revokeObjectURL = revokeObjectURLSpy;

    // <a> 클릭이 jsdom 에서 네비게이션을 시도하지 않도록 click 만 가로챈다.
    clickSpy = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(clickSpy);
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  it('코어 ApiClient(api.get)로 responseType:blob 요청을 보낸다 (토큰 경로 보장)', async () => {
    await downloadAttachmentHandler({
      params: {
        url: '/api/modules/sirsoft-board/boards/notice/attachment/abc123',
        filename: '보고서.pdf',
      },
    });

    expect(apiGetSpy).toHaveBeenCalledTimes(1);
    expect(apiGetSpy).toHaveBeenCalledWith(
      '/api/modules/sirsoft-board/boards/notice/attachment/abc123',
      { responseType: 'blob', headers: {} },
    );
  });

  it('비밀글 열람 확인 토큰이 있으면 요청 헤더에 실어 보낸다', async () => {
    (window as any).G7Core.state = {
      get: (key: string) => (key === '_global' ? { secretViewToken: 'tok-secret-view-40' } : undefined),
    };

    await downloadAttachmentHandler({
      params: { url: '/api/modules/sirsoft-board/boards/notice/attachment/abc123', filename: 'a.pdf' },
    });

    expect(apiGetSpy).toHaveBeenCalledWith(
      '/api/modules/sirsoft-board/boards/notice/attachment/abc123',
      {
        responseType: 'blob',
        headers: { 'X-Board-Secret-View-Token': 'tok-secret-view-40' },
      },
    );
  });

  it('토큰이 없으면 헤더를 만들지 않는다 (빈 값 전송 금지)', async () => {
    (window as any).G7Core.state = { get: () => ({}) };

    await downloadAttachmentHandler({ params: { url: '/d/x', filename: 'a.pdf' } });

    expect(apiGetSpy).toHaveBeenCalledWith('/d/x', { responseType: 'blob', headers: {} });
  });

  it('받은 blob 을 objectURL 로 변환해 filename 으로 다운로드하고 revoke 한다', async () => {
    await downloadAttachmentHandler({
      params: { url: '/d/x', filename: '보고서.pdf' },
    });

    expect(createObjectURLSpy).toHaveBeenCalledTimes(1);
    expect(clickSpy).toHaveBeenCalledTimes(1);
    expect(revokeObjectURLSpy).toHaveBeenCalledWith('blob:mock-object-url');
  });

  it('url 이 없으면 api 호출 없이 조용히 반환한다', async () => {
    await downloadAttachmentHandler({ params: { filename: 'x.pdf' } });

    expect(apiGetSpy).not.toHaveBeenCalled();
  });

  it('다운로드 실패 시 throw 하지 않고 에러 토스트를 띄운다', async () => {
    apiGetSpy.mockRejectedValueOnce(new Error('network'));

    await expect(
      downloadAttachmentHandler({ params: { url: '/d/x', filename: 'x.pdf' } }),
    ).resolves.toBeUndefined();

    expect((window as any).G7Core.toast.error).toHaveBeenCalledTimes(1);
  });
});
