/**
 * AvatarUploader 업로드 확인 미리보기 대체 글 테스트 (7.1.0 N O-N7)
 *
 * 프로필 사진을 고르면 [이 이미지로 변경하시겠습니까?] 확인 단계에서 미리보기가 뜬다. 그 그림의 대체 글이
 * 「Preview」 영어로 박혀 있어, 한국어 화면에서도 스크린리더·깨진 그림 자리에 「Preview」 가 나왔다.
 * 대체 글은 번역 키(`mypage.profile.avatar_preview`)를 거친다.
 */
import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { render, fireEvent, waitFor } from '@testing-library/react';
import type { AvatarUploader as AvatarUploaderType } from '../AvatarUploader';

let AvatarUploader: typeof AvatarUploaderType;

describe('AvatarUploader — 업로드 확인 미리보기 대체 글', () => {
  beforeAll(async () => {
    (window as any).G7Core = {
      api: { getToken: () => 'MEMBER-TOKEN' },
      t: (key: string) => `T(${key})`,
      toast: { success: vi.fn(), error: vi.fn() },
      refetchDataSource: vi.fn(),
      dispatch: vi.fn(),
    };
    (URL as any).createObjectURL = vi.fn(() => 'blob:qa-preview');
    (URL as any).revokeObjectURL = vi.fn();
    ({ AvatarUploader } = await import('../AvatarUploader'));
  });

  afterAll(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario surface=user_avatar_uploader
   * @effects avatar_preview_alt_is_translated
   */
  it('미리보기 그림의 대체 글은 번역 키를 거친다(영어 고정 「Preview」 아님)', async () => {
    const { container, unmount } = render(<AvatarUploader confirmUpload={true} />);
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['x'], 'a.png', { type: 'image/png' })] } });

    await waitFor(() => expect(container.querySelector('img[src="blob:qa-preview"]')).not.toBeNull());
    const img = container.querySelector('img[src="blob:qa-preview"]') as HTMLImageElement;
    expect(img.getAttribute('alt')).toBe('T(mypage.profile.avatar_preview)');
    unmount();
  });
});
