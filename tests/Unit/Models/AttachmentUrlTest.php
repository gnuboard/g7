<?php

namespace Tests\Unit\Models;

use App\Models\Attachment;
use App\Support\AssetUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 코어 Attachment URL 조립 단일 지점(urlForHash/download_url) 회귀 테스트
 *
 * URL 중복 조립 정리(공개 #100) 후에도 응답 문자열이 기존과 동일함을 고정합니다.
 *
 * 왜 앱을 부팅하는가:
 *   이 클래스는 종전에 `PHPUnit\Framework\TestCase` 를 그대로 상속해 앱 없이 돌았다.
 *   그 뒤 `urlForHash()` 가 마운트 접두사를 얻으려 `AssetUrl::basePrefix()` 를 부르게
 *   되면서 `config` 바인딩이 없어 **워킹트리에 red 가 상주**했다 — 프로덕션은 정상이고
 *   테스트만 그 호출을 감당하지 못한 케이스다.
 *
 *   그리고 그 변경이 존재하는 **이유인 접두사 축을 재는 단언이 저장소에 0건**이었다.
 *   접두사를 다시 지워도 이 클래스는 초록이었다는 뜻이다. 아래 세 번째 테스트가 그
 *   축을 잠근다 — 하위 폴더 배치에서 루트 상대 경로는 **사이트 밖**을 가리키고,
 *   증상은 오류가 아니라 첨부·아바타가 전부 404 인 것뿐이다.
 */
class AttachmentUrlTest extends TestCase
{
    /**
     * 강제로 지정한 마운트 접두사를 풀어 다른 테스트에 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 루트 설치에서 해시로 만든 첨부 주소가 코어 첨부 API 경로 그대로인지 확인한다.
     *
     * @effects board_response_strings_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function url_for_hash_builds_core_attachment_url(): void
    {
        AssetUrl::forceBasePrefix('');

        $this->assertSame('/api/attachment/abc123def456', Attachment::urlForHash('abc123def456'));
    }

    /**
     * download_url 접근자가 urlForHash 와 같은 첨부 API 경로를 돌려주는지 확인한다.
     *
     * @effects board_response_strings_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function download_url_accessor_delegates_to_url_for_hash(): void
    {
        AssetUrl::forceBasePrefix('');

        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';

        $this->assertSame('/api/attachment/abc123def456', $attachment->download_url);
    }

    /**
     * 하위 폴더 배치에서는 마운트 접두사가 실린다.
     *
     * 형제 자산 통로(템플릿·모듈·플러그인·공개 스토리지)는 전부 `AssetUrl` 을 거쳐 이
     * 접두사를 얻는데, 첨부·아바타만 리터럴 조립이라 그 배치에서 전부 404 였다.
     * 접근자 경로도 함께 잰다 — 정적 메서드만 재면 접근자가 따로 조립하는 회귀를 놓친다.
     *
     * @effects attachment_url_carries_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function attachment_url_carries_the_mount_prefix_in_a_subfolder_install(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $this->assertSame(
            '/g7/api/attachment/abc123def456',
            Attachment::urlForHash('abc123def456'),
            '하위 폴더 배치에서 접두사가 빠지면 첨부 주소가 사이트 밖을 가리킨다'
        );

        $attachment = new Attachment;
        $attachment->hash = 'abc123def456';

        $this->assertSame(
            '/g7/api/attachment/abc123def456',
            $attachment->download_url,
            '접근자가 단일 지점을 경유하지 않으면 같은 첨부가 두 주소를 갖는다'
        );
    }
}
