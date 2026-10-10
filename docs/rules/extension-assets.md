# 확장 에셋·문서·캐시·의존성 규칙

루트 AGENTS.md 의 절대 금지 패턴 중 **확장 에셋·문서·캐시·의존성 규칙** 영역. 표의 왼쪽은 금지, 오른쪽이 올바른 사용이다.

## TL;DR (5초 요약)

```text
1. 확장 시스템 핵심
2. 확장·템플릿 구동 에셋은 자체 제공한다
3. 확장은 자기 개발자 문서를 소유한다
4. 의존성 감사 신호는 거짓일 수 있다
5. 확장 캐시 버전은 트레이트 게터로만 읽고, 만료시키지 않는다
```

> 원본: 이 규칙들은 2026-09-10 까지 루트 AGENTS.md 「CRITICAL RULES」 에 있었다. 제목·표는 그대로 옮겼고 앵커는 바뀌지 않았다. 색인: [docs/rules/README.md](README.md)

## 확장 시스템 핵심

> 상세: [docs/extension/](../extension/)

```text
필수: 모든 확장 작업은 _bundled 디렉토리에서만 수행 (활성 디렉토리 직접 수정 금지)
필수: 프로덕션 반영은 update 커맨드로만 수행 (_bundled → 활성 디렉토리)
필수: 확장 코드 변경 시 manifest 버전 업 (미변경 시 업데이트 감지 불가)
필수: 버전 업 시 CHANGELOG.md 기록 — Keep a Changelog 표준 (미기록 시 버전 업 불가)
필수: StorageInterface 사용 (Storage::disk() 직접 호출 금지)
필수: ActionDispatcher 에 핸들러를 등록하는 확장은 재등록 진입점을 window 전역에 고정 이름으로 노출 — 모듈 window.__[Name].initModule, 플러그인 window.__[Name].initPlugin (미노출 시 로케일 전환 후 해당 확장 액션이 전부 무반응, 에러·토스트 없음). 진입점은 핸들러 재등록만 수행
필수: 확장 미들웨어는 getMiddleware() 로 부착 대상(targets) 명시 선언 (self-gate) — SP Kernel 미들웨어 그룹 직접 조작·라우트 파일 자기 미들웨어 FQCN 부착 금지, 무규율 전역 개입 금지
필수: 라우트 정의를 바꾸는 지점은 App\Support\RouteCacheHelper::rebuild() 로 라우트 캐시 갱신 — 확장 설치/활성화/비활성화/삭제/업데이트, 코어 업데이트·업그레이드 스텝. route:clear/route:cache 를 각 지점에 직접 흩어 놓지 않는다 (누락 발생, 비우기만 하면 재생성되지 않아 성능 이점 영구 소실). 훅 캐시와 달리 라우트 캐시에는 스캔 폴백이 없어 캐시에 없는 라우트는 예외·경고 없이 404. 파일 교체 중인 코어 업데이트는 중간에 clear(), 끝에서 rebuild(). 템플릿·모듈 설정은 서버 라우트 무관 (상세: docs/backend/routing.md "라우트 캐시")
필수: 확장 라우트는 활성 상태인 확장의 것만 등록한다 — 모듈·플러그인 두 라우트 프로바이더가 같은 기준을 쓴다. 게이트가 한쪽에만 있으면 그 비대칭은 오류가 아니라 "조용히 열린 경로" 로만 나타난다: 비활성화해도 화면·메뉴·에셋만 사라지고 API 는 계속 호출 가능하며, 컨트롤러가 정상 처리하므로 오류도 로그도 남지 않는다
필수: 그 rebuild() 는 확장 상태 캐시 무효화(invalidate*StatusCache()) 뒤에 온다 — route:cache 는 새 앱을 부팅해 라우트를 수집하는데 그 부팅의 확장 라우트 프로바이더는 DB 가 아니라 캐시된 활성 확장 목록(TTL 기본 1일)을 읽으므로, 먼저 구우면 방금 바뀐 상태가 빠진 채 박제되고 자가 회복되지 않는다 (활성화 → 그 확장 API 전량 404 / 비활성화 → 끈 확장 API 가 계속 호출 가능 / 업데이트 → 404 + 훅 리스너 누락). 무효화는 굽기 직전이 아니라 DB 상태 쓰기 직후에 둔다 — 같은 목록을 읽는 굽기가 라우트 캐시 말고도 있다 (오토로드 갱신 안의 훅 매핑 캐시). update 경로만 예외: Updating 전이 직후에는 비우지 않고 (비우면 그 창의 오토로드 갱신이 그 확장을 비활성으로 판정해 훅 리스너를 떨군다) 상태 복원 직후에 비운 뒤 ExtensionManager::regenerateHookCache() 로 훅 캐시를 다시 굽는다. 훅 캐시 폴백은 파일 부재·손상에만 작동해 내용이 stale 한 경우는 조용히 통과한다
필수: 코어 레이아웃에 모듈 UI 주입은 layout_extensions만 사용
필수: 모든 확장 작업은 Artisan 커맨드로 수행
```

