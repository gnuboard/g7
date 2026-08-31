# Daum 우편번호 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-daum_postcode) — 주소 입력 자리(`address_search_slot`)에 Daum 우편번호 검색을 붙인다. 백엔드 0(라우트·모델·테이블 없음), 조각 1 + 핸들러 2 가 전부
2. 확장 방식: 발행 훅 2개 — 필드에 쓰기 전 가공은 `filter_address_data`, 확정 후 후속 동작은 `address.selected`
3. 건드리면 안 되는 것: 외부 호스트 선언에서 사유(`trusted_script_hosts_reason`) 누락, SDK 실패 시 직접 입력 폴백 제거, 확보 확인 전에 필드를 읽기 전용으로 잠그기
4. 작업 위치: `plugins/_bundled/sirsoft-daum_postcode` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-daum_postcode --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
주소 입력 자리에 **Daum 우편번호 검색 창**을 붙이는 플러그인입니다. 백엔드 코드가 없고
(라우트 0 · 모델 0 · 테이블 0 · 마이그레이션 0), 실체는 레이아웃 확장 조각 하나와 프론트
핸들러 둘입니다.

`address_search_slot` 확장점을 여는 화면(이커머스 배송지 입력 등)이 있으면 그 자리에 검색
버튼이 나타나고, 사용자가 주소를 고르면 지정된 필드들(우편번호·기본주소·도로명·지번)이
채워집니다.

**이 확장은 코어 규정의 예외를 하나 갖습니다.** 구동 자산을 자체 제공하지 않고 Daum 의
CDN(`t1.daumcdn.net`)에서 로드합니다 — 우편번호 SDK 는 라이브러리가 아니라 **Daum 이 운영하는
서비스의 클라이언트**라, 자체 호스팅해도 그 서버와 통신하지 않으면 동작하지 않습니다. 그래서
manifest 에 `trusted_script_hosts` 와 **그 사유(`trusted_script_hosts_reason`)를 함께 선언**
합니다. 사유 없는 외부 호스트 선언은 금지이며, 이 플러그인이 그 예외 기재의 선례입니다.

예외를 두는 대신 **실패 경로를 갖춥니다.** SDK 를 못 불러오면(폐쇄망·광고차단기·Daum 장애)
사용자에게 사실을 알리고 재시도 통로를 남기며, 주소를 직접 입력할 수 있게 합니다 — 검색이
안 된다고 주문을 못 하게 되면 안 되기 때문입니다.

**의도적으로 하지 않는 것**: 백엔드 저장·주소 검증·좌표 변환·해외 주소. 선택된 주소를 어디에
어떻게 저장할지는 그 화면을 소유한 확장(이커머스 등)의 일입니다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-daum_postcode --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-daum_postcode --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-daum_postcode --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-daum_postcode --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-daum_postcode --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
백엔드 흐름이 없으므로 전부 프론트에서 일어납니다.

**장착**: 어떤 화면이 `address_search_slot` 확장점을 열면 → 코어가
`resources/extensions/ecommerce-address-search.json` 을 그 자리에 넣음 → 조각의 `scripts` 가
Daum SDK 를 로드 → 컨테이너 `onMount` 에서 `sirsoft-daum_postcode.setFieldReadOnly` 가 대상
주소 필드를 읽기 전용으로 바꿉니다(검색으로만 채우게 해서 오타를 막습니다). 이 핸들러는
**SDK 확보를 먼저 확인**하고, 확보하지 못했으면 필드를 편집 가능한 상태로 남깁니다 — 읽기
전용 + 검색 불가 조합은 곧 입력 불가이기 때문입니다. 해제(`readOnly: false`)는 언제나 안전
하므로 확보를 기다리지 않습니다.

**검색 → 필드 채움**: 사용자가 버튼을 누름 → `openPostcode` 핸들러가 설정
(`display_mode`: 레이어/팝업, 팝업 크기, 테마 색상)대로 검색 창을 엶 → 주소를 고르면
`filter_address_data` 필터로 데이터를 가공할 기회를 준 뒤 지정된 필드들에 값을 쓰고
`address.selected` 액션을 발행합니다.

**SDK 확보 실패**: `postcodeSdk.ts` 가 스크립트 로드에 `onerror` 를 걸어 실패를 감지하고,
`G7Core.assets.notifyFailure` 로 사용자에게 사실과 재시도 통로를 제시합니다. 필드는 편집
가능한 채로 남아 **직접 입력**이 가능하며, 재시도가 성공하면 그때 검색 흐름으로 돌아옵니다 —
이 경로가 없으면 SDK 가 막힌 환경에서 주소를 아예 넣을 수 없습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 2개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 0개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 0개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 1개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅 2종은 **선택된 주소를 가로채는** 자리입니다.

