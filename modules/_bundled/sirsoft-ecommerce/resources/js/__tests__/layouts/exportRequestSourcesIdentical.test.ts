/**
 * @file exportRequestSourcesIdentical.test.ts
 * @description 내보내기 창의 데이터 소스·진입 초기화가 주문·상품·마일리지 세 목록 레이아웃에서 같은지 잠근다(#787)
 *
 * 창(`partials/admin_ecommerce_export/_modal_export_request`)은 세 목록이 함께 쓰지만, 창이 부르는 데이터 소스(카탈로그·예상 건수)는
 * 레이아웃 최상위에만 둘 수 있어 세 레이아웃에 같은 선언이 한 벌씩 있다. 한 곳만 고치면 그 목록의 창만 조용히 다르게 동작하므로
 * 세 사본이 같은지 본다. 굽기 산출(`.json`)을 비교한다 — 런타임이 읽는 계약이 그것이다.
 *
 * @scenario-manifest tests/scenarios/export.yaml
 */

import { describe, it, expect } from 'vitest';
import orderList from '../../../layouts/admin/admin_ecommerce_order_list.json';
import productList from '../../../layouts/admin/admin_ecommerce_product_list.json';
import mileageList from '../../../layouts/admin/admin_ecommerce_mileage_transaction_index.json';

/** 창이 부르는 데이터 소스 id */
const EXPORT_SOURCE_IDS = ['ecommerceExportCatalog', 'ecommerceExportEstimateSource'];

/** 세 목록이 함께 쓰는 요청 창 */
const SHARED_MODAL = 'partials/admin_ecommerce_export/_modal_export_request.json';

const LAYOUTS: Record<string, any> = {
  orders: orderList,
  products: productList,
  mileage: mileageList,
};

/**
 * 레이아웃에서 내보내기 창에 딸린 선언만 뽑는다.
 *
 * @param layout 굽기 산출 레이아웃
 * @returns 데이터 소스·진입 초기화·창 참조
 */
function exportDeclarations(layout: any) {
  const sources = (layout.data_sources ?? []).filter((source: any) => EXPORT_SOURCE_IDS.includes(source.id));
  const init = (layout.init_actions ?? []).filter((action: any) => JSON.stringify(action).includes('ecommerceExportTracking'));
  const modals = (layout.modals ?? []).filter((modal: any) => modal.partial === SHARED_MODAL);

  return { sources, init, modals };
}

describe('내보내기 창 선언 — 세 목록 사본 일치', () => {
  // @scenario type=orders
  // @effects export_request_sources_identical_across_lists
  it('세 목록 모두 창 데이터 소스 두 개·진입 초기화·공용 창을 갖는다', () => {
    for (const [name, layout] of Object.entries(LAYOUTS)) {
      const { sources, init, modals } = exportDeclarations(layout);
      expect(sources.map((source: any) => source.id).sort(), name).toEqual([...EXPORT_SOURCE_IDS].sort());
      expect(init, `${name}: 진행 표시 초기화가 없다`).toHaveLength(1);
      expect(modals, `${name}: 공용 내보내기 창이 없다`).toHaveLength(1);
    }
  });

  // @scenario type=orders
  // @effects export_request_sources_identical_across_lists
  it('세 목록의 창 데이터 소스·진입 초기화가 글자 그대로 같다', () => {
    const [baseName, baseLayout] = Object.entries(LAYOUTS)[0];
    const base = exportDeclarations(baseLayout);

    for (const [name, layout] of Object.entries(LAYOUTS).slice(1)) {
      const other = exportDeclarations(layout);
      expect(other.sources, `${name} 의 창 데이터 소스가 ${baseName} 와 다르다 — 세 레이아웃을 함께 고치세요`).toEqual(base.sources);
      expect(other.init, `${name} 의 진입 초기화가 ${baseName} 와 다르다`).toEqual(base.init);
    }
  });

  // @scenario type=orders
  // @effects export_request_sources_identical_across_lists
  it('한 사본만 바뀌면 비교가 그 차이를 잡는다(검사가 아무것도 재지 않는 상태를 막는다)', () => {
    const drifted = structuredClone(productList as any);
    const estimate = drifted.data_sources.find((source: any) => source.id === 'ecommerceExportEstimateSource');
    estimate.endpoint = `${estimate.endpoint}?drift=1`;

    expect(exportDeclarations(drifted).sources).not.toEqual(exportDeclarations(orderList).sources);
  });
});
