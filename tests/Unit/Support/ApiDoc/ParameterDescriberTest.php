<?php

namespace Tests\Unit\Support\ApiDoc;

use App\Support\ApiDoc\ParameterDescriber;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 요청 파라미터 설명기 단위 테스트.
 *
 * 도메인 무관하게 의미가 고정된 공통 파라미터(페이지네이션·정렬·검색·필터·
 * 리소스 식별자 등)의 설명이 위치/명명 규칙대로 채워지는지, 그리고 도메인 특이
 * 파라미터는 null(사람 서술 폴백)로 남는지 검증한다.
 */
class ParameterDescriberTest extends TestCase
{
    #[Test]
    public function 공통_페이지네이션_정렬_파라미터를_정확_사전으로_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertStringContainsString('페이지 번호', $describer->describe('page', 'query', 'integer'));
        $this->assertStringContainsString('페이지당', $describer->describe('per_page', 'query', 'integer'));
        $this->assertStringContainsString('정렬 기준 필드', $describer->describe('sort_by', 'query', 'string'));
        $this->assertStringContainsString('검색어', $describer->describe('search', 'query', 'string'));
        $this->assertStringContainsString('필터', $describer->describe('filters', 'query', 'array'));
    }

    #[Test]
    public function 기간_토글_파라미터를_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertStringContainsString('시작일', $describer->describe('start_date', 'query', 'date'));
        $this->assertStringContainsString('종료일', $describer->describe('end_date', 'query', 'date'));
        $this->assertStringContainsString('활성', $describer->describe('is_active', 'query', 'boolean'));
        $this->assertStringContainsString('강제', $describer->describe('force', 'body', 'boolean'));
    }

    #[Test]
    public function sort_order_는_타입으로_방향과_순서값을_구분한다(): void
    {
        $describer = new ParameterDescriber;

        // 문자열 asc/desc → 정렬 방향
        $this->assertStringContainsString('정렬 방향', $describer->describe('sort_order', 'query', 'string'));
        $this->assertStringContainsString('정렬 방향', $describer->describe('order', 'query', 'string'));
        // 정수 → 표시 순서 값
        $this->assertStringContainsString('표시 정렬 순서', $describer->describe('sort_order', 'body', 'integer'));
        $this->assertStringContainsString('표시 정렬 순서', $describer->describe('order', 'body', 'integer'));
    }

    #[Test]
    public function path_의_order_는_정렬방향이_아니라_리소스_식별자다(): void
    {
        // 라우트 모델 바인딩 {order} 는 주문 리소스를 가리킨다 — 정렬 방향일 수 없다.
        // (회귀: `| order | path | ... | 정렬 방향 (asc 오름차순 / desc 내림차순) |` 오염)
        $describer = new ParameterDescriber;

        $description = $describer->describe('order', 'path', 'string');

        $this->assertNotNull($description);
        $this->assertStringNotContainsString('정렬 방향', $description);
        $this->assertStringNotContainsString('asc', $description);
        $this->assertStringContainsString('식별자', $description);
    }

    #[Test]
    public function path_의_sort_order_도_정렬방향으로_설명하지_않는다(): void
    {
        // path 에 sort_order 가 올 일은 없지만, 위치 우선 규칙이 이름보다 앞서야 한다.
        $describer = new ParameterDescriber;

        $description = $describer->describe('sort_order', 'path', 'string');

        $this->assertStringNotContainsString('정렬 방향', (string) $description);
    }

    #[Test]
    public function path_식별자_파라미터를_위치_기반으로_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertSame('대상 리소스의 식별자', $describer->describe('id', 'path', 'string'));
        $this->assertStringContainsString('slug', $describer->describe('slug', 'path', 'string'));
        $this->assertSame('대상 리소스의 식별자', $describer->describe('identifier', 'path', 'string'));
    }

    #[Test]
    public function path_카멜케이스_식별자를_패턴으로_설명한다(): void
    {
        $describer = new ParameterDescriber;

        // *Id → "대상 {base}의 식별자"
        $this->assertSame('대상 post의 식별자', $describer->describe('postId', 'path', 'string'));
        $this->assertSame('대상 product의 식별자', $describer->describe('productId', 'path', 'string'));
        // *Name → "대상 {base}의 이름 (식별자)"
        $this->assertStringContainsString('template', $describer->describe('templateName', 'path', 'string'));
        $this->assertStringContainsString('이름', $describer->describe('pluginName', 'path', 'string'));
    }

    #[Test]
    public function path_bare_리소스명은_route_model_binding_식별자로_설명한다(): void
    {
        $describer = new ParameterDescriber;

        // Laravel route-model binding 세그먼트(`/{definition}`, `/{menu}`, `/{role}`)는
        // 대상 모델의 단수형을 그대로 쓰므로 대상 리소스의 식별자로 서술한다.
        $this->assertSame('대상 definition의 식별자', $describer->describe('definition', 'path', 'string'));
        $this->assertSame('대상 menu의 식별자', $describer->describe('menu', 'path', 'string'));
        $this->assertSame('대상 role의 식별자', $describer->describe('role', 'path', 'string'));
        $this->assertSame('대상 schedule의 식별자', $describer->describe('schedule', 'path', 'string'));
        $this->assertSame('대상 challenge의 식별자', $describer->describe('challenge', 'path', 'string'));
        // camelCase route-model binding (activityLog, notificationLog)
        $this->assertSame('대상 activity log의 식별자', $describer->describe('activityLog', 'path', 'string'));
        // *Identifier 접미
        $this->assertSame('대상 template의 식별자', $describer->describe('templateIdentifier', 'path', 'string'));
        // key/version 은 리소스가 아니라 설정 키/버전 값
        $this->assertStringContainsString('키', $describer->describe('key', 'path', 'string'));
        $this->assertStringContainsString('버전', $describer->describe('version', 'path', 'string'));
        // bare path 리소스명은 자동 서술되므로 query/body 위치에서만 도메인 특이 판정이 유지된다
        $this->assertNull($describer->describe('definition', 'body', 'string'));
    }

    #[Test]
    public function 연관_식별자_snake_패턴을_설명한다(): void
    {
        $describer = new ParameterDescriber;

        // query/body 의 *_id 는 연관 리소스 식별자 참조
        $this->assertSame('user 식별자', $describer->describe('user_id', 'body', 'integer'));
        $this->assertSame('shipping policy 식별자', $describer->describe('shipping_policy_id', 'query', 'integer'));
        // *_ids 는 배열
        $this->assertStringContainsString('배열', $describer->describe('product_ids', 'body', 'array'));
    }

    #[Test]
    public function 불리언_날짜_접미_패턴을_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertSame('featured 여부', $describer->describe('is_featured', 'query', 'boolean'));
        $this->assertSame('paid 날짜', $describer->describe('paid_date', 'body', 'date'));
    }

    #[Test]
    public function 프로필_콘텐츠_공통_필드를_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertStringContainsString('이름', $describer->describe('name', 'body', 'string'));
        $this->assertStringContainsString('닉네임', $describer->describe('nickname', 'body', 'string'));
        $this->assertStringContainsString('설명', $describer->describe('description', 'body', 'string'));
        $this->assertStringContainsString('본문', $describer->describe('content', 'body', 'array'));
        $this->assertStringContainsString('제목', $describer->describe('subject', 'body', 'array'));
        $this->assertStringContainsString('전화', $describer->describe('phone', 'body', 'string'));
        $this->assertStringContainsString('휴대전화', $describer->describe('mobile', 'body', 'string'));
        $this->assertStringContainsString('자기소개', $describer->describe('bio', 'body', 'string'));
        $this->assertStringContainsString('경로', $describer->describe('path', 'query', 'string'));
        $this->assertStringContainsString('사용자명', $describer->describe('username', 'body', 'string'));
        // collection: 첨부 컬렉션 그룹명 (근거: UploadAttachmentRequest collection ?? 'default')
        $this->assertStringContainsString('첨부 컬렉션', $describer->describe('collection', 'body', 'string'));
    }

    #[Test]
    public function 확장_버전_공통_파라미터를_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertStringContainsString('확장 유형', $describer->describe('extension_type', 'body', 'string'));
        $this->assertStringContainsString('확장 식별자', $describer->describe('extension_identifier', 'body', 'string'));
        $this->assertStringContainsString('시작 버전', $describer->describe('from_version', 'query', 'string'));
        $this->assertStringContainsString('대상 버전', $describer->describe('to_version', 'query', 'string'));
        $this->assertStringContainsString('GitHub', $describer->describe('github_url', 'body', 'string'));
        $this->assertStringContainsString('체크섬', $describer->describe('checksum', 'body', 'string'));
        $this->assertStringContainsString('자동 활성화', $describer->describe('auto_activate', 'body', 'boolean'));
        // query/body identifier 는 확장/리소스 식별자
        $this->assertStringContainsString('식별자', $describer->describe('identifier', 'query', 'string'));
    }

    #[Test]
    public function 확장_이름_snake_패턴을_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertStringContainsString('이름', $describer->describe('template_name', 'body', 'string'));
        $this->assertStringContainsString('이름', $describer->describe('plugin_name', 'body', 'string'));
        $this->assertStringContainsString('이름', $describer->describe('module_name', 'body', 'string'));
        $this->assertStringContainsString('이름', $describer->describe('layout_name', 'query', 'string'));
    }

    #[Test]
    public function 스케줄_작업_공통_파라미터를_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertStringContainsString('아티즌 커맨드', $describer->describe('command', 'body', 'string'));
        $this->assertStringContainsString('주기', $describer->describe('frequency', 'body', 'string'));
        $this->assertStringContainsString('타임아웃', $describer->describe('timeout', 'body', 'integer'));
        $this->assertStringContainsString('점검 모드', $describer->describe('run_in_maintenance', 'body', 'boolean'));
        $this->assertStringContainsString('중복 실행', $describer->describe('without_overlapping', 'body', 'boolean'));
        $this->assertStringContainsString('잠금 버전', $describer->describe('expected_lock_version', 'body', 'integer'));
    }

    #[Test]
    public function 메일_드라이버_설정_파라미터를_설명한다(): void
    {
        $describer = new ParameterDescriber;

        $this->assertStringContainsString('메일 발송 드라이버', $describer->describe('mailer', 'body', 'string'));
        $this->assertStringContainsString('발신자 주소', $describer->describe('from_address', 'body', 'email'));
        $this->assertStringContainsString('호스트', $describer->describe('host', 'body', 'string'));
        $this->assertStringContainsString('포트', $describer->describe('port', 'body', 'integer'));
        $this->assertStringContainsString('스토리지 드라이버', $describer->describe('storage_driver', 'body', 'string'));
        $this->assertStringContainsString('S3 버킷', $describer->describe('s3_bucket', 'body', 'string'));
        $this->assertStringContainsString('Redis 호스트', $describer->describe('redis_host', 'body', 'string'));
        $this->assertStringContainsString('WebSocket', $describer->describe('websocket_scheme', 'body', 'string'));
    }

    #[Test]
    public function 도메인_특이_파라미터는_null_로_남긴다(): void
    {
        $describer = new ParameterDescriber;

        // 사전/패턴에 없는 도메인 특이 파라미터는 사람 서술(TODO)로 폴백
        $this->assertNull($describer->describe('refund_priority', 'body', 'string'));
        $this->assertNull($describer->describe('temp_key', 'body', 'string'));
        // 도메인마다 의미가 갈리는 파라미터는 계속 TODO 유지
        $this->assertNull($describer->describe('channels', 'body', 'array'));
        $this->assertNull($describer->describe('purpose', 'body', 'string'));
        $this->assertNull($describer->describe('scope_type', 'body', 'string'));
        $this->assertNull($describer->describe('conditions', 'body', 'array'));
        $this->assertNull($describer->describe('marketing_consent', 'body', 'boolean'));
        // source_type 은 Enum 기반으로 도메인마다 값 집합이 달라 TODO 유지
        $this->assertNull($describer->describe('source_type', 'body', 'string'));
        // body(생성/수정)의 status/type/category 는 여전히 TODO
        $this->assertNull($describer->describe('status', 'body', 'string'));
        $this->assertNull($describer->describe('type', 'body', 'string'));
    }

    #[Test]
    public function 공통_콘텐츠_주소_seo_파라미터를_설명한다(): void
    {
        $describer = new ParameterDescriber;

        // title: board 게시글/memo/inquiry/page 등 전 도메인에서 "제목"으로 고정
        // (근거: sirsoft-board/page/hello_module/ecommerce Store/Update Request)
        $this->assertStringContainsString('제목', $describer->describe('title', 'body', 'string'));
        // slug: URL 친화 식별자 (위치 무관 동일 의미)
        $this->assertStringContainsString('slug', $describer->describe('slug', 'body', 'string'));
        $this->assertStringContainsString('라벨', $describer->describe('label', 'body', 'string'));

        // 주소 확장 (국제 주소 표준 — 도메인 무관)
        $this->assertStringContainsString('주소', $describer->describe('address_line_1', 'body', 'string'));
        $this->assertStringContainsString('주소', $describer->describe('address_line_2', 'body', 'string'));
        $this->assertStringContainsString('도시', $describer->describe('intl_city', 'body', 'string'));
        $this->assertStringContainsString('지역', $describer->describe('region', 'query', 'string'));

        // SEO 메타 (검색엔진 노출용 — 도메인 무관)
        $this->assertStringContainsString('SEO', $describer->describe('meta_title', 'body', 'string'));
        $this->assertStringContainsString('SEO', $describer->describe('meta_description', 'body', 'string'));
        $this->assertStringContainsString('대체 텍스트', $describer->describe('alt_text', 'body', 'array'));
    }

    #[Test]
    public function 중첩_파라미터를_leaf_세그먼트로_설명한다(): void
    {
        $describer = new ParameterDescriber;

        // 중첩 환경설정 키(general.*, mail.*, upload.*)는 leaf 가 의미를 결정한다.
        // 부모 그룹명은 라벨일 뿐이므로 leaf 로 폴백해 공통 사전을 태운다.
        $this->assertStringContainsString('호스트', $describer->describe('mail.host', 'body', 'string'));
        $this->assertStringContainsString('포트', $describer->describe('mail.port', 'body', 'integer'));
        $this->assertStringContainsString('타임존', $describer->describe('general.timezone', 'body', 'string'));

        // 회귀: 전체명 접미 매칭이 부모 세그먼트를 설명문에 흘리던 문제
        // (general.site_name => "general.site 이름 (식별자)")
        $siteName = $describer->describe('general.site_name', 'body', 'string');
        $this->assertStringNotContainsString('general.', $siteName);
        $this->assertStringContainsString('이름', $siteName);
    }

    #[Test]
    public function 로케일_접미_파라미터를_부모_설명_기준으로_설명한다(): void
    {
        $describer = new ParameterDescriber;

        // 다국어 필드(name.ko / alt_text.en)는 부모 필드의 로케일별 값이다.
        $ko = $describer->describe('alt_text.ko', 'body', 'string');
        $this->assertStringContainsString('대체 텍스트', $ko);
        $this->assertStringContainsString('ko', $ko);

        // 부모가 도메인 특이(사전 미등재)면 로케일 접미도 TODO 로 남는다.
        $this->assertNull($describer->describe('refund_priority.ko', 'body', 'string'));
    }

    #[Test]
    public function 배열_원소_인덱스_파라미터는_상위_경로로_설명한다(): void
    {
        $describer = new ParameterDescriber;

        // items.*.name / ids.0 처럼 인덱스가 leaf 인 경우 상위 경로로 폴백한다.
        $this->assertStringContainsString('식별자 배열', $describer->describe('ids.*', 'body', 'array'));
        $this->assertStringContainsString('식별자 배열', $describer->describe('ids.0', 'body', 'integer'));
    }

    #[Test]
    public function seo_그룹의_중첩_키는_se_o_메타로_한정해_설명한다(): void
    {
        $describer = new ParameterDescriber;

        // 회귀: leaf 폴백만 태우면 seo_meta.title 이 "제목" 으로 축소돼 오설명이 된다.
        // 부모가 의미를 한정하는 그룹(seo/seo_meta)은 leaf 를 SEO 맥락으로 해석해야 한다.
        $this->assertStringContainsString('SEO', $describer->describe('seo_meta.title', 'body', 'string'));
        $this->assertStringContainsString('SEO', $describer->describe('seo_meta.description', 'body', 'string'));
        $this->assertStringContainsString('SEO', $describer->describe('seo_meta.keywords', 'body', 'string'));
        $this->assertStringContainsString('SEO', $describer->describe('content.meta.seo.title', 'body', 'string'));

        // 다른 그룹의 title 은 일반 제목 그대로 (오적용 방지)
        $this->assertStringNotContainsString('SEO', $describer->describe('basic_info.title', 'body', 'string'));
    }

    #[Test]
    public function 도메인_특이_중첩_파라미터는_null_로_남는다(): void
    {
        $describer = new ParameterDescriber;

        // leaf 폴백으로도 공통 사전에 없는 도메인 특이 키는 사람 서술(TODO) 대상이다.
        $this->assertNull($describer->describe('mileage.earn_trigger', 'body', 'string'));
        $this->assertNull($describer->describe('report_policy.auto_hide_target', 'body', 'string'));
    }
}
