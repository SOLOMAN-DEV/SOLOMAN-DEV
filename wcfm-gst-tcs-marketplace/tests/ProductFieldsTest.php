<?php

use PHPUnit\Framework\TestCase;

final class ProductFieldsTest extends TestCase {

	public function test_six_digit_hsn_is_valid() {
		$this->assertTrue( WGT_Product_Fields::is_valid_hsn( '123456' ) );
	}

	public function test_shorter_hsn_is_invalid() {
		$this->assertFalse( WGT_Product_Fields::is_valid_hsn( '1234' ) );
	}

	public function test_longer_hsn_is_invalid() {
		$this->assertFalse( WGT_Product_Fields::is_valid_hsn( '12345678' ) );
	}

	public function test_empty_hsn_is_invalid() {
		$this->assertFalse( WGT_Product_Fields::is_valid_hsn( '' ) );
	}

	public function test_non_numeric_hsn_is_invalid() {
		$this->assertFalse( WGT_Product_Fields::is_valid_hsn( 'ABCDEF' ) );
	}

	public function test_six_digits_with_letters_is_invalid() {
		$this->assertFalse( WGT_Product_Fields::is_valid_hsn( '12345A' ) );
	}
}
