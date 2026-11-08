<?php
/**
 * Redirects for URLs a write has moved (1.20.0): a page given a new parent, and a new
 * permalink structure or category and tag base.
 *
 * update_redirect sets one rule and is unchanged. This writes many at once, and its
 * receipts can take them away again, which update_redirect's cannot. A rule is known by
 * its handler, from-path and to-path, so a rollback removes only a rule that still
 * points where the write sent it, and never one someone set up before or changed since.
 *
 * The WordPress side is IATO_MCP_Redirect_Store (set_store() replaces it in tests).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Redirects {

	/** One change creates at most this many. More, and it creates none. */
	public const MAX_PER_CHANGE = 1000;

	/** The built-in handler's limit, as iato_mcp_write_redirect() has it. */
	public const FALLBACK_LIMIT = 500;

	/** Responses list the redirects when there are at most this many. */
	public const LIST_LIMIT = 100;

	/** Every redirect this creates is permanent. */
	public const TYPE = 301;

	private static ?IATO_MCP_Redirect_Store $store = null;

	public static function store(): IATO_MCP_Redirect_Store {
		return self::$store ??= new IATO_MCP_Redirect_Store();
	}

	/** Tests only. */
	public static function set_store( ?IATO_MCP_Redirect_Store $store ): void {
		self::$store = $store;
	}

	/**
	 * The path a redirect rule names for a URL on this site: '/services/x/', with the
	 * site's own folder when it has one, as iato_mcp_write_redirect() stores it. Null
	 * for another host, a URL with a query string (a post with no pretty URL), or no URL.
	 */
	public static function path_of( string $url, string $home_host ): ?string {
		if ( '' === $url ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['query'] ) ) {
			return null;
		}
		if ( isset( $parts['host'] ) && strtolower( (string) $parts['host'] ) !== strtolower( $home_host ) ) {
			return null;
		}
		return '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
	}

	/**
	 * Which moved URLs get a redirect. Only what visitors could open: a published object.
	 * Not from a URL to itself, and not from a path that is now another object's live
	 * URL: the built-in handler runs before WordPress renders a page, so the rule would
	 * hide that page.
	 *
	 * @param list<array{old_url:string,new_url:string,status:string}> $changes
	 * @param string                                                  $home_host The site's host.
	 * @param callable(string):bool                                   $is_live   Is this path now a live URL of something else?
	 * @return array{planned:list<array{from:string,to:string}>,skipped:list<array{from:?string,to:?string,reason:string}>}
	 */
	public static function plan( array $changes, string $home_host, callable $is_live ): array {
		$planned = [];
		$skipped = [];
		$seen    = [];
		foreach ( $changes as $change ) {
			if ( 'publish' !== ( $change['status'] ?? '' ) ) {
				continue;
			}
			$from = self::path_of( (string) $change['old_url'], $home_host );
			$to   = self::path_of( (string) $change['new_url'], $home_host );
			if ( null === $from || null === $to ) {
				$skipped[] = [ 'from' => $from, 'to' => $to, 'reason' => 'not_a_path_on_this_site' ];
				continue;
			}
			if ( $from === $to ) {
				continue;
			}
			if ( isset( $seen[ $from ] ) ) {
				continue;
			}
			$seen[ $from ] = true;
			if ( $is_live( $from ) ) {
				$skipped[] = [ 'from' => $from, 'to' => $to, 'reason' => 'would_shadow_live_url' ];
				continue;
			}
			$planned[] = [ 'from' => $from, 'to' => $to ];
		}
		return [ 'planned' => $planned, 'skipped' => $skipped ];
	}

	/**
	 * Leave alone any path the handler already redirects: it was set up on purpose, and
	 * a rollback could not tell it from the one this made.
	 *
	 * @param list<array{from:string,to:string}> $planned
	 * @return array{planned:list<array{from:string,to:string}>,skipped:list<array{from:string,to:string,reason:string,existing_to:string}>}
	 */
	public static function without_existing( array $planned, string $handler ): array {
		$keep    = [];
		$skipped = [];
		foreach ( $planned as $rule ) {
			$existing = self::store()->existing( $handler, $rule['from'] );
			if ( null !== $existing ) {
				$skipped[] = $rule + [ 'reason' => 'existing_rule_kept', 'existing_to' => $existing ];
				continue;
			}
			$keep[] = $rule;
		}
		return [ 'planned' => $keep, 'skipped' => $skipped ];
	}

	/**
	 * Why none of these can be created, or null when they all can. Checked before
	 * anything is written: a change that redirects half its URLs is worse than one that
	 * redirects none and says so.
	 */
	public static function no_room( string $handler, int $adding ): ?string {
		if ( $adding > self::MAX_PER_CHANGE ) {
			return sprintf(
				'This change moves %1$d URLs that need a redirect, more than the %2$d one change may create, so none were created. Add a pattern rule in a redirect plugin (Redirection can match a regular expression), or rely on WordPress, which redirects many old post URLs itself.',
				$adding,
				self::MAX_PER_CHANGE
			);
		}
		$limit = self::store()->limit( $handler );
		if ( null === $limit ) {
			return null;
		}
		$have = self::store()->count( $handler );
		if ( $have + $adding > $limit ) {
			return sprintf(
				'The %1$s holds at most %2$d redirects and has %3$d, so the %4$d this change needs were not created. %5$s',
				'fallback' === $handler ? 'plugin\'s built-in redirect list' : 'Safe Redirect Manager plugin',
				$limit,
				$have,
				$adding,
				'fallback' === $handler ? 'Install a redirect plugin such as Redirection for more.' : 'Its limit can be raised with the srm_max_redirects filter.'
			);
		}
		return null;
	}

	/**
	 * Create the rules. The receipt that lists them is recorded first, so a request cut
	 * short leaves a receipt whose rollback finds whatever was created.
	 *
	 * @param list<array{from:string,to:string}> $planned
	 * @return array{created:list<array{handler:string,from:string,to:string,type:int}>,failed:list<array{from:string,to:string,error:string}>}
	 */
	public static function create_all( array $planned, string $handler ): array {
		$rules = array_map( static fn( array $r ): array => [ 'handler' => $handler, 'from' => $r['from'], 'to' => $r['to'], 'type' => self::TYPE ], $planned );
		return self::store()->create( $handler, $rules );
	}

	/**
	 * Take away rules a write created. A rule already gone counts as removed, so a
	 * rollback that stopped can run again. A rule that now points elsewhere was changed
	 * by someone since and is kept. A handler whose plugin is not active cannot be
	 * reached: its rules do not fire, and come back if the plugin is turned on again.
	 *
	 * @param list<array{handler:string,from:string,to:string}> $rules
	 * @return array{removed:int,gone:int,kept:list<array>,unreachable:list<array>,failed:list<array>}
	 */
	public static function remove_all( array $rules ): array {
		$report = [ 'removed' => 0, 'gone' => 0, 'kept' => [], 'unreachable' => [], 'failed' => [] ];
		$by     = [];
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['handler'], $rule['from'], $rule['to'] ) ) {
				continue;
			}
			$by[ (string) $rule['handler'] ][] = [ 'from' => (string) $rule['from'], 'to' => (string) $rule['to'] ];
		}
		foreach ( $by as $handler => $list ) {
			if ( ! self::store()->reachable( $handler ) ) {
				foreach ( $list as $rule ) {
					$report['unreachable'][] = $rule + [ 'handler' => $handler ];
				}
				continue;
			}
			$result            = self::store()->remove( $handler, $list );
			$report['removed'] += $result['removed'];
			$report['gone']    += $result['gone'];
			$report['kept']     = array_merge( $report['kept'], $result['kept'] );
			$report['failed']   = array_merge( $report['failed'], $result['failed'] );
		}
		return $report;
	}

	/**
	 * What a response says about the redirects: the list (each with the receipt that
	 * removes it) when it is short, always the count.
	 *
	 * @param list<array> $created
	 */
	public static function report( array $created, ?string $change_id, string $key = 'redirects_created' ): array {
		$list = array_map(
			static fn( array $r ): array => [ 'from' => $r['from'], 'to' => $r['to'], 'type' => (int) ( $r['type'] ?? self::TYPE ) ] + ( null !== $change_id ? [ 'rollback_id' => $change_id ] : [] ),
			$created
		);
		$out = [ $key . '_count' => count( $list ) ];
		if ( count( $list ) <= self::LIST_LIMIT ) {
			$out[ $key ] = $list;
		}
		return $out;
	}

	/** Words for a rollback's warning when some rules could not be removed. */
	public static function removal_note( array $report ): ?string {
		$notes = [];
		if ( [] !== $report['unreachable'] ) {
			$handlers = array_values( array_unique( array_column( $report['unreachable'], 'handler' ) ) );
			$notes[]  = sprintf(
				'%1$d redirect(s) are stored by %2$s, which is not active, so they were left in place. They do not work while it is off, and will again if it is turned back on: remove them there.',
				count( $report['unreachable'] ),
				implode( ', ', $handlers )
			);
		}
		if ( [] !== $report['kept'] ) {
			$notes[] = sprintf(
				'%d redirect(s) were changed after this write, so they were kept: %s.',
				count( $report['kept'] ),
				implode( ', ', array_map( static fn( array $r ): string => $r['from'], array_slice( $report['kept'], 0, 10 ) ) )
			);
		}
		return [] === $notes ? null : implode( ' ', $notes );
	}
}

