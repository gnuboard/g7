# 그누보드7 비즈뿌리오 메시지 발송 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-message_bizppurio) — 코어 알림에 문자(SMS/LMS)·카카오 알림톡 채널을 추가한다. 알림 자체는 정의하지 않고 발송 수단만 담당
2. 확장 방식: 발행 훅 1개 — 잔액 부족·한도 초과 시 `balance.low`(쿨다운 내 1회). 그 외 배선은 구독 6종(코어 알림 로그·설정, 이커머스 비회원 연락처)
3. 건드리면 안 되는 것: webhook 라우트의 IP 화이트리스트 제거, 승인 템플릿 직접 수정(승인 취소 선행), 잔액부족 통지를 같은 채널로만 보내기, 크리덴셜 프론트 노출
4. 작업 위치: `plugins/_bundled/sirsoft-message_bizppurio` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-message_bizppurio --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
코어 알림 시스템에 **문자(SMS/LMS)와 카카오 알림톡 채널을 추가**하는 플러그인입니다. 알림을
새로 만들지 않고, 코어와 모듈이 이미 발화하는 알림을 비즈뿌리오 API 로 내보냅니다.

**설계 원칙 넷**:

1. **채널만 추가하고 알림은 만들지 않는다.** 어떤 사건에 알림을 보낼지는 코어와 각 모듈이
   정합니다. 이 플러그인은 `RegisterNotificationChannelsListener` 로 채널을 등록하고
   `SeedChannelTemplatesListener` 로 그 채널의 템플릿 자리를 시드할 뿐입니다. 그래서 삭제하면
   채널만 사라지고 알림 자체는 남습니다.
2. **발송 결과는 나중에 돌아온다.** 비즈뿌리오는 발송 API 응답이 아니라 **webhook 통보**로
   최종 결과를 줍니다. 그래서 발송과 결과 기록이 분리되어 있고, webhook 이 등록되지 않은
   사이트에서는 발송은 되지만 이력에 결과가 남지 않습니다 — 오류가 아니라 **결과 미상**입니다.
3. **승인된 내용을 박제한다.** 알림톡 템플릿은 카카오 승인 시점의 내용을 로컬에 저장해 두고
   발송하며, 발송할 때마다 카카오를 조회하지 않습니다. 그래서 승인 후 내용을 고치려면 승인을
   먼저 취소해야 하고, 취소하는 순간 알림톡 발송이 멈춥니다.
4. **실패를 종류별로 다르게 다룬다.** 결과 코드를 성공 / 재시도(일시 오류) / 잔액 부족 /
   영구 실패 넷으로 분류합니다. 잔액 부족은 재시도해도 소용없으므로 즉시 실패 처리하고
   **관리자에게 알립니다** — 다만 그 알림이 같은 채널로 나가면 함께 실패하므로 쿨다운을 두고
   다른 채널 병행을 권합니다.

**의도적으로 하지 않는 것**: 알림 정의·수신자 해석·발송 대상 판정. 그 셋은 코어
`GenericNotification` 과 각 도메인의 일이며, 이 플러그인은 채널 구현체입니다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-message_bizppurio --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-message_bizppurio --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-message_bizppurio --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-message_bizppurio --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**발송**: 코어·모듈이 알림 발화 → 코어 알림 시스템이 켜져 있는 채널별로 발송 작업을 큐잉 →
이 플러그인의 채널 구현이 그 작업을 받음 → 알림톡이면 승인된 템플릿 내용으로, 문자면 본문
길이에 따라 SMS/LMS 를 골라 비즈뿌리오 발송 API 호출 → 발송 기록(`bizppurio_dispatches`)
적재. 알림톡 발송이 불가하거나 실패하면 설정에 따라 대체 SMS 로 내려갑니다.

**결과 수신**: 비즈뿌리오가 `POST /webhook` 으로 결과 통보 →
`BizppurioWebhookIpWhitelist` 미들웨어가 발신 IP 를 검사 → `WebhookReportService` 가 결과
코드를 넷으로 분류해 발송 기록을 갱신. 잔액 부족(`9070`/`9071`/`7436`)이면
`sirsoft-message_bizppurio.balance.low` 액션을 발행하고 관리자 알림을 보냅니다 — 대량 실패
시 반복을 막기 위해 채널별 쿨다운(기본 3600초) 안에서는 **최초 1회만** 실행됩니다.

