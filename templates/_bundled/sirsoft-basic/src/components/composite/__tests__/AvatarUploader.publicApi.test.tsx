/**
 * AvatarUploader 공개 API 사용 테스트
 *
 * 프로필 사진 업로드·삭제는 코어 `G7Core.api` 로 나간다 — 업로드는 `G7Core.api.post(url, FormData)`
 * (multipart), 삭제는 `G7Core.api.delete(url)`. 템플릿이 로그인 토큰을 직접 읽거나 `fetch` 에
 * Authorization 을 붙이지 않는다(Bearer 부착·401 처리는 코어 몫).
 *
 * 코어 ApiClient 는 4xx·5xx 를 응답이 실린 오류로 던진다. 사용자에게는 서버 message 를, 없으면 기본
 * 문구를 보인다.
 */
import { describe, it, expect, vi, beforeAll, beforeEach, afterAll } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import type { AvatarUploader as AvatarUploaderType } from '../AvatarUploader';

let AvatarUploader: typeof AvatarUploaderType;

const g7 = {
  api: { post: vi.fn(), delete: vi.fn() },
  t: (key: string) => key,
  toast: { success: vi.fn(), error: vi.fn() },
  // 코어가 실제로 내놓는 표면만 둔다 — 가짜에 없는 멤버(`refetchDataSource`)를 채우면 `?.` 호출이
  // 시험에서만 성공하고 화면에서는 조용히 아무 일도 하지 않는다(D3-2).
  dataSource: { refetch: vi.fn() },
  dispatch: vi.fn(),
};