/**
 * The redirect plugins, one method per question. Each handler stores rules its own way;
 * the plugin's built-in list (the iato_mcp_redirects option) works whatever is active,
 * since its template_redirect hook always runs.
 */
class IATO_MCP_Redirect_Store {

	/** The handler update_redirect would use. */
	public function handler(): string {
		return function_exists( 'iato_mcp_detect_redirect_handler' ) ? iato_mcp_detect_redirect_handler() : 'fallback';
	}

	/** How many rules a handler can hold, or null for no limit. */
	public function limit( string $handler ): ?int {
		if ( 'fallback' === $handler ) {
			return IATO_MCP_Redirects::FALLBACK_LIMIT;
		}
		if ( 'safe_redirect_manager' === $handler && function_exists( 'srm_get_max_redirects' ) ) {
			return (int) srm_get_max_redirects();
		}
		return null;
	}

	public function count( string $handler ): int {
		if ( 'fallback' === $handler ) {
			return count( $this->fallback_rules() );
		}
		if ( 'safe_redirect_manager' === $handler ) {
			$counts = wp_count_posts( 'redirect_rule' );
			return (int) ( $counts->publish ?? 0 );
		}
		return 0;
	}

	/** Where the handler already sends this path, or null. */
	public function existing( string $handler, string $from ): ?string {
		switch ( $handler ) {
			case 'fallback':
				foreach ( $this->fallback_rules() as $rule ) {
					if ( $this->same_path( (string) ( $rule['from_url'] ?? '' ), $from ) ) {
						return (string) ( $rule['to_url'] ?? '' );
					}
				}
				return null;
			case 'safe_redirect_manager':
				foreach ( $this->srm_rules( $from ) as $id ) {
					return (string) get_post_meta( $id, '_redirect_rule_to', true );
				}
				return null;
			case 'redirection':
				foreach ( $this->redirection_items( $from ) as $item ) {
					return (string) $item->get_action_data();
				}
				return null;
			case 'yoast_premium':
				$redirect = $this->yoast_redirect( $from );
				return null === $redirect ? null : (string) $redirect;
		}
		return null;
	}

