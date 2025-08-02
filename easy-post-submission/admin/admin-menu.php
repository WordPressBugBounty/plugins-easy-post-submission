<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

/**
 * Class Easy_Post_Submission_Menu
 *
 * This class is responsible for adding the Easy Post Submission plugin menu to the WordPress admin dashboard.
 * It manages the registration of the menu page, enqueues necessary assets, and adds functionality for
 * plugin settings and notifications.
 */
if ( ! class_exists( 'Easy_Post_Submission_Menu', false ) ) {
	class Easy_Post_Submission_Menu {
		private static $instance;

		/**
		 * Gets the instance of the Easy_Post_Submission_Menu class.
		 *
		 * @return Easy_Post_Submission_Menu Instance of Easy_Post_Submission_Menu.
		 */
		public static function get_instance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Easy_Post_Submission_Menu constructor.
		 *
		 * Registers necessary actions and filters to set up the admin menu and settings.
		 */
		private function __construct() {

			self::$instance = $this;

			add_filter( 'display_post_states', [$this, 'post_state'], 10, 2 );
			add_action( 'admin_menu', [ $this, 'register_page_panel' ], 2900 );
			add_filter( 'ruby_dashboard_menu', [ $this, 'dashboard_menu' ], 10, 1 );
			add_filter( 'plugin_action_links', [ $this, 'add_plugin_setting_link' ], 10, 2 );
		}

		/**
		 * Registers the Easy Post Submission plugin page in the WordPress admin menu.
		 *
		 * This method checks if the 'foxiz-core' plugin is active and adds the plugin's menu accordingly.
		 * It also hooks into the page load to load necessary assets.
		 */
		public function register_page_panel() {
			if ( is_plugin_active( 'foxiz-core/foxiz-core.php' ) ) {
				$panel_hook_suffix = add_submenu_page(
					'foxiz-admin',
					esc_html__( 'Easy Submission', 'easy-post-submission' ),
					esc_html__( 'Easy Submission', 'easy-post-submission' ),
					'manage_options',
					'easy-post-submission',
					[ $this, 'easy_post_submission_render_menu_page' ],
					100
				);
			} else {
				$panel_hook_suffix = add_menu_page(
					esc_html__( 'EasySubmission', 'easy-post-submission' ),
					esc_html__( 'EasySubmission', 'easy-post-submission' ),
					'manage_options',
					'easy-post-submission',
					[ $this, 'easy_post_submission_render_menu_page' ],
					'data:image/svg+xml;base64,' . $this->get_plugin_icon(),
					100
				);
			}

			/** load script & css */
			add_action( 'load-' . $panel_hook_suffix, [ $this, 'load_assets' ] );
		}

		/**
		 * Adds a custom post state label 'Via EPS' in the admin post list
		 * if the post has a non-empty 'rbsm_form_id' meta value.
		 *
		 * This helps identify posts that were submitted through the Easy Post Submission (EPS) system.
		 *
		 * @param array   $post_states An array of post state labels.
		 * @param WP_Post $post        The current post object.
		 *
		 * @return array Modified array of post state labels.
		 */
		function post_state( $post_states, $post ) {

			$form_submission_id = get_post_meta( $post->ID, 'rbsm_form_id', true );

			if ( ! empty( $form_submission_id ) ) {
				$post_states['eps'] = esc_html__( 'via EPS', 'easy-post-submission' );
			}

			return $post_states;
		}

		/**
		 * Loads assets (CSS/JS) for the Easy Post Submission admin page.
		 */
		public function load_assets() {
			add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue' ] );
		}

		/**
		 * Adds a settings link to the plugin action links in the WordPress plugins list.
		 *
		 * @param array $links Existing plugin action links.
		 * @param string $file The plugin file path.
		 *
		 * @return array Modified plugin action links.
		 */
		function add_plugin_setting_link( $links, $file ) {
			if ( $file === EASY_POST_SUBMISSION_REL_PATH . '/easy-post-submission.php' && current_user_can( 'manage_options' ) ) {
				$links[] = '<a href="admin.php?page=easy-post-submission">' . esc_html__( 'Settings', 'easy-post-submission' ) . '</a>';
			}

			return $links;
		}

		/**
		 * Enqueues the necessary admin scripts and styles for the Easy Post Submission plugin.
		 * @return void
		 */
		public function admin_enqueue() {
			wp_register_style( 'rbsm-admin-vendor-style', EASY_POST_SUBMISSION_URL . 'assets/vendor/style.min.css', [], EASY_POST_SUBMISSION_VERSION );
			wp_register_script( 'rbsm-admin-vendor', EASY_POST_SUBMISSION_URL . 'assets/vendor/bundle.js', [], EASY_POST_SUBMISSION_VERSION, true );
			wp_register_script(
				'rbsm-admin',
				EASY_POST_SUBMISSION_URL . 'assets/admin/bundle.js',
				[ 'rbsm-admin-vendor' ],
				EASY_POST_SUBMISSION_VERSION,
				true
			);

			wp_localize_script(
				'rbsm-admin',
				'rbAjax',
				[
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'yesSetup'  => get_option( 'easy_post_submission_setup_flag', get_option( '_easy_post_submission_setup_flag' ) ),
					'nonce'     => wp_create_nonce( 'easy-post-submission' ),
					'translate' => easy_post_submission_admin_description_strings(),
					'isRTL'     => is_rtl(),
				]
			);

			wp_enqueue_media();
			wp_enqueue_style( 'rbsm-admin-style', EASY_POST_SUBMISSION_URL . 'assets/admin/style.min.css', [ 'rbsm-admin-vendor-style' ], EASY_POST_SUBMISSION_VERSION );
			wp_enqueue_script( 'rbsm-admin' );
		}

		/**
		 * Modifies the dashboard menu to add a link to the Easy Post Submission plugin.
		 *
		 * @param array $menu Existing dashboard menu items.
		 *
		 * @return array Modified menu with Easy Post Submission link.
		 */
		public function dashboard_menu( $menu ) {
			if ( isset( $menu['more'] ) ) {
				$menu['more']['sub_items']['rbsm'] = [
					'title' => esc_html__( 'Easy Post Submission', 'easy-post-submission' ),
					'icon'  => 'rbi-dash rbi-dash-writing',
					'url'   => admin_url( 'admin.php?page=easy-post-submission' ),
				];
			}

			return $menu;
		}

		/**
		 * Returns the plugin's SVG icon.
		 *
		 * @return string Base64 encoded SVG icon.
		 */
		function get_plugin_icon() {
			return 'PHN2ZyB2aWV3Qm94PSIwIDAgNTEyIDUxMiIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBmaWxsPSIjZjBmNmZjOTkiPjxwYXRoIGQ9Ik00MjEuMDczIDIyMS43MTljLTAuNTc4IDExLjcxOS05LjQ2OSAyNi4xODgtMjMuNzk3IDQwLjA5NHYxODMuMjVjLTAuMDE2IDQuNzE5LTEuODc1IDguNzE5LTUuMDE2IDExLjg0NC0zLjE1NiAzLjA2My03LjI1IDQuODc1LTEyLjA2MyA0LjkwNkg4MS41NThjLTQuNzgxLTAuMDMxLTguODkxLTEuODQ0LTEyLjA0Ny00LjkwNi0zLjE0MS0zLjEyNS00Ljk4NC03LjEyNS01LTExLjg0NFYxNTIuMjE5YzAuMDE2LTQuNzAzIDEuODU5LTguNzE5IDUtMTEuODQ0IDMuMTU2LTMuMDYzIDcuMjY2LTQuODc1IDEyLjA0Ny00LjkwNmgxNTguNjA5YzEyLjgyOC0xNi44NDQgMjcuNzgxLTM0LjA5NCA0NC43MTktNDkuOTA2SDgxLjU1OGMtMTguNzUtMC4wMTYtMzUuOTg0IDcuNTMxLTQ4LjI1IDE5LjU5NC0xMi4zMjggMTIuMDYzLTIwLjAxNiAyOC45MzgtMjAgNDcuMzQ0djI5Mi44NDRjLTAuMDE2IDE4LjQwNiA3LjY3MiAzNS4zMTMgMjAgNDcuMzQ0QzQ1LjU3MyA1MDQuNDY5IDYyLjgwOCA1MTIgODEuNTU4IDUxMmgyOTguNjQxYzE4Ljc4MSAwIDM2LjAxNi03LjUzMSA0OC4yODEtMTkuNTk0IDEyLjI5Ny0xMi4wMzEgMjAtMjguOTM4IDE5Ljk4NC00Ny4zNDRWMjAzLjQ2OWMwIDAtMC4xMjUtMC4xNTYtMC4zMjgtMC4zMTNDNDQwLjM3IDIwOS44MTMgNDMxLjMyMyAyMTYuMTU2IDQyMS4wNzMgMjIxLjcxOXoiPjwvcGF0aD48cGF0aCBkPSJNNDk4LjA1OCAwYzAgMC0xNS42ODggMjMuNDM4LTExOC4xNTYgNTguMTA5QzI3NS40MTcgOTMuNDY5IDIxMS4xMDQgMjM3LjMxMyAyMTEuMTA0IDIzNy4zMTNjLTE1LjQ4NCAyOS40NjktNzYuNjg4IDE1MS45MDYtNzYuNjg4IDE1MS45MDYtMTYuODU5IDMxLjYyNSAxNC4wMzEgNTAuMzEzIDMyLjE1NiAxNy42NTYgMzQuNzM0LTYyLjY4OCA1Ny4xNTYtMTE5Ljk2OSAxMDkuOTY5LTEyMS41OTQgNzcuMDQ3LTIuMzc1IDEyOS43MzQtNjkuNjU2IDExMy4xNTYtNjYuNTMxLTIxLjgxMyA5LjUtNjkuOTA2IDAuNzE5LTQxLjU3OC0zLjY1NiA2OC01LjQ1MyAxMDkuOTA2LTU2LjU2MyA5Ni4yNS02MC4wMzEtMjQuMTA5IDkuMjgxLTQ2LjU5NCAwLjQ2OS01MS0yLjE4OEM1MTMuMzg2IDEzOC4yODEgNDk4LjA1OCAwIDQ5OC4wNTggMHoiPjwvcGF0aD48L3N2Zz4=';
		}

		/**
		 * Renders the menu page for the Easy Post Submission plugin.
		 *
		 * This method is responsible for rendering the settings page of the Easy Post Submission plugin in the WordPress admin
		 * dashboard. It checks if the 'RB_ADMIN_CORE' class exists and includes its header template if so. It then includes
		 * the 'dashboard-template.php' file for rendering the main content of the menu page.
		 *
		 * @return void
		 */
		public static function easy_post_submission_render_menu_page() {
			if ( class_exists( 'RB_ADMIN_CORE' ) ) {
				RB_ADMIN_CORE::get_instance()->header_template();
			}

			include( EASY_POST_SUBMISSION_PATH . 'admin/dashboard-template.php' );
		}
	}
}

/** Init load */
Easy_Post_Submission_Menu::get_instance();
