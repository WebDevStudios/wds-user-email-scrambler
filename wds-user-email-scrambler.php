<?php
/**
 * Plugin Name: WDS User Email Scrambler
 * Description: Adds a WP-CLI command that scrambles user email addresses. Useful for preventing accidentally emailing real customers/users when testing mass or transactional email.
 * Plugin URI: https://github.org/webdevstudios/wds-user-email-scrambler
 * Version: 0.0.1
 * Author: WebDevStudios
 * Author URI: https://www.webdevstudios.com/
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Text Domain: wds-user-email-scrambler
 *
 * @package wds-user-email-scrambler
 * @since 0.0.1
 */

namespace WebDevStudios\CLI;

use \WP_CLI;
use \WP_User_Query;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * UserEmailScrambler
 *
 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
 * @since 0.0.1
 */
final class UserEmailScrambler {

	/**
	 * Args passed from CLI command.
	 *
	 * @since 0.0.1
	 *
	 * @var array
	 */
	private $args = [];

	/**
	 * Associative args passed from CLI command.
	 *
	 * @since 0.0.1
	 *
	 * @var array
	 */
	private $assoc_args = [];

	/**
	 * Number of emails scrambled.
	 *
	 * @since 0.0.1
	 *
	 * @var int
	 */
	private $num_scrambled = 0;

	/**
	 * Callback for invocation of the command from cli.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI associative args.
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->args       = $args;
		$this->assoc_args = $assoc_args;

		$this->maybe_confirm_lack_of_ignored_domains();
		$this->initialize_batch_process();
	}

	/**
	 * Specifiying domain names to ignore isn't *required*, but is highly recommended. This prompt confirms lack of them is intentional.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @return void
	 */
	private function maybe_confirm_lack_of_ignored_domains() {
		if ( ! empty( $this->assoc_args['ignored_domains'] ) ) {
			return;
		}

		WP_CLI::confirm( esc_html__( 'You have not specified any email domains to ignore. This means EVERY user, including administrators, will have their email addresses scrambled in the database. Are you sure you want to proceed?', 'wds-user-email-scrambler' ) );
	}

	/**
	 * Kick off the batch processing and display that fact to the terminal.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 */
	private function initialize_batch_process() {
		$user_ids        = $this->get_user_ids_to_scramble();
		$user_id_batches = array_chunk( $user_ids, 30, true );

		/* Translators: Placeholders are for color and line-break formatting within WP_CLI.  */
		WP_CLI::log( WP_CLI::colorize( esc_html__( '%BScrambling emails:%n', 'wds-user-email-scrambler' ) ) );

		$this->num_scrambled = 0;

		foreach ( $user_id_batches as $batch ) {
			$this->scramble_batch_of_user_emails( $batch, count( $user_ids ) );
		}

		WP_CLI::log( WP_CLI::colorize( esc_html__( '%BDone!%n', 'wds-user-email-scrambler' ) ) );
	}

	/**
	 * Get user IDs to scramble
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @return int
	 */
	private function get_user_ids_to_scramble() {
		global $wpdb;

		$users_table  = $this->get_users_table_name();
		$where_clause = $this->get_ignored_domains_where_clause();

		return $wpdb->get_results( "SELECT ID FROM {$users_table} {$where_clause}" ); // phpcs:disable WordPress.DB.PreparedSQL -- Okay use of unprepared variables SQL.
	}

	/**
	 * Get an array of ignored domains from the associative argument, which is a string.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @return array
	 */
	private function get_ignored_domains() {
		if ( ! isset( $this->assoc_args['ignored_domains'] ) ) {
			return [];
		}

		return array_map( 'trim', explode( ',', $this->assoc_args['ignored_domains'] ) );
	}

	/**
	 * If there are domains to ignore, construct a WHERE clause out of them that can be used in queries.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @return string
	 */
	private function get_ignored_domains_where_clause() {
		$ignored_domains = $this->get_ignored_domains();

		if ( empty( $ignored_domains ) ) {
			return '';
		}

		$clauses = [];

		foreach ( $ignored_domains as $ignored_domain ) {
			if ( 0 === count( $clauses ) ) {
				$clauses[] = "WHERE user_email NOT LIKE '%{$ignored_domain}%'";
			} else {
				$clauses[] = "AND user_email NOT LIKE '%{$ignored_domain}%'";
			}
		}

		return implode( $clauses, ' ' );
	}

	/**
	 * Process a batch of users and scramble their email addresses.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @param array $users A batch of users passed by array_chunking the entire list of users eligible for scrambling.
	 * @param int   $total Total number of users whose emails are being scrambled.
	 */
	private function scramble_batch_of_user_emails( $users, $total ) {
		global $wpdb;

		$users_table = $this->get_users_table_name();

		foreach ( $users as $user ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$users_table}
					SET user_email = %s
					WHERE ID = %d",
					$this->get_scrambled_email_address(),
					$user->ID
				)
			);

			$this->num_scrambled++;
		}

		WP_CLI\Utils\report_batch_operation_results( 'user email', 'scramble', $total, $this->num_scrambled, 0, 0 );
	}

	/**
	 * Get a gibberish, scrambled email address.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @return string
	 */
	private function get_scrambled_email_address() {
		$email_address = sprintf( '%1$s@example.test', wp_generate_password( 8, false, false ) );
		return sanitize_email( $email_address );
	}

	/**
	 * Simple helper for getting wpdb-prefixed users table name.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @return string
	 */
	private function get_users_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'users';
	}
}

WP_CLI::add_command( 'scramble-user-emails', '\WebDevStudios\CLI\UserEmailScrambler' );
