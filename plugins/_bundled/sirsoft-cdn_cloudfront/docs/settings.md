# CloudFront CDN — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `cdn_base_url` | `string` | - | CDN 주소 |
| `distribution_id` | `string` | - | 배포 ID |
| `access_key` | `string` | - | 무효화용 액세스 키 |
| `secret_key` | `string` | - | 무효화용 비밀 키 |
| `purge_enabled` | `boolean` | `true` | 캐시 자동 무효화 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
- 무효화 자격 두 칸은 sensitive 라 조회 응답에서 가려지고, 가린 값이 그대로 돌아오면 저장값을 유지한다.
- 배포 ID, 무효화용 키, CDN 주소 가운데 하나라도 비거나 `purge_enabled` 가 꺼지면 무효화를 보내지 않는다. 재시도 대기 건도 시도 횟수를 쓰지 않고 기다린다.
- 저장 디스크(버킷, 지역, 자격)는 이 설정이 아니라 코어 환경설정 드라이버 탭의 S3 설정을 쓴다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 권한이 없습니다._
<!-- @generated:permissions END -->

<!-- @intent START -->
플러그인 자체 권한이 없다. 설정 화면과 보조 API 는 코어 플러그인 권한 축을 그대로 쓴다 — 설정 화면 진입과 현황 조회는 `core.plugins.read`(보기 권한만 있으면 읽기 전용으로 열린다), 저장과 외부로 요청을 보내는 연결 시험은 `core.plugins.update`.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
관리자 메뉴를 따로 두지 않는다. 플러그인 관리 목록의 설정 버튼으로 연다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-cdn_cloudfront/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
두 라우트 모두 `auth:sanctum` 뒤에 코어 `permission` 미들웨어로 권한을 건다. FormRequest 의 `authorize()` 는 권한을 판정하지 않는다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
다른 확장에 의존하지 않는다. AWS SDK 는 코어 vendor 의 `aws/aws-sdk-php` 를 쓰며 이 플러그인이 따로 require 하지 않는다. 코어 7.2.0 의 썸네일 기능(`core.thumbnail.after_purge`)이 필요하다.
<!-- @intent END -->
