# 에디터 컴포넌트 가이드

> **중요**: 콘텐츠 편집이 필요한 경우 이 문서의 에디터 컴포넌트를 사용하세요.

---

## TL;DR (5초 요약)

```text
1. HtmlEditor: HTML/텍스트 편집, 게시판/상품 설명 등 사용
2. CodeEditor: JSON/코드 편집, Monaco Editor 기반
3. RichTextEditor: 미구현 상태 (사용 금지)
4. 보안: DOMPurify로 XSS 방지 필수 (HtmlEditor 내장)
5. 폼 바인딩: name, onChange props로 자동 연동
```

---

## 목차

1. [에디터 선택 가이드](#에디터-선택-가이드)
2. [HtmlEditor 컴포넌트](#htmleditor-컴포넌트)
3. [CodeEditor 컴포넌트](#codeeditor-컴포넌트)
4. [보안 처리](#보안-처리)
5. [관련 문서](#관련-문서)

---

## 에디터 선택 가이드

| 용도 | 추천 에디터 | 설명 |
|------|------------|------|
| 게시판 글 작성 | HtmlEditor | HTML/텍스트 모드 전환 가능 |
| 상품 설명 | HtmlEditor | HTML 모드로 서식 지원 |
| 공지사항 | HtmlEditor | 미리보기 기능 활용 |
| JSON 편집 | CodeEditor | 문법 강조, 스키마 검증 |
| 코드 스니펫 | CodeEditor | 다양한 언어 지원 |
| 레이아웃 JSON | CodeEditor | 자동 포맷, IntelliSense |

### 사용 불가 에디터

| 에디터 | 상태 | 비고 |
|--------|------|------|
| RichTextEditor | 미구현 | 스켈레톤만 존재, 사용 금지 |

---

## HtmlEditor 컴포넌트

**타입**: `composite`

**파일**: `templates/sirsoft-admin_basic/src/components/composite/HtmlEditor.tsx`

**용도**: HTML과 일반 텍스트를 편집할 수 있는 범용 에디터

### 주요 기능

- HTML 모드 / 일반 텍스트 모드 전환
- HTML 모드에서 미리보기 기능
- DOMPurify를 통한 XSS 방지
- Form 자동 바인딩 지원

### Props 레퍼런스

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `content` | `string` | ❌ | `''` | 콘텐츠 값 |
| `onChange` | `function` | ❌ | - | 콘텐츠 변경 콜백 (이벤트 객체 형식) |
| `isHtml` | `boolean` | ❌ | `false` | HTML 모드 여부 |
| `onIsHtmlChange` | `function` | ❌ | - | HTML 모드 변경 콜백 |
| `rows` | `number` | ❌ | `15` | Textarea 행 수 |
| `placeholder` | `string` | ❌ | `''` | 플레이스홀더 텍스트 |
| `label` | `string` | ❌ | - | 라벨 텍스트 |
| `showHtmlModeToggle` | `boolean` | ❌ | `true` | HTML 모드 체크박스 표시 여부 |
| `contentClassName` | `string` | ❌ | `''` | HtmlContent 미리보기 영역 클래스 |
| `purifyConfig` | `object` | ❌ | - | DOMPurify 커스텀 설정 |
| `className` | `string` | ❌ | `''` | 컨테이너 CSS 클래스 |
| `name` | `string` | ❌ | `'content'` | 콘텐츠 필드명 (폼 바인딩용) |
| `htmlFieldName` | `string` | ❌ | `'content_mode'` | HTML 모드 체크박스 필드명 |
| `readOnly` | `boolean` | ❌ | `false` | 읽기 전용 모드 |

### 레이아웃 JSON 사용 예시

#### 기본 사용 (게시판 글 작성)

```json
{
  "id": "content_section",
  "type": "composite",
  "name": "HtmlEditor",
  "props": {
    "content": "{{_local.form?.content ?? ''}}",
    "isHtml": "{{(_local.form?.content_mode ?? 'text') === 'html'}}",
    "rows": 15,
    "placeholder": "$t:board.form.content_placeholder",
    "label": "$t:board.form.content",
    "showHtmlModeToggle": true,
    "name": "content",
    "htmlFieldName": "content_mode"
  },
  "actions": [
    {
      "event": "onChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "form": {
          "content": "{{$args[0].target.value}}"
        }
      }
    },
    {
      "event": "onIsHtmlChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "form": {
          "content_mode": "{{$args[0].target.checked ? 'html' : 'text'}}"
        }
      }
    }
  ]
}
```

#### HTML 모드 고정 (상품 설명)

```json
{
  "id": "description_editor",
  "type": "composite",
  "name": "HtmlEditor",
  "props": {
    "content": "{{_local.form?.description ?? ''}}",
    "isHtml": true,
    "rows": 20,
    "placeholder": "$t:product.form.description_placeholder",
    "label": "$t:product.form.description",
    "showHtmlModeToggle": false,
    "name": "description"
  },
  "actions": [
    {
      "event": "onChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "form": {
          "description": "{{$args[0].target.value}}"
        }
      }
    }
  ]
}
```

#### 읽기 전용 (상세 보기)

```json
{
  "id": "content_view",
  "type": "composite",
  "name": "HtmlEditor",
  "props": {
    "content": "{{post.data?.content ?? ''}}",
    "isHtml": "{{(post.data?.content_mode ?? 'text') === 'html'}}",
    "readOnly": true,
    "showHtmlModeToggle": false
  }
}
```

### 폼 바인딩 패턴

HtmlEditor는 두 개의 필드를 폼에 바인딩합니다:

1. **content 필드** (`name` props): 콘텐츠 값
2. **content_mode 필드** (`htmlFieldName` props): 콘텐츠 모드 (text/html)

```json
{
  "state": {
    "form": {
      "content": "",
      "content_mode": "text"
    }
  }
}
```

### 이벤트 콜백 구조

```typescript
// onChange 콜백
onChange({ target: { name: 'content', value: '콘텐츠 값' } })

// onIsHtmlChange 콜백
onIsHtmlChange({ target: { name: 'content_mode', checked: true } })
```

---

## CodeEditor 컴포넌트

**타입**: `composite`

**파일**: `templates/sirsoft-admin_basic/src/components/composite/CodeEditor.tsx`

**용도**: JSON, JavaScript, HTML, CSS 등 코드 편집

**라이브러리**: Monaco Editor (@monaco-editor/react)

### 주요 기능

- 다양한 프로그래밍 언어 지원
- JSON 스키마 검증
- 문법 강조 (Syntax Highlighting)
- 자동 포맷팅
- IntelliSense 지원
- 라이트/다크 테마

### Props 레퍼런스

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `value` | `string` | ✅ | - | 에디터 값 |
| `onChange` | `function` | ❌ | - | 값 변경 콜백 (문자열 직접 전달) |
| `language` | `string` | ❌ | `'json'` | 언어 (json, javascript, html, css, typescript 등) |
| `height` | `string` | ❌ | `'100%'` | 에디터 높이 |
| `readOnly` | `boolean` | ❌ | `false` | 읽기 전용 모드 |
| `theme` | `'vs-dark'` \| `'vs-light'` | ❌ | `'vs-dark'` | 에디터 테마 |

### 레이아웃 JSON 사용 예시

#### JSON 편집기

```json
{
  "id": "json_editor",
  "type": "composite",
  "name": "CodeEditor",
  "props": {
    "value": "{{_local.jsonContent ?? '{}'}}",
    "language": "json",
    "height": "400px",
    "theme": "vs-dark"
  },
  "actions": [
    {
      "event": "onChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "jsonContent": "{{$args[0]}}"
      }
    }
  ]
}
```

#### JavaScript 코드 편집기

```json
{
  "id": "js_editor",
  "type": "composite",
  "name": "CodeEditor",
  "props": {
    "value": "{{_local.code ?? ''}}",
    "language": "javascript",
    "height": "300px",
    "theme": "vs-light"
  },
  "actions": [
    {
      "event": "onChange",
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "code": "{{$args[0]}}"
      }
    }
  ]
}
```

#### 읽기 전용 코드 뷰어

```json
{
  "id": "code_viewer",
  "type": "composite",
  "name": "CodeEditor",
  "props": {
    "value": "{{config.data?.settings ?? '{}'}}",
    "language": "json",
    "height": "200px",
    "readOnly": true
  }
}
```

### 지원 언어

| 언어 | `language` 값 |
|------|--------------|
| JSON | `json` |
| JavaScript | `javascript` |
| TypeScript | `typescript` |
| HTML | `html` |
| CSS | `css` |
| PHP | `php` |
| Markdown | `markdown` |

### 이벤트 콜백 구조

```typescript
// onChange 콜백 - 문자열 직접 전달 (HtmlEditor와 다름)
onChange('에디터 값')
```

---

## 보안 처리

### HtmlEditor의 XSS 방지

HtmlEditor는 내부적으로 **DOMPurify**를 사용하여 HTML을 정화합니다:

- 미리보기 시 DOMPurify로 HTML 정화
- HtmlContent 컴포넌트에서 렌더링 시 추가 정화
- 외부 링크에 `rel="noopener noreferrer"` 자동 추가

### 커스텀 DOMPurify 설정

```json
{
  "type": "composite",
  "name": "HtmlEditor",
  "props": {
    "content": "{{_local.content}}",
    "isHtml": true,
    "purifyConfig": {
      "ALLOWED_TAGS": ["p", "br", "strong", "em", "a", "ul", "ol", "li"],
      "ALLOWED_ATTR": ["href", "target", "rel"]
    }
  }
}
```

### 백엔드 검증 필수

```
주의: 프론트엔드 정화만으로는 불충분
필수: 백엔드에서도 HTML Purifier 또는 유사 도구로 검증
✅ 필수: API 저장 시 XSS 필터링 적용
```

---

## 관련 문서

- [컴포넌트 Props 레퍼런스](component-props.md) - Select, Input, Button Props
- [sirsoft-admin_basic 컴포넌트](templates/sirsoft-admin_basic/components.md) - Admin 컴포넌트 목록
- [레이아웃 JSON](layout-json.md) - 레이아웃 JSON 스키마
- [데이터 바인딩](data-binding.md) - {{}} 표현식, $t: 다국어
- [상태 관리](state-management.md) - _local, setState
- [보안 가이드](security.md) - XSS 방지, 입력 검증
