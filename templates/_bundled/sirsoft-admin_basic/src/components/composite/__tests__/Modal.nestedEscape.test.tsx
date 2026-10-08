/**
 * @file Modal.nestedEscape.test.tsx
 * @description admin_basic Modal — 창 위에 창이 열린 상태의 Esc 회귀 테스트 (#736 S2)
 *
 * 열린 모달마다 document keydown 리스너를 걸어, Esc 한 번에 열린 창이 전부 닫혔다(모듈 상세 위 라이선스 창 실측).
 * engine-v1.77.1 이 인자 없는 onClose() 호출을 살리기 전에는 Esc 자체가 TypeError 로 무반응이라 드러나지 않았다.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { Modal } from '../Modal';

function Pair({ onOuter, onInner, innerOpen = true, innerEscape = true }: { onOuter: () => void; onInner: () => void; innerOpen?: boolean; innerEscape?: boolean }) {
  return (
    <>
      <Modal isOpen onClose={onOuter} title="바깥 창">바깥</Modal>
      <Modal isOpen={innerOpen} onClose={onInner} closeOnEscape={innerEscape} title="안쪽 창">안쪽</Modal>
    </>
  );
}

describe('[case:components-misc-nested-modal-escape-closes-all] admin_basic Modal — 중첩 창 Esc', () => {
  /**
   * @scenario stack=two_open, top_escape=enabled
   * @effects escape_closes_only_topmost
   */
  it('Esc 한 번은 맨 위 창 하나만 닫는다', () => {
    const onOuter = vi.fn();
    const onInner = vi.fn();
    render(<Pair onOuter={onOuter} onInner={onInner} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onInner).toHaveBeenCalledTimes(1);
    expect(onOuter).not.toHaveBeenCalled();
  });

  /**
   * @scenario stack=two_open, top_escape=enabled
   * @effects escape_reaches_next_after_top_closes
   */
  it('맨 위 창이 닫히면 다음 Esc 는 아래 창을 닫는다', () => {
    const onOuter = vi.fn();
    const onInner = vi.fn();
    const { rerender } = render(<Pair onOuter={onOuter} onInner={onInner} />);
    fireEvent.keyDown(document, { key: 'Escape' });
    rerender(<Pair onOuter={onOuter} onInner={onInner} innerOpen={false} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onOuter).toHaveBeenCalledTimes(1);
    expect(onInner).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario stack=two_open, top_escape=disabled
   * @effects escape_disabled_top_shields_lower
   */
  it('맨 위 창이 Esc 를 막아 두면 아래 창도 닫히지 않는다', () => {
    const onOuter = vi.fn();
    const onInner = vi.fn();
    render(<Pair onOuter={onOuter} onInner={onInner} innerEscape={false} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onInner).not.toHaveBeenCalled();
    expect(onOuter).not.toHaveBeenCalled();
  });

  describe('[case:components-misc-stacked-modal-duplicate-title-id] 겹친 창 제목 id', () => {
    /**
     * 겹친 창이 같은 제목 id(`modal-title`)를 써서 위 창의 접근 가능한 이름이 아래 창 제목으로 읽혔다
     * (#736 F 영역 실측: 라이선스 창이 「플러그인 정보」로, 수동 설치 창이 「manifest 미리보기」로 읽힘).
     *
     * @scenario stack=two_open, top_escape=enabled
     * @effects stacked_dialogs_label_with_own_title
     */
    it('겹친 창은 각자 자기 제목으로 이름이 붙는다(제목 id 가 겹치지 않는다)', () => {
      render(<Pair onOuter={vi.fn()} onInner={vi.fn()} />);

      const dialogs = Array.from(document.querySelectorAll('[role="dialog"]'));
      expect(dialogs).toHaveLength(2);
      const ids = dialogs.map((d) => d.getAttribute('aria-labelledby'));
      expect(new Set(ids).size).toBe(2);
      const names = dialogs.map((d) => {
        const id = d.getAttribute('aria-labelledby') ?? '';
        const label = document.getElementById(id);
        expect(d.contains(label)).toBe(true);
        return label?.textContent;
      });
      expect(names).toEqual(['바깥 창', '안쪽 창']);
    });
  });

  /**
   * @scenario stack=two_open, top_escape=enabled
   * @effects lower_rerender_keeps_order
   */
  it('아래 창이 새 onClose 로 다시 그려져도 맨 위 순서는 그대로다', () => {
    const onInner = vi.fn();
    const { rerender } = render(<Pair onOuter={vi.fn()} onInner={onInner} />);
    const onOuter = vi.fn();
    rerender(<Pair onOuter={onOuter} onInner={onInner} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onInner).toHaveBeenCalledTimes(1);
    expect(onOuter).not.toHaveBeenCalled();
  });
});
