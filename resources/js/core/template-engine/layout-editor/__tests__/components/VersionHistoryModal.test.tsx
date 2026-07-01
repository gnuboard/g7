/**
 * VersionHistoryModal.test.tsx —
 *
 * 검증 대상: 버전 히스토리 모달 + useLayoutVersions
 *  - 버전 목록 / 빈 / 로딩 / 에러 상태
 *  - 변경 요약(+N 라인 -N 라인 + 문자수) 표시
 *  - 복원(POST restore) → onRestored 콜백 호출 + 모달 닫기
 *  - 복원 confirm 취소 시 호출 없음
 *  - 404 복원 → not_found 메시지
 *  - 모든 fetch 에 Authorization: Bearer 첨부 + URL 규칙(raw layoutName)
 *
 * @effects version_modal_lists_saved_versions_with_change_summary, version_modal_empty_state_when_no_versions,
 *   version_modal_error_state_on_load_failure, version_restore_confirm_posts_restore_then_reloads_document_and_closes,
 *   version_restore_cancel_does_not_post, version_restore_not_found_shows_message_keeps_modal_open,
 *   version_and_preview_fetch_attach_bearer_token, version_fetch_url_preserves_raw_layout_name_slashes,
 *   version_row_shows_creator_name_or_unknown_fallback, version_compare_fetches_prev_and_current_then_shows_unified_diff
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

import { VersionHistoryModal } from '../../components/VersionHistoryModal';

const t = (k: string, params?: Record<string, string | number>) =>
  params ? `${k}(${JSON.stringify(params)})` : k;

const sampleVersions = [
  {
    id: 11,
    layout_id: 1,
    version: 3,
    changes_summary: { added_count: 2, removed_count: 0, char_diff: 40 },
    created_at: '2026-06-03T00:00:00+00:00',
    created_by_name: '관리자',
  },
  {
    id: 10,
    layout_id: 1,
    version: 2,
    changes_summary: { added_count: 0, removed_count: 1, char_diff: -10 },
    created_at: '2026-06-02T00:00:00+00:00',
    created_by_name: null,
  },
];

function mockFetch(handler: (url: string, init?: RequestInit) => unknown) {
  const fn = vi.fn(async (url: string, init?: RequestInit) => handler(url, init));
  vi.stubGlobal('fetch', fn);
  return fn;
}

beforeEach(() => {
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => (k === 'auth_token' ? 'TESTTOKEN' : null),
    setItem: () => {},
    removeItem: () => {},
  } as unknown as Storage);
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

function listOk(versions = sampleVersions) {
  return { ok: true, json: async () => ({ success: true, data: versions }) };
}

function renderModal(overrides: Partial<React.ComponentProps<typeof VersionHistoryModal>> = {}) {
  const onRestored = overrides.onRestored ?? vi.fn();
  const onClose = overrides.onClose ?? vi.fn();
  render(
    <VersionHistoryModal
      templateIdentifier="sirsoft-basic"
      target={{ kind: 'layout', layoutName: 'auth/login' }}
      t={t}
      onRestored={onRestored}
      onClose={onClose}
      {...overrides}
    />,
  );
  return { onRestored, onClose };
}

describe('VersionHistoryModal — 목록/상태', () => {
  it('버전 목록을 렌더하고 변경량(추가/삭제 라인 + 문자수)을 항상 표시한다', async () => {
    mockFetch(() => listOk());
    renderModal();
    await screen.findByTestId('g7le-version-row-11');
    expect(screen.getByTestId('g7le-version-badge-11')).toHaveTextContent('v3');
    expect(screen.getByTestId('g7le-version-badge-10')).toHaveTextContent('v2');
    // 변경량은 라인 단위(추가/삭제 라인 수 + 문자수) — 0 이어도 표시. modified(~변경)는
    // 라인 diff 에 대응 개념이 없어 컬럼 자체가 없다.
    expect(screen.getByTestId('g7le-version-added-11').textContent).toContain('"count":2');
    expect(screen.getByTestId('g7le-version-removed-11').textContent).toContain('"count":0');
    expect(screen.queryByTestId('g7le-version-modified-11')).toBeNull();
    // char_diff 부호 포함 — v3 은 +40
    expect(screen.getByTestId('g7le-version-chars-11').textContent).toContain('"diff":"+40"');
    // v2 는 char_diff -10 → 부호 보존
    expect(screen.getByTestId('g7le-version-chars-10').textContent).toContain('"diff":"-10"');
    // 변경량 0 인 행도 added/removed 표시(항상)
    expect(screen.getByTestId('g7le-version-added-10').textContent).toContain('"count":0');
  });

  it('저장자 이름을 표시하고, 없으면(null) unknown 폴백', async () => {
    mockFetch(() => listOk());
    renderModal();
    await screen.findByTestId('g7le-version-row-11');
    // v3 — created_by_name '관리자'
    expect(screen.getByTestId('g7le-version-author-11').textContent).toContain('"name":"관리자"');
    // v2 — null → unknown_author 키로 폴백
    expect(screen.getByTestId('g7le-version-author-10').textContent).toContain(
      'layout_editor.version_history.unknown_author',
    );
  });

  it('첫 행(최신)에 최신 배지 + 복원 버튼 숨김, 비최신 행은 복원 버튼 노출', async () => {
    mockFetch(() => listOk());
    renderModal();
    await screen.findByTestId('g7le-version-row-11');
    // 최신(v3, idx 0) — latest 배지 O, 복원 버튼 X
    expect(screen.getByTestId('g7le-version-latest-11')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-version-restore-11')).not.toBeInTheDocument();
    // 비최신(v2) — latest 배지 X, 복원 버튼 O
    expect(screen.queryByTestId('g7le-version-latest-10')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-version-restore-10')).toBeInTheDocument();
  });

  it('목록 fetch URL 은 raw layoutName(슬래시 보존) + Bearer 토큰', async () => {
    const fetchFn = mockFetch(() => listOk());
    renderModal();
    await screen.findByTestId('g7le-version-row-11');
    const [url, init] = fetchFn.mock.calls[0];
    expect(url).toBe('/api/admin/templates/sirsoft-basic/layouts/auth/login/versions');
    expect((init?.headers as Record<string, string>).Authorization).toBe('Bearer TESTTOKEN');
  });

  it('빈 목록이면 empty 안내', async () => {
    mockFetch(() => listOk([]));
    renderModal();
    await screen.findByTestId('g7le-version-history-empty');
  });

  it('목록 fetch 실패 시 에러 표시', async () => {
    mockFetch(() => ({ ok: false, status: 500, json: async () => ({ message: 'boom' }) }));
    renderModal();
    await screen.findByTestId('g7le-version-history-error');
    expect(screen.getByTestId('g7le-version-history-error')).toHaveTextContent('boom');
  });
});

describe('VersionHistoryModal — 복원', () => {
  it('복원 확인 시 POST restore 호출 → onRestored + 모달 닫기', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const fetchFn = mockFetch((url, init) => {
      if (init?.method === 'POST') {
        return { ok: true, status: 200, json: async () => ({ success: true, data: { version: 4 } }) };
      }
      return listOk();
    });
    const { onRestored, onClose } = renderModal();
    // 비최신 행(v2, id 10)만 복원 버튼 노출 — 최신(v3)은 버튼 없음
    const restoreBtn = await screen.findByTestId('g7le-version-restore-10');
    fireEvent.click(restoreBtn);
    await waitFor(() => expect(onRestored).toHaveBeenCalledTimes(1));
    expect(onClose).toHaveBeenCalledTimes(1);
    // restore URL + POST + Bearer
    const postCall = fetchFn.mock.calls.find((c) => (c[1] as RequestInit)?.method === 'POST');
    expect(postCall?.[0]).toBe('/api/admin/templates/sirsoft-basic/layouts/auth/login/versions/10/restore');
    expect((postCall?.[1] as RequestInit | undefined as any)?.headers.Authorization).toBe('Bearer TESTTOKEN');
    confirmSpy.mockRestore();
  });

  it('복원 confirm 취소 시 POST 미호출', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const fetchFn = mockFetch(() => listOk());
    const { onRestored } = renderModal();
    const restoreBtn = await screen.findByTestId('g7le-version-restore-10');
    fireEvent.click(restoreBtn);
    await waitFor(() => expect(confirmSpy).toHaveBeenCalled());
    expect(fetchFn.mock.calls.some((c) => (c[1] as RequestInit)?.method === 'POST')).toBe(false);
    expect(onRestored).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('404 복원 → not_found 메시지', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    mockFetch((url, init) => {
      if (init?.method === 'POST') return { ok: false, status: 404, json: async () => ({}) };
      return listOk();
    });
    const { onClose } = renderModal();
    const restoreBtn = await screen.findByTestId('g7le-version-restore-10');
    fireEvent.click(restoreBtn);
    await screen.findByTestId('g7le-version-history-error');
    expect(screen.getByTestId('g7le-version-history-error')).toHaveTextContent(
      'layout_editor.version_history.restore_not_found',
    );
    expect(onClose).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });
});

describe('VersionHistoryModal — 비교(diff)', () => {
  /**
   * showVersion 단건 content mock — full_content 우선 사용 검증용.
   * components(분해 키)는 일부러 비워 두고, 실제 컴포넌트는 full_content.slots 에 둔다.
   * → diff 가 full_content 를 우선 쓰지 않으면 항상 identical 로 잘못 나온다(결함 재현 가드).
   */
  function versionDetailOk(version: number) {
    return {
      ok: true,
      status: 200,
      json: async () => ({
        success: true,
        data: {
          version,
          endpoint: null,
          components: [], // 분해 키는 비어 있음(extends 기반 레이아웃 모사)
          data_sources: [],
          metadata: [],
          // 버전마다 slots 컴포넌트 개수를 달리해 diff 가 생기도록
          full_content: {
            slots: {
              content: Array.from({ length: version }, (_, i) => ({ name: 'Div', id: `c${i}` })),
            },
          },
        },
      }),
    };
  }

  it('비교 버튼 클릭 시 직전 버전과 fetch 후 diff 뷰 전환', async () => {
    const fetchFn = mockFetch((url) => {
      // GET .../versions/3 또는 .../versions/2 (단건 detail)
      const m = /\/versions\/(\d+)$/.exec(url);
      if (m) return versionDetailOk(Number(m[1]));
      return listOk();
    });
    renderModal();
    // v3(id 11) 행의 비교 버튼
    const compareBtn = await screen.findByTestId('g7le-version-compare-11');
    fireEvent.click(compareBtn);
    // diff 뷰로 전환
    await screen.findByTestId('g7le-version-diff');
    expect(screen.getByTestId('g7le-version-diff-title').textContent).toContain('"old":2');
    expect(screen.getByTestId('g7le-version-diff-title').textContent).toContain('"new":3');
    // v3(3개) vs v2(2개) → 라인 추가 발생(diff hunk 존재)
    expect(screen.getByTestId('g7le-version-diff-hunk-0')).toBeInTheDocument();
    // 두 버전 detail 을 GET 으로 가져왔는지(POST 아님)
    const detailCalls = fetchFn.mock.calls.filter((c) => /\/versions\/\d+$/.test(c[0] as string));
    expect(detailCalls.length).toBe(2);
  });

  it('diff 뷰에서 목록(back)으로 복귀', async () => {
    mockFetch((url) => {
      const m = /\/versions\/(\d+)$/.exec(url);
      if (m) return versionDetailOk(Number(m[1]));
      return listOk();
    });
    renderModal();
    fireEvent.click(await screen.findByTestId('g7le-version-compare-11'));
    await screen.findByTestId('g7le-version-diff');
    fireEvent.click(screen.getByTestId('g7le-version-diff-back'));
    // 목록 복귀
    await screen.findByTestId('g7le-version-history-list');
    expect(screen.queryByTestId('g7le-version-diff')).not.toBeInTheDocument();
  });

  it('최초 버전(직전 없음)은 빈 기준과 비교 → 전체 추가 diff', async () => {
    // v1 만 존재하도록 목록 교체 + detail
    mockFetch((url) => {
      const m = /\/versions\/(\d+)$/.exec(url);
      if (m) return versionDetailOk(Number(m[1]));
      // 목록은 v1 단건
      return {
        ok: true,
        json: async () => ({
          success: true,
          data: [
            {
              id: 1,
              layout_id: 1,
              version: 1,
              changes_summary: { added_count: 5, removed_count: 0, char_diff: 100 },
              created_at: '2026-06-01T00:00:00+00:00',
              created_by_name: 'admin',
            },
          ],
        }),
      };
    });
    renderModal();
    fireEvent.click(await screen.findByTestId('g7le-version-compare-1'));
    await screen.findByTestId('g7le-version-diff');
    // 직전 없음 → old=0 표기, 전체 add
    expect(screen.getByTestId('g7le-version-diff-title').textContent).toContain('"old":0');
    expect(screen.getByTestId('g7le-version-diff-added')).toBeInTheDocument();
  });
});

