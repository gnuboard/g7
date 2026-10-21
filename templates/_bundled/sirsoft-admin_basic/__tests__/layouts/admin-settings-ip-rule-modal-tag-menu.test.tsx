/**
 * @file admin-settings-ip-rule-modal-tag-menu.test.tsx
 * @description 환경설정 > 보안 > IP 접근 제한 규칙 「편집」 창 — 허용 IP 를 Enter 로 넣은 뒤 메뉴가 아래 「메모」 칸을 덮지 않는다
 *
 * 실측: 규칙 「편집」 창(허용 IP 가 이미 있는 칸)에서 IP 를 치고 Enter 를 누르면 태그는 붙지만 태그 입력 메뉴
 * 포털(z-9999)이 열린 채 남아, 바로 아래 「메모」 칸 클릭을 가로챘다. 메뉴는 입력이 비면 붙은 태그 전부를 옵션으로
 * 펼치므로 값이 있던 편집 창에서 길어져 메모 칸 가운데까지 덮었다(추가 창의 첫 태그는 한 줄이라 가운데에 닿지 않았다).
 *
 * 같은 칸에 포커스만 줘도 메뉴가 붙은 IP 를 펼쳐 메모 칸을 덮었고, 메모 칸을 누르려던 클릭이 그 행에 닿으면 기존 IP 가
 * 해제됐다. 여러 IP 를 붙여넣어 만든 뒤에도 메뉴가 남았다.
 *
 * **실제 배포 JSON**(`_modal_ip_restriction_rule_form.json`)에서 허용 IP 노드와 그 바로 아래 메모 노드를 찾아, 그
 * 노드가 선언한 props 로 **실제 TagInput** 을 그린다 — 대역으로는 메뉴 포털이 없어 결함이 보이지 않는다.
 *
 * 엔진 하네스(createLayoutTest)로 창 전체를 그리지 않는 이유: 하네스의 렌더러는 저장소 루트의 React 를, TagInput·
 * react-select·emotion 은 이 템플릿의 React 사본을 써서 훅이 돌지 않는다(`Cannot read properties of null (reading
 * 'useCallback')`). 창 배선(입력 → 창 상태 → 적용)은 형제 시험 admin-settings-ip-restriction.test.tsx 가 잰다.
 *
 * 데스크톱 환경 재현: react-select 는 `document.createEvent('TouchEvent')` 가 되면 고른 뒤 입력칸을 흐려 메뉴를
 * 닫는다(blurInputOnSelect 기본값). jsdom 은 TouchEvent 를 만들 수 있어 결함이 가려지므로 데스크톱처럼 거부한다.
 */

import { vi } from 'vitest';

vi.hoisted(() => {
  const original = document.createEvent.bind(document);
  document.createEvent = ((type: string) => {
    if (type === 'TouchEvent') throw new Error('NotSupportedError: 데스크톱 브라우저는 TouchEvent 를 만들지 않는다');
    return original(type);
  }) as typeof document.createEvent;
});

import React, { useState } from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import modalPartial from '../../layouts/partials/admin_settings/_modal_ip_restriction_rule_form.json';
import { TagInput } from '../../src/components/composite/TagInput';

type Json = Record<string, any>;

/** 레이아웃 트리에서 id 로 노드를 찾는다. */
function findById(node: unknown, id: string): Json | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findById(child, id);
      if (found) return found;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (obj.id === id) return obj;
    for (const value of Object.values(obj)) {
      const found = findById(value, id);
      if (found) return found;
    }
  }
  return null;
}

const ipsNode = findById(modalPartial, 'ip_rule_ips_input');
const memoNode = findById(modalPartial, 'ip_rule_memo_input');

/**
 * 편집 창의 허용 IP·메모 두 칸을 배포 JSON 이 선언한 모양대로 그린다. change 는 노드 액션처럼 받은 배열을
 * 통째로 창 상태(여기서는 지역 상태)에 담는다.
 */
const EditDialogFields: React.FC<{ initialIps: string[] }> = ({ initialIps }) => {
  const [ips, setIps] = useState<string[]>(initialIps);
  const [memo, setMemo] = useState('본사망');
  const p = ipsNode!.props;
  return (
    <div role="dialog">
      <div>
        <label>허용 IP</label>
        <TagInput
          id={ipsNode!.id}
          value={ips}
          creatable={p.creatable}
          placeholder={p.placeholder}
          className={p.className}
          defaultVariant={p.defaultVariant}
          onChange={(e: any) => setIps(e.target.value)}
        />
      </div>
      <div>
        <label>메모</label>
        <input id={memoNode!.id} value={memo} onChange={(e) => setMemo(e.target.value)} />
      </div>
      <output data-testid="ips">{ips.join(',')}</output>
    </div>
  );
};

