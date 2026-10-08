/**
 * @file admin-seo-sitemap-last-generated.test.ts
 * @description 환경설정 SEO 탭 「마지막 생성」 칸 표시 회귀 테스트 (7.1.0 재실측 2026-10-07)
 *
 * 결함: 「마지막 생성」 칸이 응답의 ISO 원문(「2026-10-07T01:02:49+00:00」 — UTC)을 그대로 보였다.
 * 같은 화면의 다른 시각처럼 datetime 파이프로 보는 사람 시간대의 읽을 수 있는 시각을 보이고,
 * 값이 없으면 「-」 를 보인다. 파이프는 식 최상위에 둔다(괄호·삼항 안의 파이프는 엔진이 풀지 않는다).
 *
 * 테스트 대상: layouts/partials/admin_settings/_tab_seo.json
 */

import { describe, it, expect } from 'vitest';

type Node = Record<string, any>;

/** 레이아웃 JSON 전체에서 조건에 맞는 객체를 모두 모은다. */
function collect(node: unknown, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, pred, out));
  } else if (node && typeof node === 'object') {
    if (pred(node as Node)) out.push(node as Node);
    Object.values(node as Node).forEach((child) => collect(child, pred, out));
  }

  return out;
}

describe('SEO 탭 — Sitemap 마지막 생성 시각', () => {
  const tab = require('../../layouts/partials/admin_settings/_tab_seo.json');

  /**
   * @scenario tab=seo
   * @effects seo_sitemap_last_generated_is_readable
   */
  it('마지막 생성 칸은 datetime 파이프로 표시하고 값이 없으면 - 를 보인다', () => {
    const inputs = collect(tab, (n) => typeof n.props?.value === 'string' && n.props.value.includes('last_updated_at'));
    expect(inputs).toHaveLength(1);
    const value: string = inputs[0].props.value;
    expect(value).toMatch(/\| datetime \| default\('-'\)\}\}$/);
    expect(value).not.toMatch(/\?\? '-'\}\}$/);
  });
});