describe('VersionHistoryModal — 확장 타겟', () => {
  it('extension 타겟이면 layout-extensions 버전 API 로 목록을 조회한다', async () => {
    const calls: string[] = [];
    mockFetch((url: string) => {
      calls.push(url);
      return listOk();
    });
    renderModal({ target: { kind: 'extension', extensionId: '7' } });
    await screen.findByTestId('g7le-version-row-11');
    expect(
      calls.some((u) =>
        u.includes('/api/admin/templates/sirsoft-basic/layout-extensions/7/versions'),
      ),
    ).toBe(true);
  });

  it('extension 타겟 복원은 layout-extensions restore API 를 호출하고 onRestored 에 새 버전을 전달한다', async () => {
    const calls: Array<{ url: string; method?: string }> = [];
    mockFetch((url: string, init?: RequestInit) => {
      calls.push({ url, method: init?.method });
      if (url.includes('/restore')) {
        return { ok: true, json: async () => ({ success: true, data: { version: 9 } }) };
      }
      return listOk();
    });
    const onRestored = vi.fn();
    renderModal({ target: { kind: 'extension', extensionId: '7' }, onRestored });
    await screen.findByTestId('g7le-version-row-10');

    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    fireEvent.click(screen.getByTestId('g7le-version-restore-10'));
    await waitFor(() => {
      expect(onRestored).toHaveBeenCalledWith(9);
    });
    confirmSpy.mockRestore();

    expect(
      calls.some(
        (c) =>
          c.method === 'POST' &&
          c.url.includes('/layout-extensions/7/versions/10/restore'),
      ),
    ).toBe(true);
  });
});
