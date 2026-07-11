<?php
/** Database-atomic, ownership-tokened mutex stored in wp_options. */

defined( 'ABSPATH' ) || exit;

class ES_Option_Mutex {

	/** Build the immutable `owner|expiry` value stored by a lock holder. */
	public static function value( $owner, $lease_seconds, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return (string) $owner . '|' . ( $now + max( 1, (int) $lease_seconds ) );
	}

	/** Parse a stored lock without consulting mutable settings. */
	public static function parse( $raw, $now = null ) {
		$raw = (string) $raw;
		$now = null === $now ? time() : (int) $now;
		if ( '' === $raw ) {
			return array( 'exists' => false, 'present' => false, 'owner' => '', 'expiry' => 0, 'stale' => false );
		}
		$parts  = explode( '|', $raw, 2 );
		$expiry = (int) ( $parts[1] ?? 0 );
		return array(
			'exists'  => true,
			'present' => true,
			'owner'   => (string) ( $parts[0] ?? '' ),
			'expiry'  => $expiry,
			'stale'   => $expiry <= 0 || $now > $expiry,
		);
	}

	/**
	 * Acquire using one database statement and the unique option_name index.
	 *
	 * Do not use add_option() here: current WordPress can turn a duplicate insert
	 * into an ON DUPLICATE KEY UPDATE, allowing two contenders to report success.
	 */
	public static function acquire( $key, $owner, $lease_seconds ) {
		global $wpdb;
		$key   = (string) $key;
		$owner = (string) $owner;
		if ( '' === $key || '' === $owner || false !== strpos( $owner, '|' ) ) {
			return false;
		}
		$value = self::value( $owner, $lease_seconds );
		$sql   = $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$key,
			$value
		);
		$won = 1 === (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		self::invalidate_cache( $key );
		return $won;
	}

	/**
	 * Acquire, atomically replacing only an already-expired row.
	 *
	 * Intended for unattended recurring jobs. The conditional duplicate-key update
	 * is one SQL statement, so two stale-lock contenders cannot delete each other's
	 * newly-acquired lease. Ownership is confirmed from the database afterwards.
	 */
	public static function acquire_or_replace_stale( $key, $owner, $lease_seconds ) {
		global $wpdb;
		$key   = (string) $key;
		$owner = (string) $owner;
		if ( '' === $key || '' === $owner || false !== strpos( $owner, '|' ) ) {
			return false;
		}
		$now   = time();
		$value = self::value( $owner, $lease_seconds, $now );
		$sql   = $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
			ON DUPLICATE KEY UPDATE
			option_value = IF(CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d, VALUES(option_value), option_value)",
			$key,
			$value,
			$now
		);
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		self::invalidate_cache( $key );
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return hash_equals( $value, $stored );
	}

	/** Release only the row still owned by this request. */
	public static function release( $key, $owner ) {
		global $wpdb;
		$key   = (string) $key;
		$owner = (string) $owner;
		if ( '' === $key || '' === $owner ) {
			return false;
		}
		$pattern = $wpdb->esc_like( $owner . '|' ) . '%';
		$sql     = $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
			$key,
			$pattern
		);
		$released = 1 === (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		self::invalidate_cache( $key );
		return $released;
	}

	public static function state( $key ) {
		return self::parse( get_option( (string) $key, '' ) );
	}

	private static function invalidate_cache( $key ) {
		wp_cache_delete( (string) $key, 'options' );
		// Direct INSERT must also evict WordPress's aggregate negative cache.
		wp_cache_delete( 'notoptions', 'options' );
	}
}
