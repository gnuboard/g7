// e2e:allow 본문 서식(style 속성)을 지연 속성으로 받아 붙인 뒤 CSSOM 으로 입히는 연결을 잠근다 — 정책을 강제한 브라우저에서의 위반 0건과 글 보기 화면의 서식 표시는 출시 전 화면 재실측(엄격한 스타일 정책 강제 + 글 보기)이 덮는다
/**
 * HtmlContent — 본문 서식 지연 적용 (공개 #163, 엄격한 스타일 정책 호환)
 *
 * 회귀 배경: 운영자가 `style-src 'self'; style-src-attr 'none'` 을 걸면 HTML 문자열(dangerouslySetInnerHTML)의
 * `style="…"` 속성이 막혀 본문의 글자색·정렬·그림 너비가 사라진다. 코어 장치(`G7Core.inlineStyle`)가 있으면 style 을
 * `data-g7-style` 로 받아 화면에 붙인 뒤 `el.style.cssText` 로 입힌다. 장치가 없는 옛 코어에서는 종전 그대로다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/content-inline-style-deferral.yaml
 */
import React from 'react';
import { render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
// @ts-ignore - DOMPurify 타입 정의 없음
import DOMPurify from 'dompurify';
import { HtmlContent } from '../HtmlContent';
import { DEFERRED_ATTR, installInlineStyleMock } from '../../../../__tests__/helpers/inlineStyleMock';

afterEach(() => {
  delete (window as any).G7Core;
});

describe('HtmlContent 본문 서식 지연 적용', () => {
  /**
   * @scenario surface=html_content, core=with_inline_style
   * @effects style_attribute_deferred, style_applied_via_cssom, dedicated_purify_instance
   */
  it('장치가 있으면 style 속성 없이 붙이고 CSSOM 으로 입힌다', () => {
    const mock = installInlineStyleMock();
    const { container } = render(<HtmlContent content={'<p style="color: red; text-align: center">본문</p>'} />);
    const p = container.querySelector('p') as HTMLElement;

    // React 에 넘긴 HTML 문자열에는 style 속성이 없다(막히는 것은 문자열의 style 속성이다)
    expect(mock.sanitize).toHaveBeenCalledTimes(1);
    expect(mock.sanitize.mock.results[0].value).not.toMatch(/\sstyle=/);
    expect(mock.sanitize.mock.results[0].value).toContain(`${DEFERRED_ATTR}=`);
    // 붙인 뒤에는 지연 속성이 남지 않고 서식이 입혀져 있다
    expect(container.querySelectorAll(`[${DEFERRED_ATTR}]`).length).toBe(0);
    expect(p.style.color).toBe('red');
    expect(p.style.textAlign).toBe('center');
    // 전역 DOMPurify 가 아니라 전용 인스턴스를 넘긴다(코어 훅이 전역으로 새지 않게)
    const instance = mock.sanitize.mock.calls[0][0];
    expect(instance).not.toBe(DOMPurify);
    expect(typeof instance.sanitize).toBe('function');
  });

  /**
   * @scenario surface=html_content, core=with_inline_style
   * @effects style_reapplied_on_content_change
   */
  it('같은 본문으로 부모가 다시 그려도 입힌 서식이 남는다 (React 19 innerHTML 재기록 회귀)', () => {
    // 버그: 렌더마다 새 `{ __html }` 객체를 넘겨 React 19 가 innerHTML 을 다시 썼고, 서식이 지연 속성 상태로 되돌아갔다.
    // 글 보기 화면에서 첫 표시 직후 재렌더로 글자색·정렬이 사라졌다(엄격한 스타일 정책 실측).
    installInlineStyleMock();
    const html = '<p style="color: red">본문</p>';
    const { container, rerender } = render(<HtmlContent content={html} className="a" />);
    const first = container.querySelector('p') as HTMLElement;
    expect(first.style.color).toBe('red');

    rerender(<HtmlContent content={html} className="b" />);
    const second = container.querySelector('p') as HTMLElement;

    expect(second).toBe(first);
    expect(second.style.color).toBe('red');
    expect(container.querySelectorAll(`[${DEFERRED_ATTR}]`).length).toBe(0);
  });

  /**
   * @scenario surface=html_content, core=with_inline_style
   * @effects style_reapplied_on_content_change
   */
  it('본문이 바뀌면 다시 입힌다', () => {
    installInlineStyleMock();
    const { container, rerender } = render(<HtmlContent content={'<p style="color: red">하나</p>'} />);
    expect((container.querySelector('p') as HTMLElement).style.color).toBe('red');

    rerender(<HtmlContent content={'<p style="color: blue">둘</p>'} />);
    const p = container.querySelector('p') as HTMLElement;

    expect(p.textContent).toBe('둘');
    expect(p.style.color).toBe('blue');
    expect(container.querySelectorAll(`[${DEFERRED_ATTR}]`).length).toBe(0);
  });

  /**
   * 빈 값 → 본문 → 일반 텍스트 → 본문으로 바뀌어도(조기 return 을 오가도) 훅 순서가 흔들리지 않고 다시 입힌다.
   *
   * @scenario surface=html_content, core=with_inline_style
   * @effects hooks_stable_across_early_return, style_reapplied_on_content_change
   */
  it('빈 값·일반 텍스트를 오가도 오류 없이 다시 입힌다', () => {
    const mock = installInlineStyleMock();
    const body = '<p style="color: red">본문</p>';
    const { container, rerender } = render(<HtmlContent content="" />);
    expect(container.firstChild).toBeNull();
    expect(mock.sanitize).not.toHaveBeenCalled();

    rerender(<HtmlContent content={body} />);
    expect((container.querySelector('p') as HTMLElement).style.color).toBe('red');

    rerender(<HtmlContent content={body} isHtml={false} />);
    expect(container.querySelector('p')).toBeNull();
    expect(container.textContent).toContain('<p style=');

    rerender(<HtmlContent content={body} />);
    expect((container.querySelector('p') as HTMLElement).style.color).toBe('red');
    expect(container.querySelectorAll(`[${DEFERRED_ATTR}]`).length).toBe(0);
  });

  /**
   * @scenario surface=html_content, core=with_inline_style_and_policy
   * @effects policy_path_gets_defer_flag, style_applied_via_cssom
   */
  it('본문 콘텐츠 규칙 경로에는 지연 플래그를 넘기고 그 결과를 입힌다', () => {
    const policySanitize = vi.fn((_purify: any, _html: string, _config: Record<string, any>) => `<p ${DEFERRED_ATTR}="color: green">규칙</p>`);
    const mock = installInlineStyleMock({ contentPolicy: { sanitize: policySanitize } });
    const { container } = render(<HtmlContent content={'<p style="color: green">규칙</p>'} />);

    expect(policySanitize.mock.calls[0][2].G7_DEFER_STYLE).toBe(true);
    // 규칙 경로가 정화했으면 폴백 정화는 돌지 않는다
    expect(mock.sanitize).not.toHaveBeenCalled();
    expect((container.querySelector('p') as HTMLElement).style.color).toBe('green');
    expect(container.querySelectorAll(`[${DEFERRED_ATTR}]`).length).toBe(0);
  });

  /**
   * @scenario surface=html_content, core=with_inline_style
   * @effects forbid_config_passed_through
   */
  it('prop 으로 style 을 금지하면 그 설정이 장치에 그대로 가서 서식이 버려진다', () => {
    const mock = installInlineStyleMock();
    const { container } = render(
      <HtmlContent content={'<p style="color: red">본문</p>'} purifyConfig={{ FORBID_ATTR: ['style'] }} />,
    );
    const p = container.querySelector('p') as HTMLElement;

    expect(mock.sanitize.mock.calls[0][2].FORBID_ATTR).toContain('style');
    expect(p.style.color).toBe('');
    expect(p.hasAttribute(DEFERRED_ATTR)).toBe(false);
  });

  /**
   * @scenario surface=html_content, core=legacy
   * @effects legacy_core_unchanged
   */
  it('장치가 없으면(옛 코어) 종전 그대로 style 속성을 싣는다', () => {
    const { container } = render(<HtmlContent content={'<p style="color: red">본문</p>'} />);

    expect(container.innerHTML).toMatch(/<p style="color: ?red;?">본문<\/p>/);
    expect(container.querySelectorAll(`[${DEFERRED_ATTR}]`).length).toBe(0);
  });

  /**
   * @scenario surface=html_content, core=legacy_with_policy
   * @effects legacy_core_unchanged
   */
  it('장치가 없으면 본문 콘텐츠 규칙 경로에 지연 플래그를 넘기지 않는다', () => {
    const policySanitize = vi.fn((_purify: any, _html: string, _config: Record<string, any>) => '<p style="color: red">규칙</p>');
    (window as any).G7Core = { contentPolicy: { sanitize: policySanitize } };
    const { container } = render(<HtmlContent content={'<p style="color: red">규칙</p>'} />);

    expect('G7_DEFER_STYLE' in policySanitize.mock.calls[0][2]).toBe(false);
    expect(container.innerHTML).toContain('style="color: red"');
  });
});
