<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutProvenance;
use PHPUnit\Framework\TestCase;

/**
 * LayoutProvenance (산출 JSON `meta.authoring` 표식) 단위 테스트 (S7).
 */
class LayoutProvenanceTest extends TestCase
{
    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects stamp_records_source_hash_and_matches_the_same_source
     */
    public function test_stamp_then_matches_the_same_source(): void
    {
        $source = "<layout name=\"a\"/>\n";
        $stamped = LayoutProvenance::stamp(['layout_name' => 'a'], $source, 'x/a.g7.html');

        $this->assertSame('x/a.g7.html', $stamped['meta']['authoring']['source']);
        $this->assertSame(LayoutProvenance::COMPILER_VERSION, $stamped['meta']['authoring']['compiler_version']);
        $this->assertTrue(LayoutProvenance::matches($stamped, $source));
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects a_changed_source_no_longer_matches
     */
    public function test_changed_source_does_not_match(): void
    {
        $source = "<layout name=\"a\"/>\n";
        $stamped = LayoutProvenance::stamp(['layout_name' => 'a'], $source, 'x/a.g7.html');

        $this->assertFalse(LayoutProvenance::matches($stamped, $source.'<!-- edit -->'));
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects json_without_marker_never_matches
     */
    public function test_json_without_marker_does_not_match_and_is_not_stamped(): void
    {
        $this->assertFalse(LayoutProvenance::isStamped(['layout_name' => 'a']));
        $this->assertFalse(LayoutProvenance::matches(['layout_name' => 'a'], 'anything'));
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects normalized_line_endings_hash_identically
     */
    public function test_crlf_and_lf_and_bom_hash_identically(): void
    {
        $lf = "<layout/>\n<div/>\n";
        $crlf = "<layout/>\r\n<div/>\r\n";
        $bom = "\xEF\xBB\xBF".$lf;

        $this->assertSame(LayoutProvenance::hash($lf), LayoutProvenance::hash($crlf));
        $this->assertSame(LayoutProvenance::hash($lf), LayoutProvenance::hash($bom));
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects strip_restores_the_pre_stamp_shape
     */
    public function test_strip_removes_marker_and_empty_meta(): void
    {
        $original = ['layout_name' => 'a', 'components' => []];
        $stamped = LayoutProvenance::stamp($original, '<x/>', 'x/a.g7.html');

        // 표식 전에 meta 가 없었으면 strip 후 meta 키 자체가 사라져 원형과 deep-equal.
        $this->assertSame($original, LayoutProvenance::strip($stamped));
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects strip_preserves_other_meta_keys
     */
    public function test_strip_preserves_other_meta_keys(): void
    {
        $original = ['layout_name' => 'a', 'meta' => ['is_partial' => true]];
        $stamped = LayoutProvenance::stamp($original, '<x/>', 'x/a.g7.html');

        $this->assertArrayHasKey('authoring', $stamped['meta']);
        $this->assertSame($original, LayoutProvenance::strip($stamped));
    }
}
