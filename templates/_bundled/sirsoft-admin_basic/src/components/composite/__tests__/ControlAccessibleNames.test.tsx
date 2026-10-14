/**
 * 관리자 템플릿 컴포넌트가 그리는 입력칸의 접근 가능한 이름 (#757)
 *
 * 컴포넌트 안에서 그리는 칸은 레이아웃 정적 검사가 보지 못한다. 메뉴·브랜드·카테고리 스위치는 「스위치, 켬」만,
 * 권한 트리 그룹 체크박스는 「체크박스」만 읽혔고, 다국어칸은 placeholder 로만, 목록형 선택칸은 고른 값만 읽혔다.
 * 검색 필터 칩은 실제 체크박스가 display:none 이라 Tab 으로 닿지 않았다.
 *
 * 번역 함수는 실제 언어 파일(템플릿 ko/en, 일본어 언어팩 ja)로 만든다 — 키가 세 언어에 모두 있는지도 함께 본다.
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { render, screen, fireEvent, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { DndContext } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { SortableMenuItem } from '../SortableMenuItem';
import { PermissionTree, type PermissionNode } from '../PermissionTree';
import { MultilingualInput } from '../MultilingualInput';
import { DataGrid, type DataGridColumn } from '../DataGrid';
import { SearchableDropdown } from '../SearchableDropdown';
import { CategoryTree } from '../CategoryTree';
import { DynamicFieldList } from '../DynamicFieldList';
import { ChipCheckbox } from '../ChipCheckbox';
import { Select } from '../../basic/Select';
import { Input } from '../../basic/Input';
import { Toggle } from '../Toggle';
import { TagInput } from '../TagInput';
import { MultilingualTagInput } from '../MultilingualTagInput';
import { TabNavigationScroll } from '../TabNavigationScroll';
import { TabNavigation } from '../TabNavigation';

type Locale = 'ko' | 'en' | 'ja';

const ROOT = path.resolve(__dirname, '../../../..');
const JA_ROOT = path.resolve(ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial');

const loadCommon = (locale: Locale): Record<string, unknown> => {
  const dir = locale === 'ja' ? JA_ROOT : path.join(ROOT, 'lang/partial', locale);
  return JSON.parse(fs.readFileSync(path.join(dir, 'common.json'), 'utf8'));
};

/** 엔진 G7Core.t 와 같은 규칙 — 없는 키는 키 그대로, {{name}} 치환 */
const makeT = (locale: Locale) => {
  const common = loadCommon(locale);
  return (key: string, params?: Record<string, string | number>) => {
    const [file, ...rest] = key.split('.');
    let node: unknown = file === 'common' ? common : undefined;
    for (const part of rest) node = (node as Record<string, unknown> | undefined)?.[part];
    if (typeof node !== 'string') return key;
    return params ? node.replace(/\{\{(\w+)\}\}/g, (_, k) => String(params[k] ?? '')) : node;
  };
};

const installCore = (locale: Locale = 'ko', extra: Record<string, unknown> = {}) => {
  (window as any).G7Core = { t: makeT(locale), locale: { current: () => locale, supported: () => ['ko', 'en'] }, ...extra };
};

const ORIGINAL_WIDTH = window.innerWidth;

beforeEach(() => installCore('ko'));
afterEach(() => {
  delete (window as any).G7Core;
  Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: ORIGINAL_WIDTH });
});

describe('새 공용 키 — 세 언어', () => {
  // @scenario component=keys
  // @effects controls_have_accessible_name
  it('§5-1 키가 ko·en·ja 에 모두 있고 자리표시가 같다', () => {
    const keys = ['row_cell', 'row_n', 'select_row_n', 'select_item', 'select_all', 'select_all_in', 'item_enabled', 'scope_of', 'sort_order', 'per_page', 'search_field', 'field_in_language', 'language'];
    const placeholders = (s: string) => (s.match(/\{\{\w+\}\}/g) ?? []).sort().join(',');
    const [ko, en, ja] = (['ko', 'en', 'ja'] as Locale[]).map(loadCommon);
    for (const key of keys) {
      expect(typeof ko[key], `ko ${key}`).toBe('string');
      expect(typeof en[key], `en ${key}`).toBe('string');
      expect(typeof ja[key], `ja ${key}`).toBe('string');
      expect(placeholders(en[key] as string)).toBe(placeholders(ko[key] as string));
      expect(placeholders(ja[key] as string)).toBe(placeholders(ko[key] as string));
    }
  });
});

