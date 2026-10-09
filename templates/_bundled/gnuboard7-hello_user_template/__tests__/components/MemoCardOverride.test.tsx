/**
 * @file MemoCardOverride.test.tsx
 * @description Hello 모듈 MemoCard 대체본의 레이아웃 편집기 표식·요소 ID 패스스루 테스트
 *
 * 7.1.0 전수 실측 G3: 모듈 MemoCard 가 편집기 표식(`editorAttrs`)을 루트에 펼치지 않아 편집기 팔레트에서
 * 꺼낸 카드를 편집기가 찾지 못했다. 이 템플릿의 대체본도 같은 마크업 표면을 받으므로 같은 계약을 지킨다.
 *
 * @scenario-manifest tests/scenarios/memo-card-override-editor-attrs.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { HelloModuleMemoCard } from '../../src/components/overrides/gnuboard7-hello_module/MemoCard';

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
