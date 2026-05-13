# 템플릿 스캐폴딩 (create-template)

새로운 템플릿(관리자 또는 사용자)의 기본 구조를 생성합니다.

## TL;DR (5초 요약)

```text
1. 명령: /create-template vendor-template [--type=admin|user]
2. 위치: templates/_bundled/vendor-template/
3. 참조 샘플: templates/_bundled/gnuboard7-hello_admin_template/ (Admin), gnuboard7-hello_user_template/ (User)
4. hidden 플래그: manifest.hidden=true 시 관리자 UI 제외 (CLI 정상)
5. 생성 후: npm install → template:build → template:install → template:activate
```

## 사용법

```text
/create-template vendor-template [--type=admin|user]
```

**예시**:

- `/create-template sirsoft-admin_modern` — Admin 템플릿
- `/create-template sirsoft-modern --type=user` — User 템플릿

기본값은 `admin`입니다.

## 입력 형식

- `vendor-template` — 벤더명과 템플릿명을 하이픈(-)으로 연결
- Admin 네이밍 권장: `vendor-admin_xxx` (예: `sirsoft-admin_modern`)
- User 네이밍 권장: `vendor-xxx` (예: `sirsoft-modern`)

## 생성 위치

`templates/_bundled/vendor-template/`

## 생성되는 파일

| 파일 | 설명 |
| ---- | ---- |
| `template.json` | 메타데이터 SSoT (type, components, error_config 등) |
| `routes.json` | 프론트엔드 라우트 정의 |
| `components.json` | 컴포넌트 레지스트리 매핑 |
| `CHANGELOG.md` | Keep a Changelog 형식 |
| `LICENSE` | MIT 라이선스 |
| `lang/{ko,en}.json` | 다국어 |
| `layouts/_[type]_base.json` | 베이스 레이아웃 (slot 시스템) |
| `layouts/errors/*.json` | 에러 레이아웃 6종 (401/403/404/500/503/maintenance) |
| `layouts/[초기 페이지].json` | admin: admin_dashboard / user: home |
| `src/index.ts` | 컴포넌트 등록 엔트리 |
| `package.json` | npm 패키지 설정 |
| `vite.config.ts` | Vite 빌드 (IIFE 출력) |
| `vitest.config.ts` | Vitest 테스트 설정 |
| `tsconfig.json` | TypeScript 설정 |

## 생성되는 디렉토리

- `src/components/basic/` — 기본 컴포넌트 (Div, Button, Input 등)
- `src/components/composite/` — 합성 컴포넌트 (Modal, Toast 등)
- `src/components/layout/` — 레이아웃 컴포넌트 (Container, Flex, Grid, Section)
- `assets/css/`, `assets/fonts/`, `assets/images/` — 정적 에셋
- `preview/` — 미리보기 이미지
- `__tests__/layouts/` — 레이아웃 렌더링 테스트

## Admin vs User 차이

| 항목 | Admin | User |
| ---- | ----- | ---- |
| type | `"admin"` | `"user"` |
| 라우트 접두사 | `*/admin/*` | `/` |
| auth_required 기본 | `true` | `false` |
| 버전 히스토리 | 미지원 (읽기 전용) | 지원 (편집 가능) |
| 베이스 레이아웃 | header + sidebar + content + footer | header + content + footer |
| 초기 페이지 | `admin_dashboard` | `home` |
| 필수 composite | AdminHeader, AdminSidebar, AdminFooter, Modal, Toast | Header, Footer, Modal, Toast |
| features | 미포함 | dark_mode, responsive, multi_language, multi_currency |

## 생성 후 다음 단계

```bash
# 1. 패키지 설치
cd templates/_bundled/vendor-template
npm install

# 2. 컴포넌트 구현 (src/components/)

# 3. 빌드 및 설치
php artisan template:build vendor-template
php artisan template:install vendor-template
php artisan template:activate vendor-template
```

## 참조 문서

- [템플릿 기초](../../extension/template-basics.md)
- [템플릿 라우트](../../extension/template-routing.md)
- [템플릿 워크플로우](../../extension/template-workflow.md)
- [템플릿 보안](../../extension/template-security.md)
- [컴포넌트 개발 규칙](../../frontend/components.md)
