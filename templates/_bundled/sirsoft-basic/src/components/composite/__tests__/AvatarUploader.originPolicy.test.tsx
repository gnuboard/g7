/**
 * AvatarUploader 업로드·삭제 요청 대상 출처 테스트 (KVE-2026-2350)
 *
 * `uploadEndpoint`·`deleteEndpoint` 는 레이아웃 prop 이라 편집 권한만 있는 계정이 표현식으로 외부 주소를
 * 넣을 수 있다. 회원이 마이페이지에서 프로필 사진을 올리거나 지우면 회원 토큰이 그 주소로 갔다.
 * 토큰은 동일 출처 대상에만 싣는다.
 */
import { describe, it, expect, vi, beforeAll, beforeEach, afterAll } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import type { AvatarUploader as AvatarUploaderType } from '../AvatarUploader';

let AvatarUploader: typeof AvatarUploaderType;
let requests: Array<{ url: string; method: string; headers: Record<string, string> }>;

const offsite = ['https://evil.example/avatar', '//evil.example/avatar', '/\\evil.example/avatar'];

describe('AvatarUploader — 업로드·삭제 요청 대상 출처', () => {
  beforeAll(async () => {
    // 컴포넌트가 모듈 로드 시점에 G7Core 를 읽으므로 먼저 세운다
    (window as any).G7Core = {
      api: { getToken: () => 'MEMBER-TOKEN' },
      t: (key: string) => key,
      toast: { success: vi.fn(), error: vi.fn() },
      refetchDataSource: vi.fn(),
      dispatch: vi.fn(),
    };
    ({ AvatarUploader } = await import('../AvatarUploader'));
  });

  afterAll(() => {
    delete (window as any).G7Core;
  });

  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=xsrf-value; path=/';
    requests = [];
    global.fetch = vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
      requests.push({
        url: String(url),
        method: String(init?.method),
        headers: (init?.headers ?? {}) as Record<string, string>,
      });
      return { ok: true, json: async () => ({ data: {} }) } as Response;
    }) as unknown as typeof fetch;
  });

  const upload = async (uploadEndpoint?: string) => {
    const { container, unmount } = render(
      <AvatarUploader confirmUpload={false} {...(uploadEndpoint ? { uploadEndpoint } : {})} />
    );
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['x'], 'a.png', { type: 'image/png' })] } });
    await waitFor(() => expect(requests.length).toBeGreaterThan(0));
    unmount();
  };

  const remove = async (deleteEndpoint?: string) => {
    const { unmount } = render(
      <AvatarUploader
        src="/storage/avatar.png"
        confirmDelete={false}
        deleteButtonText="DEL"
        {...(deleteEndpoint ? { deleteEndpoint } : {})}
      />
    );
    fireEvent.click(screen.getByText('DEL'));
    await waitFor(() => expect(requests.length).toBeGreaterThan(0));
    unmount();
  };

  /**
   * @effects extension_token_same_origin_only
   */
  it('교차 출처 업로드·삭제 주소에는 Bearer·XSRF 를 싣지 않는다', async () => {
    // @scenario sink=avatar_upload, target=cross_origin
    // @scenario sink=avatar_delete, target=cross_origin
    for (const url of offsite) {
      requests = [];
      await upload(url);
      await remove(url);

      expect(requests.map(r => [r.method, r.url])).toEqual([['POST', url], ['DELETE', url]]);
      for (const request of requests) {
        expect(request.headers.Authorization).toBeUndefined();
        expect(request.headers['X-XSRF-TOKEN']).toBeUndefined();
      }
    }
  });

  /**
   * @effects extension_token_same_origin_only
   */
  it('동일 출처 업로드·삭제 주소는 종전대로 Bearer·XSRF 를 싣는다 (정상 회귀)', async () => {
    // @scenario sink=avatar_upload, target=same_origin
    // @scenario sink=avatar_delete, target=same_origin
    await upload();
    await remove();

    expect(requests.map(r => [r.method, r.url])).toEqual([['POST', '/api/me/avatar'], ['DELETE', '/api/me/avatar']]);
    for (const request of requests) {
      expect(request.headers.Authorization).toBe('Bearer MEMBER-TOKEN');
      expect(request.headers['X-XSRF-TOKEN']).toBe('xsrf-value');
    }
  });
});
