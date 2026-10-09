/**
 * EditorDialog.tsx — 레이아웃 편집기 확인·입력 대화상자
 *
 * 편집기는 나가기·템플릿 전환·커스텀 자산 이름변경/삭제·버전 복원·번역/첨부 삭제에서 브라우저 기본
 * `window.confirm`/`window.prompt` 를 썼다. 기본 대화상자는 사이트와 모양이 다르고 문구 외 단추 이름을
 * 바꿀 수 없으며, 편집기 모달 위에서는 어느 창의 질문인지 흐려진다. 편집기 모달 스택(EditorModalContext)
 * 위에 같은 모양의 대화상자를 띄운다.
 *
 * 접근성 계약:
 *  - role=alertdialog, 접근 가능한 이름 = 제목(없으면 본문), aria-describedby = 본문
 *  - 처음 초점: 입력 대화상자는 입력칸(기존 값 전체 선택), 위험 확인은 [취소], 그 밖은 [확인]
 *  - Tab/Shift+Tab 은 대화상자 안에서 돈다
 *  - Esc·백드롭·[취소] = 취소, 입력칸 Enter = 확인
 *  - 닫히면 대화상자를 연 순간 초점이 있던 요소로 초점을 돌려준다
 *
 * Provider 가 없는 곳(단독 렌더)에서는 종전 브라우저 대화상자로 대체한다 — 그래야 Provider 없이 부품만
 * 그리는 단위 테스트·외부 마운트가 같은 결과(true/false/문자열)를 받는다.
 *
 * @since engine-v1.78.7
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useOptionalEditorModal } from '../EditorModalContext';

type Translate = (key: string, params?: Record<string, string | number>) => string;

/** 확인 대화상자 옵션 */
export interface EditorConfirmOptions {
  /** 제목 — 대화상자의 접근 가능한 이름. 없으면 본문을 이름으로 쓴다 */
  title?: string;
  /** 본문(질문) */
  message: string;
  /** [확인] 단추 이름 (기본 `layout_editor.dialog.confirm`) */
  confirmLabel?: string;
  /** [취소] 단추 이름 (기본 `layout_editor.dialog.cancel`) */
  cancelLabel?: string;
  /** 되돌릴 수 없는 조작 — [확인]을 붉게, 처음 초점을 [취소]에 둔다 */
  danger?: boolean;
}

/** 입력 대화상자 옵션 */
export interface EditorPromptOptions extends Omit<EditorConfirmOptions, 'danger'> {
  /** 입력칸 기존 값 */
  defaultValue?: string;
}

/** 대화상자 도우미 */
export interface EditorDialogApi {
  /** 확인 대화상자 — [확인]이면 true, 그 밖은 false */
  confirm: (options: EditorConfirmOptions) => Promise<boolean>;
  /** 입력 대화상자 — [확인]이면 입력값, 그 밖은 null */
  prompt: (options: EditorPromptOptions) => Promise<string | null>;
}

let nextDialogId = 1;

/**
 * 편집기 대화상자 도우미를 돌려줍니다.
 *
 * @param t 편집기 번역 함수
 * @return confirm·prompt 도우미
 * @since engine-v1.78.7
 */
export function useEditorDialog(t: Translate): EditorDialogApi {
  const modal = useOptionalEditorModal();

  const open = useCallback(
    <T,>(kind: 'confirm' | 'prompt', options: EditorConfirmOptions & { defaultValue?: string }, cancelValue: T): Promise<T | boolean | string> => {
      if (!modal) {
        if (typeof window === 'undefined') return Promise.resolve(kind === 'confirm' ? true : cancelValue);
        if (kind === 'confirm') return Promise.resolve(window.confirm(options.message));
        const answer = window.prompt(options.message, options.defaultValue ?? '');

        return Promise.resolve(answer === null ? cancelValue : answer);
      }

      const opener = typeof document !== 'undefined' ? (document.activeElement as HTMLElement | null) : null;
      const seq = nextDialogId++;
      const id = `g7le-dialog-${seq}`;
      const messageId = `${id}-message`;

      return new Promise((resolvePromise) => {
        let settled = false;
        const finish = (value: T | boolean | string): void => {
          if (settled) return;
          settled = true;
          modal.close(id);
          resolvePromise(value);
          // 닫힌 뒤(렌더 반영 후) 연 요소로 초점을 돌린다. 연 요소가 사라졌으면 두지 않는다.
          setTimeout(() => {
            if (opener && typeof opener.focus === 'function' && opener.isConnected) opener.focus();
          }, 0);
        };

        modal.open({
          id,
          role: 'alertdialog',
          ariaLabel: options.title || options.message,
          ariaDescribedBy: messageId,
          width: 420,
          closeOnBackdrop: true,
          closeOnEscape: true,
          // Esc·백드롭은 모달 루트가 닫는다 — 그 닫힘을 취소로 받는다.
          onClose: () => finish(kind === 'confirm' ? false : cancelValue),
          content: (
            <EditorDialogBody
              kind={kind}
              title={options.title}
              message={options.message}
              messageId={messageId}
              defaultValue={options.defaultValue}
              confirmLabel={options.confirmLabel ?? t('layout_editor.dialog.confirm')}
              cancelLabel={options.cancelLabel ?? t('layout_editor.dialog.cancel')}
              danger={!!options.danger}
              onConfirm={(value) => finish(kind === 'confirm' ? true : value)}
              onCancel={() => finish(kind === 'confirm' ? false : cancelValue)}
            />
          ),
        });
      });
    },
    [modal, t],
  );

  return useMemo<EditorDialogApi>(
    () => ({
      confirm: (options) => open('confirm', options, false) as Promise<boolean>,
      prompt: (options) => open<null>('prompt', options, null) as Promise<string | null>,
    }),
    [open],
  );
}

