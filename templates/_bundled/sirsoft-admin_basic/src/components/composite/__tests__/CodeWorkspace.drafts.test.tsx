import React from 'react';
import { render, screen, fireEvent, act, renderHook } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { CodeWorkspace } from '../CodeWorkspace';
import { useFileDrafts } from '../../../hooks/useFileDrafts';

/**
 * CodeWorkspace — 초안 영속 (결정 D-4).
 *
 * ## 왜 자동 복원하지 않는가
 *
 * 브라우저에 남은 초안을 말없이 편집기에 밀어 넣으면, 어제 다른 사람이 남긴 편집이 오늘 내 화면에서
 * **서버 본문을 덮어쓴 것처럼** 보인다. 그 상태로 저장하면 남의 미완성 편집이 실제로 배포된다.
 * 그래서 초안은 배너로 **보여 주기만** 하고, 복원할지는 사용자가 정한다.
 */

vi.mock('@monaco-editor/react', () => ({
  // `options.readOnly` 를 실제 textarea 로 흘린다 — 잠금 여부를 목이 삼키면 «없는 파일은
  // 저장할 수 없다» 계약을 테스트가 검증하지 못한다.
  default: ({ defaultValue: value, onChange, options }: any) => (
    <textarea
      data-testid="editor-textarea"
      value={value ?? ''}
      readOnly={!!options?.readOnly}
      onChange={(event) => onChange?.(event.target.value)}
    />
  ),
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

const NAMESPACE = 'g7le.codeEditor.test';
const SERVER_A = '<layout name="a"/>';
/** 초안은 관리자 회원마다 갈린다(#639 ⑩) — 저장 키에 로그인 회원 uuid 가 들어간다 */
const MEMBER_UUID = 'admin-test';
const DRAFTS_KEY = `${NAMESPACE}.${MEMBER_UUID}.drafts`;

beforeEach(() => {
  window.localStorage.clear();
  vi.useFakeTimers({ shouldAdvanceTime: true });
  (window as any).G7Core = {
    ...((window as any).G7Core ?? {}),
    AuthManager: { getInstance: () => ({ getUser: () => ({ uuid: MEMBER_UUID }) }) },
  };
});

afterEach(() => {
  vi.useRealTimers();
  if ((window as any).G7Core) {
    delete (window as any).G7Core.AuthManager;
  }
});

function storedDrafts(): Record<string, { value: string; baseRevision: number }> {
  return JSON.parse(window.localStorage.getItem(DRAFTS_KEY) ?? '{}');
}

describe('CodeWorkspace — 초안 저장', () => {
  /** @effects code_workspace_writes_drafts_to_local_storage */
  it('편집분을 debounce 뒤 localStorage 에 적어 둔다', () => {
    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'typed' } });

    // debounce 전에는 아직 없다 — 글자마다 직렬화하면 큰 파일에서 입력이 끊긴다.
    expect(storedDrafts()['layout:a']).toBeUndefined();

    act(() => {
      vi.advanceTimersByTime(2500);
    });

    expect(storedDrafts()['layout:a'].value).toBe('typed');
    // 기준 판본을 함께 적어 둬야 복원할 때 그 사이 저장이 있었는지 가를 수 있다.
    expect(storedDrafts()['layout:a'].baseRevision).toBe(3);
  });

  /** @effects code_workspace_drops_draft_after_save */
  it('저장에 성공해 clean 이 되면 그 파일의 초안을 지운다', () => {
    const mine = 'typed';

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: mine } });

    act(() => {
      vi.advanceTimersByTime(2500);
    });

    expect(storedDrafts()['layout:a']).toBeDefined();

    // 서버가 방금 저장한 본문을 새 판본으로 돌려준다.
    rerender(
      <CodeWorkspace fileKey="layout:a" serverValue={mine} serverRevision={4} draftNamespace={NAMESPACE} />,
    );

    expect(storedDrafts()['layout:a']).toBeUndefined();
  });
});

