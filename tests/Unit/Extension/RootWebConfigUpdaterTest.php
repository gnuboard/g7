<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\AbstractRootMarkerFileUpdater;
use App\Extension\Helpers\RootHtaccessUpdater;
use App\Extension\Helpers\RootWebConfigUpdater;

/**
 * 루트 `web.config` 마커 블록 갱신기 단위 테스트 (G3 회차 16 W2 C-1).
 *
 * 케이스 표는 형제(`RootHtaccessUpdaterTest`)의 것을 **그대로 상속**한다 — 판정 로직은
 * 공통 추상화가 소유하므로, 마커 문법만 다른 이 갱신기가 형제와 다른 판정을 내면 그것이
 * 곧 결함이다. 아래에는 XML 이라서만 성립하는 축을 더한다.
 *
 * 종전에는 이 파일이 「코어 소유, 통째 교체」였다 — 운영자가 IIS 규칙을 덧붙이면 다음
 * 업데이트가 그것을 말없이 지웠고(오류 없음), 형제 `.htaccess` 와 같은 사이트에서 두
 * 파일이 정반대로 동작했다.
 */
class RootWebConfigUpdaterTest extends RootHtaccessUpdaterTest
{
    protected const UPDATER = RootWebConfigUpdater::class;

    /**
     * 마커는 **XML 주석 한 줄**이고, 형제와 같은 추상화를 상속한다.
     *
     * @scenario marker_syntax=xml_comment, destination_state=with_block
     *
     * @effects root_web_config_updates_replace_only_core_block
     *
     * @return void 반환값 없음
     */
    public function test_markers_are_xml_comments_and_the_updater_shares_the_htaccess_abstraction(): void
    {
        $this->assertTrue(is_subclass_of(RootWebConfigUpdater::class, AbstractRootMarkerFileUpdater::class));
        $this->assertTrue(is_subclass_of(RootHtaccessUpdater::class, AbstractRootMarkerFileUpdater::class));

        $this->assertMatchesRegularExpression('/^<!--\s+BEGIN G7\s+-->$/', RootWebConfigUpdater::BEGIN_MARKER);
        $this->assertMatchesRegularExpression('/^<!--\s+END G7\s+-->$/', RootWebConfigUpdater::END_MARKER);
        $this->assertNotSame(RootHtaccessUpdater::BEGIN_MARKER, RootWebConfigUpdater::BEGIN_MARKER, '두 파일의 마커가 같으면 XML 이 `#` 줄을 담게 된다');

        // 임시 파일 이름 규칙·결과 코드는 추상화의 것이다 — 정리 주체가 한 상수를 본다.
        $this->assertSame(RootHtaccessUpdater::TEMP_INFIX, RootWebConfigUpdater::TEMP_INFIX);
        $this->assertSame(RootHtaccessUpdater::TEMP_SUFFIX_PATTERN, RootWebConfigUpdater::TEMP_SUFFIX_PATTERN);
        $this->assertSame(RootHtaccessUpdater::failureResults(), RootWebConfigUpdater::failureResults());
    }

    /**
     * 블록 밖에 운영자가 둔 XML 요소는 교체 뒤에도 남고, 결과는 여전히 **유효한 XML** 이다.
     *
     * 형제 `.htaccess` 는 줄 단위라 어디에 끼워 넣어도 파일이 깨지지 않지만, XML 은 블록이
     * `<configuration>` 안의 형제 요소 자리에 있어야 한다 — 그래서 릴리즈 판본이 블록을 그
     * 자리에 두고, 이 검사가 교체 결과를 파싱해 잠근다.
     *
     * @scenario marker_syntax=xml_comment, htaccess_operator_state=modified_outside_block
     *
     * @effects root_web_config_updates_replace_only_core_block
     *
     * @return void 반환값 없음
     */
    public function test_operator_elements_outside_the_block_survive_and_the_result_stays_valid_xml(): void
    {
        $src = $this->workDir.'/src.web.config';
        $dest = $this->workDir.'/dest.web.config';

        file_put_contents($src, $this->xmlFile('<rule name="G7 v2" />'));
        file_put_contents($dest, $this->xmlFile('<rule name="G7 v1" />', '  <system.web><customErrors mode="Off" /></system.web>'."\n"));

        $this->assertSame(RootWebConfigUpdater::RESULT_UPDATED, RootWebConfigUpdater::apply($src, $dest));

        $result = (string) file_get_contents($dest);
        $this->assertStringContainsString('G7 v2', $result, '블록 안은 새 판본이어야 한다');
        $this->assertStringNotContainsString('G7 v1', $result);
        $this->assertStringContainsString('<customErrors mode="Off" />', $result, '블록 밖의 운영자 요소가 사라졌다 — 종전 「통째 교체」가 그대로다');

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($result);
        libxml_use_internal_errors($previous);
        $this->assertNotFalse($xml, '교체 결과가 XML 로 파싱되지 않는다 — IIS 는 그 상태에서 사이트 전체를 500 으로 만든다');
        $this->assertCount(1, $xml->xpath('//system.webServer'));
        $this->assertCount(1, $xml->xpath('//system.web'));
    }

