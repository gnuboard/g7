/**
 * @file adminTools.test.ts
 * @description 관리자 설정 화면 도구 핸들러 — 주소 복사(클립보드·선택 복사 물러서기)와 브라우저 로그인 테스트 창(클릭 순간 빈 창 → 받은 주소로).
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { copyToClipboardHandler, openTestLoginHandler } from '../handlers/adminTools';

describe('adminTools 핸들러', () => {
  let post: ReturnType<typeof vi.fn>;
  let dispatch: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    post = vi.fn();
    dispatch = vi.fn();
    (window as any).G7Core = { api: { post }, dispatch, t: (key: string) => key };
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  /**
   * @scenario clipboard=available|unavailable
   * @effects copy_writes_and_toasts, copy_falls_back_to_selection
   */
  it('클립보드에 쓰고 성공 토스트를 띄우며, 클립보드가 없으면 선택 복사로 물러선다', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

    await expect(copyToClipboardHandler({ params: { text: 'https://site.test/cb' } })).resolves.toBe(true);
    expect(writeText).toHaveBeenCalledWith('https://site.test/cb');
    expect(dispatch).toHaveBeenCalledWith({ handler: 'toast', params: { type: 'success', message: 'sirsoft-social_login.admin.providers.copied' } });

    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    (document as any).execCommand = vi.fn().mockReturnValue(true);
    await expect(copyToClipboardHandler({ params: { text: 'x' } })).resolves.toBe(true);
    expect((document as any).execCommand).toHaveBeenCalledWith('copy');

    await expect(copyToClipboardHandler({ params: { text: '' } })).resolves.toBe(false);
  });

  /**
   * @scenario response=https|http_external|error
   * @effects test_login_opens_blank_then_navigates, test_login_refuses_unsafe_url, test_login_closes_on_failure
   */
  it('창을 먼저 비워 열고 받은 https 주소로 보낸다 — 안전하지 않은 주소·실패면 창을 닫는다', async () => {
    const popup = { opener: {}, location: { href: '' }, close: vi.fn() };
    const open = vi.spyOn(window, 'open').mockReturnValue(popup as any);

    post.mockResolvedValueOnce({ data: { authorize_url: 'https://kauth.kakao.com/oauth/authorize?state=s' } });
    await expect(openTestLoginHandler({ params: { provider: 'kakao' } })).resolves.toBe(true);
    expect(open).toHaveBeenCalledWith('', '_blank');
    expect(post).toHaveBeenCalledWith('/plugins/sirsoft-social_login/admin/providers/kakao/test-login');
    expect(popup.opener).toBeNull();
    expect(popup.location.href).toBe('https://kauth.kakao.com/oauth/authorize?state=s');

    post.mockResolvedValueOnce({ data: { authorize_url: 'http://evil.example/x' } });
    await expect(openTestLoginHandler({ params: { provider: 'kakao' } })).resolves.toBe(false);
    expect(popup.close).toHaveBeenCalledTimes(1);

    post.mockRejectedValueOnce(new Error('403'));
    await expect(openTestLoginHandler({ params: { provider: 'kakao' } })).resolves.toBe(false);
    expect(popup.close).toHaveBeenCalledTimes(2);

    await expect(openTestLoginHandler({ params: { provider: 'Bad Key' } })).resolves.toBe(false);
  });
});
