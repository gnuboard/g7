/**
 * CustomAssetsModal + 툴바 커스텀 자산 버튼 테스트 (#123, D32·D33)
 *
 * 잠그는 계약:
 *  - 목록 403 은 오류 문구가 아니라 **안내**로 바뀐다 (권한이 부족하다는 사실과 무엇을
 *    요청해야 하는지가 화면에 나와야 한다 — "HTTP 403" 은 그 둘 다 말해 주지 않는다)
 *  - 저장은 PUT `custom-assets/content` 로 나가고 성공 후 목록을 다시 읽는다
 *  - 편집 불가(바이너리) 파일은 본문 편집기를 열지 않고 교체 안내를 보여 준다
 *  - 툴바 토글은 `?custom=off` 상태를 문구·`aria-pressed` 로 드러낸다
 */

import React from 'react';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { CustomAssetsModal } from '../../components/CustomAssetsModal';
import { EditorToolbar } from '../../components/EditorToolbar';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

/** 키를 그대로 돌려주는 t — 문구가 아니라 어떤 키가 쓰였는지를 단언한다 */
const t = (key: string): string => key;

function wrapToolbar(node: React.ReactElement): React.ReactElement {
  const engine = new TranslationEngine();

  return (
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'sirsoft-basic', locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
        {node}
      </LayoutEditorProvider>
    </TranslationProvider>
  );
}

function jsonResponse(status: number, body: unknown): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  } as unknown as Response;
}

const listBody = {
  data: {
    files: [
      {
        path: '10-overrides.css',
        name: '10-overrides.css',
        extension: 'css',
        size: 42,
        modified_at: '2026-08-26T00:00:00+00:00',
        editable: true,
        loaded: true,
      },
      {
        path: 'fonts/brand.woff2',
        name: 'brand.woff2',
        extension: 'woff2',
        size: 1024,
        modified_at: '2026-08-26T00:00:00+00:00',
        editable: false,
        loaded: false,
      },
    ],
    editable_extensions: ['css', 'js', 'mjs', 'json'],
    uploadable_extensions: ['css', 'js', 'png', 'woff2'],
    max_text_bytes: 524288,
    max_upload_bytes: 5242880,
  },
};

describe('CustomAssetsModal', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    // 대상 목록은 서버가 심은 활성 확장 메타에서 만들어진다.
    (window as any).G7Config = {
      activeModules: [{ identifier: 'sirsoft-page', display_name: { ko: '페이지' } }],
      activePlugins: [{ identifier: 'sirsoft-gdpr', display_name: 'GDPR' }],
    };
  });

  afterEach(() => {
    delete (window as any).G7Config;
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  /** @effects custom_asset_manager_surfaces_permission_denial_as_guidance */
  it('403 은 오류 문구가 아니라 안내로 바뀐다', async () => {
    fetchMock.mockResolvedValue(jsonResponse(403, { message: 'This action is unauthorized.' }));

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-assets-forbidden')).toBeTruthy();
    });

    // 원시 상태 코드가 아니라 안내 키가 화면에 있어야 한다
    expect(screen.getByTestId('g7le-custom-assets-forbidden').textContent).toBe(
      'layout_editor.custom_assets.forbidden',
    );
    expect(screen.queryByTestId('g7le-custom-assets-error')).toBeNull();
  });

  it('목록을 읽어 파일 행을 그린다', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, listBody));

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
    });

    expect(screen.getByTestId('g7le-custom-asset-row-fonts/brand.woff2')).toBeTruthy();
    expect(
      screen.getByTestId('g7le-custom-asset-row-10-overrides.css').getAttribute('data-loaded'),
    ).toBe('true');
  });

  /** @effects custom_asset_binary_file_offers_replace_not_text_edit */
  it('편집 불가 파일은 본문 편집기를 열지 않고 교체를 안내한다', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, listBody));

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-row-fonts/brand.woff2')).toBeTruthy();
    });

    fireEvent.click(screen.getByTestId('g7le-custom-asset-row-fonts/brand.woff2'));

    await waitFor(() => {
      expect(screen.queryByTestId('g7le-custom-assets-content')).toBeNull();
    });

    // 본문을 읽으러 가지 않는다 — 바이너리를 텍스트로 열면 저장 시 손상된다
    const readCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes('/content'));
    expect(readCalls).toHaveLength(0);
  });

  /** @effects custom_asset_editor_save_invalidates_published_copy */
  it('저장은 PUT content 로 나가고 성공 후 목록을 다시 읽는다', async () => {
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (init?.method === 'PUT') {
        return Promise.resolve(jsonResponse(200, { data: { path: 'new.css', size: 5 } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-assets-path')).toBeTruthy();
    });

    fireEvent.change(screen.getByTestId('g7le-custom-assets-path'), {
      target: { value: 'new.css' },
    });
    fireEvent.change(screen.getByTestId('g7le-custom-assets-content'), {
      target: { value: 'body{}' },
    });
    fireEvent.click(screen.getByTestId('g7le-custom-assets-save'));

    await waitFor(() => {
      const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit)?.method === 'PUT');
      expect(put).toBeTruthy();
      expect(String(put?.[0])).toContain('/api/admin/extensions/template/sirsoft-basic/custom-assets/content');
      expect(JSON.parse(String((put?.[1] as RequestInit).body))).toEqual({
        path: 'new.css',
        content: 'body{}',
      });
    });

    // 저장 후 목록 재조회 — 새 파일이 목록에 나타나야 한다
    await waitFor(() => {
      const listCalls = fetchMock.mock.calls.filter(
        ([url, init]) => !(init as RequestInit)?.method && String(url).endsWith('/custom-assets'),
      );
      expect(listCalls.length).toBeGreaterThanOrEqual(2);
    });
  });

  /**
   * 대상은 템플릿뿐 아니라 활성 모듈·플러그인도 고를 수 있어야 한다.
   *
   * 같은 기능이 확장 타입에 따라 화면에서 되고 안 되면 운영자는 그 이유를 알 수 없다.
   *
   * @effects custom_asset_manage_all_extension_types_parity
   */
  it('대상 선택기에 활성 모듈·플러그인이 함께 오른다', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, listBody));

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    const select = (await screen.findByTestId('g7le-custom-assets-target')) as HTMLSelectElement;
    const values = Array.from(select.options).map((o) => o.value);

    expect(values).toEqual([
      'template:sirsoft-basic',
      'module:sirsoft-page',
      'plugin:sirsoft-gdpr',
    ]);
  });

  /** @effects custom_asset_manage_all_extension_types_parity */
  it('대상을 바꾸면 그 확장의 경로로 다시 읽는다', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, listBody));

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    const select = (await screen.findByTestId('g7le-custom-assets-target')) as HTMLSelectElement;
    fireEvent.change(select, { target: { value: 'module:sirsoft-page' } });

    await waitFor(() => {
      const called = fetchMock.mock.calls.map(([url]) => String(url));
      expect(
        called.some((u) => u.includes('/api/admin/extensions/module/sirsoft-page/custom-assets')),
      ).toBe(true);
    });
  });

  /**
   * 대상 전환은 이전 확장의 선택·초안을 버려야 한다.
   *
   * 남겨 두면 A 확장에서 열어 둔 본문을 B 확장에 저장하게 되고, 경로가 유효하면 서버는
   * 정상 200 으로 받아들인다 — 오류가 남지 않는 종류의 사고다.
   *
   * @effects custom_asset_manage_all_extension_types_parity
   */
  it('대상을 바꾸면 선택과 초안을 버린다', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, listBody));

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
    });

    fireEvent.click(screen.getByTestId('g7le-custom-asset-row-10-overrides.css'));

    await waitFor(() => {
      expect((screen.getByTestId('g7le-custom-assets-path') as HTMLInputElement).readOnly).toBe(true);
    });

    fireEvent.change(screen.getByTestId('g7le-custom-assets-target'), {
      target: { value: 'plugin:sirsoft-gdpr' },
    });

    await waitFor(() => {
      const path = screen.getByTestId('g7le-custom-assets-path') as HTMLInputElement;
      expect(path.readOnly).toBe(false);
      expect(path.value).toBe('');
    });
  });
});

