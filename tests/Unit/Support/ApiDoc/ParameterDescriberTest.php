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
}
