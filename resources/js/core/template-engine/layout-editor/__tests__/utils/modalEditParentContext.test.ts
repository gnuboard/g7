/**
 * modalEditParentContext — 창 편집 미리보기에서 창 노드에 `$parent` 맥락을 넘기는 판정
 *
 * 런타임은 창을 연 레이아웃의 데이터 맥락을 `parentDataContext` 로 창에 넘긴다(template-engine.ts 의 모달 렌더).
 * 편집기는 창 편집 때 창 노드를 호스트 컴포넌트 뒤에 루트 형제로 덧붙여 그리므로, 같은 맥락을 직접 넘기지 않으면
 * `{{$parent._local.*}}` 를 읽는 창(장바구니 옵션 변경·구매 불가 안내, 주문 일괄변경 확인 등 30개)이 상태 표본을
 * 넣어도 빈 채로 그려진다.
 *
 * @scenario scope_kind=modal, patch_branch=local
 * @effects modal_edit_preview_passes_host_context_as_parent
 */

import { describe, it, expect } from 'vitest';
import { modalEditParentContext } from '../../utils/modalEditParentContext';

describe('[case:wysiwyg-7] modalEditParentContext', () => {
  const root = { _local: { items: [1, 2] }, _global: {} };

  /** @scenario scope_kind=modal, patch_branch=local
   * @effects modal_edit_preview_passes_host_context_as_parent */
  it('창 편집 모드에서 덧붙인 창 노드(편집 경로의 첫 인덱스)에는 루트 맥락을 넘긴다', () => {
    expect(modalEditParentContext('modal', { editIndexPath: [3] }, 3, root)).toBe(root);
  });

  /** @scenario scope_kind=modal, patch_branch=local
   * @effects modal_edit_preview_passes_host_context_as_parent */
  it('창 편집 모드라도 호스트 컴포넌트에는 넘기지 않는다', () => {
    expect(modalEditParentContext('modal', { editIndexPath: [3] }, 0, root)).toBeUndefined();
  });

  /** @scenario scope_kind=modal, patch_branch=local
   * @effects modal_edit_preview_passes_host_context_as_parent */
  it('창 편집이 아닌 모드에서는 넘기지 않는다', () => {
    expect(modalEditParentContext('route', { editIndexPath: [3] }, 3, root)).toBeUndefined();
    expect(modalEditParentContext('modal', undefined, 3, root)).toBeUndefined();
    expect(modalEditParentContext('modal', { editIndexPath: [] }, 0, root)).toBeUndefined();
  });
});
