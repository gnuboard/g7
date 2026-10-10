import React from 'react';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { CodeWorkspace } from '../CodeWorkspace';

/**
 * CodeWorkspace — **파일별 편집 버퍼**의 수명 계약 (계획서 §4.3 표를 1:1 로 잠근다).
 *
 * ## 이 파일이 막는 결함
 *
 * 종전에는 편집 버퍼가 전역 변수 한 벌(`_global.editorMarkup`)이었다. 파일을 고르면 재조회 응답의
 * `init-global` 이 그 전역을 **무조건 덮어썼으므로**, 저장하지 않은 수정이 아무 경고 없이 사라졌다.
 * 제보된 유실이 바로 이것이다.
 *
 * 그래서 여기서 잠그는 핵심 명제는 하나다 —
 * **"버퍼가 dirty 면 `serverValue` 를 무시한다."** 이 한 줄이 되돌아가면 결함도 함께 되돌아온다.
 */

// Monaco 대역 — 값과 모델 키만 드러내면 버퍼 계약을 재는 데 충분하다. 글자 크기는 모바일 분기를
// 재기 위해, DiffEditor 는 충돌 바의 [차이 보기] 가 양쪽 본문을 제대로 넘기는지를 재기 위해 드러낸다.
vi.mock('@monaco-editor/react', () => ({
  default: ({ defaultValue: value, onChange, path, options }: any) => (
    <textarea
      data-testid="editor-textarea"
      data-model-path={path ?? ''}
      data-font-size={String(options?.fontSize ?? '')}
      value={value ?? ''}
      onChange={(event) => onChange?.(event.target.value)}
    />
  ),
  DiffEditor: ({ original, modified }: any) => (
    <div data-testid="diff-editor" data-original={original ?? ''} data-modified={modified ?? ''} />
  ),
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

const SERVER_A = '<layout name="a"/>';
const SERVER_B = '<layout name="b"/>';

beforeEach(() => {
  window.localStorage.clear();
});

afterEach(() => {
  vi.useRealTimers();
});

/** 편집기에 글자를 친다. */
function type(value: string): void {
  fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value } });
}

