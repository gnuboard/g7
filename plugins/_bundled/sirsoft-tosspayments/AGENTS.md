# 토스페이먼츠 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-tosspayments) — 토스페이먼츠 PG 연동(통합결제창 SDK/가상계좌 웹훅 secret 대조/에스크로 3-상태). 소유 테이블 없음 — 상태는 sirsoft-ecommerce 소유
2. 확장 방식: `RegisterPgProviderListener`/`RegisterTossPaymentMethodsListener`/`RegisterCashReceiptProviderListener` 로 이커머스 레지스트리에 등록 — 이커머스 코드는 이 플러그인을 모른다
3. 건드리면 안 되는 것: 결제 승인 확인 시 서버가 재계산한 금액과 PG 콜백 금액 대조(amount mismatch 검사) 생략, 가상계좌 웹훅의 secret 대조(`webhook_secret_verify`) 우회 — 토스는 notify IP 목록·서명을 제공하지 않아 secret 대조가 유일한 위조 방지 수단
4. 작업 위치: `plugins/_bundled/sirsoft-tosspayments` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-tosspayments --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
토스페이먼츠 PG(결제 게이트웨이)를 `sirsoft-ecommerce`에 연결하는 어댑터입니다. 승인은
브라우저 리다이렉트 기반입니다 — 통합결제창 SDK(`js.tosspayments.com/v2/standard`)가
결제를 처리한 뒤 브라우저를 `?paymentKey&orderId&amount`가 붙은 콜백 URL로 리다이렉트하고,
서버가 그 파라미터로 승인 확인 API를 호출합니다. 다른 PG 플러그인(iframe 팝업/CLI/SOAP)과
달리 프론트엔드 계층이 SDK 호출 하나로 끝나고 프로토콜 복잡도가 대부분 서버 쪽(확인·웹훅)에
있습니다.

이 플러그인은 **결제창형**과 **주문서형**(`order_sheet_mode`) 두 UI 모드를 지원합니다.
결제창형은 통합결제창이 결제수단을 전부 처리하는 카드 하나로 노출되고, 주문서형은
`method_*` 설정으로 활성화한 개별 토스 결제수단(카드/가상계좌/계좌이체/휴대폰/토스페이/
카카오페이/네이버페이/페이코/삼성페이)이 체크아웃 화면에 개별 버튼으로 뜹니다 — 어느
쪽이든 최종 처리는 토스 결제창 하나로 귀결되므로 각 수단은 `pg_provider` 를 이 플러그인
자신으로 고정(`pg_locked`)합니다(§AGENTS.md "4. 확장점").

**설계 원칙**: 이 플러그인도 상태를 소유하지 않습니다(§data-model.md — 모델·테이블 0개).
가상계좌 웹훅 검증은 토스가 notify IP 목록이나 서명을 제공하지 않는다는 제약에서
비롯됩니다 — 승인 확인 응답에만 실리는 `secret` 값을 `payment_meta`에 저장해 두었다가
웹훅이 도착하면 대조하는 것이 토스 공식 문서가 제시하는 유일한 위조 방지 수단입니다.

**의도적으로 하지 않는 것**: 승인 확인 시 PG가 돌려준 금액과 서버가 재계산한 주문 금액이
다르면(`amount_mismatch`) 결제를 완료 처리하지 않고 실패로 되돌립니다 — 콜백 URL의
`amount` 쿼리 파라미터는 브라우저를 거치므로 신뢰할 수 없는 입력이기 때문입니다.
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
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-tosspayments --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-tosspayments --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-tosspayments --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-tosspayments --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**결제 승인**: 통합결제창이 브라우저를 `/payment/callback?paymentKey&orderId&amount`로
리다이렉트 → `PaymentCallbackController`가 주문 조회 →
`sirsoft-tosspayments.payment.before_confirm` 훅 → `TossPaymentsApiService::confirmPayment()`
가 토스 승인 확인 API 호출 → 응답 금액과 서버 재계산 금액 대조(불일치 시 실패 처리) →
`sirsoft-tosspayments.payment.after_confirm` 훅 → 이커머스 주문 결제 완료 처리. 가상계좌가
발급된 경우 응답에 실린 `secret`을 `payment_meta.toss_secret`에 저장해 이후 웹훅 대조에
씁니다.

**가상계좌 입금 웹훅**: 토스가 `/webhook/deposit`으로 POST → 저장된 `toss_secret`과 웹훅
본문의 `secret`을 대조(`webhook_secret_verify` 설정이 꺼져 있지 않은 한 강제) → 일치하면
결제 완료 처리, 불일치하면 경고 로그만 남기고 처리하지 않습니다.

**결제 취소(환불)**: 관리자가 주문 취소(`cancel_pg=true`) → 코어가
`sirsoft-ecommerce.payment.refund` 필터 발화 → `PaymentRefundListener`가 토스 취소 API 호출
→ `before_cancel`/`after_cancel` 훅 발화.

