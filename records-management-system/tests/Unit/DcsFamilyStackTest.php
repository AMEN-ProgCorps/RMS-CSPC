<?php

namespace Tests\Unit;

use App\Helpers\DocumentNumberSeriesHelper;
use App\Helpers\RegisterQueryHelper;
use PHPUnit\Framework\TestCase;

class DcsFamilyStackTest extends TestCase
{
    public function test_obsolete_without_stack_stays_in_the_same_document_family(): void
    {
        $groups = RegisterQueryHelper::foldOrphanStatusCopies(collect([
            $this->group('CSPC-LB-STA-10', 'Result Slip Logbook', [
                $this->row(51, 'CSPC-LB-STA-10', 'Result Slip Logbook', 'latest', 'stack-a', 1),
                $this->row(34, 'CSPC-LB-STA-10', 'Result Slip Logbook', 'superseded', 'stack-a', 1),
            ]),
            $this->group('CSPC-LB-STA-10', 'Result Slip Logbook', [
                $this->row(33, 'CSPC-LB-STA-10', 'Result Slip Logbook', 'obsolete', '', 0),
            ]),
        ]));

        $this->assertCount(1, $groups);
        $childIds = array_map(fn ($row) => (int) $row['request_id'], $groups[0]['children']);
        sort($childIds);
        $this->assertSame([33, 34], $childIds);
        $this->assertSame(51, (int) $groups[0]['parent']['request_id']);
    }

    public function test_another_latest_with_the_same_number_stays_separate(): void
    {
        $buhi = $this->group('CSPC-F-COL-89', 'Preventive Maintenance Plan 2027 - Buhi Campus', [
            $this->row(49, 'CSPC-F-COL-89', 'Preventive Maintenance Plan 2027 - Buhi Campus', 'latest', 'stack-b', 1),
            $this->row(47, 'CSPC-F-COL-89', 'Preventive Maintenance Plan 2027 - Buhi Campus', 'obsolete', 'stack-b', 0),
        ]);
        $erp = $this->group('CSPC-F-COL-89', 'Preventive Maintenance Plan 2025 - ERP Laboratory', [
            $this->row(37, 'CSPC-F-COL-89', 'Preventive Maintenance Plan 2025 - ERP Laboratory', 'latest', '', 0),
        ]);

        $groups = RegisterQueryHelper::foldOrphanStatusCopies(collect([$buhi, $erp]));

        $this->assertCount(2, $groups);
        $this->assertFalse(RegisterQueryHelper::familyGroupsMatch($buhi, $erp));
    }

    public function test_a_number_shift_keeps_the_family_stack(): void
    {
        $this->assertFalse(DocumentNumberSeriesHelper::shouldReplaceShiftStack(false));
        $this->assertTrue(DocumentNumberSeriesHelper::shouldReplaceShiftStack(true));
    }

    public function test_unrelated_logbooks_do_not_share_a_family(): void
    {
        $recur = $this->group('CSPC-LB-ST23', 'Sample Document to be Recured', [
            $this->row(49, 'CSPC-LB-ST23', 'Sample Document to be Recured', 'latest', '', 5),
        ]);
        $outgoing = $this->group('CSPC-LB-ACCTG-02', 'Outgoing Reports (Special Journals)', [
            $this->row(24, 'CSPC-LB-ACCTG-02', 'Outgoing Reports (Special Journals) revised', 'obsolete', 'stack-acctg', 2),
        ]);

        $this->assertFalse(RegisterQueryHelper::familyGroupsMatch($recur, $outgoing));

        $merged = RegisterQueryHelper::splitUnrelatedUpdateChildren(collect([
            [
                'doc_no' => 'CSPC-LB-ST23',
                'parent' => $recur['parent'],
                'children' => $outgoing['parent'] ? [$outgoing['parent']] : [],
            ],
        ]));

        $this->assertCount(2, $merged);
        $this->assertSame('CSPC-LB-ST23', $merged[0]['doc_no']);
        $this->assertSame([], $merged[0]['children']);
        $this->assertSame('CSPC-LB-ACCTG-02', $merged[1]['doc_no']);
    }

    public function test_same_title_or_same_stack_is_one_document(): void
    {
        $family = $this->group('CSPC-LB-STA-10', 'Result Slip Logbook', [
            $this->row(51, 'CSPC-LB-STA-10', 'Result Slip Logbook', 'latest', 'stack-a', 1),
        ]);
        $older = $this->group('CSPC-LB-STA-10', 'Result Slip Logbook', [
            $this->row(33, 'CSPC-LB-STA-10', 'Result Slip Logbook', 'obsolete', '', 0),
        ]);

        $this->assertTrue(RegisterQueryHelper::familyGroupsMatch($family, $older));
    }

    private function group(string $docNo, string $title, array $rows): array
    {
        $parent = $rows[0];

        return [
            'doc_no' => $docNo,
            'parent' => $parent,
            'children' => array_slice($rows, 1),
        ];
    }

    private function row(int $requestId, string $docNo, string $title, string $status, string $stack, int $revNo): array
    {
        return [
            'request_id' => $requestId,
            'doc_no' => $docNo,
            'title' => $title,
            'status' => $status,
            'stack_group' => $stack,
            'rev_no' => $revNo,
        ];
    }
}
