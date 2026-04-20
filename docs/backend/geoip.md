# GeoIP 시스템 (MaxMind GeoLite2)

> IP 주소 기반 타임존 자동 감지 시스템

---

## TL;DR (5초 요약)

```text
1. MaxMind GeoLite2-City DB 기반 IP → 타임존 감지 (SetTimezone 미들웨어 3단계)
2. 관리자 > 환경설정 > 고급 탭에서 마스터 토글 + 라이선스 키 관리
3. geoip:update 커맨드로 DB 다운로드 (주 1회 자동 스케줄)
4. 마스터 OFF 시 미들웨어 short-circuit — GeoIP 조회 완전 스킵
5. MaxMind 라이선스: 재배포 금지 → 각 환경에서 직접 다운로드 필수
```

---

## 아키텍처

```text
[관리자 고급 탭]
  ├── 마스터 토글 (geoip.feature_enabled)
  ├── 라이선스 키 (geoip.license_key)
  ├── 자동 업데이트 토글 (geoip.auto_update_enabled)
  └── 수동 업데이트 버튼 → POST /api/admin/settings/geoip/update

[스케줄러] routes/console.php
  └── 주 1회(수요일 03:00) geoip:update 실행
      └── 3개 조건 AND 게이트: 마스터 ON + 자동 업데이트 ON + 키 존재

[다운로드 흐름] GeoIpDatabaseService
  └── MaxMind API → tar.gz → PharData 추출 → 원자적 교체 → 메타데이터 갱신

[요청 처리] SetTimezone 미들웨어
  └── 1.유저 설정 → 2.X-Timezone 헤더 → 3.GeoIP(마스터 ON일 때만) → 4.기본값
```

## 설정 카테고리

GeoIP 기능 설정은 독립 카테고리 `geoip`로 관리됩니다 (`merge_into: "advanced"`로 고급 탭에 병합).

| 저장 키 | 프론트 키 | 타입 | 기본값 | 설명 |
|---------|----------|------|--------|------|
| `geoip.feature_enabled` | `advanced.geoip_enabled` | boolean | `false` | 마스터 스위치 |
| `geoip.license_key` | `advanced.geoip_license_key` | string (sensitive) | `""` | MaxMind 라이선스 키 |
| `geoip.auto_update_enabled` | `advanced.geoip_auto_update_enabled` | boolean | `true` | 자동 업데이트 활성화 |
| `geoip.last_updated_at` | `advanced.geoip_last_updated_at` | string | `""` | 마지막 성공 다운로드 시각 (ISO8601) |

**주의**: 기존 `cache.geoip_enabled` / `cache.geoip_ttl`은 **GeoIP 조회 결과 캐시** 설정으로 완전히 별개.

## 파일 구조

| 파일 | 역할 |
|------|------|
| `app/Services/GeoIpDatabaseService.php` | 다운로드/추출/교체/상태 조회 + 훅 체인 |
| `app/Services/GeoIpService.php` | IP → 타임존 조회 (Reader 사용) |
| `app/Console/Commands/GeoIp/UpdateGeoLiteDatabaseCommand.php` | `geoip:update` Artisan 커맨드 |
| `app/Http/Controllers/Api/Admin/GeoIpController.php` | 수동 업데이트 API 트리거 |
| `config/geoip.php` | GeoIP 전체 설정 (활성화, 키, DB 경로, 다운로드, 스케줄, 캐시) |
| `config/settings/defaults.json` | `geoip` 카테고리 스키마 + 기본값 |
| `storage/app/geoip/GeoLite2-City.mmdb` | MaxMind DB 파일 (`.gitignore` 제외) |

## Artisan 커맨드

```bash
# DB 다운로드 (라이선스 키 필요)
php artisan geoip:update

# 강제 재다운로드 (24시간 이내라도)
php artisan geoip:update --force

# URL/키 검증만 (실제 다운로드 X)
php artisan geoip:update --dry-run

# 스케줄 등록 확인
php artisan schedule:list | grep geoip
```

## 훅 시스템

`GeoIpDatabaseService`는 다운로드 전/후에 훅을 실행합니다:

| 훅 | 타입 | 시점 |
|----|------|------|
| `core.geoip.database.before_update` | Action | 다운로드 시작 전 |
| `core.geoip.database.filter_download_context` | Filter | 다운로드 옵션 변형 (edition_id, base_url, timeout 등) |
| `core.geoip.database.after_update` | Action | 다운로드 성공 후 |
| `core.geoip.database.after_update_failed` | Action | 다운로드 실패 후 |

## 미들웨어 short-circuit

`SetTimezone::determineTimezone()` 3단계(GeoIP)는 마스터 스위치가 OFF이면 완전히 스킵됩니다:

```php
if ((bool) g7_core_settings('geoip.feature_enabled', false)) {
    $geoTimezone = $this->geoIpService->getTimezoneByIp(...);
    // ...
}
```

- `g7_core_settings()` 헬퍼로 소스 오브 트루스 직접 참조
- `config('geoip.enabled')` 대신 사용 — `SettingsServiceProvider` 파이프라인 실패에 방어적
- 기본값 `false` — 최초 설치 직후 안전 비활성

## MaxMind 라이선스

- **무료 계정**: https://www.maxmind.com/en/geolite2/signup
- **재배포 금지**: mmdb 파일을 Git에 포함하거나 배포 패키지에 동봉할 수 없음
- `.gitignore`에 `storage/app/geoip/` 추가됨
- 각 운영 환경에서 `geoip:update`로 직접 다운로드해야 함

## 다운로드 엔드포인트

```text
https://download.maxmind.com/app/geoip_download
  ?edition_id=GeoLite2-City
  &license_key={key}
  &suffix=tar.gz
```

- 파일 형식: `.tar.gz` 내부에 `GeoLite2-City_YYYYMMDD/GeoLite2-City.mmdb`
- MaxMind 갱신 주기: 주 2회 (화/금)
- G7 기본 스케줄: 주 1회 (수요일 03:00)

## 검증 규칙

`SaveSettingsRequest`에서 3개 필드 검증:

```php
'advanced.geoip_enabled' => ['nullable', 'boolean'],
'advanced.geoip_license_key' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_]+$/'],
'advanced.geoip_auto_update_enabled' => ['nullable', 'boolean'],
```

- boolean 필드는 `prepareForValidation()`에서 `filter_var(FILTER_VALIDATE_BOOLEAN)` 전처리
- 라이선스 키: `regex` 영숫자/언더스코어만 (SSRF/삽입 방어)
