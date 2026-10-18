// e2e:allow 옵션 설명은 표시 전용(목록을 펼쳤을 때 라벨 아래 작은 글자)이라 이 렌더 테스트가 DOM·클래스를 직접 단언한다. 화면 확인은 게시판 수정 › 게시글 설정 「비밀 답변 열람 범위」 펼침으로 PO 가 재현한다(계획서 §13.8-5).
/**
 * @file Select.description.test.tsx
 * @description Select 옵션 설명(`description`) — 목록을 펼쳤을 때만 라벨 아래 작은 글자로 보인다
 *
 * 게시판 「비밀 답변 열람 범위」처럼 값마다 뜻을 풀어 줘야 하는 선택 상자가 쓴다. 닫힌 상자(트리거)에는
 * 라벨만 보이고, description 이 없는 옵션은 종전처럼 라벨 하나만 그린다.
 */
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { Select } from '../Select';

const OPTIONS = [
  { value: 'a', label: '경로 규칙', description: '원글을 볼 수 있는 사람은 아래 답변을 봅니다' },
  { value: 'b', label: '하위만' },
];

describe('Select 옵션 설명 (description)', () => {
  // @scenario option=with_description
  // @effects description_shown_small_under_label, trigger_shows_label_only
  it('description 이 있는 옵션은 펼친 목록에서 라벨 아래 작은 글자로 설명을 보이고, 닫힌 상자에는 라벨만 보인다', () => {
    render(<Select options={OPTIONS} value="a" onChange={() => {}} />);

    expect(screen.getByRole('button').textContent).toBe('경로 규칙');

    fireEvent.click(screen.getByRole('button'));
    const desc = screen.getByText('원글을 볼 수 있는 사람은 아래 답변을 봅니다');
    expect(desc.className).toContain('text-xs');
    expect(screen.getAllByRole('option')[0].contains(desc)).toBe(true);
  });

  // @scenario option=without_description
  // @effects plain_option_unchanged
  it('description 이 없는 옵션은 종전처럼 라벨 하나만 그린다', () => {
    render(<Select options={OPTIONS} value="a" onChange={() => {}} />);

    fireEvent.click(screen.getByRole('button'));
    expect(screen.getAllByRole('option')[1].textContent).toBe('하위만');
  });
});
