// e2e:allow 번들 레이아웃 사용처가 없는 부품의 초기값 서식 지연 적용 단위 — 브라우저 실측 미커버(사용처 0, 초기값 정화 시험과 같은 사유)
/**
 * RichTextEditor — 초기값 서식 지연 적용 (공개 #163, 엄격한 스타일 정책 호환)
 *
 * 회귀 배경: 초기값을 `innerHTML` 로 넣으므로, 운영자가 `style-src-attr 'none'` 을 걸면 초기값의 `style="…"` 속성이
 * 막혀 편집 영역에서 서식이 사라진다. 코어 장치(`G7Core.inlineStyle`)가 있으면 정화 → innerHTML → `el.style.cssText`
 * 대입 순서로 입힌다. 장치가 없는 옛 코어에서는 종전 그대로다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/content-inline-style-deferral.yaml
 */
import React from 'react';
import { render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
// @ts-ignore - DOMPurify 타입 정의 없음
import DOMPurify from 'dompurify';
import RichTextEditor from '../RichTextEditor';
import { DEFERRED_ATTR, installInlineStyleMock } from '../../../../__tests__/helpers/inlineStyleMock';

afterEach(() => {
  delete (window as any).G7Core;
});

describe('RichTextEditor 초기값 서식 지연 적용', () => {
  /**
   * @scenario surface=rich_text_editor, core=with_inline_style
   * @effects style_attribute_deferred, style_applied_via_cssom, dedicated_purify_instance
   */
  it('장치가 있으면 정화 → innerHTML → 적용 순서로 서식을 입힌다', () => {
    const mock = installInlineStyleMock();
    const { container } = render(
      <RichTextEditor name="body" initialValue={'<p style="color: red">안녕</p><script>window.__x=1</script>'} />,
    );
    const editable = container.querySelector('[contenteditable]') as HTMLElement;
    const p = editable.querySelector('p') as HTMLElement;

    expect(mock.sanitize.mock.results[0].value).not.toMatch(/\sstyle=/);
    expect(mock.sanitize.mock.calls[0][0]).not.toBe(DOMPurify);
    // 적용은 편집 영역 요소를 대상으로 한다(붙인 뒤에 입힌다)
    expect(mock.apply).toHaveBeenCalledWith(editable);
    expect(mock.sanitize.mock.invocationCallOrder[0]).toBeLessThan(mock.apply.mock.invocationCallOrder[0]);
    expect(p.style.color).toBe('red');
    expect(editable.querySelectorAll(`[${DEFERRED_ATTR}]`).length).toBe(0);
    // 정화는 그대로다
    expect(editable.innerHTML).not.toMatch(/<script/i);
  });

  /**
   * @scenario surface=rich_text_editor, core=legacy
   * @effects legacy_core_unchanged
   */
  it('장치가 없으면(옛 코어) 종전 그대로 style 속성을 싣는다', () => {
    const { container } = render(<RichTextEditor name="body" initialValue={'<p style="color: red">안녕</p>'} />);
    const editable = container.querySelector('[contenteditable]') as HTMLElement;

    expect(editable.innerHTML).toMatch(/<p style="color: ?red;?">안녕<\/p>/);
  });
});
