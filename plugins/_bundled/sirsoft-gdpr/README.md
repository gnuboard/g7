# GDPR Plugin for G7

GDPR(유럽 일반 데이터 보호 규정) 및 한국 개인정보보호법 대응 핵심 기능을 제공하는 G7 플러그인입니다. 쿠키 동의 배너·동의 전 자동 차단·동의 이력 영구 저장·마이페이지 동의 철회를 한 패키지로 제공합니다.

## 핵심 기능

| 기능 | 설명 |
|------|------|
| 쿠키 동의 배너 | 필수/기능/분석/마케팅 4분류 (ICO·CNIL 권장 표준), 다크 모드, 4 위치(하단 바·좌하단·우하단·중앙 모달) |
| 동의 전 자동 차단 | 외부 추적 스크립트·iframe + 기능 카테고리 1st-party 저장소(localStorage·sessionStorage·1st-party 쿠키) 게이팅 |
| 동의 이력 저장 | 정책 버전·출처·카테고리 스냅샷을 함께 immutable 보존 (GDPR Art.7(1) 입증 자료) |
| 마이페이지 동의 관리 | 회원이 자신의 동의 현황을 조회·개별 철회·재동의·전체 일괄 재동의 |
| 관리자 동의 이력 조회 | 회원/게스트 동의 변경 이력 (이메일·세션 검색, 카테고리·출처 다중 필터, 카테고리 스냅샷 표) |
| 정책 버전 수동 발행 | 정책 본문 변경 시 「+ 새 버전 발행」 클릭으로 모든 회원 재동의 트리거 |

## 설치

```bash
php artisan plugin:install sirsoft-gdpr
php artisan plugin:activate sirsoft-gdpr
```

설치 직후 쿠키 배너는 **비활성** 상태로 제공됩니다. 운영자가 운영 주체명·데이터 저장 위치·정책 페이지 슬러그를 입력한 뒤 「쿠키 배너 노출」 토글을 켜야 사이트에 노출됩니다.

## 설정

`관리자 → 플러그인 → GDPR 설정` 에서 구성합니다.

### 운영 정보

| 항목 | 설명 |
|------|------|
| 운영 주체명 (`legal_entity_name`) | 쿠키 배너 푸터·마이페이지 동의 카드에 노출되는 사이트 운영 주체 (예: "(주)홍길동컴퍼니") |
| 데이터 저장 위치 (`data_storage_location`) | 사용자에게 안내할 데이터 저장 국가 (예: "대한민국", "미국 (AWS)"). IP 주소·CIDR·클라우드 리전 코드(예: `ap-northeast-2`)는 보안상 자동 거부됩니다 |
| 개인정보처리방침 슬러그 (`privacy_policy_slug`) | sirsoft-page 플러그인에 등록된 처리방침 페이지의 슬러그. 비어있으면 쿠키 배너의 정책 링크가 자동 숨겨집니다 |

### 쿠키 배너

| 항목 | 설명 |
|------|------|
| 쿠키 배너 노출 (`banner_enabled`) | 마스터 토글 — ON 시 배너 + 자동 차단 + 마이페이지 동의 관리 카드가 일괄 활성화됩니다. GDPR Art.6 "동의 전 처리 금지" 의 강제 메커니즘인 자동 차단을 단독 OFF 할 수 없도록 단일 토글로 통합되어 있습니다 |
| 배너 위치 (`banner_position`) | 하단 바 / 좌하단 팝업 / 우하단 팝업 / 중앙 모달 |

### 자동 차단 정책

쿠키 배너가 ON 일 때 다음 카테고리의 외부 도메인 리소스가 동의 전까지 자동 차단됩니다. 카탈로그 기본값이 시드되어 있으며, 운영자가 카테고리별로 추가·삭제할 수 있습니다.

| 카테고리 | 기본 카탈로그 예시 |
|---------|------------------|
| 기능 (functional) | Crisp, Intercom, Tawk.to, Weglot 등 |
| 분석 (analytics) | Google Analytics, Hotjar, Mixpanel, 네이버 프리미엄 로그분석 등 |
| 마케팅 (marketing) | Facebook Pixel, Google Ads, Kakao Pixel, YouTube embed 등 |

도메인 형식: `example.com` 또는 와일드카드 `*.example.com`. `localhost` 같은 단일 라벨, 한글 도메인(xn-- 변환)은 미지원.

## 자체 호스팅 추적 자원 분류

자체 도메인에서 호스팅되는 추적 스크립트·iframe·임베드는 도메인 매칭 대상이 아니므로 HTML 속성으로 분류합니다.