function editorValue(): string {
  return (screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value;
}

describe('CodeWorkspace — 버퍼 수명', () => {
  /** @effects code_workspace_seeds_buffer_from_server_on_first_visit */
  // @scenario branch=layout, buffer_state=clean, leave=file_switch
  it('처음 들어온 키는 serverValue 로 버퍼를 만든다 (clean)', () => {
    const onDirtyChange = vi.fn();

    render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onDirtyChange={onDirtyChange} />,
    );

    expect(editorValue()).toBe(SERVER_A);
    expect(onDirtyChange).toHaveBeenLastCalledWith({ target: { value: [] } });
  });

  /**
   * **D1 회귀 잠금** — 이 케이스가 빨개지면 제보된 유실이 되돌아온 것이다.
   *
   * @effects code_workspace_keeps_dirty_buffer_over_server_value
   */
  // @scenario branch=layout, buffer_state=dirty, leave=file_switch
  it('재방문 · dirty — serverValue 를 무시하고 버퍼를 보여준다', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    type('<layout name="a"/><!--MINE-->');
    expect(editorValue()).toContain('MINE');

    // 다른 파일로 갔다가
    rerender(<CodeWorkspace fileKey="layout:b" serverValue={SERVER_B} serverRevision={1} />);
    expect(editorValue()).toBe(SERVER_B);

    // 돌아온다 — 서버는 같은 본문을 다시 주지만 내 편집분이 이겨야 한다.
    rerender(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    expect(editorValue()).toContain('MINE');
  });

  /** @effects code_workspace_refreshes_clean_buffer_from_server */
  it('재방문 · clean — serverValue 로 갱신한다 (남이 고친 최신본 반영)', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    rerender(<CodeWorkspace fileKey="layout:b" serverValue={SERVER_B} serverRevision={1} />);
    rerender(<CodeWorkspace fileKey="layout:a" serverValue={'<layout name="a2"/>'} serverRevision={2} />);

    expect(editorValue()).toBe('<layout name="a2"/>');
  });

  /** @effects code_workspace_raises_conflict_instead_of_overwriting */
  it('serverRevision 이 오른 채 dirty 면 충돌 바를 세우고 **덮어쓰지 않는다**', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    type('<layout name="a"/><!--MINE-->');

    // 다른 사람이 저장했다 — 같은 파일의 판본이 올랐다.
    rerender(<CodeWorkspace fileKey="layout:a" serverValue={'<layout name="theirs"/>'} serverRevision={2} />);

    expect(screen.getByTestId('conflict-bar')).toBeInTheDocument();
    expect(editorValue()).toContain('MINE');
  });

  /** @effects code_workspace_conflict_take_server_replaces_buffer */
  it('충돌에서 [서버 것으로] 를 고르면 그때 비로소 서버 본문으로 바뀐다', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    type('<layout name="a"/><!--MINE-->');
    rerender(<CodeWorkspace fileKey="layout:a" serverValue={'<layout name="theirs"/>'} serverRevision={2} />);

    fireEvent.click(screen.getByTestId('conflict-take-server'));

    expect(editorValue()).toBe('<layout name="theirs"/>');
    expect(screen.queryByTestId('conflict-bar')).not.toBeInTheDocument();
  });

  /** @effects code_workspace_conflict_keep_mine_moves_the_baseline */
  it('충돌에서 [내 것 유지] 를 고르면 편집분은 남고 기준선만 최신 판본으로 옮긴다', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    type('<layout name="a"/><!--MINE-->');
    rerender(<CodeWorkspace fileKey="layout:a" serverValue={'<layout name="theirs"/>'} serverRevision={2} />);

    fireEvent.click(screen.getByTestId('conflict-keep-mine'));

    expect(editorValue()).toContain('MINE');
    expect(screen.queryByTestId('conflict-bar')).not.toBeInTheDocument();
  });

  /** @effects code_workspace_clears_dirty_after_save */
  it('저장에 성공해 서버 본문이 내 것과 같아지면 dirty 가 풀린다', () => {
    const onDirtyChange = vi.fn();
    const mine = '<layout name="a"/><!--MINE-->';

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onDirtyChange={onDirtyChange} />,
    );

    type(mine);
    expect(onDirtyChange).toHaveBeenLastCalledWith({ target: { value: ['layout:a'] } });

    // 저장 성공 — 서버가 방금 저장한 본문을 새 판본으로 돌려준다.
    rerender(
      <CodeWorkspace fileKey="layout:a" serverValue={mine} serverRevision={2} onDirtyChange={onDirtyChange} />,
    );

    expect(onDirtyChange).toHaveBeenLastCalledWith({ target: { value: [] } });
  });

  /**
   * 서버는 저장한 마크업을 역변환해 돌려주므로 공백·속성 표기가 정규화된다(실측 2026-10-04: 태그 안
   * 공백 세 칸을 저장하면 한 칸으로, 설치본의 `<query/>` 는 `:query="{}"` 로 돌아왔다). 글자가 달라도 그
   * 판본이 내 저장이 만든 것이면 충돌이 아니다 — 서버 본문으로 clean 이 된다.
   *
   * @scenario branch=layout, buffer_state=dirty, leave=file_switch
   * @effects code_workspace_accepts_normalized_own_save_without_conflict
   */
  it('내 저장이 정규화된 본문으로 돌아와도 충돌 바 없이 서버 본문으로 clean 이 된다', () => {
    const onDirtyChange = vi.fn();
    const mine = '<layout   name="a"/><!--MINE-->';
    const normalized = '<layout name="a"/><!--MINE-->';

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onDirtyChange={onDirtyChange} />,
    );

    type(mine);

    // 저장을 보냈다 — 아직 판본이 없다.
    rerender(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} lastSave={{ key: 'layout:a', value: mine, revision: null }} onDirtyChange={onDirtyChange} />,
    );
    // 성공 — 응답 판본이 기록되고, 다시 받은 본문은 정규화돼 있다.
    rerender(
      <CodeWorkspace fileKey="layout:a" serverValue={normalized} serverRevision={2} lastSave={{ key: 'layout:a', value: mine, revision: 2 }} onDirtyChange={onDirtyChange} />,
    );

    expect(screen.queryByTestId('conflict-bar')).not.toBeInTheDocument();
    expect(editorValue()).toBe(normalized);
    expect(onDirtyChange).toHaveBeenLastCalledWith({ target: { value: [] } });
  });

  /**
   * 저장을 보낸 뒤 응답이 오기 전에 더 친 글자는 아직 저장되지 않았다 — 지우지 않고 dirty 로 남긴다.
   * 기준선은 내 저장의 판본으로 옮겨 충돌로 세우지 않는다.
   *
   * @scenario branch=layout, buffer_state=dirty, leave=file_switch
   * @effects code_workspace_keeps_edits_typed_after_save_dirty
   */
  it('저장을 보낸 뒤 더 친 글자는 남고 dirty 로 남는다 (충돌 바 없음)', () => {
    const onDirtyChange = vi.fn();
    const sent = '<layout   name="a"/><!--MINE-->';

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onDirtyChange={onDirtyChange} />,
    );

    type(sent);
    rerender(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} lastSave={{ key: 'layout:a', value: sent, revision: null }} onDirtyChange={onDirtyChange} />,
    );
    type(`${sent}<!--MORE-->`);
    rerender(
      <CodeWorkspace fileKey="layout:a" serverValue={'<layout name="a"/><!--MINE-->'} serverRevision={2} lastSave={{ key: 'layout:a', value: sent, revision: 2 }} onDirtyChange={onDirtyChange} />,
    );

    expect(screen.queryByTestId('conflict-bar')).not.toBeInTheDocument();
    expect(editorValue()).toBe(`${sent}<!--MORE-->`);
    expect(onDirtyChange).toHaveBeenLastCalledWith({ target: { value: ['layout:a'] } });
  });

  /**
   * 내 저장 기록은 **그 파일, 그 판본**에만 쓴다. 남이 그 뒤에 저장했거나(판본이 더 오름) 다른 파일의
   * 기록이면 종전대로 충돌 바를 세운다 — 남의 편집을 조용히 받아들이거나 내 편집을 버리지 않는다.
   *
   * @scenario branch=layout, buffer_state=dirty, leave=file_switch
   * @effects code_workspace_still_flags_conflict_for_other_saves
   */
  it('판본이 내 저장보다 더 올랐거나 다른 파일의 기록이면 여전히 충돌 바를 세운다', () => {
    const mine = '<layout name="a"/><!--MINE-->';

    const first = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);
    type(mine);
    first.rerender(
      <CodeWorkspace fileKey="layout:a" serverValue={'<layout name="theirs"/>'} serverRevision={3} lastSave={{ key: 'layout:a', value: mine, revision: 2 }} />,
    );
    expect(screen.getByTestId('conflict-bar')).toBeInTheDocument();
    expect(editorValue()).toBe(mine);
    first.unmount();
    window.localStorage.clear();

    const second = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);
    type(mine);
    second.rerender(
      <CodeWorkspace fileKey="layout:a" serverValue={'<layout name="theirs"/>'} serverRevision={2} lastSave={{ key: 'layout:b', value: mine, revision: 2 }} />,
    );
    expect(screen.getByTestId('conflict-bar')).toBeInTheDocument();
    expect(editorValue()).toBe(mine);
  });

  /** @effects code_workspace_pushes_active_buffer_to_host */
  it('활성 버퍼를 호스트로 올린다 — 저장·미리보기가 읽는 자리다', () => {
    const onChange = vi.fn();

    render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onChange={onChange} />);

    expect(onChange).toHaveBeenLastCalledWith({ target: { value: SERVER_A } });

    type('typed');

    expect(onChange).toHaveBeenLastCalledWith({ target: { value: 'typed' } });
  });

  /**
   * 파일을 **여는 것**은 편집이 아니다.
   *
   * `onChange` 에 서버 왕복을 걸면 파일을 열 때마다 변환이 돌고, 본문 도착 전 빈 값으로 먼저
   * 나가 422 가 난다(브라우저 실측 2026-09-12). 그래서 사람이 친 편집만 `onUserEdit` 로 알린다.
   *
   * @effects code_workspace_separates_user_edits_from_buffer_sync
   */
  it('onUserEdit 는 사람이 고쳤을 때만 불린다', () => {
    const onUserEdit = vi.fn();

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onUserEdit={onUserEdit} />,
    );

    expect(onUserEdit).not.toHaveBeenCalled();

    // 파일 전환도 편집이 아니다.
    rerender(<CodeWorkspace fileKey="layout:b" serverValue={SERVER_B} serverRevision={1} onUserEdit={onUserEdit} />);
    expect(onUserEdit).not.toHaveBeenCalled();

    type('typed');
    expect(onUserEdit).toHaveBeenCalledWith({ target: { value: 'typed' } });
  });
});

