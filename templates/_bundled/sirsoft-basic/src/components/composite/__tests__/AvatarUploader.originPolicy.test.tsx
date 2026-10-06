/**
 * AvatarUploader 업로드·삭제 요청 대상 출처 테스트 (KVE-2026-2350)
 *
 * `uploadEndpoint`·`deleteEndpoint` 는 레이아웃 prop 이라 편집 권한만 있는 계정이 표현식으로 외부 주소를
 * 넣을 수 있다. 회원이 마이페이지에서 프로필 사진을 올리거나 지우면 회원 토큰이 그 주소로 갔다.
 * 토큰은 동일 출처 대상에만 싣는다.
 *
 * 컴포넌트는 요청을 코어 `G7Core.api` 로 보내고 토큰 첨부를 코어에 맡긴다. 그래서 이 테스트는 실제 코어
 * ApiClient 를 `G7Core.api` 로 세우고, 전송 직전 요청(최종 주소·헤더)을 어댑터에서 가로채 판정한다.
 * XSRF 헤더는 axios 가 동일 출처 요청에만 붙이며(브라우저 어댑터 단계), 여기서는 Bearer 만 잰다.
 */
import { describe, it, expect, vi, beforeAll, beforeEach, afterAll, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ApiClient from '@core/api/ApiClient';
import type { AvatarUploader as AvatarUploaderType } from '../AvatarUploader';

interface SentRequest {
  method: string;
  url: string;
  origin: string;
  headers: Record<string, unknown>;
}

let AvatarUploader: typeof AvatarUploaderType;
let requests: SentRequest[];

const offsite = ['https://evil.example/avatar', '//evil.example/avatar'];

/**
 * 실제 코어 ApiClient 를 만들고 전송 직전 요청을 가로챈다 (네트워크 없음).
 *
 * @param sent 가로챈 요청을 쌓을 배열
 * @return 코어 ApiClient
 */
function createCapturingCoreApi(sent: SentRequest[]): ApiClient {
  const client = new ApiClient();
  const instance = client.getInstance();
  instance.defaults.adapter = async (config: any) => {
    const full = instance.getUri({ baseURL: config.baseURL, url: config.url });
    const headers = typeof config.headers?.toJSON === 'function' ? config.headers.toJSON() : { ...config.headers };
    sent.push({
      method: String(config.method).toUpperCase(),
      url: config.url,
      origin: new URL(full, `${window.location.origin}/`).origin,
      headers,
    });
    return { data: { data: {} }, status: 200, statusText: 'OK', headers: {}, config, request: {} };
  };
  return client;
}

describe('AvatarUploader — 업로드·삭제 요청 대상 출처', () => {
  beforeAll(async () => {
    // 컴포넌트가 모듈 로드 시점에 G7Core 를 읽으므로 먼저 세운다
    (window as any).G7Core = {
      api: null,
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
    requests = [];
    (window as any).G7Core.api = createCapturingCoreApi(requests);
    localStorage.setItem('auth_token', 'MEMBER-TOKEN');
    global.fetch = vi.fn().mockRejectedValue(new Error('raw fetch must not be used')) as unknown as typeof fetch;
  });

  afterEach(() => {
    localStorage.clear();
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

  // @scenario sink=avatar_upload, target=cross_origin
  // @effects extension_token_same_origin_only
  // @scenario sink=avatar_delete, target=cross_origin
  // @effects extension_token_same_origin_only
  it('교차 출처 업로드·삭제 주소에는 Bearer 를 싣지 않는다', async () => {
    for (const url of offsite) {
      requests.length = 0;
      await upload(url);
      await remove(url);

      expect(requests.map(r => [r.method, r.url])).toEqual([['POST', url], ['DELETE', url]]);
      for (const request of requests) {
        expect(new URL(request.origin).hostname).toBe('evil.example');
        expect(request.headers.Authorization).toBeUndefined();
      }
    }

    // 백슬래시 변형(/\host)은 외부로 나가지 않고 자기 서버 /api 아래로 간다
    requests.length = 0;
    await upload('/\\evil.example/avatar');
    expect(requests[0].origin).toBe(window.location.origin);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  // @scenario sink=avatar_upload, target=same_origin
  // @effects extension_token_same_origin_only
  // @scenario sink=avatar_delete, target=same_origin
  // @effects extension_token_same_origin_only
  it('동일 출처 업로드·삭제 주소는 종전대로 Bearer 를 싣는다 (정상 회귀)', async () => {
    await upload();
    await remove();

    expect(requests.map(r => [r.method, r.url])).toEqual([['POST', '/api/me/avatar'], ['DELETE', '/api/me/avatar']]);
    for (const request of requests) {
      expect(request.origin).toBe(window.location.origin);
      expect(request.headers.Authorization).toBe('Bearer MEMBER-TOKEN');
    }
  });
});
