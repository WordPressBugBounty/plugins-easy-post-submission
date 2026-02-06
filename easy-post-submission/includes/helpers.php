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
            if ( self::$instance === null ) {
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

            return array_map( function ( $term ) {
                return [
                    'term_id' => $term->term_id,
                    'name'    => $term->name,
                    'slug'    => $term->slug,
                ];
            }, $terms );
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

            return array_map( function ( $term ) {
                return [
                    'term_id' => $term->term_id,
                    'name'    => $term->name,
                    'slug'    => $term->slug,
                ];
            }, $terms );
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

            $yes_post_view = function_exists( 'pvc_get_post_views' );
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

                    if ( $yes_post_view ) {
                        $post_view = pvc_get_post_views( $post_id );
                    } else {
                        $post_view = 0;
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

            // SECURITY: Remove secret_key before sending to frontend
            if ( isset( $settings['recaptcha']['secret_key'] ) ) {
                unset( $settings['recaptcha']['secret_key'] );
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
         * Check if reCAPTCHA is enabled for forms
         *
         * @return bool
         */
        public static function is_recaptcha_enabled_for_forms() {
            $settings = self::$post_manager_settings;

            return ! empty( $settings['recaptcha']['enable_for_forms'] );
        }

        /**
         * Check if reCAPTCHA is enabled for login
         *
         * @return bool
         */
        public static function is_recaptcha_enabled_for_login() {
            $settings = self::$post_manager_settings;

            return ! empty( $settings['recaptcha']['enable_for_login'] );
        }

        /**
         * Check if reCAPTCHA is enabled for registration
         *
         * @return bool
         */
        public static function is_recaptcha_enabled_for_register() {
            $settings = self::$post_manager_settings;

            return ! empty( $settings['recaptcha']['enable_for_register'] );
        }
    }
}
