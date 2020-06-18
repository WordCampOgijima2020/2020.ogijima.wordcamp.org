<?php

use function WordCamp\Sunrise\{ get_domain_redirects, get_top_level_domain };
use const WordCamp\Sunrise\{ PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH };


/**
 * A helper plugin for our integration with Let's Encrypt.
 */
class WordCamp_Lets_Encrypt_Helper {
	/**
	 * Initialize
	 */
	public static function load() {
		if ( ! is_main_site() ) {
			return;
		}

		add_filter( 'rest_api_init', array( __CLASS__, 'rest_api_init' ) );
	}

	/**
	 * Register REST API endpoints
	 */
	public static function rest_api_init() {
		register_rest_route(
			'wordcamp-letsencrypt/v1',
			'/domains-dehydrated',
			array(
				'methods'  => 'GET',
				'callback' => array( __CLASS__, 'rest_callback_domains_dehydrated' ),
			)
		);
	}

	/**
	 * Return an array of all domains that need SSL certs.
	 *
	 * @return array
	 */
	public static function get_domains() {
		global $wpdb;

		$tld     = get_top_level_domain();
		$domains = array();
		$blogs   = $wpdb->get_results( "
			SELECT `blog_id`, `domain`, `path`
			FROM `$wpdb->blogs`
			WHERE
				`public`  = 1 AND
				`deleted` = 0
			ORDER BY `blog_id` ASC",
			ARRAY_A
		);

		// todo maybe modularize this a bit?

		// todo test on sandbox before committing

		foreach ( $blogs as $blog ) {
			// Match legacy 2020.narnia.wordcamp.org domains.
			// @todo this can be removed after the July 2020 migration is complete.
//			if ( preg_match( PATTERN_YEAR_DOT_CITY_DOMAIN_PATH, $blog['domain'] . $blog['path'], $matches ) ) {
//				$domains[] = sprintf( "%s.%s.$tld", $matches[2], $matches[3] );
//				// won't ^ be covered by the one below, where it just adds the domain itself?
//			}

			$domains[] = $blog['domain'];

			// document that it's for back-compat, so that old links can still redirect to the new url w/out ssl errors
			// todo change to the # at which all new domains are migrated city/year
			if ( $blog['blog_id'] <= 1375 && preg_match( PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, $blog['domain'] . $blog['path'] ) ) {
				$domains[] = sprintf(
					'%s.%s',
					trim( $blog['path'], '/' ),
					$blog['domain']
				);

				// todo test
			}

			// Match current narnia.wordcamp.org/2020 domains.
//			if ( preg_match( '#^([^\.]+)\.wordcamp.org/([0-9]{4}(?:-[^\.])?)/?$#i', $blog['domain'] . $blog['path'], $matches ) ) {
//				$domains[] = sprintf( '%s.%s.wordcamp.org', $matches[2], $matches[1] );
//			}
			// dont need ^ because adding domain. path doesn't matter
		}

		// Back-compat domains. Note: is_callable() requires the full path, it doesn't follow namespace imports.
		if ( is_callable( 'WordCamp\Sunrise\get_domain_redirects' ) ) {
			$back_compat_domains = get_domain_redirects();
			// todo test still works


				// if so, use separate function to keep organized

			// add note to migration script to add the domains here to commit along w/ running the script

			$domains = array_merge( $domains, array_keys( $back_compat_domains ) );
		}

		/// todo this isn't working? getting extra domain
		$domains = array_unique( $domains );
		$domains = apply_filters( 'wordcamp_letsencrypt_domains', $domains );

		return array_values( $domains );
	}



//
	// this is worse approach, do the one above instead
		// call a function here that just gets an array of hardcoded domains for back-compat
		// maybe name it something like `get_legacy_ssl_cert_domains()` or something
		// document that we need to keep getting certs so that old links will still redirect to the new ones
		// oh but grr, need to combine them for dehydrated single cert. ugh.
		// maybe code below will do that for us?

		// also need to make it return empty on local sites, so json endpoint is accurate
			// or maybe return something that can be used in unit tests
//	public static function get_legacy_city_dot_year_domains() {
//
//
//		/*
//
//
//SELECT domain
//FROM `wc_blogs`
//where
//	path = '/' AND
//    public = 1 AND
//    deleted = 0
//
//order by domain ASC
//limit 3000
//		*/
//		/*
//		taken on  6/24 -- need to add any new ones since then
//make sure array_unique dedupes it
//manually remove any crap like hogwarts, etc
//		 */


	/**
	 * Group domains with their parent domain.
	 *
	 * @return array
	 * @param array $domains
	 *
	 * @return array
	 */
	public static function group_domains( $domains ) {
		$tld    = get_top_level_domain();
		$result = array();

		// Sort domains by shortest first, sort all same-length domains by natural case.
		// Later on, this will allow us to create the parent array before adding the children to it.
		usort( $domains, function( $a, $b ) {
			$a_len = strlen( $a );
			$b_len = strlen( $b );

			if ( $a_len === $b_len ) {
				return strnatcasecmp( $a, $b );
			}

			return $a_len - $b_len;
			// todo test when switching
		} );

		// need to mess w/ this stuff to get the ordering correct? want 2014.seatt then 2015.seatt then 2019.seatt?
			// todo nevermind, prob has the `-extra` ones last, because of the natural sorting, just let that ride

		// Group all the subdomains together with their "parent" (xyz.campevent.tld)
		foreach ( $domains as $domain ) {
			$dots = substr_count( $domain, '.' );

			if ( $dots <= 2 ) {
				// Special cases
				if ( "central.wordcamp.$tld" === $domain ) {
					$result["wordcamp.$tld"][] = $domain;

				} elseif ( in_array( $domain, [ "2006.wordcamp.$tld", "2007.wordcamp.$tld", 'wordcampsf.org', 'wordcampsf.com'] ) ) {
					$result["sf.wordcamp.$tld"][] = $domain;
					$result["sf.wordcamp.$tld"][] = "www.{$domain}";

				} elseif ( ! isset( $result[ $domain ] ) ) {
					// Main domain
					$result[ $domain ] = array();
				}

			} else {
				// Strip anything before xyz.campevent.tld
				$main_domain              = implode( '.', array_slice( explode( '.', $domain ), - 3 ) );
				$result[ $main_domain ][] = $domain;
			}
		}

		return $result;
	}

	/**
	 * REST: domains-dehydrated
	 *
	 * Return a dehydrated domains.txt file of all domains that need SSL certs, in a format suitable for the
	 * dehydrated client.
	 *
	 * @see https://github.com/dehydrated-io/dehydrated/blob/master/docs/domains_txt.md
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|void
	 */
	public static function rest_callback_domains_dehydrated( $request ) {
		if ( WORDCAMP_LE_HELPER_API_KEY !== $request->get_param( 'api_key' ) ) {
			return new WP_Error( 'error', 'Invalid or empty key.', array( 'status' => 403 ) );
		}

		$domains = self::group_domains( self::get_domains() );

		// flatten and output in a dehydrated format.
		header( 'Content-type: text/plain' );

		// Primary Domain \s certAltNames
		// narnia.wordcamp.org www.narnia.wordcamp.org 2020.narnia.wordcamp.org
		foreach ( $domains as $domain => $subdomains ) {
			$altnames = implode( ' ', $subdomains );

			echo rtrim( "$domain www.{$domain} $altnames" ) . "\n";
		}

		exit;
	}
}

WordCamp_Lets_Encrypt_Helper::load();
