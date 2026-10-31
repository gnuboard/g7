/**
 * @file mypage-order-history-milestones.test.tsx
 * @description 마이페이지 주문 상세 — 「주문 이력」 섹션의 생애주기 일시 행 회귀 테스트 (S2-D8)
 *
 * 테스트 대상:
 * - templates/.../layouts/partials/mypage/orders/_history.json
 * - templates/.../lang/partial/{ko,en}/mypage.json
 *
 * 배경 (#599 통합실측 세션2 · S2-D8):
 *   주문의 생애주기는 주문 → 결제 → 배송완료 → **구매확정** → (취소) 로 간다.
 *   그런데 「주문 이력」 섹션은 **구매확정일시만 빠뜨렸다** — 확정이 끝난 주문인데도
 *   이력에 「주문일시 · 결제일시」 두 줄만 섰다.
 *
 *   백엔드는 이미 내보내고 있었다: `OrderResource` 의 `confirmed_at_formatted`.
 *   DB 에도 값이 있었다(주문 107 · `confirmed_at=2026-09-15 07:52:28`).
 *   **화면 쪽 행과 라벨만 없었다.**
 *
 *   같은 확인에서 `delivered_at` 행이 참조하는 라벨 키
 *   (`mypage.order_detail.delivered_at`)도 ko·en 양쪽에 **없다**는 것이 드러났다 —
 *   그 행이 실제로 뜨는 주문에서는 원문 키가 그대로 노출됐을 것이다.
 *
 * 검증 항목:
 * - 이력 섹션이 4개 생애주기 일시(주문·결제·배송완료·구매확정)를 모두 그린다
 * - 각 행이 대응하는 백엔드 필드에 바인딩된다 (특히 구매확정은 `confirmed_at_formatted`)
 * - 조건부 행은 값이 있을 때만 뜬다(`if`) — 빈 줄이 남지 않는다
 * - 참조하는 라벨 키가 ko·en 양쪽에 실제로 존재한다 (원문 키 노출 방지)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');

function loadJson(relPath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(baseDir, relPath), 'utf8'));
}

function collectNodes(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (!node || typeof node !== 'object') return acc;
  if (predicate(node)) acc.push(node);
  const children = Array.isArray(node) ? node : node.children;
  if (Array.isArray(children)) {
    for (const child of children) collectNodes(child, predicate, acc);
  }
  if (node.children && !Array.isArray(node.children)) {
    collectNodes(node.children, predicate, acc);
  }
  return acc;
}

/** 이력 섹션에서 `$t:mypage.order_detail.<key>` 라벨을 쓰는 노드의 키 목록 */
function labelKeys(layout: any): string[] {
  return collectNodes(layout, (n) => typeof n.text === 'string' && n.text.startsWith('$t:mypage.order_detail.'))
    .map((n) => n.text.replace('$t:mypage.order_detail.', ''));
}

describe('마이페이지 주문 이력 — 생애주기 일시 행 (S2-D8)', () => {
  const layout = loadJson('layouts/partials/mypage/orders/_history.json');

  /**
   * 구매확정일시 행이 있어야 한다. **S2-D8 의 본체.**
   *
   * @scenario order_history_row=confirmed_at
   *
   * @effects order_history_shows_purchase_confirmed_at
   */
  it('구매확정일시 행이 confirmed_at_formatted 에 바인딩되어 존재한다', () => {
    const keys = labelKeys(layout);

    expect(
      keys,
      '주문 이력에 구매확정일시 라벨이 없습니다. 확정이 끝난 주문인데도 이력에 그 시각이 남지 않습니다. '
        + `현재 라벨: ${keys.join(', ')}`,
    ).toContain('confirmed_at');

    const bound = collectNodes(
      layout,
      (n) => typeof n.text === 'string' && n.text.includes('confirmed_at_formatted'),
    );

    expect(
      bound.length,
      'confirmed_at 라벨은 있는데 값이 confirmed_at_formatted 에 바인딩되지 않았습니다.',
    ).toBeGreaterThan(0);
  });

  /**
   * 생애주기 4종이 모두 그려져야 한다.
   *
   * 하나라도 빠지면 그 단계가 이력에서 사라진다 — S2-D8 이 정확히 그 형태였다.
   *
   * @scenario order_history_row=all_milestones
   *
   * @effects order_history_covers_every_lifecycle_timestamp
   */
  it('주문·결제·배송완료·구매확정 네 일시를 모두 그린다', () => {
    const keys = labelKeys(layout);

    for (const key of ['ordered_at', 'paid_at', 'delivered_at', 'confirmed_at']) {
      expect(keys, `주문 이력에 ${key} 행이 없습니다. 현재 라벨: ${keys.join(', ')}`).toContain(key);
    }
  });

  /**
   * 조건부 행은 값이 있을 때만 떠야 한다.
   *
   * 주문일시는 항상 있으므로 `if` 가 없고, 나머지 셋은 `if` 로 가려야
   * 값이 없는 주문에서 빈 줄이 남지 않는다.
   *
   * @scenario order_history_row=conditional_rendering
   *
   * @effects order_history_rows_are_hidden_until_the_timestamp_exists
   */
  it('결제·배송완료·구매확정 행은 값이 있을 때만 렌더된다', () => {
    // 「주문 이력 목록」 컨테이너의 직계 자식만 본다 — 취소 사유 카드 안의
    // "사유 라벨 + 취소일시" 같은 노드는 생애주기 행이 아니다.
    const listContainer = collectNodes(
      layout,
      (n) => n.comment === '주문 이력 목록',
    )[0];

    expect(listContainer, '「주문 이력 목록」 컨테이너를 찾지 못했습니다 — 전제가 깨졌습니다.').toBeTruthy();

    const rows = (listContainer.children ?? []).filter(
      (n: any) => typeof n.comment === 'string' && /일시/.test(n.comment),
    );

    // 주문 · 결제 · 배송완료 · 구매확정 · 취소 다섯 단계.
    expect(
      rows.map((r: any) => r.comment),
      '생애주기 일시 행이 5개(주문·결제·배송완료·구매확정·취소)여야 합니다.',
    ).toHaveLength(5);

    for (const row of rows) {
      if (/^주문일시/.test(row.comment)) continue; // 항상 존재
      expect(row.if, `"${row.comment}" 행에 if 조건이 없어 값이 없어도 빈 줄이 남습니다.`).toBeTruthy();
    }
  });

  /**
   * 참조하는 라벨 키가 ko·en 양쪽에 실제로 있어야 한다.
   *
   * 레이아웃이 `$t:` 로 부르는 키가 언어 파일에 없으면 **원문 키가 그대로 화면에 뜬다.**
   * S2-D8 확인 중 `delivered_at` 이 정확히 그 상태였다(양쪽 로케일 모두 누락).
   *
   * @scenario order_history_row=label_keys_exist
   *
   * @effects order_history_labels_are_translated_in_every_locale
   */
  it('이력 섹션이 쓰는 라벨 키가 ko·en 양쪽에 존재한다', () => {
    const keys = labelKeys(layout);

    for (const locale of ['ko', 'en']) {
      const lang = loadJson(`lang/partial/${locale}/mypage.json`);
      const detail = lang.order_detail ?? {};

      for (const key of keys) {
        expect(
          detail[key],
          `[${locale}] mypage.order_detail.${key} 라벨이 없습니다 — 화면에 원문 키가 그대로 노출됩니다.`,
        ).toBeTruthy();
      }
    }
  });
});
