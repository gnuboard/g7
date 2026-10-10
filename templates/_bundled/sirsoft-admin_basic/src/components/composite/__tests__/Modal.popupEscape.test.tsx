/**
 * @file Modal.popupEscape.test.tsx
 * @description admin_basic Modal — 창 안에서 펼친 선택 목록이 있을 때의 Esc 회귀 테스트 (7.1.0 O-K3-16, g7 D-J3 와 같은 결함)
 *
 * 창과 창 안의 선택 목록이 각자 document keydown 리스너로 Esc 를 받아, 목록을 펼친 채 Esc 를 한 번 누르면
 * 목록과 창이 함께 닫혔다(게시판 환경설정 › 알림 템플릿 편집 창의 수신자 드롭다운 실측 — 저장 안 한 입력이 사라진다).
 * 창 안에 펼쳐진 팝업(`aria-haspopup` + `aria-expanded="true"`)이 있으면 그 Esc 는 팝업 몫으로 두고 창은 닫지 않는다.
 * 자기 키 처리기에서 곧바로 닫히는 팝업(작업 메뉴·태그 입력처럼 React onKeyDown 으로 닫는 것)은 실제 브라우저에서
 * 창의 document 리스너보다 먼저 닫힌 상태가 그려지므로, 키를 누른 순간(캡처 단계)에 펼침 여부를 읽어 둔다.
 *
 * @scenario-manifest tests/scenarios/modal-popup-escape.yaml
 */

import { describe, it, expect, vi } from 'vitest';
import React, { useState } from 'react';
import { flushSync } from 'react-dom';
import { render, fireEvent, screen } from '@testing-library/react';
import { Modal } from '../Modal';
import { Select } from '../../basic/Select';
import { SearchableDropdown } from '../SearchableDropdown';

const OPTIONS = [
  { value: 'artisan', label: 'Artisan' },
  { value: 'shell', label: 'Shell' },
];

/**
 * 자기 onKeyDown 에서 Esc 로 곧바로 닫히는 팝업 — 실제 브라우저처럼 창의 document 리스너보다 먼저 닫힘을 반영한다.
 *
 * @returns 팝업 트리거
 */
function SelfClosingPopup() {
  const [open, setOpen] = useState(true);
  return (
    <button
      type="button"
      aria-haspopup="menu"
      aria-expanded={open}
      onKeyDown={(event) => {
        if (event.key === 'Escape' && open) {
          event.preventDefault();
          flushSync(() => setOpen(false));
        }
      }}
    >
      작업
    </button>
  );
}

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
   * @scenario popup=searchable_dropdown_open
   * @effects escape_closes_popup_before_modal
   */
  it('창 안 검색 드롭다운(수신자 역할·사용자 고르기)을 펼친 채 Esc 한 번은 드롭다운만 닫는다', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen onClose={onClose} title="알림 템플릿 편집">
        <SearchableDropdown options={OPTIONS} value={[]} multiple triggerLabel="역할" onChange={() => {}} />
      </Modal>,
    );

    const trigger = document.querySelector('[role="dialog"] [aria-haspopup="listbox"]') as HTMLElement;
    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).not.toHaveBeenCalled();
    expect(trigger.getAttribute('aria-expanded')).toBe('false');

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario popup=self_closing_on_keydown
   * @effects escape_closes_popup_before_modal
   */
  it('자기 키 처리기에서 곧바로 닫히는 팝업도 그 Esc 로 창까지 닫히지 않는다', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen onClose={onClose} title="발급 내역">
        <SelfClosingPopup />
      </Modal>,
    );

    const trigger = screen.getByRole('button', { name: '작업' });
    trigger.focus();
    fireEvent.keyDown(trigger, { key: 'Escape' });

    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(onClose).not.toHaveBeenCalled();

    fireEvent.keyDown(trigger, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  /**
   * 입력칸에 초점이 있는 팝업(태그 입력·검색 선택)은 Esc 를 **자기 요소에서 먼저** 받아 목록을 닫는다. 그 뒤 document 로
   * 올라온 Esc 를 창이 보면 `aria-expanded` 는 이미 false 라, 펼친 팝업이 없다고 보고 창까지 닫았다(회원 목록 역할 일괄
   * 변경 창 E2E 실측 — 역할을 고르고 Esc 로 목록만 닫으려 하면 창이 사라졌다). 창은 팝업보다 먼저(capture) 판정해야 한다.
   *
   * @scenario popup=focused_input_closes_itself
   * @effects escape_closes_popup_before_modal
   */
  it('팝업 입력칸이 Esc 를 먼저 받아 스스로 닫혀도 그 Esc 한 번에 창은 닫히지 않는다', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen onClose={onClose} title="역할 추가">
        <input
          role="combobox"
          aria-haspopup="true"
          aria-expanded="true"
          aria-label="역할"
          onKeyDown={(e) => {
            if (e.key === 'Escape') e.currentTarget.setAttribute('aria-expanded', 'false');
          }}
        />
      </Modal>,
    );

    const input = screen.getByRole('combobox', { name: '역할' });
    fireEvent.keyDown(input, { key: 'Escape' });

    expect(input.getAttribute('aria-expanded')).toBe('false');
    expect(onClose).not.toHaveBeenCalled();

    fireEvent.keyDown(input, { key: 'Escape' });
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

  /**
   * @scenario popup=none
   * @effects escape_closes_modal_without_popup
   */
  it('펼친 팝업이 없으면 창 안 입력칸에 초점이 있어도 Esc 한 번에 창이 닫힌다', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen onClose={onClose} title="확인">
        <input aria-label="제목" />
      </Modal>,
    );

    const input = screen.getByRole('textbox', { name: '제목' });
    input.focus();
    fireEvent.keyDown(input, { key: 'Escape' });

    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
