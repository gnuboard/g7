/**
 * @file admin-mobile-viewport-css.test.tsx
 * @description 관리자 모바일 뷰포트 넘침 회귀 테스트 (템플릿 소유분: CSS + 컴포넌트)
 *
 * 브라우저 실측(390px / 320px)으로 확인된 결함에 1:1 대응한다.
 *
 * jsdom 은 레이아웃을 계산하지 않으므로 폭 자체는 검증할 수 없다.
 * 여기서는 "규칙/클래스가 선언되어 있는가" 만 단언하고, 실제 폭은 Playwright E2E
 * (tests/Playwright/specs/admin/mobile-viewport-overflow.spec.ts) 가 검증한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');
const read = (rel: string) => fs.readFileSync(path.resolve(baseDir, rel), 'utf8');

describe('B1 — .modal-container 뷰포트 clamp', () => {
  const css = read('src/styles/main.css');

  it('max-w-[calc(100vw-2rem)] 로 좌우 16px 여백을 확보한다', () => {
    const rule = css.match(/\.modal-container\s*\{[\s\S]*?\}/)?.[0] ?? '';
    expect(rule).toContain('max-w-[calc(100vw-2rem)]');
  });

  it('선언 순서 트랩 경고가 주석으로 남아 있다', () => {
    // .max-w-full 은 명시도가 같고(0,1,0) 빌드 CSS 에서 뒤에 정의되어 이 clamp 를 이긴다.
    // 이 사실을 모르면 레이아웃에 max-w-full 을 붙여 clamp 를 무효화하기 쉽다.
    expect(css).toContain('max-w-full');
    expect(css).toMatch(/명시도|승리/);
  });
});

describe('C4 — .pagination 줄바꿈', () => {
  const css = read('src/styles/main.css');

  it('flex-wrap 이 있어 페이지 수가 많아도 넘치지 않는다', () => {
    const rule = css.match(/\.pagination\s*\{[\s\S]*?\}/)?.[0] ?? '';
    expect(rule).toContain('flex-wrap');
    // 공통 컴포넌트라 이 한 줄로 주문상세(27px)·상품수정(29px) 두 지점이 동시에 해소된다.
    expect(rule).toContain('justify-center');
  });
});

describe('C1 — 다국어 로케일 탭 줄바꿈', () => {
  it.each([
    ['MultilingualInput.tsx', 'src/components/composite/MultilingualInput.tsx'],
    ['HtmlEditor.tsx', 'src/components/composite/HtmlEditor.tsx'],
  ])('%s 의 로케일 탭 행이 flex-wrap 을 갖는다', (_name, rel) => {
    const src = read(rel);
    // 로케일 3개 이상(ko/en/ja) + md 미만 뷰포트에서 탭 행이 컨테이너를 넘친다.
    // 이 두 컴포넌트는 40개 레이아웃에서 쓰이므로 여기서 고치면 전 화면이 해소된다.
    expect(src).toContain('flex flex-wrap items-center gap-2 mb-3');
    expect(src).not.toContain('"flex items-center gap-2 mb-3"');
  });
});
