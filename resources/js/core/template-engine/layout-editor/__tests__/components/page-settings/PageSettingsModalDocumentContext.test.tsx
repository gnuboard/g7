// e2e:allow 통합 토폴로지 회귀 — EditorModalRoot 가 LayoutDocumentProvider 안에 마운트돼 모달이 라이브 문서를 read/write 하는지. 라이브 결선 SSoT 는 Chrome MCP 매트릭스(세션 D 검증).
/**
 * PageSettingsModalDocumentContext.test.tsx — 모달-문서 컨텍스트 라이브 결선 회귀
 *
 * 배경: LayoutEditorChrome 가 `EditorModalRoot` 를 `LayoutDocumentProvider` **밖**
 * (Body 의 형제)에 마운트하면, 모달 content 안의 `usePageSettings()` → `useLayoutDocumentContext()`
 * 가 null 을 받아 **모든 탭이 빈 값**을 읽고 **patch 가 no-op** 이 된다. content 를 open 시점
 * activeDocument 스냅샷 provider 로 감싸도 **read 만 고쳐지고 write 는 미반영**(스냅샷 stale).
 *
 * 수정: `EditorModalRoot` 를 `LayoutDocumentProvider` **안**으로 이동 → 모달이 매 렌더 **라이브**
 * 문서 컨텍스트를 읽어 read + write(patch 후 갱신값 재노출)가 모두 round-trip 된다.
 *
 * 본 테스트는 그 라이브 토폴로지를 재현: 상태형 문서(raw + patchDocumentRaw)를 provider 로 내리고
 * EditorModalRoot 를 그 안에 둔다. 모달이 meta.title 을 읽고(read), 버튼으로 patch 한 뒤(write)
 * 같은 모달이 갱신값을 재노출함을 단언한다. EditorModalRoot 가 provider 밖이면 빈값 → 단언 실패.
 *
 * 단위 PageSettingsModal.test 는 usePageSettings 를 모킹해 이 토폴로지 결함을 가렸다
 * (라이브에서만 검출, [[feedback_editor_keyify_verify_db_layout_content_persist_not_just_keyvalue]]).
 */

import React, { useCallback, useMemo, useState } from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { LayoutDocumentProvider } from '../../../LayoutDocumentContext';
import { EditorModalProvider, EditorModalRoot, useEditorModal } from '../../../EditorModalContext';
import { usePageSettings } from '../../../hooks/usePageSettings';
import type { UseLayoutDocumentResult } from '../../../hooks/useLayoutDocument';

vi.mock('../../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: { selectedRoute: { path: '/x', layoutName: 'x' }, templateIdentifier: 't' },
    dispatch: () => {},
  }),
}));

/** 모달 안 컨슈머 — usePageSettings 로 meta.title 읽고(read) 버튼으로 patch(write). */
function ModalConsumer(): React.ReactElement {
  const { getValue, patch } = usePageSettings();
  const meta = getValue<Record<string, unknown>>('meta', {});
  const title = String((meta as { title?: unknown })?.title ?? '(empty)');
  return (
    <div>
      <div data-testid="modal-title">{title}</div>
      <button
        type="button"
        data-testid="patch-btn"
        onClick={() => patch('meta', { ...(meta as object), title: '바뀐 제목' })}
      >
        patch
      </button>
    </div>
  );
}

function Body(): React.ReactElement {
  const modal = useEditorModal();
  return (
    <button
      type="button"
      data-testid="open-btn"
      onClick={() => modal.open({ ariaLabel: 'page-settings', content: <ModalConsumer /> })}
    >
      open
    </button>
  );
}

/** 상태형 문서 — patchDocumentRaw 가 raw 를 갱신하고 리렌더(라이브 라운드트립). */
function StatefulDocHost({ insideProvider }: { insideProvider: boolean }): React.ReactElement {
  const [raw, setRaw] = useState<Record<string, unknown>>({ meta: { title: '처음 제목' } });
  const patchDocumentRaw = useCallback((key: string, value: unknown) => {
    setRaw((prev) => ({ ...prev, [key]: value }));
  }, []);
  const docValue = useMemo<UseLayoutDocumentResult>(
    () => ({ document: { raw }, patchDocumentRaw } as unknown as UseLayoutDocumentResult),
    [raw, patchDocumentRaw],
  );

  // insideProvider=true: EditorModalRoot 가 provider 안(수정). false: 밖(결함 재현).
  return (
    <EditorModalProvider>
      {insideProvider ? (
        <LayoutDocumentProvider value={docValue}>
          <Body />
          <EditorModalRoot />
        </LayoutDocumentProvider>
      ) : (
        <>
          <LayoutDocumentProvider value={docValue}>
            <Body />
          </LayoutDocumentProvider>
          <EditorModalRoot />
        </>
      )}
    </EditorModalProvider>
  );
}

describe('PageSettings 모달 라이브 문서 결선', () => {
  it('수정: EditorModalRoot 가 provider 안이면 모달이 read + write(patch 라운드트립) 둘 다 한다', () => {
    render(<StatefulDocHost insideProvider={true} />);
    fireEvent.click(screen.getByTestId('open-btn'));
    // read
    expect(screen.getByTestId('modal-title').textContent).toBe('처음 제목');
    // write → 같은 모달이 갱신값 재노출(라이브)
    act(() => {
      fireEvent.click(screen.getByTestId('patch-btn'));
    });
    expect(screen.getByTestId('modal-title').textContent).toBe('바뀐 제목');
  });

  it('결함 재현: EditorModalRoot 가 provider 밖이면 read 빈값 + write no-op', () => {
    render(<StatefulDocHost insideProvider={false} />);
    fireEvent.click(screen.getByTestId('open-btn'));
    expect(screen.getByTestId('modal-title').textContent).toBe('(empty)');
    act(() => {
      fireEvent.click(screen.getByTestId('patch-btn'));
    });
    // provider 밖이라 patch no-op + read 여전히 빈값
    expect(screen.getByTestId('modal-title').textContent).toBe('(empty)');
  });
});