describe('AvatarUploader — 공개 API 로 업로드·삭제', () => {
  beforeAll(async () => {
    // 컴포넌트가 모듈 로드 시점에 G7Core 를 읽으므로 먼저 세운다
    (window as any).G7Core = g7;
    ({ AvatarUploader } = await import('../AvatarUploader'));
  });

  afterAll(() => {
    delete (window as any).G7Core;
  });

  beforeEach(() => {
    vi.clearAllMocks();
    g7.api.post.mockResolvedValue({ data: { avatar: '/storage/new.png' } });
    g7.api.delete.mockResolvedValue({ success: true });
    global.fetch = vi.fn().mockRejectedValue(new Error('raw fetch must not be used')) as unknown as typeof fetch;
  });

  const chooseFile = (props: Record<string, unknown> = {}) => {
    const file = new File(['x'], 'a.png', { type: 'image/png' });
    const utils = render(<AvatarUploader confirmUpload={false} {...props} />);
    const input = utils.container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [file] } });
    return { ...utils, file };
  };

  // @scenario surface=avatar_upload
  // @effects avatar_upload_via_core_api
  it('업로드는 G7Core.api.post 에 avatar 파일을 담은 FormData 를 multipart 로 넘긴다', async () => {
    const getItemSpy = vi.spyOn(Storage.prototype, 'getItem');
    const onUploadSuccess = vi.fn();
    const { file } = chooseFile({ onUploadSuccess });

    await waitFor(() => expect(onUploadSuccess).toHaveBeenCalled());

    expect(g7.api.post).toHaveBeenCalledTimes(1);
    const [url, body, config] = g7.api.post.mock.calls[0];
    expect(url).toBe('/api/me/avatar');
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get('avatar')).toBe(file);
    expect(config).toEqual({ headers: { 'Content-Type': 'multipart/form-data' } });
    // 응답 래핑(data.data)을 풀어 넘긴다
    expect(onUploadSuccess).toHaveBeenCalledWith({ avatar: '/storage/new.png' });
    expect(global.fetch).not.toHaveBeenCalled();
    expect(getItemSpy.mock.calls.some(([key]) => key === 'auth_token')).toBe(false);
    getItemSpy.mockRestore();
  });

  // @scenario surface=avatar_upload
  // @effects avatar_error_message_from_response
  it('업로드가 거절되면 서버 message 를, 없으면 기본 문구를 오류로 보인다', async () => {
    g7.api.post.mockRejectedValueOnce({ response: { status: 422, data: { message: '이미지가 너무 큽니다.' } } });
    const onUploadError = vi.fn();
    const first = chooseFile({ onUploadError });
    await waitFor(() => expect(onUploadError).toHaveBeenCalledTimes(1));
    expect(onUploadError.mock.calls[0][0].message).toBe('이미지가 너무 큽니다.');
    expect(g7.toast.error).toHaveBeenCalledWith('이미지가 너무 큽니다.');
    first.unmount();

    g7.api.post.mockRejectedValueOnce({ response: { status: 500, data: {} } });
    chooseFile({ onUploadError });
    await waitFor(() => expect(onUploadError).toHaveBeenCalledTimes(2));
    expect(onUploadError.mock.calls[1][0].message).toBe('attachment.upload_failed');
  });

  // @scenario surface=avatar_upload
  // @effects avatar_error_message_from_response
  it('응답 없이 실패하면(네트워크 오류) 원래 오류 메시지를 보인다', async () => {
    g7.api.post.mockRejectedValueOnce(new Error('Network Error'));
    const onUploadError = vi.fn();
    chooseFile({ onUploadError });

    await waitFor(() => expect(onUploadError).toHaveBeenCalled());
    expect(onUploadError.mock.calls[0][0].message).toBe('Network Error');
  });

  // @scenario surface=avatar_delete
  // @effects avatar_delete_via_core_api
  it('삭제는 G7Core.api.delete 로 보내고 성공 콜백을 부른다', async () => {
    const onDeleteSuccess = vi.fn();
    render(
      <AvatarUploader src="/storage/avatar.png" confirmDelete={false} deleteButtonText="DEL" onDeleteSuccess={onDeleteSuccess} />
    );
    fireEvent.click(screen.getByText('DEL'));

    await waitFor(() => expect(onDeleteSuccess).toHaveBeenCalled());
    expect(g7.api.delete).toHaveBeenCalledWith('/api/me/avatar');
    expect(global.fetch).not.toHaveBeenCalled();
  });

  // @scenario surface=avatar_delete
  // @effects avatar_error_message_from_response
  it('삭제가 거절되면 서버 message 를 오류로 보인다', async () => {
    g7.api.delete.mockRejectedValueOnce({ response: { status: 403, data: { message: '권한이 없습니다.' } } });
    const onDeleteError = vi.fn();
    render(
      <AvatarUploader src="/storage/avatar.png" confirmDelete={false} deleteButtonText="DEL" onDeleteError={onDeleteError} />
    );
    fireEvent.click(screen.getByText('DEL'));

    await waitFor(() => expect(onDeleteError).toHaveBeenCalled());
    expect(onDeleteError.mock.calls[0][0].message).toBe('권한이 없습니다.');
  });

  // @scenario surface=avatar_upload
  // @effects avatar_success_refetches_user_via_core_data_source
  it('업로드 성공 뒤 성공 액션이 없으면 코어 G7Core.dataSource.refetch 로 user 를 다시 읽는다', async () => {
    const onUploadSuccess = vi.fn();
    chooseFile({ onUploadSuccess });

    await waitFor(() => expect(onUploadSuccess).toHaveBeenCalled());
    await waitFor(() => expect(g7.dataSource.refetch).toHaveBeenCalledWith('user'));
  });

  // @scenario surface=avatar_delete
  // @effects avatar_success_refetches_user_via_core_data_source
  it('삭제 성공 뒤 성공 액션이 없으면 코어 G7Core.dataSource.refetch 로 user 를 다시 읽는다', async () => {
    const onDeleteSuccess = vi.fn();
    render(
      <AvatarUploader src="/storage/avatar.png" confirmDelete={false} deleteButtonText="DEL" onDeleteSuccess={onDeleteSuccess} />
    );
    fireEvent.click(screen.getByText('DEL'));

    await waitFor(() => expect(onDeleteSuccess).toHaveBeenCalled());
    await waitFor(() => expect(g7.dataSource.refetch).toHaveBeenCalledWith('user'));
  });
});
