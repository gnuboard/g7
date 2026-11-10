<?php

namespace Tests\Unit\Support\ContentMedia;

use App\Extension\Storage\ModuleStorageDriver;
use App\Support\ContentMedia\ContentMediaCloner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 본문 미디어 소유 복제 도구 — 복제 후보 고르기·주소 해시 바꾸기·롤백되는 파일 복사 (#887)
 *
 * 다른 문서 소유 본문 미디어 주소를 쓴 문서를 저장하면 확장이 이 도구로 파일을 복사해 새 해시로 바꾼다. 같은 사이트 상대 정식
 * 주소가 미디어 태그 속성 값 전체인 곳만 대상이고, 다른 출처 주소·이미 아는 해시는 건드리지 않는다.
 *
 * @scenario-manifest tests/scenarios/content-media-clone.yaml
 */
class ContentMediaClonerTest extends TestCase
{
    /** 시험용 서빙 정규식 */
    private const PATTERN = '/api/modules/x/content-media/(?<hash>[A-Za-z0-9]{12})';

    /** 시험용 서빙 주소 앞부분 */
    private const PREFIX = '/api/modules/x/content-media/';

    /**
     * 복제 후보는 상대 정식 주소로 미디어 태그에 쓰인 해시 중 이미 아는 것을 뺀 것이다 — 로케일을 합치고 중복을 없앤다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=foreign_hashes
     *
     * @effects only_canonical_media_refs, known_hashes_excluded, locales_merged_deduplicated
     */
    public function test_foreign_hashes_pick_canonical_unknown_references(): void
    {
        $html = '<p><img src="'.self::PREFIX.'AAAAAAAAAAAA"></p>'
            .'<p><img src="'.self::PREFIX.'BBBBBBBBBBBB"></p>'
            .'<p><img src="https://other.example'.self::PREFIX.'CCCCCCCCCCCC"></p>'
            .'<p><a href="'.self::PREFIX.'DDDDDDDDDDDD">링크</a></p>'
            .'<video src="'.self::PREFIX.'EEEEEEEEEEEE"></video>';

        $this->assertSame(
            ['BBBBBBBBBBBB', 'EEEEEEEEEEEE', 'FFFFFFFFFFFF'],
            ContentMediaCloner::foreignHashes(
                ['ko' => $html, 'en' => '<img src="'.self::PREFIX.'BBBBBBBBBBBB"><img src="'.self::PREFIX.'FFFFFFFFFFFF">', 'x' => null],
                self::PATTERN,
                ['AAAAAAAAAAAA']
            ),
            '아는 해시(A)·다른 출처(C)·미디어 태그가 아닌 링크(D)는 빼고, 같은 해시(B)는 한 번만'
        );
    }

    /**
     * 해시 바꾸기는 같은 해시를 모두 바꾸고 맵에 없는 해시·다른 출처 주소는 그대로 둔다. 로케일 배열도 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=replace_hashes
     *
     * @effects all_occurrences_replaced, others_untouched
     */
    public function test_replace_hashes_rewrites_every_canonical_occurrence(): void
    {
        $html = '<img src="'.self::PREFIX.'AAAAAAAAAAAA"><img src=\''.self::PREFIX.'AAAAAAAAAAAA\'>'
            .'<img src="'.self::PREFIX.'BBBBBBBBBBBB"><img src="https://other.example'.self::PREFIX.'AAAAAAAAAAAA">';

        $out = ContentMediaCloner::replaceHashes(['ko' => $html, 'en' => null], self::PATTERN, ['AAAAAAAAAAAA' => 'ZZZZZZZZZZZZ']);

        $this->assertSame(
            '<img src="'.self::PREFIX.'ZZZZZZZZZZZZ"><img src=\''.self::PREFIX.'ZZZZZZZZZZZZ\'>'
            .'<img src="'.self::PREFIX.'BBBBBBBBBBBB"><img src="https://other.example'.self::PREFIX.'AAAAAAAAAAAA">',
            $out['ko']
        );
        $this->assertNull($out['en']);
        $this->assertSame($html, ContentMediaCloner::replaceHashes($html, self::PATTERN, []));
    }

    /**
     * 정화기가 src 파일 이름으로 채운 alt(값 전체가 옛 해시)는 주소와 함께 새 해시로 바뀌고, 사람이 쓴 alt 문구는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=replace_hashes
     *
     * @effects derived_alt_replaced, authored_alt_untouched
     */
    public function test_replace_hashes_rewrites_alt_derived_from_hash_only(): void
    {
        $html = '<img src="'.self::PREFIX.'AAAAAAAAAAAA" alt="AAAAAAAAAAAA">'
            .'<img src="'.self::PREFIX.'AAAAAAAAAAAA" alt="AAAAAAAAAAAA 사진">'
            .'<img src="'.self::PREFIX.'BBBBBBBBBBBB" alt=\'BBBBBBBBBBBB\'>';

        $out = ContentMediaCloner::replaceHashes($html, self::PATTERN, ['AAAAAAAAAAAA' => 'ZZZZZZZZZZZZ']);

        $this->assertSame(
            '<img src="'.self::PREFIX.'ZZZZZZZZZZZZ" alt="ZZZZZZZZZZZZ">'
            .'<img src="'.self::PREFIX.'ZZZZZZZZZZZZ" alt="AAAAAAAAAAAA 사진">'
            .'<img src="'.self::PREFIX.'BBBBBBBBBBBB" alt=\'BBBBBBBBBBBB\'>',
            $out
        );
    }

    /**
     * 파일 복사는 트랜잭션이 되돌아가면 사본을 지우고, 커밋되면 원본·사본이 함께 남는다. 원본이 없으면 false.
     *
     * @return void 반환값 없음
     *
     * @scenario case=copy_file
     *
     * @effects copy_compensated_on_rollback, copy_kept_on_commit, missing_source_false
     */
    public function test_copy_file_is_compensated_on_rollback(): void
    {
        Storage::fake('local');
        $storage = new ModuleStorageDriver('cm-clone-test');
        $storage->put('files', 'a/src.png', 'PNG');

        DB::beginTransaction();
        $this->assertTrue(ContentMediaCloner::copyFile($storage, 'files', 'a/src.png', $storage, 'files', 'b/rolled.png'));
        $this->assertTrue($storage->exists('files', 'b/rolled.png'));
        DB::rollBack();
        $this->assertFalse($storage->exists('files', 'b/rolled.png'), '롤백되면 사본을 지운다');
        $this->assertTrue($storage->exists('files', 'a/src.png'), '원본은 그대로');

        DB::beginTransaction();
        $this->assertTrue(ContentMediaCloner::copyFile($storage, 'files', 'a/src.png', $storage, 'files', 'b/kept.png'));
        DB::commit();
        $this->assertSame('PNG', $storage->get('files', 'b/kept.png'));
        $this->assertSame('PNG', $storage->get('files', 'a/src.png'));

        $this->assertFalse(ContentMediaCloner::copyFile($storage, 'files', 'a/none.png', $storage, 'files', 'b/none.png'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', ContentMediaCloner::clonedFilename('x/Y.PNG'));
    }
}