describe('C1 SortableMenuItem — 스위치 이름', () => {
  const renderItem = (name: string) =>
    render(
      <DndContext>
        <SortableContext items={[1]} strategy={verticalListSortingStrategy}>
          <SortableMenuItem item={{ id: 1, name, slug: 's', url: '/admin/x', icon: 'home', isActive: true, isModuleMenu: false }} />
        </SortableContext>
      </DndContext>
    );

  // @scenario component=sortable_menu_item
  // @effects controls_have_accessible_name
  it('스위치가 「{항목} 사용」으로 읽힌다', () => {
    renderItem('대시보드');
    expect(screen.getByRole('switch', { name: '대시보드 사용' })).toBeTruthy();
  });

  // @scenario component=sortable_menu_item
  // @effects controls_have_accessible_name
  it('일본어 화면에서는 일본어 문구다', () => {
    installCore('ja');
    renderItem('ダッシュボード');
    expect(screen.getByRole('switch', { name: 'ダッシュボード 使用' })).toBeTruthy();
  });
});

describe('C2 PermissionTree — 그룹·개별·범위 이름', () => {
  const data: PermissionNode[] = [
    {
      id: 1, identifier: 'core', name: '코어', extension_type: 'core', is_assignable: false, leaf_count: 1,
      children: [
        {
          id: 2, identifier: 'core.users', name: '회원 관리', is_assignable: false, leaf_count: 1,
          children: [
            { id: 3, identifier: 'core.users.read', name: '회원 조회', description: '회원 목록과 상세를 봅니다', is_assignable: true, leaf_count: 1, resource_route_key: 'user', children: [] },
          ],
        },
      ],
    },
  ];
  const scopeOptions = [{ value: null, label: '전체' }, { value: 'self', label: '본인만' }];

  // @scenario component=permission_tree
  // @effects controls_have_accessible_name
  it('모듈·카테고리 그룹 체크박스가 「{그룹} 전체 선택」으로 읽힌다', () => {
    render(<PermissionTree data={data} value={[]} scopeOptions={scopeOptions} />);
    expect(screen.getByRole('checkbox', { name: '코어 전체 선택' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: '회원 관리 전체 선택' })).toBeTruthy();
  });

  // @scenario component=permission_tree
  // @effects controls_have_accessible_name
  it('개별 권한 체크박스 이름에 설명·범위 값이 섞이지 않고, 설명은 부가 설명으로 읽힌다', () => {
    render(<PermissionTree data={data} value={[{ id: 3, scope_type: null }]} scopeOptions={scopeOptions} />);
    const leaf = screen.getByRole('checkbox', { name: '회원 조회' });
    expect(leaf).toHaveAccessibleDescription('회원 목록과 상세를 봅니다');
  });

  // @scenario component=permission_tree
  // @effects listbox_reads_name_and_value
  it('범위 드롭다운 단추가 「{권한} 적용 범위 {현재 값}」으로 읽히고 목록을 연다고 알린다', () => {
    render(<PermissionTree data={data} value={[{ id: 3, scope_type: 'self' }]} scopeOptions={scopeOptions} />);
    const trigger = screen.getByRole('button', { name: '회원 조회 적용 범위 본인만' });
    expect(trigger).toHaveAttribute('aria-haspopup', 'listbox');
  });
});

