<?php

use PHPUnit\Framework\TestCase;

final class StatesTest extends TestCase {

	public function test_valid_gstin_with_correct_checksum() {
		$this->assertTrue( WGT_States::is_valid_gstin( '27AAPFU0939F1ZV' ) );
	}

	public function test_gstin_validation_normalizes_case_and_whitespace() {
		$this->assertTrue( WGT_States::is_valid_gstin( ' 27aapfu0939f1zv ' ) );
	}

	public function test_gstin_with_wrong_checksum_digit_is_rejected() {
		$this->assertFalse( WGT_States::is_valid_gstin( '27AAPFU0939F1ZX' ) );
	}

	public function test_gstin_with_wrong_length_is_rejected() {
		$this->assertFalse( WGT_States::is_valid_gstin( '27AAPFU0939F1Z' ) );
	}

	public function test_empty_gstin_is_rejected() {
		$this->assertFalse( WGT_States::is_valid_gstin( '' ) );
	}

	public function test_gstin_with_invalid_characters_is_rejected() {
		$this->assertFalse( WGT_States::is_valid_gstin( '27AAPFU0939F1Z!' ) );
	}

	public function test_state_from_gstin_derives_correct_state() {
		$this->assertSame( 'MH', WGT_States::state_from_gstin( '27AAPFU0939F1ZV' ) );
		$this->assertSame( 'DL', WGT_States::state_from_gstin( '07AAPFU0939F1Z0' ) );
	}

	public function test_state_from_gstin_handles_union_territory_fallback_codes() {
		$this->assertSame( 'DL', WGT_States::state_from_gstin( '99XXXX' ) );
	}

	public function test_state_from_gstin_returns_empty_for_non_numeric_prefix() {
		$this->assertSame( '', WGT_States::state_from_gstin( 'AB' ) );
	}

	public function test_code_to_abbr_pads_single_digit_codes() {
		$this->assertSame( 'DL', WGT_States::code_to_abbr( '7' ) );
	}

	public function test_code_to_abbr_returns_empty_for_unknown_code() {
		$this->assertSame( '', WGT_States::code_to_abbr( '00' ) );
	}
}
