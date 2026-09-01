# KG 이니시스 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-pay_kginicis) — KG 이니시스 PG 연동(PC/모바일/가상계좌/에스크로/일본 CBT). 소유 테이블 없음 — 상태는 sirsoft-ecommerce 소유
2. 확장 방식: `RegisterPgProviderListener`/`RegisterCashReceiptProviderListener` 로 이커머스 레지스트리에 등록 — 이커머스 코드는 이 플러그인을 모른다
3. 건드리면 안 되는 것: `authUrl`/`P_REQ_URL`/`netCancelUrl` 화이트리스트 검증 생략, 콜백 재처리 방지 로직 우회, IP 화이트리스트 미들웨어(`InicisNotifyIpWhitelist`) 미부착
4. 작업 위치: `plugins/_bundled/sirsoft-pay_kginicis` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-pay_kginicis --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
KG 이니시스 PG(결제 게이트웨이)를 `sirsoft-ecommerce`에 연결하는 어댑터입니다. 결제수단마다
프로토콜이 다릅니다 — PC 는 브라우저 결제창 + 서버 승인 API, 모바일은 폼 POST 이동 + 별도
승인 API, 일본 CBT 는 완전히 다른 인증/승인 체계(JPPG)를 씁니다. 이 플러그인의 역할은 그
세 가지 서로 다른 프로토콜을 전부 흡수해 이커머스 쪽에는 "결제 성공/실패/취소"라는 하나의
결과만 넘기는 것입니다.

**설계 원칙**: 이 플러그인은 상태를 소유하지 않습니다(§data-model.md — 모델·테이블 0개).
주문·결제 상태는 전부 `sirsoft-ecommerce`의 테이블에 있고, 이 플러그인은 PG API 와 그 상태를
동기화하는 역할만 합니다. 등록도 코드 결합이 아니라 훅 기반입니다
(`sirsoft-ecommerce.payment.registered_pg_providers` 필터) — 이커머스 모듈은 이 플러그인의
존재를 컴파일 타임에 몰라도 됩니다.

**의도적으로 하지 않는 것**: 결제 실패 시 자동으로 다른 PG 로 재시도하지 않습니다 — PG 마다
가맹점 계약·결제수단이 다르므로 자동 전환은 이중 결제·과금 위험을 만듭니다. 또한 일본 결제
설정이 불완전할 때 한국 표준결제로 조용히 대체하지 않고 결제 자체를 중단합니다 — 설정 실수를
"어쨌든 결제는 된다"로 감추면 잘못된 통화·수수료로 승인될 수 있습니다.
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
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-pay_kginicis --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-pay_kginicis --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-pay_kginicis --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-pay_kginicis --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-pay_kginicis --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**PC 결제 승인**: `PaymentCallbackController`(KG 이니시스가 POST 하는 authToken/authUrl 수신)
→ `authUrl` 화이트리스트 검증 → `sirsoft-pay_kginicis.payment.before_authorize` 훅 →
`KgInicisApiService` 가 승인 API 호출 → `sirsoft-pay_kginicis.payment.after_authorize` 훅 →
이커머스 주문 결제 완료 처리. 승인 후 로컬 처리 실패 시 `netCancelUrl` 로 망취소를 시도합니다
— 이 지점이 실패하면 "PG 는 승인, 우리는 실패"인 가장 위험한 상태이므로 반드시 오류 로그를
남깁니다.

**결제 취소(환불)**: 관리자가 주문 취소(`cancel_pg=true`) → 코어가
`sirsoft-ecommerce.payment.refund` 필터 발화 → 이 플러그인의 `PaymentRefundListener`
(우선순위 10)가 먼저 KG 이니시스 취소 API 호출 → `CancelActivityLogListener`(우선순위 20)가
그 결과(PG 응답 시각·취소 TID)를 활동 로그에 별도 기록. 우선순위 순서가 중요합니다 — 취소가
실제로 성공한 뒤에야 로그를 남겨야 "로그는 있는데 실제 취소는 실패"가 생기지 않습니다.

