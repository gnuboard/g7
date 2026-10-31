# 에디터 컴포넌트 가이드

> **중요**: 콘텐츠 편집이 필요한 경우 이 문서의 에디터 컴포넌트를 사용하세요.

---

## TL;DR (5초 요약)

```text
1. HtmlEditor: HTML/텍스트 편집, 게시판/상품 설명 등 사용
2. CodeEditor: JSON/코드 편집, Monaco Editor 기반 — `theme="auto"`·`wordWrap`·`modelKey`(파일별 모델)
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

```html
<html-editor data-ref="content_section" content="{{_local.form?.content ?? ''}}" isHtml="{{(_local.form?.content_mode ?? 'text') === 'html'}}" :rows="15" placeholder="$t:board.form.content_placeholder" label="$t:board.form.content" :showHtmlModeToggle="true" name="content" htmlFieldName="content_mode">
  <on type="change" event="onChange">
    <set-state target="local">
      <field name="form">
        <field name="content" value="{{$args[0].target.value}}"/>
      </field>
    </set-state>
  </on>
  <on type="change" event="onIsHtmlChange">
    <set-state target="local">
      <field name="form">
        <field name="content_mode" value="{{$args[0].target.checked ? 'html' : 'text'}}"/>
      </field>
    </set-state>
  </on>
</html-editor>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

```html
<html-editor data-ref="description_editor" content="{{_local.form?.description ?? ''}}" :isHtml="true" :rows="20" placeholder="$t:product.form.description_placeholder" label="$t:product.form.description" :showHtmlModeToggle="false" name="description">
  <on type="change" event="onChange">
    <set-state target="local">
      <field name="form">
        <field name="description" value="{{$args[0].target.value}}"/>
      </field>
    </set-state>
  </on>
</html-editor>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

```html
<html-editor data-ref="content_view" content="{{post.data?.content ?? ''}}" isHtml="{{(post.data?.content_mode ?? 'text') === 'html'}}" :readOnly="true" :showHtmlModeToggle="false"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

