/**
 * @file downloadAttachment.post.test.ts
 * @description 첨부 다운로드 핸들러의 POST 본문 경로 (이슈 #682 S3)
 *
 * 방문자 통계 정보주체 내보내기(`POST /api/admin/analytics/privacy/subjects/export`)는 서버가 파일을 POST 응답으로
 * 흘려보낸다. `method: 'POST'` 를 주면 코어 ApiClient 의 post 로 본문을 실어 blob 을 받고, 생략하면 종전대로 GET 이다
 * (기존 게시판 첨부 호출은 그대로 동작해야 한다).
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { downloadAttachmentHandler } from '../downloadAttachment';

describe('downloadAttachmentHandler — POST 본문 경로', () => {
  let apiGetSpy: ReturnType<typeof vi.fn>;
  let apiPostSpy: ReturnType<typeof vi.fn>;
  let clickSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    apiGetSpy = vi.fn().mockResolvedValue(new Blob(['file-content']));
    apiPostSpy = vi.fn().mockResolvedValue(new Blob(['{"visits":[]}']));
    (window as any).G7Core = {
      api: { get: apiGetSpy, post: apiPostSpy },
      toast: { error: vi.fn() },
      createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
    };
    (URL as any).createObjectURL = vi.fn().mockReturnValue('blob:mock-object-url');
    (URL as any).revokeObjectURL = vi.fn();
    clickSpy = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(clickSpy);
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  // @scenario screen=privacy_requests, state=normal, viewport=1440, locale=ko
  // @effects download_handler_posts_body_for_blob
  it('method:POST 이면 api.post 로 본문을 실어 blob 을 받고 파일로 저장한다', async () => {
    await downloadAttachmentHandler({
      params: {
        url: '/api/admin/analytics/privacy/subjects/export',
        method: 'POST',
        body: { ip: '203.0.113.9' },
        filename: 'analytics-subject.json',
      },
    });

    expect(apiPostSpy).toHaveBeenCalledTimes(1);
    expect(apiPostSpy).toHaveBeenCalledWith(
      '/api/admin/analytics/privacy/subjects/export',
      { ip: '203.0.113.9' },
      { responseType: 'blob' },
    );
    expect(apiGetSpy).not.toHaveBeenCalled();
    expect(clickSpy).toHaveBeenCalledTimes(1);
  });

  // @scenario screen=component, state=normal, viewport=1440, locale=ko
  // @effects download_handler_defaults_to_get
  it('method 를 생략하면 종전대로 GET 이고 소문자 post 도 POST 로 본다', async () => {
    await downloadAttachmentHandler({ params: { url: '/d/a.pdf', filename: 'a.pdf' } });
    expect(apiGetSpy).toHaveBeenCalledWith('/d/a.pdf', { responseType: 'blob' });
    expect(apiPostSpy).not.toHaveBeenCalled();

    await downloadAttachmentHandler({ params: { url: '/d/b.json', method: 'post', filename: 'b.json' } });
    // 본문을 주지 않으면 빈 객체를 보낸다 — undefined 를 넘기면 서버가 JSON 본문을 받지 못한다.
    expect(apiPostSpy).toHaveBeenCalledWith('/d/b.json', {}, { responseType: 'blob' });
  });
});