describe('환경설정 보안 — IP 규칙 편집 창의 허용 IP 메뉴', () => {
  const menuPortal = () => document.body.querySelector('.tag-input__menu-portal');

  /**
   * 아래 시험이 그리는 두 칸의 전제(배포 JSON 계약)만 잰다 — 메뉴 동작은 아래 it 들이 잰다.
   * @effects tag_input_ip_rule_field_contract
   */
  it('배포 JSON 의 허용 IP 칸은 여러 개를 새로 만드는 태그 입력이고 바로 아래가 메모 칸이다', () => {
    expect(ipsNode?.name).toBe('TagInput');
    expect(ipsNode?.props?.creatable).toBe(true);
    // isMulti 를 끄지 않는다 — 여러 개 고르는 칸은 고른 뒤 메뉴를 열어 두는 갈래다
    expect(ipsNode?.props?.isMulti).toBeUndefined();
    expect(memoNode?.name).toBe('Input');
    // 제안 옵션도 안내 문구도 주지 않는다 — 빈 입력일 때 메뉴에 보일 것이 없는 칸이다
    expect(ipsNode?.props?.options).toBeUndefined();
    expect(ipsNode?.props?.noOptionsMessage).toBeUndefined();
  });

  /**
   * @scenario mode=creatable, input=prefilled_typed_enter
   * @effects tag_input_create_closes_menu
   */
  it('편집 창: 허용 IP 가 있는 칸에 IP 를 치고 Enter 를 누르면 태그가 붙고 메뉴가 닫혀 메모 칸을 덮지 않는다', async () => {
    const user = userEvent.setup();
    render(<EditDialogFields initialIps={['10.15.4.0/24']} />);

    const combobox = screen.getByRole('combobox');
    await user.click(combobox);
    await user.keyboard('10.15.5.5');
    expect(menuPortal(), '입력 중에는 메뉴가 열려 있어야 한다').not.toBeNull();
    await user.keyboard('{Enter}');

    expect(screen.getByTestId('ips').textContent).toBe('10.15.4.0/24,10.15.5.5');
    expect(menuPortal(), '태그를 넣은 뒤 메뉴가 열린 채 남아 아래 「메모」 칸 클릭을 가로챈다').toBeNull();

    // 바로 아래 메모 칸으로 마우스로 옮겨 고칠 수 있다
    const memo = document.getElementById('ip_rule_memo_input') as HTMLInputElement;
    await user.click(memo);
    expect(memo).toHaveFocus();
    await user.clear(memo);
    await user.type(memo, '본사망 확장');
    expect(memo.value).toBe('본사망 확장');
  });

  /**
   * @scenario mode=creatable, input=prefilled_focus_only
   * @effects tag_input_create_menu_omits_attached_tags, tag_input_create_blank_menu_stays_closed
   */
  it('편집 창: 허용 IP 칸에 포커스만 줘도 메뉴가 기존 IP 를 펼치지 않아, 바로 메모 칸을 눌러도 기존 IP 가 지워지지 않는다', async () => {
    const user = userEvent.setup();
    render(<EditDialogFields initialIps={['10.15.4.0/24', '10.15.5.5']} />);

    const combobox = screen.getByRole('combobox');
    await user.click(combobox);
    expect(combobox).toHaveFocus();
    // 종전: 메뉴가 붙은 IP 두 줄을 체크 표시 행으로 펼쳐 메모 칸을 덮었고, 메모 칸을 누르려던 클릭이 그 행에
    // 닿으면 react-select 가 그 IP 를 해제했다(jsdom 은 겹침을 재지 못하므로 덮을 행이 없다는 것을 잰다)
    expect(menuPortal(), '포커스만으로 기존 IP 목록 메뉴가 열려 메모 칸을 덮는다').toBeNull();
    expect(screen.queryByRole('option', { name: /10\.15\.4\.0\/24/ })).toBeNull();

    const memo = document.getElementById('ip_rule_memo_input') as HTMLInputElement;
    await user.click(memo);
    expect(memo).toHaveFocus();
    expect(screen.getByTestId('ips').textContent).toBe('10.15.4.0/24,10.15.5.5');
  });

  /**
   * @scenario mode=creatable, input=pasted_multiple
   * @effects tag_input_create_closes_menu
   */
  it('편집 창: 허용 IP 칸에 여러 IP 를 붙여넣으면 태그가 붙고 메뉴가 남지 않아 메모 칸으로 옮길 수 있다', async () => {
    const user = userEvent.setup();
    render(<EditDialogFields initialIps={['10.15.4.0/24']} />);

    await user.click(screen.getByRole('combobox'));
    await user.paste('10.15.5.5,10.15.6.6');

    expect(screen.getByTestId('ips').textContent).toBe('10.15.4.0/24,10.15.5.5,10.15.6.6');
    expect(menuPortal()).toBeNull();
    const memo = document.getElementById('ip_rule_memo_input') as HTMLInputElement;
    await user.click(memo);
    expect(memo).toHaveFocus();
  });
});