    /**
     * IIS 재직렬화로 **들여쓴** 마커도 찾아 교체한다.
     *
     * IIS 관리 도구는 web.config 를 저장할 때 XML 트리를 재직렬화하며 자식 요소를 들여쓴다 —
     * `  <!-- BEGIN G7 -->` 가 되어 컬럼 0 판정으로는 마커를 영영 찾지 못하고, 갱신이
     * `preserved`(운영자가 지웠다)로 오분류된다. XML 주석은 중첩할 수 없어 선행 공백을 허용해도
     * 블록 경계가 모호해지지 않는다. 형제 `.htaccess` 는 이 판정을 넓히지 않는다(컬럼 0 불변).
     *
     * @scenario marker_syntax=xml_comment, destination_state=with_block
     *
     * @effects root_web_config_updates_replace_only_core_block
     *
     * @return void 반환값 없음
     */
    public function test_indented_markers_are_recognized_after_iis_reserialization(): void
    {
        $src = $this->workDir.'/src.web.config';
        $dest = $this->workDir.'/dest.web.config';

        file_put_contents($src, $this->xmlFile('<rule name="G7 v2" />'));

        $indented = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<configuration>\n"
            .'  '.RootWebConfigUpdater::BEGIN_MARKER."\n"
            ."  <system.webServer><rewrite><rules>\n"
            ."    <rule name=\"G7 v1\" />\n"
            ."  </rules></rewrite></system.webServer>\n"
            .'  '.RootWebConfigUpdater::END_MARKER."\n"
            ."</configuration>\n";
        file_put_contents($dest, $indented);

        $this->assertSame(
            RootWebConfigUpdater::RESULT_UPDATED,
            RootWebConfigUpdater::apply($src, $dest),
            '들여쓴 마커를 찾지 못하면 preserved 로 오분류되어 보호 규칙이 영영 갱신되지 않는다'
        );

        $result = (string) file_get_contents($dest);
        $this->assertStringContainsString('G7 v2', $result);
        $this->assertStringNotContainsString('G7 v1', $result);

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($result);
        libxml_use_internal_errors($previous);
        $this->assertNotFalse($xml, '교체 결과가 적형이어야 한다');

        // 형제 .htaccess 는 들여쓴 마커를 마커로 보지 않는다 — 컬럼 0 컨벤션 불변(자식별 분기).
        $hsrc = $this->workDir.'/src.htaccess';
        $hdest = $this->workDir.'/dest.htaccess';
        file_put_contents($hsrc, RootHtaccessUpdater::BEGIN_MARKER."\n# core\n".RootHtaccessUpdater::END_MARKER."\n");
        file_put_contents($hdest, '  '.RootHtaccessUpdater::BEGIN_MARKER."\n# old\n  ".RootHtaccessUpdater::END_MARKER."\n");
        $this->assertSame(
            RootHtaccessUpdater::RESULT_PRESERVED,
            RootHtaccessUpdater::apply($hsrc, $hdest),
            '.htaccess 는 들여쓴 마커를 컬럼 0 컨벤션에 따라 마커로 보지 않아야 한다'
        );
    }

    /**
     * 블록 밖 내용이 닫히지 않아 교체 결과가 적형 XML 이 아니면 **쓰기 전에** 거부한다.
     *
     * IIS 는 web.config 파싱에 실패하면 사이트 전체를 500 으로 만든다 — 손상본을 디스크에
     * 확정하기 전에 걸러야 한다. 형제 `.htaccess` 는 줄 단위라 이 판정이 없다.
     *
     * @scenario marker_syntax=xml_comment, htaccess_operator_state=modified_outside_block
     *
     * @effects root_web_config_updates_replace_only_core_block
     *
     * @return void 반환값 없음
     */
    public function test_splice_that_produces_invalid_xml_is_refused_before_writing(): void
    {
        $src = $this->workDir.'/src.web.config';
        $dest = $this->workDir.'/dest.web.config';

        file_put_contents($src, $this->xmlFile('<rule name="G7 v2" />'));
        // 블록 **밖**에 닫히지 않은 태그를 둔다 — 교체 결과가 파싱 불가가 된다.
        $broken = $this->xmlFile('<rule name="G7 v1" />', "  <system.web><customErrors\n");
        file_put_contents($dest, $broken);

        $result = RootWebConfigUpdater::apply($src, $dest);

        $this->assertSame(RootWebConfigUpdater::RESULT_SPLICE_INVALID, $result, '적형이 아닌 교체 결과를 쓰면 IIS 가 사이트 전체를 500 으로 만든다');
        $this->assertTrue(RootWebConfigUpdater::isFailure($result), '적형 실패는 사람의 조치가 필요한 실패다');
        $this->assertSame($broken, (string) file_get_contents($dest), '적형이 아니면 쓰기 전에 거부해 손상본을 디스크에 확정하지 않는다');

        $message = RootWebConfigUpdater::failureMessage($result, $dest);
        $this->assertNotNull($message);
        $this->assertStringContainsString($dest, (string) $message);
    }

    /**
     * `<configuration>` 안에 코어 블록을 가진 최소 web.config 를 만듭니다.
     *
     * @param  string  $ruleLine  블록 안 규칙 줄
     * @param  string  $operatorTail  블록 뒤(운영자 영역)에 둘 XML
     * @return string 파일 전문
     */
    private function xmlFile(string $ruleLine, string $operatorTail = ''): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<configuration>\n"
            .RootWebConfigUpdater::BEGIN_MARKER."\n"
            ."  <system.webServer><rewrite><rules>\n"
            .'    '.$ruleLine."\n"
            ."  </rules></rewrite></system.webServer>\n"
            .RootWebConfigUpdater::END_MARKER."\n"
            .$operatorTail
            ."</configuration>\n";
    }
}
