<?php
/**
 * Vendored, unmodified from WordPress core (wp-includes/blocks.php, 6.9.1).
 *
 * Only parse_blocks() itself is needed by these tests, so it's pulled out on
 * its own rather than requiring the whole (much larger) blocks.php, which
 * pulls in registry/rendering machinery this test suite doesn't need.
 *
 * @package WordPress
 */

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Parses blocks out of a content string, and returns an array of the
	 * parsed block objects.
	 *
	 * @since 5.0.0
	 *
	 * @param string $content Post content.
	 * @return array[] Array of block structures.
	 */
	function parse_blocks( $content ) {
		/**
		 * Filter to allow plugins to replace the server-side block parser.
		 *
		 * @since 5.0.0
		 *
		 * @param string $parser_class Name of block parser class.
		 */
		$parser_class = apply_filters( 'block_parser_class', 'WP_Block_Parser' );

		$parser = new $parser_class();
		return $parser->parse( $content );
	}
}