describe('CodeWorkspace — 탭', () => {
  /** @effects code_workspace_opens_a_tab_per_visited_file */
  it('들른 파일마다 탭이 쌓인다', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    rerender(<CodeWorkspace fileKey="layout:b" serverValue={SERVER_B} serverRevision={1} />);

    expect(screen.getAllByRole('tab')).toHaveLength(2);
  });

  /** @effects code_workspace_tab_click_asks_host_to_switch */
  it('탭을 누르면 호스트에 전환을 요청한다 — 스스로 본문을 바꾸지 않는다', () => {
    const onRequestSelect = vi.fn();

    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onRequestSelect={onRequestSelect} />,
    );

    rerender(
      <CodeWorkspace fileKey="layout:b" serverValue={SERVER_B} serverRevision={1} onRequestSelect={onRequestSelect} />,
    );

    fireEvent.click(screen.getByTestId('file-tab-layout:a'));

    expect(onRequestSelect).toHaveBeenCalledWith('layout:a');
  });

  /**
   * 미저장 탭 닫기는 **3지선다**다 — 저장 / 버림 / 취소 (계획서 §4.3 표 6행).
   *
   * 종전 `window.confirm` 2지선다에는 «저장하고 닫기» 가 없어, 저장하려면 취소하고 따로 저장한
   * 뒤 다시 닫아야 했다.
   *
   * @effects code_workspace_confirms_before_closing_a_dirty_tab
   */
  it('미저장 탭을 닫으려면 3지선다를 묻는다 — 취소하면 닫지 않는다', () => {
    render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onSave={vi.fn()} />);

    type('dirty');
    fireEvent.click(screen.getByLabelText('admin.templates.layout_edit.tabs.close'));

    const dialog = screen.getByTestId('close-dialog');
    expect(dialog).toBeInTheDocument();
    expect(screen.getByTestId('close-save')).toBeInTheDocument();
    expect(screen.getByTestId('close-discard')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('close-cancel'));

    expect(screen.queryByTestId('close-dialog')).toBeNull();
    expect(screen.getAllByRole('tab')).toHaveLength(1);
    expect(editorValue()).toBe('dirty');
  });

  /** @effects code_workspace_close_dialog_discards_on_request */
  it('3지선다에서 «저장하지 않고 닫기» 를 고르면 편집분을 버리고 닫는다', () => {
    render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    type('dirty');
    fireEvent.click(screen.getByLabelText('admin.templates.layout_edit.tabs.close'));
    // onSave 가 없으면 «저장하고 닫기» 는 나오지 않는다 — 저장할 길이 없는 곳에서 약속하지 않는다.
    expect(screen.queryByTestId('close-save')).toBeNull();

    fireEvent.click(screen.getByTestId('close-discard'));

    expect(screen.queryByTestId('close-dialog')).toBeNull();
    expect(screen.queryAllByRole('tab')).toHaveLength(0);
  });

  /**
   * «저장하고 닫기» — 저장을 한 번 부르고, 서버 본문이 내 편집분과 같아져 clean 이 되면 닫는다.
   * 저장이 실패해 dirty 로 남으면 닫지 않고 대화상자가 «저장하는 중» 으로 남는다.
   *
   * @effects code_workspace_close_dialog_saves_then_closes
   */
  it('3지선다에서 «저장하고 닫기» 를 고르면 저장을 부르고 clean 이 된 뒤에 닫는다', () => {
    const onSave = vi.fn();
    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onSave={onSave} />,
    );

    type('mine');
    fireEvent.click(screen.getByLabelText('admin.templates.layout_edit.tabs.close'));
    fireEvent.click(screen.getByTestId('close-save'));

    expect(onSave).toHaveBeenCalledTimes(1);
    // 아직 서버가 응답하지 않았다 — 닫지 않고 기다린다.
    expect(screen.getByTestId('close-dialog')).toBeInTheDocument();
    expect(screen.getAllByRole('tab')).toHaveLength(1);

    // 저장 성공: 서버 본문 = 내 편집분, 판본 증가.
    rerender(<CodeWorkspace fileKey="layout:a" serverValue="mine" serverRevision={2} onSave={onSave} />);

    expect(screen.queryByTestId('close-dialog')).toBeNull();
    expect(screen.queryAllByRole('tab')).toHaveLength(0);
    expect(onSave).toHaveBeenCalledTimes(1);
  });

  /**
   * «저장하고 닫기» 가 실패하면 그 사실을 창에 알리고 다시 고를 수 있게 버튼을 푼다.
   *
   * 실측 2026-10-06(7.1.0 전수 실측 I1): 문법 오류가 있는 파일에서 «저장하고 닫기» → `PUT` 422 → 창은 남았지만
   * 「저장하는 중… 저장이 끝나면 탭이 닫힙니다」 문구 그대로였고 «저장하지 않고 닫기»·«저장하고 닫기» 가
   * disabled 로 굳어 [취소] 만 누를 수 있었다. 저장이 끝났는데(isLoading true→false) 여전히 dirty 면 실패다.
   *
   * @effects code_workspace_close_dialog_reports_save_failure
   */
  it('«저장하고 닫기» 가 실패하면 창에 실패를 알리고 버튼을 다시 연다', () => {
    const onSave = vi.fn();
    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onSave={onSave} />,
    );

    type('broken');
    fireEvent.click(screen.getByLabelText('admin.templates.layout_edit.tabs.close'));
    fireEvent.click(screen.getByTestId('close-save'));
    expect(onSave).toHaveBeenCalledTimes(1);

    // 저장 요청이 나가고(로딩) — 서버가 거절해 로딩이 끝났는데 본문은 그대로 dirty.
    rerender(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onSave={onSave} isLoading />);
    rerender(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} onSave={onSave} isLoading={false} />);

    const dialog = screen.getByTestId('close-dialog');
    expect(dialog).toHaveTextContent('admin.templates.layout_edit.draft.close_failed');
    expect(screen.getByTestId('close-save')).not.toBeDisabled();
    expect(screen.getByTestId('close-discard')).not.toBeDisabled();
    expect(screen.getAllByRole('tab')).toHaveLength(1);

    // 다시 «저장하고 닫기» 를 누르면 한 번 더 저장을 부른다.
    fireEvent.click(screen.getByTestId('close-save'));
    expect(onSave).toHaveBeenCalledTimes(2);
  });

  /**
   * 충돌 바의 [차이 보기] — 왼쪽 서버 본문, 오른쪽 내 편집분 (계획서 §7.7 표 7행).
   *
   * @effects code_workspace_conflict_bar_shows_diff
   */
  it('충돌 바의 [차이 보기] 는 서버 본문과 내 편집분을 나란히 연다', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    type('mine');
    rerender(<CodeWorkspace fileKey="layout:a" serverValue="theirs" serverRevision={2} />);

    fireEvent.click(screen.getByTestId('conflict-view-diff'));

    const diff = screen.getByTestId('diff-editor');
    expect(diff).toHaveAttribute('data-original', 'theirs');
    expect(diff).toHaveAttribute('data-modified', 'mine');

    fireEvent.click(screen.getByTestId('conflict-diff-close'));
    expect(screen.queryByTestId('conflict-diff')).toBeNull();
  });

  /**
   * 본문을 아직 못 받았을 때(버퍼 없음 + 손에 든 serverValue 가 다른 파일 것) 편집기 위에 오버레이를
   * 덮는다 — 편집기는 언마운트하지 않는다(계획서 §7.7 표 4행).
   *
   * @effects code_workspace_overlays_editor_while_fetching
   */
  it('다른 파일로 옮겼는데 본문이 아직 안 왔으면 로딩 오버레이를 덮는다', () => {
    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverKey="layout:a" serverValue={SERVER_A} serverRevision={1} />,
    );

    expect(screen.queryByTestId('editor-loading-overlay')).toBeNull();

    // 파일 b 로 옮겼지만 응답은 아직 a 의 것이다.
    rerender(<CodeWorkspace fileKey="layout:b" serverKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    expect(screen.getByTestId('editor-loading-overlay')).toBeInTheDocument();
    expect(screen.getByTestId('editor-textarea')).toBeInTheDocument();

    rerender(<CodeWorkspace fileKey="layout:b" serverKey="layout:b" serverValue={SERVER_B} serverRevision={1} />);

    expect(screen.queryByTestId('editor-loading-overlay')).toBeNull();
  });

  /**
   * 모바일(<768)은 글자를 13px 로 한 단계 줄인다 — 데스크톱은 14px (계획서 §7.6).
   *
   * @effects code_workspace_uses_smaller_font_on_mobile
   */
  it('창 폭이 768 미만이면 편집기 글자를 13px 로 넘긴다', () => {
    const original = window.innerWidth;
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 390 });

    try {
      render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

      expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-font-size', '13');

      Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1440 });
      act(() => {
        window.dispatchEvent(new Event('resize'));
      });

      expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-font-size', '14');
    } finally {
      Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: original });
    }
  });

  /**
   * 탭 상한을 넘기면 **clean 탭부터** 해제한다.
   *
   * dirty 탭을 자동으로 닫으면 그 파일의 미저장 편집이 조용히 사라진다 — 이 화면이 고치려는
   * 결함과 같은 성질이므로 상한 처리에서도 같은 원칙을 지킨다.
   *
   * @effects code_workspace_evicts_clean_tabs_first
   */
  it('탭 상한 초과 시 dirty 탭은 남기고 clean 탭을 해제한다', () => {
    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} maxTabs={2} />,
    );

    type('dirty-a');

    rerender(<CodeWorkspace fileKey="layout:b" serverValue={SERVER_B} serverRevision={1} maxTabs={2} />);
    rerender(<CodeWorkspace fileKey="layout:c" serverValue="c" serverRevision={1} maxTabs={2} />);

    const labels = screen.getAllByRole('tab').map((el) => el.textContent ?? '');

    expect(labels.some((label) => label.includes('a'))).toBe(true);
    expect(labels.some((label) => label.includes('b'))).toBe(false);
  });
});

