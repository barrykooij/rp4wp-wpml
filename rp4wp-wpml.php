<?php

/*
	Plugin Name: Related Posts for WordPress - WPML Compatibility
	Plugin URI: http://www.relatedpostsforwp.com/wpml-compatibility/
	Description: Add WPML support to Related Posts for WordPress Premium
	Version: 1.0.2
	Author: Barry Kooij
	Author URI: http://www.barrykooij.com/
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


class RP4WP_WPML {

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( $this->check_dependencies() ) {
			$this->setup();
		}
	}

	/**
	 * Dependency RP4WP notice
	 */
	public function dep_notice_rp4wp() {
		?>
		<div id="message" class="error"><p><strong><a
						href="https://www.relatedpostsforwp.com/?utm_source=rp4wp-wpml&utm_medium=link&utm_campaign=notice"
						target="_blank">Related Posts for WordPress Premium</a> 1.3.3 or higher</strong> must be
				installed and activated to use <strong>Related Posts for WordPress - WPML Compatibility</strong>.</p>
		</div><?php
	}

	/**
	 * Dependency WPML notice
	 */
	public function dep_notice_wpml() {
		?>
		<div id="message" class="error"><p><strong>WPML</strong> must be installed and activated to use <strong>Related
					Posts for WordPress - WPML Compatibility</strong>.</p></div><?php
	}

	/**
	 * Check plugin dependencies
	 *
	 * @return bool
	 */
	private function check_dependencies() {

		// Check RP4WP
		if ( ! defined( 'RP4WP_PLUGIN_FILE' ) || ! defined( 'RP4WP_Constants::PREMIUM' ) || true !== RP4WP_Constants::PREMIUM ) {
			add_action( 'admin_notices', array( $this, 'dep_notice_rp4wp' ) );

			return false;
		}

		// Check WPML
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			add_action( 'admin_notices', array( $this, 'dep_notice_wpml' ) );

			return false;
		}

		return true;
	}

	/**
	 * Setup method
	 */
	private function setup() {
		add_filter( 'rp4wp_get_related_posts_sql', array( $this, 'filter_related_posts_sql' ), 10, 4 );
		add_filter( 'rp4wp_ignored_words_lang', array( $this, 'filter_ignored_words' ), 10, 4 );
		add_action( 'wpml_translation_update', array( $this, 'force_relink_on_translation_insert' ), 10, 1 );
	}

	/**
	 * Add language to related posts fetch SQL so we only fetch posts of current post's language
	 *
	 * @param String $sql
	 * @param int $post_id
	 * @param String $post_type
	 * @param int $limit
	 *
	 * @return String
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
	 * Load the ignored words of the post's language
	 *
	 * @param String $lang
	 * @param int $post_id
	 *
	 * @return String
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
	 * We force an automatic relink when the translation is inserted by WPML.
	 * This is needed because WPML does not have the translation ready when the post is published (which is when we look for related posts).
	 *
	 * @param array $args
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

function __rp4wp_wpml_main() {
	new RP4WP_WPML();
}

add_action( 'plugins_loaded', '__rp4wp_wpml_main', 20 );