<?php
namespace AfaqInnovation\ThawaniPay\Tests;

use AfaqInnovation\ThawaniPay\Support\LineItems;
use PHPUnit\Framework\TestCase;

final class LineItemsTest extends TestCase {

	private function sum( array $products ): int {
		return array_sum( array_map( static fn( $p ) => $p['unit_amount'] * $p['quantity'], $products ) );
	}

	public function test_itemised_lines_match_total(): void {
		$lines = array(
			array( 'name' => 'Hoodie with Logo', 'quantity' => 2, 'total' => 25000 ),
			array( 'name' => 'Shipping: Muscat delivery', 'quantity' => 1, 'total' => 1500 ),
		);

		$products = LineItems::build( $lines, 26500, 'Order #1' );

		$this->assertCount( 2, $products );
		$this->assertSame( array( 'name' => 'Hoodie with Logo', 'quantity' => 2, 'unit_amount' => 12500 ), $products[0] );
		$this->assertSame( 26500, $this->sum( $products ) );
	}

	public function test_fractional_unit_price_is_sent_as_one_line(): void {
		$products = LineItems::build( array( array( 'name' => 'Coffee', 'quantity' => 3, 'total' => 10000 ) ), 10000, 'Order #2' );

		$this->assertSame( 1, $products[0]['quantity'] );
		$this->assertSame( 10000, $products[0]['unit_amount'] );
		$this->assertSame( 'Coffee ×3', $products[0]['name'] );
	}

	public function test_mismatched_total_falls_back_to_single_line(): void {
		$products = LineItems::build( array( array( 'name' => 'A', 'quantity' => 1, 'total' => 999 ) ), 1000, 'Order #3' );

		$this->assertSame( array( array( 'name' => 'Order #3', 'quantity' => 1, 'unit_amount' => 1000 ) ), $products );
	}

	public function test_negative_fee_falls_back_to_single_line(): void {
		$lines = array(
			array( 'name' => 'A', 'quantity' => 1, 'total' => 5000 ),
			array( 'name' => 'Loyalty credit', 'quantity' => 1, 'total' => -1000 ),
		);

		$this->assertCount( 1, LineItems::build( $lines, 4000, 'Order #4' ) );
	}

	public function test_free_items_are_skipped(): void {
		$lines = array(
			array( 'name' => 'Gift', 'quantity' => 1, 'total' => 0 ),
			array( 'name' => 'B', 'quantity' => 1, 'total' => 3000 ),
		);

		$products = LineItems::build( $lines, 3000, 'Order #5' );
		$this->assertCount( 1, $products );
		$this->assertSame( 'B', $products[0]['name'] );
	}

	public function test_single_mode(): void {
		$lines = array( array( 'name' => 'A', 'quantity' => 1, 'total' => 3000 ) );

		$this->assertSame( 'Order #6', LineItems::build( $lines, 3000, 'Order #6', LineItems::MODE_SINGLE )[0]['name'] );
	}

	public function test_more_than_100_products_falls_back(): void {
		$lines = array_fill( 0, 101, array( 'name' => 'X', 'quantity' => 1, 'total' => 10 ) );

		$this->assertCount( 1, LineItems::build( $lines, 1010, 'Order #7' ) );
	}

	public function test_quantity_over_100_is_collapsed(): void {
		$products = LineItems::build( array( array( 'name' => 'Pen', 'quantity' => 150, 'total' => 150000 ) ), 150000, 'Order #8' );

		$this->assertSame( 1, $products[0]['quantity'] );
		$this->assertSame( 150000, $products[0]['unit_amount'] );
	}

	public function test_names_are_limited_to_40_characters(): void {
		$name = LineItems::name( 'An extraordinarily long product name that goes on and on' );
		$this->assertSame( 40, mb_strlen( $name ) );
		$this->assertStringEndsWith( '…', $name );

		$arabic = LineItems::name( str_repeat( 'قهوة عمانية ', 10 ), ' ×2' );
		$this->assertLessThanOrEqual( 40, mb_strlen( $arabic ) );
		$this->assertStringEndsWith( ' ×2', $arabic );
	}

	public function test_names_are_cleaned(): void {
		$this->assertSame( 'Tea & Coffee', LineItems::name( '<b>Tea &amp; Coffee</b>' ) );
		$this->assertSame( 'Item', LineItems::name( '   ' ) );
	}
}