> 상세 규칙 (플러그인 의존성, 훅 시스템, 버전 동기화, 업그레이드 스텝 등): [docs/extension/](../extension/) 각 문서 참조

### 확장 타입 요약

| 타입 | 네이밍 | 네임스페이스 | 예시 |
|------|--------|-------------|------|
| 모듈 | vendor-module | Modules\Vendor\Module\ | sirsoft-ecommerce |
| 플러그인 | vendor-plugin | Plugins\Vendor\Plugin\ | sirsoft-payment |
| 템플릿 | vendor-template | - | sirsoft-admin_basic |

## 버전 동기화 의무

코어 또는 번들 확장의 공개 표면을 수정할 때, 그 변경의 영향 범위에 있는 다른 확장의 버전 제약(`g7_version`, `dependencies.{modules|plugins}`)을 함께 갱신한다.

### ① 코어 → 확장 동기화 (`g7_version`)

- 트리거: 코어 공개 확장 표면(`app/Extension/Abstract*`, `HookManager`, `ExtensionManager`, `ModuleManager`, `PluginManager`, `TemplateManager`, `app/Contracts/Extension/**`, `app/Extension/Helpers/**`, `app/Repositories/Concerns/**`, `app/Seo/Contracts/**`, `app/ActivityLog/**` 공개 API, 아래 [확장이 쓰는 코어 지원 클래스](#확장이-쓰는-코어-지원-클래스), 루트 `CHANGELOG.md` 의 Added/Changed/Removed) 수정
- 조치: 영향 받는 번들 확장의 `g7_version` 상향 + 각 확장 CHANGELOG 에 변경 기재

#### 확장이 쓰는 코어 지원 클래스

확장이 직접 `use` 하는 코어 클래스는 위 디렉터리 밖에 있어도 공개 표면이다. 시그니처·반환 형식·동작을 바꾸면 위와 같이 `g7_version` 을 올리고 CHANGELOG 에 적는다. 확장이 새 코어 클래스를 쓰기 시작하면 이 표에 함께 올린다.

| 영역 | 클래스(경로) | 쓰는 번들 확장 |
| --- | --- | --- |
| 개인정보 보존·휴면 | `app/Privacy/**`(`Contracts\PrivacyRetentionHandlerInterface`·`PrivacyManualRunnerInterface`, `DTO\PrivacyRetentionTarget`, `PrivacyRetentionManager`), `app/Http/Requests/Admin/PrivacyTargetListRequest.php`, `app/Http/Resources/PrivacyTargetCollection.php` | 게시판, 이커머스, gdpr |
| 동의 | `app/Extension/Consent/**`(`ConsentManager`·`ConsentSnapshot`·`ConsentSubject`) | gdpr |
| 알림 | `app/Services/NotificationRecipientCapability.php`, `app/Services/NotificationTemplateService.php`, `app/Notifications/DeliveryReport.php`, `app/Notifications/GuestNotifiable.php`, `app/Notifications/Identity/IdentityNotificationType.php`, `app/Contracts/Notifications/ChannelReadinessCheckerInterface.php` | 게시판, 이커머스, gdpr |
| 회원 연락처 노출 | `app/Http/Resources/Traits/ExposesMemberContact.php` | 게시판, 이커머스 |
| 사이트 주소·외부 출처 | `app/Support/SiteUrl.php`, `app/Support/TrustedScriptHosts.php` | 게시판, 이커머스, gdpr, koreaexim, 결제 4종, 본인인증 2종 |
| 권한 | `app/Support/PermissionEscalationGuard.php`, `app/Exceptions/PermissionEscalationException.php`, `app/Support/GuestRoleResolver.php`, `app/Contracts/Repositories/PermissionRepositoryInterface.php` | 게시판 |
| 첨부·본문 미디어 | `app/Helpers/InlineMediaPolicy.php`, `app/Rules/AttachmentFileContent.php`, `app/Services/AttachmentService.php`, `app/Contracts/Repositories/AttachmentRepositoryInterface.php`, `app/Enums/AttachmentSourceType.php`, `app/Exceptions/UnsafeStoragePathException.php` | 게시판, 이커머스, 페이지, ckeditor5 |
| 본인인증 | `app/Enums/IdentityVerificationChannel.php`, `app/Enums/IdentityVerificationPurpose.php` | totp |
| SEO | `app/Seo/SeoRenderRequest.php` | 게시판 |
| 설정 응답 | `app/Traits/FiltersFrontendSchema.php` | 페이지 |

### ② 확장 → 확장 동기화 (`dependencies.{modules|plugins}`)

- 트리거: 번들 모듈/플러그인의 공개 Service/Contract/Repository/Model/Route, 발행 훅·이벤트, CHANGELOG 수정
- 조치: 그 확장에 의존하는 다른 번들 확장 전수 스캔 → 최소 버전 제약 상향 여부 판정

### ③ manifest ↔ 패키지 파일 동기화

- 트리거: `module.json`·`plugin.json`·`template.json` 의 `version` 변경
- 조치: 같은 확장 디렉토리의 `package.json` → `composer.json` 순으로 같은 버전으로 갱신하고, 버전 섹션을 CHANGELOG 에 기록한다. 번들 확장의 `composer.json` 버전을 올리면 같은 디렉토리의 `composer.lock` 도 `composer update --lock` 으로 맞춘다

### 판정 순서

1. 기존 소비자 API 시그니처/동작을 건드렸는가 → 소비 확장 최소 버전 상향
2. 새 공개 API 가 도입되었는가 → 후보 확장 전수 스캔 후 검토
3. 의존 관계 B 의 공개 API 가 변경되었는가 → A 의 `dependencies.B` 상향
4. 동기화 대상이 없다면 그 근거("순수 내부 리팩토링" 등)를 변경 이력에 기록

> 표기 규칙·재검토 트리거·CHANGELOG 기재 형식: [changelog-rules.md "코어 버전 제약 정책"](../extension/changelog-rules.md#8-코어-버전-제약-정책)

## 확장·템플릿 구동 에셋은 자체 제공한다

브라우저가 화면을 그리기 위해 제3자 CDN 에 도달해야 하면, 그 도달 실패는 **예외도 로그도 남기지 않고 화면 기능만 조용히 사라진다.** 폐쇄망·방화벽·광고차단기에서 재현되며 자체 서버 로그에 흔적이 없어 운영자가 원인을 특정할 수 없다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 구동 자산(js/css/웹폰트)을 외부 CDN 에서 실시간 로드 | 확장이 `dist/vendor/{lib}/{version}/` 에 동봉하고 same-origin 서빙 |
| `trusted_script_hosts` 만 선언하고 사유는 생략 | `trusted_script_hosts_reason` 에 호스트별 사유 동반 — 자체 제공이 원칙이고 예외는 근거가 코드에 남는다 |
| 자산 URL 을 문자열로 조립 (`'/api/plugins/assets/'+id+'/…'`) | `G7Core.asset.{template,module,plugin}` — 확장자를 정적 location 이 가로채는 서버에서 조립 URL 만 404 가 된다 |
| AMD 로더·워커에 `G7Core.asset.template()` 결과를 base 로 전달 | `G7Core.asset.templateDir()` — `template()` 의 쿼리 형태는 경로를 인코딩해 뒤에 파일명을 이어 붙일 수 없다. 확장자 없는 모드의 `templateDir()` 은 인코딩하지 않은 `?file=` 디렉토리이고 서빙이 `.js` 없는 AMD 모듈 id 를 메운다. 그래도 받지 못하는 환경이 있으므로 **소비자가 폴백을 갖춘다** |
| CSS 로드에 `onerror` 미설치 또는 `resolve()` 로 삼킴 | `loadStylesheetWithRetry` — 아이콘만으로 조작하는 버튼이 있는 화면에서 스타일 소실은 곧 조작 불능이다 |
| 자산 실패를 `console.error` 한 줄로 끝냄 | `G7Core.assets.notifyFailure({id,label,retry})` — 사용자가 사실을 알고 조치할 수 있어야 한다 |
| 편집기·코드편집기 확보 실패 시 빈 컨테이너를 남김 | 평문 입력(textarea) 폴백 + 저장 계약 유지(`{name}_mode='text'`) + 재시도 시 입력 내용 승계 |
| CSS `@import` 를 `@plugin`·`@config` **뒤**에 둠 | `@import` 를 전부 위로 — 명세상 다른 at-rule 뒤의 `@import` 는 무시되어 **그 파일이 통째로 빌드에서 사라진다**. 빌드 성공·경고 0·테스트 초록이라 화면을 열기 전엔 드러나지 않는다 |
| 확장 `dist/`·`src/` 에 운영자 CSS 를 둠 | 확장 디렉토리 안의 **`custom/`** — 빌드 불필요, 확장 교체가 보존 |
| 번들 확장이 `custom/` 을 담아 배포 | `dist/vendor/` 에 담는다. `custom/` 은 운영자 소유라 보존 계층이 덮어쓰지 않아 **작성자 파일이 영영 반영되지 않는다** |
| 사용자 추가 에셋 URL 을 `ext.cache_version` 으로 무효화 | 파일 서명(수정 시각) — 확장 캐시 버전은 운영자가 파일을 고쳤다고 오르지 않는다 |
| `custom/` 보존을 rename 경로에만 적용 | 교체 **두 경로 모두**(rename · 제자리 동기화 폴백) — 한쪽만 고치면 Windows 잠금 상황에서만 조용히 사라진다 |

동봉 자산은 배포 산출물이므로 `sourceMappingURL` 참조를 남기지 않는다(`.map` 은 gitignore 대상이라 404 가 된다). 인라인 여부는 "없으면 조작 불능인가" 로 가른다 — 아이콘 폰트는 인라인, 글꼴·장식 아이콘은 파일 분리. 분리한 자산을 CSS 가 상대 경로로 가리켜도 된다: 확장 자산 CSS 는 서빙 시점에 내부 상대 참조가 절대 자산 URL 로 치환된다(`ServesRewritableCssAssets`). 치환이 없으면 쿼리 형태(`?file=`) 서버에서 그 참조가 조용히 404 가 된다.

사용자 추가 에셋(`custom/`)은 **출처에 의존하지 않는 서술자**로 해석하고 `core.assets.custom_assets` 필터 훅을 해석기 끝에 둔다. 소비자(뷰 컴포저·프론트 로더·서빙)가 출처를 보면, 나중에 다른 출처(템플릿 환경설정의 화면 입력 등)가 붙을 때 평행 경로가 생기고 "운영자 CSS 가 어디서 오는가" 의 SSoT 가 둘로 갈린다.

> 상세: [module-assets.md](../extension/module-assets.md) "사용자 추가 에셋", [static-asset-publishing.md](../backend/static-asset-publishing.md)
> 자동 검증이 외부 자산 URL 과 번들 확장의 `custom/` 배포를 차단한다. 서술자 형태와 교체 2경로 보존은 테스트가 잠근다.

## 확장은 자기 개발자 문서를 소유한다

`docs/api/**` 는 "엔드포인트가 무엇을 받고 무엇을 돌려주는가" 만 답한다. 확장을 고치려는 쪽이 실제로 묻는 것은 그 앞이다 — **왜 이렇게 설계됐는가 / 어디를 확장해야 하는가 / 무엇을 건드리면 안 되는가.** 그 답이 코드 안에만 있으면 매번 `src/` 전체를 훑어 구조를 재발견하게 되고, 확장이 발행하는 훅은 확장점인데도 사실상 비공개가 된다.

확장마다 `AGENTS.md`(고치는 쪽) · `README.md`(도입·운영 쪽) · `docs/**`(상세)를 두고, 코드에서 실측되는 표는 `php artisan ext:docgen` 이 유지한다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 확장 표면(훅·라우트·권한·모델·레이아웃·핸들러)을 바꾸고 그 확장 문서를 그대로 둠 | 같은 작업 단위에 `ext:docgen --scope={type}:{id}` 재실행 + 낡은 서술 정정 |
| 자동 생성 블록(`@generated:*`) 안쪽을 손으로 고침 | 생성기가 교체하는 자리다 — 코드를 고치거나 블록 **밖**에 서술한다 |
| 생성기에 파괴적 재생성 플래그(`--force`)를 추가 | 기본 동작이 "블록 안쪽 교체" 다. 사람 서술이 소실될 경로를 만들지 않는다 |
| 문서에 없는 블록 키를 생성기가 임의 위치에 주입 | 누락으로 보고하고 사람이 마커 자리를 정한다 (문서 구조는 사람 소유) |
| 필수 문서·섹션·블록 목록을 검사 스크립트에 복제 | `ExtensionDocScaffolder::DOCUMENTS` 단일 SSoT — 스크립트는 `ext:docgen --check --json` 을 소비한다 |
| `TODO:` 마커를 추측으로 채움 | 코드 근거를 읽어 서술한다 — 다섯 자리(의도·흐름·금지패턴·사용방법·트러블슈팅)는 생성기가 채울 수 없는 **왜** 다 |
| `5. 수정 시 동반 의무` 에 코어 횡단 규정을 전부 나열 | 그 확장에 **실제로 걸리는 것만** 추린다 — 전부 적으면 정작 걸리는 항목이 묻힌다 |
| 신규 확장을 문서 없이 스캐폴딩 | `php artisan ext:docgen --scope={type}:{id} --init` 으로 골격을 함께 만든다 — 없으면 21번째 확장부터 다시 문서 없이 태어난다 |
| 확장 문서를 활성 디렉토리에서 작성 | `_bundled` 에서만 작성하고 update 커맨드로 반영 (문서만이면 빌드 불필요) |
| 확장이 훅을 추가할 때 코어 문서를 고침 | 훅 집계는 그 확장의 `docs/extension-points.md` 소유 — 코어에는 총계와 링크만 |
| 레이아웃에 `data_source` 를 추가하고 `editor-spec.json` 의 `sampleData` 를 그대로 둠 | 같은 ID 로 프리뷰 샘플 추가 — 없으면 **편집기 캔버스에서만** 그 영역이 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다 |
| 컴포넌트를 추가하고 팔레트에만 등록 | 템플릿 스펙은 `componentPalette.entries` · `componentPalette.groups` · `nesting` · `componentCapabilities` **넷 다** — 하나만 빠지면 편집기에서 절반만 동작하고, 어느 단계가 빠졌는지는 증상으로만 구분된다 |
| 모듈·플러그인 스펙에 템플릿 컴포넌트의 어휘 선언 (`controls`·`nesting`, 또는 자기 식별자 접두가 아닌 이름의 `componentPalette`·`componentCapabilities`) | 템플릿 컴포넌트의 어휘는 템플릿 소유 — 모듈·플러그인 스펙은 도메인 데이터(`sampleData`·`states`)와 **자기 컴포넌트(`{확장식별자}.{이름}`)의 팔레트·역량**만 담는다. 같은 자리를 두고 다투면 어느 쪽이 이기는지가 병합 순서에 좌우되는데, 자기 식별자가 앞에 붙은 이름은 템플릿 선언과 겹칠 수 없다 |
| 공용 ID(`settings`·`roles`·`me`)를 확장마다 각자 선언 | 템플릿 스펙 한 곳 — 사본이 갈라져도 오류가 나지 않는다 |
| 편집기 스펙 `description` 에 작업 단계·심사 판정·작업 방법을 적음 (`Phase 4/5 에서 추가`·`— 정당`·`전수 스캔 기반`) | **무엇을 담았는가**만 적는다 — 확장만 내려받은 제3자에게 내부 맥락은 해석 불가이고, "다음에 추가" 는 그 항목이 실제로 들어온 뒤에도 남아 **거짓이 된다**. 문서의 한 줄 요약은 이 필드를 옮기지 않고 실측에서 생성한다 |
| 편집기 스펙을 고치고 update 커맨드 생략 | 서빙은 **활성 디렉토리만** 읽는다(`_bundled` 폴백 없음) — 파일은 고쳤는데 편집기에 직전 내용이 그대로 보인다 |

이 결함군은 오류를 남기지 않는다. 문서가 코드와 어긋난 채로 계속 읽히는 것이 유일한 증상이며, 훅 이름이 어긋나면 그 확장을 잡으려던 쪽이 **잡히지 않는 훅을 구독**하게 된다(예외도 경고도 없이 리스너가 호출되지 않을 뿐이다).

mermaid 문법 오류는 GitHub 렌더 시점에만 드러난다. 구조 검사가 잡을 수 있는 것은 선언된 다이어그램 종류·빈 본문·괄호 균형까지이므로, 새 형식은 실제 렌더를 눈으로 확인한다.

`docs/editor-spec.md` 는 세 유형 공통이다 — 편집기 스펙을 두지 않는 확장에도 문서를 둔다. 미보유가 정상일 수 있고("이 확장은 공용 ID 만 쓴다") 그 정상 여부를 적을 자리가 없으면 다음 사람이 부재를 누락으로 오해하거나 필요한 시점을 놓친다. 그 문서의 "샘플 데이터와 페이지 상태" 절은 그 확장 레이아웃의 `data_source` 중 프리뷰 샘플이 붙지 않는 것을 실측해 나열한다 — 이 결함은 편집기 캔버스에서만 빈 화면으로 나타나므로 그 목록이 유일한 통로다.

> 상세: [extension-documentation.md](../extension/extension-documentation.md)
> 자동 검증이 확장 표면 변경 시 문서 미동반과 미채움 마커 잔존을 검출한다. 생성기의 비파괴 계약(블록 밖 손실 0 · 재실행 멱등 · 미존재 키 미주입)과 필수 문서·섹션·블록 목록은 테스트가 잠근다.

## 의존성 감사 신호는 거짓일 수 있다

`npm audit --omit=dev` 는 **`dependencies` 에 선언된 것만** 본다. 실행에 쓰이는 라이브러리가 `devDependencies` 에 있으면 그 패키지는 검사 대상에서 통째로 빠지고, 취약점이 있어도 감사는 0건을 돌려준다. "운영 의존성 취약점 없음" 이라는 완료 조건이 취약한 상태로도 충족된 것처럼 보인다.

동봉(vendored) 제3자 자산은 더 나아가 **어떤 잠금파일에도 없어** 감사 도구가 원리상 볼 수 없다. 그 안에 재번들된 라이브러리가 브라우저로 나간다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 런타임 소스가 import 하는 패키지를 `devDependencies` 에만 선언 | `dependencies` 로 선언 — 검사 대상에 들어가야 감사 신호가 사실이 된다 |
| 루트 잠금파일만 감사하고 완료 선언 | 확장의 잠금파일까지 전수 (`php artisan security:audit-dependencies`) — 루트만 보면 확장 전부가 사각이다 |
| 동봉 자산을 감사 범위에 들었다고 간주 | 잠금파일에 없으므로 **사람이 확인한다** — 점검 명령이 목록으로 노출한다 |
| 잠금만 올리고 재빌드를 생략 | 브라우저가 받는 버전은 커밋된 `dist/` 가 정한다 — 잠금 갱신 후 반드시 재빌드·재게시 |
| "점검 대상 0" 과 "점검 불가" 를 같은 문구로 보고 | 구분 보고 — 뭉뚱그리면 운영자가 "전부 정상" 으로 읽는다 |
| 동봉 자산 버전을 여러 곳에 적고 상향 시 일부만 갱신 | 모든 기재(디렉토리 · 의존성 핀 · 복사 스크립트 · 소스 상수 · 테스트 어서션)를 한 버전으로 — 하나만 어긋나도 그 자산이 404 가 되는데 빌드와 테스트는 통과한다 |
| 상류가 못 고치는 잔여 취약을 감사에서 가리기(`overrides` 로 무마) | 잔여는 **하한과 사유를 기록**하고 그 아래로 내려가는 것만 막는다 — 가리면 그 지점이 영원히 보이지 않는다 |

이 결함군은 예외도 로그도 남기지 않는다. 취약한 라이브러리가 정상 동작하고 감사가 0건을 보고하는 것이 유일한 증상이다. 자동 검증이 앞의 두 항목을 차단하고, 출하 산출물의 정화기 버전과 점검 명령의 모집단은 테스트가 고정한다.

> 상세: [module-assets.md](../extension/module-assets.md), [cheatsheet.md](../cheatsheet.md)

## 확장 캐시 버전은 트레이트 게터로만 읽고, 만료시키지 않는다

확장 캐시 버전(`ext.cache_version`)은 모든 자산 URL 의 `?v=`·정적 게시본(`public/build/ext/{v}/`)·병합 번들 파일명의 좌표다. 그 키를 올리는 단일 지점(`incrementExtensionCacheVersion()`)이 재게시까지 예약하므로, 게시본에 구워지는 입력이 바뀌는 경로는 전부 그 지점을 타야 하고, 키 자체는 만료로 재생성되어서는 안 된다 — 재생성은 정적 파일 전체 재생성이자 전 방문자의 자산 URL 변경이다.

| 금지 | 올바른 사용 |
|------|------------|
| `$cache->get('ext.cache_version', 0)` / `app(CacheInterface::class)->get(...)` / `->forget('ext.cache_version')` 원시 접근 | `ExtensionStaticCacheService::getExtensionCacheVersion()` 또는 트레이트를 조합한 클래스 안의 `self::getExtensionCacheVersion()` — 부재 시 재생성하므로 `cache:clear` 직후 0 이 새지 않고, 컨테이너 바인딩의 확장 네임스페이스 누수도 없다 |
| 트레이트 정적 메서드를 트레이트 이름으로 직접 호출 (`ClearsTemplateCaches::getExtensionCacheVersion()`) | 트레이트를 조합한 코어 클래스 경유 — PHP 8.1+ E_DEPRECATED 이고, 트레이트 상수는 `self::` 로 읽을 수 없다 |
| 게시본에 구워지는 설정값(`general.asset_url_mode`)을 바꾸는 경로에서 bump 누락 | 저장·단건 저장·복원·CLI 어느 경로든 `incrementExtensionCacheVersion()` — 번들 CSS 안의 `url()` 형태가 본문에 구워져 있다 |
| 게시 입력이 바뀌는 수명주기 경로를 조건부 bump 에 위임 (템플릿 update 가 레이아웃 변경 건수 > 0 일 때만) | 모듈·플러그인과 동형으로 무조건 bump — 조건은 게시 입력 중 레이아웃 축만 본다 |
| custom 변경 감지 서명과 게시 복사 집합을 서로 다른 코드가 정의 | 같은 열거자(`CustomAssets::publishableFiles()`) — 감지가 최상위 css/js 만 보면 하위 글꼴·이미지 교체가 영영 미게시다 |
| 버전 키·서명 키를 기본 TTL 로 `put()` | `PERSISTENT_TTL_SECONDS`(10년) 명시 — `forever()` 는 `CacheInterface` 밖(공개 표면 변경), `put(…, 0)` 은 forget |
| 서명 스코프를 렌더 템플릿만으로 나눔 | `{템플릿}@{호스트명}` — 다중 서버 공유 캐시에서 서버 간 mtime 차이로 요청마다 재게시가 왕복한다 |
| `config:cache` / `route:cache` / `event:cache` / `optimize` 를 헬퍼 밖에서 `Artisan::call` | `ConfigCacheHelper::rebuild()` / `RouteCacheHelper::rebuild()` (내부가 `withPreservedContainer`) — 이 명령들은 새 Application 을 부팅하며 전역 `Container` 를 일회용 앱으로 바꿔 놓아, 그 뒤 등록되는 `app()->terminating()` 재게시 예약이 종료되지 않는 앱에 걸려 사라진다 |
| 코어 업데이트 흐름에서 현재 프로세스의 버전·update 목록을 `config('app.version')`·`config('app.update.*')` 로 판독 | spawn 자식은 부모가 비우지 않은 이전 버전 config 캐시로 부팅한다 — 버전은 `CoreVersionChecker::getCoreVersion()`(업데이트 트리 안에서만 env 우선), update 목록은 캐시 부팅이면 `CoreUpdateService::freshDiskUpdateConfig()`, 부모는 spawn 직전 `ConfigCacheHelper::clear()` + `PackageManifestCacheHelper::clear()` |

이 결함군은 예외도 로그도 남기지 않는다 — 게시본이 정상 200 으로 옛 내용을 내보내는 것, 또는 매일 전체 재생성이 일어나는 것이 유일한 증상이다. 재게시 누락의 안전망은 관리자 > 환경설정 > 일반 「초기 화면 정적 파일」의 [지금 다시 만들기](`POST /api/admin/settings/static-cache/republish`)이며, 상태 판정은 `ExtensionStaticCacheService::statusReport()` 한 곳이 CLI·API·화면에 공급한다.

> 상세: [static-asset-publishing.md](../backend/static-asset-publishing.md) "버전은 만료되지 않는다" · "5-1. 관리자 화면에서의 수동 복구"

## 저장값 + 확장 카탈로그 병합 설정의 공개 응답

설정 항목이 "운영자 저장값 + 확장이 훅으로 등록한 카탈로그" 의 병합으로 만들어지면, 저장값은 남아 있는데 카탈로그에서 항목이 사라지는 상태가 생긴다 — 그 확장을 삭제·비활성화했거나, 확장이 자기 기능 토글을 껐을 때다. 병합부는 이를 고아 항목으로 표시하지만 저장값의 `is_active` 는 참 그대로 남는다.

| 금지 | 올바른 사용 |
|------|------------|
| 공개 응답이 저장값 플래그(`is_active`)만 보고 항목을 내보냄 | 카탈로그 소속(`_orphaned`)을 함께 판정 — 공급 확장이 더 이상 제공하지 않는 항목은 공개 응답에서 제거 |
| 고아 항목을 관리자 응답에서도 제거 | 관리자 응답은 유지 — 운영자가 확인하고 지워야 할 대상이다 |
| 소비 화면(레이아웃 JSON)마다 필터를 넣어 차단 | 공개 API 단일 지점에서 차단 — 화면마다 복제하면 템플릿 하나만 빠져도 같은 결함이 남는다 |
| 같은 데이터를 내보내는 공개 엔드포인트가 서로 다른 게터를 사용 | 전 엔드포인트가 같은 공개 게터를 경유 — 한쪽이 raw `getSettings()` 를 쓰면 그 경로만 조용히 뚫린다 |
| 항목 제거 후 배열 인덱스를 그대로 둠 | `array_values()` 로 재정렬 — 비연속 키는 JSON 객체로 직렬화되어 화면 반복이 깨진다 |

이 결함은 예외도 경고도 로그도 남기지 않는다. 이미 제공 불가한 항목이 사용자 화면에서 선택 가능한 상태로 남아 있는 것이 유일한 증상이고, 관리자 화면은 고아 표시로 정상 차단하고 있어 양쪽을 나란히 보지 않으면 드러나지 않는다.

> 상세: [module-settings.md](../extension/module-settings.md) "카탈로그 병합 설정의 공개 응답"

## vendor 를 교체한 뒤 새 PHP 프로세스를 띄우기 전에는 패키지 매니페스트를 비운다

Laravel 은 `bootstrap/cache/packages.php` 가 있으면 stale 여부를 검사하지 않고 그대로 읽어 provider 를 `new` 한다. 그래서 vendor 를 바꾼 뒤 그 파일을 남겨 두면 다음에 부팅하는 프로세스가 새 vendor 에 없는 클래스를 찾다 부팅 단계에서 죽고, 예외는 부팅 전이라 앱 로그에 남지 않는다.

| 금지 | 올바른 사용 |
|------|------------|
| vendor 교체 뒤 `proc_open`·`config:cache`·`route:cache` 등 새 부팅을 `bootstrap/cache/{packages,services}.php` 정리 없이 실행 | 부팅 직전 `PackageManifestCacheHelper::clear()`(재생성까지 필요하면 `rebuild()`) — `ConfigCacheHelper::clear()` 와 짝으로 |
| 정리 로직을 호출부마다 `@unlink` 로 복제 | 헬퍼 단일 지점 — `clearAllCaches()` 도 같은 헬퍼를 쓴다 |
| 자식 프로세스 보호를 부모 코드에만 두기 | 부모는 이미 배포된 옛 코드일 수 있다 — 새 버전의 `bootstrap/app.php` 가 `G7_UPDATE_IN_PROGRESS` 를 보고 스스로 비운다(App\ 클래스 미참조·실패 무시) |
| 코어 버전 판정에서 프로세스 env `APP_VERSION` 을 무조건 우선 | env 우선은 `CoreUpdateContext::isInProgress()` 인 프로세스 트리 안에서만 — 업데이트 전에 뜬 `artisan serve`·큐 워커는 옛 값을 물고 있다 |
| 업데이트 커맨드 argv 판정을 SAPI 게이트 없이 두기 | argv 는 명령줄 SAPI(`cli`·`phpdbg`)에서만 읽는다 — CGI/FPM 은 `register_argc_argv=On` 이면 `$_SERVER['argv']` 를 쿼리스트링에서 채워(`?x+core:update`) 비인증 웹 요청이 업데이트 트리로 판정되고, 자가 치유가 요청마다 매니페스트를 지운다. env 플래그 채널은 웹에서 주입할 수 없으므로 그대로 둔다 |
| 매니페스트 삭제 실패를 `@unlink` 로 삼키기 | `clear()` 가 지우지 못한 경로를 돌려주고 spawn 직전 호출부가 업그레이드 로그에 경고로 남긴다 — 권한·소유권 불일치면 자식도 같은 이유로 실패해 증상은 제보와 같고, 이 경고가 원인을 가리키는 유일한 흔적이다 |
| 업데이트 트리 판정을 지점마다 다시 작성 | `App\Support\CoreUpdateContext` 단일 SSoT — `CoreServiceProvider::isCoreUpdateInProgress()` 도 위임이다. `bootstrap/app.php` 의 복제본은 부팅 전이라 불가피한 예외이며 주석으로 상호 참조한다 |
| 자동 비활성화 로그의 `core_version` 을 `config('app.version')` 으로 적기 | 판정과 같은 `CoreVersionChecker::getCoreVersion()` — 로그와 판정 근거가 갈리면 운영자가 원인을 특정할 수 없다 |
| "이미 있으니 건너뛴다" 분기(인스톨러 vendor 재사용)가 산출물의 출처를 보지 않음 | `installed.json` 의 `dev`/`dev-package-names` 로 출처를 보고 경고 카드·로그를 남기며, 재사용 경로에서도 컴파일 캐시를 정리한다 |
| 코어 업데이트를 마치고 상주 워커에 신호를 보내지 않음 | Step 11·핸드오프·단독 재개 사후 단계에서 `signalQueueRestart()` |

이 결함군은 예외도 로그도 남기지 않는다 — 자식 프로세스가 부팅 단계에서 죽어 부모가 핸드오프로 멈추는 것, 또는 업데이트 직후 확장이 `incompatible_core` 로 꺼지는 것이 유일한 증상이다. 후자는 관리자 템플릿이 대상이면 복구 UI 자체에 도달할 수 없어 자가 회복 경로가 없다.

> 상세: [core-update-system.md](../backend/core-update-system.md) "spawn 전 캐시 정리 계약(3계층)" · [extension-update-system.md](../extension/extension-update-system.md) "판정의 단일 출처와 이 플래그가 게이트하는 것"

## 확장 결제수단은 자기 능력을 선언한다

코어 `PaymentMethodEnum` 은 확장 결제수단 ID(`kginicis_naverpay`, `toss_tosspay` 등)를 모른다. 그래서 능력(PG 필요 여부 / PG 고정 / 환불수단 / 환불 자동 승인 여부)은 **등록하는 확장이 카탈로그에 선언**하고, 관리자 화면과 서버는 그 선언만 읽는다. 미선언 시 안전 기본값(`needs_pg=true`, `pg_locked=false`, `pg_provider=null`, `auto_approve_refund=false`)으로 떨어지는데, 앞 세 개의 조합은 "PG 가 필요한데 어느 PG 인지 모른다" 를 뜻해 화면과 실제 결제 경로가 어긋난다.

`auto_approve_refund` 는 "사람의 개입 없이 환불이 집행되는가" 이고, 다른 키와 달리 **미선언이 안전한 쪽**이다 — 자동으로 잘못 완료하면 이중 환불이지만 멈추면 운영자가 한 번 더 누르면 된다. 대신 선언을 빠뜨리면 반대 방향의 조용한 손해가 난다: PG 결제취소 API 가 그 자리에서 환불하는 수단(카드·간편결제)의 환불신청서가 전부 운영자 승인 대기로 쌓이고, 운영자에게는 고칠 곳이 보이지 않는다.

| 금지 | 올바른 사용 |
|--------|---------------|
| entry `defaults` 에 `pg_provider` 만 두고 능력 키 생략 | `needs_pg` 명시 선언 (PG 결제창을 거치는가) |
| 자기 PG 전용 수단인데 `pg_provider: null` | `pg_provider: '{자기 provider id}'` + `pg_locked: true` (PG 제공자 등록 리스너의 id 와 동일해야 배지가 이름을 찾는다) |
| 표시를 고치려고 코어 레이아웃 표현식을 정규식 치환 | 카탈로그 선언만 바꾼다 — 레이아웃이 `pg_locked`/`needs_pg` 로 직접 3분기한다 |
| PG 가 즉시 환불하는 수단인데 `auto_approve_refund` 생략 | `auto_approve_refund: true` 선언 (현금으로 받아 사람이 송금해야 끝나는 수단 — 편의점·가상계좌·계좌이체 — 은 선언하지 않는다) |
| 선언을 바꾸고 기설치본은 그대로 | 저장된 `order_settings.json` 을 정정하는 업그레이드 스텝 동반 (자기 접두사만, 멱등) — 단 코어가 정의값으로 덮어쓰는 능력 키(`needs_pg`·`pg_locked`·`refund_method`·`auto_approve_refund`)는 저장값이 이기지 않으므로 스텝이 필요 없다 |

레이아웃 치환 방식은 코어가 그 리터럴을 버리는 순간 조용히 사문화된다 — 합성 입력으로만 검증한 테스트는 계속 통과하므로 사문화가 드러나지 않는다. 자동 검증이 능력 선언 누락을 차단한다.