describe('CodeWorkspace — 초안 복원', () => {
  /** @effects code_workspace_offers_drafts_instead_of_restoring_them */
  it('진입 시 초안이 있으면 배너로 알리되 편집기를 건드리지 않는다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:a': { value: 'yesterday', savedAt: Date.now() - 60000, baseRevision: 3 } }),
    );

    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    expect(screen.getByTestId('draft-restore-banner')).toBeInTheDocument();
    // 자동으로 들어가지 않는다 — 편집기는 서버 본문 그대로다.
    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe(SERVER_A);
  });

  /** @effects code_workspace_restores_drafts_on_demand */
  it('[복원] 을 누르면 그때 편집기에 들어간다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:a': { value: 'yesterday', savedAt: Date.now() - 60000, baseRevision: 3 } }),
    );

    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.click(screen.getByTestId('draft-restore'));

    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('yesterday');
    expect(screen.queryByTestId('draft-restore-banner')).not.toBeInTheDocument();
  });

  /**
   * 지금 열려 있지 않은 파일의 초안도 복원한 그대로 남는다.
   *
   * 실측 2026-10-06(7.1.0 전수 실측 I1): 배너 「저장하지 않은 편집 2건 — probe_a · _admin_base」 에서
   * [복원] 을 누른 뒤 probe_a 탭을 열자 서버 본문(73자)만 보이고 복원한 초안(46,215자)이 사라졌다.
   * 열려 있지 않은 파일은 서버 본문을 모르니 기준선을 초안 자신으로 잡아 「바뀐 것 없음」 이 됐고,
   * 본문이 도착하자 「깨끗한 버퍼는 최신본을 받는다」 규칙이 초안을 덮어썼다.
   *
   * @effects code_workspace_restores_drafts_of_unopened_files
   */
  it('[복원] 한 다른 파일의 초안은 그 파일을 열어 본문이 와도 덮이지 않는다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:b': { value: 'draft-b', savedAt: Date.now() - 60000, baseRevision: 5 } }),
    );

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.click(screen.getByTestId('draft-restore'));

    // b 로 옮긴다 — 처음에는 아직 a 의 본문을 들고 있고, 이어서 b 의 서버 본문이 도착한다.
    rerender(
      <CodeWorkspace
        fileKey="layout:b"
        serverKey="layout:a"
        serverValue={SERVER_A}
        serverRevision={3}
        draftNamespace={NAMESPACE}
      />,
    );
    rerender(
      <CodeWorkspace
        fileKey="layout:b"
        serverKey="layout:b"
        serverValue="<layout name=&quot;b&quot;/>"
        serverRevision={5}
        draftNamespace={NAMESPACE}
      />,
    );

    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('draft-b');
    // 서버 본문과 다르므로 저장하지 않은 파일로 보인다.
    expect(screen.getByRole('tab', { name: /저장되지 않음|unsaved|editor\.unsaved/i })).toBeInTheDocument();
  });

  /** @effects code_workspace_discards_drafts_on_demand */
  it('[버리기] 를 누르면 저장소에서 지운다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:a': { value: 'yesterday', savedAt: Date.now() - 60000, baseRevision: 3 } }),
    );

    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.click(screen.getByTestId('draft-discard'));

    expect(storedDrafts()).toEqual({});
    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe(SERVER_A);
  });

  /** @effects code_workspace_shows_no_banner_without_drafts */
  it('초안이 없으면 배너를 세우지 않는다', () => {
    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    expect(screen.queryByTestId('draft-restore-banner')).not.toBeInTheDocument();
  });

  /**
   * 저장소가 막힌 환경(사생활 보호 창·차단된 사이트 데이터)에서도 편집은 계속돼야 한다.
   *
   * 초안은 편의 장치이지 정본이 아니다 — 여기서 예외가 새어 나가면 편집기 자체가 뜨지 않는다.
   *
   * @effects code_workspace_survives_blocked_local_storage
   */
  it('localStorage 접근이 막혀도 편집기는 선다', () => {
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });

    try {
      render(
        <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
      );

      fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'typed' } });

      act(() => {
        vi.advanceTimersByTime(2500);
      });

      expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('typed');
    } finally {
      getItem.mockRestore();
      setItem.mockRestore();
    }
  });
});

/**
 * 동의 게이트가 걸린 저장소 — GDPR 플러그인이 `Storage.prototype.setItem` 을 가로채는 환경.
 *
 * 그 게이트는 strictly-necessary 허용목록 · functional 동의 · user-initiated 면제(마지막
 * 인터랙션 후 500ms) 중 하나에 걸릴 때만 쓰기를 통과시킨다. 초안 쓰기는 debounce 가 2,000ms 라
 * 타이핑 뒤 언제나 그 창 **밖**에서 발화한다 — 그래서 functional 미동의 사이트에서는 초안이
 * 한 번도 저장되지 않았다(실측 2026-09-12: 신뢰된 키 입력 뒤 6초간 `{}` 유지).
 *
 * 떠나는 순간의 신뢰된 이벤트 안에서 적으면 면제를 타고 통과한다. 마침 "복원할까요" 가 필요한
 * 순간이 바로 그 순간이다.
 */
