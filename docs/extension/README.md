# 그누보드7 확장 시스템 개발 가이드

> 이 문서는 그누보드7의 확장 시스템 전체 개요와 각 상세 문서에 대한 링크를 제공합니다.

---

## 핵심 원칙

```text
필수: /modules, /plugins 디렉토리 스캔으로 자동 발견 (composer.json 하드코딩 금지)
필수: 모든 확장 작업은 Artisan 커맨드로 수행
✅ 필수: 훅 시스템을 통한 코어 수정 최소화
```

---

## 확장 시스템 개요

G7은 **동적 로딩** 기반의 확장 시스템을 제공합니다:

- **모듈**: 독립적인 비즈니스 기능 단위 (예: 이커머스, 블로그)
- **플러그인**: 특정 기능 확장 (예: 결제, 배송)
- **템플릿**: UI 렌더링 및 레이아웃 정의 (Admin, User)
- **훅 시스템**: 코어 수정 없이 로직 확장

---

## 문서 구조

### 훅 시스템

| 문서 | 설명 |
|------|------|
| [hooks.md](hooks.md) | Action/Filter Hook, 리스너 구현, 우선순위 |

### 모듈 개발

| 문서 | 설명 |
|------|------|
| [module-basics.md](module-basics.md) | AbstractModule, 디렉토리 구조, 네이밍 규칙 |
| [module-routing.md](module-routing.md) | API/Web 라우트, 자동 Prefix, routes.json |
| [module-layouts.md](module-layouts.md) | 레이아웃 등록, 오버라이드, 상속 |
| [module-commands.md](module-commands.md) | Artisan 커맨드 (install, activate, uninstall) |
| [module-i18n.md](module-i18n.md) | 백엔드/프론트엔드 다국어 분리 |

### 레이아웃 확장

| 문서 | 설명 |
|------|------|
| [layout-extensions.md](layout-extensions.md) | 동적 UI 주입 (Overlay, Extension Point), 템플릿 오버라이드 |

### 플러그인 개발

| 문서 | 설명 |
|------|------|
| [plugin-development.md](plugin-development.md) | PluginInterface, 격리성, 의존성 원칙 |

### 확장 관리자

| 문서 | 설명 |
|------|------|
| [extension-manager.md](extension-manager.md) | Composer 오토로드, 서비스 등록 |

### 템플릿 시스템

| 문서 | 설명 |
|------|------|
| [template-basics.md](template-basics.md) | Admin/User 템플릿, 메타데이터, 버전 히스토리 |
| [template-routing.md](template-routing.md) | 라우트 정의, 언어 파일 경로 규칙 |
| [template-security.md](template-security.md) | API 서빙, 파일 확장자 화이트리스트 |
| [template-caching.md](template-caching.md) | 캐시 계층, 무효화 전략 |
| [template-commands.md](template-commands.md) | Artisan 커맨드 (install, activate) |

### 권한 및 메뉴 시스템

| 문서 | 설명 |
|------|------|
| [permissions.md](permissions.md) | Role, Permission, 자동 관리 |
| [menus.md](menus.md) | 메뉴 권한, 시더 |

---

## 확장 타입별 네이밍 규칙

| 타입 | 디렉토리명 | 네임스페이스 | Composer 경로 |
|------|-----------|-------------|---------------|
| 모듈 | `vendor-module` | `Modules\Vendor\Module\` | `modules/vendor-module` |
| 플러그인 | `vendor-plugin` | `Plugins\Vendor\Plugin\` | `plugins/vendor-plugin` |
| 템플릿 | `vendor-template` | - | `templates/vendor-template` |

**예시**:
- 모듈: `sirsoft-ecommerce` → `Modules\Sirsoft\Ecommerce\`
- 플러그인: `sirsoft-payment` → `Plugins\Sirsoft\Payment\`
- 템플릿: `sirsoft-admin_basic`

---

## 훅 네이밍 규칙

```
[vendor-module].[entity].[action]_[timing]
```

**타이밍 접미사**:
- `before_*`: 작업 실행 전
- `after_*`: 작업 실행 후
- `on_*`: 특정 이벤트 발생 시
- `filter_*`: 데이터 필터링

**예시**:
```
sirsoft-ecommerce.product.before_create
sirsoft-ecommerce.product.after_update
sirsoft-ecommerce.product.filter_create_data
sirsoft-ecommerce.order.on_payment_success
```

---

## 주요 Artisan 커맨드

### 모듈 관련

```bash
php artisan module:list                    # 모듈 목록 조회
php artisan module:install [identifier]    # 모듈 설치
php artisan module:activate [identifier]   # 모듈 활성화
php artisan module:deactivate [identifier] # 모듈 비활성화
php artisan module:uninstall [identifier]  # 모듈 제거
php artisan module:cache-clear             # 모듈 캐시 정리
```

### 템플릿 관련

```bash
php artisan template:list                    # 템플릿 목록 조회
php artisan template:install [identifier]    # 템플릿 설치
php artisan template:activate [identifier]   # 템플릿 활성화
php artisan template:deactivate [identifier] # 템플릿 비활성화
php artisan template:uninstall [identifier]  # 템플릿 제거
php artisan template:cache-clear             # 템플릿 캐시 정리
```

### 확장 관리자

```bash
php artisan extension:update-autoload   # Composer 오토로드 업데이트
```

---

## 빠른 시작 가이드

### 새 모듈 개발 시

1. [module-basics.md](module-basics.md) - 기본 구조 이해
2. [module-routing.md](module-routing.md) - 라우트 설정
3. [module-i18n.md](module-i18n.md) - 다국어 지원
4. [module-layouts.md](module-layouts.md) - 레이아웃 등록 (필요시)
5. [hooks.md](hooks.md) - 훅 리스너 구현 (필요시)

### 새 플러그인 개발 시

1. [plugin-development.md](plugin-development.md) - 플러그인 구조 이해
2. [hooks.md](hooks.md) - 훅 리스너 구현

### 템플릿 커스터마이징 시

1. [template-basics.md](template-basics.md) - 템플릿 타입 이해
2. [template-routing.md](template-routing.md) - 라우트/언어 파일
3. [template-security.md](template-security.md) - 보안 규칙

---

## 관련 문서

- [AGENTS.md](../../../AGENTS.md) - 프로젝트 개발 가이드
- [backend/](../backend/) - 백엔드 개발 가이드
- [frontend/](../frontend/) - 프론트엔드 개발 가이드
- [database-guide.md](../database-guide.md) - 데이터베이스 가이드