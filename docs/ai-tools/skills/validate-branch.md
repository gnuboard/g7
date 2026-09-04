# 브랜치 단위 통합 검증 (validate-branch)

한 브랜치(또는 워킹트리)의 변경을 영역별로 분류해 해당 검증 스킬을 모두 호출하는 **통합 메타 스킬**입니다.
백엔드만, 프론트엔드만 검증하고 완료를 선언하는 것을 막는 것이 이 스킬의 존재 이유입니다.

## 판정 원칙

의미 검토가 1차, 정적 분석은 보조입니다. 스크립트가 통과했다는 사실만으로 "검증 완료" 를 단정하지 않습니다.
자동 검사로 확인할 수 없는 영역(의도 부합도, 부수 효과 누락)은 결과에 별도로 명시합니다.

## 사용 시점

- 여러 영역이 함께 변경되었을 때 (백엔드 + 프론트엔드, 코드 + 다국어 등)
- PR 작성 직전
- 브랜치 전체 점검 요청 시

단일 영역 변경이 확실할 때만 개별 스킬(`/validate-backend`, `/validate-frontend` 등)을 단독으로 씁니다.

## 입력 모드

```bash
/validate-branch                # 기본: develop 대비 브랜치 diff
/validate-branch --working      # 워킹트리 변경
/validate-branch <base>         # 임의 기준 브랜치
```

## 0단계: 변경 파일 수집 및 카테고리 분류

```bash
git diff --name-only develop...HEAD    # 기본
git status --porcelain                 # 워킹트리
```

| 파일 경로 패턴 | 활성 카테고리 |
| --- | --- |
| `app/**/*.php`, `modules/**/src/**/*.php`, `plugins/**/src/**/*.php` | **backend** |
| `database/migrations/**`, `upgrades/Upgrade_*.php` | **migration** |
| `**/Listeners/**`, `**/Providers/**` (훅 등록) | **hook** |
| `lang/**`, `**/lang/**`, `lang-packs/**` | **i18n** |
| `resources/layouts/**/*.{json,g7.html}`, `**/layouts/**/*.{json,g7.html}`, `**/extensions/**/*.{json,g7.html}`, `**/components/**/*.tsx` | **frontend** (`.g7.html` = 소스 SSoT, `.json` = 런타임 계약 — 동일 취급) |
| `resources/js/core/template-engine/**` | **engine** (CHANGELOG 보강 필요) |
| `module.json` / `plugin.json` / `template.json` / `config/app.php` | **version-sync** |

활성 카테고리가 없는 변경(예: 빌드 산출물 단독)은 그 사실을 보고하고 종료합니다.

## 1단계: audit 자동 검사

변경 범위에 대해 코드 패턴 감사를 먼저 돌립니다. `error` 와 `warn` 을 모두 보고합니다.

```bash
# 프로젝트가 제공하는 audit 러너를 변경 범위에 대해 실행합니다.
#   기본: develop...HEAD  /  --working: 워킹트리  /  --paths <glob>: 지정 경로
```

audit 은 **변경된 파일만** 봅니다. 손대지 않은 영역은 `--paths` 로 축을 좁혀 따로 훑습니다 —
전체 동시 실행은 룰 대부분이 저장소 전역 스캔이라 성립하지 않습니다.

```bash
# 예: --paths 'app/Services/**'
```

## 2단계: 카테고리별 검증 호출

| 활성 카테고리 | 호출할 스킬 | 함께 읽을 규정 |
| --- | --- | --- |
| backend | `/validate-backend` | [service-repository.md](../../backend/service-repository.md) · [controllers.md](../../backend/controllers.md) · [validation.md](../../backend/validation.md) |
| migration | `/validate-migration` | [database-guide.md](../../database-guide.md) |
| hook | `/validate-hook` | [hooks.md](../../extension/hooks.md) |
| i18n | `/validate-i18n` | [module-i18n.md](../../extension/module-i18n.md) |
| frontend | `/validate-frontend` | 마크업 [layout-markup.md](../../frontend/layout-markup.md) + 런타임 계약 [layout-json.md](../../frontend/layout-json.md) · [components.md](../../frontend/components.md) |
| engine | 엔진 CHANGELOG 갱신 확인 | — |
| version-sync | manifest ↔ package.json ↔ package-lock.json 동기 확인 (번들 템플릿에는 `composer.json` 이 없습니다) | [changelog-rules.md](../../extension/changelog-rules.md) |

## 3단계: 보조 자동 검증 스크립트

변경 내용에 해당하는 것만 실행합니다.

```bash
# 코어 버전 동기 검사     — config/app.php 변경 시
# 확장 버전 제약 정합 검사 — 확장 manifest 변경 시
```

## 4단계: 테스트 실행

```bash
php artisan test --filter=<관련 테스트>                  # 백엔드
powershell -Command "npm run test:run -- <패턴>"          # 프론트엔드 (Windows)
```

프론트엔드(레이아웃 `.g7.html`·`.json`, 컴포넌트 TSX, 엔진 TS) 변경은 Playwright E2E spec
동반 추가·수정이 함께 요구됩니다. 단위 테스트만으로 완료를 선언하지 않습니다.

## 5단계: 통합 보고 형식

```markdown
## 브랜치 단위 통합 검증 결과

### 검증 범위
- 기준: <base>...HEAD (또는 워킹트리) / 변경 파일 N건
- 활성 카테고리: backend, frontend, i18n

### audit 자동 검사
- error N건 / warn M건 (룰별 내역)

### 카테고리별 결과
| 카테고리 | 스킬 | 결과 | 비고 |
| --- | --- | --- | --- |

### 테스트
- PHPUnit: N passed / Vitest: M passed

### 미커버 영역
- 자동 검사로 확인할 수 없는 항목을 여기 명시
```

## 금지 사항

- 한 영역만 검증하고 "검증 완료" 선언
- audit 통과만을 근거로 규정 준수 단정
- 활성 카테고리 누락 (특히 i18n·version-sync 는 코드 변경에 묻혀 빠지기 쉽습니다)

## 관련 스킬

- [validate-backend.md](validate-backend.md) · [validate-frontend.md](validate-frontend.md)
- [validate-migration.md](validate-migration.md) · [validate-i18n.md](validate-i18n.md) · [validate-hook.md](validate-hook.md)
