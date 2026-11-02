// e2e:allow 번들 레이아웃 사용처가 없는 부품의 초기값 정화 단위 — 브라우저 실측 미커버(사유: 이력 문서 §8 「B3」 행).
/**
 * RichTextEditor 초기값 정화 (#158 부수 결함 B3)
 *
 * 초기화 useEffect 가 `innerHTML = initialValue` 로 정화 없이 넣어, 초기값의 <script>·on* 이 편집 영역에서
 * 살아나는 XSS 싱크였다(현재 번들 레이아웃 사용처 0). HtmlContent 와 같은 DOMPurify 로 거른다.
 */
import React from 'react';
import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import RichTextEditor from '../RichTextEditor';

describe('RichTextEditor 초기값은 DOMPurify 로 정화한다', () => {
  // @scenario initial_value=malicious_html
  // @effects rich_text_editor_sanitizes_initial_value
  it('script·on* 속성·iframe 을 걷어내고 서식 태그는 남긴다', () => {
    const { container } = render(
      <RichTextEditor
        name="body"
        initialValue={'<p onclick="alert(1)">안녕 <strong>굵게</strong></p><script>window.__x=1</script><img src="x" onerror="alert(2)"><iframe src="https://evil.test"></iframe>'}
      />,
    );
    const editable = container.querySelector('[contenteditable]') as HTMLElement;

    expect(editable.innerHTML).toContain('<strong>굵게</strong>');
    expect(editable.innerHTML).not.toMatch(/<script/i);
    expect(editable.innerHTML).not.toMatch(/<iframe/i);
    expect(editable.innerHTML).not.toMatch(/\son[a-z]+=/i);
  });
});
