# 학습용 샘플 확장 (Sample Extensions)

> G7 확장 시스템을 처음 접하는 개발자를 위한 **학습용 최소 샘플 확장** 4종 가이드.
> 각 샘플은 해당 확장 타입의 모든 계층을 1파일씩 포함한 "읽기 쉬운 레퍼런스" 입니다.

---

## TL;DR (5초 요약)

```text
1. 샘플 확장 4종: gnuboard7-hello_module / _plugin / _admin_template / _user_template
2. 위치: modules/_bundled/, plugins/_bundled/, templates/_bundled/ 하위
3. 용도: 스캐폴딩 결과 검증, 계층 구조 1:1 비교, 훅/레이아웃/테스트 학습
4. hidden: 모든 샘플은 manifest.hidden=true → 관리자 UI 기본 제외 (CLI 정상)
5. 설치: php artisan {type}:install gnuboard7-hello_*
```

---

## 목차

1. [샘플 확장 4종 한눈에 보기](#샘플-확장-4종-한눈에-보기)
2. [각 확장이 시연하는 계층](#각-확장이-시연하는-계층)
3. [샘플 복제 워크플로우](#샘플-복제-워크플로우)
4. [학습 순서 권장](#학습-순서-권장)
5. [샘플 설치/제거 명령어](#샘플-설치제거-명령어)
6. [hidden 플래그와 관리자 UI](#hidden-플래그와-관리자-ui)

---

## 샘플 확장 4종 한눈에 보기

| 확장 | 타입 | 경로 | 파일 수 | 테스트 | 주 시연 |
|------|------|------|---------|--------|---------|
| `gnuboard7-hello_module` | 모듈 | `modules/_bundled/gnuboard7-hello_module/` | 34 | 11/11 green | Memo CRUD + 훅 발행 |
| `gnuboard7-hello_plugin` | 플러그인 | `plugins/_bundled/gnuboard7-hello_plugin/` | 15 | 6/6 green | Action + Filter 훅 구독 |
| `gnuboard7-hello_admin_template` | Admin 템플릿 | `templates/_bundled/gnuboard7-hello_admin_template/` | 31 | 13/13 green (공통) | Basic 8개 컴포넌트 + 에러 6종 |
| `gnuboard7-hello_user_template` | User 템플릿 | `templates/_bundled/gnuboard7-hello_user_template/` | 28 | 13/13 green (공통) | 홈 + Memo 리스트 연동 |

모든 샘플은 `manifest.hidden = true` 로 설정되어 있어 관리자 UI 의 모듈/플러그인/템플릿 목록에서 기본 제외됩니다. artisan CLI 로는 정상 설치/관리할 수 있습니다.

---

## 각 확장이 시연하는 계층

### 1. `gnuboard7-hello_module` — 학습용 모듈

실제 비즈니스 모듈이 필요로 하는 **모든 계층을 1파일씩** 포함합니다. 복잡한 도메인 로직은 배제하고, Memo 라는 최소 엔티티 1개의 CRUD + 훅 발행 흐름만 보여줍니다.

| 계층 | 예시 파일 | 역할 |
|------|-----------|------|
| Entry | `module.php` | `AbstractModule` 상속, 권한/메뉴 정의 |
| Model | `src/Models/Memo.php` | Eloquent 모델 |
| Migration | `database/migrations/*_create_memos_table.php` | 테이블 스키마 |
| Factory | `database/factories/MemoFactory.php` | 테스트 데이터 생성 |
| Seeder | `database/seeders/DatabaseSeeder.php` + `Sample/` | 설치/샘플 시더 분리 |
| Repository (Interface) | `src/Contracts/Repositories/MemoRepositoryInterface.php` | DI 인터페이스 |
| Repository (Impl) | `src/Repositories/MemoRepository.php` | Eloquent 구현체 |
| Service | `src/Services/MemoService.php` | 비즈니스 로직 + 훅 발행 |
| FormRequest | `src/Http/Requests/Admin/MemoRequest.php` | 검증 |
| Resource | `src/Http/Resources/MemoResource.php` | API 응답 포맷 |
| Controller | `src/Http/Controllers/Admin/MemoController.php` | RESTful CRUD |
| Listener | `src/Listeners/*Listener.php` | 훅 구독 (자체 발행도 예시) |
| Layout | `resources/layouts/admin/memos/*.json` | 관리자 목록/폼 레이아웃 |
| Test | `tests/Feature/MemoControllerTest.php` + `tests/Unit/` | Feature + Unit |
| 다국어 (백엔드) | `src/lang/{ko,en}/messages.php` | PHP 배열 |
| 다국어 (프론트) | `resources/lang/{ko,en}.json` | JSON |

실제 모듈이 N 개 엔티티를 다룬다면, 위 계층을 N 배로 확장합니다.

### 2. `gnuboard7-hello_plugin` — 학습용 플러그인

플러그인의 핵심 역할인 **훅 구독** 을 시연합니다. 모듈의 Memo 생성 훅을 구독하여 부가 작업을 수행하는 Action 리스너와, 리스트 응답을 가공하는 Filter 리스너를 각각 1개씩 포함합니다.

| 계층 | 예시 파일 | 역할 |
|------|-----------|------|
| Entry | `plugin.php` (루트) | `AbstractPlugin` 상속 |
| Listener (Action) | `src/Listeners/*ActionListener.php` | 훅 발생 시 부가 작업 |
| Listener (Filter) | `src/Listeners/*FilterListener.php` | `type: 'filter'` 명시, 반환값으로 가공 |
| Settings Schema | `config/settings/defaults.json` + `getSettingsSchema()` | 관리자 UI 설정 |
| Settings Layout | `resources/layouts/admin/plugin_settings.json` | 설정 UI (자동 바인딩 패턴) |
| Test | `tests/Feature/`, `tests/Unit/` | 훅 발행 → 구독 검증 |
| 다국어 (프론트) | `resources/lang/{ko,en}.json` | JSON (백엔드 PHP 다국어 없음) |

플러그인은 **완전한 페이지 레이아웃 등록 불가** — 설정 UI (`plugin_settings.json`) 와 `layout_extensions` (확장 지점/Overlay) 만 허용됩니다.

### 3. `gnuboard7-hello_admin_template` — 학습용 Admin 템플릿

Admin 템플릿의 최소 구성을 보여줍니다. `sirsoft-admin_basic` 의 전체 컴포넌트 세트 중 **꼭 필요한 Basic 8개** 와 에러 레이아웃 6종만 포함합니다.

| 계층 | 예시 파일 | 역할 |
|------|-----------|------|
| Entry | `template.json` | type: admin, components 레지스트리, error_config |
| Components | `src/components/basic/{Div,Button,Input,...}.tsx` | HTML 태그 래핑 Basic 8개 |
| Layouts (베이스) | `layouts/_admin_base.json` | 헤더 + 사이드바 + 콘텐츠 슬롯 |
| Layouts (초기) | `layouts/admin_dashboard.json` | 대시보드 예시 |
| Layouts (에러) | `layouts/errors/{401,403,404,500,503,maintenance}.json` | 에러 6종 필수 |
| Routes | `routes.json` | `*/admin/*` 라우트 |
| Test | `__tests__/layouts/*.test.tsx` | `createLayoutTest()` 기반 렌더링 테스트 |
| 다국어 | `lang/{ko,en}.json` | common.* 키만 |

### 4. `gnuboard7-hello_user_template` — 학습용 User 템플릿

User 템플릿의 최소 구성을 보여줍니다. `gnuboard7-hello_module` 의 Memo API 를 `data_sources` 로 연동하여 홈 페이지에 리스트를 출력하는 예시를 포함합니다.

| 계층 | 예시 파일 | 역할 |
|------|-----------|------|
| Entry | `template.json` | type: user, features 플래그, components 레지스트리 |
| Components | `src/components/basic/*.tsx` | Basic 컴포넌트 |
| Layouts (베이스) | `layouts/_user_base.json` | 헤더 + 콘텐츠 + 푸터 |
| Layouts (홈) | `layouts/home.json` | Memo 모듈 `data_sources` 연동 |
| Layouts (에러) | `layouts/errors/*.json` | 에러 6종 |
| Routes | `routes.json` | `/` 루트, `auth_required: false` |
| Test | `__tests__/layouts/*.test.tsx` | API 모킹 + 렌더링 테스트 |

---

## 샘플 복제 워크플로우

"Hello 모듈을 템플릿으로 새 모듈 `acme-blog` 만들기" 시나리오:

```bash
# 1. _bundled 하위에 복제
cp -r modules/_bundled/gnuboard7-hello_module modules/_bundled/acme-blog

# 2. 식별자/네임스페이스 일괄 치환 (에디터 전역 치환)
#    gnuboard7-hello_module   → acme-blog
#    Gnuboard7\HelloModule    → Acme\Blog
#    gnuboard7                → acme
#    HelloModule              → Blog
#    hello_module             → blog
#    Memo / memo / memos      → Post / post / posts  (도메인 이름 변경)

# 3. manifest 정리
#    - module.json: hidden 필드 제거 (또는 false) — 새 모듈은 UI 노출 필요
#    - version: 0.1.0 으로 초기화
#    - description: 새 모듈 설명으로 교체

# 4. 오토로드 갱신 후 설치
php artisan extension:update-autoload
php artisan module:install acme-blog
php artisan module:activate acme-blog

# 5. 테스트 실행으로 복제 무결성 확인
php vendor/bin/phpunit modules/_bundled/acme-blog/tests
```

복제 후 **반드시 제거해야 할 항목**:

- `manifest.hidden` 필드 (또는 `false` 로 설정)
- README/CHANGELOG 의 "학습용 샘플" 문구
- `Sample/` 시더의 학습용 더미 데이터 (실제 샘플 데이터로 교체)

---

## 학습 순서 권장

확장 시스템을 처음 접하는 경우 다음 순서를 권장합니다:

```text
1) gnuboard7-hello_module         (백엔드 계층 전부 — Service/Repository/Controller/Request/Resource)
        ↓
2) gnuboard7-hello_plugin         (훅 구독 — Action + Filter)
        ↓
3) gnuboard7-hello_admin_template (프론트엔드 컴포넌트 + 레이아웃 테스트)
        ↓
4) gnuboard7-hello_user_template  (모듈 API 연동 — data_sources)
        ↓
5) sirsoft-page / sirsoft-board 등 실전 확장
        (다중 엔티티, 복잡한 권한 범위, 동적 메뉴 등 본격적인 패턴)
```

- 1~2 단계는 백엔드 개발자가 반드시 거쳐야 하는 최소 학습 경로입니다.
- 3~4 단계는 프론트엔드 담당자가 `components.md` / `layout-json.md` / `layout-testing.md` 와 함께 읽으면 좋습니다.
- 5 단계부터는 실전 패턴(권한 범위, 동적 엔티티, SEO 변수 등) 이 등장하므로 본격적인 개발 전 [module-basics.md](module-basics.md) 의 "동적 권한/역할/메뉴 보존 규칙" 을 먼저 숙지합니다.

---

## 샘플 설치/제거 명령어

### 설치

```bash
# 모듈
php artisan module:install gnuboard7-hello_module
php artisan module:activate gnuboard7-hello_module

# 플러그인 (Hello 모듈이 먼저 활성화되어 있어야 훅 구독 검증 가능)
php artisan plugin:install gnuboard7-hello_plugin
php artisan plugin:activate gnuboard7-hello_plugin

# Admin 템플릿
php artisan template:install gnuboard7-hello_admin_template
php artisan template:activate gnuboard7-hello_admin_template

# User 템플릿
php artisan template:install gnuboard7-hello_user_template
php artisan template:activate gnuboard7-hello_user_template
```

### 샘플 데이터 시딩

```bash
# 설치 + 샘플 시더 실행 (Memo 더미 데이터 생성)
php artisan module:seed gnuboard7-hello_module --sample
```

### 숨김 포함 목록 조회

모든 Hello 샘플은 `hidden: true` 이므로 기본 목록에 나타나지 않습니다:

```bash
php artisan module:list --hidden
php artisan plugin:list --hidden
php artisan template:list --hidden
```

### 제거

```bash
php artisan template:deactivate gnuboard7-hello_user_template
php artisan template:uninstall gnuboard7-hello_user_template

php artisan template:deactivate gnuboard7-hello_admin_template
php artisan template:uninstall gnuboard7-hello_admin_template

php artisan plugin:deactivate gnuboard7-hello_plugin
php artisan plugin:uninstall gnuboard7-hello_plugin

php artisan module:deactivate gnuboard7-hello_module
php artisan module:uninstall gnuboard7-hello_module
```

> 제거 순서는 의존성의 역순 — 템플릿 → 플러그인 → 모듈.
> 플러그인이 모듈 훅을 구독하므로 플러그인을 먼저 비활성화해야 합니다.

---

## hidden 플래그와 관리자 UI

모든 Hello 샘플은 manifest 에 `"hidden": true` 가 설정되어 있습니다:

```json
{
    "identifier": "gnuboard7-hello_module",
    "version": "0.1.0",
    "hidden": true
}
```

### 동작

| 대상 | hidden 적용 |
|------|-------------|
| 관리자 UI 기본 응답 (`GET /api/admin/modules` 등) | 제외 |
| artisan 기본 목록 (`module:list`, `plugin:list`, `template:list`) | 제외 |
| 설치/활성화/제거 커맨드 | 정상 동작 |
| 업데이트 감지 (`*:check-updates`, `*:update`) | 정상 동작 |
| 활성화 후 런타임 기능 (라우트/훅/권한) | 정상 동작 |

### 숨김 포함 조회

```bash
# CLI
php artisan module:list --hidden
php artisan plugin:list --hidden
php artisan template:list --hidden

# API
GET /api/admin/modules?include_hidden=1
GET /api/admin/plugins?include_hidden=1
GET /api/admin/templates?include_hidden=1
```

### 학습 후 제거 권장

실전 프로젝트에서는 Hello 샘플을 **제거** 하여 관리자 UI 와 DB 를 정리할 것을 권장합니다. 복제 워크플로우로 새 확장을 만든 후에는 원본 샘플이 더 이상 필요하지 않습니다.

> 상세: [extension-manager.md](extension-manager.md#관리자-ui-필터링-hidden-플래그)

---

## 관련 문서

- [module-basics.md](module-basics.md) — 모듈 기초, hidden 필드
- [plugin-development.md](plugin-development.md) — 플러그인 기초, hidden 필드
- [template-workflow.md](template-workflow.md) — 템플릿 워크플로우, hidden 필드
- [extension-manager.md](extension-manager.md) — 관리자 UI 필터링, `?include_hidden=1`
- [hooks.md](hooks.md) — Action/Filter 훅 (Hello 플러그인 학습에 필수)
- [layout-testing.md](../frontend/layout-testing.md) — 레이아웃 렌더링 테스트 (Hello 템플릿 학습에 필수)
