<?php
/**
 * Plugin Name: WDS User Email Scrambler
 * Description: Adds a WP-CLI command that scrambles user email addresses. Useful for preventing accidentally emailing real customers/users when testing mass or transactional email.
 * Plugin URI: https://github.org/webdevstudios/wds-user-email-scrambler
 * Version: 0.0.2
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
	 * Wpdb-prefixed target table name.
	 *
	 * @since 0.0.2
	 *
	 * @var string
	 */
	private $table = '';

	/**
	 * Target field name.
	 *
	 * @since 0.0.2
	 *
	 * @var string
	 */
	private $field = '';

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
		if ( ! empty( $this->get_ignored_domains() ) ) {
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
		$this->table       = $this->get_target_table();
		$this->field       = $this->get_target_field();
		$key               = $this->get_target_table_key();
		$record_ids        = $this->get_record_ids_to_scramble( $key );
		$record_id_batches = array_chunk( $record_ids, 30, true );

		/* Translators: Placeholders are for color and line-break formatting within WP_CLI.  */
		WP_CLI::log( WP_CLI::colorize( esc_html__( '%BScrambling emails:%n', 'wds-user-email-scrambler' ) ) );

		$this->num_scrambled = 0;

		foreach ( $record_id_batches as $batch ) {
			$this->scramble_batch_of_user_emails( $batch, count( $record_ids ), $key );
		}

		WP_CLI::log( WP_CLI::colorize( esc_html__( '%BDone!%n', 'wds-user-email-scrambler' ) ) );
	}

	/**
	 * Get name of table to update, default to 'users'.
	 *
	 * @author Rebekah Van Epps <rebekah.vanepps@webdevstudios.com>
	 * @since  0.0.2
	 *
	 * @return string Table name, if exists.
	 */
	private function get_target_table() : string {
		if ( ! isset( $this->assoc_args['table'] ) ) {
			return $this->get_table_name();
		}

		return $this->check_table_exists( trim( $this->assoc_args['table'] ) );
	}

	/**
	 * Get primary key of target table.
	 *
	 * @author Rebekah Van Epps <rebekah.vanepps@webdevstudios.com>
	 * @since  0.0.2
	 *
	 * @return string Primary key name.
	 */
	private function get_target_table_key() : string {
		global $wpdb;

		$response = $wpdb->get_row( "SHOW KEYS FROM {$this->table} WHERE Key_name = 'PRIMARY'" ); // phpcs:ignore WordPress.DB.PreparedSQL -- Okay use of unprepared variable for table name in SQL.

		// Output error if primary key not available.
		if ( null === $response || ! isset( $response->Column_name ) ) { // phpcs:ignore WordPress.NamingConventions -- Okay property name.
			WP_CLI::error( esc_html__( 'Something went wrong. Supplied table does not have a primary key. Please check table schema and try again.', 'wds-user-email-scrambler' ) );
		}

		return $response->Column_name; // phpcs:ignore WordPress.NamingConventions -- Okay property name.
	}

	/**
	 * Get name of field/column to update, default to 'user_email'.
	 *
	 * @author Rebekah Van Epps <rebekah.vanepps@webdevstudios.com>
	 * @since  0.0.2
	 *
	 * @return string        Field name, if exists.
	 */
	private function get_target_field() : string {
		if ( ! isset( $this->assoc_args['field'] ) ) {
			return 'user_email';
		}

		return $this->check_field_exists( trim( $this->assoc_args['field'] ) );
	}

	/**
	 * Ensure user-supplied table name exists in the db.
	 *
	 * @author Rebekah Van Epps <rebekah.vanepps@webdevstudios.com>
	 * @since  0.0.2
	 *
	 * @param  string $table User-supplied, wpdb-prefixed table name.
	 * @return string        Confirmed, wpdb-prefixed table name, if exists.
	 */
	private function check_table_exists( string $table ) : string {
		global $wpdb;

		$table    = $this->get_table_name( $table );
		$response = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		// Output error if table does not exist.
		if ( 0 !== strcasecmp( $table, $response ) ) {
			WP_CLI::error( esc_html__( 'Supplied table name does not exist. Please check spelling and try again.', 'wds-user-email-scrambler' ) );
		}

		return $response;
	}

	/**
	 * Ensure user-supplied field name exists in table.
	 *
	 * @author Rebekah Van Epps <rebekah.vanepps@webdevstudios.com>
	 * @since  0.0.2
	 *
	 * @param  string $field User-supplied field name.
	 * @return string        Confirmed field name, if exists.
	 */
	private function check_field_exists( string $field ) : string {
		global $wpdb;

		$response = $wpdb->get_col( "DESC {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- Okay use of unprepared variable for table name in SQL.

		// Output error if field does not exist.
		if ( false === array_search( $field, $response ) ) {
			WP_CLI::error( esc_html__( 'Supplied field name does not exist. Please check spelling and try again.', 'wds-user-email-scrambler' ) );
		}

		return $response[ array_search( $field, $response ) ];
	}

	/**
	 * Get record IDs to scramble.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @param  string $key Primary key for target table.
	 * @return array       Array of record objects containing primary key IDs.
	 */
	private function get_record_ids_to_scramble( string $key ) : array {
		global $wpdb;

		$where_clause = $this->get_full_where_clause();

		return $wpdb->get_results( "SELECT {$key} FROM {$this->table} {$where_clause}" ); // phpcs:disable WordPress.DB.PreparedSQL -- Okay use of unprepared variables SQL.
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
		if ( ! isset( $this->assoc_args['ignored-domains'] ) ) {
			return [];
		}

		return array_map( 'trim', explode( ',', $this->assoc_args['ignored-domains'] ) );
	}

	/**
	 * Combine WHERE clause fragments: domains to ignore, custom WHERE arg.
	 *
	 * @author Rebekah Van Epps <rebekah.vanepps@webdevstudios.com>
	 * @since  0.0.2
	 *
	 * @return string Formatted WHERE clause string.
	 */
	private function get_full_where_clause() : string {
		$where = array_filter(
			[
				$this->get_ignored_domains_where_clause(),
				$custom_where = $this->get_custom_where_clause(),
			]
		);

		if ( empty( $where ) ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $where );
	}

	/**
	 * If there are domains to ignore, construct a WHERE clause out of them that can be used in queries.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @return string
	 */
	private function get_ignored_domains_where_clause() : string {
		$ignored_domains = $this->get_ignored_domains();

		if ( empty( $ignored_domains ) ) {
			return '';
		}

		$clauses = [];

		foreach ( $ignored_domains as $ignored_domain ) {
			if ( 0 === count( $clauses ) ) {
				$clauses[] = "{$this->field} NOT LIKE '%{$ignored_domain}%'";
			} else {
				$clauses[] = "AND {$this->field} NOT LIKE '%{$ignored_domain}%'";
			}
		}

		return implode( $clauses, ' ' );
	}

	/**
	 * Construct custom WHERE clause from user-provided field and value.
	 *
	 * @author Rebekah Van Epps <rebekah.vanepps@webdevstudios.com>
	 * @since  0.0.2
	 *
	 * @return string Formatted WHERE clause.
	 */
	private function get_custom_where_clause() : string {
		$where_field = isset( $this->assoc_args['where-field'] ) ?
			$this->check_field_exists( $this->assoc_args['where-field'] ) :
			'';
		$where_value = isset( $this->assoc_args['where-value'] ) ?
			esc_sql( $this->assoc_args['where-value'] ) :
			'';

		if ( empty( $where_field ) || empty( $where_value ) ) {
			return '';
		}

		return "{$where_field} = '{$where_value}'";
	}

	/**
	 * Process a batch of records and scramble their email addresses.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @param array  $records A batch of records passed by array_chunking the entire list of records eligible for scrambling.
	 * @param int    $total   Total number of records whose emails are being scrambled.
	 * @param string $key     Primary key for target table.
	 */
	private function scramble_batch_of_user_emails( array $records, int $total, string $key ) {
		global $wpdb;

		foreach ( $records as $record ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table}
					SET {$this->field} = %s
					WHERE {$key} = %d",
					$this->get_scrambled_email_address(),
					$record->$key
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
	 * Simple helper for getting wpdb-prefixed table name.
	 *
	 * @author George Gecewicz <george.gecewicz@webdevstudios.com>
	 * @since 0.0.1
	 *
	 * @param  string $table Table name, defaults to 'users'.
	 * @return string        Wpdb-prefixed table name.
	 */
	private function get_table_name( $table = 'users' ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}
}

WP_CLI::add_command( 'scramble-user-emails', '\WebDevStudios\CLI\UserEmailScrambler' );
