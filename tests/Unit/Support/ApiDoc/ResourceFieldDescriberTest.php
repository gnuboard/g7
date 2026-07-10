<?php

namespace Tests\Unit\Support\ApiDoc;

use App\Support\ApiDoc\ResourceFieldDescriber;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 리소스 계약 필드 설명기 단위 테스트.
 *
 * 컬럼 주석이 없는 accessor/computed 필드(status_label, is_owner, *_at 등)의
 * 설명이 코드에서 확인된 의미대로 채워지는지 검증한다.
 */
class ResourceFieldDescriberTest extends TestCase
{
    #[Test]
    public function 표준_메타_필드를_정확_사전으로_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $this->assertStringContainsString('소유자', $describer->describe('is_owner', 'boolean'));
        $this->assertStringContainsString('작업 불리언 맵', $describer->describe('abilities', 'object'));
        $this->assertStringContainsString('Enum label()', $describer->describe('status_label', 'string'));
        $this->assertStringContainsString('관리자', $describer->describe('is_admin', 'boolean'));
    }

    #[Test]
    public function 타임스탬프_접미_패턴을_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $this->assertSame('생성 일시', $describer->describe('created_at', 'string'));
        $this->assertSame('최종 수정 일시', $describer->describe('updated_at', 'string'));
        // 사전에 없는 *_at 는 패턴으로 유추
        $this->assertSame('email verified 일시', $describer->describe('email_verified_at', 'string'));
    }

    #[Test]
    public function label_variant_접미_패턴을_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $desc = $describer->describe('priority_label', 'string');
        $this->assertStringContainsString('priority', $desc);
        $this->assertStringContainsString('라벨', $desc);

        $variant = $describer->describe('priority_variant', 'string');
        $this->assertStringContainsString('변형', $variant);
    }

    #[Test]
    public function can_접두는_능력_불리언으로_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $desc = $describer->describe('can_update', 'boolean');
        $this->assertStringContainsString('update', $desc);
        $this->assertStringContainsString('수행 가능', $desc);
    }

    #[Test]
    public function is_has_접두_불리언을_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $this->assertStringContainsString('여부', $describer->describe('is_default', 'boolean'));
        $this->assertStringContainsString('여부', $describer->describe('has_children', 'boolean'));
        // boolean 이 아니면 패턴 미적용 (오설명 방지)
        $this->assertNull($describer->describe('is_default', 'string'));
    }

    #[Test]
    public function count_접미_집계를_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $this->assertStringContainsString('개수', $describer->describe('user_count', 'integer'));
        // integer/number 가 아니면 미적용
        $this->assertNull($describer->describe('user_count', 'string'));
    }

    #[Test]
    public function 알_수_없는_필드는_null_을_반환한다(): void
    {
        $describer = new ResourceFieldDescriber;

        // IDV/marketing 도메인 특이 필드는 제외 (사전/패턴에 없어야 — TODO 로 폴백)
        $this->assertNull($describer->describe('purpose', 'string'));
        $this->assertNull($describer->describe('channels', 'array'));
        $this->assertNull($describer->describe('render_hint', 'string'));
        // type/code/category 는 값 집합이 도메인마다 달라 제외 (역할은 있으나 도메인 종속)
        $this->assertNull($describer->describe('code', 'string'));
        $this->assertNull($describer->describe('type', 'string'));
        $this->assertNull($describer->describe('category', 'string'));
    }

    #[Test]
    public function 쓰기_응답_공통_관계_실행이력_필드를_설명한다(): void
    {
        // 쓰기 메서드 실측 도입으로 응답에 자주 등장하는 공통 관계/실행이력/토큰 필드는
        // 도메인 무관하게 역할이 고정되므로 설명한다(TODO 방치 금지).
        $describer = new ResourceFieldDescriber;

        $this->assertStringContainsString('역할', $describer->describe('roles', 'array'));
        $this->assertStringContainsString('사용자', $describer->describe('user', 'object'));
        $this->assertStringContainsString('토큰', $describer->describe('token', 'string'));
        $this->assertStringContainsString('실행', $describer->describe('duration', 'integer'));
        $this->assertStringContainsString('종료 코드', $describer->describe('exit_code', 'integer'));
        // status 는 문자열/정수일 때 일반 설명(도메인 라벨은 status_label 참조)
        $this->assertStringContainsString('상태 값', $describer->describe('status', 'string'));
    }

    #[Test]
    public function 관계_연관_객체_필드를_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $this->assertStringContainsString('생성자', $describer->describe('creator', 'object'));
        $this->assertStringContainsString('하위', $describer->describe('children', 'array'));
        $this->assertStringContainsString('상위', $describer->describe('parent', 'object'));
        $this->assertStringContainsString('권한', $describer->describe('permissions', 'array'));
        $this->assertStringContainsString('수신자', $describer->describe('recipient', 'object'));
        $this->assertStringContainsString('발신자', $describer->describe('sender', 'object'));
        $this->assertStringContainsString('작성자', $describer->describe('author', 'object'));
        $this->assertStringContainsString('행위', $describer->describe('actor_name', 'string'));
    }

    #[Test]
    public function 공통_콘텐츠_표시_필드를_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        // 도메인 무관하게 역할이 고정된 공통 콘텐츠/표시 필드 (요청 파라미터 대응물)
        $this->assertStringContainsString('이름', $describer->describe('name', 'object'));
        $this->assertStringContainsString('제목', $describer->describe('title', 'string'));
        $this->assertStringContainsString('본문', $describer->describe('content', 'string'));
        $this->assertStringContainsString('설명', $describer->describe('description', 'object'));
        $this->assertStringContainsString('slug', $describer->describe('slug', 'string'));
        $this->assertStringContainsString('라벨', $describer->describe('label', 'string'));
        $this->assertStringContainsString('아이콘', $describer->describe('icon', 'string'));
        $this->assertStringContainsString('썸네일', $describer->describe('thumbnail', 'string'));
        $this->assertStringContainsString('IP', $describer->describe('ip_address', 'string'));
    }

    #[Test]
    public function sort_order_는_타입으로_분기한다(): void
    {
        $describer = new ResourceFieldDescriber;

        // 정수: 표시 정렬 순서 값
        $this->assertStringContainsString('정렬 순서', $describer->describe('sort_order', 'integer'));
        // 문자열: 정렬 방향일 수 있어 도메인 특이 → null (오설명 방지)
        $this->assertNull($describer->describe('sort_order', 'string'));
    }

    #[Test]
    public function 시스템_집계_필드를_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $this->assertStringContainsString('전체', $describer->describe('total', 'integer'));
        $this->assertStringContainsString('사용자', $describer->describe('total_users', 'object'));
        $this->assertStringContainsString('상대 시각', $describer->describe('time', 'string'));
        $this->assertStringContainsString('서버', $describer->describe('server_time', 'string'));
        $this->assertStringContainsString('순번', $describer->describe('number', 'integer'));
    }

    #[Test]
    public function 확장_버전_로케일_필드를_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $this->assertStringContainsString('타입', $describer->describe('extension_type', 'string'));
        $this->assertStringContainsString('식별자', $describer->describe('extension_identifier', 'string'));
        $this->assertStringContainsString('이름', $describer->describe('extension_name', 'string'));
        $this->assertStringContainsString('GitHub', $describer->describe('github_url', 'string'));
        $this->assertStringContainsString('변경 이력', $describer->describe('changelog', 'string'));
        $this->assertStringContainsString('코어', $describer->describe('current_core_version', 'string'));
        $this->assertStringContainsString('로케일', $describer->describe('locales', 'array'));
        $this->assertStringContainsString('표시명', $describer->describe('locale_names', 'object'));
        $this->assertStringContainsString('차단', $describer->describe('install_blocked_reason', 'string'));
    }

    #[Test]
    public function raw_접미_패턴은_원본_값으로_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        $nameRaw = $describer->describe('name_raw', 'string');
        $this->assertStringContainsString('name', $nameRaw);
        $this->assertStringContainsString('원본', $nameRaw);

        $descRaw = $describer->describe('description_raw', 'string');
        $this->assertStringContainsString('description', $descRaw);
        $this->assertStringContainsString('원본', $descRaw);
    }

    #[Test]
    public function formatted_접미는_표시용_포맷_문자열로_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        // 근거: size_formatted accessor, formatCurrencyPrice/formatFileSize/
        //       formatCreatedAtFormat — 통화·용량·일시 포맷 파생
        $created = $describer->describe('created_at_formatted', 'string');
        $this->assertStringContainsString('created_at', $created);
        $this->assertStringContainsString('포맷', $created);

        $price = $describer->describe('selling_price_formatted', 'string');
        $this->assertStringContainsString('selling_price', $price);
        $this->assertStringContainsString('포맷', $price);
    }

    #[Test]
    public function localized_접두_접미는_로케일_해석_값으로_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        // 근거: getLocalizedName() / getLocalizedOptionName()
        $localizedName = $describer->describe('localized_name', 'string');
        $this->assertStringContainsString('name', $localizedName);
        $this->assertStringContainsString('로케일', $localizedName);

        $optionLocalized = $describer->describe('option_name_localized', 'string');
        $this->assertStringContainsString('option name', $optionLocalized);
        $this->assertStringContainsString('로케일', $optionLocalized);
    }

    #[Test]
    public function url_접미는_리소스_url로_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        // 근거: thumbnail_url => download_url / getThumbnailUrl()
        $thumb = $describer->describe('thumbnail_url', 'string');
        $this->assertStringContainsString('thumbnail', $thumb);
        $this->assertStringContainsString('URL', $thumb);
    }

    #[Test]
    public function id_접미는_참조_식별자로_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        // 응답의 *_id 는 예외 없이 연관 리소스 참조 식별자다 (parent_id/user_id 등)
        $parent = $describer->describe('parent_id', 'integer');
        $this->assertStringContainsString('parent', $parent);
        $this->assertStringContainsString('식별자', $parent);

        $ids = $describer->describe('permission_ids', 'array');
        $this->assertStringContainsString('permission', $ids);
        $this->assertStringContainsString('배열', $ids);

        // 예외: login_id 는 참조 식별자가 아니라 로그인 계정 아이디(문자열) → TODO 유지
        $this->assertNull($describer->describe('user_login_id', 'string'));
    }

    #[Test]
    public function depth_는_계층_트리_깊이로_설명한다(): void
    {
        $describer = new ResourceFieldDescriber;

        // 근거: CommentResource/CategoryResource 의 depth (children/parent 트리)
        $depth = $describer->describe('depth', 'integer');
        $this->assertStringContainsString('깊이', $depth);
        // 정수가 아니면 미적용 (오설명 방지)
        $this->assertNull($describer->describe('depth', 'string'));
    }
}
