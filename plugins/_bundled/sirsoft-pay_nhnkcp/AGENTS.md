# NHN KCP — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-pay_nhnkcp) — NHN KCP PG 연동(PC CLI 승인/모바일 SOAP/가상계좌/에스크로). 소유 테이블 없음 — 상태는 sirsoft-ecommerce 소유
2. 확장 방식: `RegisterPgProviderListener`/`RegisterEasyPayMethodsListener` 로 이커머스 레지스트리에 등록 — 이커머스 코드는 이 플러그인을 모른다
3. 건드리면 안 되는 것: KCP CLI(`bin/pp_cli*`) 호출 인자 사전검증(`assertSafeCliValue`) 생략, 동일 거래번호 콜백 재처리 방지 우회, IP 화이트리스트 미들웨어(`RestrictKcpIp`) 미부착
4. 작업 위치: `plugins/_bundled/sirsoft-pay_nhnkcp` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-pay_nhnkcp --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
NHN KCP PG(결제 게이트웨이)를 `sirsoft-ecommerce`에 연결하는 어댑터입니다. PC 결제는 KCP CLI
바이너리(`bin/pp_cli*`)를 서버에서 직접 실행해 승인 응답을 받고, 모바일 결제는 SOAP
승인키 발급 후 모바일 결제창으로 폼 이동합니다. 두 프로토콜 모두 `sirsoft-pay_kginicis`(HTTP
API 호출)와 달리 **로컬 프로세스 실행**을 최종 승인 수단으로 쓴다는 점이 이 플러그인 고유의
설계 축입니다.

**설계 원칙**: 이 플러그인도 `sirsoft-pay_kginicis`와 마찬가지로 상태를 소유하지 않습니다
(§data-model.md — 모델·테이블·Repository 0개, kginicis 의 CBT 정산 Repository 2개조차 없음:
이 플러그인은 일본/CBT 결제를 아예 구현하지 않기 때문입니다). 등록은 훅 기반입니다
(`sirsoft-ecommerce.payment.registered_pg_providers` 필터) — 이커머스 모듈은 이 플러그인의
존재를 컴파일 타임에 몰라도 됩니다.

