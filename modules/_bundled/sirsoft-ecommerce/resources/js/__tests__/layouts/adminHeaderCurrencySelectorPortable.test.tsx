/**
 * @file adminHeaderCurrencySelectorPortable.test.tsx
 * @description 관리자 헤더 통화/배송국가 주입 조각의 portable(모바일·태블릿) 칩 전환 회귀 테스트
 *
 * 배경: 관리자 모바일 헤더 우측 그룹이 420px 를 요구해 폰 뷰포트에서 통화 셀렉터가 잘렸다.
 * 템플릿이 통화 슬롯을 모바일 드로어(`admin_drawer_prefs`)로 옮겼고, 이 조각은 드로어 안에서
 * 접기/펼치기 아코디언(트리거 행 + static 가로 칩 목록)으로 렌더된다(사용자 조각
 * `header-currency-selector-user` 와 동일 사상).
 *
 * 접힘이 기본이며, 표시 여부는 데스크톱과 같은 `_local.showCurrencyDropdown` 이 결정한다 —
 * portable 오버라이드에 `if` 를 두지 않으면 엔진이 base `if` 를 상속하기 때문(`override.if ?? baseDef.if`).
 * 데스크톱(≥1024px)은 기존 absolute 드롭다운을 그대로 유지해야 한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const fragmentPath = path.resolve(
  __dirname,
  '../../../extensions/header-currency-selector-admin.json',
);

const fragment = JSON.parse(fs.readFileSync(fragmentPath, 'utf8'));
const root = fragment.injections[0].components[0];
const [trigger, panel, backdrop] = root.children;

describe('관리자 통화 주입 조각 — portable 칩 전환', () => {
  it('데스크톱 기본 경로는 absolute 드롭다운을 유지한다 (회귀 방지)', () => {
    expect(root.props.className).toContain('relative');
    expect(root.props.className).not.toContain('w-full');

    expect(panel.props.className).toContain('absolute');
    expect(panel.props.className).toContain('z-50');
    expect(panel.props.className).toContain('w-44');
    expect(panel.if).toBe('{{_local.showCurrencyDropdown}}');
  });

  it('portable 에서 루트가 w-full 이 되고 hidden 게이트 표현식이 보존된다', () => {
    const cls = root.responsive.portable.props.className;
    expect(cls).toContain('w-full');
    // props 는 얕은 머지가 아니라 className 통째 교체이므로 표현식을 복제해야 한다.
    expect(cls).toContain('availableCurrencies');
    expect(cls).toContain('international_shipping_enabled');
  });

  it('portable 트리거는 아코디언 행이 되고 접힘 상태 요약(통화·배송국가)을 노출한다', () => {
    expect(trigger.name).toBe('Button');

    const t = trigger.responsive.portable;
    // 더 이상 숨기지 않는다 — 드로어 폭을 채우는 트리거 행
    expect(t.props.className).not.toBe('hidden');
    expect(t.props.className).toContain('w-full');
    expect(t.props.className).toContain('justify-between');
    // 얕은 머지가 아니라 통째 교체이므로 aria-* 를 오버라이드에도 명시
    expect(t.props['aria-expanded']).toBe('{{_local.showCurrencyDropdown ?? false}}');

    // 접힘 상태에서 현재 값을 알 수 있어야 한다
    const summary = JSON.stringify(t.children);
    expect(summary).toContain('preferredCurrency');
    expect(summary).toContain('preferredShippingCountry');
    expect(summary).toContain('chevron-down');
    expect(summary).toContain("{{_local.showCurrencyDropdown ? 'rotate-180' : ''}}");
  });

  it('portable 패널도 _local.showCurrencyDropdown 으로 게이트된다 (기본 접힘)', () => {
    const p = panel.responsive.portable;
    // 핵심: `{{true}}` 로 강제하면 드로어에서 항상 펼쳐져 아코디언이 성립하지 않는다.
    // if 를 생략해야 base if 를 상속한다.
    expect(p.if).toBeUndefined();
    expect(p.props.className).toContain('static');
    expect(p.props.className).not.toContain('absolute');
    expect(p.props.className).not.toContain('z-50');
    // 얕은 머지라 role 을 재선언하지 않으면 유실된다.
    expect(p.props.role).toBe('listbox');
  });

  it('portable 백드롭은 렌더되지 않는다 (fixed inset-0 이 드로어 전체 클릭을 가로챈다)', () => {
    expect(backdrop.props.className).toContain('fixed');
    expect(backdrop.responsive.portable.if).toBe('{{false}}');
  });

  it('칩 래퍼는 정적이고 iteration 은 그 자식에 걸린다', () => {
    // 엔진은 iteration 을 가진 노드를 항목 수만큼 복제한다. flex 컨테이너에 걸면
    // 컨테이너가 복제되어 칩이 세로로 쌓인다.
    const wrappers = panel.children.filter(
      (c: any) => c.responsive?.portable?.props?.className?.includes('flex-wrap'),
    );
    // 통화 섹션 래퍼 1 + 배송국가 섹션 래퍼 1
    const shippingSection = panel.children.find(
      (c: any) => c.comment?.includes('배송국가 섹션'),
    );
    const shippingWrapper = shippingSection.children.find(
      (c: any) => c.responsive?.portable?.props?.className?.includes('flex-wrap'),
    );

    expect(wrappers.length).toBe(1); // 통화 섹션은 panel 직계
    expect(shippingWrapper).toBeTruthy();

    for (const wrapper of [wrappers[0], shippingWrapper]) {
      expect(wrapper.iteration).toBeUndefined();
      const iterating = wrapper.children[0];
      expect(iterating.iteration).toBeTruthy();
    }
  });

  it('portable 옵션은 rounded-full 칩이고 체크 아이콘은 숨는다', () => {
    const currencyWrapper = panel.children.find(
      (c: any) => c.responsive?.portable?.props?.className?.includes('flex-wrap'),
    );
    const button = currencyWrapper.children[0].children[0];

    const chipCls = button.responsive.portable.props.className;
    expect(chipCls).toContain('rounded-full');
    expect(chipCls).toContain('inline-flex');
    expect(chipCls).toContain('whitespace-nowrap');
    // 얕은 머지 — role/aria-selected 재선언 필수
    expect(button.responsive.portable.props.role).toBe('option');
    expect(button.responsive.portable.props['aria-selected']).toBeTruthy();

    const check = button.children.find((c: any) => c.name === 'Icon');
    expect(check.responsive.portable.if).toBe('{{false}}');
  });

  it('portable 패널은 드로어 안에서 카드 크롬을 벗는다 (언어 칩과 줄바꿈 정렬)', () => {
    const cls = panel.responsive.portable.props.className;
    // 크롬을 남기면 드로어 패딩 안에 패딩이 중첩되어 칩 가용폭이 줄고 언어 칩과 어긋난다.
    expect(cls).toContain('bg-transparent');
    expect(cls).toContain('border-0');
    expect(cls).toContain('shadow-none');
  });
});
