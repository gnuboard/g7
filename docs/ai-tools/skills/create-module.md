# 모듈 스캐폴딩 (create-module)

새로운 모듈의 기본 구조를 생성합니다.

## 사용법

```
/create-module vendor-module
```

**예시**: `/create-module sirsoft-ecommerce`

## 입력 형식

- `vendor-module` — 벤더명과 모듈명을 하이픈(-)으로 연결
- 네임스페이스 자동 변환: `sirsoft-ecommerce` → `Modules\Sirsoft\Ecommerce\`
- 언더스코어(_) → PascalCase: `sirsoft-order_management` → `Modules\Sirsoft\OrderManagement\`

## 생성 위치

`modules/_bundled/vendor-module/`

## 생성되는 파일

| 파일 | 설명 |
|------|------|
| `module.json` | 메타데이터 SSoT (identifier, version, dependencies 등) |
| `module.php` | AbstractModule 상속 엔트리 클래스 |
| `composer.json` | PSR-4 오토로딩 (src/, database/seeders/, database/factories/) |
| `CHANGELOG.md` | Keep a Changelog 형식 |
| `LICENSE` | MIT 라이선스 |
| `config/settings/defaults.json` | 기본 설정 (_meta, defaults, frontend_schema) |
| `src/lang/{ko,en}/*.php` | 백엔드 다국어 (messages, validation, exceptions) |
| `resources/lang/{ko,en}.json` | 프론트엔드 다국어 |
| `resources/routes.json` | 프론트엔드 라우트 정의 |
| `src/routes/web.php` | 웹/API 라우트 |
| `resources/layouts/admin/pages/index.json` | 초기 관리자 레이아웃 |

## 생성되는 디렉토리

- `src/Contracts/Repositories/` — Repository 인터페이스
- `src/Enums/` — Enum 정의
- `src/Exceptions/` — Custom Exception
- `src/Http/Controllers/Admin/` — 관리자 컨트롤러
- `src/Http/Requests/Admin/` — FormRequest
- `src/Http/Resources/` — API Resource
- `src/Listeners/` — 훅 리스너
- `src/Models/` — Eloquent 모델
- `src/Providers/` — 서비스 프로바이더
- `src/Repositories/` — Repository 구현체
- `src/Services/` — 서비스 클래스
- `database/migrations/` — 마이그레이션
- `database/seeders/` — 시더
- `database/factories/` — 팩토리
- `tests/Feature/`, `tests/Unit/` — 테스트

## module.php 구현 메서드

| 메서드 | 설명 |
|--------|------|
| `getRoles()` | 모듈 역할 정의 |
| `getPermissions()` | 계층형 권한 (categories → permissions → action/name/type/roles) |
| `getAdminMenus()` | 관리자 메뉴 (slug, url, icon, order, permission) |
| `getSeeders()` | 설치 시 실행할 시더 목록 |
| `getHookListeners()` | 훅 리스너 클래스 목록 |
| `getStorageDisk()` | 스토리지 디스크 설정 |

## 생성 후 다음 단계

```bash
php artisan extension:update-autoload
php artisan module:install vendor-module
php artisan module:activate vendor-module
```

## 참조 문서

- [모듈 기초](../../extension/module-basics.md)
- [모듈 라우트](../../extension/module-routing.md)
- [모듈 레이아웃](../../extension/module-layouts.md)
- [모듈 다국어](../../extension/module-i18n.md)
- [모듈 커맨드](../../extension/module-commands.md)
