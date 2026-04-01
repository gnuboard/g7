# Changelog 규칙 (Changelog Rules)

> 코어 및 확장(모듈, 플러그인, 템플릿)의 변경사항을 CHANGELOG.md에 기록하는 규정

## TL;DR (5초 요약)

```text
1. 확장/코어 버전 업 시 CHANGELOG.md에 변경사항 기록 필수 (미기록 시 버전 업 불가)
2. 형식: Keep a Changelog 표준 (## [버전] - 날짜 / ### 카테고리 / - 항목)
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

---

## 1. CHANGELOG.md 작성 규칙

### 필수 사항

```text
확장 버전 업 시 CHANGELOG.md에 변경사항 기록 필수
미기록 시 버전 업 작업 불완전으로 간주
```

- **형식**: [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/) 표준 준수
- **버전 관리**: [Semantic Versioning](https://semver.org/lang/ko/) 준수
- **작성 언어**: 한국어 (확장 대상이 한국어 사용자)
- **날짜 형식**: YYYY-MM-DD (ISO 8601)
- **최신 버전**: 파일 상단에 위치 (역순)

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

## 관련 파일

- `app/Extension/Helpers/ChangelogParser.php` — Changelog 파서
- `app/Http/Requests/Extension/ChangelogRequest.php` — FormRequest 검증
- `app/Http/Controllers/Api/Admin/ModuleController.php` — `changelog()` 메서드
- `app/Http/Controllers/Api/Admin/PluginController.php` — `changelog()` 메서드
- `app/Http/Controllers/Api/Admin/TemplateController.php` — `changelog()` 메서드
- `app/Services/ModuleService.php` — `getModuleChangelog()` 메서드
- `app/Services/PluginService.php` — `getPluginChangelog()` 메서드
- `app/Services/TemplateService.php` — `getTemplateChangelog()` 메서드
