<?php
/**
 * SKUSN SKU Normalizer class
 *
 * Handles SKU comparison key generation including:
 * - Trim outer whitespace
 * - Case-sensitive/insensitive comparison
 * - Normalization rules (hyphens, underscores, internal spaces)
 * - Empty SKU handling
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Sku_Normalizer {
	/**
	 * Normalize a SKU for comparison purposes.
	 *
	 * @since 1.0.0
	 * @param string $sku Raw SKU value
	 * @param array $options Normalization options
	 * @return string Normalized comparison key
	 */
	public static function normalize( $sku, $options = array() ) {
		$default_options = array(
			'trim_whitespace'      => true,
			'case_insensitive'     => false,
			'trim_hyphens'         => false,
			'trim_underscores'     => false,
			'trim_internal_spaces' => false,
			'remove_punctuation'   => false,
		);
		$options         = array_merge( $default_options, $options );

		// Remove the SKU if empty or whitespace-only
		if ( empty( $sku ) || trim( $sku ) === '' ) {
			return '';
		}

		$normalized = $sku;

		// Trim leading/trailing whitespace
		if ( $options['trim_whitespace'] ) {
			$normalized = trim( $normalized );
		}

		// Case insensitive comparison
		if ( $options['case_insensitive'] ) {
			$normalized = strtolower( $normalized );
		}

		// Trim hyphens
		if ( $options['trim_hyphens'] ) {
			$normalized = trim( $normalized, '-' );
		}

		// Trim underscores
		if ( $options['trim_underscores'] ) {
			$normalized = trim( $normalized, '_' );
		}

		// Remove internal spaces (only if explicitly enabled to avoid false positives)
		if ( $options['trim_internal_spaces'] ) {
			$normalized = str_replace( ' ', '', $normalized );
		}

		// Remove punctuation (only if explicitly enabled)
		if ( $options['remove_punctuation'] ) {
			$normalized = preg_replace( '/[^\w\s]/', '', $normalized );
		}

		return $normalized;
	}

	/**
	 * Create a comparison key from a SKU.
	 *
	 * @since 1.0.0
	 * @param string $sku Raw SKU value
	 * @param array $options Normalization options
	 * @return string Comparison key
	 */
	public static function create_comparison_key( $sku, $options = array() ) {
		$normalized = self::normalize( $sku, $options );

		if ( '' === $normalized ) {
			return 'empty-sku';
		}

		return $normalized;
	}

	/**
	 * Check if SKU is empty or whitespace-only.
	 *
	 * @since 1.0.0
	 * @param string $sku Raw SKU value
	 * @return bool
	 */
	public static function is_empty( $sku ) {
		return empty( $sku ) || trim( $sku ) === '';
	}

	/**
	 * Check if two SKUs match based on comparison options.
	 *
	 * @since 1.0.0
	 * @param string $sku1 First SKU
	 * @param string $sku2 Second SKU
	 * @param array $options Comparison options
	 * @return bool
	 */
	public static function matches( $sku1, $sku2, $options = array() ) {
		$normalized1 = self::normalize( $sku1, $options );
		$normalized2 = self::normalize( $sku2, $options );

		if ( '' === $normalized1 && '' === $normalized2 ) {
			return true; // Both empty
		}

		if ( '' === $normalized1 || '' === $normalized2 ) {
			return false; // One empty, one not
		}

		return $normalized1 === $normalized2;
	}
}
