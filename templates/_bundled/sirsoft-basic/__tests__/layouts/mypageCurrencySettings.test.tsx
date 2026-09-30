/**
 * @file mypageCurrencySettings.test.tsx
 * @description 마이페이지 결제 통화 = 회원정보 통합저장 구조 회귀 테스트 (A3·A5 + D8·D9)
 *
 * 설계: 통화는 별도 저장버튼이 아니라 회원정보 수정 시 함께 저장한다.
 * - _view.json: 결제 통화는 읽기 전용 표시(다른 프로필 필드와 동일). 별도 select/저장버튼·
 *   user/currency PUT 액션이 없어야 한다(D8 405 + D9 별도버튼 결함 제거).
 * - _edit.json: 통화 selector(name=ecommerce_preferred_currency)가 폼 안에 있고, 그 값은 회원정보 저장
 *   (PUT /api/me) 본문에 실려 이커머스가 코어 훅으로 함께 저장한다 — 별도 user/currency 호출이 없다
 *   (코어 저장 통합, 결정 2026-09-28). 검증 오류는 그 요청의 필드 오류로 칸 아래에 뜬다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');
const viewText = fs.readFileSync(
  path.resolve(baseDir, 'layouts/partials/mypage/profile/_view.json'),
  'utf8'
);
const view = JSON.parse(viewText);
const editText = fs.readFileSync(
  path.resolve(baseDir, 'layouts/partials/mypage/profile/_edit.json'),
  'utf8'
);

interface Node {
  comment?: string;
  if?: string;
  children?: Node[];
  [k: string]: any;
}

function flatten(n: Node | undefined, acc: Node[] = []): Node[] {
  if (!n) return acc;
  acc.push(n);
  (n.children ?? []).forEach((c) => flatten(c, acc));
  return acc;
}

describe('D9 — 마이페이지 결제 통화: 회원정보 통합저장', () => {
  describe('_view.json — 읽기 전용 표시(별도 저장버튼·PUT 제거)', () => {
    const card = flatten(view).find(
      (n) => typeof n.comment === 'string' && n.comment.includes('결제 통화')
    );

    /** @effects profile_currency_ui_renders_selector */
    it('결제 통화 표시는 설치 게이트(ecommerce_preferred_currency)로 존재한다', () => {
      expect(card).toBeDefined();
      expect(card!.if).toContain('ecommerce_preferred_currency');
    });

    /** @effects form_calls_only_core_save_api */
    it('별도 통화 저장 액션(user/currency)이 없다 (D8/D9 제거)', () => {
      expect(viewText).not.toContain('modules/sirsoft-ecommerce/user/currency');
    });

    /** @effects form_calls_only_core_save_api */
    it('view 에 통화 저장 버튼 라벨(currency_settings.save_button)이 없다', () => {
      expect(viewText).not.toContain('currency_settings.save_button');
    });
  });

  describe('_edit.json — 폼 통합 저장', () => {
    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects profile_edit_has_currency_field, form_field_rides_core_save_body
     */
    it('통화 selector 가 회원정보 폼 안에 있다(name=ecommerce_preferred_currency)', () => {
      expect(editText).toContain('ecommerce_preferred_currency');
    });

    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects field_options_follow_selectable_currencies
     */
    it('통화 옵션은 모듈 노출 글로벌(_global.modules ... language_currency.currencies)에서 읽는다', () => {
      expect(editText).toContain('language_currency');
      expect(editText).toContain('currencies');
    });

    /**
     * @scenario entry_point=profile_update, field=currency
     * @effects form_calls_only_core_save_api, field_error_shown_under_field, profile_save_syncs_global_preference
     */
    it('저장은 /api/me 한 번이고 통화 전용 호출이 없다 — 오류는 칸 아래, 성공 시 전역 선호 통화 갱신', () => {
      expect(editText).toContain('"target": "/api/me"');
      expect(editText).not.toContain('sirsoft-ecommerce/user/currency');
      expect(editText).toContain('_local.profileErrors?.ecommerce_preferred_currency');
      expect(editText).toContain('"preferredCurrency": "{{_local.profileEditForm.ecommerce_preferred_currency}}"');
    });
  });
});
