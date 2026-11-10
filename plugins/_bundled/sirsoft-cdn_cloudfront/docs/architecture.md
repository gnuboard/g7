# CloudFront CDN — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
- 코어는 썸네일을 만들고 지우고, 지운 접두를 요청 끝에 한 번 알린다(`core.thumbnail.after_purge`). 이 플러그인은 그 알림을 CloudFront 무효화로 바꾸는 어댑터다 — 코어 코드를 바꾸지 않는다.
- CDN 디스크는 새 저장소가 아니라 코어 S3 디스크의 「공개 URL 만 다른 사본」이다. 그래서 버킷, 자격 설정 화면이 하나로 유지되고, 플러그인을 끄면 디스크 정의만 사라져 코어의 고아 디스크 폴백(공개 썸네일 → 비공개 썸네일 디스크, 공개 자산 → 스트리밍)이 그대로 동작한다.
- 무효화는 「최선 노력 + 재시도」다. 실패해도 사용자 작업은 성공하고(대기열 쓰기가 실패해도 경고 로그만 남긴다), 대기열이 5분 간격 지수 대기로 5번까지 다시 보낸다. 재시도는 시각이 된 대기 건을 합쳐 한 번에 보내고, 합친 요청이 거절되면 시도 횟수를 쓰지 않고 건별로 다시 보내 불량 건만 실패로 센다. 건별 전송은 첫 시도와 같은 호출자 참조를 쓴다. 진행 중 한도, 호출 제한 오류는 시도 횟수로 세지 않는다. 멈춘 건은 30일 뒤 정기 작업이 지운다.
- 무효화 한도를 넘는 묶음은 경로가 많은 첫 세그먼트부터 그 안에서만 접고, 그래도 넘으면 요청을 나눈다. 배포 전체(`/*`)는 어떤 경로로도 보내지 않는다.
- 디스크 정의는 프로세스 부팅 때 한 번이다. 코어의 작업별 설정 다시 읽기는 디스크를 다시 만들지 않으므로, 설정 저장, 초기화, 비활성화 때 `SyncCloudFrontDiskListener` 가 그 프로세스의 정의를 고치고 큐 워커에 재시작 신호를 보낸다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```text
코어 ThumbnailPurgeBuffer ──(action core.thumbnail.after_purge)──▶ PurgeThumbnailsListener
                                                                       └▶ PurgeService::purgeItems
                                                                            ├ InvalidationPaths (경로 계산)
                                                                            ├ CloudFrontClientFactory (SDK)
                                                                            └ PurgeQueueRepositoryInterface ▶ CloudfrontPurge (cloudfront_purges)
스케줄 retry-purges ▶ RetryPurgesCommand ▶ PurgeService::retryDue
관리자 화면 ▶ CloudFrontAdminController (FormRequest) ▶ PurgeService::status / testConnection
CdnCloudfrontServiceProvider::booted ▶ CloudFrontDisk::register (활성일 때만)
코어 DriverRegistryService ──(filter core.settings.available_public_asset_drivers)──▶ RegisterCloudFrontDiskListener
```
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-cdn_cloudfront --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-cdn_cloudfront --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-cdn_cloudfront --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-cdn_cloudfront --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-cdn_cloudfront --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
