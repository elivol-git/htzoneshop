<?php

namespace Tests\Unit;

use App\Services\Search\QueryExpander;
use App\Services\Search\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    public function test_normalizes_case_niqqud_and_punctuation(): void
    {
        $n = new TextNormalizer;
        $this->assertSame('שלום iphone 16', $n->normalize('  שָׁלוֹם, iPhone-16!! '));
        $this->assertSame("צ'יפס פריך", $n->normalize('צ׳יפס "פריך"'));
    }

    public function test_content_tokens_drop_stopwords_and_single_letters(): void
    {
        $n = new TextNormalizer(['של', 'משהו']);
        $this->assertSame(['שערות', 'כלב'], $n->contentTokens('משהו שערות של כלב ב'));
        $this->assertSame(['iphone', '16'], $n->contentTokens('iPhone 16'));
    }

    public function test_prefix_variants(): void
    {
        $n = new TextNormalizer;
        $this->assertSame(['הספה', 'ספה'], $n->prefixVariants('מהספה'));
        $this->assertSame([], $n->prefixVariants('ספה'));
        $this->assertSame([], $n->prefixVariants('dyson'));
    }

    public function test_expander_adds_synonyms_and_known_prefix_variants(): void
    {
        $n = new TextNormalizer(['של']);
        $x = new QueryExpander($n, [['iphone', 'אייפון', 'apple סמארטפון'], ['sofa', 'ספה']], ['ספה' => true]);

        $terms = $x->expand('אייפון')['terms'];
        $this->assertArrayHasKey('iphone', $terms);
        $this->assertArrayHasKey('apple סמארטפון', $terms);

        $terms = $x->expand('שערות של כלב מהספה')['terms'];
        $this->assertArrayHasKey('ספה', $terms);
        $this->assertArrayHasKey('sofa', $terms);
        $this->assertArrayNotHasKey('של', $terms);
        $this->assertArrayNotHasKey('הספה', $terms, 'unknown variant is not added');
    }
}
