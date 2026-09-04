# 그누보드7 Basic 템플릿 — 에이전트 가이드

> 이 문서는 이 템플릿을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 템플릿 (sirsoft-basic, type=user) — 방문자가 보는 사이트 전체 화면 166개. 게시판·이커머스 모듈은 방문자 화면을 갖지 않으므로 상점·게시판 UI 는 여기가 소유한다. 서버 코드 0줄
2. 확장 방식: 훅 0/0 — 확장점은 화면 구조다. 다른 확장이 `layout_extensions` 로 조각을 끼우고, `extensions/{확장}/` 오버라이드로 원본 조각을 대체한다
3. 건드리면 안 되는 것: `extends` 없는 독립 레이아웃에서 toast/modal 사용, 상점 경로 하드코딩(표현식 유지), 목록 이동의 `mergeQuery` 누락, 비회원 주문 토큰 진입 정리 제거
4. 작업 위치: `templates/_bundled/sirsoft-basic` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan template:update sirsoft-basic --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
방문자가 보는 **사이트 전체 화면**을 소유하는 사용자(User) 템플릿입니다. 홈·게시판·상점·
마이페이지·인증·오류 화면 166개가 여기 있습니다.

**이것이 왜 템플릿에 있는가**가 이 확장을 이해하는 열쇠입니다. 게시판 모듈도 이커머스 모듈도
레이아웃이 전부 `admin` 그룹이고 방문자 화면을 갖지 않습니다 — 두 모듈은 관리자 CRUD 와 공개
API 까지만 소유하고, 그 API 를 소비해 실제로 그리는 것은 이 템플릿입니다. 상점 디자인을 바꾸는
작업은 이커머스 모듈이 아니라 **여기**입니다.

**설계 원칙 넷**:

1. **모든 화면이 `_user_base` 를 상속한다.** 헤더·푸터·모바일 네비와 토스트·모달 호스트가
   거기 있습니다. `extends` 없이 독립 레이아웃을 만들면 `toast`·`openModal` 이 성공으로
   기록되지만 화면에는 아무것도 나타나지 않습니다.
2. **화면은 조각으로 나눈다.** 166개 중 124개가 partial 입니다. 조각은 여러 화면이 공유하며,
   화면 하나를 고칠 때는 그 화면 이름의 partials 디렉토리를 함께 엽니다.
3. **모듈 설정이 라우트에 스며든다.** 상점 경로 9개가 표현식이라 운영자가 이커머스 설정에서
   상점 경로를 바꾸거나 루트에 두면 라우트가 그에 맞춰 바뀝니다.
4. **다른 확장의 조각을 갈아 끼울 수 있다.** `extensions/{확장}/` 오버라이드가 그 장치이며,
   지금은 주소 검색 조각 하나가 있습니다.

