<?php
/**
 * reCAPTCHA Settings Migration
 *
 * Migrates reCAPTCHA settings from individual forms to global settings
 *
 * @package Easy_Post_Submission
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Migrate reCAPTCHA settings from forms to global settings
 *
 * Runs only once during plugin update to version 2.0.0 or higher
 * Extracts reCAPTCHA keys from any form and saves to global settings
 */
function easy_post_submission_migrate_recaptcha_settings() {

    global $wpdb;

    // Get all forms
    $forms = $wpdb->get_results( "SELECT id, data FROM  {$wpdb->prefix}rb_submission" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    if ( ! $forms ) {
        update_option( 'easy_post_submission_recaptcha_migrated', true );

        return;
    }

    $site_key   = '';
    $secret_key = '';
    $found_keys = false;

    // Find first form with reCAPTCHA keys
    foreach ( $forms as $form ) {
        if ( empty( $form->data ) ) {
            continue;
        }

        $data = json_decode( $form->data, true );

        if ( ! empty( $data['security_fields']['recaptcha']['recaptcha_site_key'] ) && ! empty( $data['security_fields']['recaptcha']['recaptcha_secret_key'] ) ) {
            $site_key   = $data['security_fields']['recaptcha']['recaptcha_site_key'];
            $secret_key = $data['security_fields']['recaptcha']['recaptcha_secret_key'];
            $found_keys = true;
            break;
        }
    }

    // Save to global settings if keys found
    if ( $found_keys ) {
        $post_manager_settings = get_option( 'easy_post_submission_post_manager_settings', [] );

        if ( ! isset( $post_manager_settings['recaptcha'] ) ) {
            $post_manager_settings['recaptcha'] = [
                'site_key'            => $site_key,
                'secret_key'          => $secret_key,
                'enable_for_forms'    => true,
                'enable_for_login'    => false,
                'enable_for_register' => false,
            ];

            update_option( 'easy_post_submission_post_manager_settings', $post_manager_settings );
        }
    }

    update_option( 'easy_post_submission_recaptcha_migrated', true );
}

/**
 * Check and run migrations only when Easy Post Submission plugin is updated.
 */
function easy_post_submission_maybe_migrate_after_update( $upgrader_object, $options ) {
    // Only run for plugin updates
    if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
        return;
    }

    // Check if our plugin was updated
    if ( empty( $options['plugins'] ) || ! in_array( 'easy-post-submission/easy-post-submission.php', $options['plugins'], true ) ) {
        return;
    }

    // Get current and new version
    $current_version = get_option( 'easy_post_submission_version', '0.0.0' );

    $new_version = EASY_POST_SUBMISSION_VERSION;

    // Only migrate if upgrading to a newer version
    if ( version_compare( $current_version, $new_version, '<' ) ) {

        // Example: Run migration for 2.1.0 upgrade
        if ( version_compare( $current_version, '2.1.0', '<' ) && version_compare( $new_version, '2.1.0', '>=' ) ) {
            easy_post_submission_migrate_recaptcha_settings();
        }

        // Update stored version
        update_option( 'easy_post_submission_version', $new_version );
    }
}

add_action( 'upgrader_process_complete', 'easy_post_submission_maybe_migrate_after_update', 10, 2 );
