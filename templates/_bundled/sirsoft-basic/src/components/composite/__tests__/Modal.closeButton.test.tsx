/**
 * 사용자 템플릿 Modal — 머리말 닫기(×) 버튼의 손가락 커서.
 *
 * 7.1.0 전수 실측 K2 관측 O-K2-8: 스크랩 해제·신고 취소·신고 접수 창의 닫기(×) 버튼만 커서가
 * default 였다(같은 창의 「취소」·확인 버튼은 pointer). 사용자 템플릿 기반 스타일은 button 에
 * 손가락 커서를 주지 않으므로(Tailwind v4 preflight) 버튼마다 `cursor-pointer` 를 달아야 한다.
 * 관리자 템플릿 Modal 은 공용 `.button` 클래스가 커서를 주므로 이 결함이 없다.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { Modal } from '../Modal';

describe('[case:modal-close-button-pointer] Modal (sirsoft-basic) 닫기 버튼', () => {
  /**
   * @scenario header=title_and_close
   * @effects close_button_uses_pointer_cursor
   */
  it('제목이 있는 창의 닫기(×) 버튼은 손가락 커서를 쓴다', () => {
    render(<Modal isOpen onClose={vi.fn()} title="스크랩을 해제할까요?"><div>본문</div></Modal>);

    // 닫기 버튼은 머리말의 이름(aria-label) 붙은 버튼 하나다 — 번역 함수는 시험 환경마다 다르게 풀려 이름 문자열 대신 속성으로 찾는다.
    const close = within(screen.getByRole('dialog')).getAllByRole('button').find((b) => b.hasAttribute('aria-label'));
    expect(close).toBeTruthy();
    expect(close!.className.split(/\s+/)).toContain('cursor-pointer');
  });

  /**
   * @scenario header=close_only
   * @effects close_button_uses_pointer_cursor
   */
  it('제목 없이 닫기 버튼만 있는 창에서도 손가락 커서를 쓴다', () => {
    render(<Modal isOpen onClose={vi.fn()}><div>본문</div></Modal>);

    const close = within(screen.getByRole('dialog')).getAllByRole('button').find((b) => b.hasAttribute('aria-label'));
    expect(close).toBeTruthy();
    expect(close!.className.split(/\s+/)).toContain('cursor-pointer');
  });
});
