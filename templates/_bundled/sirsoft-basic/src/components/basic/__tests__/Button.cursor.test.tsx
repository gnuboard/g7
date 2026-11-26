/**
 * Button(sirsoft-basic) — 켜진 단추는 손 모양 커서, 잠긴 단추는 금지 커서
 *
 * Tailwind v4 는 단추 기본 커서를 `default` 로 바꿨다. 사용자 템플릿 Button 의 기본 클래스에 커서가 없어, 레이아웃이
 * `cursor-pointer` 를 따로 적은 단추(「취소」 등)만 손 모양이고 나머지(비회원 글 삭제, 비밀번호 확인의 주 단추 등 95곳)는
 * 화살표였다. 관리자 템플릿 Button 의 기본 클래스(`button`)와 같게 부품이 기본값을 준다. 레이아웃이 커서를 따로 주면 그것이 이긴다.
 *
 * 조합 요약(마커 아님, 평문): cursor=enabled|disabled|override
 * 효과 요약(마커 아님, 평문): user_button_pointer_cursor_by_default
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Button } from '../Button';

describe('Button(sirsoft-basic) 커서 기본값', () => {
  // @scenario cursor=enabled
  // @effects user_button_pointer_cursor_by_default
  it('켜진 단추는 cursor-pointer 를 기본으로 갖는다', () => {
    render(<Button className="px-4 py-2 bg-red-600 dark:bg-red-500">삭제</Button>);
    const classes = screen.getByRole('button', { name: '삭제' }).className.split(/\s+/);
    expect(classes, '켜진 단추의 손 모양 커서가 없습니다').toContain('cursor-pointer');
  });

  // @scenario cursor=disabled
  // @effects user_button_pointer_cursor_by_default
  it('잠긴 단추는 cursor-not-allowed 를 기본으로 갖는다', () => {
    render(<Button disabled className="px-4 py-2">확인</Button>);
    const button = screen.getByRole('button', { name: '확인' });
    expect(button).toBeDisabled();
    expect(button.className.split(/\s+/)).toContain('disabled:cursor-not-allowed');
  });

  // @scenario cursor=override
  // @effects user_button_pointer_cursor_by_default
  it('레이아웃이 다른 커서를 주면 그 값이 남는다', () => {
    render(<Button className="cursor-default">읽기 전용</Button>);
    expect(screen.getByRole('button', { name: '읽기 전용' }).className.split(/\s+/)).toContain('cursor-default');
  });
});
