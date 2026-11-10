<?php

namespace Tests\Unit\Support\ContentMedia;

use App\Enums\ContentMediaKind;
use App\Rules\ContentMediaFile;
use App\Support\ContentMedia\ContentMediaPolicy;
use App\Support\ContentMedia\ContentMediaPolicyRules;
use App\Support\ContentMedia\ContentMediaReferenceExtractor;
use App\Support\ContentMedia\ContentMediaUploadTarget;
use App\Support\ContentMedia\ContentMediaUrlRewriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 본문 미디어 코어 계약 — 정책·검증 규칙·참조 추출·주소 재작성·업로드 계약 (#883 4단계)
 *
 * @scenario-manifest tests/scenarios/content-media-contract.yaml
 */
class ContentMediaContractTest extends TestCase
{
    /** 시험용 서빙 정규식 */
    private const PATTERN = '/api/modules/x/content-media/(?<hash>[A-Za-z0-9]{12})';

    /**
     * 대체 정책은 세 종류를 모두 열어 두고 동영상·오디오는 코어 「본문 콘텐츠」 스위치가 정하게 한다.
     *
     * 확장 규칙이 비어 있는 곳(페이지 기본, 게시판·쇼핑몰 「첨부 정책을 따름」)에서 코어가 업로드 동영상을 켰는데도 이미지만
     * 올라가던 동작을 바꾼 것이다. 확장자는 첨부 허용 확장자와 종류 후보의 교집합이고, 비면 그 종류 후보 전부다.
     * 파일당 크기는 첨부 파일당 크기다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=policy_fallback
     *
     * @effects fallback_follows_core_switch, extensions_narrowed_to_candidates
     */
    public function test_fallback_policy_follows_core_switch_for_video_and_audio(): void
    {
        $policy = ContentMediaPolicy::fromAttachmentSettings(5, ['pdf', 'PNG', 'mp4', 'zip']);

        $this->assertTrue($policy->allows(ContentMediaKind::Image));
        $this->assertSame(['png'], $policy->extensions(ContentMediaKind::Image));
        $this->assertSame(['mp4'], $policy->extensions(ContentMediaKind::Video), '첨부가 받는 동영상 형식으로 좁힌다');
        $this->assertSame(ContentMediaKind::Audio->candidateExtensions(), $policy->extensions(ContentMediaKind::Audio), '첨부에 오디오 형식이 없으면 후보 전부');
        $this->assertSame(5, $policy->maxSizeMb(ContentMediaKind::Video));
        $this->assertSame(30, $policy->maxPerDocument());

        config(['g7_settings.core.content.allow_uploaded_video' => false, 'g7_settings.core.content.allow_uploaded_audio' => false]);
        $this->assertFalse($policy->acceptsUpload(ContentMediaKind::Video), '코어가 끄면 받지 않는다');
        $this->assertFalse($policy->toClientArray()['audio']['enabled']);

        config(['g7_settings.core.content.allow_uploaded_video' => true, 'g7_settings.core.content.allow_uploaded_audio' => true]);
        $this->assertTrue($policy->acceptsUpload(ContentMediaKind::Video), '코어가 켜면 규칙이 없는 곳도 받는다');
        $this->assertTrue($policy->acceptsUpload(ContentMediaKind::Audio));
        $this->assertSame(['video/mp4'], $policy->toClientArray()['video']['mimeTypes']);

        $explicitOff = ContentMediaPolicy::fromArray(['video' => ['enabled' => false]], $policy);
        $this->assertFalse($explicitOff->acceptsUpload(ContentMediaKind::Video), '직접 정한 규칙이 끈 것은 코어가 켜도 끈다');

        $docsOnly = ContentMediaPolicy::fromAttachmentSettings(5, ['pdf']);
        $this->assertSame(ContentMediaKind::Image->candidateExtensions(), $docsOnly->extensions(ContentMediaKind::Image));
        $this->assertSame(ContentMediaKind::Video->candidateExtensions(), $docsOnly->extensions(ContentMediaKind::Video));
    }

    /**
     * 저장값은 대체 정책 위에 덮이고, 후보 밖 확장자는 버린다. 범위 밖 숫자는 잘라 맞춘다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=policy_from_array
     *
     * @effects stored_overrides_fallback, values_clamped
     */
    public function test_from_array_overrides_and_clamps(): void
    {
        $fallback = ContentMediaPolicy::fromAttachmentSettings(10, ['jpg']);
        $policy = ContentMediaPolicy::fromArray([
            'video' => ['enabled' => true, 'max_size_mb' => 99999, 'extensions' => ['mp4', 'exe']],
            'max_per_document' => 0,
            'use_custom' => true,
        ], $fallback);

        $this->assertTrue($policy->allows(ContentMediaKind::Video));
        $this->assertSame(['mp4'], $policy->extensions(ContentMediaKind::Video));
        $this->assertSame(ContentMediaPolicy::MAX_SIZE_MB_LIMIT, $policy->maxSizeMb(ContentMediaKind::Video));
        $this->assertSame(1, $policy->maxPerDocument());
        $this->assertSame(['jpg'], $policy->extensions(ContentMediaKind::Image), '안 준 종류는 대체 정책 값');
        $this->assertSame($fallback, ContentMediaPolicy::fromArray(null, $fallback));
    }

    /**
     * 코어 「본문 콘텐츠」 후보가 꺼져 있으면 정책이 켜도 업로드를 받지 않고 화면에도 꺼서 내린다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=core_gate
     *
     * @effects core_setting_gates_video
     */
    public function test_core_setting_gates_video_upload(): void
    {
        $policy = ContentMediaPolicy::fromArray(['video' => ['enabled' => true, 'extensions' => ['mp4']]], ContentMediaPolicy::fromAttachmentSettings(5, []));

        config(['g7_settings.core.content.allow_uploaded_video' => false]);
        $this->assertFalse($policy->acceptsUpload(ContentMediaKind::Video));
        $this->assertFalse($policy->toClientArray()['video']['enabled']);

        config(['g7_settings.core.content.allow_uploaded_video' => true]);
        $this->assertTrue($policy->acceptsUpload(ContentMediaKind::Video));
        $this->assertSame(['video/mp4'], $policy->toClientArray()['video']['mimeTypes']);
    }

    /**
     * 파일 규칙은 내용으로 판정한다 — 이름만 png 인 HTML 은 거부, 진짜 PNG 는 통과, 크기 초과는 거부.
     *
     * @return void 반환값 없음
     *
     * @scenario case=file_rule
     *
     * @effects content_sniffed_not_name, size_enforced
     */
    public function test_file_rule_sniffs_content_and_size(): void
    {
        $policy = ContentMediaPolicy::fromArray(['image' => ['enabled' => true, 'max_size_mb' => 1, 'extensions' => ['png']]], ContentMediaPolicy::fromAttachmentSettings(1, []));
        $validate = fn (UploadedFile $file) => Validator::make(['file' => $file], ['file' => [new ContentMediaFile($policy)]])->passes();

        $this->assertTrue($validate(UploadedFile::fake()->image('ok.png')));
        $this->assertFalse($validate(UploadedFile::fake()->createWithContent('evil.png', '<html><script>x</script></html>')));
        $this->assertFalse($validate(UploadedFile::fake()->image('big.png')->size(1500)));
        $this->assertFalse($validate(UploadedFile::fake()->image('photo.jpg')), 'jpg 는 정책이 허용하지 않았다');
    }

    /**
     * 참조 추출은 img·video·audio·source 의 src 와 video poster 에서 서빙 주소에 맞는 해시만 순서대로 중복 없이 뽑는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=extractor
     *
     * @effects hashes_extracted_in_order
     */
    public function test_reference_extractor(): void
    {
        $html = '<p><img src="/api/modules/x/content-media/AAAAAAAAAAAA?expires=1&signature=z"></p>'
            .'<video poster="/api/modules/x/content-media/BBBBBBBBBBBB"><source src="/api/modules/x/content-media/CCCCCCCCCCCC"></video>'
            .'<img src="/api/modules/y/content-media/DDDDDDDDDDDD"><img src="/api/modules/x/content-media/AAAAAAAAAAAA">';

        $this->assertSame(['AAAAAAAAAAAA', 'BBBBBBBBBBBB', 'CCCCCCCCCCCC'], ContentMediaReferenceExtractor::hashes($html, self::PATTERN));
        $this->assertSame([], ContentMediaReferenceExtractor::hashes(null, self::PATTERN));
    }

    /**
     * 정규화는 서명 쿼리와 같은 사이트 출처를 떼고, 서명은 정식 주소만 바꾼다(다른 출처·다른 경로는 그대로).
     *
     * @return void 반환값 없음
     *
     * @scenario case=rewriter
     *
     * @effects normalize_strips_signature, sign_only_canonical
     */
    public function test_url_rewriter_normalize_and_sign(): void
    {
        config(['app.url' => 'https://site.test']);
        $html = '<img src="https://site.test/api/modules/x/content-media/AAAAAAAAAAAA?expires=1&amp;signature=z">'
            .'<img src="https://evil.test/api/modules/x/content-media/BBBBBBBBBBBB?x=1">'
            .'<a href="/api/modules/x/content-media/CCCCCCCCCCCC">link</a>';

        $normalized = ContentMediaUrlRewriter::normalize($html, self::PATTERN);
        $this->assertStringContainsString('src="/api/modules/x/content-media/AAAAAAAAAAAA"', $normalized);
        $this->assertStringContainsString('src="https://evil.test/api/modules/x/content-media/BBBBBBBBBBBB"', $normalized, '다른 출처는 정식 주소로 바꾸지 않는다');

        $signed = ContentMediaUrlRewriter::sign('<img src="/api/modules/x/content-media/AAAAAAAAAAAA">', self::PATTERN, fn (string $hash) => "/signed/{$hash}");
        $this->assertSame('<img src="/signed/AAAAAAAAAAAA">', $signed);
        $this->assertSame(240, ContentMediaUrlRewriter::ttlMinutes());
    }

    /**
     * 설정 정리 — 「직접 정하기」가 꺼지면 null, 켜지면 표지를 남기고 스위치 문자열·쉼표 확장자를 정리한다. 규칙은 후보 밖 확장자를 거부.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings_rules
     *
     * @effects use_custom_off_clears, settings_validation_rejects_non_candidate
     */
    public function test_policy_rules_normalize_and_validate(): void
    {
        $this->assertNull(ContentMediaPolicyRules::normalizeInput(['use_custom' => 'off', 'image' => ['enabled' => true]]));
        $this->assertSame(
            ['use_custom' => true, 'image' => ['enabled' => true, 'extensions' => ['png', 'jpg']]],
            ContentMediaPolicyRules::normalizeInput(['use_custom' => 'on', 'image' => ['enabled' => 'on', 'extensions' => '.PNG, jpg']])
        );

        $rules = ContentMediaPolicyRules::settingsRules('p');
        $this->assertFalse(Validator::make(['p' => ['video' => ['extensions' => ['exe']]]], $rules)->passes());
        $this->assertTrue(Validator::make(['p' => ['video' => ['enabled' => true, 'extensions' => ['mp4'], 'max_size_mb' => 10]]], $rules)->passes());
    }

    /**
     * 업로드 계약은 버전·응답 경로·허용 헤더만·JS 정규식(명명 그룹)으로 만든다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upload_target
     *
     * @effects target_shape_and_header_allowlist
     */
    public function test_upload_target_shape(): void
    {
        $target = ContentMediaUploadTarget::toArray('/api/x', ContentMediaPolicy::fromAttachmentSettings(5, ['png']), '/x/(?P<hash>[a-z]{12})', ['post_id' => 3], ['X-Board-Post-Verify-Token' => 't', 'Authorization' => 'no']);

        $this->assertSame(1, $target['version']);
        $this->assertSame('data.url', $target['response']['urlPath']);
        $this->assertSame('/x/(?<hash>[a-z]{12})', $target['mediaUrlPattern']);
        $this->assertSame(['X-Board-Post-Verify-Token' => 't'], (array) $target['headers']);
        $this->assertSame(['post_id' => 3], (array) $target['fields']);
    }
}