**의도적으로 하지 않는 것**: CLI 실행 권한이 사라진 경우(예: `plugin:update` 가 `_bundled` 의
0664 권한을 활성 디렉토리로 그대로 복사) 조용히 실패하지 않고 결제 hot path 에서
`ensureCliExecutable()` 로 0755 자가 복구를 시도합니다 — "결제 버튼을 눌렀는데 원인 불명으로
9502 오류"라는 상태를 막기 위함입니다. 또한 CLI 인자에 위험 문자·제어문자가 섞이면 그 값을
정제해서 통과시키지 않고 `NhnKcpApiException` 으로 즉시 거부합니다 — 부분 정제는 안전하다는
착각을 주면서 실제로는 우회 경로를 남길 수 있습니다.
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
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-pay_nhnkcp --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-pay_nhnkcp --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-pay_nhnkcp --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-pay_nhnkcp --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-pay_nhnkcp --force` |
| `bin/` | 확장이 실행하는 외부 바이너리·인증서 | 교체 시 OS별 파일과 권한을 함께 확인 (비면 해당 기능 정지) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**PC 결제 승인**: `PaymentCallbackController`(KCP 결제창이 POST 하는 `enc_data`/`enc_info`
수신) → `sirsoft-pay_nhnkcp.payment.before_confirm` 훅 → `NhnKcpApiService::executeCli()` 가
OS 판별 후 `executeCliWindows()`/`executeCliLinux()` 로 분기 → CLI 인자 전량
`assertSafeCliValue()` 사전검증 → `escapeshellarg()` 이중 quoting 후 `exec()` 실행 →
`sirsoft-pay_nhnkcp.payment.after_confirm` 훅 → 이커머스 주문 결제 완료 처리.
`PreventsReplayCallback` 트레이트가 콜백 진입 시점에 동일 `transaction_id` 가 이미 `paid`
상태인지 먼저 확인해 재처리를 조기 차단합니다.

**모바일 결제 승인**: `/mobile/approval-key` API 호출 → KCP SOAP `approve` 로 승인키·`pay_url`
획득 → 브라우저가 `pay_url` 로 폼 POST → KCP가 `/payment/callback` 으로 결과 POST(PC와 동일
콜백 엔드포인트 공유) → 이후는 PC 흐름과 합류.

**가상계좌 입금통보 / 에스크로 공통통보**: KCP 서버가 `/payment/vbank-notify` 또는
`/payment/escrow-common-notify` 를 직접 호출 → `RestrictKcpIp` 미들웨어가 운영 모드에서
발신 IP 를 화이트리스트와 대조 → `EscrowCommonNotifyController` 가 `tx_cd`/`cl_status` 조합으로
4가지 훅(`escrow.purchase_confirmed`/`purchase_cancelled`/`denial_confirmed`/
`delivery_started`) 중 하나를 분기 발화. 결제 취소는 `PaymentRefundListener`(우선순위 10)가
먼저 KCP 취소 API 를 호출한 뒤 `CancelActivityLogListener`(우선순위 20)가 그 결과를 활동
로그에 별도 기록합니다 — `sirsoft-pay_kginicis` 와 동일한 순서 원칙입니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 8개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 9개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 8개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 5개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 1개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
`before_confirm`/`before_cancel` 은 PG(CLI/SOAP) 호출 **전** 개입 지점입니다 — 예를 들어
`before_cancel` 을 잡아 조건 미충족 시 예외를 던지면 KCP 취소 API 자체가 호출되지 않습니다.
`after_*` 훅은 응답을 받은 뒤 부가효과를 붙이는 자리입니다. 에스크로 훅 4종은 KCP 공통통보의
`tx_cd`/`cl_status` 조합을 이미 해석해 발화하므로, 구독하는 확장은 원시 통보 파라미터를
다시 파싱할 필요가 없습니다. 구독 훅의 `core.layout_extension.after_apply` 3건은 관리자
주문 목록/상세에 "테스트 모드 배지"·"거래 조회 UI"를 레이아웃 확장으로 주입하기 때문입니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-pay_nhnkcp --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-pay_nhnkcp` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] CLI 인자 조립부를 고칠 때 `assertSafeCliValue()` 검증을 모든 인자에 유지 — 인자 하나만 빠져도 그 필드가 injection 통로가 된다
- [ ] `bin/` 바이너리(OS별 CLI·`pub.key`·WSDL) 교체 시 실행 권한(0755)과 파일 존재를 관리자 설정 화면의 시스템 점검(§API)으로 확인
- [ ] 승인/취소 흐름을 고칠 때 `before_*`/`after_*` 훅 순서와 우선순위(`PaymentRefundListener` < `CancelActivityLogListener`)를 유지 — 로그가 실제 처리보다 먼저 실행되면 안 된다
- [ ] IP 화이트리스트(`RestrictKcpIp`) 대상 라우트를 추가/변경하면 미들웨어 부착 대상(targets)도 함께 갱신
- [ ] 새 결제수단·통화를 추가하면 그 결제수단의 콜백 URL을 관리자 설정 안내(README "콜백 및 통보 URL")에도 반영
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan plugin:update sirsoft-pay_nhnkcp --force`
- [ ] NHN KCP 가 SDK 호스트를 바꾸면 `plugin.json` 의 `trusted_script_hosts`(+`trusted_script_hosts_reason`)와 `resources/js/handlers/requestPayment.ts` 의 `KNOWN_SDK_HOSTS` 를 **함께** 갱신 — 두 목록이 어긋나면 테스트가 실패하며, 코드 상수에 없는 호스트는 주입 직전 확인에서 거부되어 결제가 진행되지 않는다(fail-closed). 변경 후 `php artisan ext:docgen --scope=plugin:sirsoft-pay_nhnkcp` 재실행

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| KCP CLI 인자(`site_cd`/`tx_cd`/`enc_data` 등)를 검증 없이 문자열 결합해 `exec()` 에 전달 | `assertSafeCliValue()` 로 위험 문자·제어문자 사전 거부 후 `escapeshellarg()` 로 quoting | 검증을 생략하면 서버가 받은 KCP 응답 값이 그대로 셸 명령 인자가 되어 명령 삽입(command injection)으로 이어질 수 있다 |
| CLI 실행 권한 오류(9502)를 그대로 사용자에게 노출하고 자가 복구를 생략 | `ensureCliExecutable()` 로 결제 hot path 진입 시 0755 자가 복구 시도 | `plugin:update` 가 파일 권한을 0664 로 되돌리는 것은 배포 절차의 부작용이지 운영자 실수가 아니다 — 매 결제 실패로 드러나게 두면 안 된다 |
| 동일 `transaction_id` 콜백을 매번 재처리 | `PreventsReplayCallback::wasAlreadyPaid()` 로 이미 `paid` 상태면 멱등 응답 | KCP 서버의 재전송·사용자의 새로고침으로 같은 콜백이 두 번 오면 결제완료 알림·마일리지가 중복 적립될 수 있다 |
| 에스크로 공통통보의 `tx_cd`/`cl_status` 매핑을 컨트롤러 밖(리스너 등)에서 다시 판정 | `EscrowCommonNotifyController` 의 매핑표(§핵심 흐름)를 SSoT 로 유지 | 판정 로직이 두 곳에 있으면 KCP 가 새 `cl_status` 값을 보낼 때 한쪽만 갱신되어 조용히 어긋난다 |
| 라이브 사이트 키(`live_site_key`)를 로그·에러 메시지에 노출 | 운영 키는 항상 마스킹하거나 로그 대상에서 제외 | 노출되면 제3자가 결제창 요청을 위조할 수 있다 |
| 승인 성립 전 실패 분기(브라우저 결과코드·금액 불일치·CLI 승인 거절)에서 `failPayment()` 호출 | 로그 + `resolveFailUrl()` 만. 주문 상태는 건드리지 않는다 | `authCallback` 은 PG 서명도 IP 증명도 없는 비인증 브라우저 POST 이고 `ordr_idxx` 도 요청자가 고른 값이다. 승인 실패는 남의 주문번호와 위조 암호문만으로 만들어낼 수 있으므로, 그것을 근거로 실패 처리하면 타인의 결제대기 주문이 취소된다 |
| catch 블록에서 `$approvedTno` 확인 없이 주문을 실패 처리 | `hasApproval($approvedTno)` 가 참일 때만 — tno 는 승인 성공 후에만 채워진다 | 승인 전에 터진 예외까지 반영하면 위 금지 패턴과 같은 통로가 catch 경로로 다시 열린다 |
| 정당한 결제 실패 기록을 콜백에서 처리 | 소유권을 검증하는 `close-report`(`requestMatchesOrderBuyer`) 경유 | 구매자 이메일·전화 대조를 통과한 요청만 주문 상태를 바꿔야 한다 |
| 결제창 컨텍스트(구매자 정보)를 메모리에만 보관 | `rememberPendingClose()` 로 sessionStorage 에 남기고, 부팅 시 `reportPaymentFailureOnReturn()` 으로 보고 | 결제창은 전체 페이지 이동으로 열리고 돌아와 JS 컨텍스트가 소실된다. 승인 거절은 fail URL 리다이렉트로 끝나므로, 남겨 둔 정보가 없으면 정당한 결제 실패가 어디에도 기록되지 않는다 |
| 실패 화면에서 보고가 닿지 못한 주문을 방치 | 이커머스 모듈의 만료 주문 자동 정리가 최종 안전망 | 브라우저를 바로 닫으면 보고가 나가지 않는다. 두 경로가 함께 있어야 선차감 마일리지가 무기한 묶이지 않는다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 28개 | `plugins/_bundled/sirsoft-pay_nhnkcp/tests` |
| Vitest | 8개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 1개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-pay_nhnkcp/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-pay_nhnkcp && powershell -Command "npm run test:run -- <대상>"

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
