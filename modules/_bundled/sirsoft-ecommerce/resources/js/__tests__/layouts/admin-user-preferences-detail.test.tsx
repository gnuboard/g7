/**
 * 회원 상세와 수정 화면의 이커머스 선호값(결제 통화, 배송국가)을 누가 그리는지에 대한 계약.
 *
 * 두 값은 회원 항목(core.user)으로 선언돼 있다. 그래서 관리자 템플릿의 공용 조각이 회원 상세와 수정 화면의
 * 「확장 항목」 구획에 같은 이름, 같은 값으로 그린다(상세와 수정은 같은 항목을 같은 양식으로 갖는다 — 요구사항 2026-09-21).
 * 이커머스가 따로 주입하던 조각(수정 화면의 탭과 칸 2개, 상세 「쇼핑 데이터」 탭 안의 두 줄)이 남아 있으면
 * 같은 값이 한 화면에 두 번 선다.
 *
 * @vitest-environment jsdom
 */
// e2e:allow 조각이 없음을 재는 정적 계약이다. 화면에서 두 값이 한 번만 서는지는 관리자 회원 상세와 수정 화면의 브라우저 실측 대상이다.
import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const extensions = path.resolve(__dirname, '../../../extensions');
const fragments = fs.readdirSync(extensions).filter((file) => file.endsWith('.json'));
const read = (file: string): any => JSON.parse(fs.readFileSync(path.join(extensions, file), 'utf8'));

describe('회원 상세와 수정 화면 — 이커머스 선호값은 회원 항목으로 그린다', () => {
  // @scenario surface=admin, field_kind=record_only
  // @effects admin_user_detail_shows_ecommerce_preferences
  it('회원 상세 「쇼핑 데이터」 탭 조각은 결제 통화와 배송국가를 그리지 않는다', () => {
    const detailExt = read('admin-user-ecommerce-data-tab.json');
    expect(detailExt.target_layout ?? detailExt.targetLayout).toBe('admin_user_detail');

    const json = JSON.stringify(detailExt);
    expect(json).not.toContain('ext_user_preferences');
    expect(json).not.toContain('ecommerce_preferred_currency');
    expect(json).not.toContain('ecommerce_preferred_shipping_country');
  });

  // @scenario surface=admin, field_kind=record_only
  // @effects admin_user_detail_shows_ecommerce_preferences
  it('회원 수정 화면에 탭과 칸을 주입하던 조각 2개가 없다', () => {
    for (const name of ['admin-user-currency-field', 'admin-user-shipping-country-field']) {
      expect(fragments).not.toContain(`${name}.json`);
      expect(fs.existsSync(path.join(extensions, `${name}.g7.html`))).toBe(false);
    }
  });

  // @scenario surface=admin, field_kind=record_only
  // @effects admin_user_detail_shows_ecommerce_preferences
  it('이커머스 조각 어느 것도 회원 폼과 상세에 두 값의 입력칸이나 표시 줄을 주입하지 않는다', () => {
    const offenders = fragments.filter((file) => {
      const fragment = read(file);
      const target = fragment.target_layout ?? fragment.targetLayout;
      if (target !== 'admin_user_form' && target !== 'admin_user_detail') {
        return false;
      }
      const json = JSON.stringify(fragment);

      return json.includes('ecommerce_preferred_currency') || json.includes('ecommerce_preferred_shipping_country');
    });

    expect(offenders).toEqual([]);
  });
});
