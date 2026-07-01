/**
 * @file mypageCurrencySettings.test.tsx
 * @description 마이페이지 결제 통화 = 회원정보 통합저장 구조 회귀 테스트 (A3·A5 + D8·D9)
 *
 * 설계: 통화는 별도 저장버튼이 아니라 회원정보 수정 시 함께 저장한다.
 * - _view.json: 결제 통화는 읽기 전용 표시(다른 프로필 필드와 동일). 별도 select/저장버튼·
 *   user/currency PUT 액션이 없어야 한다(D8 405 + D9 별도버튼 결함 제거).
 * - _edit.json: 통화 selector(name=ecommerce_preferred_currency)가 폼 안에 있고,
 *   저장 submit 시퀀스가 /api/me 저장 후 user/currency 저장을 /api/ 접두사로 함께 호출한다.
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

    it('결제 통화 표시는 설치 게이트(ecommerce_preferred_currency)로 존재한다', () => {
      expect(card).toBeDefined();
      expect(card!.if).toContain('ecommerce_preferred_currency');
    });

    it('별도 통화 저장 액션(user/currency)이 없다 (D8/D9 제거)', () => {
      expect(viewText).not.toContain('modules/sirsoft-ecommerce/user/currency');
    });

    it('view 에 통화 저장 버튼 라벨(currency_settings.save_button)이 없다', () => {
      expect(viewText).not.toContain('currency_settings.save_button');
    });
  });

  describe('_edit.json — 폼 통합 저장', () => {
    it('통화 selector 가 회원정보 폼 안에 있다(name=ecommerce_preferred_currency)', () => {
      expect(editText).toContain('ecommerce_preferred_currency');
    });

    it('통화 옵션은 모듈 노출 글로벌(_global.modules ... language_currency.currencies)에서 읽는다', () => {
      expect(editText).toContain('language_currency');
      expect(editText).toContain('currencies');
    });

    it('저장 시퀀스가 user/currency 를 /api/ 접두사로 호출한다(D8 405 회귀 차단)', () => {
      expect(editText).toContain('/api/modules/sirsoft-ecommerce/user/currency');
      // 상대경로(접두사 누락) 패턴이 남아있지 않아야 함
      expect(editText).not.toContain('"target": "modules/sirsoft-ecommerce/user/currency"');
    });
  });
});
