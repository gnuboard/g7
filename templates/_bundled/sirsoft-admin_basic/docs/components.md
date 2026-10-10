# Admin Basic — 컴포넌트

> 템플릿이 제공하는 컴포넌트 · 진입점: [AGENTS.md](../AGENTS.md)

## 제공 컴포넌트

<!-- @generated:components START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
컴포넌트 134개 (루트: `src/components`).

| 분류 | 개수 |
|---|---|
| `basic` | 39개 |
| `composite` | 88개 |
| `layout` | 5개 |
| `modals` | 1개 |
| `shared` | 1개 |
<!-- @generated:components END -->

## 상태 의미 토큰 (`status-*`)

`Icon` 은 Font Awesome 글리프 이름 외에 **아이콘 라이브러리 중립 토큰**을 받는다. 서버(예:
쇼핑몰 모듈의 클레임·주문 상태 Enum)는 특정 아이콘 글꼴을 알지 않고 `status-request` · `status-pickup`
같은 의미 토큰만 내보내며, 그 토큰을 이 템플릿의 글리프로 바꾸는 표는
`templates/_bundled/sirsoft-admin_basic/src/components/basic/IconTypes.ts` 의 `iconNameMap` 한 곳이다. 어휘(21종 — 클레임 15 + 주문 전용 6)와 각 토큰의 뜻은
쇼핑몰 모듈 API 문서(`modules/_bundled/sirsoft-ecommerce/docs/api/admin-claims.md` "아이콘 의미 토큰 어휘")가 소유한다.

표에 없는 토큰은 `fa-status-…` 로 떨어져 오류 없이 빈 네모가 그려진다 — 어휘가 늘면 이 표에
먼저 더한다. 다른 아이콘 세트를 쓰는 템플릿은 같은 토큰을 자기 글리프로 해석하면 된다.