	/**
	 * @param list<array{handler:string,from:string,to:string,type:int}> $rules
	 * @return array{created:list<array>,failed:list<array>}
	 */
	public function create( string $handler, array $rules ): array {
		$created = [];
		$failed  = [];
		if ( 'fallback' === $handler ) {
			// One write for the whole list: the option is read on every front-end request.
			$stored = $this->fallback_rules();
			foreach ( $rules as $rule ) {
				$stored[] = [ 'from_url' => $rule['from'], 'to_url' => $rule['to'], 'type' => $rule['type'] ];
			}
			update_option( 'iato_mcp_redirects', array_values( $stored ), false );
			$check = $this->fallback_rules();
			foreach ( $rules as $rule ) {
				$found = false;
				foreach ( $check as $row ) {
					if ( $this->same_path( (string) ( $row['from_url'] ?? '' ), $rule['from'] ) && (string) ( $row['to_url'] ?? '' ) === $rule['to'] ) {
						$found = true;
						break;
					}
				}
				if ( $found ) {
					$created[] = $rule;
				} else {
					$failed[] = [ 'from' => $rule['from'], 'to' => $rule['to'], 'error' => 'not saved' ];
				}
			}
			return [ 'created' => $created, 'failed' => $failed ];
		}
		foreach ( $rules as $rule ) {
			$result = $this->create_one( $handler, $rule );
			if ( true === $result ) {
				$created[] = $rule;
			} else {
				$failed[] = [ 'from' => $rule['from'], 'to' => $rule['to'], 'error' => is_wp_error( $result ) ? $result->get_error_message() : 'not saved' ];
			}
		}
		return [ 'created' => $created, 'failed' => $failed ];
	}

