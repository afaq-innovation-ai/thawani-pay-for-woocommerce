<?php
/**
 * Thawani E-Commerce API client.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Api;

use AfaqInnovation\ThawaniPay\Support\Logger;
use AfaqInnovation\ThawaniPay\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Complete coverage of the Thawani E-Commerce API v1:
 * Checkout sessions, Customers, Payment methods, Payment intents, Payments and Refunds.
 *
 * Every response is wrapped as `{ success, code, description, data }`; this client
 * unwraps `data` and turns any non-success into an {@see ApiException}.
 */
class Client {

	const UAT_HOST  = 'https://uatcheckout.thawani.om';
	const LIVE_HOST = 'https://checkout.thawani.om';

	/** @var string */
	private $secret_key;

	/** @var string */
	private $publishable_key;

	/** @var string */
	private $mode;

	/**
	 * Constructor.
	 *
	 * @param string $secret_key      Secret key (thawani-api-key header).
	 * @param string $publishable_key Publishable key (hosted checkout URL).
	 * @param string $mode            `test` or `live`.
	 */
	public function __construct( string $secret_key, string $publishable_key, string $mode ) {
		$this->secret_key      = $secret_key;
		$this->publishable_key = $publishable_key;
		$this->mode            = $mode;
	}

	/**
	 * Client for the active (or a specific) environment, using saved settings.
	 *
	 * @param string|null $mode `test`, `live` or null for the active mode.
	 */
	public static function for_mode( ?string $mode = null ): self {
		$mode = $mode ? $mode : Settings::mode();

		return new self( Settings::secret_key( $mode ), Settings::publishable_key( $mode ), $mode );
	}

	/** Environment of this client. */
	public function mode(): string {
		return $this->mode;
	}

	/** Host for the environment. */
	public function host(): string {
		$host = Settings::MODE_TEST === $this->mode ? self::UAT_HOST : self::LIVE_HOST;

		/**
		 * Filter the Thawani host (useful for proxies or mock servers in automated tests).
		 *
		 * @param string $host Host URL without trailing slash.
		 * @param string $mode `test` or `live`.
		 */
		return untrailingslashit( (string) apply_filters( 'thawani_pay_api_host', $host, $this->mode ) );
	}

	/**
	 * Hosted payment page for a session.
	 *
	 * @param string $session_id Checkout session id.
	 */
	public function checkout_url( string $session_id ): string {
		return $this->host() . '/pay/' . rawurlencode( $session_id ) . '?key=' . rawurlencode( $this->publishable_key );
	}

	// Checkout sessions.

	/**
	 * Create a checkout session.
	 *
	 * @param array $payload Session payload.
	 */
	public function create_session( array $payload ): array {
		return $this->request( 'POST', '/checkout/session', $payload );
	}

	/**
	 * Retrieve a session.
	 *
	 * @param string $session_id Session id.
	 */
	public function get_session( string $session_id ): array {
		return $this->request( 'GET', '/checkout/session/' . rawurlencode( $session_id ) );
	}

	/**
	 * Retrieve a session by client_reference_id.
	 *
	 * @param string $reference Client reference.
	 */
	public function get_session_by_reference( string $reference ): array {
		return $this->request( 'GET', '/checkout/reference/' . rawurlencode( $reference ) );
	}

	/**
	 * Retrieve a session by checkout invoice.
	 *
	 * @param string $invoice Invoice number.
	 */
	public function get_session_by_invoice( string $invoice ): array {
		return $this->request( 'GET', '/checkout/invoice/' . rawurlencode( $invoice ) );
	}

	/**
	 * Cancel a session.
	 *
	 * @param string $session_id Session id.
	 */
	public function cancel_session( string $session_id ): array {
		return $this->request( 'POST', '/checkout/' . rawurlencode( $session_id ) . '/cancel', array() );
	}

	/**
	 * List sessions.
	 *
	 * @param int $limit Page size.
	 * @param int $skip  Offset.
	 */
	public function list_sessions( int $limit = 20, int $skip = 0 ): array {
		return $this->request( 'GET', '/checkout/session', null, compact( 'limit', 'skip' ) );
	}

	// Customers.

	/**
	 * Create a customer.
	 *
	 * @param string $client_customer_id Your own unique customer reference.
	 */
	public function create_customer( string $client_customer_id ): array {
		return $this->request( 'POST', '/customers', array( 'client_customer_id' => $client_customer_id ) );
	}

