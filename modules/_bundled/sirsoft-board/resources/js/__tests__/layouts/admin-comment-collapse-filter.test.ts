/**
 * 관리자 게시글 상세 — 답글 접기 필터가 실제로 걸리는지 회귀 테스트 (53차)
 *
 * @description
 * 댓글 목록은 "루트 댓글은 항상 노출, 답글은 그 루트가 펼쳐졌을 때만 노출" 을 의도한다.
 * 그런데 그 조건식이 **반복 컨테이너 자신의 `if`** 에 붙어 있었다.
 *
 * 엔진은 `if` 를 `iteration` 보다 **먼저** 평가한다(`DynamicRenderer`: `shouldRender` 판정 뒤
 * `renderIteration` 분기). 그래서 조건식 안의 `comment` 는 항상 `undefined` 였고,
 * `(undefined ?? 0) === 0` 이 언제나 참이라 **필터가 한 번도 걸리지 않았다** — 접기 토글을
 * 아무리 눌러도 답글이 계속 보이는 상태.
 *
 * 이 결함은 audit 룰 `layout-grammar`(I4)가 잡을 수 있는 형태였는데, 그 룰이 쓰는 순회가
 * 조각(partial) 본문 root 아래로 내려가지 못해 **한 번도 이 노드를 본 적이 없었다**(53차 통합).
 *
 * 계약:
 *   - 반복(`iteration`)을 가진 노드는 `if` 를 갖지 않는다.
 *   - 조건은 반복 **자식**에 있어야 반복 변수(`comment`)가 바인딩된 뒤 평가된다.
 */

import { describe, it, expect } from 'vitest';
import commentsPartial from '../../../layouts/admin/partials/admin_board_post_detail/_comments.json';

/** 트리 전체에서 `iteration` 을 가진 노드를 모은다. */
function iterationNodes(node: any, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => iterationNodes(n, out));

    return out;
  }
  if (node.iteration && typeof node.iteration === 'object') out.push(node);
  Object.values(node).forEach((v) => iterationNodes(v, out));

  return out;
}

/** 노드 트리에서 id 접두어로 첫 노드를 찾는다. */
function findByIdPrefix(node: any, prefix: string): any {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const n of node) {
      const hit = findByIdPrefix(n, prefix);
      if (hit) return hit;
    }

    return null;
  }
  if (typeof node.id === 'string' && node.id.startsWith(prefix)) return node;
  for (const v of Object.values(node)) {
    const hit = findByIdPrefix(v, prefix);
    if (hit) return hit;
  }

  return null;
}

describe('관리자 댓글 목록 — 답글 접기 필터', () => {
  it('반복 컨테이너에는 if 가 없다 (if 는 iteration 보다 먼저 평가되어 반복 변수가 undefined)', () => {
    const nodes = iterationNodes(commentsPartial);

    // 모집단이 살아 있음을 먼저 못박는다 — 0건이면 아래 단언이 공허하게 통과한다.
    expect(nodes.length).toBeGreaterThan(0);

    const offenders = nodes
      .filter((n) => typeof n.if === 'string')
      .filter((n) => {
        const itemVar = n.iteration?.item_var;

        return typeof itemVar === 'string' && new RegExp(`(^|[^\\w$.])${itemVar}([^\\w$]|$)`).test(n.if);
      })
      .map((n) => `${n.id ?? '(id 없음)'}: ${n.if}`);

    expect(offenders).toEqual([]);
  });

  it('접기 조건은 반복 자식에 있고 루트 댓글/펼친 답글을 가른다', () => {
    const wrapper = findByIdPrefix(commentsPartial, 'comment_wrapper_');
    expect(wrapper).toBeTruthy();
    expect(wrapper.iteration?.item_var).toBe('comment');
    expect(wrapper.if).toBeUndefined();

    const visible = findByIdPrefix(wrapper, 'comment_visible_');
    expect(visible).toBeTruthy();
    // 조건 자체는 보존한다 — 옮긴 것이지 없앤 것이 아니다.
    expect(visible.if).toContain('comment');
    expect(visible.if).toContain('collapsedReplies');
    expect(visible.if).toContain('commentRootMap');
  });

  it('접기 토글이 실재한다 — 필터를 되살려도 사용자가 펼칠 수단이 있다', async () => {
    const comment = await import('../../../layouts/admin/partials/admin_board_post_detail/_comment.json');
    expect(JSON.stringify(comment)).toContain('collapsedReplies');
  });
});