**템플릿 수명주기**: 관리자 화면에서 작성(`POST templates`) → 검수 신청
(`POST templates/{id}/request`) → 카카오 검수 → 승인/반려. 결과는
`bizppurio:sync-template-status` 스케줄이 **30분마다** 확인하고, 화면의 [새로고침]
(`POST templates/{id}/sync`)으로 즉시 확인할 수도 있습니다. 승인된 템플릿을 고치려면
`POST templates/{id}/cancel-approval` 로 승인을 먼저 취소합니다.

**알림 이력 연결**: 코어가 알림 발송 성공·실패를 기록하면
(`core.notification_log.after_log_sent` / `after_log_failed`) `LinkNotificationLogListener`
가 그 로그와 이 플러그인의 발송 기록을 연결합니다. 관리자 "알림 발송 이력" 화면에서 문자·
알림톡 결과가 함께 보이는 것이 이 연결 덕분입니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 1개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 6개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 7개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 7개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 1개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 1개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 1개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅은 하나뿐입니다.

**`sirsoft-message_bizppurio.balance.low`** — 잔액 부족·후불 한도 초과로 발송이 실패했을 때
(쿨다운 내 최초 1회) 발화합니다. 인수는 `string $resultCode, string $channel` 이며,
`$resultCode` 는 `9070`(문자 잔액 부족) · `7436`(알림톡 지갑 잔액 부족) · `9071`(후불 한도
초과) 중 하나입니다.

```php
use App\Extension\HookManager;

HookManager::addAction(
    'sirsoft-message_bizppurio.balance.low',
    function (string $resultCode, string $channel) {
        // 예: 잔액 부족 시 Slack 으로도 별도 알림
        SlackNotifier::send("비즈뿌리오 잔액 부족: 채널={$channel}, 코드={$resultCode}");
    },
    priority: 10
);
```

이 훅이 관리자 자체 알림(잔액부족·후불한도초과 안내)을 발화하는 지점과 **동일**합니다. 잔액
부족 알림을 문자·알림톡이 아닌 다른 경로로 받고 싶을 때 여기에 붙입니다 — 같은 채널로 보내면
잔액이 없으므로 그 알림도 함께 실패합니다.

**구독 6종이 이 플러그인의 배선 전부**입니다. 코어 알림 로그 2종(발송 기록 연결) · 코어 설정
2종(저장 시 토큰 무효화 · 운영 모드 전환 시 필수값 검증) · 이커머스 1종(비회원 연락처 주입) ·
자기 훅 1종(잔액부족 알림 데이터). `sirsoft-ecommerce.notification.extract_data` 는 manifest
의존이 아니라 훅 구독이므로, 이커머스가 없으면 비회원 문자 발송만 비고 나머지는 정상
동작합니다.

