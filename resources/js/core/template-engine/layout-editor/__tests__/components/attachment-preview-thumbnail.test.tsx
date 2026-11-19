/**
 * attachment-preview-thumbnail.test.tsx — 편집기 첨부 이미지의 표시용 썸네일 주소 (#869)
 *
 * 배경: 이미지 선택기(44px 칸)·미리보기(높이 80)·첨부 관리(높이 88)가 원본을 받았다. 썸네일 주소는 표시에만 쓰고,
 * 저장되는 값은 서버가 준 주소 원문 그대로여야 한다 — `size` 가 저장되면 방문자 화면이 편집기용 규격을 받는다.
 *
 * 규격은 그 자리의 그리는 모양이 정한다.
 *  - 44px 정사각 cover 칸: `sm`(160 정사각). 서버의 중앙 자르기와 칸의 중앙 cover 가 같은 영역을 보인다.
 *  - 가로로 긴 미리보기·관리 카드: `md`(가로 640 맞춤). 정사각 `sm` 을 긴 칸에 cover 로 깔면 두 번 잘려 위아래가 사라지고
 *    폭이 모자라 흐려진다. contain 은 원본 비율을 보여야 하므로 더욱 자른 규격을 쓸 수 없다.
 *  - 타일(`background-size: auto`): 원본. 타일 크기가 이미지 실제 크기이므로 줄인 파생본은 거짓 미리보기다.
 */

import React, { useEffect } from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

import { ImagePickerControl } from '../../components/property-controls/ImagePickerControl';
import { LayoutAttachmentManager } from '../../components/property-controls/LayoutAttachmentManager';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { EditorModalProvider } from '../../EditorModalContext';
import type { EditorControlSpec } from '../../spec/specTypes';

const t = (k: string) => k;

const URL_A = '/api/templates/sirsoft-basic/layout-attachments/1/file';
const URL_B = '/api/templates/sirsoft-basic/layout-attachments/2/file';

const LIST = [
  { id: 1, layout_name: 'home', original_name: 'hero.png', mime_type: 'image/png', size: 131072, url: URL_A, created_at: '2026-05-30T00:00:00+00:00' },
  { id: 2, layout_name: 'home', original_name: 'bg2.jpg', mime_type: 'image/jpeg', size: 65536, url: URL_B, created_at: '2026-05-29T00:00:00+00:00' },
];

const bundleCtrl: EditorControlSpec = {
  widget: 'image',
  apply: { type: 'styleProp', props: ['backgroundImage', 'backgroundSize', 'backgroundRepeat', 'backgroundPosition'] },
};

const logoCtrl: EditorControlSpec = { widget: 'image', apply: { type: 'propValue', propKey: 'logo' } };

/** 마운트 시 라우트를 골라 첨부 목록을 부를 레이아웃 이름을 채운다 */
function SelectRoute({ children }: { children: React.ReactNode }): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/', layoutName: 'home' } });
  }, [dispatch]);
  return <>{children}</>;
}

/**
 * 이미지 위젯을 실제 Provider 안에서 렌더합니다.
 *
 * @param value 위젯 값
 * @param control 컨트롤 스펙
 * @returns onChange mock
 */
function renderPicker(value: unknown, control: EditorControlSpec = bundleCtrl) {
  const onChange = vi.fn();
  render(
    <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
      <EditorModalProvider>
        <SelectRoute>
          <ImagePickerControl control={control} value={value} onChange={onChange} t={t} />
        </SelectRoute>
      </EditorModalProvider>
    </LayoutEditorProvider>,
  );
  return onChange;
}

beforeEach(() => {
  (globalThis as any).G7Config = {
    settings: { upload: { thumbnail_url_patterns: ['/api/templates/*/layout-attachments/*/file'] } },
  };
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ success: true, data: LIST }) })));
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  delete (globalThis as any).G7Config;
});

