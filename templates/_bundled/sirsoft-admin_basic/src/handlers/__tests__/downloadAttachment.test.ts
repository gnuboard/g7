/**
 * @file downloadAttachment.test.ts
 * @description 관리자 게시판 첨부 다운로드 핸들러 단위 검증 (이슈 #413 item 58b)
 *
 * 배경: 관리자 게시글/답변 첨부 카드도 사용자 라우트(optional.sanctum)의 download_url 을
 * <a href> 직접 링크로 호출해, 토큰 미동반으로 활동이력 행위자(user_id)가 NULL 로 남았다.
 * 이 핸들러는 G7Core.api.get(url, { responseType: 'blob' }) 로 토큰을 동반해
 * 관리자 회원 ID 가 활동이력에 정상 기록되도록 한다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */

import { describe, it, expect, vi, beforeEach, afterEach, type Mock } from 'vitest';
import { downloadAttachmentHandler } from '../downloadAttachment';

describe('downloadAttachmentHandler (admin) — 토큰 동반 첨부 다운로드 (이슈 #413 item 58b)', () => {
  let apiGetSpy: ReturnType<typeof vi.fn>;
  let createObjectURLSpy: ReturnType<typeof vi.fn>;
  let revokeObjectURLSpy: ReturnType<typeof vi.fn>;
  let clickSpy: Mock<() => void>;

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

    clickSpy = vi.fn<() => void>();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(clickSpy);
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  // @scenario card=admin_post
  // @effects download_via_api_client_with_token
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
      { responseType: 'blob' },
    );
  });

  // @scenario card=admin_post
  // @effects filename_preserved
  it('받은 blob 을 objectURL 로 변환해 filename 으로 다운로드하고 revoke 한다', async () => {
    await downloadAttachmentHandler({
      params: { url: '/d/x', filename: '보고서.pdf' },
    });

    expect(createObjectURLSpy).toHaveBeenCalledTimes(1);
    expect(clickSpy).toHaveBeenCalledTimes(1);
    expect(revokeObjectURLSpy).toHaveBeenCalledWith('blob:mock-object-url');
  });

  // @scenario card=admin_post
  // @effects download_skips_request_without_url
  it('url 이 없으면 api 호출 없이 조용히 반환한다', async () => {
    await downloadAttachmentHandler({ params: { filename: 'x.pdf' } });

    expect(apiGetSpy).not.toHaveBeenCalled();
  });

  // @scenario card=admin_post
  // @effects download_failure_shows_error_toast
  it('다운로드 실패 시 throw 하지 않고 에러 토스트를 띄운다', async () => {
    apiGetSpy.mockRejectedValueOnce(new Error('network'));

    await expect(
      downloadAttachmentHandler({ params: { url: '/d/x', filename: 'x.pdf' } }),
    ).resolves.toBeUndefined();

    expect((window as any).G7Core.toast.error).toHaveBeenCalledTimes(1);
  });
});