describe('C3 MultilingualInput — 이름·언어명·곁 라벨', () => {
  const two = [{ code: 'ko', name: '한국어' }, { code: 'en', name: 'English' }];
  const one = [{ code: 'ko', name: '한국어' }];

  // @scenario component=multilingual_input
  // @effects controls_have_accessible_name
  it('tabs: 언어가 둘이면 칸마다 「{이름} ({언어})」', () => {
    render(<MultilingualInput layout="tabs" availableLocales={two} defaultLocale="ko" ariaLabel="상품명" value={{}} />);
    expect(screen.getByRole('textbox', { name: '상품명 (한국어)' })).toBeTruthy();
    expect(screen.getByRole('textbox', { name: '상품명 (English)' })).toBeTruthy();
  });

  // @scenario component=multilingual_input
  // @effects controls_have_accessible_name
  it('compact: 고른 언어를 따라 이름이 바뀐다, aria-label 로 줘도 같다', () => {
    render(<MultilingualInput layout="compact" availableLocales={two} defaultLocale="ko" aria-label="옵션명" value={{}} />);
    expect(screen.getByRole('textbox', { name: '옵션명 (한국어)' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /EN/ }));
    expect(screen.getByRole('textbox', { name: '옵션명 (English)' })).toBeTruthy();
  });

  // @scenario component=multilingual_input
  // @effects controls_have_accessible_name
  it('언어가 하나면 언어명 없이 이름만(D7) — inline·textarea 도', () => {
    render(<MultilingualInput layout="inline" inputType="textarea" availableLocales={one} defaultLocale="ko" ariaLabel="설명" value={{}} />);
    expect(screen.getByRole('textbox', { name: '설명' })).toBeTruthy();
  });

  // @scenario component=multilingual_input
  // @effects controls_have_accessible_name
  it('이름 prop 이 없으면 바깥 상자 곁의 form-label 을 잇는다(compact·tabs)', () => {
    render(
      <div>
        <div>
          <label className="form-label">상품명</label>
          <MultilingualInput layout="compact" availableLocales={two} defaultLocale="ko" value={{}} />
        </div>
        <div>
          <label className="form-label">짧은 설명</label>
          <MultilingualInput layout="tabs" availableLocales={two} defaultLocale="ko" value={{}} />
        </div>
      </div>
    );
    expect(screen.getByRole('textbox', { name: '상품명' })).toBeTruthy();
    expect(screen.getAllByRole('textbox', { name: '짧은 설명' })).toHaveLength(2);
  });
});

describe('C4 DataGrid — 셀 문맥·rowLabelField·행 선택 이름', () => {
  const columns: DataGridColumn[] = [
    { field: 'is_default', header: '기본' },
    { field: 'price', header: '판매가', cellChildren: [{ type: 'basic', name: 'Span', text: 'x' }] as any },
  ];
  const rows = [
    { id: 1, is_default: true, price: 1000, name: { ko: '기본 티셔츠', en: 'Basic tee' } },
    { id: 2, is_default: false, price: 2000, name: { ko: '', en: '' } },
  ];

  const setup = () => {
    const contexts: any[] = [];
    installCore('ko', {
      renderItemChildren: (_children: unknown, ctx: any) => {
        contexts.push(ctx);
        return null;
      },
    });
    return contexts;
  };

  // @scenario component=data_grid
  // @effects row_controls_read_row_and_column
  it('표: 셀 문맥에 $rowLabel·$rowNumber·$column 이 들어간다(rowLabelField 는 현재 언어 값)', () => {
    const contexts = setup();
    render(<DataGrid columns={columns} data={rows} idField="id" rowLabelField="name" />);
    expect(contexts[0]).toMatchObject({ $rowLabel: '기본 티셔츠', $rowNumber: 1, $column: '판매가', $value: 1000 });
    // 이름이 빈 행은 번호 대체
    expect(contexts[1]).toMatchObject({ $rowLabel: '2번째 행', $rowNumber: 2, $column: '판매가' });
  });

  // @scenario component=data_grid
  // @effects row_controls_read_row_and_column
  it('768px 미만 카드 보기도 같은 문맥', () => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 500 });
    const contexts = setup();
    render(<DataGrid columns={columns} data={rows} idField="id" rowLabelField="name" />);
    expect(contexts[0]).toMatchObject({ $rowLabel: '기본 티셔츠', $rowNumber: 1, $column: '판매가' });
  });

  // @scenario component=data_grid
  // @effects row_controls_read_row_and_column
  it('첫 열이 참/거짓이면 행 선택이 「N번째 행 선택」으로 나뉜다(「행 선택」 뭉침 없음)', () => {
    setup();
    render(<DataGrid columns={columns} data={rows} idField="id" selectable selectedIds={[]} onSelectionChange={vi.fn()} />);
    expect(screen.getByRole('checkbox', { name: '1번째 행 선택' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: '2번째 행 선택' })).toBeTruthy();
  });

  // @scenario component=data_grid
  // @effects row_controls_read_row_and_column
  it('rowLabelField 가 없고 첫 열이 날짜·숫자 모양이면 그 값 대신 「N번째 행」 — 「2026-10-07T02:50:00+00:00 선택」 방지', () => {
    setup();
    const cols: DataGridColumn[] = [{ field: 'ordered_at', header: '주문일시' }, { field: 'no', header: '번호' }];
    render(
      <DataGrid
        columns={cols}
        data={[{ id: 1, ordered_at: '2026-10-07T02:50:00+00:00', no: 3 }, { id: 2, ordered_at: '2026-10-07 11:20', no: 4 }]}
        idField="id"
        selectable
        selectedIds={[]}
        onSelectionChange={vi.fn()}
      />
    );
    expect(screen.getByRole('checkbox', { name: '1번째 행 선택' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: '2번째 행 선택' })).toBeTruthy();
    render(<DataGrid columns={[{ field: 'no', header: '번호' }]} data={[{ id: 9, no: 12345 }]} idField="id" selectable selectedIds={[]} onSelectionChange={vi.fn()} />);
    expect(screen.queryByRole('checkbox', { name: '12345 선택' })).toBeNull();
  });

  // @scenario component=data_grid
  // @effects row_controls_read_row_and_column
  it('rowLabelField 가 있으면 행 선택도 그 이름으로', () => {
    setup();
    render(<DataGrid columns={columns} data={rows} idField="id" rowLabelField="name" selectable selectedIds={[]} onSelectionChange={vi.fn()} />);
    expect(screen.getByRole('checkbox', { name: '기본 티셔츠 선택' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: '2번째 행 선택' })).toBeTruthy();
  });
});

