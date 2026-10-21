/**
 * @file empty-object-state-reset.test.ts
 * @description 빈 객체로 비우는 화면 상태는 null 로 먼저 지운다 (7.1.1)
 *
 * setState 기본 병합(deep)은 중첩 객체를 보존한다 — `{}` 를 넣어도 이전 값의 키가 남는다. 상품평 옵션 필터
 * 「블랙」 이 다른 상품으로 SPA 이동한 뒤에도, 같은 상품의 「필터 초기화」 뒤에도 남았다(실측: 초기화 뒤 요청
 * `option_filters={"색상":"블랙"}`). 비우는 setState 앞에 같은 액션 목록에서 그 키를 null 로 쓰는 setState 가
 * 있어야 한다.
 *
 * 모집단: layouts/**, extensions/** 굽기 산출 JSON 전부의 setState(merge 생략·deep) 중 값이 빈 객체인 키.
 *
 * @scenario-manifest tests/scenarios/empty-object-state-reset.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const EXT_ROOT = path.resolve(__dirname, '../..');

type Action = { handler?: string; params?: Record<string, unknown> };

const walk = (dir: string, out: string[] = []): string[] => {
  if (!fs.existsSync(dir)) return out;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (p.endsWith('.json')) out.push(p);
  }

  return out;
};

const isEmptyObject = (v: unknown): boolean =>
  !!v && typeof v === 'object' && !Array.isArray(v) && Object.keys(v as object).length === 0;

const isDeepSetState = (a: unknown): a is Action => {
  const x = a as Action;

  return !!x && x.handler === 'setState' && !!x.params && (x.params.merge === undefined || x.params.merge === 'deep');
};

interface Site { file: string; key: string; target: string; nulledBefore: boolean }

/** 빈 객체로 비우는 자리와 그 앞의 null 단계 유무를 모은다. */
function collect(): Site[] {
  const sites: Site[] = [];
  for (const file of [...walk(path.join(EXT_ROOT, 'layouts')), ...walk(path.join(EXT_ROOT, 'extensions'))]) {
    const rel = path.relative(EXT_ROOT, file).split(path.sep).join('/');
    (function visit(o: unknown): void {
      if (Array.isArray(o)) {
        o.forEach((a, i) => {
          if (isDeepSetState(a)) {
            for (const [key, value] of Object.entries(a.params!)) {
              if (!isEmptyObject(value)) continue;
              const nulledBefore = o.slice(0, i).some((b) => isDeepSetState(b) && b.params![key] === null
                && b.params!.target === a.params!.target);
              sites.push({ file: rel, key, target: String(a.params!.target ?? ''), nulledBefore });
            }
          }
          visit(a);
        });
      } else if (o && typeof o === 'object') {
        Object.values(o).forEach(visit);
      }
    })(JSON.parse(fs.readFileSync(file, 'utf8')));
  }

  return sites;
}

describe('빈 객체로 비우는 setState 는 null 로 먼저 지운다 (7.1.1)', () => {
  const sites = collect();

  // 모집단은 세 문맥을 모두 품는다 — init(show·register 의 init_actions) · 이벤트 처리기(_tab_reviews 필터 초기화·
  // _purchase_card 담기 성공·_cart_item 옵션 변경) · 부모 문맥(_modal_cart_added 의 `$parent._local`). 마커 파서는 주석 블록
  // 하나에서 시나리오 조합을 하나만 읽으므로 문맥마다 블록을 둔다.
  /**
   * @scenario context=init_actions
   * @effects empty_object_reset_preceded_by_null
   */
  /**
   * @scenario context=event_handler
   * @effects empty_object_reset_preceded_by_null
   */
  /**
   * @scenario context=parent_local
   * @effects empty_object_reset_preceded_by_null
   */
  it('모든 자리에서 같은 대상·같은 키의 null setState 가 먼저 온다', () => {
    expect(sites.filter((s) => !s.nulledBefore).map((s) => `${s.file} ${s.target}.${s.key}`)).toEqual([]);
  });

  /**
   * 모집단이 비면 판정이 공허하게 통과한다 — 수정 시점의 자리(6파일 10곳)를 하한으로 둔다.
   * 하한 목록이 세 문맥의 자리를 하나 이상씩 고정한다(show·register = init, _tab_reviews·_purchase_card·_cart_item = 이벤트,
   * _modal_cart_added = `$parent._local`).
   *
   * @scenario context=init_actions
   * @effects empty_object_reset_preceded_by_null
   */
  /**
   * @scenario context=event_handler
   * @effects empty_object_reset_preceded_by_null
   */
  /**
   * @scenario context=parent_local
   * @effects empty_object_reset_preceded_by_null
   */
  it('상품 상세 첫 진입·상품평 필터 초기화·담기 뒤·담기 완료 창·장바구니 옵션 변경·회원가입이 모집단에 든다', () => {
    const keys = sites.map((s) => `${s.file}:${s.key}`);
    expect(keys).toEqual(expect.arrayContaining([
      'layouts/shop/show.json:reviewOptionFilters',
      'layouts/shop/show.json:currentSelection',
      'layouts/shop/show.json:additionalOptions',
      'layouts/partials/shop/detail/_tab_reviews.json:reviewOptionFilters',
      'layouts/partials/shop/detail/_purchase_card.json:currentSelection',
      'layouts/partials/shop/detail/_modal_cart_added.json:currentSelection',
      'layouts/partials/shop/_cart_item.json:selectedOptions',
      'layouts/auth/register.json:registerForm',
    ]));
    expect(sites.length).toBeGreaterThanOrEqual(10);
  });
});
