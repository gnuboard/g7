# 수출입은행 환율 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `auth_key` | `string` | - | 인증키 |
| `auto_manage` | `boolean` | `false` | 환율 자동 관리 사용 |
| `update_time` | `string` | `11:30` | 갱신 시각 (한국시간) |
| `run_days` | `array` | `[1,2,3,4,5]` | 실행 요일 |
| `retry_max` | `integer` | `3` | 최대 재시도 |
| `rate_type` | `enum` | `deal_bas_r` | 환율 종류 |
| `rate_decimal_places` | `integer` | `6` | 환율 소수 자릿수 |
| `lookback_days` | `integer` | `7` | 직전 영업일 탐색 일수 |
| `history_retention_days` | `integer` | `365` | 이력 보존 일수 |
| `notify_on_failure` | `boolean` | `true` | 실패 알림 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
auto_manage의 기본값은 false입니다. 인증키는 민감 설정으로 저장하고 설정 API는 마스크를 반환합니다. 공개 프론트엔드에는 auto_manage만 노출합니다. run_days는 ISO 요일(월=1, 일=7)이며 빈 배열은 정기 실행하지 않음을 의미합니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `exchange_rate` | 환율 | `view`, `manage` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
조회와 실행 권한을 분리합니다. view는 상태·목록·상세 조회, manage는 수동 실행과 플러그인 설정 변경을 제어합니다. 코어 설정 API의 원래 권한도 필요하며 알림 템플릿 편집은 코어 settings.update 권한을 따릅니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
별도 시스템 메뉴를 등록하지 않습니다. 플러그인 관리에서 이 플러그인의 환경설정을 열면 인증키 입력과 상태·갱신 설정을 사용할 수 있습니다. 설정·이력은 tab 쿼리로 구분하여 링크와 새로고침에서 선택을 유지합니다. 비활성화하면 모듈 제공자 카탈로그에서 빠집니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-koreaexim/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
GET status/runs/runs/{run}과 POST runs 네 경로를 제공합니다. POST의 dry_run=true는 환율을 적용하지 않는 연결 테스트이며, 모든 수동 호출은 60초 간격·KST 하루 20회로 제한합니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

| 확장 | 유형 | 버전 제약 | 번들 |
|---|---|---|---|
| `sirsoft-ecommerce` | 모듈 | `>=1.3.0` | ✅ |

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
이커머스 1.3.0의 제공자 선택·환율 적용 계약을 사용합니다. 먼저 모듈을 업데이트한 뒤 플러그인을 활성화해야 하며, 플러그인 제거가 기존 이커머스 통화값을 삭제하지 않습니다.
<!-- @intent END -->
