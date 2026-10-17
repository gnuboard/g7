/**
 * 바깥에서 온 주소로 가는 navigate 는 fallbackPath 를 적는다 (#757)
 *
 * 엔진은 실행 스킴·다른 출처·`//host` 주소로의 같은 탭 이동을 하지 않는다(결정). 그래서 `?redirect=`·서버가 준
 * 복귀 주소·알림 이동 주소를 그대로 넘기던 navigate 는 그 값이 거부되거나 비면 화면에 머물렀다
 * (실측: `/login?redirect=javascript:…` 로 로그인하면 로그인 화면 잔류). 레이아웃이 식으로 주소를 거르지 않고
 * 엔진 판정을 거치는 `fallbackPath` 로 그 화면의 정상 착지점을 적는다.
 *
 * 1) 전수: 쿼리·서버 값·이벤트 값·복귀 주소 상태가 앞자리를 정하는 navigate 는 비지 않은 fallbackPath 를 가진다.
 * 2) 자리별: 각 화면의 착지점이 정한 값이다(로그인 `/` 등).
 *
 * @scenario-manifest tests/scenarios/login-redirect-fallback.yaml
 */
import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const LAYOUT_ROOT = path.resolve(__dirname, '../../layouts');

type Action = { handler?: string; target?: unknown; params?: Record<string, unknown> };

/**
 * 굽힌 레이아웃 JSON 파일을 모두 모은다.
 *
 * @param dir 시작 폴더
 * @returns 파일 경로 목록
 */
function layoutFiles(dir: string = LAYOUT_ROOT): string[] {
  const out: string[] = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...layoutFiles(p));
    else if (e.name.endsWith('.json')) out.push(p);
  }

  return out;
}

/**
 * 트리 안의 navigate 액션을 모두 모은다.
 *
 * @param node 순회 대상
 * @param out 결과
 * @returns navigate 액션 목록
 */
function navigates(node: unknown, out: Action[] = []): Action[] {
  if (Array.isArray(node)) {
    node.forEach((v) => navigates(v, out));
  } else if (node && typeof node === 'object') {
    if ((node as Action).handler === 'navigate') out.push(node as Action);
    Object.values(node).forEach((v) => navigates(v, out));
  }

  return out;
}

/** 이동 주소 */
function targetOf(action: Action): string | null {
  const t = action.params?.path !== undefined ? action.params.path : action.target;

  return typeof t === 'string' ? t : null;
}

/**
 * 주소의 앞자리가 바깥 값인가 — `{{` 로 시작하고, 식의 첫 피연산자가 쿼리·서버 응답·이벤트 값·복귀 주소 상태다.
 * 식이 고정 경로 문자열(`'/x'`)이나 신뢰 값 뒤 고정 경로(`{{_global.shopBase}}/…`)로 시작하면 아니다.
 *
 * @param target 이동 주소
 * @returns 바깥 값이 앞자리를 정하면 true
 */
function headIsUntrusted(target: string): boolean {
  const m = /^\{\{\s*([^}]*)\}\}/.exec(target.trim());
  if (!m) return false;

  return /^\(*\s*(query|response|error|result|\$results|\$args)\s*(\?\.|\.|\[)/.test(m[1])
    || /^\(*\s*(_local|_global)\??\.[\w.?]*?(redirect|return_?url|returnTo|next_?url)/i.test(m[1]);
}

/** 바깥 주소 navigate 와 그 fallbackPath — 파일(레이아웃 루트 기준)별 */
function externalNavigates(): Array<{ file: string; target: string; fallbackPath: unknown }> {
  return layoutFiles().flatMap((file) => {
    const json = JSON.parse(fs.readFileSync(file, 'utf8'));
    const rel = path.relative(LAYOUT_ROOT, file).split(path.sep).join('/');

    return navigates(json)
      .filter((a) => {
        const t = targetOf(a);
        return t !== null && headIsUntrusted(t);
      })
      .map((a) => ({ file: rel, target: targetOf(a)!, fallbackPath: a.params?.fallbackPath }));
  });
}

describe('바깥 주소 navigate 의 fallbackPath', () => {
  // @scenario redirect=javascript_scheme
  // @effects external_navigate_declares_fallback_path
  it('쿼리·서버 값·이벤트 값으로 가는 navigate 는 모두 비지 않은 fallbackPath 를 가진다', () => {
    const missing = externalNavigates()
      .filter((n) => typeof n.fallbackPath !== 'string' || n.fallbackPath.trim() === '')
      .map((n) => `${n.file}: ${n.target.slice(0, 80)}`);

    expect(missing).toEqual([]);
  });

  // @scenario redirect=protocol_relative
  // @effects rejected_redirect_lands_on_fallback
  it('화면마다 정상 착지점이 fallbackPath 다', () => {
    const shopGuestOrders = "{{(_global.shopBase ?? '') + '/guest/orders'}}";
    const expected: Record<string, string[]> = {
      'partials/auth/_login_form.json': ['/', '/', '/', '/'],
      'auth/identity_challenge.json': ['/'],
      'partials/board/show/modals/_password_verify_modal.json': ['/board/{{route.slug}}'],
      'partials/shop/_checkout_summary.json': ['{{_global.shopBase}}/orders/{{response.data.order.order_number}}/complete'],
      'shop/guest_claim_apply.json': [shopGuestOrders, shopGuestOrders],
      'shop/guest_claim_show.json': [shopGuestOrders, shopGuestOrders],
      'shop/guest_order_show.json': [shopGuestOrders, shopGuestOrders],
      'shop/order_complete.json': ['/login', '/login'],
      '_user_base.json': ['/mypage/notifications', '/mypage/notifications'],
    };

    const actual: Record<string, string[]> = {};
    for (const n of externalNavigates()) {
      if (!(n.file in expected)) continue;
      (actual[n.file] ??= []).push(String(n.fallbackPath));
    }

    expect(actual).toEqual(expected);
  });
});
