<?php

use PHPUnit\Framework\TestCase;

final class TcsEngineTest extends TestCase {

	public function test_same_state_splits_evenly_into_cgst_and_sgst() {
		$split = WGT_TCS_Engine::calculate_tcs_split( 100000, 1, true );

		$this->assertSame( 1000.0, $split['tcs_amount'] );
		$this->assertSame( 500.0, $split['cgst'] );
		$this->assertSame( 500.0, $split['sgst'] );
		$this->assertSame( 0.0, $split['igst'] );
	}

	public function test_different_state_goes_entirely_to_igst() {
		$split = WGT_TCS_Engine::calculate_tcs_split( 100000, 1, false );

		$this->assertSame( 1000.0, $split['tcs_amount'] );
		$this->assertSame( 0.0, $split['cgst'] );
		$this->assertSame( 0.0, $split['sgst'] );
		$this->assertSame( 1000.0, $split['igst'] );
	}

	public function test_cgst_plus_sgst_always_equals_tcs_amount_even_with_odd_rounding() {
		// 333.33 * 1% = 3.3333 -> rounds to 3.33, which doesn't halve evenly (1.665 each).
		$split = WGT_TCS_Engine::calculate_tcs_split( 333.33, 1, true );

		$this->assertSame( 3.33, $split['tcs_amount'] );
		$this->assertEqualsWithDelta( $split['tcs_amount'], $split['cgst'] + $split['sgst'], 0.0001 );
	}

	public function test_zero_net_value_produces_zero_tcs() {
		$split = WGT_TCS_Engine::calculate_tcs_split( 0, 1, true );

		$this->assertSame( 0.0, $split['tcs_amount'] );
		$this->assertSame( 0.0, $split['cgst'] );
		$this->assertSame( 0.0, $split['sgst'] );
		$this->assertSame( 0.0, $split['igst'] );
	}

	public function test_fractional_tcs_rate() {
		$split = WGT_TCS_Engine::calculate_tcs_split( 50, 0.5, true );

		$this->assertSame( 0.25, $split['tcs_amount'] );
		$this->assertEqualsWithDelta( 0.25, $split['cgst'] + $split['sgst'], 0.0001 );
	}
}
