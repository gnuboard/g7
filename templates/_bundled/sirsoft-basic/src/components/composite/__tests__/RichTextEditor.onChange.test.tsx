// e2e:allow 부품 콜백 시점 계약 — 번들 레이아웃에서 쓰이지 않는 부품이라 편집기 [동작] 탭 실측(이력 문서)으로 대신한다.
/**
 * RichTextEditor onChange 호출 시점 (7.1.0 O-I2F-4)
 *
 * 편집기 [동작] 탭은 이 부품의 「값이 바뀌면」을 원시값 콜백(`event: "onChange"`)으로 적는다. 그 표기는 부품이
 * onChange 를 부를 때마다 동작을 실행하므로, 마운트·리렌더 때 부르면 사용자가 아무것도 하지 않아도 동작이
 * 실행된다(사례 26). 종전에는 `useEffect([content, onChange])` 에서 불러 마운트와 콜백이 바뀌는 리렌더마다 불렸다.
 * 사용자가 내용을 바꿀 때만 부른다.
 */
import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import RichTextEditor from '../RichTextEditor';

describe('RichTextEditor onChange 는 사용자 입력 때만', () => {
  // @scenario event_notation=event
  // @effects raw_value_slot_writes_event_notation
  it('마운트·콜백이 바뀐 리렌더에서는 부르지 않는다', () => {
    const first = vi.fn();
    const { rerender } = render(<RichTextEditor name="body" initialValue="<p>a</p>" onChange={first} />);
    expect(first).not.toHaveBeenCalled();

    const second = vi.fn();
    rerender(<RichTextEditor name="body" initialValue="<p>a</p>" onChange={second} />);
    expect(first).not.toHaveBeenCalled();
    expect(second).not.toHaveBeenCalled();
  });

  // @scenario event_notation=event
  // @effects raw_value_slot_writes_event_notation
  it('편집 영역에 입력하면 그 HTML 로 한 번 부른다', () => {
    const onChange = vi.fn();
    const { container } = render(<RichTextEditor name="body" initialValue="" onChange={onChange} />);
    const editable = container.querySelector('[contenteditable]') as HTMLElement;
    editable.innerHTML = '<p>hello</p>';
    fireEvent.input(editable);

    expect(onChange).toHaveBeenCalledTimes(1);
    expect(onChange).toHaveBeenCalledWith('<p>hello</p>');
  });
});
