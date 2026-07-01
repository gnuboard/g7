import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { PermissionTree, PermissionNode } from '../PermissionTree';

/**
 * 편집기 선택 결함 회귀 가드.
 *
 * PermissionTree 는 권한 API 데이터로 구동되는 컴포넌트라 기본(빈 데이터) 상태에서
 * 0×0 비가시 노드로 렌더되어 편집기에서 선택 불가하던 문제를 정정했다.
 * 빈 데이터일 때도 시각적 placeholder + 루트 id/editorAttrs 를 부착해 선택 가능하게 한다.
 */
describe('PermissionTree —/id passthrough', () => {
  const editorAttrs = {
    'data-editor-name': 'PermissionTree',
    'data-editor-path': '1.children.0',
  } as Record<string, unknown>;

  const sampleNode: PermissionNode = {
    id: 1,
    identifier: 'core.users',
    name: '사용자 관리',
    is_assignable: true,
    leaf_count: 1,
    children: [],
  };

  it('빈 데이터 분기 루트에 id/editorAttrs 가 도달함', () => {
    const { container } = render(
      <PermissionTree data={[]} value={[]} id="perm-empty" editorAttrs={editorAttrs} />
    );
    const root = container.querySelector('[data-editor-name="PermissionTree"]');
    expect(root).toBeTruthy();
    expect(root).toHaveAttribute('id', 'perm-empty');
    expect(root).toHaveAttribute('data-editor-path', '1.children.0');
  });

  it('빈 데이터 분기가 가시적 placeholder 텍스트를 렌더함(0×0 비가시 방지)', () => {
    const { container } = render(<PermissionTree data={[]} value={[]} />);
    // 빈 컨테이너가 아니라 안내 문구를 가진 노드여야 함
    expect((container.textContent || '').trim().length).toBeGreaterThan(0);
  });

  it('데이터 있음 분기 루트에도 id/editorAttrs 가 도달함', () => {
    const { container } = render(
      <PermissionTree data={[sampleNode]} value={[]} id="perm-full" editorAttrs={editorAttrs} />
    );
    const root = container.querySelector('[data-editor-name="PermissionTree"]');
    expect(root).toBeTruthy();
    expect(root).toHaveAttribute('id', 'perm-full');
  });
});
