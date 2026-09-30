<?php
namespace AfaqInnovation\ThawaniPay\Tests;

use AfaqInnovation\ThawaniPay\Webhooks\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {

	const BODY   = '{"data":{"session_id":"checkout_x"},"event_type":"checkout.completed"}';
	const SECRET = 'whsec_test';
	const TS     = '1733807121';

	public function test_matches_thawani_reference_algorithm(): void {
		// Same as the Node.js sample in the Thawani docs: HMAC-SHA256( body + "-" + timestamp, secret ).
		$this->assertSame( hash_hmac( 'sha256', self::BODY . '-' . self::TS, self::SECRET ), Signature::compute( self::BODY, self::TS, self::SECRET ) );
	}

	public function test_verifies_valid_signature_case_insensitively(): void {
		$sig = strtoupper( Signature::compute( self::BODY, self::TS, self::SECRET ) );
		$this->assertTrue( Signature::verify( self::BODY, self::TS, $sig, self::SECRET ) );
	}

	public function test_rejects_tampered_body_timestamp_or_secret(): void {
		$sig = Signature::compute( self::BODY, self::TS, self::SECRET );

		$this->assertFalse( Signature::verify( self::BODY . ' ', self::TS, $sig, self::SECRET ) );
		$this->assertFalse( Signature::verify( self::BODY, '1733807122', $sig, self::SECRET ) );
		$this->assertFalse( Signature::verify( self::BODY, self::TS, $sig, 'other' ) );
		$this->assertFalse( Signature::verify( self::BODY, self::TS, '', self::SECRET ) );
		$this->assertFalse( Signature::verify( self::BODY, self::TS, $sig, '' ) );
	}

	public function test_freshness_window(): void {
		$now = 1733807121;
		$this->assertTrue( Signature::is_fresh( (string) $now, 600, $now + 599 ) );
		$this->assertFalse( Signature::is_fresh( (string) $now, 600, $now + 601 ) );
		$this->assertFalse( Signature::is_fresh( 'abc', 600, $now ) );
	}
}