describe('C5 SearchableDropdown — 이름 + 값, 곁 라벨, 검색칸', () => {
  const options = [{ value: 1, label: '홍길동' }, { value: 2, label: '김철수' }];

  // @scenario component=searchable_dropdown
  // @effects listbox_reads_name_and_value
  it('aria-label 이 있으면 단추가 「{이름} {현재 값}」', () => {
    render(<SearchableDropdown options={options} value={1} aria-label="작성자" />);
    expect(screen.getByRole('button', { name: '작성자 홍길동' })).toBeTruthy();
  });

  // @scenario component=searchable_dropdown
  // @effects listbox_reads_name_and_value
  it('aria-labelledby 를 받으면 「{그 요소} {현재 값}」', () => {
    render(
      <div>
        <span id="recipient-title">수신자</span>
        <SearchableDropdown options={options} value={2} aria-labelledby="recipient-title" />
      </div>
    );
    expect(screen.getByRole('button', { name: '수신자 김철수' })).toBeTruthy();
  });

  // @scenario component=searchable_dropdown
  // @effects listbox_reads_name_and_value
  it('이름 prop 이 없으면 곁 form-label + 현재 값', () => {
    render(
      <div>
        <label className="form-label">국가</label>
        <SearchableDropdown options={options} value={1} />
      </div>
    );
    expect(screen.getByRole('button', { name: '국가 홍길동' })).toBeTruthy();
  });

  // @scenario component=searchable_dropdown
  // @effects listbox_reads_name_and_value
  it('고른 값이 없으면 안내 글자(triggerLabel)를 값으로 읽지 않는다 — 이름만', () => {
    render(<SearchableDropdown options={options} aria-label="국가" triggerLabel="국가 선택" />);
    expect(screen.getByRole('button', { name: '국가' })).toBeTruthy();
  });

  // @scenario component=searchable_dropdown
  // @effects listbox_reads_name_and_value
  it('값이 빈 문자열이거나 옵션에 없는 값이면 고른 값이 아니다 — 「국가 국가 선택」「행위자 행위자 선택」 방지', () => {
    render(
      <div>
        <SearchableDropdown options={options} value="" aria-label="국가" triggerLabel="국가 선택" />
        <SearchableDropdown options={options} value={99} aria-label="행위자" triggerLabel="행위자 선택" />
      </div>
    );
    expect(screen.getByRole('button', { name: '국가' })).toBeTruthy();
    expect(screen.getByRole('button', { name: '행위자' })).toBeTruthy();
  });

  // @scenario component=searchable_dropdown
  // @effects controls_have_accessible_name
  it('펼친 목록의 검색칸 이름은 「검색」', () => {
    render(<SearchableDropdown options={options} aria-label="작성자" />);
    fireEvent.click(screen.getByRole('button', { name: /작성자/ }));
    expect(screen.getByRole('textbox', { name: '검색' })).toBeTruthy();
  });
});

