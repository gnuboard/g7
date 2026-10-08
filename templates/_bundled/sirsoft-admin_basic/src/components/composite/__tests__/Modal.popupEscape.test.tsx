/**
 * @file Modal.popupEscape.test.tsx
 * @description admin_basic Modal — 창 안에서 펼친 선택 목록이 있을 때의 Esc 회귀 테스트 (7.1.0 영역 J D-J3, 관측 O-G6)
 *
 * 창과 창 안의 선택 목록이 각자 document keydown 리스너로 Esc 를 받아, 목록을 펼친 채 Esc 를 한 번 누르면
 * 목록과 창이 함께 닫혔다(알림 템플릿 편집 창·스케줄 추가 창 실측 — 고르던 입력이 통째로 사라진다).
 * 창 안에 펼쳐진 팝업(`aria-haspopup` + `aria-expanded="true"`)이 있으면 그 Esc 는 팝업 몫으로 두고 창은 닫지 않는다.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent, screen } from '@testing-library/react';
import { Modal } from '../Modal';
import { Select } from '../../basic/Select';

const OPTIONS = [
  { value: 'artisan', label: 'Artisan' },
  { value: 'shell', label: 'Shell' },
];

describe('[case:components-misc-modal-escape-closes-open-popup-and-modal] admin_basic Modal — 펼친 선택 목록과 Esc', () => {
  /**
   * @scenario popup=select_open
   * @effects escape_closes_popup_before_modal
   */
  it('창 안 선택 목록을 펼친 채 Esc 한 번은 목록만 닫고 창은 남긴다', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen onClose={onClose} title="스케줄 추가">
        <Select options={OPTIONS} value="artisan" onChange={() => {}} />
      </Modal>,
    );

    const trigger = screen.getByRole('button', { name: 'Artisan' });
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).not.toHaveBeenCalled();
    expect(trigger.getAttribute('aria-expanded')).toBe('false');

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario popup=disclosure_expanded
   * @effects escape_ignores_non_popup_expanded
   */
  it('팝업이 아닌 펼침(아코디언처럼 aria-haspopup 없는 aria-expanded)은 창 닫기를 막지 않는다', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen onClose={onClose} title="상세">
        <button type="button" aria-expanded="true">구획</button>
      </Modal>,
    );

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario popup=outside_modal
   * @effects escape_ignores_popup_outside_modal
   */
  it('창 밖(아래 화면)에 펼쳐진 팝업은 맨 위 창의 Esc 를 막지 않는다', () => {
    const onClose = vi.fn();
    render(
      <>
        <button type="button" aria-haspopup="listbox" aria-expanded="true">바깥 목록</button>
        <Modal isOpen onClose={onClose} title="상세">본문</Modal>
      </>,
    );

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
