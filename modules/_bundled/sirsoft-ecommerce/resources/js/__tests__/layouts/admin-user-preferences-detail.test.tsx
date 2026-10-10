/**
 * 회원 상세의 이커머스 선호값(결제 통화 · 배송 국가) 주입 계약 (#599).
 *
 * 이 둘은 종전에 **수정 화면에만** 있었다. 그래서 「이 회원이 어느 통화로 결제하나」 를 보려면
 * 고치는 화면을 열어야 했고, 볼 생각만 있던 운영자가 저장 버튼 앞까지 갔다(요구사항 2026-09-21 —
 * 상세와 수정은 같은 항목을 같은 자리·같은 양식으로 갖는다).
 *
 * @vitest-environment jsdom
 */
// e2e:allow 2026-09-21 결제 통화·배송 국가 두 줄을 회원 상세에 읽기 전용으로 세운 변경이다.
// Playwright spec 대신 **작성자가 직접 브라우저로 실측**했다 — 관리자로 로그인해 회원 상세의
// 「쇼핑 데이터」 탭을 열고 결제 통화 `KRW` · 배송국가 `대한민국` 이 서는 것을 확인했다.
// spec 을 세우려면 이커머스 선호값이 채워진 회원 시드가 필요한데 기존 admin spec 의 시드에는
// 그 값이 없고, 시드 신설은 별도 과제다. 구조 회귀는 이 파일의 계약 3건이 막는다.
import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const extensions = path.resolve(__dirname, '../../../extensions');
const read = (file: string) => JSON.parse(fs.readFileSync(path.join(extensions, file), 'utf8'));

// 두 줄은 「쇼핑 데이터」 탭 조각 **안에** 있다 — 별도 확장으로 두면 그 카드를 앵커로
// 잡아야 하는데, 앵커 자체가 확장이 만든 노드라 주입 시점에 아직 없다(실측에서 확인).
const detailExt = read('admin-user-ecommerce-data-tab.json');
const formCurrency = read('admin-user-currency-field.json');
const formShipping = read('admin-user-shipping-country-field.json');

const flatten = (node: unknown, out: any[] = []): any[] => {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => flatten(n, out));
    return out;
  }
  out.push(node);
  Object.values(node as Record<string, unknown>).forEach((v) => flatten(v, out));
  return out;
};

describe('회원 상세 — 이커머스 선호값 (#599)', () => {
  // @scenario surface=admin, field_kind=record_only
  // @effects admin_user_detail_shows_ecommerce_preferences
  it('회원 상세에 붙고, 수정 화면의 칸과 같은 값을 읽는다', () => {
    expect(detailExt.target_layout ?? detailExt.targetLayout).toBe('admin_user_detail');

    const json = JSON.stringify(detailExt);

    // 값의 출처가 수정 화면의 칸과 같아야 한다 — 다른 경로를 읽으면 두 화면이 다른 값을 말한다.
    expect(JSON.stringify(formCurrency)).toContain('ecommerce_preferred_currency');
    expect(JSON.stringify(formShipping)).toContain('ecommerce_preferred_shipping_country');
    expect(json).toContain('user?.data?.ecommerce_preferred_currency');
    expect(json).toContain('user?.data?.ecommerce_preferred_shipping_country');

    // 라벨도 같은 키를 쓴다 — 같은 항목이 두 화면에서 다른 이름으로 불리면 안 된다.
    expect(json).toContain('sirsoft-ecommerce.admin.user_currency.title');
    expect(json).toContain('sirsoft-ecommerce.admin.user_shipping_country.title');
  });

  // @scenario surface=admin, field_kind=record_only
  // @effects admin_user_detail_shows_ecommerce_preferences
  it('상세에서는 고치지 않는다 — 입력칸도 저장 액션도 없다', () => {
    // 고치는 자리를 둘로 늘리면 저장 경로가 둘이 된다. 이 두 값은 회원정보 본문과 달리 각자의
    // PATCH 로 나가므로(수정 화면 저장 onSuccess 체인), 상세가 그 체인을 한 벌 더 들게 된다.
    const nodes = flatten(detailExt);

    const inside = flatten(nodes.find((n) => n?.id === 'ext_user_preferences_detail'));

    expect(inside.filter((n) => n?.name === 'Select' || n?.name === 'Input')).toEqual([]);
    expect(JSON.stringify(inside)).not.toContain('apiCall');
  });

  // @scenario surface=admin, field_kind=record_only
  // @effects admin_user_detail_shows_ecommerce_preferences
  it('이커머스가 값을 싣지 않으면 통째로 서지 않는다', () => {
    // 코어만 쓰는 설치본에서는 `ecommerce_preferred_*` 가 응답에 없다 — 그때 빈 칸 둘이
    // 서면 「설정이 비었다」 로 읽혀, 없는 기능을 운영자가 찾게 된다.
    const block = flatten(detailExt).find((n) => n?.id === 'ext_user_preferences_detail');

    expect(block).toBeTruthy();
    expect(block.if).toContain('ecommerce_preferred_currency !== undefined');
    expect(block.if).toContain('ecommerce_preferred_shipping_country !== undefined');
  });
});