```html
<init-local>
  <field name="form">
    <field name="content" value=""/>
    <field name="content_mode" value="text"/>
  </field>
</init-local>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "initLocal": {
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
- 문서 테마 추종 (`theme="auto"`)
- 자동 줄바꿈 토글
- **파일별 모델** — 커서·스크롤·되돌리기 스택이 파일마다 따로 산다

### Props 레퍼런스

| Props | 타입 | 필수 | 기본값 | 설명 |
|-------|------|------|--------|------|
| `value` | `string` | ✅ | - | 에디터 값 |
| `onChange` | `function` | ❌ | - | 값 변경 콜백 (문자열 직접 전달) |
| `language` | `string` | ❌ | `'json'` | 언어 (json, javascript, html, css, typescript 등) |
| `height` | `string` | ❌ | `'100%'` | 에디터 높이 |
| `readOnly` | `boolean` | ❌ | `false` | 읽기 전용 모드 |
| `theme` | `'auto'` \| `'vs-dark'` \| `'vs-light'` | ❌ | `'auto'` | 에디터 테마. `auto` 는 `<html class="dark">` 를 따라간다 |
| `markers` | `Array<{line, column, endLine?, endColumn?, message, severity: 'error' \| 'warning'}>` | ❌ | - | 서버 컴파일 오류·경고 마커 — 해당 줄에 빨간/노란 물결로 표시. 값이 바뀌면 통째로 다시 찍고, 빈 배열이면 지운다. 모델 줄 수를 넘는 마커는 새 내용이 모델에 들어올 때까지 보류했다가 찍고, 이미 `value` 를 담은 모델에서도 넘는 마커(파일 끝 다음 줄 등)는 마지막 줄로 접는다 |
| `wordWrap` | `boolean` | ❌ | `false` | 자동 줄바꿈. 폴백 textarea 의 `wrap` 도 함께 따른다 |
| `fontSize` | `number` | ❌ | `14` | 글자 크기 (12~20) |
| `modelKey` | `string` | ❌ | - | 파일별 모델 키. 주면 커서·스크롤·되돌리기 스택·접힘 상태가 **파일별로 보존**된다 |
| `revealAt` | `{line, column?, nonce?}` | ❌ | - | 커서를 옮길 좌표 (1-base). 같은 좌표를 다시 지목하려면 `nonce` 를 바꾼다 |
| `commands` | `Array<{key, mod?, shift?, alt?, run}>` | ❌ | - | 편집기 **안에서도** 잡을 단축키. Monaco 가 선점하는 조합(`mod+p`·`mod+k`)의 재바인딩용 |
| `onReady` | `function` | ❌ | - | 편집기 준비 완료 콜백 |

> **`theme="auto"` 가 기본값인 이유.** 종전 기본값은 `vs-dark` 하드코딩이라 사이트가 라이트
> 모드여도 편집기만 검은 판이었다(#476 D8). `auto` 는 마운트 시점의 `dark` 클래스를 곧바로
> 반영하고, 이후 전환은 `MutationObserver` 로 따라간다 — 부모 재렌더 없이도 함께 바뀐다.

> **`modelKey` 는 `<Editor path>` 로 넘어간다.** 모델을 직접 만들어 `setModel()` 하지 않는다 —
> `@monaco-editor/react` 가 자기 모델을 만들어 붙이므로 밖에서 끼운 모델이 곧바로 밀려난다
> (실측 2026-09-12: 본문 42,523자가 전역에는 있는데 편집기는 빈 화면이었다). 모델 수명은
> 라이브러리 한 곳이 쥐고, 우리는 키만 준다. 그 대가로 뷰 상태(커서·스크롤) 보존도 따라온다.

> **`language="html"` 로 `.g7.html` 을 편집할 때** Monaco 의 HTML 모드는 커스텀 태그를 검증하지 않는다. 검증은 서버 컴파일러(`markup-compile`·저장 422 의 `compile_error`)가 담당하고, 그 결과를 `markers` 로 넘겨 줄에 표시한다. 레이아웃 코드 편집기(`admin_template_layout_edit`)가 이 관용구의 실사용처다.

### 레이아웃 JSON 사용 예시

#### JSON 편집기

```html
<code-editor data-ref="json_editor" value="{{_local.jsonContent ?? '{}'}}" language="json" height="400px">
  <on type="change" event="onChange">
    <set-state target="local" jsonContent="{{$args[0]}}"/>
  </on>
</code-editor>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

```html
<code-editor data-ref="js_editor" value="{{_local.code ?? ''}}" language="javascript" height="300px" theme="vs-light">
  <on type="change" event="onChange">
    <set-state target="local" code="{{$args[0]}}"/>
  </on>
</code-editor>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

```html
<code-editor data-ref="code_viewer" value="{{config.data?.settings ?? '{}'}}" language="json" height="200px" :readOnly="true"/>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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

```html
<html-editor content="{{_local.content}}" :isHtml="true">
  <prop name="purifyConfig">
    <field name="ALLOWED_TAGS">
      <item>p</item>
      <item>br</item>
      <item>strong</item>
      <item>em</item>
      <item>a</item>
      <item>ul</item>
      <item>ol</item>
      <item>li</item>
    </field>
    <field name="ALLOWED_ATTR">
      <item>href</item>
      <item>target</item>
      <item>rel</item>
    </field>
  </prop>
</html-editor>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

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
- [sirsoft-admin_basic 컴포넌트](../../templates/_bundled/sirsoft-admin_basic/docs/components.md) - Admin 컴포넌트 목록
- [레이아웃 JSON](layout-json.md) - 레이아웃 JSON 스키마
- [데이터 바인딩](data-binding.md) - {{}} 표현식, $t: 다국어
- [상태 관리](state-management.md) - _local, setState
- [보안 가이드](security.md) - XSS 방지, 입력 검증
