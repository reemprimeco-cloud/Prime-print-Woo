<?php
/**
 * The design table (§3.2): draft and final design records.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_DB {

	const DB_VERSION      = '2';
	const VERSION_OPTION  = 'binder_db_version';

	/**
	 * Full table name, with the site's prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'binder_designs';
	}

	/**
	 * Activation: create the table.
	 */
	public static function activate() {
		self::create_table();
	}

	/**
	 * Deactivation: deliberately non-destructive — see uninstall.php.
	 */
	public static function deactivate() {
		// Nothing to tear down yet. Scheduled render checks (Step 7) are
		// cleared here when they exist.
	}

	/**
	 * Create or upgrade the table. dbDelta() is idempotent, so this is safe to
	 * call on every activation and on version bumps.
	 *
	 * Same columns and types as §3.2; written in dbDelta's dialect (two spaces
	 * after PRIMARY KEY, KEY instead of INDEX, no inline SQL comments).
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  order_item_id bigint(20) unsigned DEFAULT NULL,
  session_token varchar(64) NOT NULL,
  template varchar(32) NOT NULL,
  mode varchar(16) NOT NULL,
  design_json longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  preview_url varchar(500) DEFAULT NULL,
  pdf_url varchar(500) DEFAULT NULL,
  pdf_cmyk_url varchar(500) DEFAULT NULL,
  validation_warnings longtext DEFAULT NULL,
  render_job_id varchar(64) DEFAULT NULL,
  render_error longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY session_token (session_token),
  KEY order_id (order_id)
) {$charset};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Bring the table up to date when the plugin is updated in place (an
	 * update does not fire the activation hook).
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * Current UTC time in the column format.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Insert a design row.
	 *
	 * @param array $data Column => value; created_at/updated_at are added.
	 * @return int Row id, or 0 on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = self::now();
		$ok  = $wpdb->insert( self::table(), array_merge( $data, array( 'created_at' => $now, 'updated_at' => $now ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update columns of one design row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value; updated_at is refreshed.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		return false !== $wpdb->update( self::table(), array_merge( $data, array( 'updated_at' => self::now() ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One design row, or null.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return $row ? $row : null;
	}

	/**
	 * Drop the table. Only uninstall.php calls this.
	 */
	public static function drop_table() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is prefix + constant.
	}

	/**
	 * Whether the table exists (used by the health check and tests).
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
