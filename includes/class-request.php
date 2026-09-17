<?php
/** HTTP scalar input boundaries; business validators still enforce allowed values. */
namespace MarchePotier;
defined( 'ABSPATH' ) || exit;

final class Request {
	/** Read-only navigation, display flags and downloads; authorization stays in each handler. */
	public static function query( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query parameters does not authorize a mutation.
		return self::scalar( $_GET, $key );
	}
	public static function has_query( string $key ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check for read-only navigation.
		return isset( $_GET[ $key ] );
	}
	/** Mutation handlers must verify their nonce and capabilities before storing anything. */
	public static function post( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification belongs to the action-specific handler.
		return self::scalar( $_POST, $key );
	}
	/** Unslash exactly once and reject malformed tokens instead of converting them to valid values. */
	private static function scalar( array $source, string $key ): ?string {
		if ( ! isset( $source[ $key ] ) || ! is_string( $source[ $key ] ) ) { return null; }
		$value = wp_unslash( $source[ $key ] );
		if ( 's' === $key ) { return sanitize_text_field( $value ); }
		if ( in_array( $key, array( 'application', 'candidature', 'post', 'mp_edition', 'paged', 'm', 'author' ), true ) ) { return '' === $value || ctype_digit( $value ) ? $value : null; }
		return preg_match( '/^[a-zA-Z0-9_.:\-]*$/D', $value ) ? $value : null;
	}
	/** Missing or invalid IPs share a conservative rate-limit bucket; never trust forwarded headers. */
	public static function remote_address(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact IP validation below; no lossy cleaning.
		$value = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : 'unknown';
	}
	public static function method(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact HTTP method allowlist below.
		$value = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		return in_array( $value, array( 'GET', 'HEAD', 'POST' ), true ) ? $value : 'INVALID';
	}
	public static function content_length(): int {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decimal byte count checked below; invalid input rejects the request as oversized.
		$value = isset( $_SERVER['CONTENT_LENGTH'] ) ? ( is_string( $_SERVER['CONTENT_LENGTH'] ) ? wp_unslash( $_SERVER['CONTENT_LENGTH'] ) : '' ) : '0';
		return ctype_digit( $value ) ? (int) $value : PHP_INT_MAX;
	}
}
