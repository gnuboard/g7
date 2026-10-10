# Changelog 규칙 (Changelog Rules)

> 코어 및 확장(모듈, 플러그인, 템플릿)의 변경사항을 CHANGELOG.md에 기록하는 규정

## TL;DR (5초 요약)

```text
1. 확장/코어 버전 업 시 CHANGELOG.md에 변경사항 기록 필수 (미기록 시 버전 업 불가)
2. 형식: Keep a Changelog 표준 (## [버전] - 날짜 / ### 카테고리 / - 항목) — 한 버전 안에 같은 카테고리 헤딩은 한 번만
3. 허용 카테고리: Added, Changed, Deprecated, Removed, Fixed, Security
4. 확장 위치: 각 확장의 루트 디렉토리 (modules/_bundled/vendor-module/CHANGELOG.md)
5. 코어 위치: 프로젝트 루트 /CHANGELOG.md (코어 버전 변경 시 필수)
```

---

## 목차

1. [CHANGELOG.md 작성 규칙](#1-changelogmd-작성-규칙)
2. [카테고리 정의](#2-카테고리-정의)
3. [파일 위치](#3-파일-위치)
4. [ChangelogParser 헬퍼](#4-changelogparser-헬퍼)
5. [API 엔드포인트](#5-api-엔드포인트)
6. [관리 화면 표시](#6-관리-화면-표시)
7. [템플릿 예시](#7-템플릿-예시)
8. [코어 버전 제약 정책](#8-코어-버전-제약-정책)
9. [다국어 파일 변경과 CHANGELOG](#9-다국어-파일-변경과-changelog)

---

## 1. CHANGELOG.md 작성 규칙

### 필수 사항

```text
확장 버전 업 시 CHANGELOG.md에 변경사항 기록 필수
미기록 시 버전 업 작업 불완전으로 간주
릴리스 태깅 전 composer test-smoke 통과 필수 (Installation 스위트)
```

- **형식**: [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/) 표준 준수
- **버전 관리**: [Semantic Versioning](https://semver.org/lang/ko/) 준수
- **작성 언어**: 한국어 (확장 대상이 한국어 사용자)
- **날짜 형식**: YYYY-MM-DD (ISO 8601)
- **최신 버전**: 파일 상단에 위치 (역순)

### 릴리스 전 Smoke Suite 통과

코어 또는 번들 확장의 버전 bump 시 다음을 충족해야 합니다:

```bash
# 릴리스 전 필수 검증
composer test-smoke

# 첫 실패에서 중단 (CI 모드)
composer test-smoke-ci
```

- `composer test-smoke`는 `tests/Feature/Installation/` 및 각 번들 확장의 `tests/Feature/Installation/` 디렉토리를 실행합니다.
- 스모크 통과 없이 릴리스 태깅을 진행하면 beta.2 #12 유형(마이그레이션–Repository 결합 누락) 회귀를 놓칠 수 있습니다.
- 상세: [testing-guide.md#pre-release-smoke-suite](../testing-guide.md#pre-release-smoke-suite)

### 버전 섹션 형식

```markdown
## [버전] - YYYY-MM-DD
```

예: `## [0.1.2] - 2026-02-25`

### 항목 형식

```markdown
### 카테고리
- 변경사항 설명
```

### 한 버전 섹션 안의 카테고리는 한 번만

브랜치마다 버전 섹션 머리에 자기 `### Fixed` 블록을 새로 얹으면, 병합이 양쪽을 그대로 이어 붙여 한 버전 안에 같은 카테고리 헤딩이 두 번 남는다. 오류도 경고도 없고, 렌더된 문서에서 같은 제목이 두 번 보이는 것이 유일한 증상이다.

- 새 항목은 **이미 있는 카테고리 블록 끝에** 추가한다 (같은 카테고리 헤딩을 새로 만들지 않는다)
- 병합 뒤 두 번째 블록이 보이면 그 항목을 첫 블록에 합치고 **헤딩만** 지운다 (항목 삭제 금지)
- 같은 버전 헤더(`## [1.2.1]`)도 한 번만 — 뒤 섹션의 내용을 앞 섹션에 합친다
- 카테고리 헤딩은 §2 의 6종만 쓴다. 기능 단위 분류는 `####` 서브 헤딩으로 둔다
- 이미 공개 배포된 과거 버전 섹션은 소급 수정하지 않는다

번들 확장은 자동 검증이 working 버전 섹션(+`[Unreleased]`)에서 이 조건을 차단한다. 제3자 확장에는 강제하지 않는다.

---

## 2. 카테고리 정의

| 카테고리 | 설명 | 사용 시점 |
|----------|------|----------|
| `Added` | 새 기능 추가 | 새로운 기능, 화면, API 엔드포인트 추가 |
| `Changed` | 기존 기능 변경 | 기존 동작 수정, 성능 개선, UI 변경 |
| `Deprecated` | 향후 제거 예정 | 향후 삭제될 기능 경고 |
| `Removed` | 기능 제거 | 기존 기능/API 삭제 |
| `Fixed` | 버그 수정 | 기존 버그, 오류 수정 |
| `Security` | 보안 취약점 수정 | 보안 관련 수정 |

---

## 3. 파일 위치

### 확장 (모듈/플러그인/템플릿)

```text
modules/_bundled/vendor-module/CHANGELOG.md     # 모듈 (예: sirsoft-board)
plugins/_bundled/vendor-plugin/CHANGELOG.md     # 플러그인 (예: sirsoft-tosspayments)
templates/_bundled/vendor-template/CHANGELOG.md # 템플릿 (예: sirsoft-admin_basic)
```

- _bundled 디렉토리에서 작업 (활성 디렉토리 직접 수정 금지)
- 업데이트 프로세스를 통해 활성 디렉토리로 복사

### 코어

```text
/CHANGELOG.md                                   # 프로젝트 루트
```

- **코어 버전 변경 시 CHANGELOG.md에 변경사항 기록 필수** (미기록 시 버전 업 불가)
- 확장과 동일한 Keep a Changelog 형식 사용
- 코어 버전 변경 대상 파일: `config/app.php`, `.env.example`, `.env.testing`
- 버전 변경 시 반드시 CHANGELOG.md에 해당 버전 섹션 추가 후 버전 파일 수정

---

## 4. ChangelogParser 헬퍼

**파일**: `app/Extension/Helpers/ChangelogParser.php`

### 주요 메서드

| 메서드 | 설명 | 반환 |
|--------|------|------|
| `parse(string $filePath)` | CHANGELOG.md 전체 파싱 | `array` (버전별 구조화 배열) |
| `getVersionRange(string $filePath, string $from, string $to)` | 특정 범위 엔트리 추출 | `array` (from 초과 ~ to 이하) |
| `resolveChangelogPath(string $basePath, string $identifier, ?string $source)` | 소스별 경로 결정 | `?string` |

### 반환 형식

```php
[
  [
    'version' => '0.1.2',
    'date' => '2026-02-25',
    'categories' => [
      ['name' => 'Added', 'items' => ['새 기능 A']],
      ['name' => 'Fixed', 'items' => ['버그 B 수정']],
    ],
  ],
]
```

### 이어 쓴 줄

항목(`- `) 밑에 들여써 이어 쓴 줄은 앞 항목에 공백 하나로 이어 붙인다. 들여쓴 `- ` 는 중첩 항목으로 따로 두고 그 밑 이어 쓴 줄은
중첩 항목에 붙인다. 빈 줄 뒤 들여쓴 문단은 같은 항목(느슨한 목록)으로, 들여쓰지 않은 문단·`####` 소제목은 항목을 끝낸다.
항목 안 코드 블록은 울타리 줄을 빼고 내용을 붙이고, 항목 밖 코드 블록 안의 `- `·`##` 줄은 무시한다. CRLF 파일도 같다.
종전에는 둘째 줄부터 버려져 확장 상세 「변경 내역」의 문장이 잘렸다(7.1.0 에서 수정). 회귀 고정: `tests/Unit/Extension/ChangelogParserTest.php`
(번들 CHANGELOG 전수 포함), 시나리오 `tests/scenarios/changelog-parser.yaml`.

---

## 5. API 엔드포인트

```text
GET /api/admin/modules/{identifier}/changelog
GET /api/admin/plugins/{identifier}/changelog
GET /api/admin/templates/{identifier}/changelog
```

### 쿼리 파라미터

| 파라미터 | 설명 | 기본값 |
|----------|------|--------|
| `source` | CHANGELOG.md 읽을 위치 | `active` |
| `from_version` | 이 버전 초과 항목만 반환 | - |
| `to_version` | 이 버전 이하만 반환 | - |

### 사용 시나리오

- **상세 모달**: `GET .../changelog` (전체 조회)
- **업데이트 모달**: `GET .../changelog?source=bundled&from_version=0.1.1&to_version=0.1.2` (변경분)

---

## 6. 관리 화면 표시

### 업데이트 모달

- **전역 상태**: `_global.updateChangelog` (3개 확장 타입 공통)
- **표시 조건**: `updateChangelog && updateChangelog.length > 0`
- **표시 위치**: 버전 정보 아래, 외부 링크(github_changelog_url) 위
- **스타일**: blue 배경 (`bg-blue-50 dark:bg-blue-900/20`)
- **우선순위**: 인라인 changelog > 외부 링크 (둘 다 있으면 둘 다 표시)

### 상세 모달

- **전역 상태**: `_global.moduleChangelog`, `_global.pluginChangelog`, `_global.templateChangelog` (타입별)
- **섹션 ID**: `changelog_section`
- **아이콘**: `clock-rotate-left`
- **empty state**: `_global.xxxChangelog`가 비어있으면 "변경 내역 정보가 없습니다." 표시

### 메인 리스트 액션 흐름

```text
# 업데이트 버튼 클릭 시:
setState(selectedX) → apiCall(changelog?source=bundled&from_version=...&to_version=...)
  → onSuccess: setState(updateChangelog) + openModal(update_modal)
  → onError: setState(updateChangelog=[]) + openModal(update_modal)

# 상세 보기 버튼 클릭 시:
setState(selectedX) → apiCall(changelog)
  → onSuccess: setState(xChangelog) + openModal(detail_modal)
  → onError: setState(xChangelog=[]) + openModal(detail_modal)
```

---

## 7. 템플릿 예시

새 확장을 만들 때 아래 템플릿을 사용합니다:

```markdown
# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [0.1.0] - YYYY-MM-DD

### Added
- 초기 기능 구현
```

---

## 8. 코어 버전 제약 정책

확장 manifest(`module.json`, `plugin.json`, `template.json`)의 `g7_version` 과 `dependencies.{modules|plugins}` 버전 제약 작성 규칙.

### 표기 규칙

- 형식: `>=X.Y.Z[-prerelease]` 통일 (공백 없음). 예: `>=7.0.0-beta.2`, `>=1.0.0-beta.2`
- 캐럿(`^`), 틸드(`~`), 엄격 일치(`=`) 사용 금지
- placeholder 금지: 실존하지 않는 버전(`0.1.0`, `0.0.1` 등) 을 적어 사실상 "아무 버전이나 허용" 상태로 두지 않음

### `g7_version` — 코어 최소 요구 버전

- 확장이 실제로 의존하는 코어 API/기능의 **최초 도입 버전**을 최소값으로 기재
- 확장 `version` 이 bump 될 때 `g7_version` 재검토 필수
- 번들 확장은 일반적으로 코어의 현재 릴리스와 같은 단계(beta.X, rc.X, X.Y.Z)를 하한으로 둠
- 예: 알림 시스템 3계층(NotificationDefinition/Template) 을 사용하는 모듈은 최소 `>=7.0.0-beta.2`

### `dependencies.{modules|plugins}` — 확장 간 버전 제약

- A 확장이 B 확장의 공개 Service/Contract/Model/Route/훅을 사용한다면 `dependencies.{type}.B: ">=X.Y.Z"` 기재
- B 에서 공개 표면이 변경되거나 새 API 가 도입되어 A 가 그것을 소비하게 되면 A 의 최소 버전 제약을 **그 API 최초 도입 버전**으로 상향
- 실존하지 않는 placeholder 버전(`>=0.1.0` 등)은 금지 — 버전 게이팅 무효화 유발

### 버전 제약 재검토 트리거

다음 이벤트 발생 시 관련 확장 manifest 를 전수 재검토한다.

| 이벤트 | 재검토 대상 |
|--------|------------|
| 코어 공개 확장 표면 변경 (AbstractModule/AbstractPlugin/HookManager/Contracts 등) | 모든 번들 확장의 `g7_version` |
| 코어 minor/major/beta 번호 변경 | 번들 확장 전체 `g7_version` |
| 번들 모듈/플러그인의 공개 Service/Contract/Model/Route/훅/CHANGELOG 변경 | 해당 확장을 `dependencies` 에 선언한 모든 확장 |

### CHANGELOG 기재

- `g7_version` 상향: `### Changed` 에 `- 코어 최소 요구 버전을 X.Y.Z 로 상향`
- `dependencies.{id}` 상향: `### Changed` 에 `- {id} 의존성 버전 제약을 실제 릴리스 버전에 맞춰 정비` 또는 `- {id} 최소 버전을 X.Y.Z 로 상향` (변경 이유에 따라)

---

## 9. 다국어 파일 변경과 CHANGELOG

호스트(코어/모듈/플러그인/템플릿)의 다국어 파일을 변경하면 그 호스트의 CHANGELOG 에도 사용자 관점 항목을 기재한다.

다국어 변경은 대부분 사용자가 화면에서 읽는 문장의 변경이다.

| 변경 유형 | 사용자에게 보이는 결과 |
| --- | --- |
| 누락 키 추가 | 원문 키(`sirsoft-ecommerce::messages.settings.fetch_success`)가 그대로 보이던 화면이 정상 문구로 바뀐다 |
| 문구 정정 | 잘못된 안내(예: 실제 입력 한도와 다른 글자 수)가 고쳐진다 |
| 오탈자 정정 | 화면 용어가 바뀐다 |

이들 변경은 diff 상 `lang/` 아래에만 남아 CHANGELOG 를 쓰는 시점에 눈에 띄지 않는다. 실제로 이슈 #78 에서 코어·이커머스·게시판·kginicis 4개 호스트의 문구 수정이 기록 없이 누락됐고, 그중에는 로그인/역할 권한 거절 응답에 원문 키가 노출되던 사용자 관점 결함도 있었다.

### 기재 예시

```markdown
### Fixed

- 일부 안내가 실제 문장 대신 내부 식별자로 표시되던 문제를 수정했습니다. 로그인하지 않았거나 역할 권한이 없어 요청이 거절될 때가 해당됩니다.
```

### 면제

사용자에게 노출되지 않는 문구(내부 커맨드 출력, 테스트 픽스처 등)는 변경 파일 상단에 사유와 함께 면제 주석을 둔다.

```php
// changelog:allow 개발용 Artisan 커맨드 출력 문구 — 사용자 화면 비노출
```

### 자동 검증

자동 검증이 다음 두 가지를 본다.

- 변경셋에 호스트 lang 파일이 있는데 그 호스트 CHANGELOG 가 없으면 경고한다.
- 같은 레벨의 중복 배열 키는 차단한다. 뒤 선언이 앞을 덮어 앞 블록 전용 키가 런타임에 소실되고 원문 키가 노출되기 때문이다. 정정은 항상 **두 블록 병합** — 한쪽만 삭제하면 그쪽 키가 사라진다.

언어팩 패키지(`lang-packs/_bundled/**`)는 별도 검사가 담당하므로 본 절의 대상이 아니다.

---

## 관련 파일

- `app/Extension/Helpers/ChangelogParser.php` — Changelog 파서
- `app/Http/Requests/Extension/ChangelogRequest.php` — FormRequest 검증
- `app/Http/Controllers/Api/Admin/ModuleController.php` — `changelog()` 메서드
- `app/Http/Controllers/Api/Admin/PluginController.php` — `changelog()` 메서드
- `app/Http/Controllers/Api/Admin/TemplateController.php` — `changelog()` 메서드
- `app/Services/ModuleService.php` — `getModuleChangelog()` 메서드
- `app/Services/PluginService.php` — `getPluginChangelog()` 메서드
- `app/Services/TemplateService.php` — `getTemplateChangelog()` 메서드

## 템플릿 엔진 내부 버전 (engine-v1.x.x)
코드와 문서에서 `engine-v1.x.x` 형태의 버전은 **템플릿 엔진의 내부 개발 이력**입니다.
그누보드7 공식 버전(`config/app.php`)과는 무관합니다.

- **CHANGELOG**: `resources/js/core/template-engine/CHANGELOG.md`
- **표기법**: `engine-v1.X.Y` (engine- 접두사 필수, `v1.X.Y` 단독 사용 금지)
- **사용처**: @since JSDoc, 인라인 주석, 규정 문서

### 엔진 CHANGELOG 반영 규칙

| 트리거 | 필수 작업 |
|--------|----------|
| `resources/js/core/template-engine/**` 기능 추가 시 | 마이너 버전 업 (engine-v1.X+1.0) + CHANGELOG 기록 |
| `resources/js/core/template-engine/**` 버그 수정 시 | 패치 버전 업 (engine-v1.X.Y+1) + CHANGELOG 기록 |
| `resources/js/core/*.ts` (TemplateApp, G7CoreGlobals 등) 버그 수정 시 | CHANGELOG `[Unreleased]` 또는 해당 버전에 기록 |
| 코드에 `@since` 추가 시 | CHANGELOG 해당 버전에 항목 추가 |
| 규정 문서에 엔진 버전 표기 시 | `engine-v1.X.Y` 형식 사용 |

### 엔진 CHANGELOG 대상 범위

엔진 CHANGELOG에 기록하는 대상은 **엔진 코어 코드**의 변경사항입니다:

| 포함 (엔진 코드) | 제외 (비엔진 코드) |
|------------------|---------------------|
| `resources/js/core/**` (프론트엔드 코어 런타임 **전체**) | `templates/**/src/components/**` (템플릿 컴포넌트) |
| ├ `template-engine/**` (편집기·DevTools 포함) | `modules/**/resources/layouts/**` (모듈 레이아웃) |
| ├ `TemplateApp.ts` · `G7CoreGlobals.ts` · `template-engine.ts` | `resources/layouts/**` (코어 레이아웃) |
| ├ `modules/**` · `auth/**` · `api/**` · `routing/**` | `docs/**` (규정 문서) |
| ├ `websocket/**` · `devtools/**` · `utils/**` · `error/**` | 백엔드 PHP 코드 (blade 포함 → 루트 CHANGELOG) |
| └ `types/**` (엔진 타입) | `resources/js/core/**/__tests__/**` (테스트 코드) |

`resources/js/core/**` **전체**가 대상입니다. 종전 표는 `template-engine/`·`TemplateApp.ts` 등
5개 경로만 열거했으나, 실제 관행은 코어 런타임 전 영역을 기록해 왔습니다 —
`modules/`(engine-v1.52.0 `loadBundle`, v1.39.0 에셋 병렬화), `auth/`(v1.47.0),
`api/`(v1.50.0 IDV 428), `routing/`(v1.38.0), `websocket/`(v1.33.1), `devtools/`(v1.51.0).
열거식 표는 새 디렉토리가 생길 때마다 판단 공백을 만들므로 경로 전체로 규정합니다.

**blade(`resources/views/**`)는 PHP 파일이므로 엔진 CHANGELOG 대상이 아닙니다** —
루트 `CHANGELOG.md` 에 기록합니다(선례: `[7.0.0-alpha.14]` 의 `seo.blade.php` 항목).

**`__tests__/**` 단독 변경도 대상이 아닙니다.** CHANGELOG 는 런타임 동작의 변화를 알리는 문서인데,
테스트만 바뀐 변경은 동작을 바꾸지 않습니다. 같은 커밋에서 런타임 코드가 함께 바뀌었다면
그 런타임 변경을 기준으로 판정합니다. 런타임 파일의 주석·JSDoc 단독 변경도 같습니다 —
판정 기준은 파일 경로가 아니라 **동작 변화**입니다.

### 엔진 CHANGELOG 작성 형식

Keep a Changelog 표준:

- `### Added` — 새 기능
- `### Fixed` — 버그 수정
- `### Changed` — 기존 기능 변경
- `### Deprecated` — 곧 제거될 기능
- `### Removed` — 제거된 기능

### CHANGELOG 항목 작성 규칙

엔진 코드 수정 후 CHANGELOG 미기록 시 작업 미완료로 간주합니다.

**항목 형식**: `- 수정 내용 요약 (수정 파일명)`

```markdown
# 좋은 예
- setState dot notation 멀티 키 병합 시 이전 키 변경 유실 방지 (ActionDispatcher)
- blocking 데이터소스 + errorHandling 데드락 — fallback 동기 적용, 에러핸들러 비동기 실행

# 나쁜 예
- 버그 수정                      ← 무엇을 수정했는지 불명확
- ActionDispatcher.ts 수정       ← 파일명만으로는 변경 내용 파악 불가
```

**패치 버전 항목 형식**: `- (engine-v1.X.Y) 수정 내용 (파일명)`

```markdown
- (engine-v1.17.5) dataKey 자동 바인딩 컴포넌트에서 setState 호출 시 stale 값 방지
```

#### 버전 결정 기준

| 상황 | 버전 처리 |
|------|----------|
| 새 기능 추가 (핸들러, 속성, API) | 마이너 버전 업: `engine-v1.X+1.0` |
| 기존 기능 버그 수정 | 패치 버전 업: `engine-v1.X.Y+1` |
| 특정 버전에 귀속 불가한 수정 | `[Unreleased]` 섹션에 기록 |
| 릴리스 시 | `[Unreleased]` → `[engine-v1.X.0]`으로 이동 |

#### 대규모 Fixed 섹션 카테고리 분류

항목이 10개를 넘으면 `####` 서브 헤딩으로 그룹핑합니다.

```markdown
### Fixed

#### 상태 동기화
- 항목 1
- 항목 2

#### 캐시
- 항목 3
```

#### 트러블슈팅 연동

- 엔진 버그를 고치면 트러블슈팅 가이드에 사례를 추가하고 CHANGELOG `### Fixed` 에도 같은 변경으로 기록합니다
- 트러블슈팅 사례에 적는 엔진 버전 태그는 그 수정이 기록된 CHANGELOG 버전과 같아야 합니다

## 공개 CHANGELOG 작성 규칙
코어(`CHANGELOG.md`) 및 확장(`modules/*/CHANGELOG.md`, `plugins/*/CHANGELOG.md`, `templates/*/CHANGELOG.md`)의 릴리즈 CHANGELOG 작성 규칙입니다.

### 톤과 표현

- 사용자/개발자가 읽는 문서이므로 **사용자 관점**으로 작성
- "~할 수 있도록 개선", "~하도록 변경", "~문제 수정" 톤 사용
- 내부 구현 상세(클래스명, 파일 경로, 테스트 건수, 훅 체인, DI 패턴)는 포함하지 않음
- 내부 작업 이슈 번호(`#123`, `refs #347`)는 포함하지 않음 — 단 아래 **공개 제보자 attribution** 은 예외

### 항목 길이

- 각 불릿은 **1~2줄**로 간결하게 작성
- 한 기능의 세부 구현을 여러 줄에 나열하지 않음
- 관련 항목이 많으면 **기능 단위 서브 헤딩**(`####`)으로 그룹핑

### 포함/제외 대상

| 포함 | 제외 |
|------|------|
| 사용자에게 보이는 기능 추가/변경 | 내부 파일 경로, 클래스/메서드명 |
| API 변경 (엔드포인트, 파라미터) | 테스트 건수/파일명 |
| 기존 기능의 버그 수정 | 리팩토링 세부사항 |
| 성능 개선 (체감 가능한 것) | 내부 규정/문서 변경 |
| Breaking Change | 내부 작업 이슈 번호 단독 (예: `refs #347`) |
| 엔진 버전 참조 (engine-v1.X.Y) | 코드 패턴 설명 |
| **공개 제보자 attribution** (`(#N @login 님께서 제보해주셨습니다.)`) | — |
| **KISA 등 공식 보안 채널** (`(KISA 측에서 제보해주셨습니다 — KVE-XXXX-XXXXX)`) | — |

### 공개 제보자 attribution

공개 저장소(GitHub) 이슈로 제보·건의된 항목이 출시 CHANGELOG 에 반영되면, 항목 끝에 공개 이슈 번호와 제보자 GitHub 핸들 멘션을 부착합니다.

- 형식: `- (본문) (#N @login 님께서 제보해주셨습니다.)` 또는 `... 건의해주셨습니다.`
- 톤: 버그 리포트는 "제보", 제안형 개선 요청은 "건의"
- 다중 매칭: `(#A @x, #B @y 님께서 제보해주셨습니다.)` / 혼재: `(#A @x 님께서 제보해주시고, #B @y 님께서 건의해주셨습니다.)`
- KISA 등 공식 보안 채널: GitHub 멘션 없이 텍스트 "KISA 측에서" + 공개 가능한 식별자만 — `(KISA 측에서 제보해주셨습니다 — KVE-XXXX-XXXXX)`
- sir.kr 커뮤니티 제보·문의: GitHub 이슈가 없어도 표기합니다 — `(sir.kr 커뮤니티의 {닉네임} 님께서 제보해주셨습니다.)`, 닉네임을 밝히지 않은 제보는 `(sir.kr 커뮤니티에서 제보해주신 내용입니다.)`, 문의·건의는 `제보` 대신 `문의`·`건의`
- 공개 PR 코드 기여: `(#N @login 님께서 수정해주셨습니다.)`(동작 결함을 고친 PR) 또는 `(#N @login 님께서 기여해주셨습니다.)`(기능 추가·개선 PR). N 은 공개 PR 번호이며 제보·건의와 섞이면 위 혼재 문장 규칙을 따릅니다
- `(#N @login` 형식을 유지합니다 — 전체 URL 로 바꾸지 않습니다
- 표기는 **선택이 아니라 해당 항목의 의무**입니다. 공개 이슈·공개 PR·공식 보안 채널·sir.kr 커뮤니티의 신고를 반영한 항목이면 그 항목 끝에 붙입니다
- 내부 작업 이슈 번호와의 구분: 같은 줄에 `@login` 멘션 또는 `님께서 (제보|건의|수정|기여)해주셨습니다`·`KISA 측에서` 표현이 함께 있으면 공개 attribution, 그 밖의 `#숫자` 는 내부 번호라 쓰지 않습니다
- README(`README.md`·`README.ko.md`)의 **커뮤니티 기여자** 목록은 공개 CHANGELOG 의 attribution 전체에서 모읍니다. 새 `@login` 을 적었다면 같은 변경에서 두 README 목록도 갱신합니다

### 신규 기능의 버그 수정 제외 규칙

해당 릴리즈에서 **새로 도입한 기능**의 개발 중 버그 수정은 Fixed에 기록하지 않습니다. 사용자 관점에서 그 기능은 해당 릴리즈에서 처음 제공되므로, "추가했다가 고쳤다"는 내부 개발 이력일 뿐입니다.

**판단 기준**: 해당 버그가 **이전 릴리즈에도 존재했던 기능**에서 발생한 것인지 확인

### 기능 그룹핑

Added/Changed/Fixed 내 항목이 10개를 초과하면 `####` 서브 헤딩으로 기능 단위 분류합니다.

### 신규 확장의 최초 출시 항목

새 확장(또는 새 번들 언어팩)의 첫 공개 버전은 **그 확장 전체를 처음 소개하는 항목**입니다. 개발 과정의 변경 이력이 아니라 완성된 기능의 소개로 씁니다.

| 항목 | 규격 |
|------|------|
| 카테고리 | `### Added` 단독 — 출시 전에 고친 것은 Fixed 가 아니다 |
| 첫 불릿 | "… 플러그인(모듈/템플릿) 최초 릴리즈." 로 시작해 무엇이 처음 제공되는지 밝힌다 |
| 분류 | 항목이 10개를 넘으면 `####` 서브 헤딩으로 기능 영역별 분류 |
| 불릿 길이 | 다른 릴리즈와 동일하게 1~2줄 |
| 다국어 | 기본 제공 언어와 번들 언어팩으로 제공되는 언어를 밝힌다 |
| 코어 요구 버전 | manifest `g7_version` 의 최소 버전을 밝힌다 |

출시일자는 **그 확장이 실제로 공개되는 날**입니다. 개발 중 적어 둔 날짜가 남아 있으면 코어 릴리즈일에 맞춰 정정합니다.

### Keep a Changelog 형식

- `## [버전] - YYYY-MM-DD` 헤더 필수
- `### Added` / `### Changed` / `### Fixed` / `### Removed` 카테고리 사용
- 최신 버전이 파일 상단
- 한 버전 섹션 안에 같은 카테고리 헤딩은 한 번만 — 브랜치마다 섹션 머리에 자기 `### Fixed` 블록을 얹고 병합이 양쪽을 이어 붙이면 같은 제목이 두 번 남는다. 항목은 기존 카테고리 블록 끝에 추가하고, 병합 뒤 두 번째 블록이 보이면 그 항목을 첫 블록에 합치고 헤딩만 지운다(항목 삭제 금지). 같은 버전 헤더 중복·허용 밖 카테고리도 같은 결함군이며 자동 검증이 working 버전 섹션에서 차단한다. 공개 배포된 과거 섹션은 소급 수정하지 않는다
