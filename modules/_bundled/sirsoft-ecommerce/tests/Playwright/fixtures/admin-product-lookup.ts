/**
 * 관리자 상품폼 E2E 공용 조회 헬퍼.
 *
 * 추가옵션 선택지(values)를 보유한 상품을 실행 시점에 찾아 그 **숫자 id** 를 돌려준다.
 * 상품 id 를 spec 에 상수로 박으면 실측 시드가 정리되는 순간 그 spec 이 통째로 죽는데,
 * 죽었다는 사실이 "대상 없음" 과 구분되지 않는다 (실제로 id 306 이 그렇게 사라졌다).
 */
import type { Page } from '@playwright/test';

const LIST_API = '/api/modules/sirsoft-ecommerce/admin/products?per_page=40';
const DETAIL_API = '/api/modules/sirsoft-ecommerce/admin/products';

/**
 * 추가옵션 선택지를 보유한 상품의 숫자 id 를 찾습니다.
 *
 * @param  page  인증이 적용된 Playwright 페이지 (auth_token 이 localStorage 에 있어야 한다)
 * @return 찾은 상품의 숫자 id (없으면 null)
 */
export async function findProductWithAdditionalOptionValues(page: Page): Promise<number | null> {
  return page.evaluate(
    async ({ listApi, detailApi }) => {
      const token = localStorage.getItem('auth_token') ?? '';
      const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };

      const listRes = await fetch(listApi, { headers });
      if (!listRes.ok) return null;
      const listJson = await listRes.json();
      const rows = listJson?.data?.data ?? listJson?.data ?? [];

      for (const row of rows) {
        const id = Number(row?.id);
        if (!Number.isFinite(id)) continue;

        const detailRes = await fetch(`${detailApi}/${id}`, { headers });
        if (!detailRes.ok) continue;
        const detail = (await detailRes.json())?.data;

        const groups = detail?.additional_options ?? [];
        const hasValues = groups.some((g: any) => (g?.values ?? []).length > 0);
        if (hasValues) return id;
      }

      return null;
    },
    { listApi: LIST_API, detailApi: DETAIL_API },
  );
}
