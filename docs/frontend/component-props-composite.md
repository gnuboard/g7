# 컴포넌트 Props 레퍼런스 - Composite

> **관련 문서**: [컴포넌트 Props (Basic/DataGrid/Modal)](component-props.md) | [컴포넌트 개발 규칙](components.md) | [sirsoft-admin_basic 컴포넌트](templates/sirsoft-admin_basic/components.md)

---

## TL;DR (5초 요약)

```text
1. FileUploader: autoUpload, uploadTriggerEvent, imageCompression, apiEndpoints 커스터마이징
2. DynamicFieldList: columns 정의, enableDrag, errors 자동 매핑 (fields.0.name.ko → per-row)
3. TagInput: creatable, delimiters, splitOnPaste, isMulti, tagVariants 색상 맵핑
4. MultilingualInput: layout('inline'|'tabs'|'compact'), availableLocales, inputType
5. Toggle: size('sm'|'md'|'lg'), description 텍스트 | RadioGroup: inline 가로 배치
6. HtmlContent: isHtml prop으로 HTML/텍스트 분기 — DB *_mode 필드 → isHtml 연동 패턴 필수 숙지
```

---

## 목차

1. [FileUploader](#fileuploader)
2. [DynamicFieldList](#dynamicfieldlist)
3. [TagInput](#taginput)
4. [MultilingualInput](#multilingualinput)
5. [Toggle](#toggle)
6. [Checkbox](#checkbox)
7. [RadioGroup](#radiogroup)
8. [SearchBar](#searchbar)
9. [HtmlContent](#htmlcontent)

---

## FileUploader

**템플릿**: `sirsoft-admin_basic`, `sirsoft-basic`

파일/이미지 업로드 컴포넌트. 드래그앤드롭, 이미지 압축, 병렬 업로드, 대표 이미지 선택을 지원합니다.

### Props

| Prop | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `uploadTriggerEvent` | string | - | 업로드 트리거 이벤트명 (emitEvent와 연동) |
| `attachmentableType` | string | - | 첨부 대상 모델 타입 (예: `'Product'`) |
| `attachmentableId` | number | - | 첨부 대상 모델 ID |
| `collection` | string | `'default'` | 컬렉션명 |
| `maxFiles` | number | `10` | 최대 첨부 개수 |
| `maxSize` | number | `10` | 파일당 최대 크기 (MB) |
| `maxConcurrentUploads` | number | `3` | 동시 업로드 수 |
| `accept` | string | - | 허용 확장자 (예: `"image/*"`, `".jpg,.png,.pdf"`) |
| `imageCompression` | object | - | 이미지 압축 옵션 `{ maxSizeMB, maxWidthOrHeight }` |
| `autoUpload` | boolean | `true` | 파일 선택 시 즉시 업로드 여부 |
| `uploadParams` | object | - | 업로드 시 추가 FormData 파라미터 |
| `initialFiles` | Attachment[] | - | 초기 첨부파일 목록 |
| `disabled` | boolean | `false` | 비활성화 (읽기 전용) |
| `roleIds` | number[] | - | 접근 가능 역할 ID 배열 |
| `enablePrimarySelection` | boolean | `false` | 대표 파일 선택 기능 활성화 |
| `primaryFileId` | number \| null | - | 현재 대표 파일 ID |
| `confirmBeforeRemove` | boolean | `false` | 삭제 전 확인 모달 표시 |
| `confirmRemoveTitle` | string | - | 삭제 확인 모달 제목 |
| `confirmRemoveMessage` | string | - | 삭제 확인 모달 메시지 |
| `apiEndpoints` | object | - | API 엔드포인트 커스터마이징 |
| `className` | string | - | 추가 CSS 클래스 |

### apiEndpoints 구조

```json
{
  "apiEndpoints": {
    "upload": "/api/admin/sirsoft-ecommerce/products/attachments",
    "delete": "/api/admin/sirsoft-ecommerce/products/attachments/:id",
    "reorder": "/api/admin/sirsoft-ecommerce/products/attachments/reorder"
  }
}
```

### imageCompression 구조

```json
{
  "imageCompression": {
    "maxSizeMB": 1,
    "maxWidthOrHeight": 1920
  }
}
```

### 콜백 이벤트

| 이벤트 | $args[0] 값 | 설명 |
| ------ | ----------- | ---- |
| `onUploadComplete` | Attachment[] | 업로드 완료 시 |
| `onUploadError` | `{ error, file }` | 업로드 에러 시 |
| `onRemove` | number (파일 ID) | 파일 삭제 시 |
| `onFilesChange` | PendingFile[] | 대기 파일 변경 시 |
| `onPrimaryChange` | number \| null | 대표 파일 변경 시 |

### 사용 예시

```json
{
  "type": "composite",
  "name": "FileUploader",
  "props": {
    "attachmentableType": "Product",
    "attachmentableId": "{{route.id}}",
    "collection": "images",
    "maxFiles": 10,
    "maxSize": 10,
    "accept": "image/*",
    "imageCompression": { "maxSizeMB": 1, "maxWidthOrHeight": 1920 },
    "initialFiles": "{{product?.data?.attachments ?? []}}",
    "enablePrimarySelection": true,
    "primaryFileId": "{{_local.featuredImageId}}"
  }
}
```

### 수동 업로드 패턴

```json
{
  "type": "composite",
  "name": "FileUploader",
  "props": {
    "autoUpload": false,
    "uploadTriggerEvent": "upload:product_images",
    "uploadParams": { "temp_key": "{{_local.ui.imageTempKey}}" }
  }
}
```

> 저장 버튼에서 `emitEvent` 핸들러로 `"upload:product_images"` 이벤트를 발행하면 업로드가 시작됩니다.

---

## DynamicFieldList

**템플릿**: `sirsoft-admin_basic`

동적 필드 목록 컴포넌트. 행 추가/삭제, 드래그 정렬, 다국어 입력, 검증 에러 매핑을 지원합니다.

### Props

| Prop | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `items` | object[] | - | 데이터 항목 배열 |
| `columns` | DynamicFieldColumn[] | - | 컬럼 정의 |
| `enableDrag` | boolean | `true` | 드래그앤드롭 정렬 활성화 |
| `showIndex` | boolean | `true` | 순번 표시 |
| `minItems` | number | `0` | 최소 항목 수 (이하 삭제 불가) |
| `maxItems` | number | - | 최대 항목 수 (이상 추가 불가) |
| `addLabel` | string | `'항목 추가'` | 추가 버튼 라벨 |
| `emptyMessage` | string | `'항목이 없습니다.'` | 빈 상태 메시지 |
| `readOnly` | boolean | `false` | 읽기 전용 모드 |
| `errors` | object | - | 검증 에러 객체 |
| `itemIdKey` | string | `'_id'` | 항목별 고유 ID 키 |
| `rowActions` | RowAction[] | - | 행 액션 정의 |
| `name` | string | - | 폼 필드 이름 |
| `className` | string | - | 테이블 className |
| `headerClassName` | string | - | 헤더 className |
| `rowClassName` | string | - | 행 className |

### DynamicFieldColumn 구조

| 필드 | 타입 | 설명 |
| ---- | ---- | ---- |
| `key` | string | 데이터 필드명 |
| `label` | string | 컬럼 라벨 |
| `type` | string | `'input'` \| `'number'` \| `'select'` \| `'textarea'` \| `'multilingual'` \| `'custom'` |
| `width` | string | 컬럼 너비 (Tailwind 클래스, 예: `'w-48'`) |
| `placeholder` | string | 플레이스홀더 |
| `required` | boolean | 필수 여부 (헤더에 * 표시) |
| `options` | array | Select 옵션 (`type: 'select'`일 때) |
| `min` / `max` / `step` | number | 숫자 범위 (`type: 'number'`일 때) |
| `readOnly` | boolean | 컬럼별 읽기 전용 |
| `readOnlyCondition` | string | 조건부 읽기 전용 (필드명, `!` prefix로 부정) |

### 검증 에러 매핑

서버 검증 에러를 행별로 자동 추출합니다.

```text
서버 응답: { "fields.0.name.ko": ["필수 항목입니다"], "fields.1.content.ko": ["너무 깁니다"] }
                ↓ 자동 변환
행 0 에러: { "name.ko": ["필수 항목입니다"] }
행 1 에러: { "content.ko": ["너무 깁니다"] }
```

```json
{
  "type": "composite",
  "name": "DynamicFieldList",
  "props": {
    "errors": "{{_local.errors ?? {}}}"
  }
}
```

### 콜백 이벤트

| 이벤트 | $args[0] 값 | 설명 |
| ------ | ----------- | ---- |
| `onChange` | object[] | 데이터 변경 시 (전체 items 배열) |
| `onAddItem` | - | 항목 추가 시 |
| `onRemoveItem` | `{ index, item }` | 항목 삭제 시 |
| `onReorder` | object[] | 순서 변경 시 (정렬된 items) |

### 사용 예시

```json
{
  "type": "composite",
  "name": "DynamicFieldList",
  "props": {
    "items": "{{_local.fields ?? []}}",
    "columns": [
      { "key": "label", "label": "$t:common.label", "type": "multilingual", "required": true },
      { "key": "value", "label": "$t:common.value", "type": "input" },
      { "key": "count", "label": "$t:common.count", "type": "number", "min": 0 }
    ],
    "enableDrag": true,
    "minItems": 1,
    "maxItems": 10,
    "errors": "{{_local.errors ?? {}}}"
  }
}
```

---

## TagInput

**템플릿**: `sirsoft-admin_basic`

태그 선택/생성 컴포넌트. 자동완성, 새 태그 생성, 붙여넣기 분할, 그룹화를 지원합니다.

### Props

| Prop | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `value` | array \| string \| number | - | 선택된 값 |
| `options` | TagOption[] | - | 선택 가능한 옵션 목록 |
| `creatable` | boolean | `false` | 새 항목 추가 가능 여부 |
| `isMulti` | boolean | `true` | 다중 선택 여부 |
| `isClearable` | boolean | `true` | 선택 해제 가능 (싱글 모드) |
| `isSearchable` | boolean | `true` | 검색 가능 여부 |
| `maxItems` | number | - | 최대 선택 개수 (멀티 모드) |
| `delimiters` | string[] | `[',']` | 태그 구분자 (creatable 모드) |
| `splitOnPaste` | boolean | `true` | 붙여넣기 시 자동 분리 |
| `placeholder` | string | - | 입력창 placeholder |
| `noOptionsMessage` | string | - | 검색 결과 없을 때 메시지 |
| `formatCreateLabel` | string | - | 새 항목 추가 라벨 |
| `createLabelSuffix` | string | `'추가'` | 새 항목 추가 접미사 |
| `disabled` | boolean | `false` | 비활성화 |
| `tagVariants` | object | - | 태그 값별 색상 맵핑 |
| `defaultVariant` | string | - | 기본 태그 색상 |
| `name` | string | - | 폼 필드 이름 |
| `className` | string | - | 추가 CSS 클래스 |

### TagOption 구조

| 필드 | 타입 | 설명 |
| ---- | ---- | ---- |
| `value` | string \| number | 옵션 값 |
| `label` | string | 표시 라벨 |
| `count` | number | 카운트 표시 (선택사항) |
| `isDisabled` | boolean | 비활성화 |
| `group` | string | 그룹명 (드롭다운 그룹 헤더) |
| `description` | string | 상세 설명 (옵션 아래 표시) |

### tagVariants 색상

사용 가능한 variant: `'blue'`, `'green'`, `'amber'`, `'red'`, `'purple'`, `'gray'`

```json
{
  "tagVariants": {
    "active": "green",
    "inactive": "gray",
    "pending": "amber"
  },
  "defaultVariant": "blue"
}
```

### 콜백 이벤트

| 이벤트 | $args[0] 값 | 설명 |
| ------ | ----------- | ---- |
| `onChange` | (string \| number)[] | 값 변경 시 (태그 배열) |
| `onCreateOption` | string | 새 옵션 생성 시 (입력값) |
| `onInputChange` | string | 검색 입력 변경 시 (비동기 검색용) |
| `onBeforeRemove` | TagOption | 삭제 전 확인 (true: 허용) |

### 사용 예시

```json
{
  "type": "composite",
  "name": "TagInput",
  "props": {
    "value": "{{_local.selectedTags ?? []}}",
    "options": "{{$computed.tagOptions ?? []}}",
    "creatable": true,
    "maxItems": 10,
    "delimiters": [",", ";"],
    "splitOnPaste": true,
    "placeholder": "$t:common.select_tags"
  },
  "actions": [
    {
      "event": "onChange",
      "handler": "setState",
      "params": { "target": "local", "selectedTags": "{{$args[0]}}" }
    }
  ]
}
```

---

## MultilingualInput

**템플릿**: `sirsoft-admin_basic`

다국어 입력 컴포넌트. 인라인/탭/컴팩트 레이아웃으로 로케일별 텍스트를 입력합니다.

### Props

| Prop | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `value` | object | - | 다국어 값 (`{ ko: "...", en: "..." }`) |
| `inputType` | string | `'text'` | 입력 타입: `'text'` \| `'textarea'` |
| `layout` | string | `'inline'` | 레이아웃: `'inline'` \| `'tabs'` \| `'compact'` |
| `availableLocales` | LocaleOption[] | - | 사용 가능한 언어 목록 |
| `defaultLocale` | string | - | 기본 언어 코드 |
| `placeholder` | string | - | 입력창 placeholder |
| `required` | boolean | `false` | 필수 입력 여부 |
| `disabled` | boolean | `false` | 비활성화 |
| `maxLength` | number | - | 최대 길이 |
| `rows` | number | - | textarea 행 수 (`inputType: 'textarea'`일 때) |
| `error` | string | - | 에러 메시지 |
| `name` | string | - | 폼 필드 이름 |
| `showCodeOnMobile` | boolean | `false` | 모바일에서 언어 코드로 표시 |
| `className` | string | - | 추가 CSS 클래스 |

### 레이아웃 모드

| 모드 | 설명 |
| ---- | ---- |
| `inline` | 모든 언어를 수직으로 나열 (기본) |
| `tabs` | 탭으로 언어 전환 |
| `compact` | 탭과 입력 필드가 한 줄에 표시 |

### LocaleOption 구조

```json
{ "code": "ko", "name": "Korean", "nativeName": "한국어" }
```

### 사용 예시

```json
{
  "type": "composite",
  "name": "MultilingualInput",
  "props": {
    "value": "{{_local.form.name ?? {}}}",
    "layout": "tabs",
    "inputType": "text",
    "placeholder": "$t:common.enter_name",
    "required": true
  }
}
```

---

## Toggle

**템플릿**: `sirsoft-admin_basic`

토글 스위치 컴포넌트. 세 가지 크기와 선택적 라벨/설명을 지원합니다.

### Props

| Prop | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `checked` | boolean | - | 체크 상태 |
| `value` | boolean | - | 체크 상태 (`checked`의 대체) |
| `size` | string | `'md'` | 크기: `'sm'` \| `'md'` \| `'lg'` |
| `label` | string | - | 라벨 텍스트 |
| `description` | string | - | 설명 텍스트 (라벨 아래 작은 글씨) |
| `disabled` | boolean | `false` | 비활성화 |
| `name` | string | - | 폼 필드 이름 |
| `className` | string | - | 추가 CSS 클래스 |

### 크기 비교

| 크기 | 사용 시점 |
| ---- | --------- |
| `sm` | 테이블 셀, 밀집 UI |
| `md` | 일반 폼 필드 (기본) |
| `lg` | 강조가 필요한 설정 |

### 사용 예시

```json
{
  "type": "composite",
  "name": "Toggle",
  "props": {
    "checked": "{{_local.form.is_active ?? false}}",
    "size": "md",
    "label": "$t:common.active",
    "description": "$t:admin.settings.active_description"
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": { "target": "local", "form.is_active": "{{$event.target.checked}}" }
    }
  ]
}
```

---

## Checkbox

**템플릿**: `sirsoft-admin_basic`, `sirsoft-basic`

체크박스 컴포넌트. `indeterminate` 상태로 부분 선택을 표현합니다.

> **참고**: Checkbox는 Basic 컴포넌트이지만, `indeterminate` prop이 특수하므로 여기에 포함합니다.

### 추가 Props

| Prop | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `indeterminate` | boolean | `false` | 부분 선택 상태 (HTML 속성이 아닌 DOM 프로퍼티) |
| `label` | string | - | 라벨 텍스트 |

> 표준 HTML input 속성(`checked`, `disabled`, `name`, `value`, `className` 등)을 모두 지원합니다.

### indeterminate 사용 예시

```json
{
  "type": "basic",
  "name": "Checkbox",
  "props": {
    "checked": "{{$computed.allSelected}}",
    "indeterminate": "{{$computed.someSelected && !$computed.allSelected}}"
  }
}
```

---

## RadioGroup

**템플릿**: `sirsoft-admin_basic`

라디오 버튼 그룹 컴포넌트. 수직/수평 배치와 옵션별 비활성화를 지원합니다.

### Props

| Prop | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `name` | string | - | 그룹 이름 (필수) |
| `value` | string | - | 현재 선택된 값 |
| `options` | RadioOption[] | - | 라디오 옵션 배열 |
| `inline` | boolean | `false` | 인라인(가로) 배치 |
| `disabled` | boolean | `false` | 전체 비활성화 |
| `size` | string | `'md'` | 크기: `'sm'` \| `'md'` \| `'lg'` |
| `label` | string | - | 그룹 라벨 |
| `error` | string | - | 에러 메시지 |
| `className` | string | - | 추가 CSS 클래스 |

### RadioOption 구조

| 필드 | 타입 | 설명 |
| ---- | ---- | ---- |
| `value` | string | 옵션 값 |
| `label` | string | 옵션 라벨 |
| `disabled` | boolean | 개별 옵션 비활성화 |

### 사용 예시

```json
{
  "type": "composite",
  "name": "RadioGroup",
  "props": {
    "name": "shipping_type",
    "value": "{{_local.form.shipping_type ?? 'standard'}}",
    "options": [
      { "value": "standard", "label": "$t:common.standard" },
      { "value": "express", "label": "$t:common.express" },
      { "value": "pickup", "label": "$t:common.pickup", "disabled": true }
    ],
    "inline": true
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": { "target": "local", "form.shipping_type": "{{$event.target.value}}" }
    }
  ]
}
```

---

## SearchBar

**템플릿**: `sirsoft-admin_basic`, `sirsoft-basic`

검색 바 컴포넌트. 자동완성 제안과 검색 버튼을 지원합니다.

### Props

| Prop | 타입 | 기본값 | 설명 |
| ---- | ---- | ------ | ---- |
| `value` | string | - | 검색어 |
| `placeholder` | string | - | 플레이스홀더 |
| `showButton` | boolean | `false` | 검색 버튼 표시 |
| `suggestions` | SearchSuggestion[] | - | 자동완성 제안 목록 |
| `showSuggestions` | boolean | - | 제안 목록 표시 여부 |
| `name` | string | - | 폼 필드 이름 |
| `className` | string | - | 추가 CSS 클래스 |
| `style` | object | - | 인라인 스타일 |

### SearchSuggestion 구조

```json
{ "id": 1, "text": "검색어 제안" }
```

### 콜백 이벤트

| 이벤트 | $args[0] 값 | 설명 |
| ------ | ----------- | ---- |
| `onChange` | ChangeEvent | 검색어 변경 시 (DOM 이벤트) |
| `onSubmit` | FormEvent | 검색 제출 시 |
| `onSuggestionClick` | SearchSuggestion | 제안 항목 클릭 시 |

### 사용 예시

```json
{
  "type": "composite",
  "name": "SearchBar",
  "props": {
    "value": "{{_local.searchKeyword ?? ''}}",
    "placeholder": "$t:common.search",
    "showButton": true
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": { "target": "local", "searchKeyword": "{{$event.target.value}}" }
    }
  ]
}
```

---

## HtmlContent

HTML 콘텐츠와 일반 텍스트를 안전하게 렌더링하는 컴포넌트입니다.

### Props

| Prop | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `content` | `string` | ❌ | `''` | 렌더링할 콘텐츠 (HTML 또는 텍스트) |
| `text` | `string` | ❌ | - | 레이아웃 JSON용 콘텐츠 (`content`보다 우선) |
| `isHtml` | `boolean` | ❌ | `true` | HTML 렌더링 여부 |
| `className` | `string` | ❌ | `''` | 컨테이너 CSS 클래스 |
| `purifyConfig` | `object` | ❌ | - | DOMPurify 커스텀 설정 (isHtml=true 시만) |

### isHtml에 따른 렌더링 분기

| `isHtml` | 렌더링 방식 | 스타일 |
|----------|-----------|--------|
| `true` | `dangerouslySetInnerHTML` + DOMPurify 정화 | `prose dark:prose-invert` (Tailwind Typography) |
| `false` | React 텍스트 노드 (이스케이프됨) | `whitespace-pre-wrap` (줄바꿈 보존) |

### DB `*_mode` 필드 → `isHtml` 연동 패턴 (CRITICAL)

G7에서는 콘텐츠의 렌더링 모드를 DB의 `*_mode` 컬럼(`'text'` / `'html'`)으로 관리합니다.
이 값이 API를 거쳐 프론트엔드 `isHtml` prop으로 변환되는 전체 흐름을 이해해야 합니다.

#### 데이터 흐름

```text
1. DB 컬럼        → description_mode: 'text' | 'html'
2. API Resource   → 'description_mode' => $this->description_mode
3. 레이아웃 JSON  → "isHtml": "{{(product.data?.description_mode ?? 'text') === 'html'}}"
4. HtmlContent    → isHtml=true → HTML 렌더링 / isHtml=false → 텍스트 렌더링
```

#### 적용 사례

| 기능 | DB 컬럼 | API Resource 필드 | 레이아웃 표현식 |
|------|---------|-------------------|----------------|
| 상품 설명 | `products.description_mode` | `description_mode` | `{{(product.data?.description_mode ?? 'text') === 'html'}}` |
| 게시글 본문 | `posts.content_mode` | `content_mode` | `{{(post?.data?.content_mode ?? 'text') === 'html'}}` |
| 페이지 콘텐츠 | `pages.content_mode` | `content_mode` | `{{(page?.data?.content_mode ?? 'html') === 'html'}}` |
| 공통정보 | `common_infos.content_mode` | `common_info.content_mode` | `{{product.data?.common_info?.content_mode === 'html'}}` |

#### 레이아웃 JSON 사용 예시

```json
{
  "type": "composite",
  "name": "HtmlContent",
  "props": {
    "content": "{{product.data?.description_localized ?? ''}}",
    "isHtml": "{{(product.data?.description_mode ?? 'text') === 'html'}}",
    "className": "prose dark:prose-invert max-w-none"
  }
}
```

#### 주의사항

```text
1. API Resource에 *_mode 필드 누락 시 → 프론트엔드 기본값('text')으로 fallback → HTML 미렌더링
2. Admin Resource와 Public Resource 양쪽 모두에 *_mode 필드 포함 필수
3. 기본값은 기능별로 다름: 상품/게시글은 'text', 페이지는 'html'
4. isHtml=true 시 DOMPurify 자동 적용 — 별도 sanitize 불필요
5. 백엔드에서도 description_mode='html'일 때 HTMLPurifier로 서버 사이드 정화
```

### 보안

- `isHtml=true`: DOMPurify FORBID 방식 — `script`, `iframe`, `form` 등 위험 태그 차단, 나머지 허용
- 외부 링크(`http://`, `https://`)에 `rel="noopener noreferrer"` 자동 추가
- `purifyConfig`로 커스터마이징 가능하나, 보안 기본값(`FORBID_TAGS`, `FORBID_ATTR`)은 항상 유지

---

## 관련 문서

- [컴포넌트 Props (Basic/DataGrid/Modal)](component-props.md)
- [컴포넌트 개발 규칙](components.md)
- [컴포넌트 고급 기능](components-advanced.md)
- [sirsoft-admin_basic 컴포넌트](templates/sirsoft-admin_basic/components.md)
- [액션 핸들러 - 커스텀 콜백](actions.md#커스텀-이벤트-event-필드)
- [보안 가이드 - HTML 렌더링](security.md#htmlcontent--htmleditor-html-렌더링이-필요한-경우)
- [에디터 컴포넌트](editors.md)