describe('C6 CategoryTree — 체크박스 이름', () => {
  // @scenario component=category_tree
  // @effects controls_have_accessible_name
  it('분류 체크박스가 「{분류명} 선택」', () => {
    render(<CategoryTree selectable data={[{ id: 1, name: 'clothes', localized_name: '의류', children: [] }]} selectedIds={[]} />);
    expect(screen.getByRole('checkbox', { name: '의류 선택' })).toBeTruthy();
  });
});

describe('C7 DynamicFieldListItem — 셀 이름', () => {
  // @scenario component=dynamic_field_list
  // @effects row_controls_read_row_and_column
  it('셀 칸이 「N번째 행 {열 이름}」', () => {
    render(
      <DynamicFieldList
        items={[{ name: '소재', value: '면', unit: '' }, { name: '', value: '', unit: '' }]}
        columns={[
          { key: 'name', label: '항목명', type: 'input' },
          { key: 'value', label: '내용', type: 'textarea' },
          { key: 'unit', label: '단위', type: 'select', options: [{ value: 'cm', label: 'cm' }] },
        ] as any}
        onChange={vi.fn()}
      />
    );
    expect(screen.getByRole('textbox', { name: '1번째 행 항목명' })).toBeTruthy();
    expect(screen.getByRole('textbox', { name: '2번째 행 내용' })).toBeTruthy();
    expect(screen.getByRole('combobox', { name: '2번째 행 단위' })).toBeTruthy();
  });
});

describe('C8 ChipCheckbox — 키보드로 닿는 칩', () => {
  // @scenario component=chip_checkbox
  // @effects controls_have_accessible_name
  it('Tab 으로 실제 체크박스에 포커스가 가고 Space 로 바뀐다, 라벨에 포커스 링 클래스(두 분기)', async () => {
    const user = userEvent.setup();
    const onA = vi.fn();
    const onB = vi.fn();
    render(
      <div>
        <ChipCheckbox label="결제완료" value="paid" checked={false} onChange={onA} />
        <ChipCheckbox label="배송중" value="shipping" checked={false} customColor="#3B82F6" onChange={onB} />
      </div>
    );
    const paid = screen.getByRole('checkbox', { name: '결제완료' });
    const shipping = screen.getByRole('checkbox', { name: '배송중' });
    for (const box of [paid, shipping]) {
      expect(box.className).not.toMatch(/(^|\s)hidden(\s|$)/);
      expect(box.className).toMatch(/\bsr-only\b/);
      expect(box.closest('label')?.className).toMatch(/has-\[:focus-visible\]:ring-2/);
    }
    await user.tab();
    expect(document.activeElement).toBe(paid);
    await user.keyboard(' ');
    expect(onA).toHaveBeenCalledTimes(1);
    await user.tab();
    expect(document.activeElement).toBe(shipping);
  });
});

