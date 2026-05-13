# 플러그인 스캐폴딩 (create-plugin)

새로운 플러그인의 기본 구조를 생성합니다.

## TL;DR (5초 요약)

```text
1. 명령: /create-plugin vendor-plugin
2. 위치: plugins/_bundled/vendor-plugin/
3. 참조 샘플: plugins/_bundled/gnuboard7-hello_plugin/ (학습용 최소 플러그인, Action+Filter 훅)
4. hidden 플래그: manifest.hidden=true 시 관리자 UI 제외 (CLI 정상)
5. 생성 후: extension:update-autoload → plugin:install → plugin:activate
```

## 사용법

```text
/create-plugin vendor-plugin
```

**예시**: `/create-plugin sirsoft-payment`

## 입력 형식

- `vendor-plugin` — 벤더명과 플러그인명을 하이픈(-)으로 연결
- 네임스페이스 자동 변환: `sirsoft-payment` → `Plugins\Sirsoft\Payment\`
- 언더스코어(_) → PascalCase: `sirsoft-daum_postcode` → `Plugins\Sirsoft\DaumPostcode\`

## 생성 위치

`plugins/_bundled/vendor-plugin/`

## 생성되는 파일

| 파일 | 설명 |
| ---- | ---- |
| `plugin.json` | 메타데이터 SSoT (identifier, version, assets, loading 등) |
| `plugin.php` | AbstractPlugin 상속 엔트리 클래스 (루트 위치!) |
| `composer.json` | PSR-4 오토로딩 (src/ + ./ 매핑) |
| `CHANGELOG.md` | Keep a Changelog 형식 |
| `LICENSE` | MIT 라이선스 |
| `config/settings/defaults.json` | 설정 기본값 (_meta, defaults, frontend_schema) |
| `resources/lang/{ko,en}.json` | 프론트엔드 다국어 (백엔드 PHP 다국어 없음) |
| `src/routes/web.php` | 웹 라우트 (선택) |
| `tests/PluginTestCase.php` | 테스트 기반 클래스 |

## 생성되는 디렉토리

- `src/Listeners/` — 훅 리스너
- `src/Services/` — 서비스 클래스
- `resources/extensions/` — layout_extensions JSON
- `resources/layouts/admin/` — 설정 UI 레이아웃
- `tests/Feature/`, `tests/Unit/` — 테스트

## plugin.php 구현 메서드

| 메서드 | 설명 |
| ------ | ---- |
| `getMetadata()` | 메타데이터 (author, license, homepage, keywords) |
| `getDependencies()` | 의존성 (모듈만 가능, 플러그인 간 의존 금지) |
| `getSettingsSchema()` | 설정 스키마 (type, default, label, hint) |
| `getConfigValues()` | 설정 기본값 |
| `getHookListeners()` | 훅 리스너 클래스 목록 |
| `getHooks()` | 제공하는 훅 정의 (name, type, description, parameters) |

## 모듈과의 주요 차이

| 항목 | 모듈 | 플러그인 |
| ---- | ---- | -------- |
| 엔트리 위치 | module.php (src root) | plugin.php (프로젝트 루트!) |
| 베이스 클래스 | AbstractModule | AbstractPlugin |
| 권한/역할/메뉴 | 있음 | 없음 |
| DB 모델 | 있음 | 선택적 |
| 백엔드 다국어 | PHP 파일 | 없음 |
| 레이아웃 | 완전한 페이지 | settings.json만 (나머지 layout_extensions) |
| 의존성 | 모듈+플러그인 | 모듈만 |

## 생성 후 다음 단계

```bash
php artisan extension:update-autoload
php artisan plugin:install vendor-plugin
php artisan plugin:activate vendor-plugin
```

## 참조 문서

- [플러그인 개발 가이드](../../extension/plugin-development.md)
- [훅 시스템](../../extension/hooks.md)
- [모듈 환경설정 시스템](../../extension/module-settings.md)
- [Changelog 규칙](../../extension/changelog-rules.md)