describe('[case:components-misc-consent-gated-local-storage] CodeWorkspace — 동의 게이트가 걸린 저장소', () => {
  /**
   * debounce 쓰기만 막고 이벤트 안 쓰기는 통과시키는 게이트를 세운다.
   *
   * @returns 게이트 해제 함수와 통과한 쓰기 수를 세는 카운터
   */
  function installUserInitiatedGate(): { restore: () => void; blocked: () => number } {
    const real = Storage.prototype.setItem;
    let insideEvent = false;
    let blockedCount = 0;

    const mark = (): void => {
      insideEvent = true;
    };

    window.addEventListener('pagehide', mark, true);
    window.addEventListener('beforeunload', mark, true);

    const spy = vi
      .spyOn(Storage.prototype, 'setItem')
      .mockImplementation(function (this: Storage, key: string, value: string) {
        if (!insideEvent) {
          blockedCount += 1;

          return;
        }

        real.call(this, key, value);
      });

    return {
      restore: () => {
        spy.mockRestore();
        window.removeEventListener('pagehide', mark, true);
        window.removeEventListener('beforeunload', mark, true);
      },
      blocked: () => blockedCount,
    };
  }

  /** @effects code_workspace_flushes_drafts_on_pagehide */
  it('debounce 쓰기가 막혀도 탭을 닫을 때 초안이 남는다', () => {
    const gate = installUserInitiatedGate();

    try {
      render(
        <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
      );

      fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'typed-then-left' } });

      act(() => {
        vi.advanceTimersByTime(2500);
      });

      // debounce 는 인터랙션 창 밖이라 게이트에 막혔다 — 여기까지가 수정 전 상태다.
      expect(gate.blocked()).toBeGreaterThan(0);
      expect(storedDrafts()['layout:a']).toBeUndefined();

      act(() => {
        window.dispatchEvent(new Event('pagehide'));
      });

      expect(storedDrafts()['layout:a']?.value).toBe('typed-then-left');
    } finally {
      gate.restore();
    }
  });

  /** @effects code_workspace_flushes_drafts_on_visibility_hidden */
  it('화면이 감춰질 때도 초안을 적어 둔다', () => {
    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'hidden-draft' } });

    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');

    try {
      act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
      });

      expect(storedDrafts()['layout:a']?.value).toBe('hidden-draft');
    } finally {
      visibility.mockRestore();
    }
  });
});

/**
 * 인터랙션 재시도 — 떠나는 이벤트로는 게이트를 통과하지 못하는 환경.
 *
 * 실측 2026-09-12: `pagehide`·`beforeunload`·`visibilitychange` 핸들러 안의 쓰기가 모두
 * 버려졌다(떠나는 이벤트에는 `window.event` 가 실리지 않고 마지막 인터랙션도 500ms 를 넘긴다).
 * 남은 통로는 **신뢰된 입력 이벤트 안** 뿐이다.
 */
/**
 * 신뢰된 키 입력을 흉내 내어 dispatch 한다.
 *
 * jsdom 이 만드는 합성 이벤트는 `isTrusted` 가 언제나 false 이고, 인스턴스의 그 속성은
 * 재정의할 수 없다(`Cannot redefine property`). 재시도는 **사용자가 실제로 친 입력** 안에서만
 * 도는 것이 요점이라 훅이 그 값을 보므로, 프로토타입 접근자를 그 순간에만 바꿔 끼운다.
 *
 * @returns void
 */
function dispatchTrustedKeydown(): void {
  const descriptor = Object.getOwnPropertyDescriptor(Event.prototype, 'isTrusted');

  Object.defineProperty(Event.prototype, 'isTrusted', { configurable: true, get: () => true });

  try {
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'x', bubbles: true }));
  } finally {
    if (descriptor) {
      Object.defineProperty(Event.prototype, 'isTrusted', descriptor);
    }
  }
}