레이아웃 조각 7개가 UI 대부분입니다 — 알림 설정 화면의 비즈뿌리오 탭(코어·게시판·이커머스
각각), 알림 목록 행 하단 요약, 알림 템플릿 편집 창의 알림톡·문자 섹션과 하단 버튼, 발송 이력
화면의 결과 열. 대상 화면이 그 자리를 없애면 조각은 **오류 없이 사라집니다.**
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-message_bizppurio --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-message_bizppurio` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] webhook 라우트를 추가·변경했다면 `getMiddleware()` 의 `targets` 에 그 라우트 이름을 함께 추가 (누락 시 인증 없는 공개 경로가 된다)
- [ ] 결과 코드 분류(성공/재시도/잔액부족/영구실패)를 바꿨다면 `lang/{ko,en}/result_codes.php` 의 사유 문구도 함께 갱신
- [ ] 코어 알림 시스템(채널 등록·발송 로그 훅)이 바뀌면 이 플러그인의 구독 4종이 조용히 끊기므로 함께 확인
- [ ] 레이아웃 조각 7개는 대상 화면(알림 설정·알림 템플릿 편집·발송 이력)의 자리가 사라지면 오류 없이 빠진다 — 코어·게시판·이커머스 업그레이드 후 노출 확인
- [ ] 크리덴셜 설정을 추가한다면 `frontend_schema` 에 `expose: false` + `sensitive: true` 를 함께 선언
- [ ] `dist/` 는 커밋되는 배포 산출물 — TS 를 고쳤으면 `--production` 재빌드 후 커밋 (`sourceMappingURL` 잔존 금지)
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 를 확인 — 이 확장은 편집기 스펙이 없어 `bizppurioCategories` · `bizppurioProfiles` · `bizppurio_templates_list` · `report_url` · `templates_readiness` 가 편집기 캔버스에서 빈 화면으로 보인다. `data_source` 를 더 늘리면 그 자리도 같은 상태가 된다

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 이 플러그인에서 알림 자체(수신자·발화 조건)를 정의 | 채널 등록까지만 — 알림은 코어·모듈이 정의 | 채널 구현이 알림을 소유하면 플러그인을 바꿀 때 알림 이력까지 사라진다 |
| 잔액 부족 알림을 문자·알림톡 채널로만 보내도록 두기 | 사이트 내 알림·메일 등 다른 채널 병행 | 잔액이 없어서 실패한 상황인데 통지도 같은 수단이면 함께 실패한다 |
| 잔액 부족 알림에 쿨다운 없이 매 실패마다 발송 | 채널별 쿨다운(기본 3600초) 안에서 최초 1회 | 대량 발송이 한꺼번에 실패하면 통지가 수백 건 쏟아진다 |
| 발송할 때마다 카카오에 템플릿을 조회 | 승인 시점 내용을 로컬에 박제해 발송 | 외부 조회가 발송 경로에 들어가면 그 서비스 지연이 곧 발송 지연이 된다 |
| 승인된 템플릿을 그대로 수정 | 승인 취소 → 수정 → 재신청 | 카카오 승인 대상은 특정 내용이다. 승인 후 내용이 바뀌면 승인과 발송물이 어긋난다 |
| webhook 라우트를 IP 화이트리스트 없이 공개 | `BizppurioWebhookIpWhitelist` 부착 유지 | 인증 없는 공개 엔드포인트다 — 위조 통보로 발송 결과를 조작할 수 있다 |
| webhook 라우트를 추가·변경하면서 미들웨어 `targets` 선언을 그대로 두기 | 라우트 이름을 `getMiddleware()` 선언에도 추가 | 이름이 어긋나면 미들웨어가 붙지 않는데, 정상 응답이 나가므로 오류도 로그도 남지 않는다 |
| 크리덴셜(비밀번호·API 키·발신프로필 키)을 프론트에 노출 | `expose: false` + `sensitive: true` 유지 | 발송 권한이 곧 비용이다 |
| 일시 오류와 영구 실패를 같은 방식으로 재시도 | 결과 코드 4분류를 따른다 | 영구 실패를 재시도하면 비용만 늘고, 일시 오류를 포기하면 발송이 누락된다 |
| 문자 본문이 없는 언어에 기본 언어 본문도 없이 발송 시도 | 두 단계 폴백 후 그 알림의 문자 발송을 건너뛴다 | 빈 본문 발송은 비용이 나가면서 수신자에게 아무 정보도 주지 않는다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 37개 | `plugins/_bundled/sirsoft-message_bizppurio/tests` |
| Vitest | 7개 | `vitest.config.ts` |
| Playwright | 2개 | `tests/Playwright` |
| 시나리오 매니페스트 | 6개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-message_bizppurio/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-message_bizppurio && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd plugins/_bundled/sirsoft-message_bizppurio && npm run test:e2e -- specs/<대상>.spec.ts

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/extension-points.md](docs/extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 | ✅ |
| [docs/data-model.md](docs/data-model.md) | 모델·소유 테이블·마이그레이션·Enum | ✅ |
| [docs/settings.md](docs/settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 | ✅ |
| [docs/frontend.md](docs/frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
