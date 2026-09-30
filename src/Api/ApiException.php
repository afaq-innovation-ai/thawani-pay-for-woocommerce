<?php
/**
 * Thawani API error.
 *
 * @package AfaqInnovation\ThawaniPay
 */

namespace AfaqInnovation\ThawaniPay\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries both the HTTP status and Thawani's own result code (4000, 4003, 4200 …).
 */
class ApiException extends \Exception {

	const NOT_FOUND          = 4003;
	const ALREADY_EXISTS     = 4004;
	const METHOD_REJECTED    = 4200;
	const INSUFFICIENT_FUNDS = 4201;
	const DEBIT_UNSUPPORTED  = 4204;
	const CREDIT_UNSUPPORTED = 4205;
	const REFUND_NOT_ALLOWED = 4300;
	const ALREADY_REFUNDED   = 4301;

	/** @var int */
	private $http_status;

	/** @var int */
	private $api_code;

	/** @var array */
	private $response;

	/**
	 * Constructor.
	 *
	 * @param string $message     Description returned by Thawani (or transport error).
	 * @param int    $http_status HTTP status, 0 for transport failures.
	 * @param int    $api_code    Thawani result code.
	 * @param array  $response    Decoded response body.
	 */
	public function __construct( string $message, int $http_status = 0, int $api_code = 0, array $response = array() ) {
		parent::__construct( $message, $api_code ? $api_code : $http_status );
		$this->http_status = $http_status;
		$this->api_code    = $api_code;
		$this->response    = $response;
	}

	/** HTTP status (0 = network error). */
	public function http_status(): int {
		return $this->http_status;
	}

	/** Thawani result code. */
	public function api_code(): int {
		return $this->api_code;
	}

	/** Decoded response body. */
	public function response(): array {
		return $this->response;
	}

	/** True when the key was rejected. */
	public function is_auth_error(): bool {
		return 401 === $this->http_status || 403 === $this->http_status;
	}

	/** True when the object does not exist. */
	public function is_not_found(): bool {
		return self::NOT_FOUND === $this->api_code || 404 === $this->http_status;
	}

	/**
	 * Message that is safe to show to shoppers.
	 */
	public function customer_message(): string {
		switch ( $this->api_code ) {
			case self::METHOD_REJECTED:
				return __( 'Your card was declined. Please try another card.', 'thawani-pay-for-woocommerce' );
			case self::INSUFFICIENT_FUNDS:
				return __( 'Your card has insufficient balance. Please try another card.', 'thawani-pay-for-woocommerce' );
			case self::DEBIT_UNSUPPORTED:
				return __( 'Debit cards are not accepted for this payment. Please use a credit card.', 'thawani-pay-for-woocommerce' );
			case self::CREDIT_UNSUPPORTED:
				return __( 'Credit cards are not accepted for this payment. Please use a debit card.', 'thawani-pay-for-woocommerce' );
		}

		return __( 'We could not start your payment with Thawani right now. Please try again in a moment or choose another payment method.', 'thawani-pay-for-woocommerce' );
	}
}
