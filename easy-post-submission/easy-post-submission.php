<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name:       Easy Post Submission
 * Plugin URI:        https://easyps.net/
 * Description:       Enable users to submit posts and manage profiles from the front-end of your site. Ideal for news, magazines, and creative platforms to collect ideas and contributions easily.
 * Tags:              frontend post, guest post, anonymous post, user post, public post
 * Author:            ThemeRuby
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author URI:        https://themeruby.com/
 * Text Domain:       easy-post-submission
 * Domain Path:       /languages
 *
 * @package           easy-post-submission
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or any later version.
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

define( 'EASY_POST_SUBMISSION_VERSION', '2.0.0' );
define( 'EASY_POST_SUBMISSION_BASENAME', plugin_basename( __FILE__ ) );
define( 'EASY_POST_SUBMISSION_URL', plugins_url( '/', __FILE__ ) );
define( 'EASY_POST_SUBMISSION_REL_PATH', dirname( EASY_POST_SUBMISSION_BASENAME ) );
define( 'EASY_POST_SUBMISSION_PATH', plugin_dir_path( __FILE__ ) );
require_once EASY_POST_SUBMISSION_PATH . 'includes/client-ajax-handler.php';

if ( ! class_exists( 'Easy_Post_Submission' ) ) {
	/**
	 * Class Easy_Post_Submission
	 */
	class Easy_Post_Submission {
		private static $instance;

		/**
		 * Disable object cloning.
		 *
		 * @return void
		 */
		public function __clone() {
		}

		/**
		 * Disable unserializing of the class.
		 *
		 * @return void
		 */
		public function __wakeup() {
		}

		/**
		 * Returns the single instance of the Easy_Post_Submission class.
		 *
		 * @return Easy_Post_Submission The single instance of the class.
		 */
		public static function get_instance() {
			if ( self::$instance === null ) {
				return new self();
			}

			return self::$instance;
		}

		/**
		 * Easy_Post_Submission constructor.
		 *
		 * Registers activation, deactivation hooks and loads the necessary plugin files.
		 */
		private function __construct() {

			self::$instance = $this;

			// activation hooks
			register_activation_hook( __FILE__, [ $this, 'activation' ] );
			register_deactivation_hook( __FILE__, [ $this, 'deactivation' ] );

			add_action( 'plugins_loaded', [ $this, 'load_files' ], 1 );
			add_filter( 'query_vars', [ $this, 'query_vars' ] );
		}

		/**
		 * Loads the necessary plugin files based on the context (admin or frontend).
		 *
		 * @return void
		 */
		public function load_files() {
			include_once EASY_POST_SUBMISSION_PATH . 'includes/description-strings.php';
			include_once EASY_POST_SUBMISSION_PATH . 'includes/helpers.php';

			if ( is_admin() ) {
				include_once EASY_POST_SUBMISSION_PATH . 'admin/admin-menu.php';
				require_once EASY_POST_SUBMISSION_PATH . 'admin/ajax-handler.php';
			} else {
				require_once EASY_POST_SUBMISSION_PATH . 'includes/shortcodes.php';
			}
		}

		/**
		 * Adds custom query vars to WordPress.
		 *
		 * @param array $qvars Existing query variables.
		 *
		 * @return array Modified query variables.
		 */
		public function query_vars( $qvars ) {
			$qvars[] = 'rbsm-id';

			return $qvars;
		}

		/**
		 * Handles plugin activation for both single and multisite setups.
		 *
		 * @param bool $network Whether this is a network-wide activation (for multisite).
		 *
		 * @return void
		 */
		public function activation( $network ) {

			if ( is_multisite() && $network ) {

				$sites = get_sites();
				foreach ( $sites as $site ) {
					// change to another site
					switch_to_blog( (int) $site->blog_id );

					// activation process
					$this->activate_site();
					restore_current_blog();
				}
			} else {
				$this->activate_site();
			}
		}

		/**
		 * Handles plugin deactivation, such as cleaning up options.
		 *
		 * @return void
		 */
		public function deactivation() {
			delete_option( 'easy_post_submission_setup_flag' );
		}

		/**
		 * Creates the plugin database table for storing submissions.
		 *
		 * @return void
		 */
		public function activate_site() {
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();

			// required for dbdelta
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rb_submission (
			            `id` INT(11) NOT NULL AUTO_INCREMENT,
			            `title` VARCHAR(50) NOT NULL,
			            `data` TEXT NOT NULL,
			            PRIMARY KEY (`id`)
			        ) {$charset_collate};
			    " );
		}
	}
}

/** INIT */
Easy_Post_Submission::get_instance();