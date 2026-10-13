<?php
/*
	Plugin Name: Related Posts for WordPress - WPML Compatibility
	Plugin URI: https://docs.relatedpostsforwp.com/multilingual/wpml-compatibility/
	Description: Keeps related posts in the language of the post on WPML sites. Works with Related Posts for WordPress 3.0 or newer (free or Premium), and with Premium 2.x.
	Version: 3.0.0
	Author: Never5
	Author URI: http://www.never5.com/
	Requires at least: 6.8
	Requires PHP: 7.4
	License: GPL v3

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

defined( 'ABSPATH' ) || exit;

class RP4WP_WPML {

	/**
	 * The legacy hooks Related Posts for WordPress 3.x registers its on-publish callbacks under, in the order they run.
	 */
	const PUBLISH_HOOKS = array( 'RP4WP_Hook_Related_Save_Words', 'RP4WP_Hook_Related_Auto_Link' );

	/**
	 * Posts published before WPML stored their language: post ID => the status before publishing.
	 *
	 * @var array<int, string>
	 */
	private $held = array();

	/**
	 * The on-publish callbacks that are taken off while a post is held.
	 *
	 * @var array<string, object>
	 */
	private $unhooked = array();

	/**
	 * Constructor. Runs after Related Posts for WordPress (plugins_loaded 20) and the safe mode check of Premium (30).
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'setup' ), 40 );
	}

	/**
	 * Hook in, depending on the version of Related Posts for WordPress.
	 */
	public function setup() {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			add_action( 'admin_notices', array( $this, 'dep_notice_wpml' ) );

			return;
		}

		if ( class_exists( '\LV2\WordPress\RelatedPostsForWP\Main' ) && \LV2\WordPress\RelatedPostsForWP\Main::get()->is_set_up() ) {
			$this->setup_3();

			return;
		}

		if ( class_exists( 'RP4WP_Constants' ) && defined( 'RP4WP_Constants::PREMIUM' ) && true === RP4WP_Constants::PREMIUM ) {
			$this->setup_2();

			return;
		}

		add_action( 'admin_notices', array( $this, 'dep_notice_rp4wp' ) );
	}

	/**
	 * Related Posts for WordPress 3.x, free or Premium.
	 */
	private function setup_3() {
		add_filter( 'rp4wp_finder_clauses', array( $this, 'filter_finder_clauses' ), 10, 3 );
		add_filter( 'rp4wp_fill_conditions', array( $this, 'filter_fill_conditions' ), 10, 3 );

		// The plugin reads and links a post when it is published, which is before WPML stores the language of a post that
		// is created and published at once (the REST API, an import, WP-CLI). Such posts wait until WPML is done.
		add_action( 'transition_post_status', array( $this, 'hold' ), 9, 3 );
		add_action( 'transition_post_status', array( $this, 'rehook' ), 12, 0 );
		add_action( 'wp_after_insert_post', array( $this, 'release' ), 99, 1 );
		add_action( 'shutdown', array( $this, 'release_all' ), 0, 0 );
	}

	/**
	 * Related Posts for WordPress Premium 2.x.
	 */
	private function setup_2() {
		add_filter( 'rp4wp_get_related_posts_sql', array( $this, 'filter_related_posts_sql' ), 10, 4 );
		add_filter( 'rp4wp_ignored_words_lang', array( $this, 'filter_ignored_words' ), 10, 4 );
		add_action( 'wpml_translation_update', array( $this, 'force_relink_on_translation_insert' ), 10, 1 );
	}

	/**
	 * Dependency RP4WP notice
	 */
	public function dep_notice_rp4wp() {
		?>
		<div class="notice notice-error"><p><strong><a
						href="https://www.relatedpostsforwp.com/?utm_source=rp4wp-wpml&utm_medium=link&utm_campaign=notice"
						target="_blank">Related Posts for WordPress</a> 3.0 or higher</strong>, or Related Posts for WordPress Premium 2.x, must be
				installed and activated to use <strong>Related Posts for WordPress - WPML Compatibility</strong>.</p>
		</div><?php
	}

	/**
	 * Dependency WPML notice
	 */
	public function dep_notice_wpml() {
		?>
		<div class="notice notice-error"><p><strong>WPML</strong> must be installed and activated to use <strong>Related
					Posts for WordPress - WPML Compatibility</strong>.</p></div><?php
	}

	/**
	 * The WPML language code of a post, or an empty string when WPML has none (yet).
	 *
	 * @param int $post_id The post.
	 *
	 * @return string
	 */
	private function language( $post_id ) {
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );

		return is_array( $details ) && ! empty( $details['language_code'] ) ? (string) $details['language_code'] : '';
	}

	/**
	 * The SQL condition that keeps a candidate related post in the language of the post, or an empty string when the
	 * post has no language (yet). Posts of post types WPML does not translate have no language, and stay candidates.
	 *
	 * @param int    $post_id The post.
	 * @param string $column  The column of the candidate's ID in the query.
	 *
	 * @return string
	 */
	private function condition( $post_id, $column ) {
		global $wpdb;

		$language = $this->language( $post_id );
		if ( '' === $language ) {
			return '';
		}

		$table = $wpdb->prefix . 'icl_translations';
		$posts = $wpdb->esc_like( 'post_' ) . '%';

		return $wpdb->prepare(
			"( EXISTS ( SELECT 1 FROM {$table} RP4WP_IT WHERE RP4WP_IT.element_id = {$column} AND RP4WP_IT.element_type LIKE %s AND RP4WP_IT.language_code = %s )
			OR NOT EXISTS ( SELECT 1 FROM {$table} RP4WP_IT2 WHERE RP4WP_IT2.element_id = {$column} AND RP4WP_IT2.element_type LIKE %s ) )",
			$posts,
			$language,
			$posts
		);
	}

	/**
	 * Only find related posts in the language of the post (3.x).
	 *
	 * @param array  $clauses   The extra joins and conditions; the candidate related post is R.post_id.
	 * @param int    $post_id   The post.
	 * @param string $post_type The post type of the post.
	 *
	 * @return array
	 */
	public function filter_finder_clauses( $clauses, $post_id, $post_type ) {
		$condition = $this->condition( $post_id, 'R.post_id' );
		if ( '' !== $condition ) {
			$clauses['where'][] = $condition;
		}

		return $clauses;
	}

	/**
	 * Only fill up related posts with posts in the language of the post (Premium 3.0.1 and newer).
	 *
	 * @param string[] $conditions The conditions; {ID} is the post that may fill up.
	 * @param int      $post_id    The post.
	 * @param string   $post_type  The post type of the post.
	 *
	 * @return string[]
	 */
	public function filter_fill_conditions( $conditions, $post_id, $post_type ) {
		$condition = $this->condition( $post_id, '{ID}' );
		if ( '' !== $condition ) {
			$conditions[] = $condition;
		}

		return $conditions;
	}

	/**
	 * When a post is published before WPML stored its language, take the on-publish callbacks of the plugin off for it,
	 * so it is read and linked once its language is known (3.x).
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old status.
	 * @param \WP_Post $post       The post.
	 */
	public function hold( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post || '' !== $this->language( (int) $post->ID ) ) {
			return;
		}

		if ( ! class_exists( '\LV2\WordPress\RelatedPostsForWP\Compat\LegacyHooks' ) ) {
			return;
		}

		foreach ( self::PUBLISH_HOOKS as $name ) {
			$hook = \LV2\WordPress\RelatedPostsForWP\Compat\LegacyHooks::get( $name );
			if ( $hook && remove_action( $hook->get_tag(), array( $hook, 'run' ), $hook->get_priority() ) ) {
				$this->unhooked[ $name ] = $hook;
			}
		}

		if ( count( $this->unhooked ) > 0 ) {
			$this->held[ (int) $post->ID ] = (string) $old_status;
		}
	}

	/**
	 * Put the on-publish callbacks back, so other posts are handled as usual (3.x).
	 */
	public function rehook() {
		foreach ( $this->unhooked as $hook ) {
			add_action( $hook->get_tag(), array( $hook, 'run' ), $hook->get_priority(), $hook->get_args() );
		}

		$this->unhooked = array();
	}

	/**
	 * Read and link a held post, now that WPML stored its language (3.x).
	 *
	 * @param int $post_id The post.
	 */
	public function release( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! isset( $this->held[ $post_id ] ) ) {
			return;
		}

		$old_status = $this->held[ $post_id ];
		unset( $this->held[ $post_id ] );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		foreach ( self::PUBLISH_HOOKS as $name ) {
			$hook = \LV2\WordPress\RelatedPostsForWP\Compat\LegacyHooks::get( $name );
			if ( $hook ) {
				$hook->run( 'publish', $old_status, $post );
			}
		}
	}

	/**
	 * Read and link the posts that are still held at the end of the request, for code that publishes a post without
	 * wp_insert_post() (3.x).
	 */
	public function release_all() {
		foreach ( array_keys( $this->held ) as $post_id ) {
			$this->release( $post_id );
		}
	}

	/**
	 * Filter related posts SQL query (2.x)
	 *
	 * @param $sql
	 * @param $post_id
	 * @param $post_type
	 * @param $limit
	 *
	 * @return string
	 */
	public function filter_related_posts_sql( $sql, $post_id, $post_type, $limit ) {
		global $wpdb, $sitepress;

		// Get language of post
		$lang_code = $sitepress->get_language_for_element( $post_id, 'post_' . $post_type );

		// Check lang code
		if ( empty( $lang_code ) ) {
			// No lang code means WPML isn't ready yet. We'll 'sabotage' the query so there will never be any related posts until WPML sets language
			$sql = preg_replace( "/(WHERE 1=1)/m", "$1 AND 1=0 ", $sql );

			return $sql;
		}

		// Add WPML table to JOINS
		$sql = preg_replace( "/(WHERE 1=1)/m", "INNER JOIN `" . $wpdb->prefix . "icl_translations` IT ON IT.`element_id` = R.`post_id` $1", $sql );

		// Add extra WHERE statements
		$sql = $wpdb->prepare( preg_replace( "/(WHERE 1=1)/m", "$1 AND IT.`element_type` = %s AND IT.`language_code` = %s", $sql ), 'post_' . $post_type, $lang_code );

		return $sql;
	}

	/**
	 * Filter ignored words language (2.x)
	 *
	 * @param $lang
	 * @param $post_id
	 *
	 * @return string
	 */
	public function filter_ignored_words( $lang, $post_id ) {

		// Get WPML language information
		$lang_info = apply_filters( 'wpml_post_language_details', null, $post_id );

		// Check and set new locale
		if ( isset( $lang_info['locale'] ) ) {
			$lang = $lang_info['locale'];
		}

		return $lang;
	}

	/**
	 * Force a relink when a translation is inserted (2.x)
	 *
	 * @param $args
	 */
	public function force_relink_on_translation_insert( $args ) {
		// only run on _insert_ of new translation
		if ( 'insert' === $args['type'] ) {

			// get post
			$post = get_post( $args['element_id'] );

			// Check if this post type is installed
			$pt_manager = new RP4WP_Post_Type_Manager();
			if ( ! $pt_manager->is_post_type_installed( $post->post_type ) ) {
				return;
			}

			// Is automatic linking enabled?
			if ( 1 != RP4WP::get()->settings[ 'general_' . $post->post_type ]->get_option( 'automatic_linking' ) ) {
				return;
			}

			// Post Link Manager
			$pl_manager = new RP4WP_Post_Link_Manager();

			// Get automatic linking post amount
			$automatic_linking_post_amount = RP4WP::get()->settings[ 'general_' . $post->post_type ]->get_option( 'automatic_linking_post_amount' );

			// Count already linked posts
			$already_linked_posts = $pl_manager->get_children_count( $post->ID );

			// Subtract already linked post count from posts to link amount
			if ( $already_linked_posts > 0 ) {
				$automatic_linking_post_amount = $automatic_linking_post_amount - $already_linked_posts;
			}

			// Related Posts Manager
			$related_post_manager = new RP4WP_Related_Post_Manager();

			// Link related posts
			$related_post_manager->link_related_post( $post->ID, $post->post_type, $automatic_linking_post_amount );

			// Set the auto linked meta
			update_post_meta( $post->ID, RP4WP_Constants::PM_POST_AUTO_LINKED, 1 );

		}
	}
}

new RP4WP_WPML();