```html
<script src="/js/my-analytics.js" data-gdpr-category="analytics"></script>
<iframe src="/embed/custom-tracker" data-gdpr-category="marketing"></iframe>
```

동의 전까지 자동 차단되며, 동의 후 자동 복원됩니다. 동의 철회 시 다시 차단됩니다.

## 정책 버전 발행

`관리자 → 플러그인 → GDPR 설정` 의 「정책 버전」 카드에서 「+ 새 버전 발행」 을 클릭합니다. 발행 즉시 모든 회원이 다음 방문 시 재동의 화면(amber 안내 박스 + 「최신 정책으로 갱신」 버튼)을 보게 됩니다.

| 발행이 필요한 변경 | 발행이 필요 없는 변경 |
|------------------|---------------------|
| 정책 본문 (개인정보처리방침 페이지) 변경 | 차단 도메인 추가/삭제 |
| 카테고리 의미 변경 | UI 라벨/설명 정정 |
| 위탁자·데이터 보관 정보 변경 | 운영 주체명·저장 위치 정정 |

발행 시 변경 사유 메모를 함께 저장합니다 (GDPR Art.30 처리 기록 의무).

## 동의 이력 조회

`관리자 → GDPR 동의 이력` 메뉴에서 회원/게스트의 모든 동의 변경 이력을 조회할 수 있습니다.

- **검색**: 이메일 부분 일치, 세션 ID
- **필터**: 카테고리(필수/기능/분석/마케팅), 출처(banner/mypage/register/withdraw 등), 동의 액션(granted/revoked)
- **카테고리 스냅샷**: 각 행 펼침에서 동의 시점의 전체 카테고리 의사 표를 immutable 보존 (GDPR Art.7(1) 입증 자료)

## 가용 훅 (Hook)

다른 확장에서 본 플러그인의 동의 이벤트를 구독할 수 있습니다.

### 액션 훅

| 훅 이름 | 시점 | 인수 |
|---------|------|------|
| `sirsoft-gdpr.consent.granted` | 동의 부여 시 | `GdprUserConsent $consent, string $source` |
| `sirsoft-gdpr.consent.revoked` | 동의 철회 시 | `GdprUserConsent $consent, string $source` |

`$source` 값: `banner` / `mypage` / `mypage_renew_all` / `register` / `withdraw`.

### 훅 등록 예시

```php
use App\Extension\HookManager;

HookManager::addAction(
    'sirsoft-gdpr.consent.granted',
    function ($consent, string $source) {
        // 예: 분석 동의 부여 시 외부 분석 도구에 사용자 식별 전송
        if ($consent->consent_key === 'cookie_analytics' && $consent->is_consented) {
            AnalyticsService::identify($consent->user_id);
        }
    },
    priority: 10
);
```

## 데이터베이스

| 테이블 | 용도 | 보존 정책 |
|--------|------|----------|
| `gdpr_user_consents` | 회원 현재 동의 상태 (mutable) | 사용자 삭제 시 명시 삭제 |
| `gdpr_user_consent_histories` | 동의 변경 이력 (immutable append-only) | 사용자 삭제 시 `user_id`/IP/UA 만 NULL 익명화하여 행 보존 (GDPR Art.17 + Art.7(1) 양립) |
| `gdpr_policy_versions` | 정책 버전 발행 이력 (불변) | 영구 보존 (Art.30 처리 기록) |

플러그인 제거 시 위 3 테이블이 자동 DROP 됩니다.

## 게스트 → 회원 동의 승계

본 플러그인은 게스트 → 회원 동의 자동 승계를 제공하지 않습니다. GDPR Art.6/ePrivacy Art.5(3) 관점에서 게스트(디바이스 단위)와 회원(주체 단위)은 별도 동의 모델이며, 글로벌 CMP 대부분도 자동 승계를 기본으로 제공하지 않습니다. 회원가입 폼 동의로 Art.7(1) 입증 책임이 충족되며, 게스트 시절 동의 이력은 세션 기준으로 보존됩니다.

## 의존성

| 대상 | 의존 수준 | 미설치 시 동작 |
|------|----------|---------------|
| `sirsoft-page` 모듈 | 소프트 (런타임 체크) | 배너 "자세히" 링크만 자동 숨김. 나머지 정상 |
| `sirsoft-basic` 템플릿 | 주입 지점 의존 | 다른 사용자 템플릿 사용 시 해당 템플릿에도 `_user_base.json` 의 공용 확장 지점이 있어야 정상 동작 |

## 테스트 실행

```bash
# 백엔드
php vendor/bin/phpunit plugins/_bundled/sirsoft-gdpr/tests

# 프론트엔드
cd plugins/_bundled/sirsoft-gdpr
npm run test:run
```

## 라이선스

MIT