describe('ImagePickerControl — 표시용 썸네일 주소 (#869)', () => {
  // @scenario public_asset_disk=unset, row_disk=attachments, filter_url_hook=absent
  // @effects editor_attachment_preview_uses_thumbnail_address, editor_attachment_saved_value_stays_original_url
  it('인라인 44px 칸은 sm 을 받고, 칸을 누르면 저장값은 주소 원문이다', async () => {
    const onChange = renderPicker(undefined);
    const thumb = await screen.findByTestId('g7le-image-thumb-use-1');

    expect(thumb.style.backgroundImage).toContain(`${URL_A}?size=sm`);

    fireEvent.click(thumb);
    expect(onChange).toHaveBeenLastCalledWith(expect.objectContaining({ url: URL_A }));
  });

  // @scenario public_asset_disk=unset, row_disk=attachments, filter_url_hook=absent
  // @effects editor_attachment_preview_uses_thumbnail_address
  it.each([
    ['fill', { size: 'cover', repeat: 'no-repeat' }],
    ['fit', { size: 'contain', repeat: 'no-repeat' }],
  ])('미리보기(%s)는 자르지 않는 md 를 받는다', (_mode, style) => {
    renderPicker({ url: URL_A, position: 'center', ...style });

    expect(screen.getByTestId('g7le-image-preview').style.backgroundImage).toContain(`${URL_A}?size=md`);
  });

  // @scenario public_asset_disk=unset, row_disk=attachments, filter_url_hook=absent
  // @effects editor_attachment_preview_uses_thumbnail_address
  it('단일 값 슬롯 미리보기(contain 고정)도 md 를 받는다', () => {
    renderPicker({ url: URL_A }, logoCtrl);

    expect(screen.getByTestId('g7le-image-preview').style.backgroundImage).toContain(`${URL_A}?size=md`);
  });

  // @scenario public_asset_disk=unset, row_disk=attachments, filter_url_hook=absent
  // @effects editor_tile_preview_keeps_original
  it('타일 미리보기는 원본을 받는다 — 타일 크기가 이미지 실제 크기라서 줄이면 거짓 미리보기다', () => {
    renderPicker({ url: URL_A, size: 'auto', repeat: 'repeat', position: 'center' });

    const image = screen.getByTestId('g7le-image-preview').style.backgroundImage;
    expect(image).toContain(URL_A);
    expect(image).not.toContain('size=');
  });

  // @scenario public_asset_disk=unset, row_disk=attachments, filter_url_hook=absent
  // @effects editor_attachment_saved_value_stays_original_url
  it('미리보기에 규격을 붙여도 URL 칸과 표시 방식 변경이 내보내는 값은 주소 원문이다', () => {
    const onChange = renderPicker({ url: URL_A, size: 'cover', repeat: 'no-repeat', position: 'center' });

    expect(screen.getByTestId('g7le-image-url')).toHaveValue(URL_A);

    fireEvent.click(screen.getByTestId('g7le-image-mode-fit'));
    expect(onChange).toHaveBeenLastCalledWith({ url: URL_A, size: 'contain', repeat: 'no-repeat', position: 'center' });
  });

  // @scenario public_asset_disk=unset, row_disk=attachments, filter_url_hook=absent
  // @effects editor_attachment_preview_uses_thumbnail_address
  it('썸네일 기능이 꺼져 경로 목록이 비면 주소를 바꾸지 않는다', async () => {
    (globalThis as any).G7Config.settings.upload.thumbnail_url_patterns = [];
    renderPicker({ url: URL_A, size: 'cover', repeat: 'no-repeat', position: 'center' });

    const thumb = await screen.findByTestId('g7le-image-thumb-use-1');
    expect(thumb.style.backgroundImage).not.toContain('size=');
    expect(screen.getByTestId('g7le-image-preview').style.backgroundImage).not.toContain('size=');
  });
});

describe('LayoutAttachmentManager — 표시용 썸네일 주소 (#869)', () => {
  // @scenario public_asset_disk=unset, row_disk=attachments, filter_url_hook=absent
  // @effects editor_attachment_preview_uses_thumbnail_address, editor_attachment_saved_value_stays_original_url
  it('가로로 긴 카드는 자르지 않는 md 를 받고, 배경으로 쓰면 주소 원문을 넘긴다', async () => {
    const onSelect = vi.fn();
    render(<LayoutAttachmentManager templateIdentifier="sirsoft-basic" layoutName="home" t={t} onSelect={onSelect} onClose={vi.fn()} />);

    const card = await screen.findByTestId('g7le-attachment-card-1');
    expect((card.firstElementChild as HTMLElement).style.backgroundImage).toContain(`${URL_A}?size=md`);

    fireEvent.click(screen.getByTestId('g7le-attachment-use-1'));
    expect(onSelect).toHaveBeenCalledWith(URL_A);
  });
});
