<?php
/**
 * Thawani customers and saved cards ⇄ WooCommerce payment tokens.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Tokens;

use AfaqInnovation\ThawaniPay\Api\ApiException;
use AfaqInnovation\ThawaniPay\Api\Client;
use AfaqInnovation\ThawaniPay\Gateway\Gateway;
use AfaqInnovation\ThawaniPay\Support\Logger;
use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps "My account → Payment methods" in sync with the cards stored at Thawani.
 *
 * Cards are only ever stored by Thawani (PCI scope stays with Thawani); WooCommerce
 * keeps a token with the card id, brand, last four digits and expiry for display.
 */
final class TokenManager {

	const USER_META = '_thawani_customer_id_';

	/**
	 * Set while removing local tokens for cards that no longer exist at Thawani.
	 *
	 * @var bool
	 */
	private static $silent = false;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_payment_token_deleted', array( __CLASS__, 'on_token_deleted' ), 10, 2 );
		add_filter( 'woocommerce_get_customer_payment_tokens', array( __CLASS__, 'filter_tokens_by_mode' ), 10, 3 );
		add_action( 'woocommerce_before_account_payment_methods', array( __CLASS__, 'on_payment_methods_page' ) );
	}

	/**
	 * Thawani customer id for a WordPress user, created on first use.
	 *
	 * @param int    $user_id User id.
	 * @param string $mode    Environment.
	 * @param bool   $create  Create when missing.
	 */
	public static function customer_id( int $user_id, string $mode, bool $create = true ): string {
		if ( ! $user_id ) {
			return '';
		}

		$stored = (string) get_user_meta( $user_id, self::USER_META . $mode, true );
		if ( $stored || ! $create ) {
			return $stored;
		}

		$client    = Client::for_mode( $mode );
		$reference = self::client_customer_id( $user_id );

		try {
			$customer = $client->create_customer( $reference );
			$id       = (string) ( $customer['id'] ?? '' );
		} catch ( ApiException $e ) {
			$id = ApiException::ALREADY_EXISTS === $e->api_code() ? self::find_customer( $client, $reference ) : '';
			if ( ! $id ) {
				Logger::error(
					'Could not create Thawani customer.',
					array(
						'user'  => $user_id,
						'error' => $e->getMessage(),
					)
				);
				return '';
			}
		}

		if ( $id ) {
			update_user_meta( $user_id, self::USER_META . $mode, $id );
		}

		return $id;
	}

	/**
	 * Stable, site-scoped customer reference (never the email, so it survives email changes).
	 *
	 * @param int $user_id User id.
	 */
	public static function client_customer_id( int $user_id ): string {
		return 'wp_' . substr( md5( (string) get_option( 'siteurl' ) ), 0, 10 ) . '_' . $user_id;
	}

	/**
	 * Pull the customer's cards from Thawani and mirror them as WooCommerce tokens.
	 *
	 * @param int    $user_id User id.
	 * @param string $mode    Environment.
	 */
	public static function sync( int $user_id, string $mode ): void {
		$customer_id = self::customer_id( $user_id, $mode, false );
		if ( ! $customer_id ) {
			return;
		}

		try {
			$cards = Client::for_mode( $mode )->list_payment_methods( $customer_id );
		} catch ( ApiException $e ) {
			Logger::warning(
				'Could not list saved cards.',
				array(
					'user'  => $user_id,
					'error' => $e->getMessage(),
				)
			);
			return;
		}

		$remote = array();
		foreach ( $cards as $card ) {
			if ( ! empty( $card['id'] ) ) {
				$remote[ (string) $card['id'] ] = $card;
			}
		}

		// Thawani stores the same card again every time it is re-saved; mirror each physical card once.
		$seen = array();

		$existing = array();
		remove_filter( 'woocommerce_get_customer_payment_tokens', array( __CLASS__, 'filter_tokens_by_mode' ), 10 );
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $user_id, Gateway::ID ) as $token ) {
			if ( $mode !== $token->get_meta( 'thawani_mode' ) ) {
				continue;
			}
			$id = $token->get_token();
			if ( ! isset( $remote[ $id ] ) || isset( $seen[ self::fingerprint( $remote[ $id ] ) ] ) ) {
				self::delete_silently( $token );
				continue;
			}
			$seen[ self::fingerprint( $remote[ $id ] ) ] = true;
			$existing[ $id ]                             = true;
		}
		add_filter( 'woocommerce_get_customer_payment_tokens', array( __CLASS__, 'filter_tokens_by_mode' ), 10, 3 );

		foreach ( $remote as $id => $card ) {
			if ( isset( $existing[ $id ] ) || isset( $seen[ self::fingerprint( $card ) ] ) ) {
				continue;
			}
			$seen[ self::fingerprint( $card ) ] = true;

			$masked = (string) ( $card['masked_card'] ?? '' );
			$digits = preg_replace( '/\D/', '', $masked );
			$year   = (int) ( $card['expiry_year'] ?? 0 );

			$token = new \WC_Payment_Token_CC();
			$token->set_token( $id );
			$token->set_gateway_id( Gateway::ID );
			$token->set_user_id( $user_id );
			$token->set_card_type( strtolower( (string) ( $card['brand'] ?? 'card' ) ) );
			$token->set_last4( substr( (string) $digits, -4 ) );
			$token->set_expiry_month( str_pad( (string) (int) ( $card['expiry_month'] ?? 12 ), 2, '0', STR_PAD_LEFT ) );
			$token->set_expiry_year( (string) ( $year < 100 ? 2000 + $year : $year ) );
			$token->add_meta_data( 'thawani_mode', $mode, true );
			$token->add_meta_data( 'masked_card', $masked, true );
			$token->add_meta_data( 'card_type', (string) ( $card['card_type'] ?? '' ), true );
			$token->save();
		}
	}

	/**
	 * Thawani id of the customer's saved card with the given masked number (most recent match).
	 *
	 * @param int    $user_id User id.
	 * @param string $mode    Environment.
	 * @param string $masked  Masked card number, e.g. "4242 42XX XXXX 4242".
	 */
	public static function find_card_id( int $user_id, string $mode, string $masked ): string {
		$customer_id = self::customer_id( $user_id, $mode, false );
		if ( ! $customer_id || '' === $masked ) {
			return '';
		}

		try {
			$cards = Client::for_mode( $mode )->list_payment_methods( $customer_id );
		} catch ( ApiException $e ) {
			return '';
		}

		$normalise = static function ( string $value ): string {
			return strtoupper( (string) preg_replace( '/\s+/', '', $value ) );
		};

		$found = '';
		foreach ( $cards as $card ) {
			if ( $normalise( (string) ( $card['masked_card'] ?? '' ) ) === $normalise( $masked ) ) {
				$found = (string) ( $card['id'] ?? '' );
			}
		}

		return $found;
	}

	/**
	 * Identity of a physical card (masked number + expiry).
	 *
	 * @param array $card Thawani payment method.
	 */
	private static function fingerprint( array $card ): string {
		return strtolower( (string) ( $card['masked_card'] ?? '' ) ) . '|' . (int) ( $card['expiry_month'] ?? 0 ) . '/' . (int) ( $card['expiry_year'] ?? 0 );
	}

	/**
	 * Refresh cards when the customer opens "Payment methods" (at most every 10 minutes).
	 */
	public static function on_payment_methods_page(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! Settings::saved_cards_enabled() ) {
			return;
		}

		$mode = Settings::mode();
		$key  = 'thawani_pay_cards_synced_' . $user_id . '_' . $mode;
		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		self::sync( $user_id, $mode );
	}

	/**
	 * Remove the card at Thawani when the customer deletes it in WooCommerce.
	 *
	 * @param int               $token_id Token id.
	 * @param \WC_Payment_Token $token    Token.
	 */
	public static function on_token_deleted( $token_id, $token ): void {
		if ( ! $token instanceof \WC_Payment_Token || Gateway::ID !== $token->get_gateway_id() || self::$silent ) {
			return;
		}

		$mode = (string) $token->get_meta( 'thawani_mode' );

		try {
			Client::for_mode( $mode ? $mode : Settings::mode() )->delete_payment_method( $token->get_token() );
		} catch ( ApiException $e ) {
			if ( ! $e->is_not_found() ) {
				Logger::error( 'Could not delete card at Thawani.', array( 'error' => $e->getMessage() ) );
			}
		}
	}

	/**
	 * Only show cards of the active environment (sandbox cards cannot pay live orders).
	 *
	 * @param \WC_Payment_Token[] $tokens     Tokens.
	 * @param int                 $user_id    User id.
	 * @param string              $gateway_id Gateway id filter.
	 * @return \WC_Payment_Token[]
	 */
	public static function filter_tokens_by_mode( $tokens, $user_id, $gateway_id ) {
		$mode = Settings::mode();

		foreach ( (array) $tokens as $key => $token ) {
			if ( $token instanceof \WC_Payment_Token && Gateway::ID === $token->get_gateway_id() && $mode !== $token->get_meta( 'thawani_mode' ) ) {
				unset( $tokens[ $key ] );
			}
		}

		return $tokens;
	}

	/**
	 * Delete a local token without calling the API (card already gone at Thawani).
	 *
	 * @param \WC_Payment_Token $token Token.
	 */
	private static function delete_silently( \WC_Payment_Token $token ): void {
		self::$silent = true;
		\WC_Payment_Tokens::delete( $token->get_id() );
		self::$silent = false;
	}

	/**
	 * Find an existing customer by our reference (used when Thawani says "already exists").
	 *
	 * @param Client $client    Client.
	 * @param string $reference Reference.
	 */
	private static function find_customer( Client $client, string $reference ): string {
		for ( $page = 0; $page < 20; $page++ ) {
			try {
				$customers = $client->list_customers( 100, $page * 100 );
			} catch ( ApiException $e ) {
				return '';
			}

			foreach ( $customers as $customer ) {
				if ( is_array( $customer ) && (string) ( $customer['customer_client_id'] ?? '' ) === $reference ) {
					return (string) ( $customer['id'] ?? '' );
				}
			}

			if ( count( $customers ) < 100 ) {
				break;
			}
		}

		return '';
	}
}
