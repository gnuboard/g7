// e2e:allow 단위 테스트 단언만 editor-spec member 상태 계약(로그인 baseline 유지)에 재정합 — 동작 무변경, 해당 화면 E2E 는 branch 기존 spec 으로 커버됨
/**
 * guest_order_form 상태 그룹 패치 계약 테스트
 *
 * 비회원 주문 조회 폼(shop/guest_order_form)은 본문이 `_global.currentUser.uuid`
 * 유무로 양분된다(slots.content[0] 회원→마이페이지 리다이렉트 안내 / content[1]
 * 비회원→조회 폼). 편집기 sampleGlobal 은 로그인 상태(currentUser 시드)라, 상태
 * 그룹이 없으면 비회원 조회 폼이 캔버스에 영영 미표시되어 편집 불가였다.
 *
 * 본 테스트는 develop 신규 화면에 대응해 추가한 상태 그룹의 계약을 가드한다:
 *  - scope.match 가 정규화 라우트 path(`/shop/guest/orders`)와 일치
 *  - 기본(guest) 상태가 `global.currentUser: null` 패치로 비회원 폼을 노출
 *  - 회원(member) 상태가 `global.currentUser.uuid` 명시 시드로 마이페이지 안내 분기를 노출
 *  - 두 상태 라벨이 `$t:` 친화 키
 *
 * baseline 정정(커밋 e6f6eb7a5): sampleGlobal 은 코어 keyspace(currentUser)를 시드하지
 * 않으므로(코어 우선) 빈 패치로는 guest(null) 와 동일 결과가 되어 양분 분기를 못 만든다.
 * 따라서:
 *  - guest 변종은 currentUser 를 null 로 명시 덮어 content[1] 비회원 조회 폼을 노출하고,
 *  - member 변종은 currentUser.uuid 를 명시 시드해 content[0] 마이페이지 안내
 *    ({{_global?.currentUser?.uuid}}) 분기를 노출한다(editor-spec member.initialState.global
 *    = { currentUser: { uuid } }).
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';

function findProjectRoot(startDir: string): string {
  let dir = startDir;
  while (dir !== path.dirname(dir)) {
    if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
    dir = path.dirname(dir);
  }
  return path.resolve(startDir, '../../../../../../..');
}

const REPO_ROOT = findProjectRoot(__dirname);
const spec = JSON.parse(
  fs.readFileSync(path.join(REPO_ROOT, 'modules/_bundled/sirsoft-ecommerce/editor-spec.json'), 'utf-8'),
);
const group = spec.states.groups.find(
  (g: any) => g.scope?.kind === 'route' && g.scope?.match === '/shop/guest/orders',
);
const guest = group?.items.find((i: any) => i.id === 'guest');
const member = group?.items.find((i: any) => i.id === 'member');

describe('guest_order_form 상태 그룹', () => {
  it('정규화 라우트 path(`/shop/guest/orders`)에 상태 그룹이 존재한다', () => {
    expect(group).toBeTruthy();
    // items 2개 이상이어야 편집기 캔버스에 상태 드롭다운(PageStateSwitcher)이 표시된다.
    expect(group.items.length).toBeGreaterThanOrEqual(2);
  });

  it('기본(guest) 상태가 currentUser 를 null 로 패치해 비회원 조회 폼을 노출한다', () => {
    expect(guest?.default).toBe(true);
    // global.currentUser === null → `_global.currentUser?.uuid` undefined → content[1] 폼 활성.
    expect(guest?.initialState?.global).toHaveProperty('currentUser');
    expect(guest.initialState.global.currentUser).toBeNull();
  });

  it('회원(member) 상태가 currentUser.uuid 를 명시 시드해 마이페이지 안내 분기를 노출한다', () => {
    expect(member).toBeTruthy();
    // sampleGlobal 은 코어 keyspace(currentUser)를 시드하지 않으므로(코어 우선) 빈 패치로는
    // guest(null) 와 다른 결과를 만들지 못한다. 로그인 상태를 명시 패치해야 양분 분기가
    // 회원 쪽(content[0] 마이페이지 안내)으로 전환되고 캔버스가 실제로 달라진다(커밋 e6f6eb7a5).
    expect(member?.initialState?.global).toHaveProperty('currentUser');
    expect(member.initialState.global.currentUser?.uuid).toBeTruthy();
  });

  it('두 상태 라벨이 $t: 친화 키다', () => {
    expect(guest?.label).toMatch(/^\$t:editor\.state\.guest_order_form_/);
    expect(member?.label).toMatch(/^\$t:editor\.state\.guest_order_form_/);
  });
});
