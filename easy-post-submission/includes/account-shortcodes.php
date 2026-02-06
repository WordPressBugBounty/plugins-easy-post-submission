<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Easy_Post_Submission_Account_Forms' ) ) {
    
    /**
     * Class Easy_Post_Submission_Account_Forms
     *
     * Handles login, register, and password reset forms using WordPress core functions
     */
    class Easy_Post_Submission_Account_Forms {
        /**
         * Instance of this class
         *
         * @var Easy_Post_Submission_Account_Forms
         */
        private static $instance;
        /**
         * Form errors
         *
         * @var WP_Error
         */
        private $errors;
        /**
         * Form messages
         *
         * @var array
         */
        private $messages = [];
        /**
         * Shortcode attributes
         *
         * @var array
         */
        private $shortcode_atts = [];
        
        /**
         * Get instance
         *
         * @return Easy_Post_Submission_Account_Forms
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            
            return self::$instance;
        }
        
        /**
         * Constructor
         */
        private function __construct() {
            $this->errors = new WP_Error();
            $this->init_hooks();
        }
        
        /**
         * Initialize hooks
         */
        private function init_hooks() {
            // Register shortcodes
            add_shortcode( 'easy_post_submission_login', [ $this, 'login_shortcode' ] );
            add_shortcode( 'easy_post_submission_register', [ $this, 'register_shortcode' ] );

            // Handle form submissions on wp_loaded (WooCommerce uses wp_loaded at priority 20)
            add_action( 'wp_loaded', [ $this, 'process_forms' ], 20 );

            // Enqueue styles
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
        }

        /**
         * Retrieves the Post Manager settings for the Easy Post Submission plugin.
         *
         * This method fetches the plugin's post manager configuration options stored
         * in the WordPress options table. If no settings exist, it returns an empty array.
         *
         * @return array The post manager settings from the database, or an empty array if not set.
         * @since 2.1.0
         *
         */
        public function post_manager_settings() {
            return Easy_Post_Submission_Client_Helper::get_instance()->get_post_manager_settings();
        }

        /**
         * Enqueue account form styles and scripts
         */
        public function enqueue_styles() {
            global $post;
            
            if ( ! is_a( $post, 'WP_Post' ) ) {
                return;
            }
            
            if (
                    has_shortcode( $post->post_content, 'easy_post_submission_login' ) ||
                    has_shortcode( $post->post_content, 'easy_post_submission_register' )
            ) {
                // Enqueue plugin styles
                wp_enqueue_style(
                        'easy-post-submission-account',
                        EASY_POST_SUBMISSION_URL . 'assets/client/style.min.css',
                        [],
                        EASY_POST_SUBMISSION_VERSION
                );
                
                // Enqueue account forms CSS (built from src/css/account-forms.css)
                wp_enqueue_style(
                        'easy-post-submission-account-forms',
                        EASY_POST_SUBMISSION_URL . 'assets/account-forms/style.min.css',
                        [ 'easy-post-submission-account' ],
                        EASY_POST_SUBMISSION_VERSION
                );
                
                // Enqueue account forms JavaScript (built from src/js/account-forms.js) - vanilla JS, no dependencies
                wp_enqueue_script(
                        'easy-post-submission-account-forms',
                        EASY_POST_SUBMISSION_URL . 'assets/account-forms/bundle.js',
                        [],
                        EASY_POST_SUBMISSION_VERSION,
                        true
                );
                
                // Enqueue Google reCAPTCHA script if enabled for login or register
                $is_recaptcha_enabled = false;
                if ( has_shortcode( $post->post_content, 'easy_post_submission_login' ) ) {
                    $is_recaptcha_enabled = Easy_Post_Submission_Client_Helper::is_recaptcha_enabled_for_login();
                }
                if ( ! $is_recaptcha_enabled && has_shortcode( $post->post_content, 'easy_post_submission_register' ) ) {
                    $is_recaptcha_enabled = Easy_Post_Submission_Client_Helper::is_recaptcha_enabled_for_register();
                }
                
                if ( $is_recaptcha_enabled ) {
                    $recaptcha_settings = Easy_Post_Submission_Client_Helper::get_global_recaptcha_settings();
                    if ( $recaptcha_settings && ! empty( $recaptcha_settings['recaptcha_site_key'] ) ) {
                        wp_enqueue_script(
                                'google-recaptcha',
                                'https://www.google.com/recaptcha/api.js',
                                [],
                                null,
                                true
                        );
                    }
                }
            }
        }

        /**
         * Verify reCAPTCHA response
         *
         * @param string $recaptcha_response The reCAPTCHA response token
         * @param string $context The context where reCAPTCHA is being used (login, register)
         *
         * @return bool True if verification passes or reCAPTCHA is disabled, false otherwise
         */
        private function verify_recaptcha( $recaptcha_response, $context = 'login' ) {
            // Check if reCAPTCHA is enabled for this context
            $is_enabled = false;
            if ( 'login' === $context ) {
                $is_enabled = Easy_Post_Submission_Client_Helper::is_recaptcha_enabled_for_login();
            } elseif ( 'register' === $context ) {
                $is_enabled = Easy_Post_Submission_Client_Helper::is_recaptcha_enabled_for_register();
            }
            
            // If not enabled, skip verification
            if ( ! $is_enabled ) {
                return true;
            }
            
            // Get global reCAPTCHA settings
            $global_recaptcha_settings = Easy_Post_Submission_Client_Helper::get_global_recaptcha_settings();
            
            // If no global settings, show error
            if ( ! $global_recaptcha_settings ) {
                $message = current_user_can( 'manage_options' )
                        ? esc_html__( '<strong>Error:</strong> reCAPTCHA is not configured. Please add your reCAPTCHA keys in settings.', 'easy-post-submission' )
                        : esc_html__( '<strong>Error:</strong> reCAPTCHA verification is not available.', 'easy-post-submission' );
                $this->errors->add( 'recaptcha_not_configured', wp_kses( $message, $this->get_allowed_message_html() ) );
                
                return false;
            }
            
            // Check if recaptcha response is provided
            if ( empty( $recaptcha_response ) ) {
                $this->errors->add( 'recaptcha_missing', esc_html__( '<strong>Error:</strong> Please complete the reCAPTCHA verification.', 'easy-post-submission' ) );
                
                return false;
            }
            
            // Use global secret key
            $recaptcha_secret_key = $global_recaptcha_settings['recaptcha_secret_key'] ?? '';
            
            // Verify with Google reCAPTCHA API
            $response = wp_remote_post(
                    'https://www.google.com/recaptcha/api/siteverify',
                    [
                            'body' => [
                                    'secret'   => $recaptcha_secret_key,
                                    'response' => $recaptcha_response,
                            ],
                    ]
            );
            
            if ( is_wp_error( $response ) ) {
                $this->errors->add( 'recaptcha_error', wp_kses( esc_html__( '<strong>Error:</strong> Unable to verify reCAPTCHA. Please try again.', 'easy-post-submission' ), $this->get_allowed_message_html() ) );
                
                return false;
            }
            
            $response_body = wp_remote_retrieve_body( $response );
            $result        = json_decode( $response_body );
            
            if ( isset( $result->success ) && $result->success ) {
                return true;
            } else {
                $this->errors->add( 'recaptcha_invalid', wp_kses( __( '<strong>Error:</strong> reCAPTCHA verification failed. Please try again.', 'easy-post-submission' ), $this->get_allowed_message_html() ) );
                
                return false;
            }
        }
        
        /**
         * Process form submissions
         */
        public function process_forms() {
            
            // Only process POST requests
            if ( ! isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
                return;
            }
            
            // Only process if plugin-specific nonce is present
            $plugin_nonces = [
                    'easy_post_submission_login_nonce',
                    'easy_post_submission_register_nonce',
                    'easy_post_submission_lostpassword_nonce',
            ];
            
            $has_plugin_nonce = false;
            // This only checks if a nonce field exists, Nonce verification is not required here
            foreach ( $plugin_nonces as $nonce_name ) {
                if ( ! empty( $_POST[ $nonce_name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    $has_plugin_nonce = true;
                    break;
                }
            }
            
            // Not our form, skip processing
            if ( ! $has_plugin_nonce ) {
                return;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Nonce verification is not required here.
            $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : 'login';
            
            // Route to appropriate handler
            switch ( $action ) {
                case 'login':
                case 'easy_post_submission_login':
                    $this->process_login();
                    break;

                case 'register':
                case 'easy_post_submission_register':
                    $this->process_register();
                    break;

                case 'lostpassword':
                    $this->process_lostpassword();
                    break;
            }
        }
        
        /**
         * Process login form submission
         */
        private function process_login() {
            // Check for nonce
            if ( empty( $_POST['easy_post_submission_login_nonce'] ) ) {
                return;
            }
            
            // Verify nonce
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['easy_post_submission_login_nonce'] ) ), 'easy_post_submission_login' ) ) {
                return;
            }
            
            // Honeypot check (anti-bot)
            if ( ! empty( $_POST['website_url'] ) ) {
                wp_safe_redirect( home_url() );
                exit;
            }
            
            // Get credentials
            $credentials = [
                    'user_login'    => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '',
                    'user_password' => isset( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, A password should not be sanitized.
                    'remember'      => ! empty( $_POST['rememberme'] ),
            ];
            
            // Validate
            if ( empty( $credentials['user_login'] ) ) {
                $this->errors->add( 'empty_username', esc_html__( '<strong>Error:</strong> The username field is empty.', 'easy-post-submission' ) );
            }
            
            if ( empty( $credentials['user_password'] ) ) {
                $this->errors->add( 'empty_password', esc_html__( '<strong>Error:</strong> The password field is empty.', 'easy-post-submission' ) );
            }
            
            // Verify reCAPTCHA if enabled for login
            $recaptcha_response = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
            $this->verify_recaptcha( $recaptcha_response, 'login' );
            
            // WordPress core filter - allows plugins to modify login errors (including captcha plugins)
            $this->errors = apply_filters( 'wp_login_errors', $this->errors, $credentials['user_login'] );
            
            if ( $this->errors->has_errors() ) {
                return;
            }
            
            // Authenticate using WordPress core function (handles rate limiting, etc.)
            $user = wp_signon( $credentials, is_ssl() );
            
            if ( is_wp_error( $user ) ) {
                $this->errors = $user;
                
                // WordPress core action - fires when login fails
                do_action( 'wp_login_failed', $credentials['user_login'], $user );
                
                return;
            }
            
            // Success! WordPress core action
            do_action( 'wp_login', $user->user_login, $user );
            
            // Get redirect URL
            $redirect_to = $this->get_redirect_url( 'login' );
            
            // WordPress core filter - allows plugins to modify login redirect
            $redirect_to = apply_filters( 'login_redirect', $redirect_to, isset( $_REQUEST['redirect_to'] ) ? sanitize_url( wp_unslash( $_REQUEST['redirect_to'] ) ) : '', $user );
            
            wp_safe_redirect( $redirect_to );
            exit;
        }
        
        /**
         * Process register form submission
         * Sends password setup link
         */
        private function process_register() {
            // Check for nonce
            if ( empty( $_POST['easy_post_submission_register_nonce'] ) ) {
                return;
            }
            
            // Verify nonce
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['easy_post_submission_register_nonce'] ) ), 'easy_post_submission_register' ) ) {
                return;
            }
            
            // Honeypot check
            if ( ! empty( $_POST['website_url'] ) ) {
                wp_safe_redirect( home_url() );
                exit;
            }
            
            // Check if registration is enabled
            if ( ! get_option( 'users_can_register' ) ) {
                $this->errors->add( 'registerfail', esc_html__( '<strong>Error:</strong> User registration is currently not allowed.', 'easy-post-submission' ) );
                
                return;
            }
            
            $user_email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
            
            // Generate username from email
            $user_login = '';
            if ( ! empty( $user_email ) ) {
                // Extract username from email before @
                $user_login = sanitize_user( current( explode( '@', $user_email ) ), true );
                
                // If username exists, append number
                $append = '';
                while ( username_exists( $user_login . $append ) ) {
                    $append = wp_rand( 1, 9999 );
                }
                $user_login = $user_login . $append;
            }
            
            // Verify reCAPTCHA if enabled for register
            $recaptcha_response = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
            $this->verify_recaptcha( $recaptcha_response, 'register' );
            
            // WordPress core filter - allows plugins to add registration errors (including captcha plugins)
            $this->errors = apply_filters( 'registration_errors', $this->errors, $user_login, $user_email );
            
            if ( $this->errors->has_errors() ) {
                return;
            }
            
            // handles all validation and sends password setup email
            $user_id = register_new_user( $user_login, $user_email );
            
            if ( is_wp_error( $user_id ) ) {
                $this->errors = $user_id;
                
                return;
            }
            
            // WordPress core action - fires after successful registration
            do_action( 'register_new_user', $user_id );
            
            // Redirect to show success message
            // This prevents the form from being shown again after successful registration
            wp_safe_redirect( add_query_arg( 'checkemail', 'registered' ) );
            exit;
        }
        /**
         * Process lost password form submission
         */
        private function process_lostpassword() {
            // Check for nonce
            if ( empty( $_POST['easy_post_submission_lostpassword_nonce'] ) ) {
                return;
            }
            
            // Verify nonce
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['easy_post_submission_lostpassword_nonce'] ) ), 'easy_post_submission_lostpassword' ) ) {
                return;
            }
            
            // Verify reCAPTCHA if enabled for login (using login setting)
            $recaptcha_response = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
            $this->verify_recaptcha( $recaptcha_response, 'login' );
            
            // WordPress core filter - allows plugins to add lostpassword errors (including captcha)
            $this->errors = apply_filters( 'lostpassword_errors', $this->errors, null );
            
            if ( $this->errors->has_errors() ) {
                return;
            }
            
            // handles everything including rate limiting
            $result = retrieve_password();
            
            if ( is_wp_error( $result ) ) {
                $this->errors = $result;
                
                return;
            }
            
            // Success! Redirect to show success message 
            wp_safe_redirect( add_query_arg( 'checkemail', 'confirm' ) );
            exit;
        }
        
        /**
         * Get redirect URL
         *
         * @param string $context Context: 'login' or 'register'
         *
         * @return string
         */
        private function get_redirect_url( $_context = 'login' ) {

            // Check shortcode attribute
            if ( ! empty( $this->shortcode_atts['redirect'] ) ) {
                return esc_url( $this->shortcode_atts['redirect'] );
            }
            
            // Get settings
            $settings = $this->post_manager_settings();
            
            // For login context, redirect to Post Manager Page (where users manage their posts)
            if ( 'login' === $_context && ! empty( $settings['user_profile']['post_manager_page_url'] ) ) {
                return esc_url( $settings['user_profile']['post_manager_page_url'] );
            }
            
            // Check for custom redirect URL in settings
            if ( ! empty( $settings['custom_login_and_registration']['redirect_url'] ) ) {
                return esc_url( $settings['custom_login_and_registration']['redirect_url'] );
            }
            
            // Default to home
            return home_url();
        }
        
        /**
         * Render login form shortcode
         *
         * @param array $atts Shortcode attributes
         *
         * @return string
         */
        public function login_shortcode( $atts = [] ) {
            
            // Parse shortcode attributes
            $this->shortcode_atts = shortcode_atts( [ 'redirect' => '' ], $atts, 'easy_post_submission_login' );
            
            // If user is already logged in
            if ( is_user_logged_in() ) {
                return $this->render_logged_in_message();
            }
            
            // Check if showing success message after password reset email sent 
            if ( isset( $_GET['checkemail'] ) && 'confirm' === $_GET['checkemail'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Nonce verification is not required here.
                return $this->render_lostpassword_success_message();
            }
            
            // Check action
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Nonce verification is not required here.
            $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : 'login';
            
            switch ( $action ) {
                case 'lostpassword':
                    return $this->render_lostpassword_form();

                default:
                    return $this->render_login_form();
            }
        }
        
        /**
         * Render register form shortcode
         *
         * @param array $atts Shortcode attributes
         *
         * @return string
         */
        public function register_shortcode( $atts = [] ) {
            
            // Parse shortcode attributes
            $this->shortcode_atts = shortcode_atts(
                    [
                            'redirect' => '',
                    ],
                    $atts,
                    'easy_post_submission_register'
            );
            
            if ( is_user_logged_in() ) {
                return $this->render_logged_in_message();
            }
            
            // Check if showing success message after registration
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, Nonce verification is not required here.
            if ( isset( $_GET['checkemail'] ) && 'registered' === $_GET['checkemail'] ) {
                return $this->render_registration_success_message();
            }
            
            if ( ! get_option( 'users_can_register' ) ) {
                return '<div class="rbsm-account-wrapper"><div class="rbsm-account-error">' .
                       wp_kses( esc_html__( '<strong>Error:</strong> User registration is currently not allowed.', 'easy-post-submission' ), $this->get_allowed_message_html() ) .
                       '</div></div>';
            }
            
            return $this->render_register_form();
        }
        
        /**
         * Render logged-in message
         *
         * @return string
         */
        private function render_logged_in_message() {

            $current_user = wp_get_current_user();
            $redirect_url = $this->get_redirect_url( 'login' );
            
            ob_start();
            ?>
            <div class="rbsm-account-wrapper">
                <div class="rbsm-account-message">
                    <p>
                        <?php
                        printf(
                        /* translators: %s: Username */
                                esc_html__( 'You are already logged in as %s.', 'easy-post-submission' ),
                                '<strong>' . esc_html( $current_user->display_name ) . '</strong>'
                        );
                        ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( $redirect_url ); ?>" class="rbsm-button">
                            <?php esc_html_e( 'Go to Dashboard', 'easy-post-submission' ); ?>
                        </a>
                        <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="rbsm-button secondary">
                            <?php esc_html_e( 'Logout', 'easy-post-submission' ); ?>
                        </a>
                    </p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        /**
         * Render login form
         *
         * @return string
         */
        private function render_login_form() {

            $redirect_to = $this->get_redirect_url( 'login' );
            $settings    = $this->post_manager_settings();

            // phpcs:ignore WordPress.Security.NonceVerification.Missing, Nonce verification is not required here.
            $user_login = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
            
            // Get settings for register link
            $register_url = ! empty( $settings['custom_login_and_registration']['custom_registration_link'] )
                    ? esc_url( $settings['custom_login_and_registration']['custom_registration_link'] )
                    : '';
            
            ob_start();
            ?>
            <div class="rbsm-account-wrapper">
                <?php $this->display_messages(); ?>
                <div class="rbsm-login-form-container">
                    <form name="loginform" id="loginform" method="post" action="<?php echo esc_url( add_query_arg( 'action', 'login' ) ); ?>">
                        <?php wp_nonce_field( 'easy_post_submission_login', 'easy_post_submission_login_nonce' ); ?>
                        <div class="honeypot" aria-hidden="true">
                            <input type="text" name="website_url" value="" tabindex="-1" autocomplete="off" />
                        </div>
                        <div class="rbsm-form-group">
                            <label for="user_login">
                                <?php esc_html_e( 'Username or Email Address', 'easy-post-submission' ); ?>
                                <span class="required">*</span>
                            </label>
                            <input
                                    type="text"
                                    name="log"
                                    id="user_login"
                                    class="rbsm-input"
                                    value="<?php echo esc_attr( $user_login ); ?>"
                                    size="20"
                                    autocapitalize="off"
                                    autocomplete="username"
                                    required />
                        </div>
                        <div class="rbsm-form-group">
                            <label for="user_pass">
                                <?php esc_html_e( 'Password', 'easy-post-submission' ); ?>
                                <span class="required">*</span>
                            </label>
                            <div class="user-pass-wrap">
                                <input
                                        type="password"
                                        name="pwd"
                                        id="user_pass"
                                        class="rbsm-input"
                                        value=""
                                        size="20"
                                        autocomplete="current-password"
                                        required />
                                <button type="button" class="rbsm-hide-pw hide-if-no-js" data-show-label="<?php esc_attr_e( 'Show password', 'easy-post-submission' ); ?>" data-hide-label="<?php esc_attr_e( 'Hide password', 'easy-post-submission' ); ?>" aria-label="<?php esc_attr_e( 'Show password', 'easy-post-submission' ); ?>">
                                    <?php $this->show_pw_icon(); ?>
                                </button>
                            </div>
                        </div>
                        <?php
                        /**
                         * Fires following the 'Password' field in the login form.
                         * Allows third-party captcha plugins to add their fields
                         *
                         * @since WordPress 2.1.0
                         */
                        do_action( 'login_form' );
                        ?>
                        <div class="rbsm-remember-me">
                            <label>
                                <input name="rememberme" type="checkbox" id="rememberme" value="forever" />
                                <?php esc_html_e( 'Remember Me', 'easy-post-submission' ); ?>
                            </label>
                        </div>
                        <?php
                        // Display reCAPTCHA if enabled for login
                        if ( Easy_Post_Submission_Client_Helper::is_recaptcha_enabled_for_login() ) {
                            $recaptcha_settings = Easy_Post_Submission_Client_Helper::get_global_recaptcha_settings();
                            if ( $recaptcha_settings && ! empty( $recaptcha_settings['recaptcha_site_key'] ) ) :
                                ?>
                                <div class="rbsm-recaptcha-wrapper">
                                    <div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $recaptcha_settings['recaptcha_site_key'] ); ?>"></div>
                                </div>
                            <?php
                            endif;
                        }
                        ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
                        <button type="submit" name="wp-submit" id="wp-submit" class="rbsm-button">
                            <?php esc_html_e( 'Log In', 'easy-post-submission' ); ?>
                        </button>
                    </form>
                    <div class="rbsm-form-links">
                        <a href="<?php echo esc_url( add_query_arg( 'action', 'lostpassword' ) ); ?>" class="rbsm-form-link">
                            <?php esc_html_e( 'Lost your password?', 'easy-post-submission' ); ?>
                        </a>
                        <?php if ( ! empty( $register_url ) ) : ?>
                            <span class="rbsm-form-link-separator"> | </span>
                            <a href="<?php echo esc_url( $register_url ); ?>" class="rbsm-form-link">
                                <?php esc_html_e( 'Register', 'easy-post-submission' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        /**
         * Render register form
         *
         * @return string
         */
        private function render_register_form() {

            // phpcs:ignore WordPress.Security.NonceVerification.Missing, Nonce verification is not required here.
            $user_email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
            $settings   = $this->post_manager_settings();

            $redirect_to = $this->get_redirect_url( 'register' );
            
            // Get settings for login link
            $login_url = ! empty( $settings['custom_login_and_registration']['custom_login_link'] )
                    ? esc_url( $settings['custom_login_and_registration']['custom_login_link'] )
                    : '';
            ob_start();
            ?>
            <div class="rbsm-account-wrapper">
                <?php $this->display_messages(); ?>
                <div class="rbsm-register-form-container">
                    <form name="registerform" id="registerform" method="post" action="<?php echo esc_url( add_query_arg( 'action', 'register' ) ); ?>">
                        <?php wp_nonce_field( 'easy_post_submission_register', 'easy_post_submission_register_nonce' ); ?>
                        <div class="honeypot" aria-hidden="true">
                            <input type="text" name="website_url" value="" tabindex="-1" autocomplete="off" />
                        </div>
                        <div class="rbsm-form-group">
                            <label for="user_email">
                                <?php esc_html_e( 'Email Address', 'easy-post-submission' ); ?>
                                <span class="required">*</span>
                            </label>
                            <input
                                    type="email"
                                    name="user_email"
                                    id="user_email"
                                    class="rbsm-input"
                                    value="<?php echo esc_attr( $user_email ); ?>"
                                    size="25"
                                    autocomplete="email"
                                    required />
                        </div>
                        <?php
                        /**
                         * Fires following the 'Email' field in the user registration form.
                         * Allows third-party captcha plugins to add their fields
                         *
                         * @since WordPress 2.1.0
                         */
                        do_action( 'register_form' );
                        ?>
                        <p class="rbsm-register-notice">
                            <?php esc_html_e( 'A link to set your password will be sent to your email address.', 'easy-post-submission' ); ?>
                        </p>
                        <?php
                        // Display reCAPTCHA if enabled for register
                        if ( Easy_Post_Submission_Client_Helper::is_recaptcha_enabled_for_register() ) {
                            $recaptcha_settings = Easy_Post_Submission_Client_Helper::get_global_recaptcha_settings();
                            if ( $recaptcha_settings && ! empty( $recaptcha_settings['recaptcha_site_key'] ) ) :
                                ?>
                                <div class="rbsm-recaptcha-wrapper">
                                    <div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $recaptcha_settings['recaptcha_site_key'] ); ?>"></div>
                                </div>
                            <?php
                            endif;
                        }
                        ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
                        <button type="submit" name="wp-submit" id="wp-submit" class="rbsm-button">
                            <?php esc_html_e( 'Register', 'easy-post-submission' ); ?>
                        </button>
                    </form>
                    <?php if ( ! empty( $login_url ) ) : ?>
                        <div class="rbsm-form-links">
                            <a href="<?php echo esc_url( $login_url ); ?>" class="rbsm-form-link">
                                <?php esc_html_e( 'Back to Login', 'easy-post-submission' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        /**
         * Render registration success message
         * Follows WordPress core pattern - shows message without form
         *
         * @return string
         */
        private function render_registration_success_message() {
            ob_start();
            ?>
            <div class="rbsm-account-wrapper">
                <div class="rbsm-account-message">
                    <p>
                        <?php echo esc_html__( 'Success! Registration complete.', 'easy-post-submission' ); ?>
                    </p>
                    <p><?php echo esc_html__( 'Please check your email for a link to set your password.', 'easy-post-submission' ); ?></p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        /**
         * Render lost password success message
         * Shows after password reset email is sent
         *
         * @return string
         */
        private function render_lostpassword_success_message() {
            ob_start();
            ?>
            <div class="rbsm-account-wrapper">
                <div class="rbsm-account-message">
                    <p>
                        <strong><?php esc_html_e( 'Check your email!', 'easy-post-submission' ); ?></strong>
                    </p>
                    <p>
                        <?php esc_html_e( 'A password reset link has been sent to your email address.', 'easy-post-submission' ); ?>
                    </p>
                </div>
                <div class="rbsm-form-links">
                    <a href="<?php echo esc_url( remove_query_arg( 'checkemail' ) ); ?>" class="rbsm-form-link">
                        <?php esc_html_e( 'Back to Login', 'easy-post-submission' ); ?>
                    </a>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        /**
         * Render lost password form
         *
         * @return string
         */
        private function render_lostpassword_form() {
            $user_login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, Nonce verification is not required here.

            ob_start();
            ?>
            <div class="rbsm-account-wrapper">
                <?php $this->display_messages(); ?>
                <div class="rbsm-login-form-container">
                    <h2 class="rbsm-form-title"><?php esc_html_e( 'Reset Password', 'easy-post-submission' ); ?></h2>
                    <p><?php esc_html_e( 'Please enter your username or email address. You will receive an email with a link to reset your password.', 'easy-post-submission' ); ?></p>
                    <form name="lostpasswordform" id="lostpasswordform" method="post" action="<?php echo esc_url( add_query_arg( 'action', 'lostpassword' ) ); ?>">
                        <?php wp_nonce_field( 'easy_post_submission_lostpassword', 'easy_post_submission_lostpassword_nonce' ); ?>
                        <div class="rbsm-form-group">
                            <label for="user_login">
                                <?php esc_html_e( 'Username or Email Address', 'easy-post-submission' ); ?>
                                <span class="required">*</span>
                            </label>
                            <input
                                    type="text"
                                    name="user_login"
                                    id="user_login"
                                    class="rbsm-input"
                                    value="<?php echo esc_attr( $user_login ); ?>"
                                    size="20"
                                    autocapitalize="off"
                                    autocomplete="username"
                                    required />
                        </div>
                        <?php
                        /**
                         * Fires inside the lostpassword form tags, before the hidden fields.
                         * Allows third-party captcha plugins to add their fields
                         *
                         * @since WordPress 2.1.0
                         */
                        do_action( 'lostpassword_form' );
                        ?>
                        <?php
                        // Display reCAPTCHA if enabled for login (using login setting)
                        if ( Easy_Post_Submission_Client_Helper::is_recaptcha_enabled_for_login() ) {
                            $recaptcha_settings = Easy_Post_Submission_Client_Helper::get_global_recaptcha_settings();
                            if ( $recaptcha_settings && ! empty( $recaptcha_settings['recaptcha_site_key'] ) ) :
                                ?>
                                <div class="rbsm-recaptcha-wrapper">
                                    <div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $recaptcha_settings['recaptcha_site_key'] ); ?>"></div>
                                </div>
                            <?php
                            endif;
                        }
                        ?>
                        <button type="submit" name="wp-submit" id="wp-submit" class="rbsm-button">
                            <?php esc_html_e( 'Get New Password', 'easy-post-submission' ); ?>
                        </button>
                    </form>
                    <div class="rbsm-form-links">
                        <a href="<?php echo esc_url( remove_query_arg( 'action' ) ); ?>" class="rbsm-form-link">
                            <?php esc_html_e( 'Back to Login', 'easy-post-submission' ); ?>
                        </a>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        /**
         * Display error and success messages
         */
        private function display_messages() {
            // Display errors
            if ( $this->errors->has_errors() ) {
                foreach ( $this->errors->get_error_messages() as $error ) {
                    // Replace wp-login.php URLs with current page URL
                    $error = $this->replace_wp_login_urls( $error );
                    echo '<div class="rbsm-account-error">' . wp_kses( $error, $this->get_allowed_message_html() ) . '</div>';
                }
            }
            
            // Display success messages
            foreach ( $this->messages as $message ) {
                echo '<div class="rbsm-account-message">' . wp_kses( $message, $this->get_allowed_message_html() ) . '</div>';
            }
        }

        /**
         * SVG icon for password visibility toggle
         */
        public function show_pw_icon() { ?>
            <svg class="rbsm-icon-hide" viewBox="0 0 128 128" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                <path d="m64 104c-41.873 0-62.633-36.504-63.496-38.057-.672-1.209-.672-2.678 0-3.887.863-1.552 21.623-38.056 63.496-38.056s62.633 36.504 63.496 38.057c.672 1.209.672 2.678 0 3.887-.863 1.552-21.623 38.056-63.496 38.056zm-55.293-40.006c4.758 7.211 23.439 32.006 55.293 32.006 31.955 0 50.553-24.775 55.293-31.994-4.758-7.211-23.439-32.006-55.293-32.006-31.955 0-50.553 24.775-55.293 31.994zm55.293 24.006c-13.234 0-24-10.766-24-24s10.766-24 24-24 24 10.766 24 24-10.766 24-24 24zm0-40c-8.822 0-16 7.178-16 16s7.178 16 16 16 16-7.178 16-16-7.178-16-16-16z" />
            </svg>
            <svg class="rbsm-icon-show" viewBox="0 0 128 128" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                <path d="m79.891 65.078 7.27-7.27c.529 1.979.839 4.048.839 6.192 0 13.234-10.766 24-24 24-2.144 0-4.213-.31-6.192-.839l7.27-7.27c7.949-.542 14.271-6.864 14.813-14.813zm47.605-3.021c-.492-.885-7.47-13.112-21.11-23.474l-5.821 5.821c9.946 7.313 16.248 15.842 18.729 19.602-4.741 7.219-23.339 31.994-55.294 31.994-4.792 0-9.248-.613-13.441-1.591l-6.573 6.573c6.043 1.853 12.685 3.018 20.014 3.018 41.873 0 62.633-36.504 63.496-38.057.672-1.209.672-2.677 0-3.886zm-16.668-39.229-88 88c-.781.781-1.805 1.172-2.828 1.172s-2.047-.391-2.828-1.172c-1.563-1.563-1.563-4.094 0-5.656l11.196-11.196c-18.1-10.927-27.297-27.012-27.864-28.033-.672-1.209-.672-2.678 0-3.887.863-1.552 21.623-38.056 63.496-38.056 10.827 0 20.205 2.47 28.222 6.122l12.95-12.95c1.563-1.563 4.094-1.563 5.656 0s1.563 4.094 0 5.656zm-76.495 65.183 10.127-10.127c-2.797-3.924-4.46-8.709-4.46-13.884 0-13.234 10.766-24 24-24 5.175 0 9.96 1.663 13.884 4.459l8.189-8.189c-6.47-2.591-13.822-4.27-22.073-4.27-31.955 0-50.553 24.775-55.293 31.994 3.01 4.562 11.662 16.11 25.626 24.017zm15.934-15.935 21.809-21.809c-2.379-1.405-5.118-2.267-8.076-2.267-8.822 0-16 7.178-16 16 0 2.958.862 5.697 2.267 8.076z" />
            </svg>
            <?php
        }

        /**
         * Get allowed HTML tags for wp_kses
         * Allows only safe formatting tags for messages
         *
         * @return array
         */
        private function get_allowed_message_html() {
            return [
                    'strong' => [],
                    'a'      => [
                            'href'  => [],
                            'title' => [],
                            'class' => [],
                    ],
                    'br'     => [],
                    'em'     => [],
            ];
        }

        /**
         * Replace wp-login.php URLs in messages with custom page URLs
         *
         * @param string $message Message text
         *
         * @return string Filtered message
         */
        private function replace_wp_login_urls( $message ) {
            
            // Get current page URL
            $current_url = remove_query_arg( 'action' );
            
            // Replace wp-login.php?action=lostpassword with custom URL
            $message = str_replace(
                    'wp-login.php?action=lostpassword',
                    add_query_arg( 'action', 'lostpassword', $current_url ),
                    $message
            );
            
            // Replace other wp-login.php variations
            $message = preg_replace(
                    '#wp-login\.php\?action=([a-z]+)#i',
                    add_query_arg( 'action', '$1', $current_url ),
                    $message
            );
            
            return $message;
        }
    }
    
    // Initialize
    Easy_Post_Submission_Account_Forms::get_instance();
}