**일본 CBT 승인**: `/payment/cbt/hash-data` 로 해시 생성(타임스탬프 신선도 검증) →
CBT 인증 URL 로 폼 POST → KG 이니시스가 `sid` 를 콜백으로 전달 → `cbtapprove` API 호출 →
카드/PayPay 는 즉시 완료, 편의점은 입금대기로 저장 후 별도 NOTI 수신 시 완료. 로컬 후속
처리가 실패하면 CBT 전용 취소 API 로 자동 취소를 시도하고, 그마저 실패하면 수동 취소가
필요하다는 오류 로그를 남깁니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 6개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 14개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 11개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 4개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 1개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
`before_authorize`/`before_cancel`/`before_cbt_refund` 는 PG 호출 **전** 개입 지점입니다 —
예를 들어 고액 결제에 본인인증을 추가로 요구하고 싶은 확장이 `before_cancel` 을 잡아 조건
미충족 시 예외를 던지면 KG 이니시스 API 호출 자체가 일어나지 않습니다(`before_cancel` 의
용도로 이미 "본인인증 등 확장 지점"이라 발행 위치에 명시돼 있습니다). `after_*` 훅은 PG 응답을
받은 뒤 부가효과(추가 로그, 알림 등)를 붙이는 자리입니다. 구독 훅 14개 중 다수가
`core.layout_extension.after_apply` 인 이유는 관리자 주문 목록/상세 화면에 "테스트 모드
배지"·"거래 조회 UI"를 레이아웃 확장으로 주입하기 때문입니다(§레이아웃 확장).
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-pay_kginicis --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-pay_kginicis` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 승인/취소 흐름을 고칠 때 `before_*`/`after_*` 훅 순서와 우선순위(`PaymentRefundListener` < `CancelActivityLogListener`)를 유지 — 로그가 실제 처리보다 먼저 실행되면 안 된다
- [ ] IP 화이트리스트(`InicisNotifyIpWhitelist`) 대상 라우트를 추가/변경하면 미들웨어 부착 대상(targets)도 함께 갱신
- [ ] 새 결제수단·통화를 추가하면 그 결제수단의 콜백 URL을 관리자 설정 안내(README "콜백/통보 URL 등록")에도 반영
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan plugin:update sirsoft-pay_kginicis --force`

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| PG 콜백의 `authUrl`/`P_REQ_URL`/`netCancelUrl`을 화이트리스트 없이 그대로 호출 | KG 이니시스 허용 URL 목록과 대조 후에만 호출 | 콜백 파라미터를 신뢰하면 공격자가 임의 URL로 서버발 요청을 유도할 수 있다(SSRF) |
| 동일 거래번호 콜백을 매번 재처리 | 콜백 재처리 방지 검사를 거친 뒤 처리 | 재처리를 막지 않으면 같은 결제가 중복 완료 처리되거나 중복 환불될 수 있다 |
| 결제창 서명/모바일 해시/CBT 해시 요청에 타임스탬프 검증 생략 | 타임스탬프 신선도 검증 유지 | 오래된 서명 재사용(replay)으로 위조 결제 요청이 통과할 수 있다 |
| 일본 결제 설정 미완료 시 한국 표준결제로 조용히 대체 | 설정 미완료면 결제 자체를 중단 | 통화·수수료·정산 구조가 다른 결제가 잘못된 흐름으로 승인될 수 있다 |
| 라이브 키(사인키·INIAPI 키/IV·해시키)를 로그·에러 메시지에 노출 | 운영 키는 항상 마스킹하거나 로그 대상에서 제외 | 노출되면 제3자가 결제창 서명을 위조할 수 있다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 35개 | `plugins/_bundled/sirsoft-pay_kginicis/tests` |
| Vitest | 12개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 2개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-pay_kginicis/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-pay_kginicis && powershell -Command "npm run test:run -- <대상>"

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
