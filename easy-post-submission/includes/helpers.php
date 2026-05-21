<?php
/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Easy_Post_Submission_Client_Helper', false ) ) {
	/**
	 * Class Easy_Post_Submission_Client_Helper
	 */
	class Easy_Post_Submission_Client_Helper {
		private static $instance;
		public static $post_manager_settings;

		/**
		 * @return Easy_Post_Submission_Client_Helper
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Easy_Post_Submission_Client_Helper constructor.
		 */
		private function __construct() {

			self::$instance = $this;

			self::$post_manager_settings = get_option( 'easy_post_submission_post_manager_settings', [] );
		}

		/**
		 * Retrieves categories for the submission form.
		 *
		 * This function fetches categories for the given submission form data, with the option to exclude
		 * specific category IDs based on the form configuration.
		 *
		 * @param array $data The form submission data containing fields and other related information.
		 *                    Expected to contain a 'form_fields' key with a 'categories' sub-array that can
		 *                    include an 'exclude_category_ids' key.
		 *
		 * @return array The list of categories, which may be filtered based on exclusion criteria.
		 *               Returns an empty array if no categories are found or filtered out.
		 */
		public function get_categories( $data ) {

			$exclude_categories = ! empty( $data['form_fields']['categories']['exclude_category_ids'] )
				? array_map( 'intval', (array) $data['form_fields']['categories']['exclude_category_ids'] )
				: [];

			$params = [
				'taxonomy'   => 'category',
				'hide_empty' => false,
			];

			if ( ! empty( $exclude_categories ) ) {
				$params['exclude'] = $exclude_categories;
			}

			$terms = get_terms( $params );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return [];
			}

			return array_map(
				function ( $term ) {
					return [
						'term_id' => $term->term_id,
						'name'    => $term->name,
						'slug'    => $term->slug,
					];
				},
				$terms
			);
		}

		/**
		 * Retrieves tags for the submission form.
		 *
		 * This function fetches tags for the given submission form data, with the option to exclude
		 * specific tag IDs based on the form configuration.
		 *
		 * @param array $data The form submission data containing fields and other related information.
		 *                    Expected to contain a 'form_fields' key with a 'tags' sub-array that can
		 *                    include an 'exclude_tag_ids' key.
		 *
		 * @return array The list of tags, which may be filtered based on exclusion criteria.
		 *               Returns an empty array if no tags are found or filtered out.
		 */
		public function get_tags( $data ) {

			$exclude_tags = isset( $data['form_fields']['tags']['exclude_tag_ids'] )
				? array_map( 'intval', (array) $data['form_fields']['tags']['exclude_tag_ids'] )
				: [];

			$params = [
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			];

			if ( ! empty( $exclude_tags ) ) {
				$params['exclude'] = $exclude_tags;
			}

			$terms = get_terms( $params );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return [];
			}

			return array_map(
				function ( $term ) {
					return [
						'term_id' => $term->term_id,
						'name'    => $term->name,
						'slug'    => $term->slug,
					];
				},
				$terms
			);
		}

		/**
		 * Retrieves the raw submission form settings by form ID.
		 *
		 * This function queries the database to fetch the submission form settings for a
		 * specific form based on the provided form ID.
		 *
		 * @param int $form_id The ID of the form to retrieve settings for.
		 *
		 * @return array|object|stdClass|null The form settings, returned as a row object (usually a stdClass),
		 *                                    or null if no settings are found for the provided form ID.
		 */
		public function get_raw_submission_form_settings( $form_id ) {
			global $wpdb;

			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}rb_submission WHERE id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		/**
		 * Filter form settings by unsetting specified keys.
		 *
		 * @param array $settings The form settings array to filter.
		 *
		 * @return array The filtered settings with specified keys unset.
		 */
		public function filter_settings( $settings = [] ) {

			unset( $settings['email'] );
			unset( $settings['security_fields']['challenge']['response'] );

			// Remove old reCAPTCHA data for backward compatibility
			unset( $settings['security_fields']['recaptcha'] );

			return $settings;
		}

		/**
		 * Retrieves data about the posts created by the logged-in user.
		 *
		 * This function fetches the posts for the logged-in user, optionally paginated
		 * based on the `paged` parameter. It returns an array with post data and information
		 * regarding pagination and display conditions.
		 *
		 * @param int $paged The current page number for pagination. Defaults to 1.
		 *
		 * @return array An associative array
		 */
		public function get_user_posts_data( $paged = 1 ) {

			if ( ! is_user_logged_in() ) {
				return [
					'user_posts'               => [],
					'should_display_post_view' => false,
					'is_final_page'            => true,
				];
			}

			$args = [
				'post_type'      => 'post',
				'author'         => get_current_user_id(),
				'posts_per_page' => 10,
				'paged'          => $paged,
				'meta_key'       => 'rbsm_form_id',
				'post_status'    => [ 'publish', 'pending', 'draft' ],
			];

			$yes_post_view = self::is_post_views_available();
			$query         = new WP_Query( $args );
			$user_posts    = [];

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
						$short_desc = $post->post_content;
					}
					$short_desc = wp_trim_words( wp_strip_all_tags( $short_desc ), 10, '...' );

					$categories = [];
					if ( $categories_raw ) {
						$categories = array_map(
							function ( $category ) {
								return $category->name;
							},
							$categories_raw
						);
					}

					$tags = [];
					if ( $tags_raw ) {
						$tags = array_map(
							function ( $tag ) {
								return $tag->name;
							},
							$tags_raw
						);
					}

					$post_view = self::get_post_views( $post_id );

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

				wp_reset_postdata();
			}

			$is_final_page = $query->max_num_pages === $query->get( 'paged' ) || false;

			return [
				'user_posts'               => $user_posts,
				'should_display_post_view' => $yes_post_view,
				'is_final_page'            => $is_final_page,
			];
		}

		/**
		 * Retrieves the settings for the post manager.
		 *
		 * This function returns the stored settings for the post manager. If no settings
		 * are available, it may return `false`. The settings are typically loaded and
		 * stored in a static variable.
		 *
		 * @return mixed|false The post manager settings, or false if the settings are not available.
		 */
		public function get_post_manager_settings() {

			$settings = self::$post_manager_settings;

			// SECURITY: Remove secret keys before sending to frontend
			if ( isset( $settings['recaptcha']['secret_key'] ) ) {
				unset( $settings['recaptcha']['secret_key'] );
			}
			if ( isset( $settings['recaptcha']['turnstile_secret_key'] ) ) {
				unset( $settings['recaptcha']['turnstile_secret_key'] );
			}

			return $settings;
		}

		/**
		 * Get the form ID associated with a submitted post.
		 *
		 * This function retrieves the form ID linked to a specific post submission. If the form ID is invalid or
		 * not found, it falls back to the default form submission ID (if set). If no valid form ID is found, it returns false.
		 *
		 * @param int $post_id The ID of the submitted post.
		 *
		 * @return int|false The form ID if found and valid, otherwise false.
		 */
		public function get_form_id_by_submission( $post_id ) {

			if ( empty( $post_id ) ) {
				return false;
			}

			$form_id = get_post_meta( $post_id, 'rbsm_form_id', true );

			if ( $this->check_form_id_exist( (int) $form_id ) ) {
				return $form_id;
			}

			if ( ! empty( self::$post_manager_settings['user_profile']['form_submission_default_id'] ) ) {
				$form_id = self::$post_manager_settings['user_profile']['form_submission_default_id'];
			}

			if ( empty( $form_id ) || ! $this->check_form_id_exist( (int) $form_id ) ) {
				return false;
			}

			return $form_id;
		}

		/**
		 * Checks if a form exists by its form ID.
		 *
		 * This function queries the database to check if a form with the given ID exists
		 * in the submission table. It returns `true` if the form is found, otherwise `false`.
		 *
		 * @param int $form_id The ID of the form to check for existence.
		 *
		 * @return bool `true` if the form exists, `false` otherwise.
		 */
		private function check_form_id_exist( $form_id ) {

			global $wpdb;

			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rb_submission WHERE id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		/**
		 * Get the configured captcha type
		 *
		 * @since 2.4.0
		 *
		 * @return string The captcha type: 'none', 'recaptcha', or 'turnstile'
		 */
		public static function get_captcha_type() {
			$settings = self::$post_manager_settings;

			// If captcha_type is explicitly set, use it
			if ( isset( $settings['recaptcha']['captcha_type'] ) ) {
				return $settings['recaptcha']['captcha_type'];
			}

			// Backward compatibility: if no captcha_type set but reCAPTCHA keys exist,
			// default to 'recaptcha' to preserve existing functionality
			if ( ! empty( $settings['recaptcha']['site_key'] ) && ! empty( $settings['recaptcha']['secret_key'] ) ) {
				return 'recaptcha';
			}

			return 'none';
		}

		/**
		 * Get global reCAPTCHA settings
		 *
		 * @return array|false Array with site_key and secret_key, or false if not configured
		 */
		public static function get_global_recaptcha_settings() {
			$settings = self::$post_manager_settings;

			if ( empty( $settings['recaptcha']['site_key'] ) || empty( $settings['recaptcha']['secret_key'] ) ) {
				return false;
			}

			return [
				'recaptcha_site_key'   => $settings['recaptcha']['site_key'],
				'recaptcha_secret_key' => $settings['recaptcha']['secret_key'],
			];
		}

		/**
		 * Get global Turnstile settings
		 *
		 * @since 2.4.0
		 *
		 * @return array|false Array with site_key and secret_key, or false if not configured
		 */
		public static function get_global_turnstile_settings() {
			$settings = self::$post_manager_settings;

			if ( empty( $settings['recaptcha']['turnstile_site_key'] ) || empty( $settings['recaptcha']['turnstile_secret_key'] ) ) {
				return false;
			}

			return [
				'turnstile_site_key'   => $settings['recaptcha']['turnstile_site_key'],
				'turnstile_secret_key' => $settings['recaptcha']['turnstile_secret_key'],
			];
		}

		/**
		 * Get global captcha settings based on the configured captcha type
		 *
		 * @since 2.4.0
		 *
		 * @return array|false Array with captcha settings, or false if not configured
		 */
		public static function get_global_captcha_settings() {
			$captcha_type = self::get_captcha_type();

			if ( 'recaptcha' === $captcha_type ) {
				$recaptcha_settings = self::get_global_recaptcha_settings();
				if ( $recaptcha_settings ) {
					return [
						'type'       => 'recaptcha',
						'site_key'   => $recaptcha_settings['recaptcha_site_key'],
						'secret_key' => $recaptcha_settings['recaptcha_secret_key'],
					];
				}
			} elseif ( 'turnstile' === $captcha_type ) {
				$turnstile_settings = self::get_global_turnstile_settings();
				if ( $turnstile_settings ) {
					return [
						'type'       => 'turnstile',
						'site_key'   => $turnstile_settings['turnstile_site_key'],
						'secret_key' => $turnstile_settings['turnstile_secret_key'],
					];
				}
			}

			return false;
		}

		/**
		 * Check if captcha is enabled for forms
		 *
		 * @return bool
		 */
		public static function is_captcha_enabled_for_forms() {
			$settings     = self::$post_manager_settings;
			$captcha_type = self::get_captcha_type();

			if ( 'none' === $captcha_type ) {
				return false;
			}

			return ! empty( $settings['recaptcha']['enable_for_forms'] );
		}

		/**
		 * Check if captcha is enabled for login
		 *
		 * @return bool
		 */
		public static function is_captcha_enabled_for_login() {
			$settings     = self::$post_manager_settings;
			$captcha_type = self::get_captcha_type();

			if ( 'none' === $captcha_type ) {
				return false;
			}

			return ! empty( $settings['recaptcha']['enable_for_login'] );
		}

		/**
		 * Check if captcha is enabled for registration
		 *
		 * @return bool
		 */
		public static function is_captcha_enabled_for_register() {
			$settings     = self::$post_manager_settings;
			$captcha_type = self::get_captcha_type();

			if ( 'none' === $captcha_type ) {
				return false;
			}

			return ! empty( $settings['recaptcha']['enable_for_register'] );
		}

		/**
		 * Check if reCAPTCHA is enabled for forms (legacy compatibility)
		 *
		 * @return bool
		 */
		public static function is_recaptcha_enabled_for_forms() {
			return self::is_captcha_enabled_for_forms() && 'recaptcha' === self::get_captcha_type();
		}

		/**
		 * Check if reCAPTCHA is enabled for login (legacy compatibility)
		 *
		 * @return bool
		 */
		public static function is_recaptcha_enabled_for_login() {
			return self::is_captcha_enabled_for_login() && 'recaptcha' === self::get_captcha_type();
		}

		/**
		 * Check if reCAPTCHA is enabled for registration (legacy compatibility)
		 *
		 * @return bool
		 */
		public static function is_recaptcha_enabled_for_register() {
			return self::is_captcha_enabled_for_register() && 'recaptcha' === self::get_captcha_type();
		}

		/**
		 * Check if a post views counter plugin is available.
		 *
		 * Checks for Light View Counter (lightvc_get_post_views) first,
		 * then falls back to Post Views Counter (pvc_get_post_views).
		 *
		 * @since 2.3.0
		 *
		 * @return bool True if a post views function is available, false otherwise.
		 */
		public static function is_post_views_available() {
			return function_exists( 'lightvc_get_post_views' ) || function_exists( 'pvc_get_post_views' );
		}

		/**
		 * Get post views count for a given post.
		 *
		 * Uses Light View Counter (lightvc_get_post_views) if available,
		 * falls back to Post Views Counter (pvc_get_post_views).
		 *
		 * @since 2.3.0
		 *
		 * @param int $post_id The post ID.
		 *
		 * @return int The post views count, or 0 if no counter is available.
		 */
		public static function get_post_views( $post_id ) {
			if ( function_exists( 'lightvc_get_post_views' ) ) {
				return (int) lightvc_get_post_views( $post_id );
			}

			if ( function_exists( 'pvc_get_post_views' ) ) {
				return (int) pvc_get_post_views( $post_id );
			}

			return 0;
		}

		/**
		 * Process base64 images in content and upload them to the media library.
		 *
		 * Extracts base64-encoded images from HTML content, uploads them to WordPress
		 * media library, and replaces the data URIs with the uploaded file URLs.
		 *
		 * @since 2.5.0
		 *
		 * @param string $content      The HTML content containing base64 images.
		 * @param int    $post_id      Optional. The post ID to attach images to. Default 0.
		 * @param array  $form_settings Optional. Form settings for validation. Default empty array.
		 *
		 * @return array {
		 *     Array containing processed content and status.
		 *
		 *     @type bool   $success Whether all images were processed successfully.
		 *     @type string $content The processed content with media URLs.
		 *     @type string $message Error message if any image failed validation.
		 *     @type array  $attachment_ids Array of created attachment IDs.
		 * }
		 */
		public static function process_base64_images_in_content( $content, $post_id = 0, $form_settings = [] ) {
			$result = [
				'success'        => true,
				'content'        => $content,
				'message'        => '',
				'attachment_ids' => [],
			];

			if ( empty( $content ) ) {
				return $result;
			}

			// Match all base64 image data URIs in img src attributes.
			$pattern = '/<img([^>]*?)src=["\']data:image\/(jpeg|jpg|png|gif|webp);base64,([^"\']+)["\']([^>]*)>/i';

			if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
				return $result;
			}

			// Get allowed file types and size limits from form settings.
			$allowed_types    = [ 'jpeg', 'jpg', 'png', 'gif', 'webp' ];
			$max_size_kb      = 0;
			$max_images_count = 0;

			if ( ! empty( $form_settings['form_fields']['content_images'] ) ) {
				$image_settings = $form_settings['form_fields']['content_images'];

				if ( ! empty( $image_settings['upload_file_size_limit'] ) ) {
					$max_size_kb = absint( $image_settings['upload_file_size_limit'] );
				}

				if ( ! empty( $image_settings['maximum_images_count'] ) ) {
					$max_images_count = absint( $image_settings['maximum_images_count'] );
				}
			}

			// Check max images count.
			if ( $max_images_count > 0 && count( $matches ) > $max_images_count ) {
				$result['success'] = false;
				$result['message'] = sprintf(
					/* translators: %d: maximum number of images allowed */
					esc_html__( 'Too many images. Maximum allowed: %d', 'easy-post-submission' ),
					$max_images_count
				);
				return $result;
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$processed_content = $content;

			foreach ( $matches as $match ) {
				$full_tag    = $match[0];
				$before_src  = $match[1];
				$image_type  = strtolower( $match[2] );
				$base64_data = $match[3];
				$after_src   = $match[4];

				// Normalize jpeg/jpg.
				if ( 'jpg' === $image_type ) {
					$image_type = 'jpeg';
				}

				// Validate file type.
				if ( ! in_array( $image_type, $allowed_types, true ) ) {
					self::cleanup_attachments( $result['attachment_ids'] );
					$result['success']        = false;
					$result['message']        = esc_html__( 'Invalid image type detected in content.', 'easy-post-submission' );
					$result['attachment_ids'] = [];
					return $result;
				}

				// Decode base64 data.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required to process user-uploaded base64 images from Quill editor.
				$decoded_data = base64_decode( $base64_data, true );

				if ( false === $decoded_data ) {
					self::cleanup_attachments( $result['attachment_ids'] );
					$result['success']        = false;
					$result['message']        = esc_html__( 'Invalid image data detected in content.', 'easy-post-submission' );
					$result['attachment_ids'] = [];
					return $result;
				}

				// Check file size.
				$file_size = strlen( $decoded_data );

				if ( $max_size_kb > 0 && $file_size > ( $max_size_kb * 1024 ) ) {
					self::cleanup_attachments( $result['attachment_ids'] );
					$result['success'] = false;
					$result['message'] = sprintf(
						/* translators: %d: maximum file size in KB */
						esc_html__( 'Image size exceeds the allowed limit of %d KB.', 'easy-post-submission' ),
						$max_size_kb
					);
					$result['attachment_ids'] = [];
					return $result;
				}

				// Verify MIME type matches the declared type.
				$finfo     = new finfo( FILEINFO_MIME_TYPE );
				$mime_type = $finfo->buffer( $decoded_data );

				$expected_mime = 'image/' . $image_type;
				if ( $mime_type !== $expected_mime && ! ( 'image/jpeg' === $mime_type && 'jpeg' === $image_type ) ) {
					// Allow some flexibility for JPEG variants.
					if ( ! in_array( $mime_type, [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true ) ) {
						self::cleanup_attachments( $result['attachment_ids'] );
						$result['success']        = false;
						$result['message']        = esc_html__( 'Image content does not match declared type.', 'easy-post-submission' );
						$result['attachment_ids'] = [];
						return $result;
					}
					// Update image type based on actual MIME.
					$image_type = str_replace( 'image/', '', $mime_type );
				}

				// Generate unique filename.
				$filename = 'rbsm-upload-' . wp_generate_uuid4() . '.' . ( 'jpeg' === $image_type ? 'jpg' : $image_type );

				// Upload to WordPress.
				$upload = wp_upload_bits( $filename, null, $decoded_data );

				if ( ! empty( $upload['error'] ) ) {
					self::cleanup_attachments( $result['attachment_ids'] );
					$result['success']        = false;
					$result['message']        = esc_html__( 'Failed to upload image.', 'easy-post-submission' );
					$result['attachment_ids'] = [];
					return $result;
				}

				// Create attachment.
				$attachment_data = [
					'post_mime_type' => $upload['type'],
					'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				];

				$attachment_id = wp_insert_attachment( $attachment_data, $upload['file'], $post_id );

				if ( is_wp_error( $attachment_id ) ) {
					// Clean up uploaded file and previous attachments on failure.
					wp_delete_file( $upload['file'] );
					self::cleanup_attachments( $result['attachment_ids'] );
					$result['success']        = false;
					$result['message']        = esc_html__( 'Failed to create media attachment.', 'easy-post-submission' );
					$result['attachment_ids'] = [];
					return $result;
				}

				// Generate attachment metadata.
				$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
				wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

				$result['attachment_ids'][] = $attachment_id;

				// Replace base64 with uploaded URL in content.
				$new_img_tag       = '<img' . $before_src . 'src="' . esc_url( $upload['url'] ) . '"' . $after_src . '>';
				$processed_content = str_replace( $full_tag, $new_img_tag, $processed_content );
			}

			$result['content'] = $processed_content;

			return $result;
		}

		/**
		 * Clean up attachments created during content processing.
		 *
		 * Use this to rollback uploaded images if post creation fails.
		 *
		 * @since 2.5.0
		 *
		 * @param array $attachment_ids Array of attachment IDs to delete.
		 *
		 * @return void
		 */
		public static function cleanup_attachments( $attachment_ids ) {
			if ( empty( $attachment_ids ) || ! is_array( $attachment_ids ) ) {
				return;
			}

			foreach ( $attachment_ids as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
	}
}