	private function create_one( string $handler, array $rule ): bool|WP_Error {
		if ( 'safe_redirect_manager' === $handler && function_exists( 'srm_create_redirect' ) ) {
			// The plugin's own function also clears its cached list of rules.
			$id = srm_create_redirect( $rule['from'], $rule['to'], $rule['type'], false, 'publish', 0, 'IATO MCP: URL moved', max( 1, (int) get_current_user_id() ) );
			return is_wp_error( $id ) ? $id : (int) $id > 0;
		}
		if ( function_exists( 'iato_mcp_write_redirect' ) ) {
			return iato_mcp_write_redirect( $rule['from'], $rule['to'], (int) $rule['type'], $handler );
		}
		return new WP_Error( 'redirect_unavailable', 'The redirect writer is not loaded.' );
	}

	/** Can this handler's rules be removed now? */
	public function reachable( string $handler ): bool {
		return match ( $handler ) {
			'fallback', 'safe_redirect_manager' => true, // an option, and posts that exist with the plugin off too
			'redirection'                       => class_exists( 'Red_Item' ) && method_exists( 'Red_Item', 'get_for_matched_url' ),
			'yoast_premium'                     => class_exists( 'WPSEO_Redirect_Manager' ),
			default                             => false,
		};
	}

	/**
	 * @param list<array{from:string,to:string}> $rules
	 * @return array{removed:int,gone:int,kept:list<array>,failed:list<array>}
	 */
	public function remove( string $handler, array $rules ): array {
		$report = [ 'removed' => 0, 'gone' => 0, 'kept' => [], 'failed' => [] ];
		switch ( $handler ) {
			case 'fallback':
				$stored  = $this->fallback_rules();
				$changed = false;
				foreach ( $rules as $rule ) {
					$state = 'gone';
					foreach ( $stored as $i => $row ) {
						if ( ! $this->same_path( (string) ( $row['from_url'] ?? '' ), $rule['from'] ) ) {
							continue;
						}
						if ( (string) ( $row['to_url'] ?? '' ) === $rule['to'] ) {
							unset( $stored[ $i ] );
							$changed = true;
							$state   = 'removed';
						} elseif ( 'gone' === $state ) {
							$state = 'kept';
						}
					}
					$this->tally( $report, $state, $rule, $handler );
				}
				if ( $changed ) {
					update_option( 'iato_mcp_redirects', array_values( $stored ), false );
				}
				return $report;

			case 'safe_redirect_manager':
				foreach ( $rules as $rule ) {
					$state = 'gone';
					foreach ( $this->srm_rules( $rule['from'] ) as $id ) {
						if ( (string) get_post_meta( $id, '_redirect_rule_to', true ) !== $rule['to'] ) {
							$state = 'removed' === $state ? $state : 'kept';
							continue;
						}
						if ( ! wp_delete_post( $id, true ) ) {
							$report['failed'][] = $rule + [ 'handler' => $handler, 'error' => "redirect_rule post {$id} could not be deleted" ];
							continue 2;
						}
						$state = 'removed';
					}
					$this->tally( $report, $state, $rule, $handler );
				}
				if ( function_exists( 'srm_flush_cache' ) ) {
					srm_flush_cache();
				}
				return $report;

			case 'redirection':
				foreach ( $rules as $rule ) {
					$state = 'gone';
					foreach ( $this->redirection_items( $rule['from'] ) as $item ) {
						if ( (string) $item->get_action_data() !== $rule['to'] ) {
							$state = 'removed' === $state ? $state : 'kept';
							continue;
						}
						$item->delete();
						$state = 'removed';
					}
					$this->tally( $report, $state, $rule, $handler );
				}
				return $report;

			case 'yoast_premium':
				$manager = new WPSEO_Redirect_Manager();
				foreach ( $rules as $rule ) {
					$current = $this->yoast_redirect( $rule['from'] );
					if ( null !== $current && ltrim( $current, '/' ) !== ltrim( $rule['to'], '/' ) ) {
						$this->tally( $report, 'kept', $rule, $handler );
						continue;
					}
					if ( method_exists( $manager, 'delete_redirects' ) && class_exists( 'WPSEO_Redirect' ) ) {
						$manager->delete_redirects( [ new WPSEO_Redirect( $rule['from'] ) ] );
						$this->tally( $report, 'removed', $rule, $handler );
					} else {
						$report['failed'][] = $rule + [ 'handler' => $handler, 'error' => 'Yoast Premium cannot delete redirects in this version' ];
					}
				}
				return $report;
		}
		foreach ( $rules as $rule ) {
			$report['failed'][] = $rule + [ 'handler' => $handler, 'error' => 'unknown redirect handler' ];
		}
		return $report;
	}

