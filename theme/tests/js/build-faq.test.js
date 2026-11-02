const fs = require('fs');
const path = require('path');
const { parseFaqMarkdown, renderFaqBlocks, renderInline } = require('../../scripts/build-faq');

const COPY_DIR = path.join(__dirname, '..', '..', 'assets', 'copy');

describe('parseFaqMarkdown', () => {
    test('splits entries on --- and skips the title block', () => {
        const md = '# FAQ\n\n---\n\n**First?**\nAnswer one.\n\n---\n\n**Second?**\nAnswer two.\n';
        expect(parseFaqMarkdown(md)).toEqual([
            { question: 'First?', paragraphs: ['Answer one.'] },
            { question: 'Second?', paragraphs: ['Answer two.'] },
        ]);
    });

    test('keeps multiple paragraphs and joins wrapped lines', () => {
        const md = '**Q?**\nLine one\ncontinues.\n\nSecond paragraph.';
        expect(parseFaqMarkdown(md)[0].paragraphs).toEqual(['Line one continues.', 'Second paragraph.']);
    });

    test('drops HTML comments', () => {
        const md = '**Q?**\n<!-- TODO -->\nReal answer.';
        expect(parseFaqMarkdown(md)[0].paragraphs).toEqual(['Real answer.']);
    });

    test('throws on a question with no answer, so a TODO cannot ship empty', () => {
        expect(() => parseFaqMarkdown('**Q?**\n<!-- TODO(owner) -->\n')).toThrow('no answer');
    });
});

describe('renderInline', () => {
    test('converts links and bold, escaping HTML first', () => {
        expect(renderInline('See [Start](/start/) & **now** <b>'))
            .toBe('See <a href="/start/">Start</a> &amp; <strong>now</strong> &lt;b&gt;');
    });
});

describe('renderFaqBlocks', () => {
    test('emits one core/details block per entry', () => {
        const html = renderFaqBlocks([{ question: 'Q?', paragraphs: ['A.'] }]);
        expect(html).toContain('<details class="wp-block-details"><summary>Q?</summary>');
        expect(html).toContain('<!-- wp:paragraph -->\n<p>A.</p>\n<!-- /wp:paragraph -->');
        expect(html.match(/<!-- wp:details -->/g)).toHaveLength(1);
    });
});

describe('committed faq.html', () => {
    test('is up to date with faq.md (run `npm run build:faq`)', () => {
        const md = fs.readFileSync(path.join(COPY_DIR, 'faq.md'), 'utf8');
        const html = fs.readFileSync(path.join(COPY_DIR, 'faq.html'), 'utf8');
        expect(html).toBe(renderFaqBlocks(parseFaqMarkdown(md)));
    });
});