| 훅 | 언제 쓰는가 |
|---|---|
| `filter_address_data` | 주소 데이터를 필드에 쓰기 **전**에 가공. 도로명/지번 중 어느 것을 기본으로 쓸지, 건물명을 상세주소에 미리 넣을지 등 |
| `address.selected` | 주소가 확정된 **후**. 배송비 재계산·배송 가능 지역 판정 같은 후속 동작 |

두 훅 모두 `getHooks()` 에 파라미터까지 선언되어 있습니다 —
`zonecode`(우편번호) · `address`(기본) · `roadAddress`(도로명) · `jibunAddress`(지번) ·
`buildingName`(건물명). 발행 위치가 "선언(호출 위치 미확인)" 인 것은 실제 발행이 **프론트
핸들러**에서 이루어져 PHP 소스 스캔에 잡히지 않기 때문입니다.

레이아웃 조각 하나(`ecommerce-address-search.json`)가 이 플러그인의 UI 전부입니다. 파일
이름에 `ecommerce` 가 붙어 있지만 **확장점 이름(`address_search_slot`)으로 매칭**되므로,
그 확장점을 여는 화면이면 어디든 붙습니다 — 이커머스 전용이 아닙니다.

구독 훅·리스너·미들웨어·브로드캐스트 채널·스케줄·알림·권한·메뉴·라우트는 전부 0개입니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-daum_postcode --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 외부 스크립트 호스트를 늘린다면 `trusted_script_hosts` 와 **사유**(`trusted_script_hosts_reason`)를 함께 선언 — 자체 제공이 원칙이고 이 플러그인은 예외 기재의 선례다
- [ ] SDK 확보 실패 경로(통지·재시도·직접 입력)를 건드렸다면 `resources/js/__tests__/postcode-fallback.test.ts` 를 함께 갱신·실행
- [ ] `dist/` 는 커밋되는 배포 산출물 — TS 를 고쳤으면 `--production` 재빌드 후 커밋 (`sourceMappingURL` 잔존 금지)
- [ ] 조각이 붙는 확장점(`address_search_slot`)을 여는 화면이 그 자리를 없애면 오류 없이 사라진다 — 대상 확장 업그레이드 후 노출 확인
- [ ] 프론트엔드를 고쳤다면 Playwright spec 을 함께 갱신·실행한다 (단위 테스트만으로는 장착 회귀가 드러나지 않는다)

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| `trusted_script_hosts` 만 선언하고 사유를 생략 | `trusted_script_hosts_reason` 에 호스트별 사유 동반 | 자체 제공이 원칙이고 외부 호스트는 예외다 — 예외의 근거가 코드에 남지 않으면 다음 사람이 무심코 CDN 을 늘린다 |
| SDK 확보 실패 시 검색 버튼만 죽이고 끝내기 | 사용자 통지 + 재시도 + **필드를 편집 가능하게 남겨 직접 입력 폴백** | 검색이 막힌 환경에서 주소를 아예 넣을 수 없게 되면 그 화면 전체(주문·배송지 등록)가 불능이 된다 |
| 스크립트 로드에 `onerror` 를 걸지 않거나 실패를 `resolve()` 로 삼키기 | 실패를 명시적으로 감지해 폴백으로 분기 | 삼키면 "버튼을 눌러도 아무 일이 없다" 가 되고 콘솔 외에는 흔적이 없다 |
| SDK 확보를 확인하지 않고 필드를 먼저 읽기 전용으로 만들기 | 확보 확인 후에만 읽기 전용 적용 (해제는 확인 없이) | 읽기 전용 + 검색 불가 = 입력 불가. 순서가 뒤집히면 실패 환경에서 필드가 잠긴 채 남는다 |
| 선택된 주소를 이 플러그인이 직접 저장 | 필드에 쓰고 `address.selected` 발행까지 | 저장 위치·형식은 화면을 소유한 확장이 정한다. 여기서 저장하면 그 확장마다 분기가 늘어난다 |
| 도로명/지번 중 하나를 코드에 고정 | `filter_address_data` 로 소비처가 고르게 | 사이트마다 표기 정책이 다르다 |
| 자산 URL 을 문자열로 조립 | `G7Core.asset.plugin` | 확장자를 정적 location 이 가로채는 서버에서 조립한 URL 만 404 가 된다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 0개 | — |
| Vitest | 1개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 0개 | — |

```bash
# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-daum_postcode && powershell -Command "npm run test:run -- <대상>"

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
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