describe('C9 Select 목록형 — 이름 + 값', () => {
  const options = [{ value: 'latest', label: '최신순' }, { value: 'oldest', label: '오래된순' }];

  // @scenario component=select
  // @effects listbox_reads_name_and_value
  it('aria-label 이 있으면 단추가 「{이름} {현재 값}」으로 읽힌다(값이 이름에서 빠지지 않음)', () => {
    render(<Select options={options} value="latest" aria-label="정렬 기준" onChange={vi.fn()} />);
    expect(screen.getByRole('button', { name: '정렬 기준 최신순' })).toBeTruthy();
  });

  // @scenario component=select
  // @effects listbox_reads_name_and_value
  it('aria-labelledby 는 바깥 상자가 아니라 단추에 닿는다', () => {
    const { container } = render(
      <div>
        <span id="per-page-title">페이지당 표시 개수</span>
        <Select options={[{ value: 20, label: '20' }]} value={20} aria-labelledby="per-page-title" onChange={vi.fn()} />
      </div>
    );
    const button = screen.getByRole('button', { name: '페이지당 표시 개수 20' });
    expect(button.getAttribute('aria-labelledby')).toMatch(/^per-page-title /);
    expect(container.querySelector('div[aria-labelledby]')).toBeNull();
  });

  // @scenario component=select
  // @effects listbox_reads_name_and_value
  it('고른 값이 없으면 단추에 보이는 placeholder 를 값으로 읽지 않는다 — 이름만(「상태 변경 상태 변경」 겹침 없음)', () => {
    render(<Select options={options} value="" placeholder="상태 변경" aria-label="상태 변경" onChange={vi.fn()} />);
    expect(screen.getByRole('button', { name: '상태 변경' })).toBeTruthy();
    expect(screen.getByRole('button').textContent).toContain('상태 변경');
  });

  // @scenario component=select
  // @effects listbox_reads_name_and_value
  it('값이 빈 자리표시 선택지(disabled 또는 이름으로 시작하는 글자)는 값으로 잇지 않는다 — 「주문상태 변경 주문상태 변경」「판매 상태 판매 상태 선택」 방지', () => {
    render(
      <div>
        <Select options={[{ value: '', label: '주문상태 변경', disabled: true }, { value: 'paid', label: '결제완료' }]} value="" aria-label="주문상태 변경" onChange={vi.fn()} />
        <Select options={[{ value: '', label: '판매 상태 선택' }, { value: 'on_sale', label: '판매중' }]} value="" aria-label="판매 상태" onChange={vi.fn()} />
        <Select options={[{ value: '', label: '전체' }, { value: 'ko', label: '한국어' }]} value="" aria-label="언어" onChange={vi.fn()} />
      </div>
    );
    expect(screen.getByRole('button', { name: '주문상태 변경' })).toBeTruthy();
    expect(screen.getByRole('button', { name: '판매 상태' })).toBeTruthy();
    // 「전체」는 뜻 있는 현재 값이라 그대로 잇는다
    expect(screen.getByRole('button', { name: '언어 전체' })).toBeTruthy();
  });

  // @scenario component=select
  // @effects listbox_reads_name_and_value
  it('이름이 없으면 placeholder 글자만 읽힌다 — 판정식이 placeholder 를 목록형 이름으로 치지 않는 이유', () => {
    render(<Select options={options} value="" placeholder="레이아웃을 선택하세요" onChange={vi.fn()} />);
    expect(screen.getByRole('button', { name: '레이아웃을 선택하세요' })).toBeTruthy();
  });

  // @scenario component=select
  // @effects listbox_reads_name_and_value
  it('영어 화면에서도 이름 + 값', () => {
    installCore('en');
    render(<Select options={[{ value: 'latest', label: 'Newest' }]} value="latest" aria-label="Sort by" onChange={vi.fn()} />);
    expect(within(document.body).getByRole('button', { name: 'Sort by Newest' })).toBeTruthy();
  });
});

describe('브라우저 내장 날짜·시각 칸', () => {
  // @scenario component=native_datetime
  // @effects controls_have_accessible_name
  it('datetime-local 은 내장 칸 그대로 그리고 바깥 칸 이름(aria-label)을 넘긴다 — 안쪽 년·월·AM/PM 칸은 브라우저 내부라 이름을 달 수 없다', () => {
    const { container } = render(<Input type="datetime-local" aria-label="발급 기간 시작일" value="" onChange={vi.fn()} />);
    const input = container.querySelector('input[type="datetime-local"]');
    expect(input).not.toBeNull();
    expect(input).toHaveAccessibleName('발급 기간 시작일');
  });
});

describe('Toggle 곁 제목 — 숫자 배지는 제목이 아니다', () => {
  // @scenario component=toggle_row_title
  // @effects controls_have_accessible_name
  it('앞 형제가 항목 수 배지(숫자만)면 그 숫자를 스위치 이름으로 잇지 않는다 — 상품정보고시 「9」「10」 방지', () => {
    render(
      <div>
        <div><span>9</span></div>
        <Toggle checked={false} onChange={vi.fn()} name="is_active" />
      </div>
    );
    expect(screen.getByRole('switch')).not.toHaveAccessibleName('9');
  });

  // @scenario component=toggle_row_title
  // @effects controls_have_accessible_name
  it('제목 글자와 숫자 배지가 함께 있으면 숫자를 건너뛰고 제목 글자를 잇는다', () => {
    render(
      <div>
        <div><span>12</span><span>의류 고시</span></div>
        <Toggle checked={false} onChange={vi.fn()} name="is_active" />
      </div>
    );
    expect(screen.getByRole('switch', { name: '의류 고시' })).toBeTruthy();
  });
});