describe('CodeWorkspace — 파일별 Monaco 모델', () => {
  /**
   * 모델을 파일마다 나눠야 커서·스크롤·되돌리기 스택이 파일별로 보존된다.
   *
   * `value` prop 하나를 갈아끼우는 방식으로는 얻을 수 없다 — 그 경우 모든 파일이 한 모델을
   * 공유해 A 에서 누른 `Ctrl+Z` 가 B 의 편집을 되돌린다.
   *
   * @effects code_workspace_uses_one_monaco_model_per_file
   */
  it('파일마다 다른 모델 경로를 쓴다', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue={SERVER_A} serverRevision={1} />);

    const first = screen.getByTestId('editor-textarea').getAttribute('data-model-path');

    rerender(<CodeWorkspace fileKey="layout:b" serverValue={SERVER_B} serverRevision={1} />);

    const second = screen.getByTestId('editor-textarea').getAttribute('data-model-path');

    expect(first).toContain('layout:a');
    expect(second).toContain('layout:b');
    expect(first).not.toBe(second);
  });
});

/**
 * 빠른 연속 클릭 — `fileKey` 는 즉시 바뀌고 `serverValue` 는 응답이 와야 바뀐다.
 *
 * 그 사이 렌더에서 둘은 서로 **다른 파일**을 가리킨다. 그대로 받아들이면 앞 파일 본문이 새 파일
 * 버퍼에 굳고, 그 상태로 저장하면 다른 레이아웃을 통째로 덮어쓴다.
 *
 * 실측 2026-09-12: 300ms 간격으로 10개를 연속 클릭하니 10개 버퍼 중 **6개**가 직전 파일 본문을
 * 담았다 — `admin_module_list` 버퍼에 `admin_settings` 본문(447,685자)이 들어 있었다.
 */
