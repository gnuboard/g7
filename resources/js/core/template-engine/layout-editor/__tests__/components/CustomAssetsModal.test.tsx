/**
 * CustomAssetsModal + 툴바 커스텀 자산 버튼 테스트 (#123, D32·D33)
 *
 * 잠그는 계약:
 *  - 목록 403 은 오류 문구가 아니라 **안내**로 바뀐다 (권한이 부족하다는 사실과 무엇을
 *    요청해야 하는지가 화면에 나와야 한다 — "HTTP 403" 은 그 둘 다 말해 주지 않는다)
 *  - 저장은 PUT `custom-assets/content` 로 나가고 성공 후 목록을 다시 읽는다
 *  - 편집 불가(바이너리) 파일은 본문 편집기를 열지 않고 교체 안내를 보여 준다
 *  - 툴바 토글은 `?custom=off` 상태를 문구·`aria-pressed` 로 드러낸다
 *  - 편집기에서 연 창은 Esc·백드롭 모두 미저장 확인을 거친다(편집이 없으면 바로 닫힌다)
 *  - 창 문구의 치환 자리는 화면 번역기 형식(`{name}`)이다
 */

import React from 'react';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { CustomAssetsModal } from '../../components/CustomAssetsModal';
import { EditorToolbar } from '../../components/EditorToolbar';
import { LayoutEditorChrome } from '../../LayoutEditorChrome';
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

  // @scenario branch=extension, buffer_state=clean, leave=file_switch
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
   * 새 파일(아직 목록에 없는 경로)의 본문 입력이 화면에 남아 이어져야 한다. 본문 칸이 «고른 파일» 의 버퍼만 읽어
   * 새 경로에서는 늘 빈 값으로 그려졌고, 키를 누를 때마다 빈 값에 한 글자가 붙어 버퍼에 마지막 글자만 남았다
   * (브라우저 실측 2026-10-03: 한 줄을 쳐서 저장하니 「}」 1바이트 파일).
   *
   * @scenario manage_actor=with_permission, manage_action=save
   * @effects custom_asset_editor_keeps_typing_for_a_new_path
   */
  it('[case:wysiwyg-3] 새 경로의 본문은 입력한 대로 보이고 다음 입력이 이어진다', async () => {
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (init?.method === 'PUT') {
        return Promise.resolve(jsonResponse(200, { data: { path: 'new.css', size: 9 } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-assets-path')).toBeTruthy();
    });

    fireEvent.change(screen.getByTestId('g7le-custom-assets-path'), { target: { value: 'new.css' } });

    const content = screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement;
    fireEvent.change(content, { target: { value: 'b' } });
    expect(content.value).toBe('b');
    fireEvent.change(content, { target: { value: 'body{}' } });
    expect(content.value).toBe('body{}');

    fireEvent.click(screen.getByTestId('g7le-custom-assets-save'));

    await waitFor(() => {
      const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit)?.method === 'PUT');
      expect(JSON.parse(String((put?.[1] as RequestInit).body)).content).toBe('body{}');
    });
  });

  /**
   * 새 파일의 본문은 경로를 나중에 고쳐도 따라가야 한다. 본문을 «입력 중인 경로» 의 버퍼에 두면 경로 칸을 고치는
   * 순간 본문 칸이 비고, 그대로 저장하면 고친 경로에 빈 파일이 저장되며 쓴 본문은 옛 경로 버퍼에 숨는다.
   *
   * @scenario manage_actor=with_permission, manage_action=save
   * @effects custom_asset_editor_keeps_typing_for_a_new_path
   */
  it('새 파일 본문을 쓴 뒤 경로를 고쳐도 본문이 남고 고친 경로로 저장된다', async () => {
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (init?.method === 'PUT') {
        return Promise.resolve(jsonResponse(200, { data: { path: 'fixed.css', size: 6 } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    const pathInput = (await screen.findByTestId('g7le-custom-assets-path')) as HTMLInputElement;
    fireEvent.change(pathInput, { target: { value: 'fixd.css' } });

    const content = screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement;
    fireEvent.change(content, { target: { value: 'body{}' } });

    fireEvent.change(pathInput, { target: { value: 'fixed.css' } });
    expect(content.value).toBe('body{}');

    fireEvent.click(screen.getByTestId('g7le-custom-assets-save'));

    await waitFor(() => {
      const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit)?.method === 'PUT');
      const body = JSON.parse(String((put?.[1] as RequestInit).body));
      expect(body.path).toBe('fixed.css');
      expect(body.content).toBe('body{}');
    });
  });

  /**
   * 경로를 정하기 전에 본문부터 써도 입력이 받아져야 한다 — 경로가 비었다고 입력을 버리면 운영자는 왜
   * 글자가 안 쳐지는지 알 수 없다. 저장 버튼은 경로가 생길 때까지 잠겨 있다.
   *
   * @scenario manage_actor=with_permission, manage_action=save
   * @effects custom_asset_editor_keeps_typing_for_a_new_path
   */
  it('경로보다 본문을 먼저 써도 입력이 남고 경로를 넣으면 저장된다', async () => {
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (init?.method === 'PUT') {
        return Promise.resolve(jsonResponse(200, { data: { path: 'later.css', size: 6 } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    const pathInput = (await screen.findByTestId('g7le-custom-assets-path')) as HTMLInputElement;
    const content = screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement;
    fireEvent.change(content, { target: { value: 'a{}' } });
    expect(content.value).toBe('a{}');
    expect((screen.getByTestId('g7le-custom-assets-save') as HTMLButtonElement).disabled).toBe(true);

    fireEvent.change(pathInput, { target: { value: 'later.css' } });
    fireEvent.click(screen.getByTestId('g7le-custom-assets-save'));

    await waitFor(() => {
      const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit)?.method === 'PUT');
      const body = JSON.parse(String((put?.[1] as RequestInit).body));
      expect(body.path).toBe('later.css');
      expect(body.content).toBe('a{}');
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
  // @scenario branch=extension, buffer_state=clean, leave=reload
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
  /**
   * **D2 회귀 잠금** — 파일을 바꿔도 앞 파일의 미저장 초안이 남는다.
   *
   * 종전에는 초안이 `draft` 문자열 **한 벌**이라 `openFile` 이 서버 본문으로 무조건 덮었다.
   * 레이아웃 편집기가 고친 것과 같은 결함 클래스이고, 같은 원칙으로 막는다 —
   * **버퍼가 dirty 면 서버 본문을 무시한다.**
   *
   * @effects custom_asset_editor_keeps_unsaved_draft_across_files
   */
  // @scenario branch=extension, buffer_state=dirty, leave=file_switch
  it('파일을 바꿨다 돌아와도 저장하지 않은 편집이 남는다', async () => {
    fetchMock.mockImplementation((url: string) => {
      if (String(url).includes('/content?')) {
        return Promise.resolve(jsonResponse(200, { data: { content: 'SERVER BODY' } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
    });

    // A 를 열고 고친다.
    fireEvent.click(screen.getByTestId('g7le-custom-asset-row-10-overrides.css'));
    await waitFor(() => {
      expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('SERVER BODY');
    });

    fireEvent.change(screen.getByTestId('g7le-custom-assets-content'), { target: { value: 'MY EDIT' } });

    // 편집 중인 파일에 미저장 표시가 선다.
    await waitFor(() => {
      expect(
        screen.getByTestId('g7le-custom-asset-row-10-overrides.css').getAttribute('data-dirty'),
      ).toBe('true');
    });

    // B(편집 불가 파일)로 갔다가
    fireEvent.click(screen.getByTestId('g7le-custom-asset-row-fonts/brand.woff2'));

    // A 로 돌아온다 — 서버는 같은 본문을 또 주지만 내 편집분이 이겨야 한다.
    fireEvent.click(screen.getByTestId('g7le-custom-asset-row-10-overrides.css'));

    await waitFor(() => {
      expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('MY EDIT');
    });
  });

  /**
   * **D3 회귀 잠금** — 대상(확장)을 바꿀 때 미저장 편집이 있으면 **되묻는다**.
   *
   * 버퍼를 비우는 것 자체는 맞다(A 확장의 본문을 B 확장에 저장하면 경로만 맞으면 서버는 200 으로
   * 받아들인다). 다만 **버릴지 말지는 사용자가 정한다** — 종전에는 아무 말 없이 버렸다.
   *
   * @effects custom_asset_editor_confirms_before_discarding_on_target_switch
   */
  // @scenario branch=extension, buffer_state=dirty, leave=reload
  it('미저장 편집이 있으면 대상 전환 전에 확인을 받고, 취소하면 그대로 둔다', async () => {
    fetchMock.mockImplementation((url: string) => {
      if (String(url).includes('/content?')) {
        return Promise.resolve(jsonResponse(200, { data: { content: 'SERVER BODY' } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

    try {
      render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

      await waitFor(() => {
        expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
      });

      fireEvent.click(screen.getByTestId('g7le-custom-asset-row-10-overrides.css'));
      await waitFor(() => {
        expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('SERVER BODY');
      });

      fireEvent.change(screen.getByTestId('g7le-custom-assets-content'), { target: { value: 'MY EDIT' } });

      const select = screen.getByTestId('g7le-custom-assets-target') as HTMLSelectElement;
      fireEvent.change(select, { target: { value: 'plugin:sirsoft-gdpr' } });

      expect(confirmSpy).toHaveBeenCalled();
      // 취소했으므로 대상도 편집분도 그대로다.
      expect(select.value).toBe('template:sirsoft-basic');
      expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('MY EDIT');
    } finally {
      confirmSpy.mockRestore();
    }
  });

  /** @effects custom_asset_editor_shows_a_file_tree */
  it('경로의 슬래시로 폴더를 만든다', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, listBody));

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-folder-fonts')).toBeTruthy();
    });
  });

  /** @effects custom_asset_editor_filters_by_name */
  it('검색어로 파일명을 거른다 (2자 미만은 서버를 부르지 않는다)', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, listBody));

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
    });

    fireEvent.change(screen.getByTestId('g7le-custom-assets-search'), { target: { value: 'overrides' } });

    await waitFor(() => {
      expect(screen.queryByTestId('g7le-custom-asset-folder-fonts')).toBeNull();
      expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
    });

    // 1자로는 내용 검색을 부르지 않는다.
    fireEvent.change(screen.getByTestId('g7le-custom-assets-search'), { target: { value: 'o' } });

    await waitFor(() => {
      expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/search'))).toHaveLength(0);
    });
  });

  /**
   * 계획 §4.7 — 내용 검색에서 **이미 열려 있는 파일은 클라이언트가** 찾는다. 서버는 저장본만 보므로
   * 저장하지 않은 편집분은 이 경로가 없으면 영영 검색되지 않는다.
   *
   * @effects custom_asset_editor_searches_open_buffers_locally
   */
  it('열린 파일의 저장하지 않은 본문은 서버를 거치지 않고 바로 검색된다', async () => {
    fetchMock.mockImplementation((url: string) => {
      if (String(url).includes('/content?')) {
        return Promise.resolve(jsonResponse(200, { data: { content: 'body { color: red; }' } }));
      }
      // 서버는 저장본만 본다 — 편집 중인 문자열은 저장본에 없으므로 빈 결과다.
      if (String(url).includes('/search')) {
        return Promise.resolve(jsonResponse(200, { data: [] }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
    });

    fireEvent.click(screen.getByTestId('g7le-custom-asset-row-10-overrides.css'));
    await waitFor(() => {
      expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('body { color: red; }');
    });

    fireEvent.change(screen.getByTestId('g7le-custom-assets-content'), {
      target: { value: 'body { color: red; }\n.zzneedle { margin: 0; }' },
    });
    fireEvent.change(screen.getByTestId('g7le-custom-assets-search'), { target: { value: 'zzneedle' } });

    // 2행에서 찾은 매치가 서버 왕복 없이 트리에 얹힌다 (좌표는 편집기 줄 번호와 같다).
    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-hit-10-overrides.css-2')).toBeTruthy();
    });
    expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/search'))).toHaveLength(0);
  });

  /**
   * 계획 §4.7 — 코드 입력칸에서 Tab 은 들여쓰기다. 종전에는 포커스가 다음 요소로 넘어가 코드 편집에
   * 쓸 수 없었다(계획서는 «이미 있음» 으로 잘못 전제했다).
   *
   * @effects custom_asset_editor_indents_with_tab
   */
  it('Tab 은 두 칸 들여쓰고 Shift+Tab 은 그 줄 머리의 두 칸을 거둔다', async () => {
    fetchMock.mockImplementation((url: string) => {
      if (String(url).includes('/content?')) {
        return Promise.resolve(jsonResponse(200, { data: { content: 'a {}\nb {}' } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
    });
    fireEvent.click(screen.getByTestId('g7le-custom-asset-row-10-overrides.css'));

    const textarea = () => screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement;
    await waitFor(() => {
      expect(textarea().value).toBe('a {}\nb {}');
    });

    // 둘째 줄 머리에 커서를 두고 Tab — 포커스가 옮겨 가지 않고 두 칸이 들어간다.
    textarea().setSelectionRange(5, 5);
    fireEvent.keyDown(textarea(), { key: 'Tab' });
    await waitFor(() => {
      expect(textarea().value).toBe('a {}\n  b {}');
    });

    // Shift+Tab 은 그 줄 머리의 두 칸을 거둔다.
    textarea().setSelectionRange(7, 7);
    fireEvent.keyDown(textarea(), { key: 'Tab', shiftKey: true });
    await waitFor(() => {
      expect(textarea().value).toBe('a {}\nb {}');
    });
  });

  /** @effects custom_asset_editor_renames_through_the_api */
  it('이름변경은 rename API 로 나가고 성공 후 목록을 다시 읽는다', async () => {
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (String(url).includes('/rename')) {
        return Promise.resolve(jsonResponse(200, { data: { path: 'renamed.css' } }));
      }

      if (String(url).includes('/content?')) {
        return Promise.resolve(jsonResponse(200, { data: { content: 'SERVER BODY' } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('renamed.css');

    try {
      render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

      await waitFor(() => {
        expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
      });

      fireEvent.click(screen.getByTestId('g7le-custom-asset-row-10-overrides.css'));
      await waitFor(() => {
        expect(screen.getByTestId('g7le-custom-assets-rename')).toBeTruthy();
      });

      fireEvent.click(screen.getByTestId('g7le-custom-assets-rename'));

      await waitFor(() => {
        const call = fetchMock.mock.calls.find(([url]) => String(url).includes('/rename'));
        expect(call).toBeTruthy();
        expect(JSON.parse(String((call![1] as RequestInit).body))).toEqual({
          from: '10-overrides.css',
          to: 'renamed.css',
        });
      });
    } finally {
      promptSpy.mockRestore();
    }
  });

  /** @effects custom_asset_editor_saves_with_ctrl_s */
  it('Ctrl+S 로 저장한다', async () => {
    fetchMock.mockImplementation((url: string) => {
      if (String(url).includes('/content?')) {
        return Promise.resolve(jsonResponse(200, { data: { content: 'SERVER BODY' } }));
      }

      return Promise.resolve(jsonResponse(200, listBody));
    });

    render(<CustomAssetsModal templateIdentifier="sirsoft-basic" t={t} onClose={() => {}} />);

    await waitFor(() => {
      expect(screen.getByTestId('g7le-custom-asset-row-10-overrides.css')).toBeTruthy();
    });

    fireEvent.click(screen.getByTestId('g7le-custom-asset-row-10-overrides.css'));
    await waitFor(() => {
      expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('SERVER BODY');
    });

    fireEvent.change(screen.getByTestId('g7le-custom-assets-content'), { target: { value: 'MY EDIT' } });

    fireEvent.keyDown(window, { key: 's', ctrlKey: true });

    await waitFor(() => {
      const put = fetchMock.mock.calls.find(([, init]) => (init as RequestInit)?.method === 'PUT');
      expect(put).toBeTruthy();
      expect(JSON.parse(String((put![1] as RequestInit).body)).content).toBe('MY EDIT');
    });
  });

  /** @effects custom_asset_editor_confirms_before_discarding_on_target_switch */
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
  /** @effects custom_asset_editor_filters_by_name */
  it('관리 버튼은 핸들러 미제공 시 disabled', () => {
    render(wrapToolbar(<EditorToolbar />));

    expect((screen.getByTestId('g7le-toolbar-custom-assets') as HTMLButtonElement).disabled).toBe(
      true,
    );
  });

  /** @effects custom_asset_editor_filters_by_name */
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

describe('LayoutEditorChrome — 커스텀 자산 창의 미저장 보호', () => {
  // 회귀 배경(브라우저 실측 2026-10-03): 편집기 모달 루트가 Esc 를 캡처 단계에서 가로채 창을 바로 닫고,
  // 백드롭 클릭도 바로 닫아, 창 자신의 «저장하지 않은 편집 N건» 확인을 거치지 않고 쓰던 본문이 사라졌다.
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = typeof input === 'string' ? input : String(input);
        if (/\/editor\/routes\.json/.test(url)) {
          return new Response(
            JSON.stringify({ success: true, data: { routes: [
              { path: '/', layout: 'home', source: { kind: 'template', identifier: null }, meta: { title: '홈' } },
            ] } }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
          );
        }
        if (/\/custom-assets(\?|$)/.test(url)) {
          return new Response(JSON.stringify({ data: { ...listBody.data, files: [] } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          });
        }

        return new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } });
      }),
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  async function openFromChromeWithUnsavedText(): Promise<HTMLTextAreaElement> {
    render(
      <TranslationProvider
        translationEngine={new TranslationEngine()}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorChrome templateIdentifier="sirsoft-admin_basic" initialLocale="ko" />
      </TranslationProvider>,
    );
    fireEvent.click(await screen.findByTestId('g7le-toolbar-custom-assets'));
    const content = (await screen.findByTestId('g7le-custom-assets-content')) as HTMLTextAreaElement;
    fireEvent.change(content, { target: { value: 'body{}' } });

    return content;
  }

  /**
   * @scenario manage_actor=with_permission, manage_action=save
   * @effects custom_asset_editor_guards_unsaved_text_on_close
   */
  it('[case:wysiwyg-4] 본문을 쓰다 Esc 를 누르면 버릴지 묻고, 취소하면 창과 본문이 남는다', async () => {
    // 확인은 편집기 대화상자(engine-v1.78.7) — 브라우저 기본 confirm 은 부르지 않는다.
    const confirmSpy = vi.spyOn(window, 'confirm');
    const content = await openFromChromeWithUnsavedText();

    fireEvent.keyDown(content, { key: 'Escape' });

    await screen.findByRole('alertdialog');
    fireEvent.click(screen.getByTestId('g7le-dialog-cancel'));
    await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull());
    expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('body{}');

    fireEvent.keyDown(screen.getByTestId('g7le-custom-assets-content'), { key: 'Escape' });
    await screen.findByRole('alertdialog');
    fireEvent.click(screen.getByTestId('g7le-dialog-confirm'));

    await waitFor(() => {
      expect(screen.queryByTestId('g7le-custom-assets-content')).toBeNull();
    });
    expect(confirmSpy).not.toHaveBeenCalled();
  });

  /**
   * @scenario manage_actor=with_permission, manage_action=save
   * @effects custom_asset_editor_guards_unsaved_text_on_close
   */
  it('버릴지 묻는 대화상자에서 Esc 는 취소다 — 대화상자만 닫히고 자산 창과 본문은 남는다', async () => {
    const content = await openFromChromeWithUnsavedText();

    fireEvent.keyDown(content, { key: 'Escape' });
    const dialog = await screen.findByRole('alertdialog');
    fireEvent.keyDown(dialog, { key: 'Escape' });

    await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull());
    expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('body{}');
  });

  /**
   * @scenario manage_actor=with_permission, manage_action=save
   * @effects custom_asset_editor_guards_unsaved_text_on_close
   */
  it('저장하지 않은 본문이 있을 때 백드롭을 누르면 Esc 와 같이 버릴지 묻는다', async () => {
    await openFromChromeWithUnsavedText();

    const backdrop = document.querySelector('.g7le-modal-backdrop') as HTMLElement;
    expect(backdrop).toBeTruthy();
    fireEvent.click(backdrop);

    await screen.findByRole('alertdialog');
    fireEvent.click(screen.getByTestId('g7le-dialog-cancel'));
    await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull());
    expect((screen.getByTestId('g7le-custom-assets-content') as HTMLTextAreaElement).value).toBe('body{}');

    fireEvent.click(document.querySelector('.g7le-modal-backdrop') as HTMLElement);
    await screen.findByRole('alertdialog');
    fireEvent.click(screen.getByTestId('g7le-dialog-confirm'));

    await waitFor(() => {
      expect(screen.queryByTestId('g7le-custom-assets-content')).toBeNull();
    });
  });

  /**
   * @scenario manage_actor=with_permission, manage_action=save
   * @effects custom_asset_editor_guards_unsaved_text_on_close
   */
  it('저장하지 않은 편집이 없으면 백드롭을 눌렀을 때 묻지 않고 닫힌다(페이지 설정 창과 같은 동작)', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(
      <TranslationProvider
        translationEngine={new TranslationEngine()}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorChrome templateIdentifier="sirsoft-admin_basic" initialLocale="ko" />
      </TranslationProvider>,
    );
    fireEvent.click(await screen.findByTestId('g7le-toolbar-custom-assets'));
    await screen.findByTestId('g7le-custom-assets-content');

    fireEvent.click(document.querySelector('.g7le-modal-backdrop') as HTMLElement);

    await waitFor(() => {
      expect(screen.queryByTestId('g7le-custom-assets-content')).toBeNull();
    });
    expect(confirmSpy).not.toHaveBeenCalled();
  });
});

// 편집기 문구의 치환 자리는 화면 번역기가 바꾸는 `{name}`(또는 `{{name}}`)이어야 한다. 서버 언어 파일 형식인
// `:name` 을 쓰면 치환되지 않고 화면에 그대로 나온다(실측 2026-10-03: 닫기 확인 「저장하지 않은 편집 :count건」).
describe('편집기 문구 치환 자리 형식', () => {
  const repoRoot = resolve(__dirname, '../../../../../../..');

  /**
   * @scenario manage_actor=with_permission, manage_action=save
   * @effects custom_asset_editor_guards_unsaved_text_on_close
   */
  it('layout_editor.json 값(ko·en·ja 팩)에 `:name` 형식 자리가 없다', () => {
    const files = [
      'lang/partial/ko/layout_editor.json',
      'lang/partial/en/layout_editor.json',
      'lang-packs/_bundled/g7-core-ja/frontend/partial/layout_editor.json',
    ];
    const colonParam = /(?<![A-Za-z0-9/]):([a-z][a-z_]+)/;
    const offenders: string[] = [];

    const walk = (obj: Record<string, unknown>, prefix: string, file: string): void => {
      for (const [k, v] of Object.entries(obj)) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (typeof v === 'string') {
          if (colonParam.test(v)) offenders.push(`${file} ${key}: ${v}`);
        } else if (v && typeof v === 'object') {
          walk(v as Record<string, unknown>, key, file);
        }
      }
    };

    for (const file of files) {
      walk(JSON.parse(readFileSync(resolve(repoRoot, file), 'utf-8')), '', file);
    }

    expect(offenders, '편집기 번역기는 :name 을 치환하지 않는다 — {name} 으로 쓸 것').toEqual([]);
  });
});