	/**
	 * Retrieve a customer.
	 *
	 * @param string $customer_id Customer id.
	 */
	public function get_customer( string $customer_id ): array {
		return $this->request( 'GET', '/customers/' . rawurlencode( $customer_id ) );
	}

	/**
	 * Delete a customer.
	 *
	 * @param string $customer_id Customer id.
	 */
	public function delete_customer( string $customer_id ): array {
		return $this->request( 'DELETE', '/customers/' . rawurlencode( $customer_id ) );
	}

	/**
	 * List customers.
	 *
	 * @param int $limit Page size.
	 * @param int $skip  Offset.
	 */
	public function list_customers( int $limit = 50, int $skip = 0 ): array {
		return $this->request( 'GET', '/customers', null, compact( 'limit', 'skip' ) );
	}

	// Payment methods (saved cards).

	/**
	 * Saved cards of a customer. Thawani answers 4003 when there are none; that is returned as an empty list.
	 *
	 * @param string $customer_id Customer id.
	 * @throws ApiException On any error other than "not found".
	 */
	public function list_payment_methods( string $customer_id ): array {
		try {
			$data = $this->request( 'GET', '/payment_methods', null, array( 'customer_id' => $customer_id ) );
		} catch ( ApiException $e ) {
			if ( $e->is_not_found() ) {
				return array();
			}
			throw $e;
		}

		return array_values( array_filter( $data, 'is_array' ) );
	}

	/**
	 * Delete a saved card.
	 *
	 * @param string $card_id Payment method id.
	 */
	public function delete_payment_method( string $card_id ): array {
		return $this->request( 'DELETE', '/payment_methods/' . rawurlencode( $card_id ) );
	}

	// Payment intents (charging saved cards).

	/**
	 * Create a payment intent.
	 *
	 * @param array $payload Intent payload.
	 */
	public function create_payment_intent( array $payload ): array {
		return $this->request( 'POST', '/payment_intents', $payload );
	}

	/**
	 * Confirm a payment intent.
	 *
	 * @param string $intent_id Intent id.
	 * @param array  $payload   Optional payment_method_id / amount.
	 */
	public function confirm_payment_intent( string $intent_id, array $payload = array() ): array {
		return $this->request( 'POST', '/payment_intents/' . rawurlencode( $intent_id ) . '/confirm', $payload );
	}

	/**
	 * Cancel a payment intent.
	 *
	 * @param string $intent_id Intent id.
	 */
	public function cancel_payment_intent( string $intent_id ): array {
		return $this->request( 'POST', '/payment_intents/' . rawurlencode( $intent_id ) . '/cancel', array() );
	}

	/**
	 * Retrieve a payment intent.
	 *
	 * @param string $intent_id Intent id.
	 */
	public function get_payment_intent( string $intent_id ): array {
		return $this->request( 'GET', '/payment_intents/' . rawurlencode( $intent_id ) );
	}

	/**
	 * Retrieve a payment intent by client_reference_id.
	 *
	 * @param string $reference Client reference.
	 */
	public function get_payment_intent_by_reference( string $reference ): array {
		return $this->request( 'GET', '/payment_intents/' . rawurlencode( $reference ) . '/reference' );
	}

	/**
	 * List payment intents.
	 *
	 * @param int $limit Page size.
	 * @param int $skip  Offset.
	 */
	public function list_payment_intents( int $limit = 20, int $skip = 0 ): array {
		return $this->request( 'GET', '/payment_intents', null, compact( 'limit', 'skip' ) );
	}

	// Payments.

	/**
	 * List payments for a checkout invoice or a payment intent.
	 *
	 * @param array $filter Either `checkout_invoice` or `payment_intent`.
	 * @param int   $limit  Page size.
	 * @param int   $skip   Offset.
	 * @throws ApiException On any error other than "not found".
	 */
	public function list_payments( array $filter, int $limit = 10, int $skip = 0 ): array {
		try {
			return $this->request( 'GET', '/payments', null, array_merge( compact( 'limit', 'skip' ), $filter ) );
		} catch ( ApiException $e ) {
			if ( $e->is_not_found() ) {
				return array();
			}
			throw $e;
		}
	}

