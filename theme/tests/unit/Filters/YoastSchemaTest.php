<?php

declare(strict_types=1);

namespace TheRestart\Tests\Unit\Filters;

use Brain\Monkey\Functions;
use stdClass;
use TheRestart\Tests\ThemeTestCase;

final class YoastSchemaTest extends ThemeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_strip_all_tags')->alias(fn(string $text): string => trim(strip_tags($text)));
    }

    private const FAQ_CONTENT = <<<'HTML'
<!-- wp:details -->
<details class="wp-block-details"><summary>Is The reStart free?</summary>
<!-- wp:paragraph -->
<p>Yes, The reStart is free. See <a href="/start-a-registry/">Start a Registry</a> &amp; more.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary>What&#039;s a divorce registry?</summary>
<!-- wp:paragraph -->
<p>A gift registry <span class="x">for</span> starting over.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->
HTML;

    private function context(string $postType, string $postName, string $content): stdClass
    {
        $post = new stdClass();
        $post->post_type = $postType;
        $post->post_name = $postName;
        $post->post_content = $content;
        $context = new stdClass();
        $context->post = $post;
        return $context;
    }

    public function test_extracts_questions_and_cleans_answers(): void
    {
        $items = restart_faq_items_from_content(self::FAQ_CONTENT);

        $this->assertSame([
            [
                'question' => 'Is The reStart free?',
                'answer'   => '<p>Yes, The reStart is free. See <a href="/start-a-registry/">Start a Registry</a> &amp; more.</p>',
            ],
            [
                'question' => "What's a divorce registry?",
                'answer'   => '<p>A gift registry for starting over.</p>',
            ],
        ], $items);
    }

    public function test_skips_details_with_empty_answer(): void
    {
        $this->assertSame([], restart_faq_items_from_content('<details><summary>Q?</summary><!-- wp:paragraph --></details>'));
    }

    public function test_faq_page_becomes_faqpage_with_questions(): void
    {
        $piece = ['@type' => 'WebPage', 'name' => 'FAQ'];

        $result = $this->invokeFilter('wpseo_schema_webpage', $piece, $this->context('page', 'faq', self::FAQ_CONTENT));

        $this->assertSame(['WebPage', 'FAQPage'], $result['@type']);
        $this->assertCount(2, $result['mainEntity']);
        $this->assertSame([
            '@type'          => 'Question',
            'name'           => 'Is The reStart free?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => '<p>Yes, The reStart is free. See <a href="/start-a-registry/">Start a Registry</a> &amp; more.</p>',
            ],
        ], $result['mainEntity'][0]);
    }

    public function test_keeps_existing_type_array_without_duplicating_faqpage(): void
    {
        $piece = ['@type' => ['WebPage', 'FAQPage']];

        $result = $this->invokeFilter('wpseo_schema_webpage', $piece, $this->context('page', 'faq', self::FAQ_CONTENT));

        $this->assertSame(['WebPage', 'FAQPage'], $result['@type']);
    }

    public function test_other_pages_pass_through(): void
    {
        $piece = ['@type' => 'WebPage'];

        $this->assertSame($piece, $this->invokeFilter('wpseo_schema_webpage', $piece, $this->context('page', 'about-us', self::FAQ_CONTENT)));
        $this->assertSame($piece, $this->invokeFilter('wpseo_schema_webpage', $piece, $this->context('post', 'faq', self::FAQ_CONTENT)));
        $this->assertSame($piece, $this->invokeFilter('wpseo_schema_webpage', $piece, null));
    }

    public function test_faq_page_without_details_passes_through(): void
    {
        $piece = ['@type' => 'WebPage'];

        $this->assertSame($piece, $this->invokeFilter('wpseo_schema_webpage', $piece, $this->context('page', 'faq', '<p>No questions</p>')));
    }

    public function test_organization_gets_brand_legal_and_alternate_names(): void
    {
        $result = $this->invokeFilter('wpseo_schema_organization', ['@type' => 'Organization', 'name' => 'the ReStart']);

        $this->assertSame('The reStart', $result['name']);
        $this->assertSame('reStart Group, LLC', $result['legalName']);
        $this->assertContains('The reStart.com', $result['alternateName']);
    }

    public function test_website_gets_brand_and_alternate_names(): void
    {
        $result = $this->invokeFilter('wpseo_schema_website', ['@type' => 'WebSite', 'name' => 'the ReStart']);

        $this->assertSame('The reStart', $result['name']);
        $this->assertContains('reStart', $result['alternateName']);
        $this->assertArrayNotHasKey('legalName', $result);
    }
}
