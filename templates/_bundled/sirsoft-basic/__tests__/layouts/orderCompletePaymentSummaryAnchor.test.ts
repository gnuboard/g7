/**
 * @file orderCompletePaymentSummaryAnchor.test.ts
 * @description 주문 완료 화면 결제 금액 요약의 확장 앵커 검증 (#639).
 *
 * 결제 플러그인(KG이니시스·NHN KCP·나이스페이먼츠)의 주문 완료 조각은 target_id
 * "order_complete_payment_summary" 에 붙는다. 이 화면에 그 id 가 없어서 NHN KCP·나이스페이먼츠
 * 조각은 한 번도 그려지지 않았고, 주소를 글자로 맞추던 주입 스크립트를 지운 뒤로는 영수증 표시가
 * 통째로 사라졌다. 오류도 경고도 남지 않으므로 id 를 여기서 잠근다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import orderCompleteJson from '../../layouts/shop/order_complete.json';
import routesJson from '../../routes.json';

const ANCHOR = 'order_complete_payment_summary';

/**
 * 레이아웃 트리에서 id 가 일치하는 노드를 찾는다.
 *
 * @param node 탐색 시작 노드(객체·배열 모두 허용)
 * @param id 찾을 노드 id
 * @returns 찾은 노드, 없으면 null
 */
function findNodeById(node: unknown, id: string): any {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findNodeById(child, id);
      if (hit) return hit;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    if ((node as any).id === id) return node;
    for (const value of Object.values(node as Record<string, unknown>)) {
      const hit = findNodeById(value, id);
      if (hit) return hit;
    }
  }
  return null;
}

/**
 * 이 앵커를 겨냥하는 번들 플러그인 확장 JSON 을 저장소에서 모은다.
 *
 * 손으로 적으면 결제 플러그인이 늘어도 가드가 그대로 통과하므로 파일 내용으로 파생한다.
 *
 * @returns 확장 JSON 절대 경로 목록
 */
function anchorExtensionFiles(): string[] {
  const pluginsDir = path.resolve(__dirname, '../../../../../plugins/_bundled');
  const found: string[] = [];

  for (const plugin of fs.readdirSync(pluginsDir)) {
    const extDir = path.join(pluginsDir, plugin, 'resources/extensions');
    if (!fs.existsSync(extDir)) continue;

    for (const file of fs.readdirSync(extDir)) {
      if (!file.endsWith('.json')) continue;
      const full = path.join(extDir, file);
      if (fs.readFileSync(full, 'utf-8').includes(ANCHOR)) found.push(full);
    }
  }

  return found;
}

describe('주문 완료 결제 금액 요약 — 확장 앵커', () => {
  // @scenario surface=template_anchor
  // @effects payment_summary_anchor_present
  it('결제 금액 요약 칸에 플러그인이 기대하는 id 가 있어야 한다', () => {
    const node = findNodeById(orderCompleteJson, ANCHOR);

    expect(node).not.toBeNull();
    expect(node.name).toBe('Div');
  });

  // @scenario surface=plugin_fragments
  // @effects plugin_fragments_append_to_anchor
  it('이 앵커를 겨냥하는 플러그인 조각은 모두 shop/order_complete 에 append_child 로 붙는다', () => {
    const files = anchorExtensionFiles();

    // KG이니시스·NHN KCP·나이스페이먼츠 세 곳이 이 자리를 쓴다
    expect(files.length).toBeGreaterThanOrEqual(3);

    for (const file of files) {
      const ext = JSON.parse(fs.readFileSync(file, 'utf-8'));
      const injections = (ext.injections ?? []).filter((i: any) => i.target_id === ANCHOR);

      expect(ext.target_layout, file).toBe('shop/order_complete');
      expect(injections.length, file).toBeGreaterThan(0);
      for (const injection of injections) {
        expect(injection.position, file).toBe('append_child');
      }
    }
  });

  // @scenario surface=route_path
  // @effects plugin_fragments_render_under_any_shop_path
  it('쇼핑몰 경로를 바꿔도(shop → store) 주문 완료 라우트는 같은 레이아웃을 써서 결제 조각이 그대로 붙는다', () => {
    const route = ((routesJson as any).routes ?? routesJson).find((r: any) => r.layout === 'shop/order_complete');
    expect(route, '주문 완료 라우트').toBeTruthy();

    const resolvePath = (routePath: string): string =>
      String(route.path).replace(/\{\{([\s\S]+?)\}\}/g, (_m: string, expr: string) =>
        // 라우트 식은 _global 만 읽는다 — 테스트에서 쇼핑몰 설정만 바꿔 평가한다.
        // eslint-disable-next-line no-new-func
        String(new Function('_global', `return (${expr});`)({ modules: { 'sirsoft-ecommerce': { basic_info: { route_path: routePath } } } })),
      );

    expect(resolvePath('shop')).toBe('/shop/orders/:id/complete');
    expect(resolvePath('store')).toBe('/store/orders/:id/complete');

    // 조각은 주소가 아니라 레이아웃 이름으로 붙는다 — 두 경로 모두 같은 레이아웃이므로 같은 조각이 붙는다.
    for (const file of anchorExtensionFiles()) {
      expect(JSON.parse(fs.readFileSync(file, 'utf-8')).target_layout, file).toBe(route.layout);
    }
  });
});
