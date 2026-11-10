/**
 * TabNavigation 막대와 단추의 줄바꿈 규칙 (#869, 설계 확정 2026-11-08 「두 줄은 두되 글자만 안 꺾임」)
 *
 * 탭이 많으면 막대는 단추째 다음 줄로 감기고(`flex-wrap`), 단추는 글자를 꺾지 않고 제 폭을 지킨다
 * (`whitespace-nowrap shrink-0`). 실제 줄 수와 단추 높이는 Playwright `settings-tab-labels-no-wrap.spec.ts` 가 1280 폭에서 잰다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect } from 'vitest';

const css = fs.readFileSync(path.resolve(__dirname, '../../../styles/main.css'), 'utf8');
const block = (name: string) => {
  const at = css.indexOf(`.${name} {`);
  return at < 0 ? '' : css.slice(at, css.indexOf('}', at));
};

describe('TabNavigation 줄바꿈 규칙', () => {
  // @scenario element=tab_nav
  // @effects settings_tab_bar_wraps_instead_of_squeezing
  it('두 막대 모양 모두 넘치면 다음 줄로 감긴다', () => {
    expect(block('tab-nav-default')).toContain('flex-wrap');
    expect(block('tab-nav-underline')).toContain('flex-wrap');
  });

  // @scenario element=tab_button
  // @effects settings_tab_labels_stay_on_one_line
  it('단추는 글자를 꺾지 않고 줄어들지 않는다', () => {
    expect(block('tab-btn-base')).toContain('whitespace-nowrap');
    expect(block('tab-btn-base')).toContain('shrink-0');
  });
});
