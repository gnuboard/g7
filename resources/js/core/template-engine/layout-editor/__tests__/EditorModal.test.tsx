/**
 * EditorModalContext 단위 테스트 — open / close / 스택 / 백드롭 / ESC 분기 검증
 *
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, expect, it } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import {
  EditorModalProvider,
  EditorModalRoot,
  useEditorModal,
} from '../EditorModalContext';

function Host({ children }: { children?: React.ReactNode }): React.ReactElement {
  return (
    <EditorModalProvider>
      {children}
      <EditorModalRoot />
    </EditorModalProvider>
  );
}

function OpenButton({
  ariaLabel,
  content,
  closeOnBackdrop,
  closeOnEscape,
}: {
  ariaLabel?: string;
  content: React.ReactNode;
  closeOnBackdrop?: boolean;
  closeOnEscape?: boolean;
}): React.ReactElement {
  const modal = useEditorModal();
  return (
    <button
      type="button"
      data-testid="trigger"
      onClick={() =>
        modal.open({ ariaLabel, content, closeOnBackdrop, closeOnEscape })
      }
    >
      open
    </button>
  );
}

describe('EditorModal — 기본 open / close', () => {
  it('open() 호출 시 모달 본문이 렌더되고 close 시 사라진다', () => {
    render(
      <Host>
        <OpenButton ariaLabel="palette" content={<span data-testid="content">hello</span>} />
      </Host>
    );
    expect(screen.queryByTestId('content')).toBeNull();
    fireEvent.click(screen.getByTestId('trigger'));
    expect(screen.getByTestId('content')).toBeTruthy();
    // 백드롭 클릭으로 닫기 (기본값 true)
    const backdrop = screen.getByRole('presentation');
    fireEvent.click(backdrop);
    expect(screen.queryByTestId('content')).toBeNull();
  });

  it('closeOnBackdrop=false 면 백드롭 클릭이 무시된다', () => {
    render(
      <Host>
        <OpenButton
          ariaLabel="palette"
          content={<span data-testid="content">x</span>}
          closeOnBackdrop={false}
        />
      </Host>
    );
    fireEvent.click(screen.getByTestId('trigger'));
    fireEvent.click(screen.getByRole('presentation'));
    expect(screen.queryByTestId('content')).toBeTruthy();
  });

  it('모달 본문 클릭은 닫기를 트리거하지 않는다 (e.target !== e.currentTarget)', () => {
    render(
      <Host>
        <OpenButton ariaLabel="palette" content={<span data-testid="content">x</span>} />
      </Host>
    );
    fireEvent.click(screen.getByTestId('trigger'));
    fireEvent.click(screen.getByTestId('content'));
    expect(screen.queryByTestId('content')).toBeTruthy();
  });
});

describe('EditorModal — ESC 키', () => {
  it('ESC 누르면 가장 위 모달이 닫힌다', () => {
    render(
      <Host>
        <OpenButton ariaLabel="palette" content={<span data-testid="content">x</span>} />
      </Host>
    );
    fireEvent.click(screen.getByTestId('trigger'));
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    });
    expect(screen.queryByTestId('content')).toBeNull();
  });

  it('closeOnEscape=false 면 ESC 가 무시된다', () => {
    render(
      <Host>
        <OpenButton
          ariaLabel="palette"
          content={<span data-testid="content">x</span>}
          closeOnEscape={false}
        />
      </Host>
    );
    fireEvent.click(screen.getByTestId('trigger'));
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    });
    expect(screen.queryByTestId('content')).toBeTruthy();
  });

  // closeOnEscape=false 모달(페이지 설정)은 ESC 로 안 닫히되, ESC 이벤트를
  // 삼키지 않아야 한다(모달 안 플로팅 드롭다운·인라인 입력 모드 등 하위 ESC 핸들러가 동작). 핸들러가
  // preventDefault/stopPropagation 을 호출하지 않음을 검증(하위 ESC 탈출 유지 회귀 잠금).
  it('closeOnEscape=false 면 ESC 가 하위로 전파된다(삼키지 않음)', () => {
    render(
      <Host>
        <OpenButton
          ariaLabel="palette"
          content={<span data-testid="content">x</span>}
          closeOnEscape={false}
        />
      </Host>
    );
    fireEvent.click(screen.getByTestId('trigger'));
    let defaultPrevented = false;
    let propagationStopped = false;
    const ev = new KeyboardEvent('keydown', { key: 'Escape', cancelable: true, bubbles: true });
    const origStop = ev.stopPropagation.bind(ev);
    ev.stopPropagation = () => { propagationStopped = true; origStop(); };
    act(() => {
      window.dispatchEvent(ev);
      defaultPrevented = ev.defaultPrevented;
    });
    // 모달은 그대로 열려 있고, ESC 는 삼켜지지 않는다.
    expect(screen.queryByTestId('content')).toBeTruthy();
    expect(defaultPrevented).toBe(false);
    expect(propagationStopped).toBe(false);
  });
});

describe('EditorModal — 스택 (depth 무제한)', () => {
  it('두 모달이 동시 표시되며 ESC 는 최상위만 닫는다', () => {
    function NestedTrigger(): React.ReactElement {
      const modal = useEditorModal();
      return (
        <>
          <button
            data-testid="trigger-a"
            onClick={() =>
              modal.open({
                ariaLabel: 'a',
                content: (
                  <div data-testid="content-a">
                    <button
                      data-testid="trigger-b"
                      onClick={() =>
                        modal.open({
                          ariaLabel: 'b',
                          content: <div data-testid="content-b">b</div>,
                        })
                      }
                    >
                      open b
                    </button>
                  </div>
                ),
              })
            }
          >
            open a
          </button>
        </>
      );
    }
    render(
      <Host>
        <NestedTrigger />
      </Host>
    );
    fireEvent.click(screen.getByTestId('trigger-a'));
    fireEvent.click(screen.getByTestId('trigger-b'));
    expect(screen.getByTestId('content-a')).toBeTruthy();
    expect(screen.getByTestId('content-b')).toBeTruthy();

    // ESC → b 만 닫힘
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    });
    expect(screen.queryByTestId('content-b')).toBeNull();
    expect(screen.queryByTestId('content-a')).toBeTruthy();

    // 다시 ESC → a 닫힘
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    });
    expect(screen.queryByTestId('content-a')).toBeNull();
  });
});

describe('EditorModal — closeAll', () => {
  it('closeAll 호출 시 스택의 모든 모달이 닫힌다', () => {
    function Trigger(): React.ReactElement {
      const modal = useEditorModal();
      return (
        <>
          <button
            data-testid="open-x"
            onClick={() => {
              modal.open({ content: <span data-testid="x1">1</span> });
              modal.open({ content: <span data-testid="x2">2</span> });
            }}
          >
            open
          </button>
          <button data-testid="close-all" onClick={() => modal.closeAll()}>
            close all
          </button>
        </>
      );
    }
    render(
      <Host>
        <Trigger />
      </Host>
    );
    fireEvent.click(screen.getByTestId('open-x'));
    expect(screen.getByTestId('x1')).toBeTruthy();
    expect(screen.getByTestId('x2')).toBeTruthy();
    fireEvent.click(screen.getByTestId('close-all'));
    expect(screen.queryByTestId('x1')).toBeNull();
    expect(screen.queryByTestId('x2')).toBeNull();
  });
});

// ============================================================================
// draggable 모달 + 선택 요소 자동 회피(avoidRect)
// ============================================================================
describe('EditorModal — draggable + avoidRect', () => {
  function DraggableHost({ avoidRect }: { avoidRect?: { left: number; top: number; width: number; height: number } }): React.ReactElement {
    const modal = useEditorModal();
    return (
      <button
        type="button"
        data-testid="open-draggable"
        onClick={() =>
          modal.open({
            id: 'drag1',
            draggable: true,
            avoidRect,
            width: 420,
            content: (
              <div data-modal-drag-handle data-testid="drag-handle" style={{ padding: 8 }}>
                헤더
              </div>
            ),
          })
        }
      >
        open
      </button>
    );
  }

  it('draggable 모달은 백드롭이 pointerEvents:none (캔버스 결과 보며 이동)', () => {
    render(
      <Host>
        <DraggableHost />
      </Host>,
    );
    fireEvent.click(screen.getByTestId('open-draggable'));
    const backdrop = screen.getByTestId('g7le-modal-backdrop-drag1');
    expect(backdrop.getAttribute('data-draggable')).toBe('true');
    expect((backdrop as HTMLElement).style.pointerEvents).toBe('none');
  });

  it('헤더 드래그 핸들 pointerdown→move 로 모달 위치 이동', () => {
    render(
      <Host>
        <DraggableHost />
      </Host>,
    );
    fireEvent.click(screen.getByTestId('open-draggable'));
    const handle = screen.getByTestId('drag-handle');
    const modalEl = screen.getByTestId('g7le-modal-drag1') as HTMLElement;
    fireEvent.pointerDown(handle, { clientX: 100, clientY: 100 });
    fireEvent.pointerMove(window, { clientX: 160, clientY: 140 });
    fireEvent.pointerUp(window, { clientX: 160, clientY: 140 });
    // 이동 후 left/top 이 px 좌표로 박힌다(중앙 50% 가 아님)
    expect(modalEl.style.left.endsWith('px')).toBe(true);
    expect(modalEl.style.position).toBe('absolute');
  });

  it('avoidRect 미지정 → 중앙 정렬 유지(transform translate)', () => {
    render(
      <Host>
        <DraggableHost />
      </Host>,
    );
    fireEvent.click(screen.getByTestId('open-draggable'));
    const modalEl = screen.getByTestId('g7le-modal-drag1') as HTMLElement;
    // jsdom 은 getBoundingClientRect 가 0 → computeAvoidPosition 이 null(중앙 유지)
    expect(modalEl.getAttribute('data-positioned')).toBe('false');
  });
});

describe('EditorModal — 최소화 / 복원', () => {
  function MinimizeHost(): React.ReactElement {
    const modal = useEditorModal();
    return (
      <>
        <button
          type="button"
          data-testid="open"
          onClick={() => modal.open({ id: 'm1', ariaLabel: '페이지 설정', content: <span data-testid="body">본문</span> })}
        >
          open
        </button>
        <button type="button" data-testid="min" onClick={() => modal.minimize('m1', '영역 선택 중…')}>
          min
        </button>
      </>
    );
  }

  it('최소화 시 모달 본문은 언마운트되지 않고 하단 바가 나타난다', () => {
    render(
      <Host>
        <MinimizeHost />
      </Host>,
    );
    fireEvent.click(screen.getByTestId('open'));
    expect(screen.getByTestId('body')).toBeInTheDocument();

    act(() => {
      fireEvent.click(screen.getByTestId('min'));
    });
    // 본문은 DOM 에 남는다(상태 보존 — display:none).
    expect(screen.getByTestId('body')).toBeInTheDocument();
    const backdrop = screen.getByTestId('g7le-modal-backdrop-m1') as HTMLElement;
    expect(backdrop.style.display).toBe('none');
    // 하단 바 + 라벨.
    expect(screen.getByTestId('g7le-minimized-bar')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-minimized-m1')).toHaveTextContent('영역 선택 중…');
  });

  it('하단 바 클릭 시 모달이 복원된다', () => {
    render(
      <Host>
        <MinimizeHost />
      </Host>,
    );
    fireEvent.click(screen.getByTestId('open'));
    act(() => {
      fireEvent.click(screen.getByTestId('min'));
    });
    act(() => {
      fireEvent.click(screen.getByTestId('g7le-minimized-m1'));
    });
    const backdrop = screen.getByTestId('g7le-modal-backdrop-m1') as HTMLElement;
    expect(backdrop.style.display).not.toBe('none');
    expect(screen.queryByTestId('g7le-minimized-bar')).not.toBeInTheDocument();
  });
});

describe('EditorModal — maxHeight 뷰포트 추종', () => {
  function MaxHeightHost({ ratio }: { ratio?: number }): React.ReactElement {
    const modal = useEditorModal();
    return (
      <button
        type="button"
        data-testid="open-mh"
        onClick={() =>
          modal.open({
            id: 'mh1',
            ariaLabel: '페이지 설정',
            maxHeightRatio: ratio,
            content: <span data-testid="mh-body">본문</span>,
          })
        }
      >
        open
      </button>
    );
  }

  // 페이지 설정 모달이 화면 높이를 넘쳐 백드롭이 스크롤되고
  // 상단 탭/제목이 화면 밖으로 사라졌다. 창을 줄여도 모달이 함께 줄지 않았다.
  // 근본 원인 — maxHeight 를 모달 마운트 시점의 window.innerHeight 픽셀로 한 번
  // 계산해 박아 두어, resize 시 재계산되지 않았다(stale 픽셀). vh 문자열로 주면
  // 브라우저가 리사이즈마다 자동 재계산해 항상 화면에 맞는다.
  // 비-draggable 모달은 상단 정렬 + 상단 여백(MODAL_TOP_GAP)을 쓰므로 maxHeight 가
  // calc(100vh - 여백*2) 다(상·하 여백 제외해 제목·탭 포함 화면 안에, 리사이즈 추종).
  // jsdom 의 cssstyle 은 calc() 는 받지만 min()/clamp() 복합값은 떨군다 → maxHeight 는 calc 단독.
  it('비-draggable maxHeight 는 calc(100vh - …) 화면맞춤(픽셀 고정 금지 — 리사이즈 추종)', () => {
    render(
      <Host>
        <MaxHeightHost ratio={0.85} />
      </Host>,
    );
    fireEvent.click(screen.getByTestId('open-mh'));
    const mh = (screen.getByTestId('g7le-modal-mh1') as HTMLElement).style.maxHeight;
    expect(mh).toContain('calc(100vh');
    // 'NNNpx' 같은 픽셀 고정값이면 리사이즈 추종 불가 → 실패.
    expect(/^\d+px$/.test(mh)).toBe(false);
  });

  it('maxHeightRatio 기본값에서도 비-draggable 은 calc 화면맞춤', () => {
    render(
      <Host>
        <MaxHeightHost />
      </Host>,
    );
    fireEvent.click(screen.getByTestId('open-mh'));
    const mh = (screen.getByTestId('g7le-modal-mh1') as HTMLElement).style.maxHeight;
    expect(mh).toContain('calc(100vh');
  });

  it('ratio=0 이어도 비-draggable 은 calc 화면맞춤(픽셀 아님)', () => {
    render(
      <Host>
        <MaxHeightHost ratio={0} />
      </Host>,
    );
    fireEvent.click(screen.getByTestId('open-mh'));
    const mh = (screen.getByTestId('g7le-modal-mh1') as HTMLElement).style.maxHeight;
    expect(mh).toContain('calc(100vh');
    expect(/^\d+px$/.test(mh)).toBe(false);
  });
});