describe('CodeWorkspace — 앞 파일 응답 오염 방지', () => {
  /** @effects code_workspace_ignores_server_value_from_another_file */
  it('serverKey 가 fileKey 와 다르면 그 본문으로 버퍼를 만들지 않는다', () => {
    const { rerender } = render(
      <CodeWorkspace fileKey="layout:a" serverKey="layout:a" serverValue="AAA" serverRevision={1} />,
    );

    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('AAA');

    // b 로 옮겼지만 응답은 아직 a 것이다 — b 버퍼가 'AAA' 로 만들어지면 안 된다.
    rerender(<CodeWorkspace fileKey="layout:b" serverKey="layout:a" serverValue="AAA" serverRevision={1} />);

    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).not.toBe('AAA');

    // b 응답이 도착하면 그때 받아들인다.
    rerender(<CodeWorkspace fileKey="layout:b" serverKey="layout:b" serverValue="BBB" serverRevision={2} />);

    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('BBB');

    // a 로 돌아가도 a 본문이 그대로여야 한다 (b 본문이 섞이지 않았다).
    rerender(<CodeWorkspace fileKey="layout:a" serverKey="layout:a" serverValue="AAA" serverRevision={1} />);

    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('AAA');
  });

  /** @effects code_workspace_without_server_key_keeps_legacy_behaviour */
  it('serverKey 를 주지 않으면 대조하지 않는다 (종전 호출부 호환)', () => {
    const { rerender } = render(<CodeWorkspace fileKey="layout:a" serverValue="AAA" serverRevision={1} />);

    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('AAA');

    rerender(<CodeWorkspace fileKey="layout:b" serverValue="BBB" serverRevision={1} />);

    expect((screen.getByTestId('editor-textarea') as HTMLTextAreaElement).value).toBe('BBB');
  });
});