describe('TagInput·MultilingualTagInput — 레이아웃이 준 이름이 입력칸에 닿는다', () => {
  const roles = [{ value: 'admin', label: '관리자' }, { value: 'editor', label: '편집자' }];

  // @scenario component=tag_input
  // @effects controls_have_accessible_name
  it('TagInput aria-label 이 react-select 입력칸 이름이 된다(다중·단일 모두) — 곁 라벨보다 우선', () => {
    render(
      <div>
        <div>
          <label className="form-label">무시할 라벨</label>
          <TagInput options={roles} value={[]} isMulti creatable aria-label="신고 열람 역할" onChange={vi.fn()} />
        </div>
        <TagInput options={roles} value={[]} isMulti={false} aria-label="대상 게시판" onChange={vi.fn()} />
      </div>
    );
    expect(screen.getByRole('combobox', { name: '신고 열람 역할' })).toBeTruthy();
    expect(screen.getByRole('combobox', { name: '대상 게시판' })).toBeTruthy();
  });

  // @scenario component=tag_input
  // @effects controls_have_accessible_name
  it('TagInput aria-labelledby 가 입력칸에 닿는다', () => {
    render(
      <div>
        <span id="report-role-title">신고 처리 역할</span>
        <TagInput options={roles} value={[]} isMulti aria-labelledby="report-role-title" onChange={vi.fn()} />
      </div>
    );
    expect(screen.getByRole('combobox', { name: '신고 처리 역할' })).toBeTruthy();
  });

  // @scenario component=tag_input
  // @effects controls_have_accessible_name
  it('MultilingualTagInput aria-label 이 입력칸 이름이 된다', () => {
    render(<MultilingualTagInput value={[]} aria-label="옵션 값" placeholder="입력 후 Enter" />);
    expect(screen.getByRole('textbox', { name: '옵션 값' })).toBeTruthy();
  });

  // @scenario component=tag_input
  // @effects controls_have_accessible_name
  it('MultilingualTagInput 이름 prop 이 없으면 곁 form-label 을 잇고 placeholder 를 이름으로 쓰지 않는다', () => {
    render(
      <div>
        <label className="form-label">색상</label>
        <MultilingualTagInput value={[]} placeholder="입력 후 Enter" />
      </div>
    );
    expect(screen.getByRole('textbox', { name: '색상' })).toBeTruthy();
  });

  // @scenario component=tag_input
  // @effects controls_have_accessible_name
  it('MultilingualTagInput 이름도 곁 라벨도 없으면 마지막 수단으로 placeholder', () => {
    render(<MultilingualTagInput value={[]} placeholder="입력 후 Enter" />);
    expect(screen.getByRole('textbox', { name: '입력 후 Enter' })).toBeTruthy();
  });
});

describe('탭 이동 컴포넌트 — 좁은 화면 선택 상자 이름', () => {
  const tabs = [{ id: 'basic', label: '기본정보' }, { id: 'price', label: '판매정보' }];
  const narrow = () => Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 390 });

  // @scenario component=tab_navigation
  // @effects listbox_reads_name_and_value
  it('TabNavigationScroll 390px 선택 상자가 이름 없이 「기본정보」(값만)로 읽히지 않는다 — 기본 이름 「섹션 이동」', () => {
    narrow();
    render(<TabNavigationScroll tabs={tabs} activeTabId="basic" />);
    expect(screen.getByRole('button', { name: '섹션 이동 기본정보' })).toBeTruthy();
  });

  // @scenario component=tab_navigation
  // @effects listbox_reads_name_and_value
  it('aria-label 을 주면 그 이름 + 현재 값', () => {
    narrow();
    render(<TabNavigationScroll tabs={tabs} activeTabId="price" aria-label="상품 정보 구획" />);
    expect(screen.getByRole('button', { name: '상품 정보 구획 판매정보' })).toBeTruthy();
  });

  // @scenario component=tab_navigation
  // @effects listbox_reads_name_and_value
  it('TabNavigation 도 좁은 화면 선택 상자에 이름이 붙는다', () => {
    narrow();
    render(
      <div>
        <TabNavigation tabs={tabs} activeTabId="basic" />
        <TabNavigation tabs={tabs} activeTabId="price" aria-label="설정 탭" />
      </div>
    );
    expect(screen.getByRole('button', { name: '섹션 이동 기본정보' })).toBeTruthy();
    expect(screen.getByRole('button', { name: '설정 탭 판매정보' })).toBeTruthy();
  });
});
