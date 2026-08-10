/**
 * E2E: 배송지 목록의 해외 주소 표시 (#492 트랙 A V13/V14 실측 회귀)
 *
 * 배송지 목록은 국내와 해외를 서로 다른 형식으로 찍어야 한다. 국내는 우편번호 + 도로명,
 * 해외는 서버가 조립한 `full_address` 다. 국내 전용 형식만 두면 해외 주소 행이 우편번호
 * 괄호만 남고 본문이 통째로 비어 보인다.
 *
 * 실측: 체크아웃 "배송지 관리" 모달에서 미국 주소가 `John Doe|010-... ()` 로 표시됐다.
 * 같은 데이터가 마이페이지 배송지 목록에서는 정상이었다 — 두 레이아웃 중 한쪽만
 * 국가 분기를 갖고 있었기 때문이다.
 *
 * 이 spec 은 데이터 준비 비용이 큰 결제 플로우 대신 **레이아웃 선언**을 검사한다.
 * 배송지 행을 렌더하는 레이아웃이라면 국가 분기(국내/해외)를 모두 갖고 있어야 한다.
 *
 * @scenario case=address_list_renders_overseas
 *
 * @effects overseas_address_uses_full_address, domestic_address_uses_zipcode_format
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect } from '@playwright/test';

/** 배송지 행을 렌더하는 레이아웃 — 국내/해외 분기를 모두 가져야 한다 */
const ADDRESS_LIST_LAYOUTS = [
  'templates/_bundled/sirsoft-basic/layouts/partials/mypage/addresses/_list.json',
  'templates/_bundled/sirsoft-basic/layouts/partials/shop/_modal_address_manage.json',
] as const;

test.describe('배송지 목록 레이아웃의 국내/해외 분기', () => {
  for (const relative of ADDRESS_LIST_LAYOUTS) {
    test(`${relative.split('/').pop()}: 해외 주소를 full_address 로 렌더한다`, () => {
      const path = resolve(process.cwd(), relative);
      expect(existsSync(path), `${relative} 가 존재해야 한다`).toBe(true);

      const source = readFileSync(path, 'utf-8');
      JSON.parse(source); // 구문 검증

      // 국내 분기: country_code 가 KR 인 경우 우편번호 형식
      expect(
        source.includes("(addr.country_code ?? 'KR') === 'KR'") ||
          source.includes("(address.country_code ?? 'KR') === 'KR'"),
        '국내 주소 분기(country_code === KR)가 있어야 한다'
      ).toBe(true);

      // 해외 분기: KR 이 아닌 경우 full_address
      expect(
        source.includes("(addr.country_code ?? 'KR') !== 'KR'") ||
          source.includes("(address.country_code ?? 'KR') !== 'KR'"),
        '해외 주소 분기(country_code !== KR)가 있어야 한다'
      ).toBe(true);

      expect(source, '해외 분기는 서버가 조립한 full_address 를 써야 한다').toContain('full_address');
    });
  }
});
