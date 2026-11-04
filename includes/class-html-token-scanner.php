<?php
/**
 * WordPress' HTML tokenizer, plus where the current token sits in the input.
 * Loaded by IATO_MCP_Inline_Markup only when WP_HTML_Tag_Processor::next_token()
 * exists (WordPress 6.5+).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Html_Token_Scanner extends WP_HTML_Tag_Processor {

	/**
	 * Byte offset and length of the current token. For a STYLE element the token
	 * runs through its closing tag.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public function token_span(): ?array {
		if ( ! $this->set_bookmark( 'iato_token' ) ) {
			return null;
		}
		$span = $this->bookmarks['iato_token'];
		return [ (int) $span->start, (int) $span->length ];
	}
}
