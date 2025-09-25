<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Easy_Post_Submission_Form_Shortcode', false ) ) {

	/**
	 * Class Easy_Post_Submission_Form_Shortcode
	 *
	 * Handles the rendering of the Easy Post Submission form shortcode and manages localized data for the front-end submission form.
	 * Implements the Singleton design pattern to ensure a single instance is used throughout the application.
	 */
	class Easy_Post_Submission_Form_Shortcode {
		/**
		 * @var
		 */
		private static $instance;

		public static function get_instance() {

			if ( self::$instance === null ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * @var array
		 */
		private static array $initial_localize_data;

		/**
		 * Easy_Post_Submission_Form_Shortcode constructor.
		 *
		 * Private constructor to prevent direct instantiation of the class.
		 * This ensures that the class follows the Singleton pattern and can only be instantiated via the `get_instance` method.
		 */
		private function __construct() {

			self::$instance = $this;

			add_action( 'init', [ $this, 'load' ], 0 );
		}

		/**
		 * Initialize the component by setting up dependencies, registering scripts,
		 * and hooking into WordPress actions/filters.
		 *
		 * - Calls internal setup method.
		 * - Hooks register_scripts into 'wp_enqueue_scripts'.
		 * - Hooks enqueue into 'do_shortcode_tag' with priority 10 and 3 arguments.
		 *
		 * @return void
		 */
		public function load() {

			$this->setup();

			add_action( 'wp_enqueue_scripts', [ $this, 'register_scripts' ], 10 );
			add_filter( 'do_shortcode_tag', [ $this, 'enqueue' ], 10, 3 );
			add_shortcode( 'easy_post_submission_form', [ $this, 'render_post_creation' ] );
			add_shortcode( 'easy_post_submission_manager', [ $this, 'render_post_manager' ] );
			add_shortcode( 'easy_post_submission_edit', [ $this, 'render_edit_submission_form' ] );
		}

		/**
		 * Registers scripts and styles for the plugin.
		 *
		 * This function is used to register all required scripts and styles
		 * necessary for the functionality of the plugin. Scripts and styles
		 * can then be enqueued conditionally where needed.
		 *
		 * @return void
		 */
		public function register_scripts() {

			wp_register_style( 'rbsm-vendor-style', EASY_POST_SUBMISSION_URL . 'assets/vendor/style.min.css', [], EASY_POST_SUBMISSION_VERSION );
			wp_register_style( 'rbsm-client-style', EASY_POST_SUBMISSION_URL . 'assets/client/style.min.css', [ 'rbsm-vendor-style' ], EASY_POST_SUBMISSION_VERSION );

			wp_register_script( 'rbsm-vendor', EASY_POST_SUBMISSION_URL . 'assets/vendor/bundle.js', [], EASY_POST_SUBMISSION_VERSION, true );
			wp_register_script( 'rbsm-client', EASY_POST_SUBMISSION_URL . 'assets/client/bundle.js', [ 'rbsm-vendor' ], EASY_POST_SUBMISSION_VERSION, true );
		}

		/**
		 * Enqueues specific scripts or styles based on the provided tag.
		 *
		 * This function checks the given tag and conditionally modifies the output
		 * for specific tags related to "easy_post_submission_form", "easy_post_submission_manager",
		 * and "easy_post_submission_edit".
		 *
		 * @param mixed $output The original output that might be modified.
		 * @param string $tag The tag to check against specific conditions.
		 * @param array $attr Additional attributes associated with the tag.
		 *
		 * @return mixed The modified output if the tag matches, otherwise the original output.
		 */
		public function enqueue( $output, $tag, $attr ) {

			if ( 'easy_post_submission_form' !== $tag && 'easy_post_submission_manager' !== $tag && 'easy_post_submission_edit' !== $tag ) {
				return $output;
			}

			if ( ! wp_script_is( 'rbsm-client', 'registered' ) ) {
				$this->register_scripts();
			}

			$form_id                              = null;
			self::$initial_localize_data['isRTL'] = is_rtl();

			if ( 'easy_post_submission_form' === $tag ) {
				$form_id = ! empty( $attr['id'] ) ? intval( $attr['id'] ) : null;
			} elseif ( 'easy_post_submission_edit' === $tag ) {

				$post_id = get_query_var( 'rbsm-id', null );

				if ( ! empty( $post_id ) ) {
					$form_id = Easy_Post_Submission_Client_Helper::get_instance()->get_form_id_by_submission( $post_id );

					self::$initial_localize_data['userPost'] = $this->get_user_post( $post_id );
				}
			}

			if ( $form_id ) {
				wp_localize_script( 'rbsm-client', 'rbSubmissionForm', $this->get_submission_form_data( $form_id ) );
			}

			if ( 'easy_post_submission_manager' === $tag ) {
				$user_posts_data = $this->get_user_posts_data();
				wp_localize_script( 'rbsm-client', 'rbsmUserPostsData', $user_posts_data );
			}

			wp_localize_script( 'rbsm-client', 'rbGlobalSubmissionSettings', self::$initial_localize_data );
			wp_enqueue_style( 'rbsm-client-style' );
			wp_enqueue_script( 'rbsm-client' );

			return $output;
		}

		/**
		 * Sets up data for front-end submission.
		 *
		 * This function prepares and returns the necessary configuration and settings
		 * for managing front-end submission features.
		 *
		 * @return array The configuration data for front-end submissions.
		 */
		private function setup() {

			$settings = Easy_Post_Submission_Client_Helper::get_instance()->get_post_manager_settings();

			self::$initial_localize_data = [
				'nonce'               => wp_create_nonce( 'easy-post-submission' ),
				'postManagerSettings' => $settings,
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'loginUrl'            => $this->get_login_url( $settings ),
				'registerURL'         => $this->get_registration_link( $settings ),
				'translate'           => easy_post_submission_description_strings(),
			];
		}

		/**
		 * Retrieves the login URL for the front-end.
		 *
		 * This function uses the provided post manager settings to construct
		 * and return the appropriate login URL for users.
		 *
		 * @param array $post_manager_settings The settings array containing login-related configurations.
		 *
		 * @return string The login URL.
		 */
		private function get_login_url( $post_manager_settings ) {
			return ! empty( $post_manager_settings['custom_login_and_registration']['custom_login_link'] )
				? esc_url( $post_manager_settings['custom_login_and_registration']['custom_login_link'] )
				: wp_login_url();
		}

		/**
		 * Retrieves the registration URL for the front-end.
		 *
		 * This function uses the provided post manager settings to construct
		 * and return the appropriate registration URL for users.
		 *
		 * @param array $post_manager_settings The settings array containing registration-related configurations.
		 *
		 * @return string The registration URL.
		 */
		private function get_registration_link( $post_manager_settings ) {
			return ! empty( $post_manager_settings['custom_login_and_registration']['custom_registration_link'] )
				? esc_url( $post_manager_settings['custom_login_and_registration']['custom_registration_link'] )
				: ( get_option( 'users_can_register' ) ? wp_registration_url() : '' );
		}

		/**
		 * Renders the HTML wrapper for the post manager.
		 *
		 * This function generates and returns the HTML structure for the post manager
		 * based on the provided attributes. The function will not execute in the admin area.
		 *
		 * @param array $attr An associative array of attributes passed to configure the post manager display.
		 *
		 * @return string The HTML markup for the post manager.
		 */
		public function render_post_manager( $attr ) {

			if ( is_admin() ) {
				return '<div class="rbsm-admin-placeholder"><h4>' . esc_html__( 'Easy Post Submission Post Manager Placeholder', 'easy-post-submission' ) . '</h4></div>';
			}

			return '<div id="rbsm-user-posts" class="rbsm-container"></div>';
		}

		/**
		 * Renders the HTML wrapper for the post creation form.
		 *
		 * This function generates the HTML structure for the post creation form. If accessed from the admin area
		 * within the Elementor editor, it displays a placeholder instead.
		 *
		 * @param array $attr An associative array of attributes to configure the post creation display.
		 *
		 * @return string The HTML markup for the post creation form or a placeholder in the admin area.
		 */
		public function render_post_creation( $attr ) {

			if ( is_admin() ) {
				return '<div class="rbsm-admin-placeholder"><h4>' . esc_html__( 'Easy Post Submission Form Placeholder', 'easy-post-submission' ) . '</h4></div>';
			}

			return '<div id="rbsm-form-shortcode" class="rbsm-container"></div>';
		}

		/**
		 * Renders the HTML for the edit submission form.
		 *
		 * This function generates the HTML structure for editing a submission. It should be used when rendering the
		 * form for users to modify an existing submission.
		 *
		 * @return string The HTML markup for the edit submission form.
		 */
		public function render_edit_submission_form() {

			if ( is_admin() ) {
				return '<div class="rbsm-admin-placeholder"><h4>' . esc_html__( 'Easy Post Submission Edit Post Form Placeholder', 'easy-post-submission' ) . '</h4></div>';
			}

			if ( ! is_user_logged_in() ) {
				return $this->get_login_notice();
			}

			$post_id = get_query_var( 'rbsm-id', '' );

			if ( empty( $post_id ) ) {
				return $this->get_error_box( [
					'icon'  => 'mdi-note-off-outline',
					'title' => esc_html__( 'Post Not Found', 'easy-post-submission' ),
					'desc'  => esc_html__( 'The post submission you are trying to edit does not exist. If you believe this is an error, please contact support for assistance.', 'easy-post-submission' ),
				] );
			}

			if ( ! $this->get_post_belong_current_user( $post_id ) ) {
				return $this->get_error_box( [
					'icon'  => 'mdi-shield-off-outline',
					'title' => esc_html__( 'Permission Denied', 'easy-post-submission' ),
					'desc'  => esc_html__( 'You do not have permission to edit this submission. If you believe this is an error, please contact support for assistance.', 'easy-post-submission' ),
				] );
			}

			$form_submission_id = Easy_Post_Submission_Client_Helper::get_instance()->get_form_id_by_submission( $post_id );

			if ( empty( $form_submission_id ) ) {
				return $this->get_error_box( [
					'icon'  => 'mdi-database-off-outline',
					'title' => esc_html__( 'Data Error', 'easy-post-submission' ),
					'desc'  => esc_html__( 'We could not find the form associated with this post. Please reach out to support for further assistance.', 'easy-post-submission' ),
				] );
			}

			return '<div id="rbsm-post-editing" class="rbsm-container"></div>';
		}

		/**
		 * Generate an error box with customizable icon, title, and description.
		 *
		 * This function generates an HTML block representing an error or informational box,
		 * using the provided settings for the icon, title, and description.
		 * Default values are used if any settings are missing.
		 *
		 * @param array $settings Associative array containing settings for the error box.
		 *                        Expected keys: 'icon', 'title', and 'desc'.
		 *                        - 'icon' (string): The icon class (default: 'mdi-information-outline').
		 *                        - 'title' (string): The title text (default: 'Information').
		 *                        - 'desc' (string): The description text (default: empty string).
		 *
		 * @return string The generated HTML output for the error box.
		 */
		public function get_error_box( $settings ) {

			$icon        = ! empty( $settings['icon'] ) ? $settings['icon'] : 'mdi-information-outline';
			$title       = ! empty( $settings['title'] ) ? $settings['title'] : esc_html__( 'Information', 'easy-post-submission' );
			$description = ! empty( $settings['desc'] ) ? $settings['desc'] : '';

			$output = '<div class="rbsm-table-empty">';
			$output .= '<i class="' . esc_attr( $icon ) . ' mdi v-icon notranslate v-theme--light v-icon--size-default" aria-hidden="true"></i>';
			$output .= '<h3 class="rbsm-table-empty-title">' . esc_html( $title ) . '</h3>';
			$output .= '<p class="rbsm-table-empty-desc">' . esc_html( $description ) . '</p>';
			$output .= '</div>';

			return $output;
		}

		/**
		 * Retrieves the HTML for the login notice.
		 *
		 * This function generates the HTML markup for displaying a login notice.
		 * It can be used to inform users that they need to log in to perform a specific action.
		 *
		 * @return string The HTML markup for the login notice.
		 */
		private function get_login_notice() {

			return '<div id="rbsm-login" class="rbsm-container"></div>';
		}

		/**
		 * Checks if a post belongs to the currently logged-in user.
		 *
		 * This function verifies whether a post, identified by its post ID, belongs to the currently authenticated user.
		 * It can be used to ensure that users can only edit or view their own posts.
		 *
		 * @param int $post_id The ID of the post to check.
		 *
		 * @return bool Returns true if the post belongs to the current user, otherwise false.
		 */
		private function get_post_belong_current_user( $post_id ) {

			$post = get_post( $post_id );

			$result = is_a( $post, 'WP_Post' ) && ( current_user_can( 'edit_post', $post_id ) || get_current_user_id() === (int) $post->post_author );

			if ( ! $result ) {
				return false;
			}

			return $post;
		}

		/**
		 * Retrieves data related to a submission form based on the provided form ID.
		 *
		 * This function fetches various details about a submission form, including whether the user is logged in,
		 * any potential errors, and other form-related data.
		 *
		 * @param int $form_id The ID of the form to retrieve data for.
		 *
		 * @return array An associative array containing the following keys:
		 *               - 'hasError' (bool): Indicates whether there was an error fetching the form data.
		 *               - 'errorMessage' (string): Any error message related to the form data retrieval.
		 *               - 'formId' (int): The ID of the form.
		 *               - 'isUserLogged' (bool): Whether the user is logged in.
		 *               - and more...
		 */
		private function get_submission_form_data( $form_id ) {

			$result['hasError']     = false;
			$result['errorMessage'] = '';
			$result['formId']       = $form_id;
			$result['isUserLogged'] = is_user_logged_in();

			$helper        = Easy_Post_Submission_Client_Helper::get_instance();
			$form_settings = $helper->get_raw_submission_form_settings( $form_id );


			if ( empty( $form_settings ) || empty( $form_settings->data ) ) {
				$result['hasError']     = true;
				$result['errorMessage'] = esc_html__( 'Unable to locate the submission form settings.', 'easy-post-submission' );

				return $result;
			}

			$data                   = $helper->filter_settings( json_decode( $form_settings->data, true ) );
			$form_settings->data    = wp_json_encode( $data );
			$result['categories']   = $helper->get_categories( $data );
			$result['tags']         = $helper->get_tags( $data );
			$result['formSettings'] = $form_settings;

			return $result;
		}

		/**
		 * Retrieves data related to the current user's posts.
		 *
		 * This function fetches various information about the posts associated with the current user.
		 * The data returned may include post details or other related metadata.
		 *
		 * @return array An associative array containing post-related data for the current user.
		 */
		private function get_user_posts_data() {

			return [
				'isUserLogged'        => is_user_logged_in(),
				'userPostsData'       => Easy_Post_Submission_Client_Helper::get_instance()->get_user_posts_data(),
				'postManagerSettings' => self::$initial_localize_data['postManagerSettings']
			];
		}

		/**
		 * Retrieves a user's post based on the provided post ID.
		 *
		 * This function checks if the given post ID belongs to the current user.
		 * If the post exists and belongs to the user, it retrieves the post's title and excerpt.
		 * If the post doesn't belong to the current user or doesn't exist, it returns an empty array.
		 *
		 * @param int $post_id The ID of the post to retrieve.
		 *
		 * @return array An associative array containing post data such as the title and excerpt,
		 *               or an empty array if the post is not found or doesn't belong to the current user.
		 */
		private function get_user_post( $post_id ) {

			$data = $this->get_post_belong_current_user( $post_id );
			if ( ! $data || ! is_a( $data, 'WP_Post' ) ) {
				return [];
			}

			$title               = $data->post_title;
			$excerpt             = $data->post_excerpt;
			$content             = $data->post_content;
			$content             = preg_replace( '/<!--.*?-->/s', '', $content );
			$content             = preg_replace( '/[\r\n]+/', '', $content );
			$categories_raw      = get_the_category( $post_id );
			$tags_raw            = get_the_tags( $post_id );
			$featured_image      = get_the_post_thumbnail( $data, 'medium' );
			$featured_image_size = $this->get_featured_filesize( $post_id );

			$categories = [];
			if ( $categories_raw ) {
				$categories = array_map( function ( $category ) {

					return $category->term_id;
				}, $categories_raw );
			}

			$tags = [];
			if ( $tags_raw ) {
				$tags = array_map( function ( $tag ) {

					return $tag->name;
				}, $tags_raw );
			}

			$form_submission_id     = Easy_Post_Submission_Client_Helper::get_instance()->get_form_id_by_submission( $post_id );
			$custom_field_meta_keys = $this->get_custom_field_meta_keys( $form_submission_id );
			$custom_fields          = $this->get_custom_field_data( $post_id, $custom_field_meta_keys );
			$user_name              = $this->get_user_name( $post_id );
			$user_email             = $this->get_user_email( $post_id );

			$user_post[] = [
				'title'               => $title,
				'excerpt'             => $excerpt,
				'content'             => $content,
				'categories'          => $categories,
				'tags'                => $tags,
				'featured_image'      => $featured_image,
				'featured_image_size' => $featured_image_size,
				'post_id'             => $post_id,
				'custom_fields'       => $custom_fields,
				'user_name'           => $user_name,
				'user_email'          => $user_email,
			];

			return $user_post;
		}

		/**
		 * Retrieves the user name associated with a specific post.
		 *
		 * This function retrieves the 'rbsm_author_info' meta data associated with a post
		 * and returns the user's name if available. If the user name is not set, an empty string is returned.
		 *
		 * @param int $user_post_id The ID of the post for which to retrieve the user name.
		 *
		 * @return string The user name associated with the post, or an empty string if no user name is found.
		 */
		private function get_user_name( $user_post_id ) {

			$author_info = get_post_meta( $user_post_id, 'rbsm_author_info', true );

			return ! empty( $author_info['user_name'] ) ? $author_info['user_name'] : '';
		}

		/**
		 * Retrieves the user email associated with a specific post.
		 *
		 * This function retrieves the 'rbsm_author_info' meta data associated with a post
		 * and returns the user's email if available. If the user email is not set, an empty string is returned.
		 *
		 * @param int $user_post_id The ID of the post for which to retrieve the user email.
		 *
		 * @return string The user email associated with the post, or an empty string if no user email is found.
		 */
		private function get_user_email( $user_post_id ) {

			$author_info = get_post_meta( $user_post_id, 'rbsm_author_info', true );

			return ! empty( $author_info['user_email'] ) ? $author_info['user_email'] : '';
		}

		/**
		 * Retrieves the file size of the featured image associated with a post.
		 *
		 * This function checks if the post has a featured image. If a featured image is present,
		 * it retrieves the file size of the image. If the image is not set or the file cannot
		 * be accessed, it returns 0. The function uses `filesize` to obtain the size of the file
		 * in bytes.
		 *
		 * @param int $post_id The ID of the post for which to retrieve the featured image file size.
		 *
		 * @return false|int The file size of the featured image in bytes, or false if the file does not exist.
		 */
		private function get_featured_filesize( $post_id ) {

			$file_size    = 0;
			$thumbnail_id = get_post_thumbnail_id( $post_id );
			if ( $thumbnail_id ) {
				$file_path = get_attached_file( $thumbnail_id );
				if ( $file_path && file_exists( $file_path ) && function_exists( 'filesize' ) ) {
					$file_size = filesize( $file_path );
				}
			}

			return $file_size;
		}

		/**
		 * Retrieves the custom field data for a given post.
		 *
		 * This function accepts a post ID and an array of custom field meta keys, then fetches
		 * the corresponding custom field data for the post. It returns an associative array where
		 * the keys are the provided custom field meta keys and the values are the custom field values.
		 * If no custom field data is found for a given key, the key will not be included in the result.
		 *
		 * @param int $user_post_id The ID of the post for which to retrieve custom field data.
		 * @param array $custom_field_meta_keys An array of custom field meta keys to retrieve values for.
		 *
		 * @return array An associative array of custom field data, where the keys are the meta keys
		 *               and the values are the corresponding custom field values.
		 */
		private function get_custom_field_data( $user_post_id, $custom_field_meta_keys ) {

			$custom_fields = [];

			foreach ( $custom_field_meta_keys as $meta_key ) {
				$custom_field = get_post_meta( $user_post_id, $meta_key, true );
				if ( ! empty( $custom_field ) ) {
					$custom_fields[] = $custom_field;
				}
			}

			return $custom_fields;
		}

		/**
		 * Retrieves the custom field meta keys for a given form submission.
		 *
		 * This function queries the database to retrieve the custom field data associated with a specific form
		 * submission, using the provided form submission ID. It returns an array containing the meta keys or
		 * an empty array if no data is found. If an error occurs, a boolean `false` is returned.
		 *
		 * @param int $form_submission_id The ID of the form submission to fetch custom field meta keys for.
		 *
		 * @return array|bool[]|string[] An array of custom field meta keys and values, or an empty array if no data is found.
		 *                               Returns `false` if an error occurs during the database query.
		 */
		private function get_custom_field_meta_keys( $form_submission_id ) {

			global $wpdb;

			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT data FROM {$wpdb->prefix}rb_submission WHERE id = %d",
					$form_submission_id
				)
			);

			if ( empty( $row->data ) ) {
				return [];
			}

			$data = json_decode( $row->data );
			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data->form_fields->custom_field ) ) {
				return [];
			}

			$custom_fields = $data->form_fields->custom_field;
			if ( ! is_array( $custom_fields ) ) {
				return [];
			}

			$meta_keys = array_map( function ( $custom_field ) {
				return isset( $custom_field->custom_field_name ) ? $custom_field->custom_field_name : '';
			}, $custom_fields );

			return array_filter( $meta_keys );
		}
	}
}

/** load */

Easy_Post_Submission_Form_Shortcode::get_instance();