<!-- @intent START -->
세 분류(basic/composite/layout)는 "얼마나 많이 조합됐는가"로 나뉩니다 — basic 은 HTML 태그를
그대로 래핑(`Div`→`<div>`)한 최소 단위, composite 는 basic 을 여러 개 조합해 UI 패턴을 캡슐화한
것(`DataGrid`가 `Table`+`Pagination`+정렬 로직을 감싸는 식), layout 은 페이지 구조 자체(Grid/
Flex/Container)를 정의합니다. 레이아웃 JSON 작성자는 이 구분을 몰라도 되지만("HTML 태그
직접 사용 금지" 규정만 지키면 됨), 컴포넌트를 새로 추가하는 쪽은 이 구분에 맞는 디렉토리
(`src/components/{basic,composite,layout}/`)에 넣어야 `components.json` 카탈로그와
`editor-spec.json` 팔레트 분류가 어긋나지 않습니다.

위 개수는 코드에서 실측되므로 시간이 지나면 달라집니다 — 이 문서에 구체적인 개수를 하드코딩해
적지 않습니다(과거 버전 문서가 37/66/8 로 적었다가 실제로는 39/80/5+modals 1 이 되어 있었던
사례가 있습니다). 정확한 전체 목록·Props 는 [component-props.md](../../../../docs/frontend/component-props.md)
· [component-props-composite.md](../../../../docs/frontend/component-props-composite.md) 를
따라갑니다 — 이 문서는 "이 템플릿에서만" 유효한 계약(필수 컴포넌트·AdminSidebar·SlotContainer)
만 다룹니다.

이 템플릿에서만 유효한 계약 셋을 덧붙입니다. `StatusBadge` 의 상태 어휘(`StatusType`)는 도메인
Enum 의 `variant()` 가 실제로 내려보내는 값(`danger` · `primary` · `secondary` · `dark` 포함)을
전부 담아야 합니다 — 유니언에 없는 값은 스타일 조회가 `undefined` 가 되어 배경·글자색·테두리가
통째로 사라지고, 레이아웃 JSON 은 TS 타입 검사 밖이라 컴파일에도 걸리지 않습니다.
`ColumnSelector` 의 `onColumnVisibilityChange` 는 두 번째 인자로 발화 출처
(`{ source: 'user' | 'init' | 'dynamic' }` — 사람이 고른 것 · 저장값 복원 · 새 컬럼 자동 포함)를
넘기므로, 저장 여부를 출처로 가려야 하는 화면은 그 인자를 읽습니다.
`SearchableDropdown` 의 다중 선택 트리거 문구는 `selectedCountLabel` 로 넘깁니다 — 화면이 주지
않으면 `{count} selected` 라는 영어가 그대로 섭니다. 컴포넌트는 언어 파일을 읽지 않으므로(레이아웃
JSON 의 `$t:` 로만 번역이 들어옵니다) 이 칸을 비운 화면은 한국어·일본어 운영자에게 영어 문구를
보입니다. 실제로 회원 배송지 목록의 회원 다중선택이 「2 selected」 로 서 있던 것을 브라우저
실측에서 발견해 이 prop 을 신설했습니다(2026-09-21). `{count}` 자리에 고른 개수가 들어갑니다.
<!-- @intent END -->

## 필수 컴포넌트 (모듈 호환성)

모듈 개발자가 아래 컴포넌트만 사용하면 **다른 Admin 템플릿으로 교체해도 화면이 깨지지
않습니다**. 목록의 SSoT 는 `config/template.php` 의 `required_admin_components` 이며, 아래
표는 그 값을 그대로 옮긴 것입니다(코드가 SSoT — 표와 설정이 어긋나면 설정이 맞습니다).

| 분류 | 컴포넌트 |
|---|---|
| Basic (27개) | `A`, `Button`, `Checkbox`, `Div`, `Form`, `H1`, `H2`, `H3`, `Icon`, `Img`, `Input`, `Label`, `Li`, `Nav`, `P`, `Section`, `Select`, `Span`, `Svg`, `Table`, `Tbody`, `Td`, `Textarea`, `Th`, `Thead`, `Tr`, `Ul` |
| Composite (8개) | `Alert`, `Badge`, `Card`, `DataTable`, `FormField`, `Modal`, `PageHeader`, `Pagination` |

```text
✅ 필수 컴포넌트 목록에 있는 것만 사용 (다른 Admin 템플릿 호환 보장)
✅ 템플릿의 베이스 레이아웃(`_admin_base`)을 extends
❌ 이 템플릿에만 있는 커스텀 컴포넌트 사용 금지
```

기본은 `validate_on_install: false` — 설치 시 필수 컴포넌트 검증은 기본적으로 수행되지 않고
경고에 그칩니다(`block_on_failure: false`). 검증을 강제하려면 두 설정을 함께 켭니다.

## AdminSidebar 상세

계층형 관리자 메뉴를 그리는 컴포넌트로, 데이터 소스로 받은 메뉴 트리를 그대로 넘기면 됩니다.

```typescript
interface MenuItem {
  id: string | number;
  name: string | { ko: string; en: string };
  slug: string;
  url?: string | null;
  icon?: string;              // FontAwesome 클래스 (예: "fas fa-home")
  children?: MenuItem[];
  is_active?: boolean;
  module_id?: number | null;  // 모듈이 등록한 메뉴인지 식별
}

interface AdminSidebarProps {
  logo?: string;
  logoAlt?: string;
  menu: MenuItem[];           // 필수
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  className?: string;
  currentLocale?: string;     // 미지정 시 G7Core.locale.current() 자동 사용
  id?: string;                // 레이아웃 편집기 코어 일괄 ID
}
```

아이콘은 `Icon` 컴포넌트(IconName enum)가 아니라 `I` 컴포넌트 + FontAwesome 클래스 문자열로
렌더링됩니다 — 메뉴 API 가 `icon` 필드를 이미 `"fas fa-home"` 형태 클래스 문자열로 내려주기
때문입니다. 새 메뉴 아이콘을 추가할 때 `Icon name="home"` 식으로 쓰면 렌더되지 않습니다.

메뉴 `url` 은 엔진의 `navigate` 로 이동하고, 엔진이 없을 때만 전체 새로고침으로 폴백합니다. 폴백은
같은 출처 주소(`/` 하나로 시작하는 경로 또는 같은 출처의 절대 주소)만 따라가며, 외부 주소·`//`·`/\`
로 시작하는 주소는 이동하지 않습니다 — 메뉴 데이터에 외부 주소가 섞여도 관리자 화면 밖으로 나가지 않게 하는 장치입니다.

## SlotContainer 상세

동적 슬롯 렌더링 컨테이너입니다 — 다른 컴포넌트가 `slot` prop 으로 자신이 속할 슬롯 ID 를
표현식으로 지정하면(예: `"{{_local.isVisible ? 'basic_filters' : 'detail_filters'}}"`), 그
표현식이 상태 변화에 따라 재평가되어 컴포넌트가 슬롯 사이를 동적으로 이동합니다.

```typescript
interface SlotContainerProps {
  slotId: string;    // 필수 — 렌더링할 슬롯 ID
  className?: string;
}
```

```html
<div data-ref="category_filter" data-slot="{{_local.isVisible ? 'basic_filters' : 'detail_filters'}}" data-slot-order="1"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{ "id": "category_filter", "type": "basic", "name": "Div", "slot": "{{_local.isVisible ? 'basic_filters' : 'detail_filters'}}", "slotOrder": 1, "children": [] }
```

```html
<slot-container data-ref="basic_filters_container" slotId="basic_filters"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{ "id": "basic_filters_container", "type": "composite", "name": "SlotContainer", "props": { "slotId": "basic_filters" } }
```

## 코드 편집기 컴포넌트 상세

관리자 코드 편집 화면(`admin_template_layout_edit`)이 쓰는 네 컴포넌트입니다. 넷이 한 벌로
움직이므로 함께 읽는 편이 빠릅니다 — `FileTreePicker` 가 `FileTree` 를 담고, `CodeWorkspace` 가
`FileTabs` 와 `CodeEditor` 를 담습니다.

### 왜 컴포넌트가 상태를 갖는가

이 템플릿의 다른 컴포넌트는 대부분 표시만 하고 상태는 레이아웃의 전역이 쥡니다. 코드 편집기는
예외입니다 — **편집 버퍼를 전역으로 표현할 수 없기 때문**입니다.

- 파일명에 `.` 과 `/` 가 들어가 전역의 점 표기 키로 쓸 수 없습니다
  (`_global.buffers.partials/a/_b` 같은 키는 성립하지 않습니다).
- 전역 한 벌로 두면 파일을 바꿀 때 `refetch-data-source` 응답이 그것을 덮습니다. 실제로 그것이
  저장하지 않은 수정이 사라지던 원인이었습니다(#476).

그래서 **I/O 는 선언형으로, 버퍼·상호작용은 컴포넌트로** 나눕니다. 조회·저장·생성·삭제는 전부
레이아웃의 `data-source`/`api-call` 이 하고, 컴포넌트는 `fetch` 를 직접 부르지 않습니다.

### FileTree

평면 파일 목록을 폴더 트리로 세웁니다. **트리 변환은 컴포넌트가 하므로 백엔드 응답 스키마는
바꾸지 않습니다** — 이름의 `/` 가 곧 폴더이고, 파일이 사라지면 빈 폴더도 함께 사라집니다.

```typescript
interface FileTreeNode {
  key: string;                    // 'layout:partials/a/_b' | 'ext:12'
  kind: 'layout' | 'extension';
  path: string;                   // 'partials/a/_b'
  label?: string;                 // 없으면 path 의 마지막 세그먼트
  group?: string;                 // 확장 조각의 묶음 라벨 (모듈·플러그인 이름)
  groupKind?: 'module' | 'plugin';
  badges?: Array<{ text: string; tone?: 'amber' | 'indigo' | 'red' | 'gray' }>;
  meta?: { size?: string; updatedAt?: string; disabled?: boolean };
}
```

| prop | 타입 | 설명 |
| --- | --- | --- |
| `nodes` | `FileTreeNode[]` | 평면 파일 목록 |
| `selectedKey` | `string` | 지금 열려 있는 파일 |
| `dirtyKeys` | `string[]` | 미저장 파일 — ● 점 + 굵기 + `aria-label` 세 겹 |
| `searchKeyword` | `string` | 파일명 필터·강조 (**서버를 부르지 않는다**) |
| `contentMatches` | `Array<{key, count, lines}>` | 서버 내용 검색 결과 |
| `pendingFolders` | `string[]` | 아직 파일이 없는 가상 폴더 |
| `rowSize` | `'compact' \| 'touch'` | 행 높이 32 / 44px |
| `storageKey` | `string` | 접힘 상태를 적어 둘 localStorage 키 |
| `canManage` | `boolean` | 이름변경·삭제 버튼 노출 (권한) |
| `onSelect` | `(key, revealAt?) => void` | 파일 선택 — 내용 매치를 누르면 좌표가 함께 온다 |

**접근성** — `role="tree"` 규약을 지키고 `tabindex` 는 roving 입니다(활성 노드 하나만 `0`).
144개 파일이 전부 `0` 이면 Tab 으로 트리를 빠져나가는 데만 144번을 눌러야 합니다.
`↑↓` 이동 / `→` 펼침 / `←` 접기·부모 / `Enter`·`Space` 열기 / `Home`·`End`.

### FileTreePicker

같은 트리를 기기 폭에 맞는 그릇에 담습니다. **형태 분기가 컴포넌트 안에 있으므로 레이아웃에는
트리가 하나만 있습니다** — 종전에는 데스크톱 목록과 모바일 선택기 두 벌이었고, 한쪽에만 검색이
있었습니다.

| 폭 | 형태 |
| --- | --- |
| ≥ 1024 | 인라인 — 좌측 상시 노출, 드래그로 240~480px 조절 후 기억 |
| 768~1023 | 서랍 — 좌측 오버레이, 파일을 고르면 스스로 닫힘, 포커스 트랩 |
| < 768 | 시트 — 전체 화면, 행 44px 터치 타깃 |

경계 판정은 `window.innerWidth` 입니다. `matchMedia` 는 devicePixelRatio 가 정수가 아닌 환경에서
경계 1px 이 어긋나 1023/1024 에서 형태가 뒤집힙니다(`docs/frontend/responsive-layout.md` 실측).

서랍은 왼쪽에서 200ms 슬라이드해 들어오고 시트는 아래에서 올라옵니다(`main.css` 의
`g7le-drawer-enter`/`g7le-sheet-enter`, 움직임 축소 설정이면 즉시). `focusSearchNonce` 가 오를 때마다
검색창에 포커스를 줍니다 — 단축키 Ctrl+K(파일 빠른 이동)·Ctrl+Shift+F(내용 검색)가 레이아웃의 전역
카운터를 1 올려 넘기는 자리이고, 서랍·시트면 먼저 엽니다.

| Prop | 타입 | 설명 |
| --- | --- | --- |
| `nodes` · `selectedKey` · `dirtyKeys` · `searchKeyword` · `contentMatches` · `pendingFolders` | `FileTree` 와 동일 | 안쪽 `FileTree` 로 그대로 전달 |
| `mode` | `'auto' \| 'inline' \| 'drawer' \| 'sheet'` | 기본 `auto` — 창 폭으로 고른다(위 표) |
| `storageKey` | `string` | 펼침 상태·트리 폭을 적어 둘 localStorage 키 접두 — **`g7le.` 로 시작해야 한다**(GDPR 동의 플러그인 허용 목록 밖 키는 저장되지 않거나 부팅 때 정리된다). `CodeWorkspace.draftNamespace` 도 같은 규약 |
| `canManage` | `boolean` | 파일 조작 툴바(새 파일·새 폴더)·행 메뉴 노출 여부 |
| `isLoading` | `boolean` | 목록 로딩 — 트리 자리에 스켈레톤 6행 |
| `searchFileCount` · `searchMatchCount` | `number` | 내용 검색 요약(«N건 · M파일»). 수치로 받는 이유는 `$t:` 파이프 치환이 리터럴 템플릿에서만 동작하기 때문 |
| `focusSearchNonce` | `number` | 값이 오를 때마다 검색창 포커스(서랍·시트면 먼저 연다) |
| `onSelect` | `(key, revealAt?) => void` | 파일·매치 줄 선택 |
| `onSearch` | `(keyword) => void` | 검색어 변경 |
| `onCreateFile` · `onCreateFolder` | `(parentPath) => void` | 툴바·행 메뉴의 생성 |
| `onRename` · `onDelete` | `(key) => void` | 행 메뉴의 이름변경·삭제 |
| `className` · `id` · `editorAttrs` | — | 공통 |

### FileTabs

열린 파일 탭 줄입니다. 편집기가 한 번에 한 파일만 보여 주더라도 **무엇이 열려 있고 그중 무엇이
저장되지 않았는지**는 늘 보여야 합니다. 미저장 표시는 트리와 같은 규약(점 + `aria-label`)을 씁니다.

```typescript
interface FileTabItem {
  key: string;
  label: string;
  title?: string;   // 전체 경로 (툴팁)
  dirty?: boolean;
  missing?: boolean; // 서버에 더 이상 없는 파일 — 취소선 + aria-label
}
```

| Prop | 타입 | 설명 |
| --- | --- | --- |
| `tabs` | `FileTabItem[]` | 열린 파일. 비어 있으면 아무것도 그리지 않는다 |
| `activeKey` | `string` | 활성 탭 — 바뀌면 그 탭을 시야로 끌어온다 |
| `size` | `'compact' \| 'touch'` | `h-9` / 터치 `h-10`. 탭 폭은 `max-w-[180px]` + 말줄임 |
| `onSelect` | `(key) => void` | 탭 클릭·Enter |
| `onClose` | `(key) => void` | 닫기 버튼(탭 선택을 함께 일으키지 않는다) |
| `className` · `id` | — | 공통 |

토큰(§7.6): 활성 `bg-white dark:bg-gray-800` + 파랑 2px 밑줄(inset shadow — border 는 탭 높이를 갈리게 한다),
비활성 `text-gray-600 dark:text-gray-400`, 줄 배경 `bg-gray-50 dark:bg-gray-900`.

### CodeWorkspace

**파일별 편집 버퍼의 주인**입니다. 서버 본문은 `serverValue` prop 으로 들어오기만 하고, 편집 중인
값은 컴포넌트 안의 `Map<key, buffer>` 가 소유합니다.

| 상황 | 동작 |
| --- | --- |
| 처음 들어온 키 | `serverValue` 로 버퍼 생성 (clean) |
| 재방문 · dirty | **`serverValue` 를 무시하고 버퍼를 보여준다** |
| 재방문 · clean | `serverValue` 로 갱신 (남이 고친 최신본 반영) |
| 서버 본문이 내 편집분과 같아짐 | 내 저장이 반영된 것 — 기준선을 옮겨 clean 전환 |
| `serverRevision` 증가 + 내용도 다름 | 충돌 바 — 자동 덮어쓰기 금지. [내 것 유지] / [서버 것으로] / [차이 보기](서버 본문 ↔ 내 편집을 `DiffEditor` 로 나란히) |
| 탭 닫기 · dirty | **3지선다** — [저장하고 닫기] / [저장하지 않고 닫기] / [취소]. «저장하고 닫기» 는 그 탭을 활성으로 올려 `onSave` 를 한 번 부르고 clean 이 된 뒤 닫는다. 저장이 실패하면 닫지 않고 창이 남는다 |
| 본문 미도착 (버퍼 없음 + `serverKey` ≠ `fileKey`) | 편집기 위에 «불러오는 중» 오버레이 — 편집기는 언마운트하지 않는다 |
| 탭 상한 초과 | 오래된 **clean** 탭부터 해제. dirty 탭은 절대 자동으로 닫지 않는다 |
| `knownKeys` 에 없는 키 | **없는 파일** — 서버를 부르지 않고 버퍼를 읽기 전용으로 보여 준다 |

| prop | 설명 |
| --- | --- |
| `fileKey` | 지금 서버가 준 파일의 키 |
| `serverValue` / `serverRevision` | 그 파일의 서버 본문과 판본(`lock_version`) |
| `onChange` | 활성 버퍼가 바뀔 때마다 — **파일을 열 때도 불린다** |
| `onUserEdit` | **사람이 고쳤을 때만** — 서버 왕복은 여기에 건다 |
| `onDirtyChange` | 미저장 키 목록 — 트리의 ● 점이 읽는다 |
| `onRequestSelect` | 탭 클릭 — 레이아웃이 그 파일을 다시 받는다 |
| `knownKeys` | 지금 서버에 **실제로 있는** 키 전체. 비면 아무것도 «없는 파일» 로 보지 않는다 |
| `onSaveAs` | 없는 파일의 복원분을 새 이름으로 살릴 때 — 본문을 실어 생성 흐름으로 넘긴다 |

**없는 파일(`knownKeys` 밖의 키).** 초안은 파일보다 오래 삽니다 — 지우거나 이름을 바꾼 뒤에도,
남이 지운 뒤에도 남습니다. 그래서 복원이 «없는 파일» 의 탭을 세우고, 그 탭을 누르면 404 가 났습니다
(브라우저 실측 2026-09-12 — 편집기 길이 0, 미저장 ● 없음, 초안은 저장소에 그대로). 지금은 탭에
취소선과 `aria-label` 을 붙이고, **서버를 부르지 않으며**, 복원분을 읽기 전용으로 보여 준 뒤
[새 이름으로 저장] / [버리기] 를 줍니다 — 조용히 버리면 사용자가 쓴 글이 사라지기 때문입니다.

**초안 저장소는 공유 자원입니다.** `useFileDrafts` 는 쓸 때마다 `localStorage` 를 다시 읽어
병합하고, 이 문서가 지운 키만 덜어냅니다. 통째로 덮어쓰면 편집기를 두 탭에 띄웠을 때 한쪽이 다른
쪽 초안을 말없이 지웁니다. 초안 수명은 **7일**입니다.

`onChange` 와 `onUserEdit` 를 가르는 이유: `onChange` 에 서버 변환을 걸면 파일을 열 때마다 변환이
돌고, 본문이 도착하기 전 빈 값으로 먼저 나가 422 가 납니다(브라우저 실측 2026-09-12).

단축키 SSoT 는 `src/spec/codeEditorShortcuts.ts` 한 곳이고, 매칭(`useCodeEditorShortcuts`)과
도움말 오버레이가 같은 배열을 읽습니다 — 단축키 추가 = 한 곳 수정.

**글자 크기.** `Ctrl + =` / `Ctrl + -`(`fontSizeUp`/`fontSizeDown`, Monaco 안에서도 재바인딩)로 12~20px
사이를 한 단계씩 움직이고, 고른 값은 `<draftNamespace>.fontSize` 에 적혀 기기 기본값(데스크톱 14 / 모바일 13)보다
우선합니다. 적힌 값이 없을 때만 창 폭에 따라 기본값을 따라갑니다.

### CodeEditor (변경)

Monaco 래퍼입니다. 코드 편집기 개편에서 다섯 prop 이 늘었습니다.

| prop | 설명 |
| --- | --- |
| `theme="auto"` (기본) | `<html class="dark">` 를 `MutationObserver` 로 추종. 종전 기본값은 `vs-dark` 하드코딩이라 라이트 모드에서도 편집기만 검은 판이었다 |
| `wordWrap` | 자동 줄바꿈. 폴백 textarea 의 `wrap` 도 함께 따른다 |
| `fontSize` | 글자 크기 (12~20) |
| `modelKey` | 파일별 Monaco 모델 키. `<Editor path>` 로 넘어가 커서·스크롤·되돌리기 스택이 파일별로 보존된다 |
| `revealAt` | 커서를 옮길 좌표 `{line, column?, nonce?}` |
| `commands` | 편집기 안에서 잡을 단축키 — Monaco 가 선점하는 조합의 재바인딩 |

> 모델을 직접 만들어 `setModel()` 하지 않습니다. `@monaco-editor/react` 가 자기 모델을 만들어
> 붙이므로 밖에서 끼운 모델이 곧바로 밀려납니다(실측: 본문 42,523자가 전역에는 있는데 편집기는
> 빈 화면이었다). 모델 수명은 라이브러리 한 곳이 쥡니다.

## 이관 원문 상세

> 아래는 코어 `docs/frontend/templates/sirsoft-admin_basic/components.md` 에 있던 원문을
> 이 문서로 옮긴 것입니다(#601). 이관 시점 그대로 보존하되, **코드가 SSoT 인 값과 어긋나는
> 부분에는 정정 주석**을 달았습니다 — 실측 총계는 위 「제공 컴포넌트」 블록이, 필수 컴포넌트
> 목록은 `config/template.php` 의 `required_admin_components` 가 SSoT 입니다.

### 컴포넌트 개요

> **정정(#601)**: 아래 개수는 이관 시점(2026-03) 문서 값입니다. 코드 실측은 basic 39 · composite 80 ·
> layout 5 · modals 1 = **125종**이며 위 「제공 컴포넌트」 블록이 SSoT 입니다. 아래 목록에 없는
> 컴포넌트가 있을 수 있습니다.

| 타입 | 개수 | 설명 |
|------|------|------|
| Basic | 37 | HTML 태그 래핑 — 최소 단위 컴포넌트 |
| Composite | 66 | 기본 컴포넌트 조합 — UI 패턴 캡슐화 |
| Layout | 8 | 페이지 구조 정의 — 컨테이너/그리드/플렉스 |
| **합계** | **111** | |

**소스**: `templates/_bundled/sirsoft-admin_basic/components.json`
**컴포넌트 소스**: `templates/_bundled/sirsoft-admin_basic/src/components/{basic,composite,layout}/*.tsx`

---

### Basic Components (37개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 39종). 목록 자체는 원문 그대로입니다.

HTML 태그를 래핑하는 최소 단위 컴포넌트입니다. 모든 Basic 컴포넌트는 `className`, `children` props를 공통으로 지원합니다.

#### 텍스트/링크

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `A` | HTML anchor element wrapper | href, target | - |
| `H1` | HTML h1 heading element wrapper | - | - |
| `H2` | HTML h2 heading element wrapper | - | - |
| `H3` | HTML h3 heading element wrapper | - | - |
| `H4` | HTML h4 heading element wrapper | - | - |
| `P` | HTML paragraph element wrapper | - | - |
| `Span` | HTML span element wrapper | - | - |
| `Label` | HTML label element wrapper | - | - |
| `Pre` | 서식 유지 텍스트 래퍼 | - | - |
| `Code` | 인라인 코드 래퍼 | - | - |

#### 컨테이너

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `Div` | 범용 컨테이너 | - | - |
| `Section` | 시맨틱 섹션 | - | - |
| `Nav` | 네비게이션 래퍼 | - | - |
| `Form` | 폼 컨테이너 (자동 바인딩 지원) | - | - |
| `Fragment` | React.Fragment — iterator에서 DOM 래퍼 없이 사용 | - | - |

#### 폼 입력

| 컴포넌트 | 설명 | 주요 Props | 바인딩 |
|----------|------|-----------|--------|
| `Input` | 텍스트 입력 | label, error | checkable |
| `Select` | 선택 박스. `options` 를 주면 커스텀 드롭다운으로 그리며 펼침 목록은 트리거 폭을 하한으로 항목이 한 줄에 들어갈 만큼 넓어지고(상한 320px·화면 오른쪽 여유), 스크롤바 자리를 미리 확보한다 | label, error, options, searchable | - |
| `Option` | Select 내부 옵션 | value | - |
| `Optgroup` | Select 옵션 그룹화 | label | - |
| `Textarea` | 텍스트 영역 | label, error | - |
| `Checkbox` | 체크박스 | label | checked |
| `FileInput` | 파일 입력 (검증 포함) | accept, maxSize, onChange, onError, buttonText, placeholder, disabled | - |
| `Button` | 버튼 | variant (`primary`\|`secondary`\|`danger`\|`success`), size (`sm`\|`md`\|`lg`), lockRepeatClick (boolean — 첫 클릭 즉시 잠가 처리 중 `disabled` 가 그려지기 전의 두 번째 클릭 무시. 접수형 실행 버튼에 `:lockRepeatClick="true"`) | - |

##### 라벨 자동 연결 (Input · Select · Textarea)

레이아웃은 `<label class="form-label">우편번호</label><input …>` 처럼 보이는 라벨과 칸을 형제로만 두고 `for` 로 잇지 않는다. 세 부품은 그려진 뒤 **바로 앞 형제 라벨**, 없으면 **칸이 그 하나뿐인 상자의 바로 앞 라벨**(최대 두 단계 위, 라벨 하나만 든 상자도 포함 — `<label/><div class="relative"><input/><span>KRW</span></div>`, FormField 의 라벨 칸·입력 칸)을 찾아 `aria-labelledby` 로 잇는다. 선택 상자(목록형)는 버튼이 「라벨 + 고른 값」 으로 읽힌다.

- 잇지 않는 경우: 호출처가 `aria-label`·`aria-labelledby` 를 준 칸, 라벨 안의 칸·`for` 로 이어진 칸(이미 이름이 있음), 라벨 뒤 상자에 칸이 둘 이상(기간 시작·끝, 금액 + 통화 선택 등), **라벨 뒤 범위(다음 라벨 전까지)에 칸이 둘 이상인 그룹 제목 라벨**(회원 등록 「주소」 아래 우편번호·주소·상세 주소 — 각 칸은 안내 글자 이름을 지킨다), `for` 가 있거나 안에 칸이 든 라벨, 같은 id 가 문서에 여럿인 라벨(반복 안의 고정 `data-ref`).
- id 가 없는 라벨에는 겹치지 않는 `g7-auto-label-N` 을 단다. Label 부품 자체는 훅을 쓰지 않는다 — 확장 레이아웃 시험이 엔진(저장소 루트 React)과 이 템플릿 부품(템플릿 React)을 함께 그려 훅이 깨지기 때문이다.
- 그룹 제목처럼 칸 이름이 아닌 라벨을 칸 바로 앞에 둘 때는 칸에 `aria-label` 을 직접 준다.

#### 테이블

| 컴포넌트 | 설명 |
|----------|------|
| `Table` | HTML table wrapper |
| `Thead` | 테이블 헤더 그룹 |
| `Tbody` | 테이블 본문 그룹 |
| `Tfoot` | 테이블 푸터 그룹 |
| `Tr` | 테이블 행 |
| `Th` | 테이블 헤더 셀 |
| `Td` | 테이블 데이터 셀 |

#### 리스트

| 컴포넌트 | 설명 |
|----------|------|
| `Ul` | 순서 없는 리스트 |
| `Ol` | 순서 있는 리스트 |
| `Li` | 리스트 아이템 |

#### 미디어/아이콘

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Img` | 이미지 | src, alt |
| `Icon` | FontAwesome 아이콘 | name, iconStyle, size, color, spin, pulse, fixedWidth, ariaLabel |
| `Svg` | SVG 컨테이너 | - |
| `I` | HTML i 태그 (FontAwesome 클래스 직접 사용) | style |

---

### Composite Components (66개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 80종). 목록 자체는 원문 그대로입니다.

기본 컴포넌트를 조합하여 UI 패턴을 캡슐화한 복합 컴포넌트입니다.

#### 데이터 표시

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `DataGrid` | 정렬/필터링/페이지네이션 데이터 그리드 | columns, data, sortable, pagination, pageSize, ... |
| `CardGrid` | 카드 그리드 레이아웃 (스켈레톤 로딩, 페이지네이션) | data, cardColumns, columns, gap, responsiveColumns, ... |
| `ExtensionCardGrid` | 확장·언어팩 목록 전용 카드/목록 전환 그리드 | items, viewMode, minCardWidth, maxColumns, pagination, ... |
| `Pagination` | 페이지네이션 | currentPage, totalPages, onPageChange, maxVisiblePages, showFirstLast, ... |
| `Badge` | 색상 기반 라벨 뱃지 | color, text, size, style |
| `StatusBadge` | 상태 뱃지 (아이콘 포함) | status, label, showIcon, iconName, style |
| `StatCard` | 통계 카드 (값, 라벨, 추이 표시) | value, label, change, changeLabel, iconName, ... |
| `LineChart` | Chart.js 선 차트 (방문자 추이처럼 시간 축 여러 계열) | labels, datasets[{label,data,color,fill,yAxisID}], height, showLegend, showGrid, showYGrid, showYAxis, className, id, editorAttrs |
| `EmptyState` | 데이터 없음 상태 표시 | title, description, iconName, illustrationSrc, ... |

> **선택 건수는 한 곳에서만 그린다 — `selectedCountText=""`**
>
> `DataGrid` 는 `selectable` 이 켜지고 선택된 행이 있으면 목록 위에 「N개 선택됨」을 **스스로**
> 그린다. 그런데 관리자 목록 화면은 대개 일괄 처리 바(`bulk-actions-bar`)를 따로 두고 거기에도
> 같은 문장을 적는다 — 그대로 두면 화면에 두 번 뜬다(검수 제보 2026-09-15).
>
> 일괄 바에 건수를 적는 화면은 `<data-grid selectedCountText="">` 로 내장 줄을 끈다. 빈 문자열이면
> `selectedCountText ?? t(...)` 의 `??` 가 폴백하지 않아 카드형·표형 두 렌더 지점의
> `&& resolvedSelectedCountText` 가드가 함께 꺼진다. 반대로 일괄 바가 없는 화면은 이 prop 을
> 두지 않아 내장 줄이 유일한 표기가 되게 둔다.
>
> 저장소 전역 정합은 `resources/js/core/template-engine/__tests__/DataGrid.selectedCountParity.test.ts`
> 가 모집단을 저작본에서 파생해 강제한다.
>
> **`ExtensionCardGrid` 스위치 재동기 — `resyncKey`**
>
> 카드 스위치는 누르는 즉시 바뀐 모양(대기 값)을 보여 주고, 서버 값(`toggle.checked`)이 바뀌면 그 값을 따른다.
> 서버가 토글을 **거절**해 값이 그대로 돌아오는 경로(요청 실패·의존 충돌 409 창 취소·템플릿 확인 창 닫기)에서는
> `checked` 가 바뀌지 않아 대기 값이 남는다. 엔진은 렌더마다 행 객체를 새로 만들므로 객체 동일성으로는
> 재조회를 알 수 없다 — 그래서 화면이 그 경로에서 `_global.extensionToggleResync` 를 1 올리고, 격자는
> `resyncKey="{{_global.extensionToggleResync ?? 0}}"` 로 받아 값이 바뀌면 대기 값을 버린다(#703).
> 대기 값의 기준을 행 객체로 바꾸지 않는다 — 렌더마다 새 객체라 대기 값이 곧바로 버려져, 요청이 끝날 때까지
> (수 초) 누른 반응이 보이지 않는다. 격자를 쓰는 화면(모듈·플러그인·템플릿 관리자/사용자 탭·언어팩 목록)은
> 모두 `resyncKey` 를 넘기고, 스위치 요청의 `fail` 과 409 갈래(템플릿은 확인 창을 여는 시점)에서 신호를 올린다.
> 열 수 측정의 `ResizeObserver` 미지원 폴백은 `resize` 이벤트 객체를 관찰 항목으로 넘기지 않도록 `measure` 를
> 인자 없이 감싸 호출한다.
> 회귀 테스트: `src/components/composite/__tests__/ExtensionCardGrid.test.tsx`,
> `__tests__/layouts/admin-extension-list-action-gates.test.tsx`.
>
> **`CardGrid` 카드 메뉴의 액션 이름 onClick**
>
> 카드 셀의 `ActionMenu` 가 `actions` 배열을 두고 항목을 `"onClick": "<액션 id>"` 로 가리키면, 격자가 그 항목을 함수로
> 바꿔 코어 공개 API `G7Core.dispatch(액션, { componentContext: { data: { row, $value, index } } })` 로 실행한다 — 액션
> 안의 `{{row.…}}` 는 그 카드의 행으로 풀린다. 행 컨텍스트에는 `_global` 이 없으므로 전역 값이 필요한 액션은 이벤트
> 방식(`"event": "onItemClick"` + `switch`)을 쓴다. 종전에는 코어에 없는 멤버를 찾아 조용히 건너뛰었다(7.1.0 에서 수정).
> 회귀 테스트: `src/components/composite/__tests__/CardGrid.actionDispatch.test.tsx`.
| `HtmlContent` | HTML 안전 렌더링 (DOMPurify XSS 방지) | content, isHtml, purifyConfig, text |

#### 폼/입력

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `FormField` | 폼 필드 래퍼 (라벨, 에러, 헬퍼 텍스트) | label, required, error, helperText, labelClassName, ... |
| `Toggle` | 토글 스위치 (Flowbite 스타일). 라벨이 스위치 밖(표 열 머리글·옆 문구)에 있으면 `ariaLabel` 로 보이는 라벨 없이 이름만 붙인다 | checked, value, onChange, disabled, label, ariaLabel, ... |
| `TagInput` | 태그 입력 (다중 선택, 생성 가능) | value, options, onChange, creatable, placeholder, ... |
| `TagSelect` | 태그 기반 선택 표시 | options, value, onChange, placeholder, disabled |
| `RadioGroup` | 라디오 버튼 그룹 | name, value, options, onChange, disabled, ... |
| `SearchBar` | 검색 바 (자동완성 제안) | placeholder, value, onChange, onSearch, suggestions, ... |
| `SearchableDropdown` | 검색 가능 드롭다운 (단일/다중) | options, value, onChange, multiple, searchPlaceholder, ... |
| `ChipCheckbox` | 칩 스타일 체크박스 (필터 UI) | value, checked, icon, label, style, ... |
| `MultilingualInput` | 탭 방식 다국어 텍스트 입력 | value, onChange, inputType, availableLocales, defaultLocale, ... |
| `MultilingualTagInput` | 다국어 태그 입력 (모달 편집) | value, onChange, placeholder, disabled, creatable, ... |
| `MultilingualTabPanel` | 다국어 탭 패널 (로케일 제어) | style, variant, defaultLocale, onLocaleChange |
| `DynamicFieldList` | 동적 필드 목록 (드래그 정렬, 추가/삭제) | items, columns, onChange, onAddItem, onRemoveItem, ... |
| `FileUploader` | 파일 업로드 (드래그앤드롭, 이미지 압축) | attachmentableType, attachmentableId, collection, maxFiles, maxSize, **size**, ... |
| `IconSelect` | 아이콘 선택 드롭다운 | value, onChange, options, placeholder, searchPlaceholder, ... |

##### MultilingualInput 기본 언어 칸과 탭 구조

- **기본 언어 칸은 값과 무관하게 항상 맨 앞에 둔다.** 값에 있는 언어로 칸을 꾸리는 `inline`(기본) 레이아웃에서
  초기 로케일 목록과 `value` 변경 시 재계산 모두 `[기본 언어, …값에 있는 나머지 언어]` 순서다(`tabs`·`compact` 는
  원래 지원 로케일 전부를 기본 언어부터 나열한다). 종전에는 값에 기본 언어 키가 있을 때만 넣어, 언어팩을 끈 뒤
  그 언어 번역만 남은 설정처럼 기본 언어 없이 저장된 값을 열면 기본 언어 칸과 그 옆 「언어 추가」 버튼이
  함께 사라져 기본 언어를 다시 입력할 수 없었다(#703). 이 순서를 「값에 있는 키만」으로 되돌리지 않는다.
- **탭 방식(`layout="tabs"`)의 한 칸은 `Span` 안에 선택 버튼과 삭제 버튼을 나란히 둔다.** 버튼 안에 버튼을 넣는 것은
  HTML 규격 위반이라, 삭제 버튼을 선택 버튼 바깥의 형제로 옮겼다(삭제 클릭이 선택으로 번지지 않으므로
  `stopPropagation` 도 필요 없다). 기본 언어 칸에는 삭제 버튼이 없다.
- **삭제(×) 버튼은 어느 언어를 지우는지 읽기 이름으로 단다** — `common.remove_language`(「English 입력 제거」). 아이콘만 그리면 화면 낭독기가 아이콘 이름 「xmark」 로 읽는다(`tabs`·`inline` 두 자리).
- **기본(필수) 언어는 보는 사람의 언어가 아니라 사이트 기본 언어다.** `defaultLocale` prop 이 없으면
  `src/support/siteLocale.ts` 의 `getSiteLocale()` — 전역 `appConfig.siteLocale`(서버 `app.site_locale`) → 없으면
  `window.G7Config.appConfig.siteLocale` → 없거나 지원 언어 밖이면 첫 지원 언어 — 를 쓴다. 서버 다국어 필수 규칙
  (`LocaleRequiredTranslatable`)이 같은 값을 보므로, 영어로 보는 관리자도 `*` 가 붙은 칸을 채우면 저장된다.
  `HtmlEditor`(필수 `*` 탭)·`MultilingualTagInput`(새 태그 값을 담는 칸)도 같은 함수를 쓴다. 단 태그 제거 버튼의
  읽기 이름처럼 **읽는 쪽은 보는 언어**로 둔다. `MultilingualTabPanel` 은 보기 전용 탭이라 보는 언어 그대로다.
- 회귀 테스트: `src/components/composite/__tests__/MultilingualInput.test.tsx` · `MultilingualInputRemoveName.test.tsx` · `MultilingualSiteLocaleRequired.test.tsx`.

##### FileUploader 크기(`size`)

전체 폭 영역이 아닌 **표 한 칸·종형 패널**같은 좁은 자리에 넣을 때는 `size` 로 줄인다.
드롭존의 안쪽 여백·아이콘·글자가 함께 줄어든다.

| 값 | 쓰는 자리 | 드롭존 |
| --- | --- | --- |
| `md`(기본) | 전체 폭 첸부 영역 | 여백 `p-8`, 안내 문구 2줄 |
| `sm` | 종형 패널·좌우 분할 폼 | 여백 `p-4` |
| `xs` | 표 한 칸(아이콘 지정 등) | 여백 `p-2`, 제한 안내 접음. 첨부 목록도 화면 폭과 무관한 두 칸 격자로 그리고, 첨부 개수와 한도 안내를 줄을 나눠 세운다 |

크기를 호출처 className 으로 덮어쓰지 않는다 — 화면마다 값이 갈려 같은 부품이 서로 다르게 보인다.
받지 않는 속성을 적으면 **조용히 무시**된다(감사 룰 `component-unknown-prop` 이 잡는다).

##### FileUploader 키보드 초점

- **키보드 초점 자리**: 항목의 「삭제」로 항목이 사라지면 초점은 다음 항목(없으면 이전 항목, 둘 다 없으면 「추가」 타일, 목록이 비면 드롭존)의 같은 단추로 간다. 빈 드롭존이나 「추가」 타일로 파일을 고른 뒤 그 요소가 사라지면 「추가」 타일(한도가 차서 없으면 새로 추가된 첫 항목)로 간다. 삭제 확인 창을 거친 삭제도 같다. 초점이 업로더 밖으로 나간 뒤에는 끌어오지 않는다. 자리 판정은 항목 루트의 `data-uploader-item` 과 단추의 `data-uploader-action`(`pick`·`add`·`primary`·`drag`·`download`·`retry`·`remove`)으로 하므로 이 표식을 지우지 않는다. 회귀 테스트: `src/components/composite/__tests__/FileUploaderFocusRecovery.test.tsx`.
- **언어 전환 인계**: 화면 언어를 바꾸면 엔진이 같은 화면을 새로 마운트한다. 업로더는 언어 전환 중에 내려갈 때만 아직 올리지 않은 대기 파일과 끌어 놓은 순서를 `G7Core.locale.handoff` 에 맡기고(키: 경로 + collection + 업로드 시점), 새 인스턴스가 마운트 때 한 번 꺼내 이어 쓴다. 올리던 항목은 대기 상태로 되돌린다. 일반 화면 이동에서는 맡기지 않는다(engine-v1.79.0). 회귀 테스트: `src/components/composite/__tests__/FileUploaderLocaleHandoff.test.tsx`.
- **생략한 배열 속성의 기본값**: `initialFiles`·`roleIds` 를 생략하면 모듈 상수인 얼린 빈 배열을 쓴다. 렌더마다 새 배열을 기본값으로 만들면 `initialFiles` 동기화 effect 가 상태를 바꾸고 다시 그리기를 부르는 일이 끝없이 이어진다. 동기화 effect 는 같은 항목이 같은 순서로 있으면 상태를 바꾸지 않으므로, 부모가 렌더마다 새 빈 배열을 넘겨도 다시 그리기가 이어지지 않는다. 회귀 테스트: `src/components/composite/__tests__/FileUploaderDefaultInitialFiles.test.tsx`.

#### 에디터

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `HtmlEditor` | HTML/텍스트 편집기 (편집/미리보기 토글) | content, onChange, isHtml, onHtmlModeChange, previewMode, ... |
| `CodeEditor` | JSON 코드 편집기 (Monaco Editor) | value, onChange, language, height, readOnly, ... |

#### 네비게이션

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `TabNavigation` | 탭 네비게이션 | tabs, activeTabId, onTabChange, variant, style |
| `TabNavigationScroll` | 탭 네비게이션 + 스크롤 (setState + scrollToSection 내장) | tabs, activeTabId, actions, style, activeClassName, ... |
| `Breadcrumb` | 브레드크럼 | items, separator, showHome, homeHref, maxItems |
| `ActionMenu` | 드롭다운 액션 메뉴 (메뉴 단추 패턴 — 항목은 `role=menuitem` 단추, 키보드로 열면 첫 항목에 초점, 화살표·Home·End·Enter·Space·Esc) | items, triggerLabel(비우면 아이콘만), triggerAriaLabel·ariaLabel(아이콘만일 때 이름, 기본 「더보기」), triggerIconName, position, style |
| `Dropdown` | 드롭다운 메뉴 | label, items, onItemClick, position, style |

- `TabNavigationScroll` 의 `tabs` 항목에 `if` 를 실을 수 있다. 엔진이 `{{…}}` 를 값으로 풀어 넘기면 컴포넌트가 **키가 있고 값이 거짓인 항목**을 탭 버튼, 좁은 화면 선택 목록, 화살표 이동, Scroll Spy 에서 모두 뺀다(키가 없으면 늘 보인다). 확장이 `inject_props` 로 덧붙이는 탭(회원 상세 「반응」 등)이 데이터나 권한이 없을 때 버튼만 남지 않게 하는 용도다. `ActionMenu` 의 `items[].if` 는 `false` 일 때만 뺀다.
- `TabNavigationScroll` 은 `enableScrollSpy` 일 때 **누른 탭을 사용자가 직접 스크롤(휠·터치·스크롤 키)할 때까지 활성으로 고정**한다. 스크롤 이동이 길거나 섹션이 맨 위까지 오지 못하는 페이지 바닥에서도 Scroll Spy 가 다른 탭으로 바꾸지 않는다. `onTabChange`·항목 `onClick` 을 줘도 Scroll Spy 를 쓰면 누른 탭을 바로 활성으로 그린다.
- `ActionMenu` 는 WAI-ARIA 메뉴 버튼이다. children 으로 넘긴 트리거(목록의 회원 이름 등)도 `role="button"`·`tabindex=0`·`aria-haspopup="menu"`·`aria-expanded` 를 받고 Enter·Space·아래 화살표로 열린다. 키보드로 열면 첫 항목에 초점이 가고, 항목(`role="menuitem"`, 잠긴 항목은 `aria-disabled`)은 위아래 화살표·Home·End 로 옮겨 Enter·Space 로 고르며 Escape 로 닫고 트리거로 초점을 돌린다. children 트리거 안에 버튼·링크를 넣지 않는다(초점 자리가 겹친다). 글자 없는 아이콘 트리거(`triggerLabel=""`)는 `triggerAriaLabel`(같은 뜻의 `ariaLabel` 도 받는다)을 이름으로 달고, 주지 않으면 공용 문구 `common.more_actions`(엔진 밖 대체 `More actions`)로 채운다. 잠긴 항목은 위험 색을 빼고 회색으로 흐리게 그린다. DataGrid 행 메뉴(…)는 작업 열 머리말 문구(`actionsColumnHeader`)를 넘긴다.

#### 모달/다이얼로그

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Modal` | 모달 다이얼로그 (오버레이, 포커스 트랩) | isOpen, onClose, title, titlePrefix, titleSuffix, children, footer, size, width, style |
| `Dialog` | 다이얼로그 (Modal 별칭) | isOpen, onClose, title, content, actions, ... |
| `ConfirmDialog` | 확인/취소 다이얼로그 | isOpen, onClose, title, message, confirmText, ... |
| `AlertDialog` | 알림 다이얼로그 (확인 버튼만) | isOpen, onClose, title, message, confirmText, ... |
| `Toast` | 토스트 알림 | toasts, position, onRemove |
| `Alert` | 알림 메시지 | type, message, dismissible, onDismiss |

- `Modal` 의 Esc 는 **창 안에 펼친 팝업이 있으면 팝업만 닫는다.** 펼친 팝업은 `aria-haspopup` 과 `aria-expanded="true"` 를 함께 단 요소로 판정하므로, 창 안에서 쓰는 드롭다운·메뉴·검색 목록은 이 두 속성을 트리거에 달고 자기 Esc 처리로 닫아야 한다. 자기 onKeyDown 으로 곧바로 닫는 팝업도 키를 누른 순간(캡처 단계)의 펼침 여부로 판정한다. `aria-haspopup` 없는 펼침(아코디언)과 창 밖 팝업은 창 닫기를 막지 않는다. 회귀 테스트: `src/components/composite/__tests__/Modal.popupEscape.test.tsx`.

#### 관리자 UI

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `AdminSidebar` | 관리자 사이드바 (계층형 메뉴) | logo, logoAlt, menu, collapsed, onToggleCollapse |
| `AdminHeader` | 관리자 헤더 | user, notifications, onNotificationClick, onProfileClick, onLogoutClick |
| `AdminFooter` | 관리자 푸터 | copyright, version, quickLinks |
| `PageHeader` | 페이지 헤더 (제목, 브레드크럼, 액션) | title, subtitle, breadcrumbItems, tabs, onTabChange, ... |
| `LoginForm` | 로그인 폼 (이메일/비밀번호 검증) | submitButtonText, emailPlaceholder, passwordPlaceholder, forgotPasswordText, forgotPasswordUrl, ... |
| `UserProfile` | 사용자 프로필 드롭다운 (`logoutEndpoint` 는 동일 출처일 때만 토큰을 싣고, `redirectPath` 가 동일 출처가 아니면 `/admin/login` 으로 이동. `dropdownOnly` 는 글자 없는 투명 버튼이라 「사용자 메뉴」(`common.user_menu`)를 읽기 이름으로 달고, 두 형태 모두 `aria-haspopup`·`aria-expanded` 로 메뉴 열림을 알린다) | user, profileText, logoutText, onProfileClick, onLogoutClick, logoutEndpoint, redirectPath, dropdownOnly |
| `NotificationCenter` | 알림 센터 | notifications, titleText, emptyText, onNotificationClick |
| `ThemeToggle` | 테마 모드 전환 (다크/라이트/자동) | onThemeChange, autoText, lightText, darkText |
| `LanguageSelector` | 언어 선택 드롭다운 (`apiEndpoint` 가 동일 출처일 때만 토큰을 싣는다) | availableLocales, languageText, apiEndpoint, onLanguageChange, inline |
| `PageTransitionIndicator` | 페이지 전환 로딩 표시 | style |

- **`LanguageSelector` 목록 구조와 키보드**: 독립 모드 단추는 `aria-haspopup="listbox"` 와 펼쳤을 때 `aria-controls` 로 목록을 가리킨다. 목록은 `role="listbox"`(이름 = `languageText`), 항목은 `role="option"` + `aria-selected` 다. 단추에서 ↓/↑·Enter·Space 로 열면 현재 언어 항목에 초점이 가고, 목록 안에서 ↓/↑(끝에서 돈다)·Home·End 로 옮기며 Enter 로 고른다. Esc 는 목록을 닫고 단추로 초점을 돌리고, Tab 으로 벗어나면 닫힌다. 현재 언어를 다시 고르면 요청 없이 닫힌다. 회귀 테스트: `src/components/composite/__tests__/LanguageSelector.listbox.test.tsx`.
- **아이콘 단추와 영역의 접근 이름**: 글자 없는 단추와 이름 붙은 영역은 이름을 공용 문구 키로 단다 — `ThemeToggle` 단추 `common.theme_select`(펼침 여부는 `aria-expanded`), `NotificationCenter` 항목 삭제 단추 `common.delete_notification`, `Alert` 닫기 단추 `common.close_alert`, `Breadcrumb` 영역 `common.breadcrumb`, `TagSelect` 태그 지우기 단추 `common.tag_input.remove`(`{{label}}` = 태그 이름). 번역이 없거나 엔진 밖(단위 테스트)이면 대체 문구를 쓴다. 이 이름을 영문 문자열로 되돌려 박지 않는다(한국어, 일본어 화면에서 영어로 읽힌다). 회귀 테스트: `src/components/composite/__tests__/AccessibleNameI18n.test.tsx`.
- **꾸밈 아이콘과 아이콘만 있는 단추**: `Icon` 은 `ariaLabel` 을 받으면 `role="img"` 와 그 이름으로 읽히고, 받지 않으면 꾸밈이라 `aria-hidden="true"` 로 숨는다(글리프 이름을 이름으로 만들지 않는다). 그래서 아이콘만 담은 단추와 링크는 단추 자신에 이름(`aria-label`, 필요하면 `title`)을 공용 문구 키로 단다. 레이아웃 예: 새로고침 `common.refresh`, 삭제 `common.delete`, 편집 `common.edit`, 더보기 `common.more_actions`, 선택 지우기 `common.clear_selection`, 펼치기와 접기 `common.expand`/`common.collapse`(펼침 여부는 `aria-expanded`), 쪽 넘김 `common.first_page`/`common.prev_page`/`common.next_page`/`common.last_page`, 값 보기 `common.show_value`/`common.hide_value`. `IconButton` 은 `label` 이 없으면 `ariaLabel`, 다음으로 아이콘별 기본 이름(새로고침, 추가, 닫기, 삭제, 편집, 검색, 내려받기, 더보기) 순으로 이름과 `title` 을 단다. 컴포넌트 안 아이콘 단추: `MultilingualInput` 언어 삭제 `common.multilingual.remove_language`(`{{language}}`), `MultilingualTagInput` 태그 지우기 `common.tag_input.remove`, `SortableMenuItem` 펼침 단추 `common.expand`/`common.collapse`, `UserProfile` 덮개 단추 모드 `common.user_menu`. `Alert` 유형 아이콘은 꾸밈이다. 회귀 테스트: `src/components/basic/__tests__/Icon.a11y.test.tsx`, `src/components/composite/__tests__/IconButton.a11y.test.tsx`, `src/components/composite/__tests__/IconOnlyControlNames.test.tsx`(번들 레이아웃 정적 대조 포함).
- **화면 문구 기본값**: 레이아웃이 문구 prop 을 넘기지 않으면 기본값은 공용 문구 키를 따르고, 번역이 없거나 엔진 밖이면 종전 영문을 쓴다. `LoginForm`(`auth.login.*`), `NotificationCenter`(`admin.notifications` 계열), `UserProfile`(`admin.profile_settings`, `admin.logout`, 처리 중 `common.logging_out`), `SearchableDropdown`(`common.search_placeholder`, `common.no_results`, `common.type_to_search`, `common.select_placeholder`, `common.selected_count`), `LanguageSelector`(`admin.language`), `EmptyState` 그림 대체 글 `common.empty_illustration`, `AdminSidebar` 로고 대체 글 `common.logo`, `TagSelect` 빈 안내 `common.no_items_selected`, `ThemeToggle` 메뉴(`admin.theme.auto`/`light`/`dark`), `FileInput`(`common.file_input.*`), `Select` 검색칸(`common.search_placeholder`, `common.search`, `common.no_results`), `ActionMenu` 트리거 글자 `common.actions`(대체 문구 `Actions`, 빈 문자열은 아이콘만), 아이콘 단추 접근 이름 `ThemeToggle` `common.theme_select`(대체 `Theme`), `UserProfile` 덮개 단추 `common.user_menu`(대체 `User menu`). 기본값을 영문 문자열로 되돌려 박지 않는다. 회귀 테스트: `src/components/composite/__tests__/DefaultTextI18n.test.tsx`.

#### 레이아웃 편집

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `LayoutEditorHeader` | 레이아웃 편집기 헤더 | layoutName, onBack, onPreview, onSave, isSaving |
| `LayoutFileList` | 레이아웃 파일 목록 | files, selectedId, onSelect |
| `LayoutHistoryPanel` | 레이아웃 히스토리 패널 | layoutId, versions, onRestore |
| `LayoutWarnings` | 레이아웃 경고 표시 | warnings |
| `VersionList` | 버전 목록 아이템 | versions, selectedId, onSelect |

#### 확장 관리

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `TemplateCard` | 템플릿 카드 (설치/활성화) | image, imageAlt, vendor, name, version, ... |
| `ExtensionBadge` | 확장 섹션 뱃지 (identifier로 이름 자동 조회) | type, identifier, name, installedModules, installedPlugins, ... |
| `ProductCard` | 상품 카드 (이미지, 제목, 가격, 액션) | imageUrl, imageAlt, title, subtitle, description, ... |

#### 고급 기능

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `SlotContainer` | 동적 슬롯 렌더링 컨테이너 | slotId, emptyContent, style, id |
| `FilterGroup` | 다중 필터 그룹 | title, filters, onChange, onReset, showResetButton, ... |
| `FilterVisibilitySelector` | 필터 가시성 상태 관리 (UI 없음, localStorage 저장) | id, visibleFilters, defaultFilters, onFilterVisibilityChange |
| `ColumnSelector` | 테이블 컬럼 표시/숨김 선택 드롭다운 | columns, visibleColumns, onColumnVisibilityChange, triggerLabel, triggerIconName, ... |
| `PermissionTree` | 계층형 권한 트리 (체크박스 선택) | data, value, onChange, disabled, desktopColumns |
| `CategoryTree` | 계층형 카테고리 트리 (체크박스 선택) | data, expandedIds, selectedIds, searchKeyword, showProductCount, ... |
| `MenuAccessTree` | 역할이 접근할 관리자 메뉴 체크 트리 (하위 선택 시 상위 자동, 상위 해제 시 하위 해제) | data, value, onChange, disabled, lockedAll, installedModules, installedPlugins |
| `SortableMenuList` | 드래그앤드롭 계층형 메뉴 목록 (선택 모드·행별 역할 배지) | items, selectedId, onSelect, onOrderChange, onToggleStatus, selectionMode, selectedIds, onSelectionChange, roleOptions, ... |
| `SortableMenuItem` | 개별 드래그 가능 메뉴 아이템 | item, isSelected, isExpanded, level, onClick, ... |
| `IconButton` | 아이콘 버튼 (글자 없으면 ariaLabel → 아이콘별 기본 이름을 aria-label·title 로) | iconName, label, ariaLabel, onClick, variant, size, ... |
| `Accordion` | 아코디언 (접기/펼치기) | defaultOpen, isOpen, onToggle, style, disabled |
| `Card` | 카드 컨테이너 (헤더, 본문, 푸터) | title, content, imageUrl, imageAlt, onClick, ... |
| `LoadingSpinner` | 로딩 스피너 | size, color, fullscreen, text |
| `ImageGallery` | 라이트박스 이미지 갤러리 (줌, 다운로드, 썸네일) | images, initialIndex, onClose |

---

### Layout Components (8개)

> **정정(#601)**: 제목의 개수는 이관 시점 문서 값입니다(코드 실측 5종). 목록 자체는 원문 그대로입니다.

페이지 구조를 정의하는 레이아웃 컴포넌트입니다.

| 컴포넌트 | 설명 | 주요 Props |
|----------|------|-----------|
| `Container` | Flex/Grid 컨테이너 | mode, direction, justify, align, wrap, gap, cols, responsive, padding, maxWidth, centered, style |
| `Grid` | CSS Grid 반응형 그리드 | cols, responsive, gap, rowGap, colGap, autoRows, autoCols, flow, style |
| `Flex` | Flexbox 레이아웃 | direction, justify, align, wrap, gap, grow, shrink, style |
| `SectionLayout` | 섹션 레이아웃 (스타일 옵션) | title, subtitle, padding, background, maxWidth, centered, border, shadow, rounded, style |
| `ThreeColumnLayout` | 3열 레이아웃 (좌/중/우 슬롯) | leftWidth, rightWidth, leftSlot, centerSlot, rightSlot, style |
| `RichSelect` | 커스텀 항목 렌더링 셀렉트 | options, value, onChange, placeholder, disabled, maxHeight, selectedChildren |
| `DropdownButton` | 포탈 기반 드롭다운 버튼 | label, icon, iconPosition, position |
| `DropdownMenuItem` | DropdownButton 내부 메뉴 아이템 | label, icon, variant, disabled, divider |

---

### 필수 컴포넌트 (모듈 호환성)

모듈 개발자가 이 컴포넌트들만 사용하면 **모든 Admin 템플릿에서 동작이 보장**됩니다.

#### 필수 Basic (27개)

| 카테고리 | 컴포넌트 |
|----------|----------|
| 텍스트/링크 | `A`, `H1`, `H2`, `H3`, `P`, `Span`, `Label` |
| 컨테이너 | `Div`, `Section`, `Nav` |
| 폼 | `Form`, `Input`, `Select`, `Checkbox`, `Textarea`, `Button` |
| 테이블 | `Table`, `Thead`, `Tbody`, `Tr`, `Th`, `Td` |
| 리스트 | `Ul`, `Li` |
| 미디어 | `Icon`, `Img`, `Svg` |

#### 필수 Composite (15개)

> **정정(#601)**: 아래 표는 `config/template.php` 의 `required_admin_components` 와 일치하지 않습니다 —
> 실제 필수 Composite 는 위 「필수 컴포넌트 (모듈 호환성)」 절의 8종(`Alert` `Badge` `Card`
> `DataTable` `FormField` `Modal` `PageHeader` `Pagination`)이며 설정 파일이 SSoT 입니다.
> 아래 목록은 이관 시점 문서를 보존한 것이므로 필수 여부의 근거로 쓰지 않습니다.

| 카테고리 | 컴포넌트 | 설명 |
|----------|----------|------|
| 데이터 표시 | `DataGrid` | 목록 페이지 필수 (테이블 형식) |
| | `CardGrid` | 목록 페이지 필수 (카드 형식) |
| | `Pagination` | 페이지네이션 |
| | `Badge` | 상태 표시 |
| 폼 | `FormField` | 폼 필드 래퍼 |
| | `Toggle` | 토글 스위치 |
| | `TagInput` | 태그 입력 |
| 에디터 | `HtmlEditor` | HTML/텍스트 에디터 |
| | `CodeEditor` | 코드 에디터 (Monaco) |
| 피드백 | `Modal` | 모달 다이얼로그 |
| | `Alert` | 알림 메시지 |
| 레이아웃 | `PageHeader` | 페이지 헤더 |
| | `Card` | 카드 컨테이너 |
| | `AdminSidebar` | 관리자 사이드바 |
| | `SlotContainer` | 동적 슬롯 렌더링 |

#### 설정 파일

> **정정(#601)**: 아래 PHP 스니펫은 이관 시점 값입니다. 현재 `config/template.php` 의 실제 배열은 위
> 「필수 컴포넌트 (모듈 호환성)」 절의 표(Basic 27 + Composite 8)와 일치합니다.

필수 컴포넌트 목록은 `config/template.php`에 정의:

```php
'required_admin_components' => [
    'DataTable', 'Pagination', 'Badge',
    'Form', 'FormField', 'Input', 'Select', 'Checkbox',
    'Button', 'Modal', 'Alert',
    'PageHeader', 'Card',
],
```

---

### 모듈 개발자 가이드

#### 핵심 원칙

```text
✅ 필수 컴포넌트 목록에 있는 컴포넌트만 사용
✅ 템플릿의 베이스 레이아웃 (_admin_base)을 extends
❌ 특정 템플릿에만 존재하는 커스텀 컴포넌트 사용 금지
```

#### 레이아웃 작성 예시

```html
<layout name="sirsoft-ecommerce_admin_products_index" version="1.0.0" extends="_admin_base">
  <slot name="content">
    <data-grid data-ref="products-table" :columns="[]" data="{{products?.data?.data}}"/>
  </slot>
</layout>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "version": "1.0.0",
  "layout_name": "sirsoft-ecommerce_admin_products_index",
  "extends": "_admin_base",
  "slots": {
    "content": [
      {
        "id": "products-table",
        "type": "composite",
        "name": "DataGrid",
        "props": {
          "columns": [],
          "data": "{{products?.data?.data}}"
        }
      }
    ]
  }
}
```

---

### 템플릿 개발자 가이드

Admin 타입 템플릿 개발 시 필수 컴포넌트를 반드시 구현해야 합니다.

#### 구현 체크리스트

```text
□ DataGrid - 정렬, 필터링, 페이지네이션 지원
□ Pagination - 페이지 이동, 페이지 크기 변경
□ Badge - 다양한 상태 색상 지원
□ Form - 유효성 검사, 제출 처리
□ FormField - 라벨, 에러 메시지, 필수 표시
□ Input - 텍스트, 이메일, 비밀번호 등 타입 지원
□ Select - 단일/다중 선택, 검색 기능
□ Checkbox - 단일/그룹 체크박스
□ Button - 다양한 variant와 size
□ Modal - 열기/닫기, 확인/취소 액션
□ Alert - success, warning, error, info 타입
□ PageHeader - 제목, 브레드크럼, 액션 버튼
□ Card - 헤더, 본문, 푸터 영역
□ AdminSidebar - 계층형 메뉴, 다국어 지원
```

#### Props 인터페이스 일관성

모든 Admin 템플릿은 동일한 Props 인터페이스를 구현해야 합니다. 상세 Props는 다음 문서를 참조:

- [컴포넌트 Props 레퍼런스 (Basic)](../../../../docs/frontend/component-props.md)
- [컴포넌트 Props 레퍼런스 (Composite)](../../../../docs/frontend/component-props-composite.md)

---

### AdminSidebar 상세

#### MenuItem 인터페이스

```typescript
interface MenuItem {
  id: string | number;
  name: string | { ko: string; en: string };
  slug: string;
  url?: string | null;
  icon?: string;           // FontAwesome 클래스 (예: "fas fa-home")
  children?: MenuItem[];
  is_active?: boolean;
}
```

#### AdminSidebarProps

```typescript
interface AdminSidebarProps {
  logo?: string;
  logoAlt?: string;
  menu: MenuItem[];          // 필수
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  className?: string;
  currentLocale?: string;    // 기본값: 'ko'
}
```

#### 아이콘 처리

```text
✅ I 컴포넌트 + FontAwesome 클래스 (<I className="fas fa-home w-5 h-5" />)
❌ Icon 컴포넌트 + IconName enum (금지 — API가 FontAwesome 클래스 문자열 직접 제공)
```

#### 레이아웃 JSON 사용 예시

```html
<data-source id="admin_menu" type="api" endpoint="/api/admin/menus" method="GET" :auto_fetch="true" :auth_required="true"/>
<admin-sidebar data-ref="admin_sidebar" menu="{{admin_menu.data}}"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "data_sources": [
    {
      "id": "admin_menu",
      "type": "api",
      "endpoint": "/api/admin/menus",
      "method": "GET",
      "auto_fetch": true,
      "auth_required": true
    }
  ],
  "components": [
    {
      "id": "admin_sidebar",
      "type": "composite",
      "name": "AdminSidebar",
      "props": {
        "menu": "{{admin_menu.data}}"
      }
    }
  ]
}
```

---

### SlotContainer 상세

#### SlotContainerProps

```typescript
interface SlotContainerProps {
  slotId: string;           // 필수 — 렌더링할 슬롯 ID
  className?: string;
}
```

#### 슬롯 시스템 동작

```text
1. slot 속성 컴포넌트 → SlotContext에 등록
2. SlotContainer가 해당 slotId의 컴포넌트 렌더링
3. 상태 변화 시 slot 표현식 재평가로 동적 이동
```

#### 사용 예시

```html
<div data-ref="category_filter" data-slot="{{_local.isVisible ? 'basic_filters' : 'detail_filters'}}" data-slot-order="1"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "category_filter",
  "type": "basic",
  "name": "Div",
  "slot": "{{_local.isVisible ? 'basic_filters' : 'detail_filters'}}",
  "slotOrder": 1,
  "children": []
}
```

```html
<slot-container data-ref="basic_filters_container" slotId="basic_filters"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "basic_filters_container",
  "type": "composite",
  "name": "SlotContainer",
  "props": { "slotId": "basic_filters" }
}
```

---

### 관련 문서

- [sirsoft-admin_basic 핸들러](handlers.md)
- [sirsoft-admin_basic 레이아웃](layouts.md)
- [sirsoft-basic 컴포넌트](../../sirsoft-basic/docs/components.md)
- [컴포넌트 개발 규칙](../../../../docs/frontend/components.md)
- [컴포넌트 Props 레퍼런스](../../../../docs/frontend/component-props.md)
- [컴포넌트 Props 레퍼런스 - Composite](../../../../docs/frontend/component-props-composite.md)

## 접근 가능한 이름 규약 (7.1.0)

- **Icon** — `ariaLabel` 을 준 아이콘만 이미지(`role="img"`)로 읽히고, 나머지는 꾸밈으로 `aria-hidden` 입니다. 글리프 이름(「ellipsis」·「trash can」)이 단추 이름에 섞이지 않습니다. 아이콘만 있는 단추는 단추 쪽에 `aria-label="$t:…"` 를 답니다(`IconButton` 은 `ariaLabel` 또는 아이콘별 기본 이름).
- **곁 라벨 잇기** — `Input`·`Textarea`·`Select`·`Toggle`·`TagInput` 은 스스로 이름(aria-label·aria-labelledby·`<label for>`·`label`/`ariaLabel` prop)이 없으면, 가까운 조상 3단계 안에서 앞에 놓인 `label`·`.form-label`·`.filter-label`·`.text-heading` 을 `aria-labelledby` 로 잇습니다(스위치는 뒤따르는 제목·앞 형제 상자의 첫 글자도 봅니다). 라벨과 입력 사이에 다른 입력이 끼면 그 라벨은 앞 칸의 것으로 보고 잇지 않습니다(`src/hooks/useAdjacentLabel.ts`). 같은 행에 입력이 여럿이면 레이아웃에서 이름을 직접 주는 편이 정확합니다.
- **창 Esc** — 창 안에 펼친 팝업(`aria-haspopup` + `aria-expanded="true"`)이 있으면 첫 Esc 는 팝업 몫입니다. 팝업을 여는 부품은 트리거에 두 속성을 정확히 답니다.
