<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Easy_Post_Submission_Admin_Ajax_Handler', false ) ) {
	/**
	 * Class Easy_Post_Submission_Admin_Ajax_Handler
	 *
	 * This class handles various AJAX requests for the Easy Post Submission plugin.
	 * It processes setup wizard steps, form submission, category and tag retrieval,
	 * post manager updates, and more through WordPress AJAX actions.
	 */
	class Easy_Post_Submission_Admin_Ajax_Handler {
		private static $instance;
		private static $manager_key = 'easy_post_submission_post_manager_settings';
		private static $nonce = 'easy-post-submission';

		/**
		 * Get the instance of the Easy_Post_Submission_Admin_Ajax_Handler class.
		 *
		 * This method implements the Singleton design pattern to ensure there is only one instance
		 * of the Easy_Post_Submission_Admin_Ajax_Handler class.
		 *
		 * @return Easy_Post_Submission_Admin_Ajax_Handler
		 */
		public static function get_instance() {

			if ( self::$instance === null ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Easy_Post_Submission_Admin_Ajax_Handler constructor.
		 *
		 * This constructor hooks the necessary AJAX actions to their respective handler methods.
		 * These actions are triggered for various functionalities like form submission, page setup, etc.
		 */
		public function __construct() {

			self::$instance = $this;

			add_action( 'wp_ajax_rbsm_setup', [ $this, 'setup_wizard' ] );
			add_action( 'wp_ajax_rbsm_submit_form', [ $this, 'submit_form' ] );
			add_action( 'wp_ajax_rbsm_get_forms', [ $this, 'get_forms' ] );
			add_action( 'wp_ajax_rbsm_update_form', [ $this, 'update_form' ] );
			add_action( 'wp_ajax_rbsm_delete_form', [ $this, 'delete_form' ] );
			add_action( 'wp_ajax_rbsm_get_authors', [ $this, 'get_authors' ] );
			add_action( 'wp_ajax_rbsm_admin_get_categories', [ $this, 'admin_get_categories' ] );
			add_action( 'wp_ajax_rbsm_admin_get_tags', [ $this, 'admin_get_tags' ] );
			add_action( 'wp_ajax_rbsm_restore_data', [ $this, 'restore_data' ] );
			add_action( 'wp_ajax_rbsm_get_post_manager', [ $this, 'get_post_manager' ] );
			add_action( 'wp_ajax_rbsm_update_post_manager', [ $this, 'update_post_manager' ] );
		}

		/**
         * Sanitizes Value
         *
         * @param type $value
         * @param type $sanitize_type
         * @return string
         *
         * @since 1.0.0
         */
		private function sanitize_value( $value = '', $sanitize_type = 'text' ) {
            switch( $sanitize_type ) {
                case 'html':
                    return wp_kses_post($value);
                    break;
                default:
                    return sanitize_text_field( $value );
                    break;
            }
        }

		/**
         * Sanitize values in a multidimensional array.
         * @param array $array
         * @param array $sanitize_rule
         * @return array
         *
         * @since 1.0.0
         */
        private function sanitize_array( $array = [], $sanitize_rule = [] ) {
            if ( !is_array( $array ) || count( $array ) == 0 ) {
                return array();
            }

            foreach ( $array as $key => $value ) {
                if ( !is_array( $value ) ) {
                    $sanitize_type = isset( $sanitize_rule[$key] ) ? $sanitize_rule[$key] : 'text';
                    $array[$key] = $this->sanitize_value( $value, $sanitize_type );
                }

                if ( is_array( $value ) ) {
                    $array[$key] = $this->sanitize_array( $value, $sanitize_rule );
                }
            }

            return $array;
        }

		/**
		 * Validate the user permissions before executing AJAX actions.
		 *
		 * This method verify the request's authenticity and ensures the user
		 * has the required permissions to perform the action.
		 *
		 * @return void
		 */
		public function validate_permission() {

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( esc_html__( 'You are not allowed to access this feature.', 'easy-post-submission' ) );
				wp_die();
			}
		}

		/**
		 * Handles the setup wizard for the Easy Post Submission plugin.
		 *
		 * This method processes the setup wizard steps, including form creation, and generating
		 * submit, profile, and edit pages. It also returns the results of the setup process.
		 *
		 * @return void
		 */
		public function setup_wizard() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			$create_form         = isset( $_POST['createForm'] ) && 'true' === $_POST['createForm'];
			$create_submit_page  = isset( $_POST['createSubmitPage'] ) && 'true' === $_POST['createSubmitPage'];
			$create_profile_page = isset( $_POST['createProfilePage'] ) && 'true' === $_POST['createProfilePage'];
			$create_edit_page    = isset( $_POST['createEditPage'] ) && 'true' === $_POST['createEditPage'];

			$submit_page_result  = true;
			$profile_page_result = true;
			$edit_page_result    = true;
			$form_id             = 1;

			if ( $create_form ) {
				$form_id = $this->create_form( wp_generate_password( 8, false ), wp_json_encode( $this->get_default_form() ) );
			}

			if ( $create_submit_page ) {
				$submit_page        = array(
					'post_title'   => esc_html__( 'Submit a Post', 'easy-post-submission' ),
					'post_content' => '<!-- wp:shortcode -->[easy_post_submission_form id=' . $form_id . ']<!-- /wp:shortcode -->',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				);
				$submit_page_result = wp_insert_post( $submit_page );
			}

			if ( $create_profile_page ) {
				$profile_page        = array(
					'post_title'   => esc_html__( 'Review and Manage Your Posts', 'easy-post-submission' ),
					'post_content' => '<!-- wp:shortcode -->[easy_post_submission_manager]<!-- /wp:shortcode -->',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				);
				$profile_page_result = wp_insert_post( $profile_page );
			}

			if ( $create_edit_page ) {
				$edit_page = array(
					'post_title'   => esc_html__( 'Edit Your Submission', 'easy-post-submission' ),
					'post_content' => '<!-- wp:shortcode -->[easy_post_submission_edit]<!-- /wp:shortcode -->',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				);

				$edit_page_result = wp_insert_post( $edit_page );

				/** change edit link $edit_url */
				$manager_settings = get_option( self::$manager_key, [] );

				$manager_settings['edit_post_form']['edit_post_url'] = ! empty( $edit_page_result ) ? get_permalink( $edit_page_result ) : $manager_settings['edit_post_form']['edit_post_url'];
				update_option( self::$manager_key, $manager_settings );
			}

			update_option( 'easy_post_submission_setup_flag', 1 );

			if ( ! is_wp_error( $submit_page_result ) && ! is_wp_error( $profile_page_result ) && ! is_wp_error( $edit_page_result ) ) {
				wp_send_json_success();
			} else {
				wp_send_json_error( esc_html__( 'Could not create the pages', 'easy-post-submission' ) );
			}

			wp_die();
		}

		/**
		 * Save the new form into the database.
		 *
		 * This function validates the user's permission, checks if the necessary data is provided,
		 * and then processes the form data to save it into the database. If no data is received,
		 * an error is returned in JSON format.
		 *
		 * @return void This function does not return a value, but will send a JSON response on success or failure.
		 * @throws WP_Error If the user does not have permission or if there is an error in processing the data.
		 *
		 * @since 1.0.0
		 *
		 */
		public function submit_form() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			if ( ! isset( $_POST['data'] ) ) {
				wp_send_json_error( esc_html__( 'No data received to save. Please try again.', 'easy-post-submission' ) );
				wp_die();
			}

			$data = json_decode( sanitize_textarea_field( wp_unslash( $_POST['data'] ) ), true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error( esc_html__( 'Data invalid.', 'easy-post-submission' ) );
				wp_die();
			}

			$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
			$data  = isset( $data['data'] ) ? wp_json_encode( $data['data'] ) : '';

			if ( json_last_error() !== JSON_ERROR_NONE || empty( $title ) || empty( $data ) ) {
				wp_send_json_error( esc_html__( 'Title or data is missing.', 'easy-post-submission' ) );
				wp_die();
			}

			if ( $this->check_title_exist( $title ) ) {
				wp_send_json_error( esc_html__( 'The form title already exists. Please choose a different title.', 'easy-post-submission' ) );
				wp_die();
			}

			// Create new form
			$result = $this->create_form( $title, $data );

			if ( $result ) {
				wp_send_json_success( esc_html__( 'Save successfully!', 'easy-post-submission' ) );
			} else {
				wp_send_json_error( esc_html__( 'Failed to save to the database. Please temporarily deactivate the plugin and activate it again.', 'easy-post-submission' ) );
			}

			wp_die();
		}

		/**
		 * Create form data.
		 *
		 * This function is used to create form data by validating the provided data and saving it to the database.
		 * It returns the result of the database insertion or a boolean indicating success or failure.
		 *
		 * @param string $title The title of the form.
		 * @param string $data The form data to be saved.
		 *
		 * @return bool|int|mysqli_result Returns a boolean indicating success (`true`), the inserted record's ID (`int`),
		 * or a `mysqli_result` object on success. Returns `false` on failure.
		 */
		private function create_form( $title = '', $data = '' ) {

			global $wpdb;

			$data_before_save_validate = $this->validate_data_before_saving( $data );
			if ( ! $data_before_save_validate['status'] ) {
				wp_send_json_error( $data_before_save_validate['message'] );
				wp_die();
			}

			$data_after_sanitize = wp_json_encode( $data_before_save_validate['data'] );

			$result = $wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}rb_submission (title, data) VALUES (%s, %s)",
					$title,
					$data_after_sanitize
				) );

			if ( $result ) {
				return $wpdb->insert_id;
			}

			return false;
		}

		/**
		 * Check if a form title already exists in the database.
		 *
		 * This function checks whether a given form title already exists in the 'rb_submission' table.
		 * It returns `true` if the title exists, or `false` if it does not.
		 *
		 * @param string $title The title to check for existence in the database.
		 *
		 * @return bool Returns `true` if the title exists, `false` otherwise.
		 */
		private function check_title_exist( $title ) {
			global $wpdb;

			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rb_submission WHERE title = %s", $title ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		/**
		 * Get all forms from the database.
		 *
		 * This function retrieves all forms stored in the 'rb_submission' table from the database.
		 * It returns a JSON response with the list of forms if found, or an error message if no records exist.
		 *
		 * @return void This function does not return a value but sends a JSON response on success or failure.
		 * @since 1.0.0
		 *
		 */
		public function get_forms() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			global $wpdb;

			$result = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}rb_submission" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $result ) {
				wp_send_json_success( $result );
			} else {
				wp_send_json_error( esc_html__( 'No records found', 'easy-post-submission' ) );
			}

			wp_die();
		}

		/**
		 * Validate data of form settings before saving it into the database.
		 *
		 * This function validates the form settings data by decoding the provided JSON string and checking for errors.
		 * It ensures that the necessary fields are present and returns a validation status along with a message.
		 *
		 * @param string $data The form data to validate, provided as a JSON string.
		 *
		 * @return array Returns an associative array with 'status' (boolean) and 'message' (string).
		 *               'status' is `true` if the data is valid, and `false` with an error message if invalid.
		 */
		private function validate_data_before_saving( $data ) {

			$data_object = json_decode( $data, true );
			$new_data    = [];

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Invalid data', 'easy-post-submission' ),
				];
			}

			$general_settings = $data_object['general_setting'] ?? null;
			$user_login       = $data_object['user_login'] ?? null;
			$form_fields      = $data_object['form_fields'] ?? null;
			$security_fields  = $data_object['security_fields'] ?? null;
			$email            = $data_object['email'] ?? null;

			if ( empty( $general_settings ) || empty( $user_login ) || empty( $form_fields ) || empty( $security_fields ) || empty( $email ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is missing some fields!', 'easy-post-submission' ),
				];
			}

			// validate general_setting data
			$post_status      = isset( $general_settings['post_status'] ) ? sanitize_text_field( $general_settings['post_status'] ) : null;
			$url_direction    = isset( $general_settings['url_direction'] ) ? $this->validate_url( sanitize_url( $general_settings['url_direction'] ) ) : null;
			$unique_title     = isset( $general_settings['unique_title'] ) ? (bool) $general_settings['unique_title'] : null;
			$form_layout_type = isset( $general_settings['form_layout_type'] ) ? sanitize_text_field( $general_settings['form_layout_type'] ) : null;

			if ( is_null( $post_status ) || is_null( $url_direction ) || is_null( $unique_title ) || is_null( $form_layout_type ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'General setting data is invalid.', 'easy-post-submission' ),
				];
			}

			$new_data['general_setting'] = [
				'post_status'      => $post_status,
				'url_direction'    => $url_direction,
				'unique_title'     => $unique_title,
				'form_layout_type' => $form_layout_type
			];

			// validate user_login data
			$author_access             = isset( $user_login['author_access'] ) ? sanitize_text_field( $user_login['author_access'] ) : null;
			$assign_author             = isset( $user_login['assign_author'] ) ? sanitize_text_field( $user_login['assign_author'] ) : null;
			$assign_author_id          = isset( $user_login['assign_author_id'] ) ? (bool) $user_login['assign_author_id'] : null;
			$login_type                = isset( $user_login['login_type']['type'] ) ? sanitize_text_field( $user_login['login_type']['type'] ) : null;
			$login_message             = isset( $user_login['login_type']['login_message'] ) ? sanitize_text_field( $user_login['login_type']['login_message'] ) : null;
			$required_login_title      = isset( $user_login['login_type']['required_login_title'] ) ? sanitize_text_field( $user_login['login_type']['required_login_title'] ) : null;
			$required_login_title_desc = isset( $user_login['login_type']['required_login_title_desc'] ) ? sanitize_text_field( $user_login['login_type']['required_login_title_desc'] ) : null;

			if (
				is_null( $author_access ) || is_null( $assign_author ) || is_null( $assign_author_id ) || is_null( $login_type ) || is_null( $login_message )
				|| is_null( $required_login_title ) || is_null( $required_login_title_desc )
			) {
				return [
					'status'  => false,
					'message' => esc_html__( 'User login setting data is invalid.', 'easy-post-submission' ),
				];
			}

			$new_data['user_login'] = [
				'author_access'    => $author_access,
				'assign_author'    => $assign_author,
				'assign_author_id' => $assign_author_id,
				'login_type'       => [
					'type'                      => $login_type,
					'login_message'             => $login_message,
					'required_login_title'      => $required_login_title,
					'required_login_title_desc' => $required_login_title_desc,
				],
			];

			// validate form_fields data
			$user_name             = isset( $form_fields['user_name'] ) ? sanitize_text_field( $form_fields['user_name'] ) : null;
			$user_email            = isset( $form_fields['user_email'] ) ? sanitize_text_field( $form_fields['user_email'] ) : null;
			$post_title            = isset( $form_fields['post_title'] ) ? sanitize_text_field( $form_fields['post_title'] ) : null;
			$tagline               = isset( $form_fields['tagline'] ) ? sanitize_text_field( $form_fields['tagline'] ) : null;
			$editor_type           = isset( $form_fields['editor_type'] ) ? sanitize_text_field( $form_fields['editor_type'] ) : null;
			$max_images            = isset( $form_fields['max_images'] ) ? absint( $form_fields['max_images'] ) : null;
			$max_image_size        = isset( $form_fields['max_image_size'] ) ? absint( $form_fields['max_image_size'] ) : null;
			$featured_image_status = isset( $form_fields['featured_image']['status'] ) ? sanitize_text_field( $form_fields['featured_image']['status'] ) : null;

			$upload_file_size_limit = isset( $form_fields['featured_image']['upload_file_size_limit'] )
				? absint( $form_fields['featured_image']['upload_file_size_limit'] )
				: null;

			$default_featured_image = isset( $form_fields['featured_image']['default_featured_image'] )
				? sanitize_text_field( $form_fields['featured_image']['default_featured_image'] )
				: null;

			$categories_multi = filter_var( $form_fields['categories']['multiple_categories'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			$exclude_categories = isset( $form_fields['categories']['exclude_categories'] )
				? array_map( 'sanitize_text_field', $form_fields['categories']['exclude_categories'] )
				: null;

			$exclude_category_ids = isset( $form_fields['categories']['exclude_category_ids'] )
				? array_map( 'sanitize_text_field', $form_fields['categories']['exclude_category_ids'] )
				: null;

			$auto_assign_categories = isset( $form_fields['categories']['auto_assign_categories'] )
				? array_map( 'sanitize_text_field', $form_fields['categories']['auto_assign_categories'] )
				: null;

			$auto_assign_category_ids = isset( $form_fields['categories']['auto_assign_category_ids'] )
				? array_map( 'sanitize_text_field', $form_fields['categories']['auto_assign_category_ids'] )
				: null;

			$tags_multi   = filter_var( $form_fields['tags']['multiple_tags'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$tags_add_new = filter_var( $form_fields['tags']['allow_add_new_tag'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			$exclude_tags = isset( $form_fields['tags']['exclude_tags'] )
				? array_map( 'sanitize_text_field', $form_fields['tags']['exclude_tags'] )
				: null;

			$exclude_tag_ids = isset( $form_fields['tags']['exclude_tag_ids'] )
				? array_map( 'sanitize_text_field', $form_fields['tags']['exclude_tag_ids'] )
				: null;

			$auto_assign_tags = isset( $form_fields['tags']['auto_assign_tags'] )
				? array_map( 'sanitize_text_field', $form_fields['tags']['auto_assign_tags'] )
				: null;

			$auto_assign_tag_ids = isset( $form_fields['tags']['auto_assign_tag_ids'] )
				? array_map( 'sanitize_text_field', $form_fields['tags']['auto_assign_tag_ids'] )
				: null;

			$is_valid_custom_field = $this->validate_custom_field( $form_fields )['status'];
			$custom_field          = $this->validate_custom_field( $form_fields )['data'];

			if (
				is_null( $user_name ) || is_null( $user_email ) || is_null( $post_title ) || is_null( $tagline ) || is_null( $editor_type )
				|| is_null( $max_images ) || is_null( $max_image_size )
				|| is_null( $featured_image_status ) || is_null( $upload_file_size_limit )
				|| is_null( $default_featured_image ) || is_null( $categories_multi ) || is_null( $exclude_categories )
				|| is_null( $auto_assign_categories ) || is_null( $tags_multi ) || is_null( $tags_add_new ) || is_null( $exclude_tags )
				|| is_null( $auto_assign_tags ) || is_null( $exclude_category_ids ) || is_null( $auto_assign_category_ids ) || is_null( $exclude_tag_ids )
				|| is_null( $auto_assign_tag_ids ) || ! $is_valid_custom_field
			) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Form fields setting data is invalid.', 'easy-post-submission' ),
				];
			}

			$new_data['form_fields'] = [
				'user_name'      => $user_name,
				'user_email'     => $user_email,
				'post_title'     => $post_title,
				'tagline'        => $tagline,
				'editor_type'    => $editor_type,
				'max_images'     => $max_images,
				'max_image_size' => $max_image_size,
				'featured_image' => [
					'status'                 => $featured_image_status,
					'upload_file_size_limit' => $upload_file_size_limit,
					'default_featured_image' => $default_featured_image,
				],
				'categories'     => [
					'multiple_categories'      => $categories_multi,
					'exclude_categories'       => $exclude_categories,
					'exclude_category_ids'     => $exclude_category_ids,
					'auto_assign_categories'   => $auto_assign_categories,
					'auto_assign_category_ids' => $auto_assign_category_ids,
				],
				'tags'           => [
					'multiple_tags'       => $tags_multi,
					'allow_add_new_tag'   => $tags_add_new,
					'exclude_tags'        => $exclude_tags,
					'exclude_tag_ids'     => $exclude_tag_ids,
					'auto_assign_tags'    => $auto_assign_tags,
					'auto_assign_tag_ids' => $auto_assign_tag_ids,
				],
				'custom_field'   => $custom_field,
			];

			// validate security_fields field
			$challenge_status     = filter_var( $security_fields['challenge']['status'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$challenge_question   = isset( $security_fields['challenge']['question'] ) ? sanitize_text_field( $security_fields['challenge']['question'] ) : null;
			$challenge_response   = isset( $security_fields['challenge']['response'] ) ? sanitize_text_field( $security_fields['challenge']['response'] ) : null;
			$recaptcha            = filter_var( $security_fields['recaptcha']['status'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$recaptcha_site_key   = isset( $security_fields['recaptcha']['recaptcha_site_key'] ) ? sanitize_text_field( $security_fields['recaptcha']['recaptcha_site_key'] ) : null;
			$recaptcha_secret_key = isset( $security_fields['recaptcha']['recaptcha_secret_key'] ) ? sanitize_text_field( $security_fields['recaptcha']['recaptcha_secret_key'] ) : null;

			if ( is_null( $challenge_status ) ||
			     is_null( $challenge_question ) || is_null( $challenge_response ) || is_null( $recaptcha )
			     || is_null( $recaptcha_site_key ) || is_null( $recaptcha_secret_key )
			) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Security setting data is invalid.', 'easy-post-submission' ),
				];
			}

			$new_data['security_fields'] = [
				'challenge' => [
					'status'   => $challenge_status,
					'question' => $challenge_question,
					'response' => $challenge_response,
				],
				'recaptcha' => [
					'status'               => $recaptcha,
					'recaptcha_site_key'   => $recaptcha_site_key,
					'recaptcha_secret_key' => $recaptcha_secret_key,
				],
			];

			// validate emails field
			$admin_email          = isset( $email['admin_mail']['email'] ) ? $this->validate_email( sanitize_text_field( $email['admin_mail']['email'] ) ) : null;
			$admin_mail_status    = filter_var( $email['admin_mail']['status'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$admin_subject        = isset( $email['admin_mail']['subject'] ) ? sanitize_text_field( $email['admin_mail']['subject'] ) : null;
			$admin_title          = isset( $email['admin_mail']['title'] ) ? sanitize_text_field( $email['admin_mail']['title'] ) : null;
			$admin_message        = $this->validate_textarea_content( $email['admin_mail']['message'] ?? null );
			$post_submit          = filter_var( $email['post_submit_notification']['status'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$post_submit_subject  = isset( $email['post_submit_notification']['subject'] ) ? sanitize_text_field( $email['post_submit_notification']['subject'] ) : null;
			$post_submit_title    = isset( $email['post_submit_notification']['title'] ) ? sanitize_text_field( $email['post_submit_notification']['title'] ) : null;
			$post_submit_message  = $this->validate_textarea_content( $email['post_submit_notification']['message'] ?? null );
			$post_publish         = filter_var( $email['post_publish_notification']['status'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$post_publish_subject = isset( $email['post_publish_notification']['subject'] ) ? sanitize_text_field( $email['post_publish_notification']['subject'] ) : null;
			$post_publish_title   = isset( $email['post_publish_notification']['title'] ) ? sanitize_text_field( $email['post_publish_notification']['title'] ) : null;
			$post_publish_message = $this->validate_textarea_content( $email['post_publish_notification']['message'] ?? null );
			$post_trash           = filter_var( $email['post_trash_notification']['status'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$post_trash_subject   = isset( $email['post_trash_notification']['subject'] ) ? sanitize_text_field( $email['post_trash_notification']['subject'] ) : null;
			$post_trash_title     = isset( $email['post_trash_notification']['title'] ) ? sanitize_text_field( $email['post_trash_notification']['title'] ) : null;
			$post_trash_message   = $this->validate_textarea_content( $email['post_trash_notification']['message'] ?? null );

			if (
				is_null( $admin_email ) || is_null( $admin_mail_status ) || is_null( $admin_subject ) || is_null( $admin_title ) || is_null( $admin_message )
				|| is_null( $post_submit ) || is_null( $post_submit_subject ) || is_null( $post_submit_title ) || is_null( $post_submit_message )
				|| is_null( $post_publish ) || is_null( $post_publish_subject ) || is_null( $post_publish_title ) || is_null( $post_publish_message )
				|| is_null( $post_trash ) || is_null( $post_trash_subject ) || is_null( $post_trash_title ) || is_null( $post_trash_message )
			) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Email setting data is invalid.', 'easy-post-submission' ),
				];
			}

			$new_data['email'] = [
				'admin_mail'                => [
					'email'   => $admin_email,
					'status'  => $admin_mail_status,
					'subject' => $admin_subject,
					'title'   => $admin_title,
					'message' => $admin_message,
				],
				'post_submit_notification'  => [
					'status'  => $post_submit,
					'subject' => $post_submit_subject,
					'title'   => $post_submit_title,
					'message' => $post_submit_message,
				],
				'post_publish_notification' => [
					'status'  => $post_publish,
					'subject' => $post_publish_subject,
					'title'   => $post_publish_title,
					'message' => $post_publish_message,
				],
				'post_trash_notification'   => [
					'status'  => $post_trash,
					'subject' => $post_trash_subject,
					'title'   => $post_trash_title,
					'message' => $post_trash_message,
				],
			];

			return [
				'status'  => true,
				'message' => esc_html__( 'valid data before saving!', 'easy-post-submission' ),
				'data'    => $new_data,
			];
		}

		/**
		 * Validate the provided email address.
		 *
		 * This function checks whether the given email is valid. If the email is empty, it returns the empty value.
		 * If the email is valid, it returns the email; otherwise, it returns `null`.
		 *
		 * @param string $email The email address to validate.
		 *
		 * @return string|null Returns the email if valid, or `null` if invalid.
		 */
		private function validate_email( $email ) {
			if ( $email === '' ) {
				return $email;
			}

			if ( is_email( $email ) ) {
				return $email;
			}

			return null;
		}

		/**
		 * Validate the provided URL.
		 *
		 * This function checks whether the given URL is valid. If the URL is empty, it returns the empty value.
		 * If the URL is valid, it returns the URL; otherwise, it returns `null`.
		 *
		 * @param string $url The URL to validate.
		 *
		 * @return string|null Returns the URL if valid, or `null` if invalid.
		 */
		private function validate_url( $url ) {
			if ( $url === '' ) {
				return $url;
			}

			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return $url;
			}

			return null;
		}

		/**
		 * Validate the content of a textarea before saving.
		 *
		 * This function checks whether the current user has permission to use unfiltered HTML. If the user has permission,
		 * it returns the content as is. If not, it sanitizes the content by stripping out all HTML tags except for a set of allowed tags.
		 *
		 * @param string $content The content of the textarea to validate.
		 *
		 * @return string Returns the validated content, either as is or sanitized by stripping unwanted HTML tags.
		 */
		private function validate_textarea_content( $content ) {
			if ( current_user_can( 'unfiltered_html' ) ) {
				return $content;
			}

			return strip_tags( $content, '<h1><h2><h3><h4><h5><h6><strong><b><em><i><a><code><p><div><ol><ul><li><br><button><figure><img><iframe><video><audio>' );
		}

		/**
		 * Validate custom field data in the form fields section.
		 *
		 * This function validates the custom field data provided in the form fields section, ensuring that the data meets the required format and structure.
		 * It returns an associative array containing the validation status and any error messages.
		 *
		 * @param array $form_fields The custom fields data to validate.
		 *
		 * @return array Returns an associative array with 'status' (boolean) and 'message' (string) for validation results.
		 */
		private function validate_custom_field( $form_fields ) {

			$custom_field_array = isset( $form_fields['custom_field'] ) ? (array) $form_fields['custom_field'] : null;
			$data               = [];

			if ( is_null( $custom_field_array ) ) {
				return [
					'status' => false,
					'data'   => $data,
				];
			}

			foreach ( $custom_field_array as $custom_field ) {
				$custom_field_name  = sanitize_text_field( $custom_field['custom_field_name'] ?? '' );
				$custom_field_label = sanitize_text_field( $custom_field['custom_field_label'] ?? '' );
				$field_type         = sanitize_text_field( $custom_field['field_type'] ?? '' );

				if ( empty( $custom_field_name ) || empty( $custom_field_label ) || empty( $field_type ) ) {
					return [
						'status' => false,
						'data'   => $data,
					];
				}

				$data[] = [
					'custom_field_name'  => $custom_field_name,
					'custom_field_label' => $custom_field_label,
					'field_type'         => $field_type,
				];
			}

			return [
				'status' => true,
				'data'   => $data,
			];
		}

		/**
		 * Update form settings in the database.
		 *
		 * This function validates user permissions, retrieves the form data from the request,
		 * and updates the corresponding form settings in the 'rb_submission' table.
		 * It sends a JSON response indicating success or failure based on the outcome.
		 *
		 * @return void This function does not return a value but sends a JSON response on success or failure.
		 * @since 1.0.0
		 *
		 */
		public function update_form() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			global $wpdb;
			$table_name = $wpdb->prefix . 'rb_submission';

			if ( ! isset( $_POST['data'] ) ) {
				wp_send_json_error( esc_html__( 'Form data is incorrect or missing.', 'easy-post-submission' ) );
				wp_die();
			}

			$data = json_decode( wp_kses_post( wp_unslash( $_POST['data'] ) ), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error( esc_html__( 'Data invalid.', 'easy-post-submission' ) );
				wp_die();
			}

			$id       = isset( $data['id'] ) ? intval( sanitize_text_field( $data['id'] ) ) : '';
			$new_data = isset( $data['data'] ) ? wp_json_encode( $data['data'] ) : '';

			if ( json_last_error() !== JSON_ERROR_NONE || empty( $id ) || empty( $new_data ) ) {
				wp_send_json_error( esc_html__( 'Id or new data is missing.', 'easy-post-submission' ) );
				wp_die();
			}

			$data_before_save_validate = $this->validate_data_before_saving( $new_data );
			if ( ! $data_before_save_validate['status'] ) {
				wp_send_json_error( $data_before_save_validate['message'] );
				wp_die();
			}

			$data_after_sanitize = wp_json_encode( $data_before_save_validate['data'] );

			$result = $wpdb->update(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				[ 'data' => $data_after_sanitize ],
				[ 'id' => $id ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( $result !== false ) {
				wp_send_json_success( esc_html__( 'Save successfully!', 'easy-post-submission' ) );
			} else {
				wp_send_json_error( esc_html__( 'Save to Database failed.', 'easy-post-submission' ) );
			}

			wp_die();
		}

		/**
		 * Delete form from database
		 */
		public function delete_form() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			global $wpdb;
			$table_name = $wpdb->prefix . 'rb_submission';

			if ( ! isset( $_POST['data'] ) ) {
				wp_send_json_error( esc_html__( 'Form data is incorrect or missing.', 'easy-post-submission' ) );
				wp_die();
			}

			$data = json_decode( sanitize_textarea_field( wp_unslash( $_POST['data'] ) ), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error( esc_html__( 'Json data invalid.', 'easy-post-submission' ) );
				wp_die();
			}

			$id = isset( $data['id'] ) ? intval( sanitize_text_field( $data['id'] ) ) : '';
			if ( empty( $id ) ) {
				wp_send_json_error( esc_html__( 'ID is missing', 'easy-post-submission' ) );
				wp_die();
			}

			$wpdb->delete( $table_name, [ 'id' => $id ] );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			wp_send_json_success( esc_html__( 'Removed successfully!', 'easy-post-submission' ) );
			wp_die();
		}

		/**
		 * Get all authors from the system.
		 *
		 * This function retrieves all users with the 'author' role, including their ID and display name,
		 * and returns them in a JSON response. The authors are ordered by their display name.
		 *
		 * @return void This function does not return a value but sends a JSON response with the list of authors.
		 * @since 1.0.0
		 *
		 */
		public function get_authors() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			$authors = get_users( [
				'fields'  => [ 'ID', 'display_name' ],
				'who'     => 'author',
				'orderby' => 'display_name',
			] );

			$result = array_map( function ( $author ) {

				return [
					'ID'           => $author->ID,
					'display_name' => $author->display_name,
				];
			}, $authors );

			wp_send_json_success( $result );
			wp_die();
		}

		/**
		 * Get all categories from the system.
		 *
		 * This function retrieves all categories (including empty ones) from the 'category' taxonomy
		 * and returns them in a JSON response.
		 *
		 * @return void This function does not return a value but sends a JSON response with the list of categories.
		 * @since 1.0.0
		 *
		 */
		public function admin_get_categories() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			$categories = get_terms( [
				'taxonomy'   => 'category',
				'hide_empty' => false,
			] );

			wp_send_json_success( $categories );
			wp_die();
		}

		/**
		 * Get all tags from the system.
		 *
		 * This function retrieves all tags (including empty ones) from the 'post_tag' taxonomy
		 * and returns them in a JSON response.
		 *
		 * @return void This function does not return a value but sends a JSON response with the list of tags.
		 * @since 1.0.0
		 *
		 */
		public function admin_get_tags() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			$tags = get_terms( [
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			] );

			wp_send_json_success( $tags );
			wp_die();
		}

		/**
		 * Restore plugin settings.
		 *
		 * This function restores the plugin settings by validating and sanitizing the provided data.
		 * It sends a JSON response indicating success or failure. If the data is invalid or missing, an error message is returned.
		 *
		 * @return void This function does not return a value but sends a JSON response on success or failure.
		 * @since 1.0.0
		 *
		 */
		public function restore_data() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			if ( ! isset( $_POST['data'] ) ) {
				wp_send_json_error( esc_html__( 'Form data is incorrect or missing.', 'easy-post-submission' ) );
				wp_die();
			}

			$restore_data = json_decode( wp_kses_post( wp_unslash( $_POST['data'] ) ), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error( esc_html__( 'Json data invalid.', 'easy-post-submission' ) );
				wp_die();
			}

			$restore_data_sanitize = [];
			foreach ( $restore_data as $form_data ) {
				$form_data_validate = $this->validate_restore_form_data( $form_data );

				if ( ! $form_data_validate['status'] ) {
					wp_send_json_error( $form_data_validate['message'] );
					wp_die();
				}

				$restore_data_sanitize[] = $form_data_validate['data'];
			}

			global $wpdb;
			$prepare_values = [];
			foreach ( $restore_data_sanitize as $row ) {
				$prepare_values[] = $row['id'];
				$prepare_values[] = $row['title'];
				$prepare_values[] = $row['data'];
			}

			$placeholders_string = implode(', ', array_fill(0, count($restore_data_sanitize), '(%d, %s, %s)'));
			// $placeholders_string is a dynamically generated string with placeholders for each value.
			// The values in $prepare_values are sanitized and come from a trusted source, ensuring safe query execution.
			$wpdb->query( $wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}rb_submission (id, title, data) VALUES {$placeholders_string}", $prepare_values));  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			wp_send_json_success( esc_html__( 'restore data success', 'easy-post-submission' ) );
			wp_die();
		}

		/**
		 * Validate the form data for restoring plugin settings.
		 *
		 * This function validates the form data to ensure that the necessary fields (title and ID) are present and properly sanitized.
		 * It returns an array containing the validation status and any error messages if validation fails.
		 *
		 * @param array $form_data The form data to validate.
		 *
		 * @return array Returns an associative array with 'status' (boolean) indicating whether the data is valid,
		 *               and 'message' (string) if there's an error, or 'data' (array) with sanitized values if valid.
		 */
		private function validate_restore_form_data( $form_data ) {

			$result = [];
			$title  = isset( $form_data['title'] ) ? sanitize_text_field( $form_data['title'] ) : '';
			$id     = isset( $form_data['id'] ) ? intval( sanitize_text_field( $form_data['id'] ) ) : '';

			if ( empty( $id ) || empty( $title ) ) {
				return [
					'status'  => false,
					'data'    => $result,
					'message' => esc_html__( 'ID or Title is missing', 'easy-post-submission' ),
				];
			}

			$data = isset( $form_data['data'] ) ? wp_json_encode( $form_data['data'] ) : '';
			if ( json_last_error() !== JSON_ERROR_NONE || empty( $data ) ) {
				return [
					'status'  => false,
					'data'    => $result,
					'message' => esc_html__( 'Form data is missing', 'easy-post-submission' ),
				];
			}

			$data_validate = $this->validate_data_before_saving( $data );
			if ( ! $data_validate['status'] ) {
				return [
					'status'  => false,
					'data'    => $result,
					'message' => $data_validate['message'],
				];
			}

			$result['id']    = $id;
			$result['title'] = $title;
			$result['data']  = wp_json_encode( $data_validate['data'] );

			return [
				'status'  => true,
				'data'    => $result,
				'message' => 'Valid data',
			];
		}

		/**
		 * Get the post manager data.
		 *
		 * This function retrieves the post manager data stored in the plugin options and returns it in a JSON response.
		 *
		 * @return void This function does not return a value but sends a JSON response with the post manager data.
		 * @since 1.0.0
		 *
		 */
		public function get_post_manager() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			$post_manager_data = get_option( self::$manager_key, [] );
			wp_send_json_success( $post_manager_data );
			wp_die();
		}

		/**
		 * Update the post manager data.
		 *
		 * This function validates the user's permission, retrieves the form data from the request,
		 * and updates the post manager data stored in the plugin options.
		 * It sends a JSON response indicating success or failure based on the outcome.
		 *
		 * @return void This function does not return a value but sends a JSON response on success or failure.
		 * @since 1.0.0
		 *
		 */
		public function update_post_manager() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';
			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->validate_permission();

			if ( ! isset( $_POST['data'] ) ) {
				wp_send_json_error( esc_html__( 'Form data is incorrect or missing.', 'easy-post-submission' ) );
				wp_die();
			}

			$post_manager_data = json_decode( sanitize_textarea_field( wp_unslash( $_POST['data'] ) ), true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error( esc_html__( 'Json data invalid.', 'easy-post-submission' ) );
				wp_die();
			}

			$validate_post_manager_data = $this->validate_post_manager_data( $post_manager_data );

			if ( ! $validate_post_manager_data['status'] ) {
				wp_send_json_error( $validate_post_manager_data['message'] );
				wp_die();
			}

			update_option( self::$manager_key, $validate_post_manager_data['result'] );

			wp_send_json_success( esc_html__( 'Post manager settings updated successfully.', 'easy-post-submission' ) );
			wp_die();
		}

		/**
		 * Validate the post manager data.
		 *
		 * This function validates the provided post manager data, including checking if the necessary fields (such as
		 * the 'edit_post_form' and 'edit_post_url') are present and correctly formatted.
		 * It returns an array containing the validation status and any error messages.
		 *
		 * @param array $post_manager_data The post manager data to validate.
		 *
		 * @return array Returns an associative array with 'status' (boolean) indicating whether the data is valid,
		 *               and 'message' (string) if there's an error, or 'data' (array) with sanitized values if valid.
		 */
		private function validate_post_manager_data( $post_manager_data ) {

			$edit_post_form = isset( $post_manager_data['edit_post_form'] ) ? $post_manager_data['edit_post_form'] : '';

			if ( empty( $edit_post_form ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Error with form data. Please reload the page and try saving the changes again.', 'easy-post-submission' )
				];
			}

			$edit_post_url = isset( $edit_post_form['edit_post_url'] ) ? $this->validate_url( sanitize_url( $edit_post_form['edit_post_url'] ) ) : null;
			if ( is_null( $edit_post_url ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'The Post Submission Edit Page URL is missing!', 'easy-post-submission' )
				];
			}

			$edit_login_action_choice = isset( $edit_post_form['login_action_choice'] ) ? sanitize_text_field( $edit_post_form['login_action_choice'] ) : null;
			if ( is_null( $edit_login_action_choice ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'The Login Action Choice is missing!', 'easy-post-submission' )
				];
			}

			$edit_post_required_login_title = isset( $edit_post_form['edit_post_required_login_title'] ) ? sanitize_text_field( $edit_post_form['edit_post_required_login_title'] ) : null;
			if ( is_null( $edit_post_required_login_title ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Login Notification Title is missing!', 'easy-post-submission' )
				];
			}

			$edit_post_required_login_message = isset( $edit_post_form['edit_post_required_login_message'] ) ? sanitize_text_field( $edit_post_form['edit_post_required_login_message'] ) : null;
			if ( is_null( $edit_post_required_login_message ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Login Notification Message is missing!', 'easy-post-submission' )
				];
			}

			$edit_post_form = [
				'edit_post_url'                    => $edit_post_url,
				'login_action_choice'              => $edit_login_action_choice,
				'edit_post_required_login_title'   => $edit_post_required_login_title,
				'edit_post_required_login_message' => $edit_post_required_login_message
			];

			$user_profile = isset( $post_manager_data['user_profile'] ) ? $post_manager_data['user_profile'] : '';
			if ( empty( $user_profile ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$allow_delete_post          = isset( $user_profile['allow_delete_post'] ) ? (bool)$user_profile['allow_delete_post'] : null;
			$allow_edit_post            = isset( $user_profile['allow_edit_post'] ) ? (bool) $user_profile['allow_edit_post'] : null;
			$form_submission_default_id = isset( $user_profile['form_submission_default_id'] ) ? intval($user_profile['form_submission_default_id']) : 0;

			if ( is_null($allow_delete_post) || is_null($allow_edit_post) ||  empty($form_submission_default_id )) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$user_posts_login_action_choice = isset( $user_profile['login_action_choice'] ) ? sanitize_text_field( $user_profile['login_action_choice'] ) : null;

			if ( is_null( $user_posts_login_action_choice ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$user_posts_required_login_title = isset( $user_profile['user_posts_required_login_title'] ) ? sanitize_text_field( $user_profile['user_posts_required_login_title'] ) : null;
			if ( is_null( $user_posts_required_login_title ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$user_posts_required_login_message = isset( $user_profile['user_posts_required_login_message'] ) ? sanitize_text_field( $user_profile['user_posts_required_login_message'] ) : null;
			if ( is_null( $user_posts_required_login_message ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$user_profile = [
				'allow_delete_post'                 => $allow_delete_post,
				'allow_edit_post'                   => $allow_edit_post,
				'form_submission_default_id'        => $form_submission_default_id,
				'login_action_choice'               => $user_posts_login_action_choice,
				'user_posts_required_login_title'   => $user_posts_required_login_title,
				'user_posts_required_login_message' => $user_posts_required_login_message
			];

			$custom_login_and_registration = isset( $post_manager_data['custom_login_and_registration'] ) ? $post_manager_data['custom_login_and_registration'] : '';
			if ( empty( $custom_login_and_registration ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$custom_login_button_label = isset( $custom_login_and_registration['custom_login_button_label'] ) ? sanitize_text_field( $custom_login_and_registration['custom_login_button_label'] ) : null;
			if ( is_null( $custom_login_button_label ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$custom_login_link = isset( $custom_login_and_registration['custom_login_link'] ) ? $this->validate_url( sanitize_url( $custom_login_and_registration['custom_login_link'] ) ) : null;
			if ( is_null( $custom_login_link ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$custom_registration_button_label = isset( $custom_login_and_registration['custom_registration_button_label'] ) ? sanitize_text_field( $custom_login_and_registration['custom_registration_button_label'] ) : null;
			if ( is_null( $custom_registration_button_label ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$custom_registration_link = isset( $custom_login_and_registration['custom_registration_link'] ) ? $this->validate_url( sanitize_url( $custom_login_and_registration['custom_registration_link'] ) ) : null;
			if ( is_null( $custom_registration_link ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Data is invalid.', 'easy-post-submission' )
				];
			}

			$custom_login_and_registration = [
				'custom_login_button_label'        => $custom_login_button_label,
				'custom_login_link'                => $custom_login_link,
				'custom_registration_button_label' => $custom_registration_button_label,
				'custom_registration_link'         => $custom_registration_link
			];

			$result = [
				'edit_post_form'                => $edit_post_form,
				'user_profile'                  => $user_profile,
				'custom_login_and_registration' => $custom_login_and_registration
			];

			return [
				'status' => true,
				'result' => $result
			];
		}

		/**
		 * Get the default form data.
		 *
		 * This function returns the default form settings, including general settings, user login preferences,
		 * and other configuration options used for post submission.
		 * It is typically used when initializing a new form or restoring default settings.
		 *
		 * @return array Returns an array containing the default form settings.
		 */
		public function get_default_form() {
			return [
				"general_setting" => [
					"post_status"      => "draft",
					"url_direction"    => "",
					"unique_title"     => true,
					"form_layout_type" => "2_cols"
				],
				"user_login"      => [
					"author_access"    => "allow_guest",
					"assign_author"    => "",
					"assign_author_id" => "1",
					"login_link_url"   => "",
					"login_type"       => [
						"type"                      => "show_login_message",
						"login_message"             => "Please log in to securely submit your content. If you do not have an account, sign up quickly to get started!",
						"login_link_label"          => "Continue Login",
						"required_login_title"      => "Login Required to Submit",
						"required_login_title_desc" => "You must be logged in to submit a new post. Please log in to continue.",
						"register_link"             => "",
						"register_button_label"     => "Register"
					]
				],
				"form_fields"     => [
					"user_name"      => "require",
					"user_email"     => "require",
					"post_title"     => "require",
					"tagline"        => "require",
					"editor_type"    => "rich_editor",
					"max_images"     => 3,
					"max_image_size" => 100,
					"featured_image" => [
						"status"                 => "require",
						"upload_file_size_limit" => 0,
						"default_featured_image" => ""
					],
					"categories"     => [
						"multiple_categories"      => true,
						"exclude_categories"       => [],
						"exclude_category_ids"     => [],
						"auto_assign_categories"   => [],
						"auto_assign_category_ids" => []
					],
					"tags"           => [
						"multiple_tags"       => true,
						"allow_add_new_tag"   => true,
						"exclude_tags"        => [],
						"exclude_tag_ids"     => [],
						"auto_assign_tags"    => [],
						"auto_assign_tag_ids" => []
					],
					"custom_field"   => []
				],
				"security_fields" => [
					"challenge" => [
						"status"   => false,
						"question" => "",
						"response" => ""
					],
					"recaptcha" => [
						"status"               => false,
						"recaptcha_site_key"   => "",
						"recaptcha_secret_key" => ""
					]
				],
				"email"           => [
					"admin_mail"                => [
						"status"  => false,
						"email"   => "",
						"subject" => "New Post Submitted",
						"title"   => "Notification: A New Post Has Been Submitted",
						"message" => "Dear Admin, <br>We would like to inform you that a new post titled \"{{post_title}}\" has been successfully submitted. Please check and review the post in the system. <br>Best regards, The Support Team"
					],
					"post_submit_notification"  => [
						"status"  => false,
						"subject" => "Your Post Has Been Successfully Submitted",
						"title"   => "Confirmation: Your Post Submission",
						"message" => "Dear Author, <br>We would like to inform you that your post titled \"{{post_title}}\" has been successfully submitted. Our team will review your post and notify you once its published. Thank you for your contribution! <br>Best regards, The Support Team"
					],
					"post_publish_notification" => [
						"status"  => false,
						"subject" => "Your Post Has Been Published",
						"title"   => "Congratulations: Your Post Is Now Live",
						"message" => "Dear author, <br>We are excited to inform you that your post titled \"{{post_title}}\" has been successfully published on our platform. You can now view your post live here: {{post_link}} Thank you for your contribution, and we look forward to more great content from you! <br>Best regards, The Support Team"
					],
					"post_trash_notification"   => [
						"status"  => false,
						"subject" => "Your Post Has Been Deleted",
						"title"   => "Notice: Your Post Has Been Removed",
						"message" => "Dear Author, <br>We regret to inform you that your post titled \"{{post_title}}\" has been removed from our platform. If you have any questions or concerns about this, please feel free to contact us. Thank you for your understanding. <br>Best regards, The Support Team"
					]
				]
			];
		}
	}
}

/** load */
Easy_Post_Submission_Admin_Ajax_Handler::get_instance();