describe('EditorToolbar — 커스텀 자산 (D32·D33)', () => {
  it('관리 버튼은 핸들러 미제공 시 disabled', () => {
    render(wrapToolbar(<EditorToolbar />));

    expect((screen.getByTestId('g7le-toolbar-custom-assets') as HTMLButtonElement).disabled).toBe(
      true,
    );
  });

  it('관리 버튼 클릭이 핸들러를 호출한다', () => {
    const onManage = vi.fn();
    render(wrapToolbar(<EditorToolbar onManageCustomAssets={onManage} />));

    fireEvent.click(screen.getByTestId('g7le-toolbar-custom-assets'));

    expect(onManage).toHaveBeenCalledTimes(1);
  });

  /**
   * 상태 판정의 출처는 서버다.
   *
   * SPA 부팅이 주소를 다시 쓰면서 `?custom=off` 를 지우므로, URL 로 판정하면 자산은
   * 꺼졌는데 버튼은 "켜져 있음" 으로 표시되고 되돌릴 방법이 화면에서 사라진다.
   *
   * @effects custom_assets_disabled_by_request_parameter
   */
  it('주소에 파라미터가 없어도 서버가 껐다고 하면 꺼짐으로 표시한다', () => {
    render(
      wrapToolbar(<EditorToolbar onToggleCustomAssets={() => {}} customAssetsDisabled={true} />),
    );

    const button = screen.getByTestId('g7le-toolbar-toggle-custom-assets');

    expect(window.location.search).not.toContain('custom=off');
    expect(button.getAttribute('aria-pressed')).toBe('true');
    expect(button.textContent).toContain('custom_assets_on');
  });

  /** @effects custom_assets_disabled_by_request_parameter */
  it('토글이 현재 끔 상태를 문구와 aria-pressed 로 드러낸다', () => {
    const onToggle = vi.fn();
    const { rerender } = render(
      wrapToolbar(<EditorToolbar onToggleCustomAssets={onToggle} customAssetsDisabled={false} />),
    );

    const button = () => screen.getByTestId('g7le-toolbar-toggle-custom-assets');

    expect(button().getAttribute('aria-pressed')).toBe('false');
    expect(button().getAttribute('data-custom-disabled')).toBe('false');

    rerender(
      wrapToolbar(<EditorToolbar onToggleCustomAssets={onToggle} customAssetsDisabled={true} />),
    );

    // 지금 화면이 평소와 다른 상태임이 보이지 않으면, 운영자가 "내 CSS 가 사라졌다" 를
    // 새 결함으로 오인한다
    expect(button().getAttribute('aria-pressed')).toBe('true');
    expect(button().getAttribute('data-custom-disabled')).toBe('true');

    fireEvent.click(button());
    expect(onToggle).toHaveBeenCalledTimes(1);
  });
});