**의도적으로 하지 않는 것**: 서버 코드 일체(PHP 0줄 · 모델 0 · 라우트 파일 없음 · 훅 발행 0).
데이터는 전부 모듈·코어의 공개 API 에서 옵니다. 그래서 이 템플릿을 다른 것으로 바꿔도 데이터는
그대로이고, 반대로 이 템플릿만으로는 아무 기능도 동작하지 않습니다 — manifest 가 게시판·
이커머스·페이지 모듈과 주소 검색 플러그인을 의존으로 선언하는 이유입니다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `template.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json 동기화 |
| `routes.json` | 라우트 → 레이아웃 매핑 | `php artisan template:update sirsoft-basic --force` |
| `layouts/` | 레이아웃 JSON | `php artisan template:update sirsoft-basic --force` (빌드 불필요) |
| `extensions/` | 다른 확장 화면에 주입하는 레이아웃 조각 | `php artisan template:update sirsoft-basic --force` (빌드 불필요) |
| `seo-config.json` | SEO 렌더 설정 | `php artisan template:update sirsoft-basic --force` |
| `src/components/` | React 컴포넌트 | `php artisan template:build` → `php artisan template:update sirsoft-basic --force` |
| `src/handlers/` | 템플릿 전용 액션 핸들러 | `php artisan template:build` → `php artisan template:update sirsoft-basic --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan template:update sirsoft-basic --force` |
| `editor-spec/` | 분할 편집기 스펙 | `php artisan template:update sirsoft-basic --force` |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan template:update sirsoft-basic --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
서버 코드가 없으므로 흐름은 전부 **라우트 → 레이아웃 → 데이터소스 → 컴포넌트**입니다.

**화면 렌더**: 브라우저가 경로 진입 → `routes.json` 이 그 경로에 대응하는 레이아웃을 지목 →
레이아웃이 `_user_base` 를 상속해 헤더·푸터를 얻고 콘텐츠 슬롯을 채움 → `data_sources` 가
모듈·코어의 공개 API 를 호출 → 응답을 컴포넌트에 바인딩. 상점 경로는 이 첫 단계에서
**표현식이 평가**되어 운영자가 지정한 경로가 됩니다.

**장바구니(비회원 포함)**: 진입 시 `initCartKey` 가 localStorage 의 장바구니 키를 확인하고
없으면 API 로 발급받습니다 → 담기·수량 변경·옵션 변경은 `setCartOption` ·
`recalculateCart` 등이 이커머스 API 를 호출하고 결과로 상태를 갱신 → 로그인하면 코어
`auth.after_login` 을 구독하는 이커머스 리스너가 비회원 장바구니를 회원 장바구니로 병합합니다
(그 병합은 서버 쪽 일이며 이 템플릿은 키만 넘깁니다).

**비회원 주문 조회**: 주문 완료 시 서버가 발급한 토큰을 `saveGuestOrderToken` 이 보관 →
조회 화면이 그 토큰으로 API 를 호출하면 서버의 `VerifyGuestOrderToken` 미들웨어가 신원을
확인합니다. **토큰이 곧 신원**이므로 `clearGuestTokenOnEntry` 가 진입 시점에 남은 토큰을
정리합니다 — 공용 PC 에서 다음 사용자가 남의 주문을 열지 못하게 하는 방어입니다.

**본인인증(IDV)**: 어떤 API 가 428 을 돌려주면 코어 인터셉터가 그것을 잡아, 부트스트랩에서
등록한 launcher 로 인증 화면을 엽니다. 인증을 마치면 원래 액션이 재개됩니다 — 각 화면이
428 을 개별 처리하지 않습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 제공 컴포넌트 | 79개 | [제공 컴포넌트](docs/components.md#제공-컴포넌트) |
| 레이아웃 | 166개 | [레이아웃 목록](docs/layouts.md#레이아웃-목록) |
| 전용 핸들러 | 32개 | [템플릿 전용 핸들러](docs/handlers.md#템플릿-전용-핸들러) |
| 확장 오버라이드 | 1개 | [확장 오버라이드](docs/layouts.md#확장-오버라이드) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
이 템플릿은 훅을 **발행하지도 구독하지도 않습니다**(0/0). 템플릿의 확장점은 훅이 아니라
**화면 구조** 자체입니다.

| 확장점 | 어떻게 쓰는가 |
|---|---|
| 레이아웃 166개 | 다른 확장이 `layout_extensions` 로 자기 조각을 끼워 넣습니다 — 이커머스의 헤더 통화 선택기·마이페이지 마일리지 카드, 마케팅의 회원가입 동의 항목이 그 예입니다 |
| 제공 컴포넌트 79개 | 조각을 만드는 확장이 이 컴포넌트로 화면을 구성합니다. **여기 없는 컴포넌트를 쓰면 그 조각은 이 템플릿에서 렌더되지 않습니다** |
| 전용 핸들러 32개 | 조각의 액션에서 부를 수 있습니다. 네임스페이스가 붙은 10개(`sirsoft-basic.*`)는 전체 이름으로, 나머지는 이름만으로 부릅니다 |
| 확장 오버라이드 | `extensions/{확장}/` 에 같은 이름의 조각을 두면 그 확장이 제공한 원본을 대체합니다 |

**다른 확장이 이 템플릿에 화면을 얹는 방향이 정상**입니다. 반대로 이 템플릿이 모듈의 관리자
화면에 개입하지는 않습니다.

`seo-config.json` 도 확장점입니다 — 봇 요청에 서버가 화면을 렌더할 때 어떤 속성을 HTML 로
내보낼지 이 파일이 정합니다. 새 컴포넌트가 텍스트를 담는 새 prop 을 쓰면 `text_props` 에
추가해야 봇 화면에 그 글자가 나타납니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan template:update sirsoft-basic --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` 동기화 + CHANGELOG 기재
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 새 화면을 추가했다면 `_user_base` 를 상속하는지, `routes.json` 에 경로가 등록됐는지 확인
- [ ] 상점 관련 경로·링크는 표현식(`route_path` · `no_route`)을 유지 — 문자열로 굳히지 않는다
- [ ] 목록 클러스터(목록 → 상세 → 형제 상세 → 폼 → 복귀) 이동 전 leg 에 `mergeQuery: true`
- [ ] 텍스트를 담는 새 컴포넌트 prop 을 도입했다면 `seo-config.json` 의 `text_props` 에 추가 (봇 화면에서만 글자가 사라진다)
- [ ] 의존 모듈(게시판·이커머스·페이지)의 공개 API 응답 형태가 바뀌면 이 템플릿의 화면이 조용히 빈다 — 그 모듈을 올릴 때 함께 확인
- [ ] `extensions/sirsoft-daum_postcode/` 오버라이드는 원본이 바뀌어도 따라가지 않는다 — 그 플러그인 업그레이드 후 확인
- [ ] TSX/TS 를 고쳤다면 `template:build --production` 후 `dist/` 동반 커밋 (`sourceMappingURL` 잔존 금지)
- [ ] 프론트엔드 변경은 Playwright spec 동반 — 단위 테스트만으로는 화면 회귀가 드러나지 않는다
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec/` 블록을 함께 갱신 — 컴포넌트는 팔레트·역량·중첩 **넷 다** 손대야 편집기에서 온전히 동작하고, 하나만 빠지면 절반만 동작한다. 반영은 `php artisan template:update sirsoft-basic --force` (편집기는 활성 디렉토리만 읽는다)

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| `extends` 없는 독립 레이아웃에서 `toast`·`openModal` 사용 | `_user_base` 를 상속하거나, 독립 레이아웃이라면 `Toast`·모달 호스트 컴포넌트를 직접 마운트 | 호스트가 없으면 핸들러는 성공으로 기록되는데 화면에는 아무것도 나타나지 않는다 |
| 상점 경로를 `/shop/...` 로 하드코딩 | `routes.json` 의 표현식 유지 (`route_path` · `no_route` 반영) | 운영자가 경로를 바꾸거나 루트에 두면 하드코딩한 링크만 조용히 깨진다 |
| 목록 → 상세 → 목록 이동에서 `mergeQuery` 누락 | 목록 클러스터 내 모든 이동에 `"mergeQuery": true` | 검색어·페이지·필터가 사라져 사용자가 처음부터 다시 찾아야 한다 |
| 비회원 주문 토큰을 진입 시점에 정리하지 않음 | `clearGuestTokenOnEntry` 유지 | 토큰이 곧 신원이다 — 공용 PC 에서 다음 사용자가 남의 주문을 연다 |
| `401` 오류 레이아웃에서 로그인으로 직접 리다이렉트 | 코어 `TemplateApp.showRouteError` 가드에 위임 | 이중 리다이렉트가 되고, 돌아올 위치를 코어가 이미 관리한다 |
| 모듈·플러그인에서 `AuthManager.updateConfig()` 호출 | 템플릿 부트스트랩에서만 | 여러 확장이 로그인 경로를 다투면 어느 값이 이기는지 설치 순서에 좌우된다 |
| 새 컴포넌트가 텍스트를 담는 prop 을 추가하면서 `seo-config.json` 을 그대로 두기 | `text_props` 에 그 prop 추가 | 봇 화면에서만 그 글자가 사라진다 — 사람 눈에는 정상이라 검색 노출이 줄어든 뒤에야 드러난다 |
| 레이아웃 JSON 에 빌드된 CSS 에 없는 Tailwind 클래스 사용 | 기존 레이아웃에 쓰인 클래스이거나 빌드 산출물에 존재하는지 확인 | 그 스타일만 조용히 빠져 화면이 어긋난다 |
| `dist/` 재빌드 없이 `src/` 만 고치고 커밋 | `template:build --production` 후 `dist/` 동반 커밋 | 브라우저가 받는 것은 커밋된 `dist/` 다 — 소스 수정이 사문화된다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 0개 | — |
| Vitest | 143개 | `vitest.config.ts` |
| Playwright | 8개 | `tests/Playwright` |
| 시나리오 매니페스트 | 3개 | `tests/scenarios` |

```bash
# Vitest (확장 디렉토리에서) (PowerShell)
cd templates/_bundled/sirsoft-basic && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd templates/_bundled/sirsoft-basic && npm run test:e2e -- specs/<대상>.spec.ts

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/components.md](docs/components.md) | 템플릿이 제공하는 컴포넌트 | ✅ |
| [docs/layouts.md](docs/layouts.md) | 레이아웃 목록과 라우트 매핑 | ✅ |
| [docs/handlers.md](docs/handlers.md) | 템플릿 전용 핸들러와 부트스트랩 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
