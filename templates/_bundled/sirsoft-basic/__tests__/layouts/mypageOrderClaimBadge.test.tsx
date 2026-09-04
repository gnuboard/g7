/**
 * @file mypageOrderClaimBadge.test.tsx
 * @description 마이페이지 주문내역 클레임 배지(D22) 구조 회귀 테스트
 *
 * 배경:
 * - 주문 목록 조회는 관리자와 구매자가 같은 저장소 메서드를 쓴다. 그 메서드가 클레임 관계·집계를
 *   항상 싣게 된 뒤로(#599 S10 6차 확정) 구매자 응답에도 값은 이미 손에 있었는데, 리소스가
 *   직렬화를 하지 않아 불러온 것을 그대로 버리고 있었다 — 구매자는 자기 주문에 접수된 교환·반품이
 *   어디까지 갔는지 목록에서 볼 수 없었다. 7차 확정은 "고객 화면에도 표시한다" 다.
 * - 배지 라벨은 서버가 이미 로케일에 맞춰 내려준다(`status_label`). 화면이 유형·상태를 다시
 *   조합하면 서버 Enum 이 늘 때 화면만 뒤처진다.
 *
 * 회귀 차단:
 * - 배지는 `order.claim_summary` 를 순회하고 fallback `?? []` 를 갖는다(응답 도착 전 undefined 방지).
 * - 라벨은 서버가 내려준 `status_label` 을 그대로 쓴다(클라이언트 조합 금지).
 * - 건수는 2건 이상일 때만 덧붙는다 — 배지는 최신 1건의 상태라 건수가 없으면 나머지가 없는 것처럼 읽힌다.
 * - 상태 색상은 라이트/다크 쌍을 모두 갖는다.
 *
 * 마킹 주의: 추출기는 한 docblock 에서 `@scenario` 를 **한 번만** 읽는다. 두 줄로 적으면
 * 두 번째 조합이 조용히 사라지고 그 조합의 effect 까지 첫 조합에 귀속된다. 이 파일이 실제로
 * 보는 것은 레이아웃 JSON 이므로 `with_claim` 조합만 주장하고, 서버 직렬화 축
 * (`mypage_order_without_claim_serializes_empty_array`)은 그것을 실제로 단언하는
 * `UserOrderControllerTest` 가 소유한다 — 여기서 함께 주장하면 지문만 있고 단언은 없는 조합이 된다.
 *
 * @scenario claim_presence=with_claim
 *
 * @effects mypage_order_card_renders_claim_badge, mypage_order_claim_badge_uses_server_status_label, mypage_order_claim_badge_shows_count_only_when_multiple
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const listPath = path.resolve(__dirname, '../../layouts/partials/mypage/orders/_list.json');

interface Node {
  type?: string;
  name?: string;
  if?: string;
  comment?: string;
  props?: Record<string, any>;
  classMap?: Record<string, any>;
  iteration?: { source?: string; item_var?: string };
  text?: string;
  children?: Node[];
}

function load(p: string): Node {
  return JSON.parse(fs.readFileSync(p, 'utf8'));
}

function flatten(node: Node | undefined, acc: Node[] = []): Node[] {
  if (!node) return acc;
  acc.push(node);
  if (Array.isArray(node.children)) {
    for (const c of node.children) flatten(c, acc);
  }
  return acc;
}

describe('_list.json — 클레임 배지 (D22)', () => {
  const list = load(listPath);
  const nodes = flatten(list);

  const block = nodes.find((n) => n.iteration?.item_var === 'claim');

  it('클레임 요약을 순회하는 블록이 존재한다', () => {
    expect(block).toBeTruthy();
  });

  it('래퍼는 클레임이 없는 주문에서 그려지지 않는다', () => {
    // 래퍼에 `if` 가 없으면 `[]` 에서도 빈 flex 아이템이 남아 상태 배지와 배송국가 칩 사이 gap 이
    // 한 칸 더 벌어진다 — 관리자 정본은 `length > 0` 으로 묶는다.
    const wrapper = nodes.find((n) => Array.isArray(n.children) && n.children.includes(block as Node));
    expect(wrapper?.if).toBe('{{(order.claim_summary ?? []).length > 0}}');
  });

  it('순회 대상은 order.claim_summary 이고 fallback 을 갖는다', () => {
    // fallback 이 없으면 응답 도착 전 undefined 를 순회해 렌더가 throw 한다.
    expect(block?.iteration?.source).toBe('{{order.claim_summary ?? []}}');
    // 반복은 배지(Span) 자신에 걸린다 — 래퍼 Div 에 걸면 래퍼가 유형 수만큼 복제돼 배지 사이 gap 이 죽는다.
    expect(block?.name).toBe('Span');
  });

  it('라벨은 서버가 내려준 status_label 을 그대로 쓴다 (화면이 유형·상태를 조합하지 않는다)', () => {
    const inner = flatten(block);
    const label = inner.find((n) => typeof n.text === 'string' && n.text.includes('claim.status_label'));

    expect(label).toBeTruthy();
    // 서버 Enum 이 늘어도 화면이 따라갈 필요가 없도록 조합식을 두지 않는다.
    expect(label?.text).toBe('{{claim.status_label ?? claim.type_label ?? \'\'}}');
  });

  it('건수는 2건 이상일 때만 덧붙는다', () => {
    const inner = flatten(block);
    // 관리자 목록 배지와 같은 표기 `(N)` — `SummarizesClaims` 가 "관리자·구매자가 같은 배지 형태" 를 계약으로 둔다.
    const count = inner.find((n) => n.text === "{{'(' + claim.count + ')'}}");

    expect(count).toBeTruthy();
    // 1건일 때도 숫자가 붙으면 배지가 "교환회수중 1" 로 읽혀 군더더기가 된다.
    expect(count?.if).toBe('{{(claim.count ?? 0) > 1}}');
  });

  it('상태 색상은 라이트/다크 쌍을 모두 갖는다', () => {
    const inner = flatten(block);
    const badge = inner.find((n) => n.classMap?.key === '{{claim.status_variant}}');

    expect(badge).toBeTruthy();

    const variants: string[] = [
      ...Object.values(badge?.classMap?.variants ?? {}),
      badge?.classMap?.default,
    ].filter(Boolean) as string[];

    // `> 0` 이면 default 하나만 남아도 통과한다 — 어휘 7종 + default 정확값.
    //
    // 이 `8` 은 **사본**이다. 단일 출처 대조(Enum ⊆ 사전)는 `ClaimStatusVariantVocabularyTest`
    // 가 Enum 파생으로 맡고, 여기는 그 반대 방향(사전에 사문 키가 남지 않는가)을 개수로
    // 근사한다. 둘을 합쳐야 집합 동일성이 되므로 한쪽만 보고 판단하지 않는다 —
    // `variant()` 에 값이 늘거나 줄면 이 수를 함께 고치고, 그때 위 PHP 검사도 함께 돌린다.
    expect(variants.length).toBe(8);
    // 미지 값 폴백은 관리자 `StatusBadge.default`(회색)와 같은 색 — 보라로 갈리면 `variant()` 에 값이
    // 하나 늘 때 같은 주문이 두 화면에서 다른 색으로 보인다.
    expect(badge?.classMap?.default).toBe('bg-gray-50 text-gray-600 dark:bg-gray-700 dark:text-gray-400');

    for (const v of variants) {
      expect(v, `다크 모드 짝이 없는 클래스: ${v}`).toMatch(/dark:/);
    }
  });
});
