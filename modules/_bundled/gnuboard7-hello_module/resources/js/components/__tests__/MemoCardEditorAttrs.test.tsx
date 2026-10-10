/**
 * @file MemoCardEditorAttrs.test.tsx
 * @description MemoCard 두 판본의 레이아웃 편집기 표식·요소 ID 패스스루 테스트
 *
 * 7.1.0 전수 실측 G3: 레이아웃 편집기 팔레트에서 메모 카드를 꺼내 넣으면 카드는 캔버스에 그려지지만
 * 루트에 `data-editor-path` 가 없어 편집기가 그 노드를 찾지 못했다(선택·이동·삭제 불가, 캔버스의
 * `[data-editor-path]` 개수 59 → 59 그대로). composite 컴포넌트는 코어가 주입하는 `editorAttrs` 를 받아
 * 시각적 루트에 펼쳐야 하고, 편집기 [속성] 탭의 「요소 ID」(`id`)도 루트에 닿아야 한다.
 *
 * @scenario-manifest tests/scenarios/memo-card-editor-attrs.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { MemoCard } from '../composite/MemoCard';
import { MemoCard as AdminMemoCard } from '../for/gnuboard7-hello_admin_template/MemoCard';

const variants: Array<[string, typeof MemoCard]> = [
  ['기본 판본', MemoCard],
  ['Hello 관리자 템플릿 판본', AdminMemoCard],
];

describe.each(variants)('[case:memo-card-editor-attrs] MemoCard %s', (_label, Component) => {
  /**
   * @scenario context=editor
   * @effects editor_attrs_on_root
   */
  it('editorAttrs 의 편집기 표식과 핸들러를 루트에 펼친다', () => {
    const onClick = vi.fn();
    const { container } = render(
      <Component
        title="제목"
        body="본문"
        editorAttrs={{
          'data-editor-path': '5',
          'data-editor-name': 'gnuboard7-hello_module.MemoCard',
          'data-editor-type': 'composite',
          onClick,
        }}
      />,
    );
    const root = container.firstElementChild as HTMLElement;

    expect(root).toHaveAttribute('data-editor-path', '5');
    expect(root).toHaveAttribute('data-editor-type', 'composite');
    fireEvent.click(root);
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario context=editor
   * @effects element_id_on_root
   */
  it('요소 ID 를 루트에 전달한다', () => {
    const { container } = render(<Component title="제목" id="memo-card-1" />);

    expect(container.firstElementChild).toHaveAttribute('id', 'memo-card-1');
  });

  /**
   * @scenario context=runtime
   * @effects no_editor_marks_outside_editor
   */
  it('편집기 밖에서는 편집 표식이 없다', () => {
    const { container } = render(<Component title="제목" />);

    expect(container.querySelector('[data-editor-path]')).toBeNull();
    expect(container.firstElementChild).not.toHaveAttribute('editorattrs');
  });
});
