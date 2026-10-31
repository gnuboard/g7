/**
 * @file MemoCardOverride.test.tsx
 * @description Hello 모듈 MemoCard 대체본의 레이아웃 편집기 표식·요소 ID 패스스루 테스트
 *
 * 7.1.0 전수 실측: 모듈 MemoCard 가 편집기 표식(`editorAttrs`)을 루트에 펼치지 않아 편집기 팔레트에서
 * 꺼낸 카드를 편집기가 찾지 못했다. 이 템플릿의 대체본도 같은 마크업 표면을 받으므로 같은 계약을 지킨다.
 *
 * 0.3.0: 대체본의 모양이 이 템플릿에 없는 Tailwind 클래스로 적혀 있어 강조선·제목 서식이 화면에 나오지 않았다.
 * 이제 템플릿 자기 순수 CSS(src/styles/main.css)의 클래스로 그리고, Hello 모듈 화면을 덮는 확장 CSS 덮기 파일
 * (src/styles/overrides/gnuboard7-hello_module.css)을 template.json 에 선언한다.
 *
 * @scenario-manifest tests/scenarios/memo-card-override-editor-attrs.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { render } from '@testing-library/react';
import { HelloModuleMemoCard } from '../../src/components/overrides/gnuboard7-hello_module/MemoCard';

const TEMPLATE_ROOT = path.resolve(__dirname, '../..');
const read = (rel: string) => fs.readFileSync(path.join(TEMPLATE_ROOT, rel), 'utf8');
const stripComments = (css: string) => css.replace(/\/\*[\s\S]*?\*\//g, '');

describe('[case:memo-card-override-editor-attrs] HelloModuleMemoCard (대체본)', () => {
  /**
   * @scenario context=editor
   * @effects editor_attrs_on_root
   */
  it('editorAttrs 의 편집기 표식을 루트에 펼친다', () => {
    const { container } = render(
      <HelloModuleMemoCard title="제목" editorAttrs={{ 'data-editor-path': '3', 'data-editor-type': 'composite' }} />,
    );

    expect(container.firstElementChild).toHaveAttribute('data-editor-path', '3');
  });

  /**
   * @scenario context=editor
   * @effects element_id_on_root
   */
  it('요소 ID 를 루트에 전달한다', () => {
    const { container } = render(<HelloModuleMemoCard title="제목" id="memo-card-2" />);

    expect(container.firstElementChild).toHaveAttribute('id', 'memo-card-2');
  });

  /**
   * @scenario context=runtime
   * @effects no_editor_marks_outside_editor
   */
  it('편집기 밖에서는 편집 표식이 없다', () => {
    const { container } = render(<HelloModuleMemoCard title="제목" />);

    expect(container.querySelector('[data-editor-path]')).toBeNull();
  });
});

describe('[case:memo-card-override-editor-attrs] HelloModuleMemoCard 스타일 — 템플릿 자기 CSS', () => {
  /**
   * @scenario context=style
   * @effects override_uses_template_css_classes
   */
  it('대체본은 템플릿 CSS 에 정의된 hello-memo-card 계열 클래스만 쓴다', () => {
    const css = stripComments(read('src/styles/main.css'));
    const { container } = render(<HelloModuleMemoCard title="제목" body="본문" highlighted />);
    const tokens = [container.firstElementChild!, ...container.querySelectorAll('*')].flatMap((el) => [...el.classList]);

    expect(container.firstElementChild).toHaveClass('hello-memo-card', 'hello-memo-card--highlighted');
    for (const token of tokens) {
      expect(token).toMatch(/^hello-memo-card/);
      expect(css).toContain(`.${token}`);
    }
  });

  /**
   * @scenario context=style
   * @effects accent_is_css_color
   */
  it('accent 는 CSS 색 값으로 왼쪽 강조선 색을 바꾼다', () => {
    const { container } = render(<HelloModuleMemoCard title="제목" accent="#10b981" />);

    expect((container.firstElementChild as HTMLElement).style.borderLeftColor).toBe('rgb(16, 185, 129)');
  });

  /**
   * @scenario context=style
   * @effects extension_css_override_declared_for_hello_module
   */
  it('확장 CSS 덮기 파일은 template.json 에 선언되고 원본은 대상 확장 클래스만 덮는다', () => {
    const manifest = JSON.parse(read('template.json'));
    const entry = (manifest.extension_css_overrides ?? []).find((e: { target: string }) => e.target === 'gnuboard7-hello_module');

    expect(entry).toEqual({
      target: 'gnuboard7-hello_module',
      targetVersion: '^0.3',
      path: 'dist/css/overrides/gnuboard7-hello_module.css',
    });
    // 대체본 targetVersion 과 덮기 파일 targetVersion 이 같은 판을 겨눈다
    const components = JSON.parse(read('components.json'));
    expect(components.overrides.find((o: { name: string }) => o.name === 'MemoCard').targetVersion).toBe('^0.3');

    const source = stripComments(read('src/styles/overrides/gnuboard7-hello_module.css'));
    const selectors = [...source.matchAll(/([^{}]+)\{/g)].map((m) => m[1].trim());
    expect(selectors.length).toBeGreaterThan(0);
    for (const selector of selectors) {
      expect(selector).toMatch(/^\.hellomodule-/);
    }
    // 템플릿 자기 CSS 는 assets.css 로 싣는다
    expect(manifest.assets.css).toContain('dist/css/components.css');
  });
});
