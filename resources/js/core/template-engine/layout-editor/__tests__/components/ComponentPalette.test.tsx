/**
 * ComponentPalette 컴포넌트 테스트
 *
 * 검증 매트릭스:
 *  - 디자인/DB 그룹 렌더
 *  - accepts 필터 — 부모 컨테이너의 accepts 외 컴포넌트 비표시
 *  - spec 미제공 → spec_missing 안내
 *  - 검색 — name/description 부분 일치 필터
 *  - 클릭 → onInsert 호출 (parentPath / index / 신규 노드 골격)
 *  - defaultNode — components.json props.default 값으로 골격 생성, id 미부여
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import {
  ComponentPalette,
  buildDefaultNode,
  type ComponentManifest,
} from '../../components/ComponentPalette';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import type { NestingSpec } from '../../spec/specTypes';

function withTranslation(node: React.ReactElement): React.ReactElement {
  const engine = new TranslationEngine();
  return (
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      {node}
    </TranslationProvider>
  );
}

const manifest: ComponentManifest = {
  templateId: 'test',
  components: {
    basic: [
      {
        name: 'Div',
        type: 'basic',
        description: '컨테이너',
        props: { className: { type: 'string', default: 'p-4' } },
      },
      {
        name: 'Button',
        type: 'basic',
        description: '버튼',
        props: { variant: { type: 'string', default: 'primary' } },
      },
      {
        name: 'Img',
        type: 'basic',
        description: '이미지',
      },
    ],
    composite: [
      {
        name: 'DataGrid',
        type: 'composite',
        description: '데이터 그리드',
        bindingType: 'value',
      },
      {
        name: 'Pagination',
        type: 'composite',
        description: '페이지네이션',
      },
    ],
  },
};

const nesting: NestingSpec = {
  draggable: ['Div', 'Button', 'Img', 'DataGrid', 'Pagination'],
  containers: {
    Div: { accepts: ['Div', 'Button', 'Img', 'DataGrid', 'Pagination'] },
    Img: { accepts: [] }, // leaf
  },
};

describe('ComponentPalette — 폴백 카테고리 (componentPalette 미제공)', () => {
  it('전체 카테고리 활성 시 basic / composite 모든 컴포넌트 노출', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    // 'all' 이 초기 active — 컨테이너 testid 는 active 카테고리 키 사용
    expect(screen.getByTestId('g7le-palette-group-all')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-item-Div')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-item-Button')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-item-Img')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-item-DataGrid')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-item-Pagination')).toBeTruthy();
  });

  it('사이드바에 폴백 카테고리(all/basic/composite) 노출 + 각 카테고리 카운트', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-palette-category-all')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-category-basic')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-category-composite')).toBeTruthy();
  });

  it('카드에 type 뱃지 표시 (basic/composite)', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-palette-item-Button-badge').textContent).toBe('basic');
    expect(screen.getByTestId('g7le-palette-item-DataGrid-badge').textContent).toBe('composite');
  });

  // 결함 8 — 실제 렌더 컴포넌트 태그 배지 (React 컴포넌트명 형식)
  it('카드에 React 컴포넌트명 형식의 태그 배지 표시 (<Div>, <Button>)', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-palette-item-Div-tag').textContent).toBe('<Div>');
    expect(screen.getByTestId('g7le-palette-item-Button-tag').textContent).toBe('<Button>');
    expect(screen.getByTestId('g7le-palette-item-DataGrid-tag').textContent).toBe('<DataGrid>');
  });
});

describe('ComponentPalette — componentPalette.groups 소비 ', () => {
  it('스펙 그룹 정의가 있으면 그 라벨/매핑대로 사이드바 + 카드 노출', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          componentPalette={{
            groups: [
              {
                label: '$t:layout_editor.palette.group.design',
                kind: 'design',
                components: ['Div', 'Button', 'Img'],
              },
              {
                label: '$t:layout_editor.palette.group.db',
                kind: 'data',
                components: ['DataGrid', 'Pagination'],
              },
            ],
          }}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-palette-category-design')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-category-data')).toBeTruthy();
    // 폴백 카테고리(basic/composite) 는 노출되지 않아야 함 — 스펙 정의가 덮어씀
    expect(screen.queryByTestId('g7le-palette-category-basic')).toBeNull();
    expect(screen.queryByTestId('g7le-palette-category-composite')).toBeNull();
  });
});

describe('ComponentPalette — accepts 필터', () => {
  it('targetContainerName=Img → accepts=[] 이므로 모든 항목 숨김 + empty 안내', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={[0]}
          targetContainerName="Img"
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-palette-empty')).toBeTruthy();
    expect(screen.queryByTestId('g7le-palette-item-Div')).toBeNull();
  });

  it('targetContainerName=Div → Div/Button/Img/DataGrid/Pagination 모두 허용', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={[0]}
          targetContainerName="Div"
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-palette-item-Div')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-item-Button')).toBeTruthy();
    expect(screen.getByTestId('g7le-palette-item-DataGrid')).toBeTruthy();
  });
});

describe('ComponentPalette — spec 미제공', () => {
  it('nesting=null 이면 spec_missing 안내 표시', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={null}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    expect(screen.getByTestId('g7le-palette-spec-missing')).toBeTruthy();
  });
});

describe('ComponentPalette — 검색', () => {
  it('검색어 입력 시 name/description 부분 일치만 표시', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    const search = screen.getByTestId('g7le-palette-search') as HTMLInputElement;
    fireEvent.change(search, { target: { value: 'Btn' } });
    // 'Button' 의 name 에 'Btn' 이 포함되지 않으므로 0건, 'Button' 의 description '버튼' 검색
    fireEvent.change(search, { target: { value: '버튼' } });
    expect(screen.getByTestId('g7le-palette-item-Button')).toBeTruthy();
    expect(screen.queryByTestId('g7le-palette-item-Div')).toBeNull();
  });

  it('표시 라벨(다국어 해석)로 검색 — entrySpec.label 해석값 매칭', () => {
    // 회귀 배경: 카드에 "박스"/"인라인 텍스트" 등 다국어 라벨이 보이는데
    // 검색은 name(Div)/description 만 매칭해 표시 명칭으로는 안 잡혔다. 이제 카드에
    // 보이는 해석 라벨로도 검색된다.
    const engine = new TranslationEngine();
    (engine as any).translations.set('tpl-x:ko', {
      layout_editor: {
        palette: {
          entry: {
            div: { label: '박스' },
            button: { label: '버튼요소' },
          },
        },
      },
    });
    render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'admin-host', locale: 'ko' }}
      >
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          componentPalette={{
            entries: {
              Div: { label: '$t:layout_editor.palette.entry.div.label' },
              Button: { label: '$t:layout_editor.palette.entry.button.label' },
            },
          }}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
          editorTemplateId="tpl-x"
          editorLocale="ko"
        />
      </TranslationProvider>
    );
    const search = screen.getByTestId('g7le-palette-search') as HTMLInputElement;
    // 표시 라벨 "박스" 로 검색 → Div 만 (name 은 'Div' 라 종전엔 미매칭)
    fireEvent.change(search, { target: { value: '박스' } });
    expect(screen.getByTestId('g7le-palette-item-Div')).toBeTruthy();
    expect(screen.queryByTestId('g7le-palette-item-Button')).toBeNull();
  });

  it('영문 컴포넌트명 검색은 그대로 동작 (회귀 가드)', () => {
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />
      )
    );
    const search = screen.getByTestId('g7le-palette-search') as HTMLInputElement;
    fireEvent.change(search, { target: { value: 'Pagination' } });
    expect(screen.getByTestId('g7le-palette-item-Pagination')).toBeTruthy();
    expect(screen.queryByTestId('g7le-palette-item-Div')).toBeNull();
  });
});

describe('ComponentPalette — 클릭 → onInsert (defaultNode 정식 발효)', () => {
  it('entry 의 defaultNode 가 정의되면 그 골격을 그대로 사용 (코어 폴백 우회)', () => {
    const onInsert = vi.fn();
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          componentPalette={{
            entries: {
              Div: {
                label: '$t:layout_editor.palette.div.label',
                requiresDefaultNode: true,
                defaultNode: {
                  type: 'basic',
                  name: 'Div',
                  props: { className: 'min-h-[40px] p-2 border border-dashed border-slate-300' },
                },
              },
            },
          }}
          targetParentPath={[2]}
          targetContainerName="Div"
          insertionIndex={3}
          onInsert={onInsert}
          onClose={vi.fn()}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-palette-item-Div'));
    expect(onInsert).toHaveBeenCalledTimes(1);
    const [node, parentPath, index] = onInsert.mock.calls[0];
    expect(node.name).toBe('Div');
    expect(node.id).toBeUndefined();
    // 템플릿 defaultNode 가 그대로 적용 — 코어가 minHeight/minWidth 부여하지 않음
    expect(node.props?.className).toBe('min-h-[40px] p-2 border border-dashed border-slate-300');
    expect(node.props?.style).toBeUndefined();
    expect(parentPath).toEqual([2]);
    expect(index).toBe(3);
  });

  it('entry 가 없으면 components.json props.default 폴백 — 코어는 시각 단서 부여하지 않음', () => {
    const onInsert = vi.fn();
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          targetParentPath={[2]}
          targetContainerName="Div"
          insertionIndex={3}
          onInsert={onInsert}
          onClose={vi.fn()}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-palette-item-Div'));
    expect(onInsert).toHaveBeenCalledTimes(1);
    const [node] = onInsert.mock.calls[0];
    expect(node.props?.className).toBe('p-4');
    // 코어는 더 이상 minHeight/minWidth 임시 강제하지 않음
    expect(node.props?.style).toBeUndefined();
  });

  it('requiresDefaultNode: true 인데 defaultNode 미정의 → 클릭 차단(onInsert 미호출)', () => {
    const onInsert = vi.fn();
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          componentPalette={{
            entries: {
              Button: {
                label: '$t:layout_editor.palette.button.label',
                requiresDefaultNode: true,
                // defaultNode 의도적으로 누락
              },
            },
          }}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={onInsert}
          onClose={vi.fn()}
        />
      )
    );
    const button = screen.getByTestId('g7le-palette-item-Button') as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.getAttribute('data-blocked')).toBe('true');
    fireEvent.click(button);
    expect(onInsert).not.toHaveBeenCalled();
    // 비활성 + 안내 배지 표시
    expect(screen.getByTestId('g7le-palette-item-Button-default-missing-badge')).toBeTruthy();
  });

  it('entry 에 defaultNode 미정의지만 requiresDefaultNode 도 미설정이면 추가 허용 + 안내 배지 표시', () => {
    const onInsert = vi.fn();
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          componentPalette={{
            entries: {
              Img: {
                label: '$t:layout_editor.palette.img.label',
                // requiresDefaultNode 미설정 → 차단 안 함
              },
            },
          }}
          targetParentPath={null}
          targetContainerName={null}
          insertionIndex={0}
          onInsert={onInsert}
          onClose={vi.fn()}
        />
      )
    );
    const img = screen.getByTestId('g7le-palette-item-Img') as HTMLButtonElement;
    expect(img.disabled).toBe(false);
    expect(img.getAttribute('data-blocked')).toBeNull();
    expect(screen.getByTestId('g7le-palette-item-Img-default-missing-badge')).toBeTruthy();
    fireEvent.click(img);
    expect(onInsert).toHaveBeenCalledTimes(1);
  });
});

describe('buildDefaultNode — 폴백 경로 (코어는 시각 단서 부여 안 함)', () => {
  it('props.default 가 있는 prop 만 골격에 포함, id 는 없음, style 미부여', () => {
    const node = buildDefaultNode({
      name: 'Button',
      type: 'basic',
      props: {
        variant: { type: 'string', default: 'primary' },
        label: { type: 'string' },
      },
    });
    expect(node.id).toBeUndefined();
    expect(node.name).toBe('Button');
    expect(node.props?.variant).toBe('primary');
    expect(node.props?.style).toBeUndefined();
    expect((node.props as Record<string, unknown>)?.minHeight).toBeUndefined();
  });

  it('props.default 가 전혀 없으면 props 자체가 부재 (코어 시각 단서 회수)', () => {
    const node = buildDefaultNode({ name: 'Img', type: 'basic' });
    expect(node.props).toBeUndefined();
  });
});