describe('CodeWorkspace — 버려진 초안 쓰기 재시도', () => {
  /** @effects code_workspace_retries_dropped_draft_write_on_next_interaction */
  it('조용히 버려진 쓰기를 다음 키 입력 안에서 다시 적는다', () => {
    const real = Storage.prototype.setItem;
    let insideInteraction = false;

    const spy = vi
      .spyOn(Storage.prototype, 'setItem')
      .mockImplementation(function (this: Storage, key: string, value: string) {
        // 게이트: 인터랙션 핸들러 안에서만 통과. 예외를 던지지 않고 **조용히** 버린다.
        if (!insideInteraction) {
          return;
        }

        real.call(this, key, value);
      });

    try {
      render(
        <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
      );

      fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'dropped-then-retried' } });

      act(() => {
        vi.advanceTimersByTime(2500);
      });

      // debounce 쓰기는 버려졌다 — 예외가 없으므로 훅은 되읽기로만 알 수 있다.
      expect(storedDrafts()['layout:a']).toBeUndefined();

      // 다음 신뢰된 키 입력이 오면 그 핸들러 안에서 다시 쓴다.
      insideInteraction = true;

      act(() => {
        dispatchTrustedKeydown();
      });

      expect(storedDrafts()['layout:a']?.value).toBe('dropped-then-retried');
    } finally {
      spy.mockRestore();
    }
  });

  /** @effects code_workspace_does_not_rewrite_when_storage_accepts */
  it('쓰기가 정상 반영된 환경에서는 입력마다 다시 쓰지 않는다', () => {
    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'accepted' } });

    act(() => {
      vi.advanceTimersByTime(2500);
    });

    expect(storedDrafts()['layout:a']?.value).toBe('accepted');

    const spy = vi.spyOn(Storage.prototype, 'setItem');

    act(() => {
      dispatchTrustedKeydown();
    });

    expect(spy).not.toHaveBeenCalled();

    spy.mockRestore();
  });
});

/**
 * 초안 저장소 공유 — 브라우저 실측 2026-09-12 (제보 후속).
 *
 * 종전 `flush()` 는 메모리의 맵을 통째로 `setItem` 해 저장소를 갈아치웠다. 그래서
 * **편집기를 두 탭에 띄우면 한쪽이 다른 쪽 초안을 말없이 지웠다.** 실측에서 양방향으로
 * 드러났다 — 한 탭에서 지운 초안이 다른 탭의 언마운트 flush 로 되살아났고(11시간 전
 * 초안 3건), 반대로 주입한 초안은 새로고침 한 번에 사라졌다.
 */
