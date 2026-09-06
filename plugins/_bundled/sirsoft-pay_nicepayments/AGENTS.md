# 그누보드7 나이스페이먼츠 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-pay_nicepayments) — 나이스페이먼츠 PG 연동(인증+승인 2단계/가상계좌/에스크로/간편결제 8종). 소유 테이블 없음 — 상태는 sirsoft-ecommerce 소유
2. 확장 방식: `RegisterPgProviderListener`/`RegisterEasyPayMethodsListener` 로 이커머스 레지스트리에 등록 — 이커머스 코드는 이 플러그인을 모른다
3. 건드리면 안 되는 것: 결제창 인증 실패(`AuthResultCode != '0000'`)를 승인 API 호출 전인데도 hard failure 로 취급, 가상계좌 입금통보 IP 화이트리스트(`VbankNotifyIpWhitelist`) 미부착
4. 작업 위치: `plugins/_bundled/sirsoft-pay_nicepayments` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-pay_nicepayments --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
나이스페이먼츠 PG를 `sirsoft-ecommerce`에 연결하는 어댑터입니다. 결제 승인은 **인증→승인
2단계**입니다 — 결제창(`goPay` iframe 팝업/모바일 폼)이 먼저 인증 결과(`AuthResultCode`)를
`/payment/callback`으로 POST 하고, 서버가 그 결과를 받아 `NextAppURL`로 다시 승인 API를
호출해야 최종 완료됩니다. `sirsoft-pay_kginicis`(결제창 후 단일 승인 API)와 달리 인증
단계에서 실패하는 것과 승인 단계에서 실패하는 것을 서로 다르게 취급해야 합니다(§금지 패턴).

**설계 원칙**: 이 플러그인도 상태를 소유하지 않습니다(§data-model.md — 모델·테이블·Repository
0개). 주문·결제 상태는 `sirsoft-ecommerce`에 있고, 이 플러그인은 그 상태를 나이스페이먼츠
API 와 동기화하는 역할만 합니다. 등록은 훅 기반입니다
(`sirsoft-ecommerce.payment.registered_pg_providers` 필터).

**의도적으로 하지 않는 것**: 사용자가 결제창에서 취소하거나 PG 가 인증을 거부한 경우
(`AuthResultCode != '0000'`)는 아직 승인 API 호출 전이므로 일반 오류 메시지를 띄우지 않고
체크아웃으로 조용히 리다이렉트합니다 — 이 시점은 "결제 시도 자체를 안 한 것"과 사실상
같아서, 사용자에게 오류로 보이면 혼란만 커집니다. 운영 가시성은 로그(`auth_result_code`/
`auth_result_msg`)로만 보존합니다. 반면 2단계(승인) 이후의 실패(서명·MID·금액 불일치)는
"돈이 오갔을 수 있는" 실패라 `?error=` 쿼리로 명시적으로 안내합니다.
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
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-pay_nicepayments --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-pay_nicepayments --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-pay_nicepayments --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-pay_nicepayments --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-pay_nicepayments --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**PC/모바일 결제 승인**: 결제창(iframe 팝업)이 `/payment/callback`으로 인증 결과 POST →
`AuthResultCode == '0000'` 확인(아니면 §1 "의도적으로 하지 않는 것"의 silent redirect) →
`sirsoft-pay_nicepayments.payment.before_authorize` 훅 → 서버가 `NextAppURL`로 승인 API
호출 → `sirsoft-pay_nicepayments.payment.after_authorize` 훅 → 이커머스 주문 결제 완료
처리. 결제 요청 시점에 주문의 `total_tax_amount`/`total_vat_amount`/`total_tax_free_amount`
가 모두 0이 아니면 과세 필드를 폼에 포함합니다(§4 "과세 처리").

**결제 취소(환불)**: 관리자가 주문 취소(`cancel_pg=true`) → 코어가
`sirsoft-ecommerce.payment.refund` 필터 발화 → `PaymentRefundListener`(우선순위 10)가 먼저
나이스페이먼츠 취소 API 호출(전액취소 `isPartial=0`/부분취소 `isPartial=1`) →
`CancelActivityLogListener`(우선순위 20)가 결과를 활동 로그에 별도 기록. 가상계좌 입금
완료 건은 환불 계좌 정보가 필요해 일반 취소 API가 아니라 별도 어드민 환불 계좌 API 경로로
처리됩니다. 취소 API 호출이 실패하면 `refund_failed` 훅이 발화합니다.