**설정 저장 검증**: 관리자가 플러그인 설정을 저장 → `core.plugin_settings.before_save`
(동기 훅, `sync: true`) → `ValidateTossSettingsListener`가 `vbank_valid_hours`(1~2160시간)와
`use_escrow`(`off`/`on`/`buyer_choice` 3-상태) 범위를 검증 → 위반 시 `ValidationException`으로
저장 자체를 막습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 4개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 9개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 6개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 3개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
`before_confirm`/`before_cancel`은 API 호출 **전** 개입 지점이라 예외를 던지면 실제
토스 호출이 일어나지 않습니다. `core.plugin_settings.before_save`를 `ValidateTossSettingsListener`
가 구독하는 것은 다른 PG 플러그인에는 없는 패턴입니다 — 이 훅은 코어 `PluginSettingsService`
가 발행하며, `sync: true`가 없으면 큐로 비동기 디스패치되어 `ValidationException`이 워커
안에서 죽고 저장은 그대로 진행됩니다(§CLAUDE.md "Listener 데이터 접근 규정" 의 sync 훅
규칙과 동일한 이유).
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-tosspayments --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-tosspayments` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 승인 확인에서 금액 대조(`amount_mismatch`) 로직을 우회하거나 완화하지 않는다
- [ ] 가상계좌 웹훅의 secret 대조(`webhook_secret_verify`)를 기본값 `true` 이외로 바꾸지 않는다 — 끄면 토스 노티 위조를 막을 수단이 사라진다
- [ ] `order_sheet_mode` 관련 로직을 고칠 때 `RegisterPgProviderListener`(enabled_methods)와 `RegisterTossPaymentMethodsListener`(builtin 결제수단 주입) 양쪽을 함께 갱신 — 한쪽만 고치면 설정과 노출 목록이 어긋난다
- [ ] `ValidateTossSettingsListener`에 새 범위 검증을 추가하면 `core.plugin_settings.before_save` 의 `sync: true`를 유지
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 를 확인 — 이 확장은 편집기 스펙이 없어도 되는 상태(공용 ID 만 사용)다. 이 확장만 쓰는 `data_source` 를 새로 붙이는 순간 `editor-spec.json` 신설이 필요해진다
- [ ] 토스페이먼츠가 SDK 호스트를 바꾸면 `plugin.json` 의 `trusted_script_hosts`(+`trusted_script_hosts_reason`)와 `resources/js/handlers/requestPayment.ts` 의 `KNOWN_SDK_HOSTS` 를 **함께** 갱신 — 두 목록이 어긋나면 테스트가 실패하며, 코드 상수에 없는 호스트는 주입 직전 확인에서 거부되어 결제가 진행되지 않는다(fail-closed). 변경 후 `php artisan ext:docgen --scope=plugin:sirsoft-tosspayments` 재실행

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 콜백 URL의 `amount` 쿼리 파라미터를 그대로 신뢰해 결제 완료 처리 | 서버가 주문 금액을 재계산해 PG 응답 금액과 대조, 불일치 시 실패 처리 | 콜백은 브라우저를 거치므로 사용자가 쿼리 파라미터를 조작해 실제 결제 금액보다 낮은 금액으로 완료 처리를 유도할 수 있다 |
| 가상계좌 웹훅의 secret 대조를 생략하거나 항상 통과 | `payment_meta.toss_secret`과 웹훅 본문의 secret을 항상 대조 | 토스는 notify IP 목록·서명을 제공하지 않아 secret 대조가 유일한 위조 방지 수단이다 — 생략하면 제3자가 임의 주문에 대해 위조 입금통보를 보낼 수 있다 |
| `core.plugin_settings.before_save` 리스너에 `sync: true` 없이 등록 | 저장을 막아야 하는 검증 훅은 반드시 `sync: true` | 기본값(비동기 큐)이면 `ValidationException`이 워커 안에서 죽고 저장이 그대로 진행되어 검증이 무력화된다 |
| 라이브 시크릿 키를 로그·에러 메시지에 노출 | 운영 키는 항상 마스킹하거나 로그 대상에서 제외 | 노출되면 제3자가 서버측 API를 위조 호출할 수 있다 |
| `fail()` 에서 `failPayment()` 등 주문 상태를 바꾸는 호출 | 로그 + `resolveFailUrl()` 만 | 이 엔드포인트는 인증도 서명도 없는 GET 이고 `orderId`·`code` 가 전부 쿼리스트링에서 온다. 실패 처리를 수행하면 링크 하나로 남의 결제대기 주문을 취소시킬 수 있다 |
| 결제 실패를 `fail()` 이 기록해 줄 것으로 가정 | 구매자 정보를 대조하는 `POST /api/plugins/sirsoft-tosspayments/payment/close-report` 가 기록한다 | 결제 성립은 `success()` 의 서버 `confirmPayment`, 결제완료 후 취소는 secret 대조를 통과한 웹훅이 담당한다. 주문을 실패로 전이시키는 결제창 경로는 close-report 하나뿐이다 |
| 결제창 컨텍스트(구매자 정보)를 `window` 전역에만 보관 | `rememberPendingClose()` 로 sessionStorage 에 남긴다 | 결제창은 전체 페이지 이동으로 열리고 돌아와 JS 컨텍스트가 소실된다. 전역에만 두면 실패 화면에서 보고할 근거가 사라져 결제 실패가 어디에도 기록되지 않는다 |
| 실패 화면에서 보고가 닿지 못한 주문을 방치 | 이커머스 모듈의 만료 주문 자동 정리가 최종 안전망 | 브라우저를 바로 닫으면 보고가 나가지 않는다. 두 경로가 함께 있어야 선차감 마일리지가 무기한 묶이지 않는다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 13개 | `plugins/_bundled/sirsoft-tosspayments/tests` |
| Vitest | 6개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 4개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-tosspayments/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-tosspayments && powershell -Command "npm run test:run -- <대상>"

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
