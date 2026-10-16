/**
 * @file buttonAriaDisabled.test.ts
 * @description 단추형 클래스의 비활성 꾸밈은 `disabled:` 와 `aria-disabled:` 를 짝으로 둔다 (#757 링크 단계 W7)
 *
 * 화면 이동 단추를 `<a href>` 로 바꾸면서 `<a class="button btn btn-primary" disabled="…">` 가 생겼다. 링크에는
 * `:disabled` 가 없어 `disabled:opacity-50` 이 걸리지 않는다 — A 컴포넌트는 비활성 링크에 `aria-disabled="true"` 를
 * 붙이므로, 단추형 클래스가 `aria-disabled:` 로 같은 꾸밈을 주지 않으면 권한 없는 「등록」 링크가 흐려지지 않는다.
 * 링크가 될 수 없는 입력칸(입력·여러 줄·선택칸·선택 트리거)은 대상이 아니다.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 */

import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const css = fs.readFileSync(path.resolve(__dirname, '../../styles/main.css'), 'utf8');

/** 링크로 그려질 일이 없는 입력칸 클래스 — `:disabled` 가 실제로 걸리는 폼 요소 전용 */
const FORM_FIELD_CLASSES = new Set([
  'input',
  'textarea',
  'select',
  'select-composite',
  'select-trigger',
  'searchable-dropdown-trigger',
]);

/**
 * `disabled:` 변형을 쓰는 클래스 블록을 모은다(중괄호가 중첩되지 않은 맨 안쪽 블록).
 *
 * @returns 클래스 이름과 본문
 */
function blocksUsingDisabled(): Array<{ name: string; body: string }> {
  const out: Array<{ name: string; body: string }> = [];
  const re = /([^{};]+)\{([^{}]*)\}/g;
  let match: RegExpExecArray | null;
  while ((match = re.exec(css)) !== null) {
    const selector = match[1].replace(/\/\*[\s\S]*?\*\//g, '').trim();
    const body = match[2];
    const single = /^\.([\w-]+)$/.exec(selector);
    if (!single || !/(^|[\s:])disabled:/.test(body.replace(/aria-disabled:/g, ''))) continue;
    out.push({ name: single[1], body });
  }

  return out;
}

describe('단추형 클래스 비활성 꾸밈 — aria-disabled 짝', () => {
  // @scenario component=button_styles
  // @effects disabled_link_styled_like_disabled_button
  it('disabled: 를 쓰는 클래스가 하나 이상 있다(파서 확인)', () => {
    expect(blocksUsingDisabled().map((b) => b.name)).toEqual(expect.arrayContaining(['button', 'btn']));
  });

  // @scenario component=button_styles
  // @effects disabled_link_styled_like_disabled_button
  it('입력칸을 뺀 모든 클래스는 disabled: 꾸밈마다 같은 aria-disabled: 꾸밈을 가진다', () => {
    const missing: string[] = [];
    for (const { name, body } of blocksUsingDisabled()) {
      if (FORM_FIELD_CLASSES.has(name)) continue;
      const tokens = body.match(/(?:dark:)?(?<!aria-)disabled:[\w:/.\-[\]]+/g) ?? [];
      for (const token of tokens) {
        const pair = token.replace(/disabled:/, 'aria-disabled:');
        if (!body.includes(pair)) missing.push(`.${name} ${token} → ${pair}`);
      }
    }
    expect(missing).toEqual([]);
  });
});
