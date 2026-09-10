/**
 * layoutAttachments.test.ts — 레이아웃 첨부 API 클라이언트 단위 테스트
 *
 * 서버가 돌려주는 `url` 은 설정에 따라 두 형태다 — 공개 서빙 라우트(프록시) 상대 경로,
 * 또는 공개 자산 디스크(CDN)의 교차 출처 절대 URL (공개 #134). 클라이언트는 형태를
 * 해석하거나 가공하지 않고 **서버가 준 문자열을 그대로 보존**해야 한다. 여기서 상대
 * 경로를 가정해 접두사를 붙이거나 origin 을 붙이면 CDN 주소가 깨진다.
 *
 * @scenario public_asset_disk=public,row_disk=public,filter_url_hook=absent
 *
 * @effects direct_url_when_row_matches_public_disk, proxy_url_otherwise
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  listLayoutAttachments,
  uploadLayoutAttachment,
  deleteLayoutAttachment,
} from '../../utils/layoutAttachments';

const PROXY_URL = '/api/templates/sirsoft-basic/layout-attachments/1/file';
const CDN_URL = 'https://cdn.example.test/template-layout-attachments/sirsoft-basic/bg.png';

function jsonResponse(body: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  } as unknown as Response;
}

function attachment(url: string) {
  return {
    id: 1,
    layout_name: 'home',
    original_name: 'bg.png',
    mime_type: 'image/png',
    size: 178,
    url,
  };
}

describe('layoutAttachments — 첨부 API 클라이언트', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
    // buildAuthHeaders 가 읽는 저장소 — 없어도 동작해야 하지만 명시해 둔다
    try {
      localStorage.setItem('auth_token', 'test-token');
    } catch {
      /* 저장소 미지원 환경 무시 */
    }
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('목록 응답의 프록시 URL 을 가공 없이 보존한다', async () => {
    (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, data: [attachment(PROXY_URL)] }),
    );

    const result = await listLayoutAttachments('sirsoft-basic', 'home');

    expect(result.ok).toBe(true);
    if (result.ok) expect(result.data[0].url).toBe(PROXY_URL);
  });

  it('목록 응답의 교차 출처 CDN 절대 URL 을 가공 없이 보존한다', async () => {
    (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, data: [attachment(CDN_URL)] }),
    );

    const result = await listLayoutAttachments('sirsoft-basic', 'home');

    expect(result.ok).toBe(true);
    if (result.ok) expect(result.data[0].url).toBe(CDN_URL);
  });

  it('업로드 응답의 CDN 절대 URL 을 가공 없이 보존한다', async () => {
    (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, data: attachment(CDN_URL) }),
    );

    const file = new File(['x'], 'bg.png', { type: 'image/png' });
    const result = await uploadLayoutAttachment('sirsoft-basic', 'home', file);

    expect(result.ok).toBe(true);
    if (result.ok) expect(result.data.url).toBe(CDN_URL);
  });

  it('업로드 응답에 url 이 없으면 실패로 처리한다', async () => {
    (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, data: { id: 1 } }),
    );

    const file = new File(['x'], 'bg.png', { type: 'image/png' });
    const result = await uploadLayoutAttachment('sirsoft-basic', 'home', file);

    expect(result.ok).toBe(false);
  });

  it('레이아웃 이름의 slash 를 query 로 인코딩한다', async () => {
    (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true, data: [] }),
    );

    await listLayoutAttachments('sirsoft-basic', 'auth/login');

    const calledUrl = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0][0] as string;
    expect(calledUrl).toContain('layout_name=auth%2Flogin');
  });

  it('삭제는 첨부 id 경로로 DELETE 를 보낸다', async () => {
    (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ success: true }),
    );

    const result = await deleteLayoutAttachment(1);

    expect(result.ok).toBe(true);
    const [calledUrl, init] = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(calledUrl).toBe('/api/admin/templates/layout-attachments/1');
    expect((init as RequestInit).method).toBe('DELETE');
  });

  it('네트워크 실패를 상태 0 의 에러로 돌려준다', async () => {
    (fetch as unknown as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('boom'));

    const result = await listLayoutAttachments('sirsoft-basic', 'home');

    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.status).toBe(0);
  });
});