describe('CodeWorkspace — 초안 저장소는 남의 것을 지우지 않는다', () => {
  /** @effects code_workspace_merges_drafts_from_other_tabs */
  it('다른 탭이 적어 둔 초안을 덮어쓰지 않는다', () => {
    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    // **마운트 뒤에** 다른 탭이 적는다 — 이 문서의 메모리 맵에는 없는 키다. 진입 시점에
    // 이미 있던 초안으로 재면 통째 덮어쓰기여도 통과해 버려 변별력이 없다.
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:other': { value: 'from-other-tab', savedAt: Date.now(), baseRevision: 1 } }),
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'mine' } });

    act(() => {
      vi.advanceTimersByTime(2500);
    });

    // 내 것이 들어가면서 남의 것이 함께 남아야 한다.
    expect(storedDrafts()['layout:a'].value).toBe('mine');
    expect(storedDrafts()['layout:other'].value).toBe('from-other-tab');
  });

  /**
   * 다른 탭이 지운 초안을 이 탭의 병합이 되살리지 않는다.
   *
   * 실측 2026-10-06(7.1.0 전수 실측 I1): 탭2 에서 `_admin_base` 를 고치다 [저장하지 않고 닫기] 로
   * 버려 저장소에서 지워졌는데, 그 사이 탭1 이 병합하며 받아 둔 사본을 다음 쓰기(새로고침 직전)에
   * 도로 적어 「저장하지 않은 편집 2건 — probe_a · _admin_base」 로 다시 떴다. 병합으로 받은 남의
   * 초안 사본은 이 탭의 것이 아니므로 다시 적을 권한이 없다.
   *
   * @effects code_workspace_does_not_resurrect_drafts_removed_by_other_tab
   */
  it('다른 탭이 지운 초안을 다음 병합에서 되살리지 않는다', () => {
    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    // 다른 탭이 b 를 적는다.
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:b': { value: 'from-other-tab', savedAt: Date.now(), baseRevision: 1 } }),
    );

    // 이 탭이 a 를 적는다 — 병합하며 b 를 함께 본다.
    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'mine-1' } });
    act(() => {
      vi.advanceTimersByTime(2500);
    });
    expect(storedDrafts()['layout:b']).toBeDefined();

    // 다른 탭이 b 를 버린다(저장하지 않고 닫기·저장 성공).
    const stored = storedDrafts();
    delete (stored as Record<string, unknown>)['layout:b'];
    window.localStorage.setItem(DRAFTS_KEY, JSON.stringify(stored));

    // 이 탭이 다시 적는다.
    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'mine-2' } });
    act(() => {
      vi.advanceTimersByTime(2500);
    });

    expect(storedDrafts()['layout:a'].value).toBe('mine-2');
    expect(storedDrafts()['layout:b']).toBeUndefined();
  });

  /** @effects code_workspace_delete_beats_merge */
  it('내가 지운 초안은 병합이 되살리지 않는다', () => {
    const { rerender } = render(
      <CodeWorkspace
        fileKey="layout:a"
        serverValue={SERVER_A}
        serverRevision={3}
        draftNamespace={NAMESPACE}
      />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'typed' } });
    act(() => {
      vi.advanceTimersByTime(2500);
    });
    expect(storedDrafts()['layout:a']).toBeDefined();

    // 저장에 성공해 clean 이 되면 그 초안은 지워진다.
    rerender(
      <CodeWorkspace
        fileKey="layout:a"
        serverValue="typed"
        serverRevision={4}
        draftNamespace={NAMESPACE}
      />,
    );

    // 뒤이은 쓰기가 병합을 한 번 더 돌린다 — 그때 되살아나면 안 된다.
    act(() => {
      vi.advanceTimersByTime(2500);
    });

    // 병합이 저장소에 남아 있던 옛 값을 되살려서는 안 된다.
    expect(storedDrafts()['layout:a']).toBeUndefined();
  });

  /**
   * 진입 때 읽어 둔 **남의 초안 사본**은 내 변경이 아니다 — 그 뒤 다른 탭이 같은 키에 더 새 초안을 적었으면
   * 그쪽이 남아야 한다. 종전 병합은 «내 맵이 이긴다» 라 옛 사본이 새 초안을 덮었다(실측 2026-10-03:
   * 다른 탭이 남긴 새 초안이, 진입 때 읽어 둔 옛 「broken」 사본으로 되돌아갔다).
   *
   * @scenario branch=layout, buffer_state=dirty, leave=reload
   * @effects code_workspace_keeps_newer_draft_from_other_tab
   */
  it('진입 때 읽어 둔 옛 사본이 그 뒤 다른 탭이 적은 새 초안을 덮지 않는다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:other': { value: 'old-copy', savedAt: Date.now(), baseRevision: 1 } }),
    );

    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    // 마운트 뒤 다른 탭이 같은 키에 더 새 초안을 적는다.
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:other': { value: 'newer-from-other-tab', savedAt: Date.now() + 60_000, baseRevision: 1 } }),
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'mine' } });
    act(() => {
      vi.advanceTimersByTime(2500);
    });

    expect(storedDrafts()['layout:a'].value).toBe('mine');
    expect(storedDrafts()['layout:other'].value).toBe('newer-from-other-tab');
  });

  /**
   * 내가 지운 키라도, 지운 **뒤에** 다른 탭이 새로 적은 초안은 지우지 않는다 — 삭제는 지운 시점까지의 것에만
   * 이긴다. 그렇지 않으면 한 탭이 저장으로 지운 파일을 다른 탭이 계속 고칠 때 그 탭의 초안이 사라진다.
   *
   * @scenario branch=layout, buffer_state=dirty, leave=reload
   * @effects code_workspace_keeps_newer_draft_from_other_tab
   */
  it('내가 지운 뒤 다른 탭이 새로 적은 같은 키 초안은 남긴다', () => {
    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'typed' } });
    act(() => {
      vi.advanceTimersByTime(2500);
    });

    // 저장 성공 — 이 문서는 layout:a 초안을 지운다.
    rerender(
      <CodeWorkspace fileKey="layout:a" serverValue="typed" serverRevision={4} draftNamespace={NAMESPACE} />,
    );
    act(() => {
      vi.advanceTimersByTime(2500);
    });
    expect(storedDrafts()['layout:a']).toBeUndefined();

    // 그 뒤 다른 탭이 같은 파일을 고쳐 새 초안을 적는다.
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:a': { value: 'other-tab-after-save', savedAt: Date.now() + 60_000, baseRevision: 4 } }),
    );

    // 이 문서가 다른 파일을 고쳐 쓰기를 한 번 더 돌린다.
    rerender(
      <CodeWorkspace fileKey="layout:b" serverValue="<layout name=&quot;b&quot;/>" serverRevision={1} draftNamespace={NAMESPACE} />,
    );
    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'b-edit' } });
    act(() => {
      vi.advanceTimersByTime(2500);
    });

    expect(storedDrafts()['layout:b'].value).toBe('b-edit');
    expect(storedDrafts()['layout:a']?.value).toBe('other-tab-after-save');
  });
});