	/**
	 * Retrieve a payment.
	 *
	 * @param string $payment_id Payment id.
	 */
	public function get_payment( string $payment_id ): array {
		return $this->request( 'GET', '/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * The successful payment behind a checkout invoice or payment intent, if any.
	 *
	 * @param array $filter `checkout_invoice` or `payment_intent`.
	 */
	public function find_successful_payment( array $filter ): ?array {
		foreach ( $this->list_payments( $filter ) as $payment ) {
			if ( is_array( $payment ) && 'successful' === strtolower( (string) ( $payment['status'] ?? '' ) ) ) {
				return $payment;
			}
		}

		return null;
	}

	// Refunds.

	/**
	 * Refund a payment (full or partial).
	 *
	 * @param array $payload payment_id, reason, metadata, amount.
	 */
	public function create_refund( array $payload ): array {
		return $this->request( 'POST', '/refunds', $payload );
	}

	/**
	 * Retrieve a refund.
	 *
	 * @param string $refund_id Refund id.
	 */
	public function get_refund( string $refund_id ): array {
		return $this->request( 'GET', '/refunds/' . rawurlencode( $refund_id ) );
	}

	/**
	 * List refunds.
	 *
	 * @param int $limit Page size.
	 * @param int $skip  Offset.
	 */
	public function list_refunds( int $limit = 20, int $skip = 0 ): array {
		return $this->request( 'GET', '/refunds', null, compact( 'limit', 'skip' ) );
	}

	// Transport.

	/**
	 * Perform a request and return the unwrapped `data` member.
	 *
	 * GET requests are retried (with back-off) on network errors, 429 and 5xx.
	 * POST requests are never retried automatically to avoid duplicate charges.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path below /api/v1.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query string.
	 * @return array
	 * @throws ApiException On any failure.
	 */
	public function request( string $method, string $path, ?array $body = null, array $query = array() ): array {
		if ( '' === $this->secret_key ) {
			throw new ApiException( __( 'Thawani secret key is missing.', 'thawani-pay-for-woocommerce' ), 401 );
		}

		$url = $this->host() . '/api/v1' . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'thawani-api-key' => $this->secret_key,
				'Accept'          => 'application/json',
				'User-Agent'      => $this->user_agent(),
			),
		);

		// Thawani answers HTTP 411 to body-less POSTs, so always send a JSON body (even `{}`).
		if ( null !== $body || 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( empty( $body ) ? new \stdClass() : $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$attempts = 'GET' === $method ? 3 : 1;

		for ( $attempt = 1; ; $attempt++ ) {
			$started  = microtime( true );
			$response = wp_remote_request( $url, $args );
			$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $response ) ) {
				Logger::warning(
					"{$method} {$path} transport error",
					array(
						'error'   => $response->get_error_message(),
						'attempt' => $attempt,
					)
				);
				if ( $attempt < $attempts ) {
					usleep( 300000 * $attempt );
					continue;
				}
				throw new ApiException( $response->get_error_message(), 0 );
			}

			$status  = (int) wp_remote_retrieve_response_code( $response );
			$raw     = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $raw, true );
			$decoded = is_array( $decoded ) ? $decoded : array();

			if ( ( 429 === $status || $status >= 500 ) && $attempt < $attempts ) {
				Logger::warning( "{$method} {$path} → HTTP {$status}, retrying", array( 'attempt' => $attempt ) );
				usleep( 500000 * $attempt );
				continue;
			}

			$ok = $status >= 200 && $status < 300 && ! empty( $decoded['success'] );

			Logger::debug(
				"{$method} {$path} → HTTP {$status} ({$elapsed} ms)",
				array(
					'mode'     => $this->mode,
					'request'  => $body,
					'query'    => $query,
					'response' => $ok ? ( $decoded['description'] ?? '' ) : $decoded,
				)
			);

			if ( ! $ok ) {
				$message = isset( $decoded['description'] ) ? (string) $decoded['description'] : sprintf( 'HTTP %d', $status );
				if ( 401 === $status ) {
					$message = __( 'Thawani rejected the API key. Please check the secret key for this environment.', 'thawani-pay-for-woocommerce' );
				}
				Logger::error(
					"{$method} {$path} failed: {$message}",
					array(
						'status' => $status,
						'code'   => $decoded['code'] ?? null,
					)
				);
				throw new ApiException( $message, $status, (int) ( $decoded['code'] ?? 0 ), $decoded );
			}

			$data = $decoded['data'] ?? array();

			return is_array( $data ) ? $data : array( 'value' => $data );
		}
	}

	/**
	 * Identifies the integration in Thawani's logs.
	 */
	private function user_agent(): string {
		global $wp_version;

		return sprintf(
			'ThawaniPayForWooCommerce/%s (Afaq Innovation and AI) WordPress/%s WooCommerce/%s PHP/%s',
			THAWANI_PAY_VERSION,
			$wp_version,
			defined( 'WC_VERSION' ) ? WC_VERSION : '-',
			PHP_VERSION
		);
	}
}
