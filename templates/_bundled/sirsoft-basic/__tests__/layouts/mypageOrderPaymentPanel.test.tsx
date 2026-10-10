/**
 * @file mypageOrderPaymentPanel.test.tsx
 * @description 유저 주문상세 결제 패널의 확장 앵커 검증 (#454 S3).
 *
 * 두 가지를 고정한다:
 *  1. 루트 Div 의 id = "order_payment_info_panel"
 *     KG 플러그인(resources/extensions/user_order_show.json)이 이 id 를 target_id 로 지정하고
 *     mypageOrderShowInjector.ts 가 getElementById 로 찾는다. 그동안 이 노드에 id 가 없어
 *     주입이 조용히 no-op 이었다 — 본 이슈가 부수 복구한다(R1). id 를 지우면 KG 세금내역·
 *     영수증 링크·에스크로 확인 버튼이 한꺼번에 사라진다.
 *  2. 말단 extension_point 2종
 *     - "mypage_order_cash_receipt_slot" — 이커머스 모듈이 현금영수증 카드를 주입하는 자리.
 *     - "mypage_order_payment_provider_slot" — 결제 플러그인 4종이 가상계좌 입금 안내·영수증·
 *       부가세 내역을 주입하는 자리(2026-09-01 #599 S13 신설). 이 카드는 주문 상세만의 것이
 *       아니라 클레임 신청서 상세도 그대로 재사용하므로, 레이아웃 이름(target_layout)을
 *       겨냥하면 그 한 화면에서만 뜨고 나머지 화면은 카드는 나오는데 이 블록만 빠진 채
 *       열린다 — 오류도 경고도 남지 않는다. 슬롯은 partial 을 따라간다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import paymentPartialJson from '../../layouts/partials/mypage/orders/_payment.json';
import kgUserOrderShowJson from '../../../../../plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/user_order_show.json';

const PAYMENT_CARD_ANCHOR = 'order_payment_info_panel';
const PAYMENT_PROVIDER_SLOT = 'mypage_order_payment_provider_slot';

/**
 * 결제 정보 카드를 겨냥하는 확장 JSON 을 저장소에서 파생한다.
 *
 * 손으로 적으면 결제 플러그인이 하나 늘어도 가드가 통과한다. 옛 형태(`target_layout` +
 * `target_id: order_payment_info_panel`)와 새 형태(슬롯) 둘 다 걸리도록 **앵커 id 또는
 * 슬롯 이름이 등장하는 파일**을 모집단으로 잡는다 — 슬롯 이름만으로 모으면 "슬롯을 이미
 * 선언한 파일" 만 걸려 검사가 항등식이 되고, 옛 형태로 남은 플러그인이 조용히 빠진다.
 */
function paymentCardExtensionFiles(): string[] {
  const pluginsDir = path.resolve(__dirname, '../../../../../plugins/_bundled');
  const found: string[] = [];

  for (const plugin of fs.readdirSync(pluginsDir)) {
    const extDir = path.join(pluginsDir, plugin, 'resources/extensions');
    if (!fs.existsSync(extDir)) continue;

    for (const file of fs.readdirSync(extDir)) {
      if (!file.endsWith('.json')) continue;
      const full = path.join(extDir, file);
      const src = fs.readFileSync(full, 'utf-8');
      if (src.includes(PAYMENT_CARD_ANCHOR) || src.includes(PAYMENT_PROVIDER_SLOT)) {
        found.push(full);
      }
    }
  }

  return found;
}

function flatten(node: any, out: any[] = []): any[] {
  if (node == null || typeof node !== 'object') return out;
  out.push(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach((v) => flatten(v, out));
    else if (value && typeof value === 'object') flatten(value, out);
  }
  return out;
}

