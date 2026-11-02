<?php
/**
 * Title: FAQ Content
 * Slug: therestart/faq-content
 * Categories: therestart
 * Keywords: faq, accordion, questions
 * Description: Accordion-style FAQ content for the FAQ page.
 */

// Generated from assets/copy/faq.md by `npm run build:faq`; edit faq.md, not this.
echo wp_kses_post(file_get_contents(dirname(__DIR__) . '/assets/copy/faq.html'));