**에스크로 배송 등록**: 관리자 주문 상세에서 운송장번호·택배사를 입력 →
`AdminEscrowController::registerDelivery()` → 나이스페이먼츠 배송 등록 API 호출.
`EscrowDeliveryRegisterRequest`가 입력을 검증합니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 5개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 9개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 8개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 5개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 1개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
`before_authorize`/`before_cancel`은 API 호출 **전** 개입 지점이라 여기서 예외를 던지면
실제 나이스페이먼츠 호출이 일어나지 않습니다. `refund_failed`는 취소 API 호출이 실패했을
때만 발화하는 별도 훅입니다 — `after_cancel`(성공 응답 후)과 구분해서 구독해야 합니다.
운영자 알림(예: Slack) 을 붙이고 싶은 확장은 `after_*`가 아니라 `refund_failed`를 잡습니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-pay_nicepayments --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-pay_nicepayments` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 승인/취소 흐름을 고칠 때 `before_*`/`after_*` 훅 순서와 우선순위(`PaymentRefundListener` < `CancelActivityLogListener`)를 유지 — 로그가 실제 처리보다 먼저 실행되면 안 된다
- [ ] 인증 실패(1단계)와 승인 실패(2단계)의 사용자 안내 방식(silent redirect vs `?error=`)을 구분 유지 — §1 "의도적으로 하지 않는 것" 참고
- [ ] IP 화이트리스트(`VbankNotifyIpWhitelist`) 대상 라우트를 추가/변경하면 미들웨어 부착 대상(targets)도 함께 갱신
- [ ] 새 간편결제 수단을 추가하면 그 결제수단의 계약 상태를 관리자 안내에도 반영
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan plugin:update sirsoft-pay_nicepayments --force`
- [ ] 나이스페이먼츠가 SDK 호스트를 바꾸면 `plugin.json` 의 `trusted_script_hosts`(+`trusted_script_hosts_reason`)와 `resources/js/handlers/requestPayment.ts` 의 `KNOWN_SDK_HOSTS` 를 **함께** 갱신 — 두 목록이 어긋나면 테스트가 실패하며, 코드 상수에 없는 호스트는 주입 직전 확인에서 거부되어 결제가 진행되지 않는다(fail-closed). 변경 후 `php artisan ext:docgen --scope=plugin:sirsoft-pay_nicepayments` 재실행

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 결제창 인증 실패(`AuthResultCode != '0000'`)를 승인 실패와 동일하게 `?error=` 로 안내 | 승인 API 호출 전 실패는 체크아웃으로 silent redirect, 로그로만 기록 | 아직 결제 시도 자체가 성립하지 않은 단계인데 오류 메시지를 띄우면 사용자가 "돈이 빠져나갔나" 불필요하게 불안해한다 |
| 가상계좌 입금통보(`vbank-notify`)에 IP 화이트리스트 미부착 | `VbankNotifyIpWhitelist` 미들웨어 유지 | 통보 엔드포인트는 나이스페이먼츠 서버만 호출해야 하며, 화이트리스트가 없으면 제3자가 위조 입금통보를 보내 결제 상태를 조작할 수 있다 |
| 부분취소인데 가상계좌 입금 완료 건을 일반 취소 API로 처리 | 환불 계좌 정보가 필요한 가상계좌 건은 별도 어드민 환불 계좌 API 경로로 처리 | 가상계좌는 카드와 달리 PG가 자동으로 환불할 계좌를 모르므로 일반 취소 API를 호출하면 실패하거나 환불이 누락된다 |
| 라이브 가맹점 키를 로그·에러 메시지에 노출 | 운영 키는 항상 마스킹하거나 로그 대상에서 제외 | 노출되면 제3자가 결제 요청을 위조할 수 있다 |
| 콜백이 넘겨준 `NextAppURL`/`NetCancelURL` 의 도메인을 원문 host 로 대조 (`str_ends_with` 등) | `OutboundUrlValidator::normalizeHost()` 로 정규화한 뒤 대조 (사본 금지 — 코어가 SSoT) | UTS#46 정규화에서 전각 문자가 ASCII 구분자로 바뀐다. `evil.example／.nicepay.co.kr`(U+FF0F)은 접미사 검사를 통과하지만 실제 연결 host 는 `evil.example` 이 되어, 인증 토큰과 MID 가 실린 POST 가 외부로 나간다 |
| 그 URL 에 userinfo(`user@host`)가 있어도 통과 | userinfo 존재만으로 거부 | `@` 앞부분이 신뢰 도메인처럼 보이게 위장할 수 있다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 23개 | `plugins/_bundled/sirsoft-pay_nicepayments/tests` |
| Vitest | 8개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 1개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-pay_nicepayments/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-pay_nicepayments && powershell -Command "npm run test:run -- <대상>"

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
