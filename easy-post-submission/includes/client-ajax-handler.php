<?php
/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Easy_Post_Submission_Client_Ajax_Handler', false ) ) {
	/**
	 * Class Easy_Post_Submission_Client_Ajax_Handler
	 */
	class Easy_Post_Submission_Client_Ajax_Handler {
		/**
		 * @var
		 */
		private static $instance;
		private static $nonce = 'easy-post-submission';

		/**
		 * @return Easy_Post_Submission_Client_Ajax_Handler
		 */
		public static function get_instance() {

			if ( self::$instance === null ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Easy_Post_Submission_Client_Ajax_Handler constructor.
		 */
		public function __construct() {

			self::$instance = $this;

			add_action( 'wp_ajax_rbsm_submit_post', [ $this, 'create_post' ] );
			add_action( 'wp_ajax_nopriv_rbsm_submit_post', [ $this, 'create_post' ] );
			add_action( 'wp_ajax_rbsm_update_post', [ $this, 'update_post' ] );
			add_action( 'wp_ajax_rbsm_get_form_by_id', [ $this, 'get_form_by_id' ] );
			add_action( 'wp_ajax_nopriv_rbsm_get_form_by_id', [ $this, 'get_form_by_id' ] );
			add_action( 'wp_ajax_rbsm_get_user_posts', [ $this, 'get_user_posts' ] );
			add_action( 'wp_ajax_rbsm_trash_post', [ $this, 'trash_post' ] );
			add_action( 'post_updated', [ $this, 'try_notify_on_post_publish' ], 10, 3 );
		}

		/**
		 * Validate the user before performing an action.
		 *
		 * This method checks whether the user is logged in. If the user is not logged in, it sends a JSON error response
		 * with a message indicating that login is required. The script execution is then halted using `wp_die()`.
		 *
		 * @return bool True if the user is logged in; otherwise, the script halts and returns an error.
		 */
		public function validate_logged_user() {
			if ( ! is_user_logged_in() ) {
				wp_send_json_error( esc_html__( 'You need to log in before do this action.', 'easy-post-submission' ) );
				wp_die();
			}

			return true;
		}

		/**
		 * Retrieves and sanitizes submission data from the $_POST request.
		 *
		 * This method validates the nonce to ensure the request is secure,
		 * then sanitizes and processes various form fields before returning them
		 * as an associative array.
		 *
		 * @return array An array containing sanitized submission data.
		 */
		private function get_sanitized_submission_data() {

			$nonce = ( isset( $_POST['_nonce'] ) ) ? sanitize_key( $_POST['_nonce'] ) : '';

			if ( empty( $nonce ) || false === wp_verify_nonce( $nonce, self::$nonce ) ) {
				wp_send_json_error( esc_html__( 'Invalid nonce.', 'easy-post-submission' ) );
				wp_die();
			}

			$data = [];

			$data['id']                    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
			$data['paged']                 = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 0;
			$data['formId']                = isset( $_POST['formId'] ) ? absint( $_POST['formId'] ) : 0;
			$data['postId']                = isset( $_POST['postId'] ) ? absint( $_POST['postId'] ) : 0;
			$data['title']                 = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
			$data['excerpt']               = isset( $_POST['excerpt'] ) ? sanitize_text_field( wp_unslash( $_POST['excerpt'] ) ) : '';
			$data['userEmail']             = isset( $_POST['userEmail'] ) ? sanitize_email( wp_unslash( $_POST['userEmail'] ) ) : '';
			$data['userName']              = isset( $_POST['userName'] ) ? sanitize_text_field( wp_unslash( $_POST['userName'] ) ) : '';
			$data['content']               = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
			$data['recaptchaResponse']     = isset( $_POST['recaptchaResponse'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptchaResponse'] ) ) : '';
			$data['tags']                  = isset( $_POST['tags'] ) && is_array( $_POST['tags'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['tags'] ) ) : [];
			$data['categories']            = isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? array_map( 'absint', $_POST['categories'] ) : [];
			$data['customFieldsData']      = isset( $_POST['customFieldsData'] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST['customFieldsData'] ) ), true ) : [];
			$data['isRemoveFeaturedImage'] = isset( $_POST['isRemoveFeaturedImage'] ) && 'true' === (string) sanitize_text_field( wp_unslash( $_POST['isRemoveFeaturedImage'] ) );

			return $data;
		}

		/**
		 * Trash a post submission.
		 *
		 * This method validates the nonce to ensure the request is legitimate and validates the user to check if the user is logged in.
		 * If both validations pass, it proceeds to trash the specified post submission.
		 * This function should be used to delete posts or move them to trash, depending on the requirements.
		 *
		 * @return void
		 */
		public function trash_post() {

			$this->validate_logged_user();

			$data = $this->get_sanitized_submission_data();

			$post_id = $data['postId'];
			$title   = $data['title'];

			if ( empty( $post_id ) ) {
				wp_send_json_error( esc_html__( 'Post ID is missing.', 'easy-post-submission' ) );
				wp_die();
			}

			// Check if the current user can delete the post
			if ( ! current_user_can( 'delete_post', $post_id ) ) {
				wp_send_json_error( esc_html__( 'Sorry, you are not allowed to delete this post.', 'easy-post-submission' ) );
				wp_die();
			}

			if ( get_post( $post_id ) ) {
				$this->try_notify_trash_email( $post_id, $title );

				wp_trash_post( $post_id );

				/* translators: %d is the post ID */
				wp_send_json_success( sprintf( esc_html__( 'The post with post ID: %d has been deleted.', 'easy-post-submission' ), $post_id ) );
			} else {

				/* translators: %d is the post ID */
				wp_send_json_error( sprintf( esc_html__( 'Post ID %d does not exist.', 'easy-post-submission' ), $post_id ) );
			}

			wp_die();
		}

		/**
		 * Send an email notification when a post is trashed.
		 *
		 * This method sends an email notification to the relevant recipients when a post is trashed.
		 * It retrieves the post link and form settings based on the post ID, and checks if the form settings' status is enabled.
		 * If the status is active, the email notification will be triggered.
		 *
		 * @param int $post_id The ID of the post that was trashed.
		 * @param string $title The title of the post that was trashed.
		 *
		 * @return void
		 */
		private function try_notify_trash_email( $post_id, $title ) {

			$post_link = get_permalink( $post_id );

			$form_settings = $this->get_form_settings_by_post_id( $post_id );

			if ( ! $form_settings['status'] ) {
				return;
			}

			$form_settings_result = json_decode( $form_settings['data']->data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return;
			}

			$allow_notify_trash_email = (bool) ( $form_settings_result['email']['post_trash_notification']['status'] ?? false );
			if ( ! $allow_notify_trash_email ) {
				return;
			}

			$user_email = $this->get_user_email_by_post_id( $post_id );
			if ( empty( $user_email ) ) {
				return;
			}

			$this->notify_user_when_post_trashed( $post_link, $title, $form_settings_result, $user_email );
		}

		/**
		 * Convert a name to a valid ID string.
		 *
		 * This method takes a name as input, sanitizes it by removing non-alphanumeric characters
		 * and replacing them with dashes, and then truncates the resulting string to a maximum of 20 characters.
		 * If the input name is empty, the current date is used as the default value.
		 *
		 * @param string $name The name to convert into a valid ID string.
		 *
		 * @return string The sanitized and truncated ID string.
		 */
		private function convert_to_id( $name ) {

			$name = ! empty( $name ) ? $name : gmdate( 'Y-m-d' );
			$name = preg_replace( '/[^a-zA-Z0-9]+/', '-', trim( $name ) );
			$name = trim( $name, '-' );

			return substr( $name, 0, 20 );
		}

		/**
		 * Convert base64 encoded image data to images, upload them, and return their URLs and IDs.
		 *
		 * This method decodes base64 image data, uploads the images, and retrieves their URLs and IDs.
		 * The method is typically used when working with images encoded in base64 format that need to be saved
		 * to the server and their properties (URLs and IDs) retrieved for further use.
		 *
		 * @param string $post_title The title of the post associated with the images.
		 * @param string $base64ImageData The base64 encoded image data to be uploaded.
		 *
		 * @return array An associative array containing two keys:
		 *               - 'image_urls': An array of the uploaded image URLs.
		 *               - 'image_ids': An array of the uploaded image IDs.
		 */
		private function upload_images_and_get_properties( $post_title, $base64ImageData ) {

			$image_urls  = [];
			$image_ids   = [];
			$typePattern = '/image\/([a-zA-Z0-9]+);base64/';

			$base64FullTags = $base64ImageData[0];
			$base64Contents = $base64ImageData[1];

			foreach ( $base64Contents as $index => $base64Data ) {
				$image_data = base64_decode( $base64Data );
				preg_match( $typePattern, $base64FullTags[ $index ], $image_type );
				$image_extension = $image_type[1] === 'jpeg' ? 'jpg' : $image_type[1];

				$upload_dir = wp_upload_dir();
				$file_name  = $this->convert_to_id( $post_title ) . '-' . uniqid() . '.' . $image_extension;
				$file_path  = $upload_dir['path'] . '/' . $file_name;

				// Ensure the WP_Filesystem is initialized.
				if ( ! function_exists( 'wp_filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				WP_Filesystem();

				// Global $wp_filesystem
				global $wp_filesystem;

				if ( ! is_object( $wp_filesystem ) ) {
					return [];
				}
				$wp_filesystem->put_contents( $file_path, $image_data, FS_CHMOD_FILE );

				$file = [
					'name'     => wp_basename( $file_name ),
					'type'     => 'image/' . $image_extension,
					'tmp_name' => $file_path,
					'error'    => 0,
					'size'     => filesize( $file_path ),
				];

				if ( ! function_exists( 'media_handle_sideload' ) ) {
					require_once( ABSPATH . 'wp-admin/includes/file.php' );
					require_once( ABSPATH . 'wp-admin/includes/media.php' );
					require_once( ABSPATH . 'wp-admin/includes/image.php' );
				}

				$attachment_id = media_handle_sideload( $file );

				if ( is_wp_error( $attachment_id ) ) {
					wp_delete_file( $file_path );
					wp_send_json_error( esc_html__( 'Failed to process some images in the content. Please make sure you are using supported image file types and try again.', 'easy-post-submission' ) );
					wp_die();
				}
				$attachment_url = wp_get_attachment_url( $attachment_id );
				if ( $attachment_url ) {
					$image_urls[] = $attachment_url;
					$image_ids[]  = $attachment_id;
				} else {
					wp_send_json_error( esc_html__( 'Failed to handle the attachment image URL in the post content. Please contact the webmaster for assistance.', 'easy-post-submission' ) );
					wp_die();
				}
			}

			return [ 'image_urls' => $image_urls, 'image_ids' => $image_ids ];
		}

		/**
		 * Create image tags for each image URL and return them as an array.
		 *
		 * This method generates HTML image tags (`<img>`) for each provided image URL.
		 * It is typically used when you need to display images in the content of a post,
		 * ensuring that each image has a standard `img` HTML tag with proper attributes.
		 *
		 * @param array $image_urls An array of image URLs for which the HTML image tags need to be created.
		 *
		 * @return array An array of HTML `<img>` tags corresponding to each image URL.
		 */
		private function create_image_tag_in_post( $image_urls ) {

			$image_tags = [];
			foreach ( $image_urls as $image_url ) {
				$image_tag    = '<img class="alignnone size-full" src="' . esc_url( $image_url ) . '" alt="" />';
				$image_tags[] = $image_tag;
			}

			return $image_tags;
		}

		/**
		 * Replace base64 image data with corresponding image tags in the content.
		 *
		 * This method takes the content containing base64-encoded image data and replaces
		 * each occurrence of the base64 data with the corresponding HTML `<img>` tag.
		 * It is useful when you want to convert embedded base64 images into traditional image tags.
		 *
		 * @param string $content The content that contains base64 image data.
		 * @param array $base64_data An array of base64-encoded image data.
		 * @param array $image_tags An array of HTML `<img>` tags that will replace the base64 data.
		 *
		 * @return string The content with the base64 image data replaced by HTML image tags.
		 */
		private function convert_base64_to_img_tag( $content, $base64_data, $image_tags ) {

			foreach ( $image_tags as $index => $image_tag ) {
				$content = str_replace( $base64_data[ $index ], $image_tag, $content );
			}

			return $content;
		}

		/**
		 * Check if a post with the given title already exists.
		 *
		 * This method checks whether the title of a newly created post is unique by comparing
		 * it against existing posts, based on the form settings. If the 'unique_title' setting
		 * is enabled, it ensures that no other post with the same title exists.
		 *
		 * @param int $created_post_id The ID of the created post.
		 * @param string $title The title of the newly created post.
		 * @param array $form_settings_result The form settings, which includes the 'unique_title' setting.
		 *
		 * @return array An array indicating whether the post title is unique and any additional data.
		 */
		private function is_not_exist_post_title( $created_post_id, $title, $form_settings_result ) {

			$is_unique_title = (bool) ( $form_settings_result['general_setting']['unique_title'] ?? true );

			if ( ! $is_unique_title ) {
				return [ 'status' => true, 'message' => 'Valid title!' ];
			}

			$post = get_posts( [
				'post_type'   => 'post',
				'title'       => trim( $title ),
				'post_status' => 'all',
				'numberposts' => 1,
			] );

			if ( ! empty( $post[0]->ID ) && $created_post_id !== $post[0]->ID ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'The title already exists.', 'easy-post-submission' ),
				];
			}

			return [
				'status'  => true,
				'message' => esc_html__( 'Valid title!', 'easy-post-submission' ),
			];
		}

		/**
		 * Get the post status based on the form settings.
		 *
		 * This method determines the post status (such as 'publish', 'draft', etc.) for a post
		 * based on the settings provided in the form settings. It returns the appropriate status
		 * depending on the configuration.
		 *
		 * @param array $form_settings_result The settings of the form, which may include
		 *                                    the desired post status.
		 *
		 * @return string The post status (e.g., 'publish', 'draft').
		 */
		private function get_post_status( $form_settings_result ) {

			$post_status_key = (string) ( $form_settings_result['general_setting']['post_status'] ?? 'draft' );

			$post_status_list = [
				'draft'          => 'draft',
				'pending_review' => 'pending',
				'private'        => 'private',
				'publish'        => 'publish',
			];

			return $post_status_list[ $post_status_key ] ?? 'draft';
		}

		/**
		 * Validates the reCAPTCHA response.
		 *
		 * This method sends the reCAPTCHA response to Google's reCAPTCHA verification service to
		 * confirm that the user is not a bot. It checks the response against the secret key
		 * and returns the result of the validation.
		 *
		 * @param string $recaptcha_response The response from the reCAPTCHA widget to be validated.
		 * @param array $form_settings_result The settings of the form, which may include the
		 *                                      reCAPTCHA secret key and other settings.
		 *
		 * @return array An array containing the result of the validation, such as success or failure.
		 */
		private function validate_recaptcha( $recaptcha_response, $form_settings_result ) {

			$recaptcha_setting_status = (bool) ( $form_settings_result['security_fields']['recaptcha']['status'] ?? false );
			if ( ! $recaptcha_setting_status ) {
				return [
					'status'  => true,
					'message' => esc_html__( 'reCAPTCHA is disabled.', 'easy-post-submission' ),
				];
			}

			if ( empty( $recaptcha_response ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'reCAPTCHA response is missing.', 'easy-post-submission' ),
				];
			}

			$recaptcha_secret_key = (string) ( $form_settings_result['security_fields']['recaptcha']['recaptcha_secret_key'] ?? '' );
			if ( empty( $recaptcha_secret_key ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'reCAPTCHA secret key is missing.', 'easy-post-submission' ),
				];
			}

			$response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
				'body' => [
					'secret'   => $recaptcha_secret_key,
					'response' => $recaptcha_response,
				],
			] );

			$response_body = wp_remote_retrieve_body( $response );
			$result        = json_decode( $response_body );

			if ( $result->success ) {
				return [
					'status'  => true,
					'message' => esc_html__( 'Valid reCAPTCHA.', 'easy-post-submission' ),
				];
			} else {
				return [
					'status'  => false,
					'message' => esc_html__( 'Invalid reCAPTCHA.', 'easy-post-submission' ),
				];
			}
		}

		/**
		 * Validates the title of the created post.
		 *
		 * This method checks if the title of the post already exists, ensuring that the title is
		 * unique if the form settings specify a unique title requirement. It compares the given
		 * post title with existing posts and ensures no conflict.
		 *
		 * @param int $created_post_id The ID of the created post.
		 * @param string $title The title of the post to be validated.
		 * @param array $form_settings_result The settings of the form, which may include the
		 *                                      option to require unique post titles.
		 *
		 * @return array An array containing the result of the title validation, including any error messages if the title is not unique.
		 */
		private function validate_title( $created_post_id, $title, $form_settings_result ) {

			$title_setting = (string) ( $form_settings_result['form_fields']['post_title'] ?? 'require' );

			if ( $title_setting === 'disable' && $title !== '' ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'The post title is not allowed!', 'easy-post-submission' ),
				];
			} elseif ( $title_setting === 'require' && $title === '' ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Title is missing!', 'easy-post-submission' ),
				];
			}

			return $this->is_not_exist_post_title( $created_post_id, $title, $form_settings_result );
		}

		/**
		 * Validates the excerpt of the post based on form settings.
		 *
		 * This method checks the provided excerpt to ensure it meets any validation criteria
		 * defined in the form settings, such as length limits, allowed characters, or other custom rules.
		 *
		 * @param string $excerpt The excerpt of the post to be validated.
		 * @param array $form_settings_result The settings of the form that may include validation rules
		 *                                      for the excerpt (e.g., minimum/maximum length, content restrictions).
		 *
		 * @return array An array containing validation results, including any error messages if the excerpt
		 *               doesn't comply with the specified rules.
		 */
		private function validate_excerpt( $excerpt, $form_settings_result ) {

			$excerpt_setting = (string) ( $form_settings_result['form_fields']['tagline'] ?? 'require' );

			if ( $excerpt_setting === 'disable' && $excerpt !== '' ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'The post excerpt is not allowed!', 'easy-post-submission' ),
				];
			} elseif ( $excerpt_setting === 'require' && $excerpt === '' ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'Post excerpt is missing!', 'easy-post-submission' ),
				];
			}

			return [
				'status'  => true,
				'message' => esc_html__( 'Valid excerpt!', 'easy-post-submission' ),
			];
		}

		/**
		 * Validates the images in the post content based on form settings.
		 *
		 * This method checks the number and size of the images in the post content against the limits set
		 * in the form settings. If the images exceed the allowed number or size, an error message is returned.
		 *
		 * @param string $content The content of the post which may contain images to be validated.
		 * @param array $form_settings_result The settings of the form, including the maximum allowed number of images
		 *                                     and the maximum allowed image size.
		 *
		 * @return array An array with the validation result. If successful, the `status` will be `true` and
		 *               a message indicating valid images will be returned. If validation fails, the `status`
		 *               will be `false`, and a message with the error will be returned (e.g., exceeding the max number
		 *               of images or the max image size).
		 */
		private function validate_images( $content, $form_settings_result ) {
			$max_images_allowed     = isset( $form_settings_result['form_fields']['max_images'] ) ? $form_settings_result['form_fields']['max_images'] : 3;
			$max_image_size_allowed = isset( $form_settings_result['form_fields']['max_image_size'] ) ? $form_settings_result['form_fields']['max_image_size'] : 100;
			$amount_images          = $this->get_total_images_in_content( $content );

			if ( $amount_images > $max_images_allowed ) {
				return [
					'status'  => false,

					/* translators: %d is the maximum number of images allowed */
					'message' => sprintf( esc_html__( 'You have reached the maximum limit of %d images.', 'easy-post-submission' ), $max_images_allowed )
				];
			}

			$validate_size_base64_images = $this->validate_size_base64_images( $max_image_size_allowed, $content );
			if ( ! $validate_size_base64_images['status'] ) {
				return [
					'status'  => false,
					'message' => $validate_size_base64_images['message']
				];
			}

			return [
				'status'  => true,
				'message' => esc_html__( 'Valid images.', 'easy-post-submission' )
			];
		}

		/**
		 * Validates the size of base64-encoded images in the post content.
		 *
		 * This method checks each base64-encoded image in the content against the maximum allowed size. If any
		 * image exceeds the allowed size, an error message is returned.
		 *
		 * @param int $max_image_size_allowed The maximum allowed image size in kilobytes (KB).
		 * @param string $content The content of the post, which may contain base64-encoded images to be validated.
		 *
		 * @return array An array with the validation result. If successful, the `status` will be `true` and
		 *               a message indicating valid image base64 will be returned. If validation fails, the `status`
		 *               will be `false`, and a message with the error (e.g., exceeding the allowed size) will be returned.
		 */
		private function validate_size_base64_images( $max_image_size_allowed, $content ) {
			$base64_pattern = '/<img[^>]+src="image\/[^;]+;base64,([^"]+)"[^>]*>/i';

			preg_match_all( $base64_pattern, $content, $base64_matches );

			$base64Contents = $base64_matches[1];
			foreach ( $base64Contents as $base64Content ) {
				$size = ( strlen( $base64Content ) * 3 ) / 4;
				if ( $size > $max_image_size_allowed * 1024 ) {
					return [
						'status'  => false,
						/* translators: %1$s is the size of the image and %2$d is the maximum allowed image size in KB */
						'message' => sprintf( esc_html__( 'The size %1$s of the image has exceeded the allowed limit, which is %2$dKB.', 'easy-post-submission' ), $size, $max_image_size_allowed )
					];

				}
			}

			return [
				'status'  => true,
				'message' => esc_html__( 'Valid image base64.', 'easy-post-submission' )
			];
		}

		/**
		 * Gets the number of image tags in the given content.
		 *
		 * This method searches for all `<img>` tags in the provided content and returns the total count of image tags found.
		 *
		 * @param string $content The content in which to search for image tags.
		 *
		 * @return int The number of `<img>` tags found in the content.
		 */
		private function get_total_images_in_content( $content ) {
			preg_match_all( '/<img[^>]+>/i', $content, $matches );

			return count( $matches[0] );
		}

		/**
		 * Validates the user name based on form settings.
		 *
		 * This method checks the user name according to the form settings:
		 * - If user name is disabled, it ensures that the user name is not provided.
		 * - If user name is required, it checks that a user name is provided or the user is logged in.
		 *
		 * @param string $user_name The user name to be validated.
		 * @param array $form_settings_result The settings for the form, including the user name field configuration.
		 *
		 * @return array An associative array containing:
		 * - 'status' (bool): Whether the validation was successful.
		 * - 'message' (string): The validation message.
		 */
		private function validate_user_name( $user_name, $form_settings_result ) {

			$user_name_setting = (string) ( $form_settings_result['form_fields']['user_name'] ?? 'require' );

			if ( $user_name_setting === 'disable' && $user_name !== '' ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'The user name is not allowed!', 'easy-post-submission' ),
				];
			} elseif ( $user_name_setting === 'require' && $user_name === '' && ! is_user_logged_in() ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'User name is missing!', 'easy-post-submission' ),
				];
			}

			return [
				'status'  => true,
				'message' => esc_html__( 'Valid user name!', 'easy-post-submission' ),
			];
		}

		/**
		 * Validates the user email based on form settings.
		 *
		 * This method checks the user email according to the form settings:
		 * - If the email is required, it checks whether an email is provided.
		 * - If the email is disabled, it ensures that the email field is not filled.
		 *
		 * @param string $user_email The user email to be validated.
		 * @param array $form_settings_result The settings for the form, including the email field configuration.
		 *
		 * @return array An associative array containing:
		 * - 'status' (bool): Whether the validation was successful.
		 * - 'message' (string): The validation message.
		 */
		private function validate_user_email( $user_email, $form_settings_result ) {

			$user_email_setting = (string) ( $form_settings_result['form_fields']['user_email'] ?? 'require' );

			if ( $user_email_setting === 'disable' && $user_email !== '' ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'User email is not allowed!', 'easy-post-submission' ),
				];
			} elseif ( $user_email_setting === 'require' && $user_email === '' && ! is_user_logged_in() ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'User email is missing!', 'easy-post-submission' ),
				];
			}

			$email_regex = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
			if ( ! preg_match( $email_regex, $user_email ) ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'User email is invalid.', 'easy-post-submission' ),
				];
			}

			return [
				'status'  => true,
				'message' => esc_html__( 'Valid user email!', 'easy-post-submission' ),
			];
		}

		/**
		 * Attempts to get the old title of a post if applicable.
		 *
		 * This method checks the form settings for the post title field:
		 * - If the title field is disabled and the post ID is provided, it retrieves the old title from the database.
		 * - Otherwise, it returns the new title passed as an argument.
		 *
		 * @param int $created_post_id The ID of the created post.
		 * @param string $title The title to be used if the old title is not required.
		 * @param array $form_settings_result The form settings, including the post title configuration.
		 *
		 * @return string The old title of the post if applicable, otherwise the new title.
		 */
		private function try_get_old_title( $created_post_id, $title, $form_settings_result ) {

			$title_setting = (string) ( $form_settings_result['form_fields']['post_title'] ?? 'require' );

			if ( ! is_null( $created_post_id ) && $title_setting === 'disable' ) {
				return get_the_title( $created_post_id );
			}

			return $title;
		}

		/**
		 * Attempts to get the old excerpt of a post if applicable.
		 *
		 * This method checks the form settings for the post excerpt field:
		 * - If the excerpt field is disabled and the post ID is provided, it retrieves the old excerpt from the database.
		 * - Otherwise, it returns the new excerpt passed as an argument.
		 *
		 * @param int $created_post_id The ID of the created post.
		 * @param string $excerpt The excerpt to be used if the old excerpt is not required.
		 * @param array $form_settings_result The form settings, including the post excerpt configuration.
		 *
		 * @return string The old excerpt of the post if applicable, otherwise the new excerpt.
		 */
		private function try_get_old_excerpt( $created_post_id, $excerpt, $form_settings_result ) {

			$excerpt_setting = (string) ( $form_settings_result['form_fields']['tagline'] ?? 'require' );

			if ( ! is_null( $created_post_id ) && $excerpt_setting === 'disable' ) {
				return get_post( $created_post_id )->post_excerpt;
			}

			return $excerpt;
		}

		/**
		 * Determines if the featured image is allowed based on the form settings.
		 *
		 * This function checks the 'status' of the featured image in the form settings.
		 * If the status is set to 'Disable', the function returns false, indicating that the featured image is not allowed.
		 * For any other status (including 'Require' or other values), it returns true, indicating that the featured image is allowed.
		 *
		 * @param array $form_settings The form settings containing the 'status' of the featured image.
		 *
		 * @return bool Returns true if the featured image is allowed, false if it is disabled.
		 */
		private function is_featured_image_allowed( $form_settings ) {
			$featured_image_status = isset( $form_settings['form_fields']['featured_image']['status'] )
				? (string) $form_settings['form_fields']['featured_image']['status']
				: 'require';

			if ( $featured_image_status === 'disable' ) {
				return false;
			}

			return $featured_image_status;
		}

		/**
		 * Validates the featured image based on the form settings and checks if it meets the required criteria.
		 *
		 * This function ensures the featured image is valid by checking its file extension and whether it matches
		 * the allowed types (JPG, JPEG, PNG, GIF). It also ensures the image is associated with the created post.
		 * The result of the validation is returned as an associative array with status and message details.
		 *
		 * @param array $featured_image_file The uploaded featured image file data, including the file name and size.
		 * @param array $form_settings_result The form settings, which include the allowed image extensions and other settings.
		 *
		 * @return array An associative array containing the validation result, with 'status' indicating success or failure,
		 *               and 'message' providing details about the validation outcome.
		 */
		private function get_sanitized_featured_image( $featured_image_file, $form_settings_result ) {

			// Check for upload errors
			if ( $featured_image_file['error'] !== UPLOAD_ERR_OK ) {
				return [
					'status'  => false,
					'message' => esc_html__( 'There was an error uploading the file. Please try again.', 'easy-post-submission' ),
				];
			}

			$allowed_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'svg' ];

			if ( empty( $featured_image_file['name'] ) || ! in_array( strtolower( pathinfo( $featured_image_file['name'], PATHINFO_EXTENSION ) ), $allowed_extensions ) ) {
				return [
					'status'  => 'error',
					'message' => esc_html__( 'Invalid file or extension. Allowed extensions: JPG, JPEG, PNG, GIF.', 'easy-post-submission' ),
				];
			}

			$filesize_limit = (int) ( $form_settings_result['form_fields']['featured_image']['upload_file_size_limit'] ?? 0 );
			$filesize_limit = max( 0, $filesize_limit );

			if ( $filesize_limit > 0 && ! empty( $featured_image_file['size'] ) ) {

				$limit_in_bytes      = $filesize_limit * 1024;
				$uploaded_image_size = isset( $featured_image_file['size'] ) ? $featured_image_file['size'] : 0;

				if ( $uploaded_image_size > 0 && $uploaded_image_size > $limit_in_bytes ) {
					return [
						'status'  => 'error',
						'message' => esc_html__( 'Image size exceeds the allowed limit! Please choose an image with a smaller size.', 'easy-post-submission' ),
					];
				}
			}

			return $featured_image_file;

		}

		/**
		 * Updates the post content based on the submitted data.
		 *
		 * This function validates the nonce and the user, ensuring that the submission is legitimate.
		 * If the data is not present, it returns an error. It proceeds with updating the post content afterward.
		 */
		public function update_post() {

			$this->validate_logged_user();

			$data = $this->get_sanitized_submission_data();

			$created_post_id = $data['postId'];
			if ( empty( $created_post_id ) ) {
				wp_send_json_error( esc_html__( 'Post ID is null', 'easy-post-submission' ) );
				wp_die();
			}

			$post = get_post( $created_post_id );
			if ( is_null( $post ) ) {
				wp_send_json_error( esc_html__( 'Post was not existed.', 'easy-post-submission' ) );
				wp_die();
			}

			$author_id       = (int) $post->post_author;
			$current_user_id = get_current_user_id();

			if ( $author_id !== $current_user_id ) {
				wp_send_json_error( esc_html__( 'You are not allowed to edit this post.', 'easy-post-submission' ) );
				wp_die();
			}

			$this->handle_data_to_submit_post( $data );
		}

		/**
		 * Handles the post submission, validates nonce, and sets the featured image.
		 *
		 * This function checks if the form submission data is valid, and if not, it returns an error.
		 * It then calls the necessary methods to handle the data, create a post, and set the featured image.
		 */
		public function create_post() {

			$data = $this->get_sanitized_submission_data();

			$this->handle_data_to_submit_post( $data );
		}

		/**
		 * Handles the data received from the form submission and creates or updates a WordPress post.
		 *
		 * This function processes the POST data, including the title, content, categories, tags, and other form fields.
		 * It sanitizes and validates the input data before using it to create or update a WordPress post.
		 *
		 * @param array $data An associative array of form data, which may include:
		 *                    - 'title' (string) The post title.
		 *                    - 'excerpt' (string) The post excerpt.
		 *                    - 'content' (string) The post content.
		 *                    - 'formId' (int) The ID of the form submitted.
		 *                    - 'postId' (int|null) The ID of the existing post (if updating).
		 *                    - 'isRemoveFeaturedImage' (bool) Whether to remove the featured image.
		 *                    - 'userName' (string) The user's name.
		 *                    - 'userEmail' (string) The user's email address.
		 *                    - 'customFieldsData' (array) Custom fields data.
		 *                    - 'recaptchaResponse' (string) The reCAPTCHA response.
		 *                    - 'categories' (array|int) Categories associated with the post.
		 *                    - 'tags' (array) Tags associated with the post.
		 *                    - and more...
		 *
		 * @return WP_Error|int Returns a WP_Error object if there is an issue with the data or post creation process,
		 *                      or the post ID if the post was successfully created or updated.
		 */
		private function handle_data_to_submit_post( $data = [] ) {

			$title                    = $data['title'];
			$excerpt                  = $data['excerpt'];
			$content                  = $data['content'];
			$form_id                  = $data['formId'];
			$created_post_id          = $data['postId'];
			$is_remove_featured_image = $data['isRemoveFeaturedImage'];
			$user_name                = $data['userName'];
			$user_email               = $data['userEmail'];
			$custom_fields_data       = $data['customFieldsData'];
			$recaptcha_response       = $data['recaptchaResponse'];

			$categories = (array) $data['categories'];
			$post_tags  = (array) $data['tags'];

			if ( empty( $form_id ) ) {
				wp_send_json_error( esc_html__( 'Error processing the post due to form settings ID. Please contact the webmaster for assistance.', 'easy-post-submission' ) );
				wp_die();
			}

			$form_settings = $this->get_form_settings_by_id( $form_id );
			if ( $form_settings === false ) {
				wp_send_json_error( esc_html__( 'Form settings could not be found. Please contact the webmaster for assistance.', 'easy-post-submission' ) );
				wp_die();
			}

			$form_settings_result = json_decode( ( $form_settings->data ), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error( esc_html__( 'An error occurred with the form settings data. Please contact the webmaster for assistance.', 'easy-post-submission' ) );
				wp_die();
			}

			$recaptcha_validate = $this->validate_recaptcha( $recaptcha_response, $form_settings_result );
			if ( ! $recaptcha_validate['status'] ) {
				wp_send_json_error( $recaptcha_validate['message'] );
				wp_die();
			}

			if ( empty( $content ) || $content === '<p><br></p>' ) {
				wp_send_json_error( esc_html__( 'Please provide the content for your post before submitting.', 'easy-post-submission' ) );
				wp_die();
			}

			$title_validate = $this->validate_title( $created_post_id, $title, $form_settings_result );

			if ( ! $title_validate['status'] ) {
				wp_send_json_error( $title_validate['message'] );
				wp_die();
			}

			$excerpt_validate = $this->validate_excerpt( $excerpt, $form_settings_result );
			if ( ! $excerpt_validate['status'] ) {
				wp_send_json_error( $excerpt_validate['message'] );
				wp_die();
			}

			$images_in_post_validate = $this->validate_images( $content, $form_settings_result );
			if ( ! $images_in_post_validate['status'] ) {
				wp_send_json_error( $images_in_post_validate['message'] );
				wp_die();
			}

			if ( ! is_user_logged_in() ) {

				$user_name_validate = $this->validate_user_name( $user_name, $form_settings_result );
				if ( ! $user_name_validate['status'] ) {
					wp_send_json_error( $user_name_validate['message'] );
					wp_die();
				}

				$user_email_validate = $this->validate_user_email( $user_email, $form_settings_result );
				if ( ! $user_email_validate['status'] ) {
					wp_send_json_error( $user_email_validate['message'] );
					wp_die();
				}
			} else {
				$current_user = wp_get_current_user();
				$user_email   = $current_user->user_email;
				$user_name    = $current_user->display_name;
			}


			$post_author_validate = $this->validate_post_author( $form_settings_result );

			if ( ! $post_author_validate['status'] ) {
				wp_send_json_error( $post_author_validate['message'] );
				wp_die();
			}

			// Validate featured image
			$is_allow_featured = $this->is_featured_image_allowed( $form_settings_result );

			if ( $is_allow_featured ) {
				// Check if the post has a featured image (thumbnail) assigned
				$yes_post_featured = ! empty( $created_post_id ) ? has_post_thumbnail( $created_post_id ) : false;


				// Nonce verification has already been performed prior to calling this function (see line 71).
				// If an image file is provided, it sanitizes and validates the featured image via function: get_sanitized_featured_image
				// If validation fails, an error message is sent and the script is terminated before executing the next function.
				if ( isset( $_FILES['image'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
					$featured_image_validate = $this->get_sanitized_featured_image( $_FILES['image'], $form_settings_result ); //phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

					if ( ! empty( $featured_image_validate['status'] ) ) {
						wp_send_json_error( $featured_image_validate['message'] );
						wp_die();
					} else {
						$featured_image_file = $featured_image_validate;
					}
				} else {
					$featured_image_file = null;
				}

				if ( ! $yes_post_featured && $is_allow_featured === 'require' && empty( $featured_image_file ) ) {
					wp_send_json_error( esc_html__( 'A featured image is required for this submission. Please upload an image to proceed.', 'easy-post-submission' ) );
					wp_die();
				}
			} else {

				// Unset featured file if disable setting
				$featured_image_file      = null;
				$is_remove_featured_image = true;
			}

			$post_author = $post_author_validate['post_author'];
			$title       = $this->try_get_old_title( $created_post_id, $title, $form_settings_result );
			$excerpt     = $this->try_get_old_excerpt( $created_post_id, $excerpt, $form_settings_result );
			$post_status = $this->get_post_status( $form_settings_result );

			$post_images_handled = $this->try_convert_images_in_content( $content, $title );
			$image_ids           = $post_images_handled['image_ids'];

			$post_data = [
				'post_title'    => esc_html( $title ),
				'post_content'  => wp_kses_post( $post_images_handled['content'] ),
				'post_status'   => esc_attr( $post_status ),
				'post_author'   => esc_attr( $post_author ),
				'post_excerpt'  => esc_html( $excerpt ),
				'post_type'     => 'post',
				'post_category' => $this->filter_categories( $categories, $form_settings_result ),
				'tags_input'    => $this->filter_tags( $post_tags, $form_settings_result ),
			];

			$is_new_post = empty( $created_post_id );
			if ( ! $is_new_post ) {
				$post_data['ID'] = $created_post_id;
			}

			$post_id = $is_new_post ? wp_insert_post( $post_data ) : wp_update_post( $post_data );

			if ( is_wp_error( $post_id ) ) {
				return new WP_Error( 'post_creation_failed', esc_html__( 'Failed to create the post. Please contact the webmaster for assistance.', 'easy-post-submission' ), [ 'status' => 500 ] );
			}

			$this->try_set_featured_image( $post_id, $form_settings_result, $featured_image_file, $is_remove_featured_image );

			$this->try_store_images_from_post_content( $image_ids, $post_id, $title );

			$this->update_author_info( $post_id, $user_name, $user_email, $form_settings_result );

			$this->update_custom_field_data( $post_id, $custom_fields_data );

			update_post_meta( $post_id, 'rbsm_form_id', $form_id );

			$post_link    = get_permalink( $post_id );
			$mail_message = $this->try_send_email_notification( $post_link, $title, $form_settings_result, $user_email, $post_status, $is_new_post );

			$submit_post_result = [
				'post_id' => $post_id,
				'url'     => $post_link,
				'message' => esc_html__( 'Your post has been submitted successfully!', 'easy-post-submission' ),
			];

			if ( ! empty( $mail_message ) ) {
				$submit_post_result['email_message'] = $mail_message;
			}

			wp_send_json_success( $submit_post_result );
			wp_die();
		}

		/**
		 * Filters and processes the categories associated with a post submission.
		 *
		 * This method checks whether categories are provided by the user and processes them based on the form settings.
		 * If the form settings restrict adding new categories, only existing categories are retained.
		 *
		 * @param array $categories The categories submitted with the post.
		 * @param array $form_settings_result The settings for the form, which include category handling settings.
		 *
		 * @return array                              The filtered categories to be associated with the post.
		 */
		private function filter_categories( $categories, $form_settings_result ) {

			if ( ( empty( $categories ) || ! is_array( $categories ) ) && ! empty( $form_settings_result['form_fields']['categories']['auto_assign_category_ids'] ) ) {
				return array_map( 'absint', $form_settings_result['form_fields']['categories']['auto_assign_category_ids'] );
			}

			$category_ids = [];

			foreach ( $categories as $id ) {
				$category = get_term_by( 'term_id', $id, 'category' );
				if ( ! empty( $category ) && ! is_wp_error( $category ) ) {
					$category_ids[] = $category->term_id;
				}
			}

			return $category_ids;
		}

		/**
		 * Filters and processes the tags associated with a post submission.
		 *
		 * This method checks whether tags are provided by the user and processes them based on the form settings.
		 * If the form settings allow automatic tag assignment, those tags are returned. If the form settings restrict
		 * adding new tags, only existing tags are kept. Otherwise, the provided tags are returned as-is.
		 *
		 * @param array $tags The tags submitted with the post.
		 * @param array $form_settings_result The settings for the form, which include tag handling settings.
		 *
		 * @return array                              The filtered tags to be associated with the post.
		 */
		private function filter_tags( $tags, $form_settings_result ) {

			if ( ( empty( $tags ) || ! is_array( $tags ) ) && ! empty( $form_settings_result['form_fields']['tags']['auto_assign_tags'] ) ) {
				return array_map( 'esc_attr', $form_settings_result['form_fields']['tags']['auto_assign_tags'] );
			}

			if ( empty( $form_settings_result['form_fields']['tags']['allow_add_new_tag'] ) ) {
				$filtered_tags = [];
				foreach ( $tags as $name ) {
					$tag = get_term_by( 'name', $name, 'post_tag' );
					if ( ! empty( $tag ) && ! is_wp_error( $tag ) ) {
						$filtered_tags[] = $name;
					}
				}

				return $filtered_tags;
			}

			return $tags;
		}

		/**
		 * Attempts to send email notifications after a post submission.
		 *
		 * This method checks the form settings to determine if email notifications should be sent to the admin and user
		 * after a post submission. It handles three types of notifications:
		 * 1. Admin notification after post submission.
		 * 2. User notification upon post submission.
		 * 3. User notification when the post is published (if applicable).
		 *
		 * @param string $post_link The link to the submitted post.
		 * @param string $title The title of the submitted post.
		 * @param array $form_settings_result The settings for the form, which includes email settings.
		 * @param string $user_email The email address of the user who submitted the post.
		 * @param string $post_status The status of the post (e.g., 'publish').
		 * @param bool $is_new_post A flag indicating whether the post is new.
		 *
		 * @return array                              An associative array with the status messages for each notification.
		 */
		private function try_send_email_notification( $post_link, $title, $form_settings_result, $user_email, $post_status, $is_new_post ) {

			$email_message = [];

			$should_notify_admin = (bool) ( $form_settings_result['email']['admin_mail']['status'] ?? false );
			if ( $should_notify_admin ) {
				$admin_message                  = $this->notify_admin_on_post_submission( $post_link, $title, $form_settings_result );
				$email_message['admin_message'] = $admin_message;
			}

			$should_notify_user_on_post_submission = (bool) ( $form_settings_result['email']['post_submit_notification']['status'] ?? false );
			if ( $should_notify_user_on_post_submission ) {
				$user_submitted_message                  = $this->notify_user_on_post_submission( $post_link, $title, $form_settings_result, $user_email, $is_new_post );
				$email_message['user_submitted_message'] = $user_submitted_message;
			}

			$should_notify_user_on_post_publish = (bool) ( $form_settings_result['email']['post_publish_notification']['status'] ?? false );
			if ( $should_notify_user_on_post_publish && $post_status === 'publish' ) {
				$user_published_message                  = $this->notify_user_on_post_publish( $post_link, $title, $form_settings_result, $user_email );
				$email_message['user_published_message'] = $user_published_message;
			}

			return $email_message;
		}

		/**
		 * Notifies the admin by email when a post is submitted.
		 *
		 * This method sends an email notification to the admin when a post is submitted. The subject and message of the
		 * email can be customized through the form settings. The notification includes the post title and a link to the
		 * submitted post. If no specific email is set for the admin in the form settings, the default email of the admin
		 * user will be used.
		 *
		 * @param string $post_link The link to the post that was submitted.
		 * @param string $title The title of the post that was submitted.
		 * @param array $form_settings_result The form settings containing the subject and message for the email.
		 *
		 * @return string                      A message indicating if the email was sent successfully or any errors.
		 */
		private function notify_admin_on_post_submission( $post_link, $title, $form_settings_result ) {

			$admin_mail = (string) ( $form_settings_result['email']['admin_mail']['email'] ?? '' );
			if ( empty( $admin_mail ) ) {
				$admin_mail = get_option( 'admin_email' );
			}

			$subject = (string) ( $form_settings_result['email']['admin_mail']['subject'] ?? esc_html__( 'The post ', 'easy-post-submission' ) . $title . esc_html__( ' has just submitted.', 'easy-post-submission' ) );
			$message = (string) ( $form_settings_result['email']['admin_mail']['message'] ?? esc_html__( 'The post ', 'easy-post-submission' ) . $title . esc_html__( ' has just submitted. Please check at: ', 'easy-post-submission' ) . $post_link );
			$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

			$placeholders = [
				'/{{post_title}}/' => $title,
				'/{{post_link}}/'  => $post_link,
			];

			$subject = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject );
			$message = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $message );
			$headers = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $headers );

			if ( wp_mail( $admin_mail, $subject, $message, $headers ) ) {
				return esc_html__( 'Admin mail was sent successfully.', 'easy-post-submission' );
			} else {
				return esc_html__( 'Admin mail sending failed.', 'easy-post-submission' );
			}
		}

		/**
		 * Notifies the user by email when a post is submitted.
		 *
		 * This method sends an email notification to the user (either the one associated with the post or the one specified)
		 * when a post is submitted. The subject and message of the email can be customized through the form settings.
		 * It will differ depending on whether it's a new post or an update to an existing post. If the email address is empty,
		 * the current user's email will be used. If there is still no email, the function will return a message indicating that
		 * the email address is missing.
		 *
		 * @param string $post_link The link to the post that was submitted.
		 * @param string $title The title of the post that was submitted.
		 * @param array $form_settings_result The form settings containing the subject and message for the email.
		 * @param string $user_email The email address to send the notification to (optional).
		 * @param bool $is_new_post Flag indicating if the post is new or an update.
		 *
		 * @return string                      A success message or an error message indicating an empty email address.
		 */
		private function notify_user_on_post_submission( $post_link, $title, $form_settings_result, $user_email, $is_new_post ) {

			if ( empty( $user_email ) ) {
				$current_user = wp_get_current_user();
				$user_email   = $current_user->user_email;
			}

			if ( empty( $user_email ) ) {
				return esc_html__( 'The user email is empty', 'easy-post-submission' );
			}

			$subject = (string) ( $form_settings_result['email']['post_submit_notification']['subject'] ?? esc_html__( 'The post ', 'easy-post-submission' ) . $title . esc_html__( ' has just submitted.', 'easy-post-submission' ) );
			$message = (string) ( $form_settings_result['email']['post_submit_notification']['message'] ?? esc_html__( 'The post ', 'easy-post-submission' ) . $title . esc_html__( ' has just submitted. Please check at: ', 'easy-post-submission' ) . $post_link );
			$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

			$subject = $is_new_post ? '[NEW POST]: ' . $subject : '[POST EDITED]: ' . $subject;

			$placeholders = [
				'/{{post_title}}/' => $title,
				'/{{post_link}}/'  => $post_link,
			];

			$subject = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject );
			$message = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $message );
			$headers = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $headers );

			if ( wp_mail( $user_email, $subject, $message, $headers ) ) {
				return esc_html__( 'User mail was sent successfully.', 'easy-post-submission' );
			} else {
				return esc_html__( 'User mail sending failed.', 'easy-post-submission' );
			}
		}

		/**
		 * Notifies the user by email when a post is published.
		 *
		 * This method sends an email notification to the user (either the one associated with the post or the one specified)
		 * when a post is published. The subject and message of the email can be customized through the form settings.
		 * If the email address is empty, the current user's email will be used. If there is still no email, the function
		 * will return a message indicating that the email address is missing.
		 *
		 * @param string $post_link The link to the post that was published.
		 * @param string $title The title of the post that was published.
		 * @param array $form_settings_result The form settings containing the subject and message for the email.
		 * @param string $user_email The email address to send the notification to (optional).
		 *
		 * @return string                    A success message or an error message indicating an empty email address.
		 */
		private function notify_user_on_post_publish( $post_link, $title, $form_settings_result, $user_email ) {

			if ( empty( $user_email ) ) {
				$current_user = wp_get_current_user();
				$user_email   = $current_user->user_email;
			}

			if ( empty( $user_email ) ) {
				return esc_html__( 'The user email address is empty.', 'easy-post-submission' );
			}

			$subject = (string) ( $form_settings_result['email']['post_publish_notification']['subject'] ?? esc_html__( 'The post ', 'easy-post-submission' ) . $title . esc_html__( ' has just published.', 'easy-post-submission' ) );
			$message = (string) ( $form_settings_result['email']['post_publish_notification']['message'] ?? esc_html__( 'The post ', 'easy-post-submission' ) . $title . esc_html__( ' has just published. Please check at: ', 'easy-post-submission' ) . $post_link );
			$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

			$placeholders = [
				'/{{post_title}}/' => $title,
				'/{{post_link}}/'  => $post_link,
			];

			$subject = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject );
			$message = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $message );
			$headers = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $headers );

			if ( wp_mail( $user_email, $subject, $message, $headers ) ) {
				return esc_html__( 'User mail was sent successfully.', 'easy-post-submission' );
			} else {
				return esc_html__( 'User mail sending failed.', 'easy-post-submission' );
			}
		}

		/**
		 * @param $post_link
		 * @param $title
		 * @param $form_settings_result
		 * @param $user_email
		 *
		 * @return string
		 */
		private function notify_user_when_post_trashed( $post_link, $title, $form_settings_result, $user_email ) {

			if ( empty( $user_email ) ) {
				$current_user = wp_get_current_user();
				$user_email   = $current_user->user_email;
			}

			if ( empty( $user_email ) ) {
				return esc_html__( 'The user email address is empty.', 'easy-post-submission' );
			}

			$subject = (string) ( $form_settings_result['email']['post_trash_notification']['subject'] ?? esc_html__( 'The post ', 'easy-post-submission' ) . $title . esc_html__( ' has just trashed.', 'easy-post-submission' ) );
			$subject = '[POST TRASHED]: ' . $subject;
			$message = (string) ( $form_settings_result['email']['post_trash_notification']['message'] ?? esc_html__( 'The post ', 'easy-post-submission' ) . $title . esc_html__( ' has just trashed. Please check at: ', 'easy-post-submission' ) . $post_link );
			$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

			$placeholders = [
				'/{{post_title}}/' => $title,
				'/{{post_link}}/'  => $post_link,
			];

			$subject = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject );
			$message = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $message );
			$headers = preg_replace( array_keys( $placeholders ), array_values( $placeholders ), $headers );

			if ( wp_mail( $user_email, $subject, $message, $headers ) ) {
				return esc_html__( 'User mail was sent successfully.', 'easy-post-submission' );
			} else {
				return esc_html__( 'User mail sending failed.', 'easy-post-submission' );
			}
		}

		/**
		 * Attempts to set or remove the featured image for a given post.
		 *
		 * This method will check if the featured image should be set or removed based on the provided form settings and
		 * the `is_remove_featured_image` flag. If a new image is provided, it will upload the image and set it as the
		 * featured image. If the flag indicates removal, it will delete the featured image from the post. Additionally,
		 * if no image is set, it will attempt to set a default featured image if configured in the form settings.
		 *
		 * @param int $post_id The ID of the post for which the featured image is being set or removed.
		 * @param array $form_settings_result The form settings that determine the behavior of featured image handling.
		 * @param array $featured_image_file The image data to be set as the featured image.
		 * @param bool $is_remove_featured_image Whether the featured image should be removed (true) or set (false).
		 */
		private function try_set_featured_image( $post_id, $form_settings_result, $featured_image_file, $is_remove_featured_image ) {

			// remove featured image if unset
			if ( $is_remove_featured_image ) {
				delete_post_thumbnail( $post_id );

				return;
			}

			// return if empty upload
			if ( empty( $featured_image_file ) ) {
				return;
			}

			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once( ABSPATH . 'wp-admin/includes/file.php' );
				require_once( ABSPATH . 'wp-admin/includes/media.php' );
				require_once( ABSPATH . 'wp-admin/includes/image.php' );
			}

			$upload = wp_handle_upload( $featured_image_file, array( 'test_form' => false ) );
			if ( isset( $upload['error'] ) ) {
				return;
			}

			if ( isset( $upload['file'] ) ) {
				$attachment    = array(
					'post_title'     => wp_basename( $upload['file'] ),
					'post_content'   => $upload['url'],
					'post_mime_type' => $upload['type'],
					'guid'           => $upload['url'],
					'context'        => 'rubySubmission',
					'post_status'    => 'private',
				);
				$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
				if ( ! is_wp_error( $attachment_id ) ) {
					$result = set_post_thumbnail( $post_id, $attachment_id );
				}
			}

			if ( empty( $result ) ) {
				$this->try_set_default_featured_image( $post_id, $form_settings_result );
			}
		}

		/**
		 * Attempts to store image IDs associated with a post in the post's metadata.
		 *
		 * This method will store the given image IDs as metadata for the post. The image IDs are stored under a custom
		 * meta key that is based on the post title, ensuring that each post has its own unique metadata for images.
		 * If the image IDs are provided, they will be saved in the post's metadata for later reference.
		 *
		 * @param array $image_ids The array of image IDs to be stored in the post metadata.
		 * @param int $post_id The ID of the post to which the images belong.
		 * @param string $title The title of the post, used to create a unique meta key.
		 */
		private function try_store_images_from_post_content( $image_ids, $post_id, $title ) {

			if ( ! empty( $image_ids ) ) {
				$meta_key = $title . '_images';
				update_post_meta( $post_id, $meta_key, $image_ids );
			}
		}

		/**
		 * Handles base64-encoded images in post content by uploading them and replacing base64 strings with image tags.
		 *
		 * This method scans the post content for base64-encoded images, uploads them, and returns the updated content
		 * with image tags replacing the base64-encoded images. It also returns the IDs of the uploaded images.
		 *
		 * @param string $content The post content that may contain base64-encoded images.
		 * @param string $title The title of the post, used when uploading the images.
		 *
		 * @return array An associative array containing:
		 *               - 'content' (string) the updated post content with image tags replacing base64 images.
		 *               - 'image_ids' (array) an array of IDs of the uploaded images.
		 */
		private function try_convert_images_in_content( $content, $title ) {

			$base64_pattern = '/<img[^>]+src="image\/[^;]+;base64,([^"]+)"[^>]*>/i';

			preg_match_all( $base64_pattern, $content, $base64_matches );

			$image_properties = $this->upload_images_and_get_properties( $title, $base64_matches );
			$image_urls       = $image_properties['image_urls'];
			$image_ids        = $image_properties['image_ids'];

			$image_tags = $this->create_image_tag_in_post( $image_urls );
			$content    = $this->convert_base64_to_img_tag( $content, $base64_matches[0], $image_tags );

			return [
				'content'   => $content,
				'image_ids' => $image_ids,
			];
		}

		/**
		 * Validates the post author based on form settings and user login status.
		 *
		 * This method checks whether the post author should be the logged-in user or a predefined default author.
		 * If the settings require a logged-in user and the user is not logged in, an error is returned.
		 * If the settings specify a default author, the method checks if the author exists and returns an error if not.
		 *
		 * @param array $form_settings_result The form settings containing user login and author access configurations.
		 *
		 * @return array An associative array containing:
		 *               - 'status' (bool) indicating whether the author validation is successful.
		 *               - 'message' (string) a message explaining the result of the validation.
		 *               - 'post_author' (int) the ID of the valid post author.
		 */
		private function validate_post_author( $form_settings_result ) {

			$author_access = (string) ( $form_settings_result['user_login']['author_access'] ?? 'only_logged_user' );

			$is_logged = is_user_logged_in();
			$author_id = $is_logged ? get_current_user_id() : (int) ( $form_settings_result['user_login']['assign_author_id'] ?? 0 );

			if ( $author_access === 'only_logged_user' && ! $is_logged ) {
				return [
					'status'      => false,
					'message'     => esc_html__( 'You need to log in before submitting a post.', 'easy-post-submission' ),
					'post_author' => 0,
				];
			}

			if ( empty( $author_id ) ) {
				return [
					'status'      => false,
					'message'     => esc_html__( 'The default author is not configured. Please contact the webmaster for assistance.', 'easy-post-submission' ),
					'post_author' => 0,
				];
			}

			if ( ! $is_logged && is_wp_error( get_user_by( 'ID', $author_id ) ) ) {
				return [
					'status'      => false,
					'message'     => esc_html__( 'An error occurred while trying to assign the author. Please contact the webmaster for assistance', 'easy-post-submission' ),
					'post_author' => 0,
				];
			}

			return [
				'status'      => true,
				'message'     => esc_html__( 'The author is valid and assigned.', 'easy-post-submission' ),
				'post_author' => $author_id,
			];
		}

		/**
		 * Updates the author information (name and email) for a post.
		 *
		 * This method updates the post's author information based on the provided user name and email,
		 * while taking into account the form settings. If the user name or email is disabled in the settings,
		 * the previous author information will be retained.
		 *
		 * @param int $post_id The ID of the post to update.
		 * @param string $user_name The user name to set as the author.
		 * @param string $user_email The user email to set as the author.
		 * @param array $form_settings_result An array containing the form settings that determine
		 *                                     whether the user name and email fields are enabled or disabled.
		 */
		private function update_author_info( $post_id, $user_name, $user_email, $form_settings_result ) {

			$user_name_setting = isset( $form_settings_result['form_fields']['user_name'] )
				? sanitize_text_field( $form_settings_result['form_fields']['user_name'] )
				: 'disable';

			$user_email_setting = isset( $form_settings_result['form_fields']['user_email'] )
				? sanitize_text_field( $form_settings_result['form_fields']['user_email'] )
				: 'disable';

			$old_author_info = get_post_meta( $post_id, 'rbsm_author_info', true );
			$old_user_name   = '';
			$old_user_email  = '';

			if ( ! empty( $old_author_info ) ) {
				$old_user_name  = isset( $old_author_info['user_name'] ) ? sanitize_text_field( $old_author_info['user_name'] ) : '';
				$old_user_email = isset( $old_author_info['user_email'] ) ? sanitize_text_field( $old_author_info['user_email'] ) : '';
			}

			$author_info = [
				'user_name'  => $user_name_setting === 'disable' ? $old_user_name : $user_name,
				'user_email' => $user_email_setting === 'disable' ? $old_user_email : $user_email,
			];

			update_post_meta( $post_id, 'rbsm_author_info', $author_info );
		}

		/**
		 * Sets a default featured image for a post if none exists.
		 *
		 * This method checks if the post already has a featured image. If not, it sets a default featured image
		 * based on the settings in the provided form settings. If the feature is disabled or the default image
		 * is not available, the post's featured image will not be updated.
		 *
		 * @param int $post_id The ID of the post to set the featured image for.
		 * @param array $form_settings_result An array containing the form settings, which includes the
		 *                                    default featured image and its status.
		 *
		 * @return bool Returns true if the default featured image is successfully set, or false if the post already has a featured image
		 *              or the default featured image is not available.
		 */
		private function try_set_default_featured_image( $post_id, $form_settings_result ) {

			$attachment_id = (int) ( $form_settings_result['form_fields']['featured_image']['default_featured_image'] ?? 0 );

			if ( ! empty( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}

		/**
		 * Updates custom field data for a given post.
		 *
		 * This method updates custom fields for a specific post based on the provided custom fields data.
		 * It sanitizes the content, label, name, and type before updating the post meta.
		 * The data is stored as an associative array in the post meta with keys 'content', 'label',
		 * 'meta_key', and 'type'.
		 *
		 * @param int $post_id The ID of the post to update.
		 * @param array $custom_fields_data An array of custom field data to be updated.
		 *                                Each entry should include 'content', 'label', 'name', and 'type'.
		 */
		private function update_custom_field_data( $post_id, $custom_fields_data ) {

			if ( ! empty( $custom_fields_data ) ) {
				foreach ( $custom_fields_data as $custom_field_data ) {

					$name    = isset( $custom_field_data['name'] ) ? sanitize_key( $custom_field_data['name'] ) : '';
					$content = isset( $custom_field_data['content'] ) ? sanitize_text_field( $custom_field_data['content'] ) : '';
					$label   = isset( $custom_field_data['label'] ) ? sanitize_text_field( $custom_field_data['label'] ) : '';
					$type    = isset( $custom_field_data['type'] ) ? sanitize_text_field( $custom_field_data['type'] ) : '';

					if ( empty( $content ) || empty( $label ) || empty( $name ) || empty( $type ) ) {
						continue;
					}

					update_post_meta( $post_id, $name, [
						'content'  => $content,
						'label'    => $label,
						'meta_key' => $name,
						'type'     => $type,
					] );
				}
			}
		}

		/**
		 * Retrieves form settings based on the provided form ID.
		 *
		 * This method validates the nonce, checks if the required data is provided in the request,
		 * and retrieves the form settings using the form ID. The data is expected to be in JSON format.
		 * If any of the steps fail, appropriate error messages are returned.
		 *
		 * @return void
		 */
		public function get_form_by_id() {

			$data = $this->get_sanitized_submission_data();

			$id = $data['id'];
			if ( empty( $id ) ) {
				wp_send_json_error( esc_html__( 'Form ID is missing.', 'easy-post-submission' ) );
				wp_die();
			}

			$row = $this->get_form_settings_by_id( $id );

			if ( $row !== false ) {
				wp_send_json_success( $row );
			} else {
				wp_send_json_error( esc_html__( 'No records found!', 'easy-post-submission' ) );
			}

			wp_die();
		}

		/**
		 * Retrieves the form settings by the given form ID form database
		 *
		 * This method queries the database to fetch form settings from the 'rb_submission' table
		 * based on the provided form ID. It uses the global `$wpdb` object to execute a safe query
		 * and returns the corresponding form settings row.
		 *
		 * @param int $form_id The ID of the form whose settings are to be retrieved.
		 *
		 * @return object|null The form settings as an object if found, or null if no settings are found.
		 */
		private function get_form_settings_by_id( $form_id ) {

			global $wpdb;

			$form_id = (int) $form_id;

			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}rb_submission WHERE id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		}

		/**
		 * Retrieves all posts created by the currently logged-in user with pagination support.
		 *
		 * This method validates the nonce, checks if the user is logged in, and processes the requested page of posts.
		 * It queries posts by the logged-in user, including drafts and pending posts, and returns the relevant data in JSON format.
		 * The response includes the list of posts, whether post views should be displayed, and if the current page is the final page of results.
		 *
		 * @return void Outputs a JSON response with post data or an error message.
		 *
		 */
		public function get_user_posts() {

			$this->validate_logged_user();
			$data = $this->get_sanitized_submission_data();

			$paged = $data['paged'];

			if ( empty( $paged ) ) {
				wp_send_json_error( esc_html__( 'Page number is empty!', 'easy-post-submission' ) );
				wp_die();
			}

			$current_user = wp_get_current_user();
			$user_id      = $current_user->ID;

			$should_display_post_view = function_exists( 'pvc_get_post_views' );

			$args = [
				'post_type'      => 'post',
				'author'         => $user_id,
				'posts_per_page' => 10,
				'paged'          => $paged,
				'meta_key'       => 'rbsm_form_id',
				'post_status'    => [ 'publish', 'pending', 'draft' ],
			];

			$query      = new WP_Query( $args );
			$user_posts = [];

			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					global $post;

					$post_id        = $post->ID;
					$title          = $post->post_title;
					$date           = $post->post_date;
					$status         = $post->post_status;
					$short_desc     = $post->post_excerpt;
					$categories_raw = get_the_category( $post_id );
					$tags_raw       = get_the_tags( $post_id );
					$link           = get_permalink( $post_id );

					if ( empty( $short_desc ) ) {
						$short_desc = get_the_content();
					}

					if ( ! empty( $short_desc ) ) {
						$short_desc = wp_trim_words( $short_desc, 12, '...' );
					}

					$categories = [];
					if ( $categories_raw ) {
						$categories = array_map( function ( $category ) {

							return $category->name;
						}, $categories_raw );
					}

					$tags = [];
					if ( $tags_raw ) {
						$tags = array_map( function ( $tag ) {

							return $tag->name;
						}, $tags_raw );
					}

					$post_view = 0;
					if ( function_exists( 'pvc_get_post_views' ) ) {
						$post_view = pvc_get_post_views( $post_id );
					}

					$user_posts[] = [
						'title'      => $title,
						'categories' => $categories,
						'tags'       => $tags,
						'date'       => $date,
						'post_id'    => $post_id,
						'post_view'  => $post_view,
						'status'     => $status,
						'link'       => $link,
						'short_desc' => $short_desc,
					];
				}

				$is_final_page = $query->max_num_pages === $query->get( 'paged' ) || false;
				wp_reset_postdata();
				wp_send_json_success( [
					'user_posts'               => $user_posts,
					'should_display_post_view' => $should_display_post_view,
					'is_final_page'            => $is_final_page,
				] );

				wp_die();
			} else {
				wp_send_json_error( esc_html__( 'No more posts found.', 'easy-post-submission' ) );
				wp_die();
			}
		}

		/**
		 * Attempts to notify a user via email when a post is published,
		 * based on specific form settings tied to the post.
		 *
		 * @param int $ID The ID of the post being updated.
		 * @param WP_Post $post_after The post object after the update.
		 * @param WP_Post $post_before The post object before the update.
		 *
		 * @return void
		 */
		public function try_notify_on_post_publish( $ID, $post_after, $post_before ) {

			$form_submission_id = get_post_meta( $ID, 'rbsm_form_id', true );

			if ( ! $form_submission_id ) {
				return;
			}

			$form_settings = $this->get_form_settings_by_id( $form_submission_id );

			if ( ! $form_settings ) {
				return;
			}

			if ( $post_before->post_status !== 'publish' && $post_after->post_status === 'publish' ) {

				$form_settings_result = json_decode( wp_kses_post( wp_unslash( $form_settings->data ) ), true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return;
				}

				$user_published_message = (bool) ( $form_settings_result['email']['post_publish_notification']['status'] ?? false );
				if ( ! $user_published_message ) {
					return;
				}

				$title      = $post_after->post_title;
				$post_link  = get_permalink( $ID );
				$user_email = get_the_author_meta( 'user_email', $post_after->post_author );
				$author_info = get_post_meta( $ID, 'rbsm_author_info', true );

				if ( $author_info ) {
					$user_email = (string) ( $author_info['user_email'] ?? '' );
				}

				if ( empty( $user_email ) ) {
					$user_email = get_the_author_meta( 'user_email', $post_after->post_author );
				}

				$this->notify_user_on_post_publish( $post_link, $title, $form_settings_result, $user_email );
			}
		}

		/**
		 * Retrieves the form settings associated with a specific post ID.
		 *
		 * This method checks if a post has an associated form ID (stored in post meta) and retrieves the corresponding
		 * form settings based on that ID. It returns the form settings if found, or an error status if no form ID
		 * is associated with the post or if the form settings cannot be retrieved.
		 *
		 * @param int $post_id The ID of the post for which the form settings need to be retrieved.
		 *
		 * @return array An associative array containing:
		 *               - 'status' (bool): Whether the form settings retrieval was successful.
		 *               - 'data' (mixed): The form settings data if successful, or `null` if not found.
		 *               - If no form ID is found or if the form settings cannot be retrieved, 'status' is `false`
		 *                 and 'data' is `null`.
		 */
		private function get_form_settings_by_post_id( $post_id ) {

			$form_submission_id = Easy_Post_Submission_Client_Helper::get_instance()->get_form_id_by_submission( $post_id );

			if ( ! $form_submission_id ) {
				return [
					'status' => false,
					'data'   => null,
				];
			}


			$form_submission = $this->get_form_settings_by_id( $form_submission_id );
			if ( ! $form_submission ) {
				return [
					'status' => false,
					'data'   => null,
				];
			}

			return [
				'status' => true,
				'data'   => $form_submission,
			];
		}

		/**
		 * Retrieves the user's email associated with a post ID.
		 *
		 * This method retrieves the email of the user who is associated with the given post ID.
		 * It checks if the post has a custom meta field containing the author's email and returns it.
		 *
		 * @param int $post_id The ID of the post from which the user's email will be retrieved.
		 *
		 * @return string The user's email associated with the post, or an empty string if not found.
		 */
		private function get_user_email_by_post_id( $post_id ) {

			$user_email  = '';
			$author_info = get_post_meta( $post_id, 'rbsm_author_info', true );

			if ( $author_info ) {
				$user_email = (string) ( $author_info['user_email'] ?? '' );
			}

			if ( ! empty( $user_email ) ) {
				return $user_email;
			}

			$author_id = get_post_field( 'post_author', $post_id );
			$user      = get_user_by( 'id', $author_id );
			if ( $user ) {
				$user_email = $user->user_email;
			}

			return $user_email;
		}
	}
}
/** load */
Easy_Post_Submission_Client_Ajax_Handler::get_instance();