/**
 * 초안 수명 — 결정 2026-09-12.
 *
 * 실측에서 전날 19:21 초안 3건이 11시간 뒤에도 배너에 올라왔다. 수명이 없으면 초안은
 * 영원히 남아, 진짜 최근 편집분이 그 안에 묻힌다.
 */
describe('CodeWorkspace — 초안 수명', () => {
  /** @effects code_workspace_expires_stale_drafts */
  it('7일이 지난 초안은 배너에 올리지 않는다', () => {
    const EIGHT_DAYS = 8 * 24 * 60 * 60 * 1000;

    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({
        'layout:stale': { value: 'old', savedAt: Date.now() - EIGHT_DAYS, baseRevision: 1 },
        'layout:fresh': { value: 'new', savedAt: Date.now(), baseRevision: 1 },
      }),
    );

    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    const banner = screen.getByTestId('draft-restore-banner');
    expect(banner.textContent).toContain('fresh');
    expect(banner.textContent).not.toContain('stale');
  });

  /** @effects code_workspace_prunes_expired_drafts_from_storage */
  it('수명이 지난 초안은 다음 쓰기에서 저장소에서도 걷힌다', () => {
    const EIGHT_DAYS = 8 * 24 * 60 * 60 * 1000;

    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({
        'layout:stale': { value: 'old', savedAt: Date.now() - EIGHT_DAYS, baseRevision: 1 },
      }),
    );

    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} draftNamespace={NAMESPACE} />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'typed' } });
    act(() => {
      vi.advanceTimersByTime(2500);
    });

    expect(storedDrafts()['layout:stale']).toBeUndefined();
    expect(storedDrafts()['layout:a'].value).toBe('typed');
  });
});

/**
 * 없어진 파일의 초안 — 브라우저 실측 2026-09-12 (제보).
 *
 * 초안은 파일보다 오래 산다. 복원이 «없는 파일» 의 탭을 세웠고, 그 탭을 누르면
 * `GET .../layouts/<없는이름>` 이 404 를 냈다. 화면에는 빈 편집기와 코어의 범용 문구
 * «요청한 리소스를 찾을 수 없습니다» 만 남았고, 복원했다고 알린 편집분은 어디에도 없었다
 * (실측: 편집기 길이 0 · 탭의 미저장 ● 없음 · 초안은 저장소에 그대로).
 *
 * 결정 2026-09-12 — 조용히 버리지 않는다. «없는 파일» 로 표시하고 본문은 읽기 전용으로
 * 보여 준 뒤 [새 이름으로 저장] / [버리기] 를 준다.
 */
