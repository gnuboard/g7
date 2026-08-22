// e2e:allow 순수 선택 정규화 hook 의 단위 테스트 — DOM/네트워크 영향 없음(renderHook).
/**
 * useElementSelection — 확장 조각 통짜 선택 정규화 통합 테스트
 *
 * handleSelect/handleHover 가 확장 조각 내부 자식 DOM path 를 클릭받으면, 그 조각의
 * 진입점 path 로 selectedPath/hoverPath 를 정규화하는지 renderHook 으로 검증한다.
 * (브라우저: 확장 주입 영역 내부 자식을 클릭해도 조각 통짜가 선택되어 잠금/확장 편집
 *  어포던스가 일관 적용되어야 한다 — 결함 재발 가드)
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useElementSelection } from '../../hooks/useElementSelection';
import type { EditorNode } from '../../utils/layoutTreeUtils';

// 트리: root.children = [ base, route { extEntry(ext10) { extChild } } ]
//  DOM path 기준:
//   "0"                                  → base
//   "1.children.0"                       → extension 진입점(ext10)
//   "1.children.0.children.0"            → extension 자식(ext10)
//   "1.children.2"                       → 일반 route 노드
const rootNode: EditorNode = {
  children: [
    { name: 'BaseDiv', __source: { kind: 'base' } },
    {
      name: 'RouteWrap',
      __source: { kind: 'route' },
      children: [
        {
          name: 'ExtRoot',
          __source: { kind: 'extension', extensionId: 10 },
          children: [
            { name: 'ExtChild', __source: { kind: 'extension', extensionId: 10 } },
          ],
        },
        { name: 'Sibling', __source: { kind: 'route' } },
        { name: 'RouteLeaf', __source: { kind: 'route' } },
      ],
    },
  ],
};

function selectByPath(domPath: string) {
  return { dataset: { editorPath: domPath } as DOMStringMap };
}

describe('useElementSelection — 확장 조각 통짜 선택 정규화 ', () => {
  it('확장 조각 내부 자식 클릭 → 진입점 path 로 정규화 + lockKind=extension', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'route' }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0.children.0'));
    });
    // 내부 자식이 아니라 진입점("1.children.0")이 선택됨
    expect(result.current.selectedPath).toBe('1.children.0');
    expect(result.current.selectedLockKind).toBe('extension');
  });

  it('확장 진입점 직접 클릭도 진입점 유지', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'route' }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0'));
    });
    expect(result.current.selectedPath).toBe('1.children.0');
    expect(result.current.selectedLockKind).toBe('extension');
  });

  it('일반 route 노드 클릭은 정규화하지 않음 (lockKind=none)', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'route' }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.2'));
    });
    expect(result.current.selectedPath).toBe('1.children.2');
    expect(result.current.selectedLockKind).toBe('none');
  });

  it('hover 도 동일하게 진입점으로 정규화', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'route' }),
    );
    act(() => {
      result.current.handleHover('x', selectByPath('1.children.0.children.0'));
    });
    expect(result.current.hoverPath).toBe('1.children.0');
  });

  it('확장 편집 모드(currentExtensionId 일치)에서는 정규화 제외 — 자유 편집', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'extension', currentExtensionId: 10 }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0.children.0'));
    });
    // 편집 중 확장이면 내부 자식 그대로 선택(통짜 정규화 안 함)
    expect(result.current.selectedPath).toBe('1.children.0.children.0');
  });
});

// 별도 편집 모드에서 편집 대상과 무관한 잠긴 노드는 클릭·hover 가 무시되어야 한다.
describe('useElementSelection — 별도 모드 무관 노드 선택 차단', () => {
  it('확장 편집 모드: base 노드 클릭 → 선택 안 됨(차단)', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'extension', currentExtensionId: 10 }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('0')); // base
    });
    expect(result.current.selectedPath).toBeNull();
  });

  it('확장 편집 모드: 현재 라우트 본체 노드 클릭 → 선택 안 됨(차단)', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'extension', currentExtensionId: 10 }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.2')); // route leaf
    });
    expect(result.current.selectedPath).toBeNull();
  });

  it('확장 편집 모드: hover 도 무관 노드는 점선 표시 안 함', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'extension', currentExtensionId: 10 }),
    );
    act(() => {
      result.current.handleHover('x', selectByPath('0')); // base
    });
    expect(result.current.hoverPath).toBeNull();
  });

  it('확장 편집 모드: 편집 중 확장 조각은 정상 선택', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'extension', currentExtensionId: 10 }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0')); // ext10 root
    });
    expect(result.current.selectedPath).toBe('1.children.0');
  });

  it('route 모드: base 노드 클릭은 종전대로 선택됨(차단 안 함 — 진입 어포던스 보존)', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'route' }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('0')); // base
    });
    expect(result.current.selectedPath).toBe('0');
    expect(result.current.selectedLockKind).toBe('base');
  });

  it('base 편집 모드: base 본체 노드는 선택, 주입 확장은 차단', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode, editMode: 'base' }),
    );
    // base 모드는 base 본체(kind:route 로 태깅됨)가 편집 대상 → 선택 허용
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.2')); // route leaf = base 본체
    });
    expect(result.current.selectedPath).toBe('1.children.2');
    // 주입 확장(ext10)은 차단
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0'));
    });
    // 차단되어 직전 선택이 유지됨
    expect(result.current.selectedPath).toBe('1.children.2');
  });
});

// path 기반 편집 대상 모드(iteration_item / modal). editableRootPath 노드와 그
// 자손만 선택 가능하고 나머지 호스트 전체는 차단(확장 편집 출처 잠금과 동형의 path 잠금).
// 확장 출처가 없는 깨끗한 트리(pathTree)로 검증 — 확장 노드는 통짜 정규화가 끼어들어 path
// 기반 잠금 검증과 직교한다.
//   "0"            → header (route)
//   "1"            → list (route, 편집 대상 컨테이너)
//   "1.children.0" → list child (route)
//   "1.children.0.children.0" → list grandchild (route)
//   "2"            → footer (route)
const pathTree: EditorNode = {
  children: [
    { name: 'Header', __source: { kind: 'route' } },
    {
      name: 'List',
      __source: { kind: 'route' },
      children: [
        { name: 'Row', __source: { kind: 'route' }, children: [{ name: 'Cell', __source: { kind: 'route' } }] },
      ],
    },
    { name: 'Footer', __source: { kind: 'route' } },
  ],
};

describe('useElementSelection — path 기반 모드 선택 잠금', () => {
  it('iteration_item: editableRootPath 노드는 선택', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode: pathTree, editMode: 'iteration_item', editableRootPath: [1] }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1'));
    });
    expect(result.current.selectedPath).toBe('1');
  });

  it('iteration_item: editableRootPath 자손도 선택', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode: pathTree, editMode: 'iteration_item', editableRootPath: [1] }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0.children.0'));
    });
    expect(result.current.selectedPath).toBe('1.children.0.children.0');
  });

  it('iteration_item: 편집 대상 밖 노드는 차단', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode: pathTree, editMode: 'iteration_item', editableRootPath: [1] }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('0')); // header — 대상 밖
    });
    expect(result.current.selectedPath).toBeNull();
    act(() => {
      result.current.handleSelect('', selectByPath('2')); // footer — 대상 밖
    });
    expect(result.current.selectedPath).toBeNull();
  });

  it('modal: editableRootPath(모달 노드)와 자손만 선택', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode: pathTree, editMode: 'modal', editableRootPath: [1] }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0')); // 모달 자손
    });
    expect(result.current.selectedPath).toBe('1.children.0');
    act(() => {
      result.current.handleSelect('', selectByPath('0')); // 호스트 — 차단
    });
    // 직전 선택 유지
    expect(result.current.selectedPath).toBe('1.children.0');
  });

  it('editableRootPath 부재 시 폴백 — 차단하지 않음(전체 선택 가능)', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode: pathTree, editMode: 'iteration_item', editableRootPath: null }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('0'));
    });
    expect(result.current.selectedPath).toBe('0');
  });

  // 편집 대상(editableRootPath 자손) lockKind 정교화.
  // 조상 iteration 때문에 isDataBoundNode 가 항목 내부를 전부 data_bound 로 잡으므로, 평문/컨테이너는
  // none(편집 가능)으로 덮되, **노드 자신이 바인딩**인 것은 data_bound 로 유지해 "데이터 영역 편집 불가"
  // 안내가 일반 편집기와 동일하게 뜨도록 한다.
  const d34Tree: EditorNode = {
    children: [
      { name: 'Header', __source: { kind: 'route' } },
      {
        name: 'List',
        __source: { kind: 'route' },
        iteration: { source: '{{posts.data}}' },
        children: [
          {
            name: 'Row',
            __source: { kind: 'route' },
            // 평문 라벨(편집 가능) + 바인딩 텍스트(데이터 영역) 자식.
            children: [
              { name: 'Label', __source: { kind: 'route' }, text: '카테고리' }, // 평문
              { name: 'Title', __source: { kind: 'route' }, text: '{{post.title}}' }, // 바인딩
            ],
          },
        ],
      },
    ],
  };

  it('iteration_item: 편집 대상 안의 평문 노드는 none(편집 가능)', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode: d34Tree, editMode: 'iteration_item', editableRootPath: [1] }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0.children.0')); // Label(평문)
    });
    expect(result.current.selectedPath).toBe('1.children.0.children.0');
    expect(result.current.selectedLockKind).toBe('none'); // 평문 → 편집 가능
  });

  it('iteration_item: 편집 대상 안의 데이터 바인딩 노드는 data_bound(편집 불가 안내)', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode: d34Tree, editMode: 'iteration_item', editableRootPath: [1] }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0.children.1')); // Title({{post.title}})
    });
    // 선택은 허용(안내를 띄우기 위해) + lockKind=data_bound 로 "데이터 영역 편집 불가" 가시.
    expect(result.current.selectedPath).toBe('1.children.0.children.1');
    expect(result.current.selectedLockKind).toBe('data_bound');
  });

  it('iteration_item: 컨테이너 Row(자식이 바인딩 보유)는 자신이 바인딩 아니므로 none', () => {
    const { result } = renderHook(() =>
      useElementSelection({ rootNode: d34Tree, editMode: 'iteration_item', editableRootPath: [1] }),
    );
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0')); // Row(컨테이너)
    });
    expect(result.current.selectedLockKind).toBe('none');
  });
});

// 공통 레이아웃 파일명 / 데이터소스 출처 라벨
//  "0"                       → base 노드(layout: _user_base)
//  "1"                       → iteration 정의 노드(source: {{recent_posts?.data}})
//  "1.children.0"            → iteration 자식(데이터 행 — data_bound)
//  "2.children.0"            → 일반 route 노드(라벨 없음)
//  "3"                       → props 바인딩 노드(value={{profile.email}} + 상태루트 혼재)
//  "4"                       → 상태루트(_local)만 바인딩한 노드
const labelTree: EditorNode = {
  children: [
    { name: 'BaseHeader', __source: { kind: 'base', layout: '_user_base' } },
    {
      name: 'PostList',
      __source: { kind: 'route' },
      iteration: { source: '{{recent_posts?.data}}' },
      children: [{ name: 'PostRow', __source: { kind: 'route' } }],
    },
    {
      name: 'Plain',
      __source: { kind: 'route' },
      children: [{ name: 'PlainChild', __source: { kind: 'route' } }],
    },
    {
      // props 바인딩만으로 data_bound — 상태루트(_local)보다 실제 데이터소스(profile) 우선
      name: 'Input',
      __source: { kind: 'route' },
      props: {
        value: '{{profile.email}}',
        disabled: '{{_local?.isRegistering}}',
      },
    },
    {
      // 상태루트만 바인딩 — 폴백으로 _local 표시(데이터소스 후보 없음)
      name: 'Input',
      __source: { kind: 'route' },
      props: { error: '{{_local.errors?.email?.[0] ?? ""}}' },
    },
  ],
};

describe('useElementSelection — 식별 라벨 (base 파일명 / 데이터소스 출처)', () => {
  it('base 노드 선택 → selectedBaseLayout 에 레이아웃 파일명', () => {
    const { result } = renderHook(() => useElementSelection({ rootNode: labelTree, editMode: 'route' }));
    act(() => {
      result.current.handleSelect('', selectByPath('0'));
    });
    expect(result.current.selectedLockKind).toBe('base');
    expect(result.current.selectedBaseLayout).toBe('_user_base');
    // data_bound 가 아니므로 데이터소스 라벨은 없음
    expect(result.current.selectedDataSourceId).toBeNull();
  });

  it('iteration 정의 노드 선택 → data_bound + selectedDataSourceId 추출 + 반복 진입 가능', () => {
    const { result } = renderHook(() => useElementSelection({ rootNode: labelTree, editMode: 'route' }));
    act(() => {
      result.current.handleSelect('', selectByPath('1'));
    });
    expect(result.current.selectedLockKind).toBe('data_bound');
    expect(result.current.selectedDataSourceId).toBe('recent_posts');
    expect(result.current.selectedBaseLayout).toBeNull();
    // 반복 항목 편집 모드 진입 어포던스 — iteration 정의 노드 자신이 원본 path
    expect(result.current.selectedIsIteration).toBe(true);
    expect(result.current.selectedIterationSourcePath).toBe('1');
  });

  it('iteration 자식(데이터 행) 선택 → 조상 source 에서 데이터소스 식별자 추출', () => {
    const { result } = renderHook(() => useElementSelection({ rootNode: labelTree, editMode: 'route' }));
    act(() => {
      result.current.handleSelect('', selectByPath('1.children.0'));
    });
    expect(result.current.selectedLockKind).toBe('data_bound');
    expect(result.current.selectedDataSourceId).toBe('recent_posts');
  });

  it('펼침 인스턴스(.iteration.N) path 선택 → 원본 노드 path 로 절단해 진입 출처 제공', () => {
    const { result } = renderHook(() => useElementSelection({ rootNode: labelTree, editMode: 'route' }));
    act(() => {
      // DynamicRenderer 가 펼친 데이터 행 — iteration 보유 노드(path '1')의 인스턴스는
      // '1.iteration.N'. 원본 노드 path 는 .iteration. 직전 = '1'.
      result.current.handleSelect('', selectByPath('1.iteration.0.children.0'));
    });
    expect(result.current.selectedIsIteration).toBe(true);
    expect(result.current.selectedIterationSourcePath).toBe('1');
  });

  it('일반 route 노드 선택 → 라벨 모두 null', () => {
    const { result } = renderHook(() => useElementSelection({ rootNode: labelTree, editMode: 'route' }));
    act(() => {
      result.current.handleSelect('', selectByPath('2.children.0'));
    });
    expect(result.current.selectedLockKind).toBe('none');
    expect(result.current.selectedBaseLayout).toBeNull();
    expect(result.current.selectedDataSourceId).toBeNull();
  });

  it('props 바인딩 노드(iteration 아님) → 실제 데이터소스(profile)를 상태루트보다 우선 추출', () => {
    const { result } = renderHook(() => useElementSelection({ rootNode: labelTree, editMode: 'route' }));
    act(() => {
      result.current.handleSelect('', selectByPath('3'));
    });
    expect(result.current.selectedLockKind).toBe('data_bound');
    // value={{profile.email}} 가 disabled={{_local?...}} 보다 우선
    expect(result.current.selectedDataSourceId).toBe('profile');
    // props 바인딩만의 data_bound 는 반복이 아니므로 반복 항목 편집 어포던스 미제공
    expect(result.current.selectedIsIteration).toBe(false);
    expect(result.current.selectedIterationSourcePath).toBeNull();
  });

  it('상태루트(_local)만 바인딩한 노드 → 폴백으로 _local 표시', () => {
    const { result } = renderHook(() => useElementSelection({ rootNode: labelTree, editMode: 'route' }));
    act(() => {
      result.current.handleSelect('', selectByPath('4'));
    });
    expect(result.current.selectedLockKind).toBe('data_bound');
    expect(result.current.selectedDataSourceId).toBe('_local');
  });
});