/**
 * 파일을 여는 중의 빈 값은 **편집이 아니다** (2026-09-12 제보).
 *
 * 편집기는 제어 컴포넌트라 `value` prop 이 바뀌면 그대로 change 를 올려 준다. 파일을 눌러
 * 서버 본문이 도착하기 전에는 그 파일의 버퍼가 아직 없어 빈 값이 올라오는데, 그것을 편집으로
 * 치면 **빈 본문으로 `markup-compile` 이 돌아** 탭을 열 때마다
 * «컴파일 오류 — 저장이 막힙니다 / 변환할 마크업(.g7.html)이 필요합니다» 배너가 떴다.
 * 다른 탭에 갔다 오면 제대로 된 본문으로 다시 돌아 사라지는 것도 같은 이유다.
 */
describe('CodeWorkspace — 여는 중의 값은 편집이 아니다', () => {
  /** @effects code_workspace_does_not_report_user_edit_before_buffer_exists */
  it('버퍼가 생기기 전의 편집기 change 는 onUserEdit 을 올리지 않는다', () => {
    const onUserEdit = vi.fn();

    // b 를 눌렀지만 서버 응답은 아직 a 것이다 — b 버퍼가 없다.
    const { rerender } = render(
      <CodeWorkspace
        fileKey="layout:b"
        serverKey="layout:a"
        serverValue="AAA"
        serverRevision={1}
        onUserEdit={onUserEdit}
      />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: '' } });
    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'echo' } });

    expect(onUserEdit).not.toHaveBeenCalled();

    // b 응답이 도착하면 버퍼가 생기고, 그 뒤의 진짜 편집은 올라간다.
    rerender(
      <CodeWorkspace
        fileKey="layout:b"
        serverKey="layout:b"
        serverValue="BBB"
        serverRevision={2}
        onUserEdit={onUserEdit}
      />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'BBB typed' } });

    expect(onUserEdit).toHaveBeenCalledTimes(1);
    expect(onUserEdit.mock.calls[0][0].target.value).toBe('BBB typed');
  });

  /** @effects code_workspace_ignores_unchanged_echo */
  it('값이 그대로인 change 는 편집으로 치지 않는다', () => {
    const onUserEdit = vi.fn();

    render(
      <CodeWorkspace
        fileKey="layout:a"
        serverKey="layout:a"
        serverValue="AAA"
        serverRevision={1}
        onUserEdit={onUserEdit}
      />,
    );

    fireEvent.change(screen.getByTestId('editor-textarea'), { target: { value: 'AAA' } });

    expect(onUserEdit).not.toHaveBeenCalled();
  });
});
