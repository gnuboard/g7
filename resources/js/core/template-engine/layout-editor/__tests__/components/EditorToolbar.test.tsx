/**
 * EditorToolbar 컴포넌트 테스트
 *
 * 결함 4/5/6 회귀 가드:
 * - 나가기 버튼: onExit 제공 시 활성 + 클릭 시 호출 / 미제공 시 disabled
 * - 코드편집 버튼: onEditCode 제공 시 활성 + 클릭 시 호출 / 미제공 시 disabled
 * - 저장 버튼: onSave 가 Promise 면 진행 중 스피너 노출 + disabled, 완료 후 복원
 */

import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { EditorToolbar } from '../../components/EditorToolbar';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function wrap(node: React.ReactElement): React.ReactElement {
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

describe('EditorToolbar — 나가기 버튼 (결함 4)', () => {
  it('onExit 미제공 시 나가기 버튼 disabled', () => {
    render(wrap(<EditorToolbar />));
    const exit = screen.getByTestId('g7le-toolbar-exit') as HTMLButtonElement;
    expect(exit.disabled).toBe(true);
  });

  it('onExit 제공 시 활성 + 클릭하면 onExit 호출', () => {
    const onExit = vi.fn();
    render(wrap(<EditorToolbar onExit={onExit} />));
    const exit = screen.getByTestId('g7le-toolbar-exit') as HTMLButtonElement;
    expect(exit.disabled).toBe(false);
    fireEvent.click(exit);
    expect(onExit).toHaveBeenCalledTimes(1);
  });
});

describe('EditorToolbar — 코드편집 버튼 (결함 5)', () => {
  it('onEditCode 미제공 시 disabled', () => {
    render(wrap(<EditorToolbar />));
    const code = screen.getByTestId('g7le-toolbar-edit-code') as HTMLButtonElement;
    expect(code.disabled).toBe(true);
  });

  it('onEditCode 제공 시 활성 + 클릭하면 호출', () => {
    const onEditCode = vi.fn();
    render(wrap(<EditorToolbar onEditCode={onEditCode} />));
    const code = screen.getByTestId('g7le-toolbar-edit-code') as HTMLButtonElement;
    expect(code.disabled).toBe(false);
    fireEvent.click(code);
    expect(onEditCode).toHaveBeenCalledTimes(1);
  });
});

describe('EditorToolbar — 버튼 잔류 테두리 (추가 결함: hover/focus 후 검정 테두리 유지)', () => {
  it('base/hover 스타일이 longhand(borderColor/backgroundColor)만 사용한다 (shorthand 혼용 금지)', () => {
    // 회귀 가드: base 가 `border` shorthand 이고 hover 가 `borderColor` longhand 이면,
    // hover 해제 시 React 가 borderColor 만 제거하고 border-width/style 을 남겨 테두리가
    // color(텍스트색)로 폴백되며 검정 테두리가 잔류한다.
    render(wrap(<EditorToolbar onExit={vi.fn()} onEditCode={vi.fn()} />));
    const exit = screen.getByTestId('g7le-toolbar-exit') as HTMLButtonElement;
    const raw = exit.getAttribute('style') ?? '';

    expect(raw).toContain('border-color');
    expect(raw).toContain('background-color');
    // shorthand `border:` / `background:` (longhand 가 아닌 단독) 부재 — 혼용 시 잔류 발생
    expect(/(^|;)\s*border\s*:/.test(raw)).toBe(false);
    expect(/(^|;)\s*background\s*:/.test(raw)).toBe(false);
  });

  it('마우스 올렸다 치우면 hover 스타일이 원복되어 잔류 테두리 없음', () => {
    render(wrap(<EditorToolbar onExit={vi.fn()} onEditCode={vi.fn()} />));
    const exit = screen.getByTestId('g7le-toolbar-exit') as HTMLButtonElement;
    const baseBorder = exit.style.borderColor;

    fireEvent.mouseEnter(exit);
    // hover 중에는 테두리색이 hover 값으로 바뀜
    fireEvent.mouseLeave(exit);

    // 치운 뒤에는 base 테두리색으로 복귀 (잔류 없음)
    expect(exit.style.borderColor).toBe(baseBorder);
  });

  it('클릭(focus)된 뒤에도 브라우저 기본 검정 outline 이 노출되지 않음 (outline:none)', () => {
    render(wrap(<EditorToolbar onExit={vi.fn()} onEditCode={vi.fn()} />));
    const code = screen.getByTestId('g7le-toolbar-edit-code') as HTMLButtonElement;

    act(() => {
      code.focus();
    });

    // 브라우저 기본 outline 을 제거해 클릭 후 검정 테두리 잔류를 방지한다.
    expect(code.style.outline).toBe('none');
  });
});

describe('EditorToolbar — 저장 스피너 (결함 6)', () => {
  it('onSave 가 Promise 면 저장 중 스피너 노출 + disabled, 완료 후 복원', async () => {
    let resolveSave: () => void = () => {};
    const onSave = vi.fn(
      () =>
        new Promise<void>((res) => {
          resolveSave = res;
        }),
    );
    render(wrap(<EditorToolbar onSave={onSave} isDirty />));
    const save = screen.getByTestId('g7le-toolbar-save') as HTMLButtonElement;

    // 저장 전: 스피너 없음
    expect(screen.queryByTestId('g7le-toolbar-save-spinner')).toBeNull();

    fireEvent.click(save);

    // 저장 중: 스피너 노출 + disabled
    await waitFor(() => {
      expect(screen.getByTestId('g7le-toolbar-save-spinner')).toBeInTheDocument();
    });
    expect((screen.getByTestId('g7le-toolbar-save') as HTMLButtonElement).disabled).toBe(true);

    // 저장 완료
    resolveSave();
    await waitFor(() => {
      expect(screen.queryByTestId('g7le-toolbar-save-spinner')).toBeNull();
    });
  });

  it('onSave 미제공 시 저장 버튼 disabled', () => {
    render(wrap(<EditorToolbar />));
    const save = screen.getByTestId('g7le-toolbar-save') as HTMLButtonElement;
    expect(save.disabled).toBe(true);
  });
});

describe('EditorToolbar — 템플릿 이름/버전 표시 + 전환 드롭다운', () => {
  const others = [
    { identifier: 'sirsoft-basic', name: '심플 유저', version: '1.2.0', type: 'user' as const },
    { identifier: 'sirsoft-admin_basic', name: '관리자 베이직', version: '2.0.1', type: 'admin' as const },
  ];

  it('templateName/templateVersion 제공 시 이름과 버전을 함께 표시', () => {
    render(
      wrap(<EditorToolbar templateName="심플 유저" templateVersion="1.2.0" />),
    );
    expect(screen.getByTestId('g7le-toolbar-template-name').textContent).toBe('심플 유저');
    expect(screen.getByTestId('g7le-toolbar-template-version').textContent).toBe('v1.2.0');
  });

  it('templateName 미제공 시 식별자로 폴백', () => {
    render(wrap(<EditorToolbar />));
    // wrap 의 templateIdentifier 는 sirsoft-basic
    expect(screen.getByTestId('g7le-toolbar-template-name').textContent).toBe('sirsoft-basic');
  });

  it('전환 후보(자기 자신 제외)가 없으면 드롭다운 토글 비활성', () => {
    render(
      wrap(
        <EditorToolbar
          templateList={[{ identifier: 'sirsoft-basic', name: '심플 유저', version: '1.0.0', type: 'user' }]}
          onSwitchTemplate={vi.fn()}
        />,
      ),
    );
    const label = screen.getByTestId('g7le-toolbar-template-label') as HTMLButtonElement;
    expect(label.disabled).toBe(true);
    expect(label.getAttribute('data-can-switch')).toBe('false');
  });

  it('다른 템플릿 후보가 있으면 클릭 시 메뉴 열림 + 현재 템플릿도 함께 노출', () => {
    render(
      wrap(<EditorToolbar templateList={others} onSwitchTemplate={vi.fn()} />),
    );
    const label = screen.getByTestId('g7le-toolbar-template-label') as HTMLButtonElement;
    expect(label.disabled).toBe(false);
    // 처음엔 메뉴 닫힘
    expect(screen.queryByTestId('g7le-toolbar-template-menu')).toBeNull();
    fireEvent.click(label);
    expect(screen.getByTestId('g7le-toolbar-template-menu')).toBeInTheDocument();
    const items = screen.getAllByTestId('g7le-toolbar-template-menu-item');
    // 현재 선택된 템플릿(sirsoft-basic)도 함께 노출 → 2개
    expect(items).toHaveLength(2);
    const ids = items.map((el) => el.getAttribute('data-identifier'));
    expect(ids).toContain('sirsoft-basic');
    expect(ids).toContain('sirsoft-admin_basic');
    // 현재 항목은 data-current=true 로 표시
    const current = items.find((el) => el.getAttribute('data-identifier') === 'sirsoft-basic');
    expect(current?.getAttribute('data-current')).toBe('true');
    expect(current?.getAttribute('aria-selected')).toBe('true');
  });

  it('현재 선택 항목 클릭 시 이동 없이 메뉴만 닫힘(onSwitch 미호출)', () => {
    const onSwitch = vi.fn();
    render(
      wrap(<EditorToolbar templateList={others} onSwitchTemplate={onSwitch} />),
    );
    fireEvent.click(screen.getByTestId('g7le-toolbar-template-label'));
    const current = screen
      .getAllByTestId('g7le-toolbar-template-menu-item')
      .find((el) => el.getAttribute('data-identifier') === 'sirsoft-basic')!;
    fireEvent.click(current);
    expect(onSwitch).not.toHaveBeenCalled();
    expect(screen.queryByTestId('g7le-toolbar-template-menu')).toBeNull();
  });

  it('다른 템플릿 항목 클릭 시 onSwitchTemplate 가 해당 식별자로 호출 + 메뉴 닫힘', () => {
    const onSwitch = vi.fn();
    render(
      wrap(<EditorToolbar templateList={others} onSwitchTemplate={onSwitch} />),
    );
    fireEvent.click(screen.getByTestId('g7le-toolbar-template-label'));
    const other = screen
      .getAllByTestId('g7le-toolbar-template-menu-item')
      .find((el) => el.getAttribute('data-identifier') === 'sirsoft-admin_basic')!;
    fireEvent.click(other);
    expect(onSwitch).toHaveBeenCalledTimes(1);
    expect(onSwitch).toHaveBeenCalledWith('sirsoft-admin_basic');
    expect(screen.queryByTestId('g7le-toolbar-template-menu')).toBeNull();
  });
});

// 별도 편집 모드(확장/반복/공통레이아웃/모달)에서도 툴바 본체가 모두 표시되어야 한다.
// 종전엔 `!isAltMode` 게이트로 별도 모드에서 본체를 통째로 숨겨 "← 종료" 버튼만 남았다.
describe('EditorToolbar — 별도 편집 모드 풀 툴바', () => {
  // provider 안에서 별도 편집 모드로 진입시키는 헬퍼.
  function EnterMode({ mode }: { mode: 'extension' | 'iteration_item' | 'base' }): React.ReactElement {
    const { dispatch } = useLayoutEditor();
    React.useEffect(() => {
      act(() => {
        dispatch({ type: 'SELECT_ROUTE', route: { path: '/x', layoutName: 'home' } });
        if (mode === 'extension') dispatch({ type: 'ENTER_EXTENSION_EDIT', extensionId: '44' });
        else if (mode === 'iteration_item')
          dispatch({ type: 'ENTER_ITERATION_ITEM_EDIT', sourcePath: '0.children.1', hostLayout: 'home' });
        else dispatch({ type: 'ENTER_BASE_EDIT', layoutName: '_user_base' });
      });
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    return <></>;
  }

  const BODY_BUTTONS = [
    'g7le-toolbar-add-element',
    'g7le-toolbar-preview',
    'g7le-toolbar-versions',
    'g7le-toolbar-edit-code',
    'g7le-toolbar-page-settings',
    'g7le-toolbar-save',
    'g7le-toolbar-exit',
  ];

  for (const mode of ['extension', 'iteration_item', 'base'] as const) {
    it(`${mode} 모드: 툴바 본체 + 모드 종료 버튼이 모두 렌더`, async () => {
      render(
        wrap(
          <>
            <EnterMode mode={mode} />
            <EditorToolbar onSave={vi.fn()} onEditCode={vi.fn()} />
          </>,
        ),
      );
      // EnterMode 의 useEffect dispatch 가 반영돼 별도 모드 종료 버튼이 나타날 때까지 대기.
      await screen.findByTestId('g7le-toolbar-exit-alt-mode');
      // 본체 버튼 전수 노출
      for (const id of BODY_BUTTONS) {
        expect(screen.getByTestId(id)).toBeInTheDocument();
      }
    });
  }
});