describe('CodeWorkspace — 없어진 파일의 초안', () => {
  const KNOWN = ['layout:a', 'layout:b'];

  /** @effects code_workspace_marks_missing_file_tab */
  it('서버에 없는 키는 탭에 「없는 파일」 로 표시한다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:gone': { value: '<!--GHOST-->', savedAt: Date.now(), baseRevision: 2 } }),
    );

    render(
      <CodeWorkspace
        fileKey="layout:a"
        serverValue={SERVER_A}
        serverRevision={3}
        knownKeys={KNOWN}
        draftNamespace={NAMESPACE}
      />,
    );

    fireEvent.click(screen.getByTestId('draft-restore'));

    const tab = screen.getByTestId('file-tab-layout:gone');
    expect(tab.textContent).toContain('gone');
    // 표시는 색만으로 하지 않는다 — 아이콘 + 취소선 + aria-label 3중.
    expect(tab.querySelector('[aria-label="admin.templates.layout_edit.file.missing_badge"]')).not.toBeNull();
  });

  /** @effects code_workspace_shows_missing_file_bar_with_rescue */
  it('없는 파일을 열면 복원분을 보여 주고 살릴 길을 준다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:gone': { value: '<!--GHOST-->', savedAt: Date.now(), baseRevision: 2 } }),
    );

    const onSaveAs = vi.fn();
    const { rerender } = render(
      <CodeWorkspace
        fileKey="layout:a"
        serverValue={SERVER_A}
        serverRevision={3}
        knownKeys={KNOWN}
        onSaveAs={onSaveAs}
        draftNamespace={NAMESPACE}
      />,
    );

    fireEvent.click(screen.getByTestId('draft-restore'));

    rerender(
      <CodeWorkspace
        fileKey="layout:gone"
        serverValue={SERVER_A}
        serverKey="layout:a"
        serverRevision={3}
        knownKeys={KNOWN}
        onSaveAs={onSaveAs}
        draftNamespace={NAMESPACE}
      />,
    );

    // 안내가 서고 — 빈 화면 + 범용 404 문구가 아니다.
    expect(screen.getByTestId('missing-file-bar')).toBeTruthy();
    // 복원한 편집분이 실제로 보인다(실측에서는 길이 0 이었다).
    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('<!--GHOST-->');

    // [새 이름으로 저장] 이 그 본문과 원래 키를 들고 나간다 — 키는 살린 뒤 이 탭을 닫으라고 되돌려 받는 표지다.
    fireEvent.click(screen.getByTestId('missing-save-as'));
    expect(onSaveAs).toHaveBeenCalledWith({ target: { value: '<!--GHOST-->', key: 'layout:gone' } });
  });

  /**
   * 새 이름으로 살린 뒤에도 없는 파일 탭과 그 초안이 남으면, 이미 살린 편집분이 다음 진입에서 또 «저장하지 않은
   * 편집 1건» 으로 뜨고 「새 이름으로 저장하거나 버리십시오」 를 다시 묻는다(실측 2026-10-03). 부모가 생성 성공 뒤
   * `retiredKey` 로 그 키를 알리면 탭을 닫고 초안을 지운다.
   *
   * @scenario branch=layout, buffer_state=dirty, leave=file_switch
   * @effects code_workspace_retires_rescued_missing_file
   */
  it('새 이름으로 살린 뒤 retiredKey 로 알리면 없는 파일 탭을 닫고 초안을 지운다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:gone': { value: '<!--GHOST-->', savedAt: Date.now(), baseRevision: 2 } }),
    );

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={3} knownKeys={KNOWN} draftNamespace={NAMESPACE} />,
    );
    fireEvent.click(screen.getByTestId('draft-restore'));
    rerender(
      <CodeWorkspace
        fileKey="layout:gone"
        serverValue={SERVER_A}
        serverKey="layout:a"
        serverRevision={3}
        knownKeys={KNOWN}
        draftNamespace={NAMESPACE}
      />,
    );
    expect(screen.getByTestId('file-tab-layout:gone')).toBeTruthy();

    // 생성 성공 — 부모가 새 파일을 고르고, 살린 키를 알린다.
    rerender(
      <CodeWorkspace
        fileKey="layout:rescued"
        serverValue="<layout name=&quot;rescued&quot;/>"
        serverKey="layout:rescued"
        serverRevision={1}
        knownKeys={[...KNOWN, 'layout:rescued']}
        retiredKey="layout:gone"
        draftNamespace={NAMESPACE}
      />,
    );
    act(() => {
      vi.advanceTimersByTime(2500);
    });

    expect(screen.queryByTestId('file-tab-layout:gone')).toBeNull();
    expect(storedDrafts()['layout:gone']).toBeUndefined();
  });

  /**
   * 살리기 알림은 한 번만 처리한다. 알림(`retiredKey`)이 남아 있는 채로 같은 이름의 파일을 새로 만들어 열고
   * 다른 파일로 옮기면, 파일을 옮길 때마다 도는 효과가 옛 알림으로 그 새 탭을 다시 닫았다(검토 지적 2026-10-04).
   *
   * @scenario branch=layout, buffer_state=dirty, leave=file_switch
   * @effects code_workspace_retires_rescued_missing_file
   */
  it('처리한 살리기 알림은 같은 키로 새로 연 탭을 파일을 옮길 때 다시 닫지 않는다', () => {
    const { rerender } = render(
      <CodeWorkspace fileKey="layout:gone" serverValue={SERVER_A} serverRevision={3} knownKeys={[...KNOWN, 'layout:gone']} draftNamespace={NAMESPACE} />,
    );
    // 살리기 성공 — 부모가 새 파일을 고르고 옛 키를 알린다.
    rerender(
      <CodeWorkspace
        fileKey="layout:rescued"
        serverValue={SERVER_A}
        serverRevision={1}
        knownKeys={[...KNOWN, 'layout:rescued']}
        retiredKey="layout:gone"
        draftNamespace={NAMESPACE}
      />,
    );
    expect(screen.queryByTestId('file-tab-layout:gone')).toBeNull();

    // 같은 이름의 파일을 새로 만들어 연다 — 알림은 그대로 남아 있다.
    rerender(
      <CodeWorkspace
        fileKey="layout:gone"
        serverValue="<layout name=&quot;gone&quot;/>"
        serverKey="layout:gone"
        serverRevision={1}
        knownKeys={[...KNOWN, 'layout:rescued', 'layout:gone']}
        retiredKey="layout:gone"
        draftNamespace={NAMESPACE}
      />,
    );
    expect(screen.getByTestId('file-tab-layout:gone')).toBeTruthy();

    // 다른 파일로 옮겨도 새로 연 탭은 남는다.
    rerender(
      <CodeWorkspace
        fileKey="layout:a"
        serverValue={SERVER_A}
        serverKey="layout:a"
        serverRevision={3}
        knownKeys={[...KNOWN, 'layout:rescued', 'layout:gone']}
        retiredKey="layout:gone"
        draftNamespace={NAMESPACE}
      />,
    );
    expect(screen.getByTestId('file-tab-layout:gone')).toBeTruthy();
  });

  /** @effects code_workspace_missing_file_is_read_only */
  it('없는 파일은 저장할 수 없으므로 편집기를 잠근다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:gone': { value: '<!--GHOST-->', savedAt: Date.now(), baseRevision: 2 } }),
    );

    const { rerender } = render(
      <CodeWorkspace
        fileKey="layout:a"
        serverValue={SERVER_A}
        serverRevision={3}
        knownKeys={KNOWN}
        draftNamespace={NAMESPACE}
      />,
    );
    fireEvent.click(screen.getByTestId('draft-restore'));
    rerender(
      <CodeWorkspace
        fileKey="layout:gone"
        serverValue={SERVER_A}
        serverKey="layout:a"
        serverRevision={3}
        knownKeys={KNOWN}
        draftNamespace={NAMESPACE}
      />,
    );

    expect(screen.getByTestId('editor-textarea')).toHaveProperty('readOnly', true);
  });

  /** @effects code_workspace_known_keys_empty_marks_nothing_missing */
  it('목록을 아직 못 받았으면 아무것도 「없는 파일」 로 몰지 않는다', () => {
    window.localStorage.setItem(
      DRAFTS_KEY,
      JSON.stringify({ 'layout:gone': { value: '<!--GHOST-->', savedAt: Date.now(), baseRevision: 2 } }),
    );

    render(
      <CodeWorkspace
        fileKey="layout:gone"
        serverValue=""
        serverRevision={0}
        knownKeys={[]}
        draftNamespace={NAMESPACE}
      />,
    );
    fireEvent.click(screen.getByTestId('draft-restore'));

    expect(screen.queryByTestId('missing-file-bar')).toBeNull();
  });
});

