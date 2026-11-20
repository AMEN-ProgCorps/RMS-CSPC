<?php

namespace Tests\Unit;

use App\Helpers\SmartSearchHelper;
use PHPUnit\Framework\TestCase;

class SmartSearchTest extends TestCase
{
    /**
     * Test tokenization of queries.
     */
    public function test_tokenize_preserves_words_and_digits(): void
    {
        $tokens = SmartSearchHelper::tokenize('NAP Form 1');
        $this->assertEquals(['nap', 'form', '1'], $tokens);

        $dtsTokens = SmartSearchHelper::tokenize('DTS-2026-0012');
        $this->assertEquals(['dts', '2026', '0012'], $dtsTokens);

        $empty = SmartSearchHelper::tokenize('   ');
        $this->assertEquals([], $empty);
    }

    /**
     * Test relevance scoring hierarchy (exact match > prefix > contains > tokens).
     */
    public function test_relevance_scoring_hierarchy(): void
    {
        $query = 'DTS-2026-0001';

        // Exact primary match
        $exactScore = SmartSearchHelper::calculateScore($query, [
            'primary' => 'DTS-2026-0001',
            'secondary' => 'Travel Order',
        ]);

        // Prefix match
        $prefixScore = SmartSearchHelper::calculateScore($query, [
            'primary' => 'DTS-2026-0001-REV',
            'secondary' => 'Travel Order',
        ]);

        // Contains match
        $containsScore = SmartSearchHelper::calculateScore($query, [
            'primary' => 'REF-DTS-2026-0001-EXTRA',
            'secondary' => 'Travel Order',
        ]);

        // Completely unrelated
        $unrelatedScore = SmartSearchHelper::calculateScore($query, [
            'primary' => 'DCS-2025-9999',
            'secondary' => 'Leave Application',
        ]);

        $this->assertGreaterThan($prefixScore, $exactScore, 'Exact match must score higher than prefix match');
        $this->assertGreaterThan($containsScore, $prefixScore, 'Prefix match must score higher than contains match');
        $this->assertGreaterThan($unrelatedScore, $containsScore, 'Contains match must score higher than unrelated');
        $this->assertEquals(0, $unrelatedScore, 'Unrelated records must score 0');
    }

    /**
     * Test multi-token matching and ordering where best fit is sorted to top.
     */
    public function test_sort_collection_puts_most_fit_at_top(): void
    {
        $items = collect([
            [
                'id' => 1,
                'control_number' => 'DTS-2026-0050',
                'subject' => 'General memorandum regarding attendance',
            ],
            [
                'id' => 2,
                'control_number' => 'DTS-2026-0012',
                'subject' => 'Special Travel Order for Dean of Computer Studies',
            ],
            [
                'id' => 3,
                'control_number' => 'DTS-2026-0099',
                'subject' => 'Travel Order',
            ],
        ]);

        // Search: "Travel Order"
        $sorted = SmartSearchHelper::sortCollection($items, 'Travel Order', function ($item) {
            return [
                'primary' => $item['control_number'],
                'secondary' => $item['subject'],
            ];
        });

        // Exact secondary match ("Travel Order") must be #1!
        $this->assertEquals(3, $sorted->first()['id']);

        // Search: "Dean Travel Order"
        $sortedDean = SmartSearchHelper::sortCollection($items, 'Dean Travel Order', function ($item) {
            return [
                'primary' => $item['control_number'],
                'secondary' => $item['subject'],
            ];
        });

        // Item 2 has both Dean AND Travel Order
        $this->assertEquals(2, $sortedDean->first()['id']);

        // Search exact control number: "DTS-2026-0050"
        $sortedCn = SmartSearchHelper::sortCollection($items, 'DTS-2026-0050', function ($item) {
            return [
                'primary' => $item['control_number'],
                'secondary' => $item['subject'],
            ];
        });
        $this->assertEquals(1, $sortedCn->first()['id']);
    }

    /**
     * Test base64 string auto-normalization.
     */
    public function test_base64_normalization(): void
    {
        $plain = 'DTS-2026-0042';
        $encoded = base64_encode($plain);

        $normalized = SmartSearchHelper::normalize($encoded);
        $this->assertEquals($plain, $normalized);
    }

    /**
     * Test applySearch generates valid SQL clauses with relevance ordering.
     */
    public function test_apply_search_builds_expected_where_and_order_sql(): void
    {
        $connection = $this->createMock(\Illuminate\Database\Connection::class);
        $builder = new \Illuminate\Database\Query\Builder(
            $connection,
            new \Illuminate\Database\Query\Grammars\PostgresGrammar($connection),
            new \Illuminate\Database\Query\Processors\PostgresProcessor()
        );
        $builder->from('dts_transactions as dt');

        SmartSearchHelper::applySearch($builder, 'Travel Order', [
            'primary' => ['dt.control_number'],
            'secondary' => ['dt.subject'],
        ]);

        $sql = $builder->toSql();

        // Must contain case when relevance ordering
        $this->assertStringContainsString('CASE WHEN', $sql);
        $this->assertStringContainsString('THEN 10000', $sql);
        $this->assertStringContainsString('THEN 5000', $sql);
        $this->assertStringContainsString('DESC', $sql);

        // Must contain ILIKE where clause
        $this->assertStringContainsString('ilike', strtolower($sql));
    }

    /**
     * Test applySearch defensively excludes columns from table aliases not joined in query.
     */
    public function test_apply_search_filters_unjoined_table_aliases(): void
    {
        $connection = $this->createMock(\Illuminate\Database\Connection::class);
        $builder = new \Illuminate\Database\Query\Builder(
            $connection,
            new \Illuminate\Database\Query\Grammars\PostgresGrammar($connection),
            new \Illuminate\Database\Query\Processors\PostgresProcessor()
        );
        $builder->from('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id');

        // req is NOT joined in $builder
        SmartSearchHelper::applySearch($builder, 'Test Search', [
            'primary'   => ['dtd.control_number', 'dt.qr_code'],
            'secondary' => ['dtd.subject'],
            'meta'      => ['req.requestor_name', 'dtd.action_needed'],
        ]);

        $sql = $builder->toSql();

        // Must NOT reference req.requestor_name
        $this->assertStringNotContainsString('req.requestor_name', $sql);
        // Must contain joined columns
        $this->assertStringContainsString('dtd.control_number', $sql);
        $this->assertStringContainsString('dt.qr_code', $sql);
    }
}