describe('유저 주문상세 결제 패널 — 확장 앵커', () => {
  it('루트 Div 에 KG 가 기대하는 id 가 부여되어야 한다 (R1 no-op 주입 복구)', () => {
    expect(paymentPartialJson.id).toBe('order_payment_info_panel');
  });

  it('KG 확장은 이 카드에 실제로 있는 슬롯을 가리켜야 한다', () => {
    // 2026-09-01 이후 이 확장은 레이아웃 이름이 아니라 슬롯을 가리킨다. 가리키는 이름이
    // 이 카드에 없으면 코어가 등록 자체를 하지 않아, 오류 없이 주입만 사라진다.
    const ext = kgUserOrderShowJson as any;

    expect(ext.target_layout).toBeUndefined();
    expect(ext.extension_point).toBe('mypage_order_payment_provider_slot');
    expect(ext.components.length).toBeGreaterThan(0);

    const slotNames = flatten(paymentPartialJson)
      .filter((n) => n.type === 'extension_point')
      .map((n) => n.name);

    expect(slotNames).toContain(ext.extension_point);
  });

  it('현금영수증 확장 슬롯이 패널 말단에 존재해야 한다', () => {
    const slots = flatten(paymentPartialJson).filter((n) => n.type === 'extension_point');
    expect(slots.map((s) => s.name)).toContain('mypage_order_cash_receipt_slot');

    const slot = slots.find((s) => s.name === 'mypage_order_cash_receipt_slot');
    expect(slot.default).toEqual([]);
  });

  it('슬롯은 결제 정보 목록의 말단에 모여 있어야 한다 (결제수단 행 아래)', () => {
    // 재는 것은 "금액 줄이 확장 슬롯 뒤로 밀리지 않았는가" 다 — 밀리면 확장이 주입한 카드와
    // 금액 줄의 순서가 뒤집혀 결제 정보가 두 덩이로 갈라진다. 슬롯이 둘로 늘었으므로
    // "마지막 하나" 가 아니라 "말단 연속 구간" 을 잰다.
    const infoList = (paymentPartialJson.children as any[]).find(
      (c) => (c.props?.className ?? '').includes('space-y-3')
    );
    const children = infoList.children as any[];

    const trailing: string[] = [];
    for (let i = children.length - 1; i >= 0; i--) {
      if (children[i].type !== 'extension_point') break;
      trailing.unshift(children[i].name);
    }

    expect(trailing).toEqual([
      'mypage_order_cash_receipt_slot',
      'mypage_order_payment_provider_slot',
    ]);

    // 확장 슬롯은 말단에만 있다 — 중간에 끼면 금액 줄 사이가 갈라진다.
    const slotCount = children.filter((c: any) => c.type === 'extension_point').length;
    expect(slotCount).toBe(trailing.length);
  });

  it('결제 정보 카드를 겨냥하는 확장은 전부 같은 슬롯을 가리킨다 (한 곳만 남으면 그 PG 만 뜬다)', () => {
    const files = paymentCardExtensionFiles();

    // 모집단이 비면 red — 파일이 옮겨졌는데 초록으로 통과하는 것을 막는다.
    expect(files.length).toBeGreaterThan(0);

    for (const file of files) {
      const ext = JSON.parse(fs.readFileSync(file, 'utf-8'));

      expect(ext.target_layout, `${file} 이 슬롯 대신 레이아웃 이름을 겨냥한다`).toBeUndefined();
      expect(ext.extension_point, `${file}`).toBe(PAYMENT_PROVIDER_SLOT);
    }
  });

  it('템플릿은 현금영수증 도메인 필드를 직접 그리지 않는다 (모듈 소유)', () => {
    // 슬롯 이름/주석에는 'cash_receipt' 가 들어간다(정상). 금지 대상은 도메인 값 바인딩이다.
    const bindings = flatten(paymentPartialJson)
      .filter((n) => n.type !== 'extension_point')
      .flatMap((n) => [n.text, ...Object.values(n.props ?? {})])
      .filter((v): v is string => typeof v === 'string');

    for (const b of bindings) {
      expect(b, `템플릿이 현금영수증 필드를 직접 바인딩 → ${b}`).not.toContain('cash_receipt');
    }
  });

  it('id 부여가 기존 responsive.portable 규약을 깨지 않는다', () => {
    expect(Object.keys(paymentPartialJson.responsive)).toEqual(['portable']);
  });
});
