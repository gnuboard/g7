/**
 * @file pageHeaderNarrowScreen.test.ts
 * @description 페이지 헤더 세 변형 모두 좁은 화면(≤640px)에서 세로로 쌓이고, 버튼 묶음은 줄바꿈된다
 *
 * 좁은 화면 규칙이 기본 `.page-header` 에만 걸려 있어, 여백형(`.page-header-padded`)·풀블리드형(`.page-header-fullbleed`)
 * 헤더는 375px 에서도 가로 한 줄로 남았다. 왼쪽 제목·메타가 폭을 차지하면 오른쪽 `.page-actions` 가 눌려 「원 주문 보기」·
 * 「목록」 같은 버튼 글자가 한 글자씩 세로로 꺾였다(클레임 상세·주문 상세·상품 폼·쿠폰 폼·게시판 폼·회원 상세).
 * 반응형 접두 클래스(`sm:`)는 이 템플릿 빌드 CSS 에 없으므로 공통 CSS 에서 고친다.
 */

import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

const css = fs.readFileSync(path.resolve(__dirname, '../../styles/main.css'), 'utf8');

/**
 * 640px 이하 미디어쿼리 블록 본문들을 돌려준다.
 *
 * @returns 블록 본문 목록
 */
function narrowBlocks(): string[] {
  const out: string[] = [];
  const re = /@media\s*\(max-width:\s*640px\)\s*\{/g;
  let match: RegExpExecArray | null;
  while ((match = re.exec(css)) !== null) {
    let depth = 1;
    let i = re.lastIndex;
    while (i < css.length && depth > 0) {
      if (css[i] === '{') depth++;
      else if (css[i] === '}') depth--;
      i++;
    }
    out.push(css.slice(re.lastIndex, i - 1));
  }

  return out;
}

describe('페이지 헤더 좁은 화면 규칙', () => {
  // 모집단을 CSS 에서 파생한다 — 헤더 변형을 더 만들면 그 변형도 검사 대상이 된다.
  const variants = [...css.matchAll(/^\s*\.(page-header(?:-[a-z]+)?)\s*\{/gm)].map((m) => m[1]);

  /**
   * @effects page_header_variants_stack_on_narrow_screens
   */
  it('헤더 변형이 셋 이상 정의돼 있다(모집단 확인)', () => {
    expect(variants).toEqual(expect.arrayContaining(['page-header', 'page-header-padded', 'page-header-fullbleed']));
  });

  // 좁은 화면 블록 안에서 `flex-direction: column` 을 가진 규칙의 선택자 목록을 모은다.
  const stackedSelectors = new Set<string>();
  for (const body of narrowBlocks()) {
    for (const rule of body.matchAll(/([^{}]+)\{([^}]*)\}/g)) {
      if (/flex-direction:\s*column/.test(rule[2])) {
        rule[1].split(',').map((s) => s.trim().replace(/^\./, '')).forEach((s) => stackedSelectors.add(s));
      }
    }
  }

  /**
   * CSS 에서 파생한 모든 변형이 쌓인다 — 새 변형이 생기면 이 검사가 잡는다.
   *
   * @effects page_header_variants_stack_on_narrow_screens
   */
  it('파생한 모든 헤더 변형이 640px 이하에서 세로로 쌓인다', () => {
    for (const variant of variants) {
      expect(stackedSelectors.has(variant), `.${variant} 가 좁은 화면에서도 가로 한 줄이라 버튼 글자가 꺾인다`).toBe(true);
    }
  });

  /**
   * @scenario header_variant=page_header
   * @effects page_header_variants_stack_on_narrow_screens
   */
  it('기본 헤더가 쌓인다', () => {
    expect(stackedSelectors.has('page-header')).toBe(true);
  });

  /**
   * @scenario header_variant=page_header_padded
   * @effects page_header_variants_stack_on_narrow_screens
   */
  it('여백형 헤더(클레임 상세·주문 상세·상품 폼·쿠폰 폼)가 쌓인다', () => {
    expect(stackedSelectors.has('page-header-padded'), '여백형 헤더가 좁은 화면에서도 가로 한 줄이다').toBe(true);
  });

  /**
   * @scenario header_variant=page_header_fullbleed
   * @effects page_header_variants_stack_on_narrow_screens
   */
  it('풀블리드형 헤더(게시판 폼·회원 상세)가 쌓인다', () => {
    expect(stackedSelectors.has('page-header-fullbleed'), '풀블리드형 헤더가 좁은 화면에서도 가로 한 줄이다').toBe(true);
  });

  /**
   * @effects page_actions_wrap_instead_of_squeezing_buttons
   */
  it('버튼 묶음은 폭이 모자라면 줄을 바꾼다', () => {
    const block = css.match(/\.page-actions\s*\{([^}]*)\}/);
    expect(block, '.page-actions 규칙이 없다').not.toBeNull();
    expect(block![1]).toMatch(/flex-wrap/);
  });
});