interface EditorDialogBodyProps {
  kind: 'confirm' | 'prompt';
  title?: string;
  message: string;
  messageId: string;
  defaultValue?: string;
  confirmLabel: string;
  cancelLabel: string;
  danger: boolean;
  onConfirm: (value: string) => void;
  onCancel: () => void;
}

/**
 * 대화상자 본문 — 제목·본문·(입력칸)·[취소][확인].
 *
 * @param props EditorDialogBodyProps
 * @return 본문 엘리먼트
 */
function EditorDialogBody({
  kind,
  title,
  message,
  messageId,
  defaultValue,
  confirmLabel,
  cancelLabel,
  danger,
  onConfirm,
  onCancel,
}: EditorDialogBodyProps): React.ReactElement {
  const [value, setValue] = useState(defaultValue ?? '');
  const rootRef = useRef<HTMLDivElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const confirmRef = useRef<HTMLButtonElement | null>(null);
  const cancelRef = useRef<HTMLButtonElement | null>(null);

  useEffect(() => {
    if (kind === 'prompt' && inputRef.current) {
      inputRef.current.focus();
      inputRef.current.select();

      return;
    }
    (danger ? cancelRef.current : confirmRef.current)?.focus();
  }, [kind, danger]);

  // 초점 가두기 — 대화상자 안의 초점 가능한 요소 사이에서만 Tab 이 돈다.
  const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>): void => {
    if (event.key !== 'Tab' || !rootRef.current) return;
    const focusables = Array.from(
      rootRef.current.querySelectorAll<HTMLElement>('input, button:not([disabled])'),
    );
    if (focusables.length === 0) return;
    const index = focusables.indexOf(document.activeElement as HTMLElement);
    const nextIndex = event.shiftKey
      ? (index <= 0 ? focusables.length - 1 : index - 1)
      : (index === -1 || index === focusables.length - 1 ? 0 : index + 1);
    event.preventDefault();
    focusables[nextIndex].focus();
  };

  return (
    <div ref={rootRef} className="g7le-dialog" data-testid="g7le-dialog" onKeyDown={onKeyDown} style={wrap}>
      {title ? (
        <div className="g7le-dialog__title" style={titleStyle}>
          {title}
        </div>
      ) : null}
      <p id={messageId} className="g7le-dialog__message" style={messageStyle}>
        {message}
      </p>
      {kind === 'prompt' ? (
        <input
          ref={inputRef}
          type="text"
          aria-label={message}
          data-testid="g7le-dialog-input"
          value={value}
          onChange={(event) => setValue(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              onConfirm(value);
            }
          }}
          style={inputStyle}
        />
      ) : null}
      <div style={actions}>
        <button ref={cancelRef} type="button" data-testid="g7le-dialog-cancel" onClick={onCancel} style={cancelButton}>
          {cancelLabel}
        </button>
        <button
          ref={confirmRef}
          type="button"
          data-testid="g7le-dialog-confirm"
          onClick={() => onConfirm(value)}
          style={danger ? dangerButton : confirmButton}
        >
          {confirmLabel}
        </button>
      </div>
    </div>
  );
}

const wrap: React.CSSProperties = { padding: '18px 20px 16px', display: 'flex', flexDirection: 'column', gap: 10 };
const titleStyle: React.CSSProperties = { fontSize: 15, fontWeight: 700, color: '#0f172a' };
const messageStyle: React.CSSProperties = { margin: 0, fontSize: 13, lineHeight: 1.55, color: '#334155', whiteSpace: 'pre-line' };
const inputStyle: React.CSSProperties = {
  width: '100%',
  boxSizing: 'border-box',
  padding: '7px 10px',
  fontSize: 13,
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
};
const actions: React.CSSProperties = { display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 4 };
const baseButton: React.CSSProperties = { padding: '7px 14px', fontSize: 13, borderRadius: 6, cursor: 'pointer', fontWeight: 600 };
const cancelButton: React.CSSProperties = { ...baseButton, background: '#fff', color: '#334155', border: '1px solid #cbd5e1' };
const confirmButton: React.CSSProperties = { ...baseButton, background: '#2563eb', color: '#fff', border: '1px solid #2563eb' };
const dangerButton: React.CSSProperties = { ...baseButton, background: '#dc2626', color: '#fff', border: '1px solid #dc2626' };