describe('CodeWorkspace — 초안 전체 상한', () => {
  /**
   * 상한(기본 2MB)을 넘으면 **오래된 초안부터** 버린다.
   *
   * 넘친 채로 쓰면 `QuotaExceededError` 가 나 그 뒤의 모든 초안 쓰기가 조용히 실패한다 —
   * 가장 최근 편집분이 저장되지 않는 쪽이 오래된 초안이 사라지는 쪽보다 나쁘다. 상한을
   * 작게 주입해 같은 규칙을 잰다(2MB 를 실제로 채우면 테스트가 느려질 뿐 규칙은 같다).
   *
   * @effects code_workspace_trims_drafts_to_byte_cap_oldest_first
   */
  it('전체 상한을 넘으면 오래된 초안부터 버려 최근 편집분을 지킨다', () => {
    const body = 'x'.repeat(100);
    // 초안 1건 ≈ 165 bytes — 260 이면 정확히 1건만 남는다.
    const { result } = renderHook(() => useFileDrafts({ namespace: NAMESPACE, debounceMs: 0, maxBytes: 260 }));

    for (const [index, key] of ['layout:old', 'layout:mid', 'layout:new'].entries()) {
      vi.setSystemTime(new Date(Date.UTC(2026, 8, 12, 0, 0, index)));

      act(() => {
        result.current.rememberDraft(key, body, 1);
        vi.advanceTimersByTime(1);
      });
    }

    const stored = storedDrafts();

    expect(Object.keys(stored)).toEqual(['layout:new']);
    expect(JSON.stringify(stored).length).toBeLessThanOrEqual(260);
  });
});