	private function tally( array &$report, string $state, array $rule, string $handler ): void {
		if ( 'removed' === $state ) {
			$report['removed']++;
		} elseif ( 'gone' === $state ) {
			$report['gone']++;
		} else {
			$report['kept'][] = $rule + [ 'handler' => $handler ];
		}
	}

	/** Paths compare without the trailing slash, as the plugins do. */
	private function same_path( string $a, string $b ): bool {
		return rtrim( '/' . ltrim( $a, '/' ), '/' ) === rtrim( '/' . ltrim( $b, '/' ), '/' );
	}

	private function fallback_rules(): array {
		$rules = get_option( 'iato_mcp_redirects', [] );
		return is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : [];
	}

	/** @return list<int> Published redirect_rule posts for this path. */
	private function srm_rules( string $from ): array {
		$ids = get_posts(
			[
				'post_type'        => 'redirect_rule',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one meta key, looked up per moved URL.
				'meta_query'       => [
					[
						'key'     => '_redirect_rule_from',
						'value'   => [ $from, rtrim( $from, '/' ) ],
						'compare' => 'IN',
					],
				],
			]
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/** Redirection's enabled, plain (not regular expression) rules for exactly this path. */
	private function redirection_items( string $from ): array {
		if ( ! class_exists( 'Red_Item' ) || ! method_exists( 'Red_Item', 'get_for_matched_url' ) ) {
			return [];
		}
		$items = [];
		foreach ( (array) Red_Item::get_for_matched_url( $from ) as $item ) {
			if ( ! is_object( $item ) || ( method_exists( $item, 'is_regex' ) && $item->is_regex() ) ) {
				continue;
			}
			if ( $this->same_path( (string) $item->get_url(), $from ) ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/** Where Yoast Premium sends this path, when this version can say. */
	private function yoast_redirect( string $from ): ?string {
		if ( ! class_exists( 'WPSEO_Redirect_Manager' ) ) {
			return null;
		}
		$manager = new WPSEO_Redirect_Manager();
		if ( ! method_exists( $manager, 'get_redirect' ) ) {
			return null;
		}
		$redirect = $manager->get_redirect( ltrim( $from, '/' ) ) ?: $manager->get_redirect( $from );
		return is_object( $redirect ) && method_exists( $redirect, 'get_target' ) ? (string) $redirect->get_target() : null;
	}
}
