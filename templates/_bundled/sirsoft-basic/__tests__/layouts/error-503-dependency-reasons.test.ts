/**
 * @file error-503-dependency-reasons.test.ts
 * @description 사용자 503 화면이 미충족 의존 항목마다 사유를 보인다 (코어 7.1.4, #152)
 *
 * 활성 템플릿이 요구하는 모듈·플러그인이 조건에 맞지 않으면 코어가 사용자 화면 전체를 503 으로 돌리고
 * `_global.errorData.modules/plugins` 에 항목별 `status`(not_installed·inactive·version_mismatch)와 설치·요구 버전을 싣는다.
 * 종전 화면은 「필요한 확장 기능이 활성화되지 않았습니다」로 단정해, 두 확장이 켜져 있지만 버전이 모자란 경우와 맞지 않았다.
 *
 * @scenario-manifest tests/scenarios/error-503-dependency-reasons.yaml
 */

import { describe, it, expect } from 'vitest';
import error503 from '../../layouts/errors/503.json';
import ko from '../../lang/partial/ko/error.json';
import en from '../../lang/partial/en/error.json';

type LayoutNode = Record<string, unknown>;

/** 레이아웃 JSON 안의 모든 노드를 모은다. */
function nodes(tree: unknown): LayoutNode[] {
  const out: LayoutNode[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) n.forEach(walk);
    else if (n && typeof n === 'object') {
      out.push(n as LayoutNode);
      Object.values(n).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

/** `if` 식이 정확히 같은 노드를 직렬화한다. */
function nodeWithIf(condition: string): string {
  const hit = nodes(error503).find((n) => n.if === condition);
  expect(hit, `조건 ${condition} 를 가진 노드가 있어야 합니다`).toBeDefined();
  return JSON.stringify(hit);
}

describe('sirsoft-basic 503 — 의존 사유', () => {
  /**
   * @scenario surface=reason_nodes
   * @effects error_503_shows_reason_per_item
   */
  it('모듈·플러그인 항목마다 사유 3종을 status 로 고른다', () => {
    for (const v of ['mod', 'plug']) {
      expect(nodeWithIf(`{{${v}.status === 'version_mismatch'}}`)).toContain(`$t:error.503.reason_version_mismatch|installed={{${v}.installed_version}}|required={{${v}.required_version}}`);
      expect(nodeWithIf(`{{${v}.status === 'inactive'}}`)).toContain('$t:error.503.reason_inactive');
      expect(nodeWithIf(`{{${v}.status === 'not_installed'}}`)).toContain('$t:error.503.reason_not_installed');
    }
  });

  /**
   * @scenario surface=lang_keys
   * @effects error_503_neutral_title
   */
  it('제목·안내가 중립 문구이고 사유 키가 ko·en 에 있다', () => {
    for (const lang of [ko, en] as Array<Record<string, any>>) {
      for (const key of ['reason_not_installed', 'reason_inactive', 'reason_version_mismatch', 'unmet_dependencies', 'contact_admin']) {
        expect(typeof lang['503'][key]).toBe('string');
      }
      expect(lang['503'].reason_version_mismatch).toContain('{{installed}}');
    }
    expect(ko['503'].unmet_dependencies).not.toContain('활성화되지 않았습니다');
    expect(ko['503'].contact_admin).toContain('업데이트');
    expect(en['503'].unmet_dependencies).not.toContain('not activated');
  });
});
