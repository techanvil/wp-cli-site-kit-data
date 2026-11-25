<?php

namespace SiteKitData\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SiteKitData\Validator;

require_once dirname( __DIR__ ) . '/src/Validator.php';

final class ValidatorTest extends TestCase {

	/** @dataProvider valid_modules */
	public function test_valid_module( $module ) {
		$this->assertSame( $module, Validator::module( $module ) );
	}

	public function valid_modules() {
		return array( array( 'analytics-4' ), array( 'search-console' ), array( 'adsense' ), array( 'a' ) );
	}

	/** @dataProvider invalid_modules */
	public function test_invalid_module( $module ) {
		$this->expectException( InvalidArgumentException::class );
		Validator::module( $module );
	}

	public function invalid_modules() {
		return array(
			'empty'            => array( '' ),
			'uppercase'        => array( 'Analytics-4' ),
			'slash'            => array( 'analytics-4/data/report' ),
			'traversal'        => array( '../core' ),
			'underscore'       => array( 'search_console' ),
			'query'            => array( 'adsense?x=1' ),
			'trailing newline' => array( "adsense\n" ),
			'space'            => array( 'adsense ' ),
			'null'             => array( null ),
			'int'              => array( 4 ),
		);
	}

	/** @dataProvider valid_datapoints */
	public function test_valid_datapoint( $datapoint ) {
		$this->assertSame( $datapoint, Validator::datapoint( $datapoint ) );
	}

	public function valid_datapoints() {
		return array( array( 'report' ), array( 'searchanalytics' ), array( 'account-summaries' ) );
	}

	/** @dataProvider invalid_datapoints */
	public function test_invalid_datapoint( $datapoint ) {
		$this->expectException( InvalidArgumentException::class );
		Validator::datapoint( $datapoint );
	}

	public function invalid_datapoints() {
		return array(
			'empty'            => array( '' ),
			'digit'            => array( 'report2' ),
			'uppercase'        => array( 'Report' ),
			'slash'            => array( 'report/x' ),
			'trailing newline' => array( "report\n" ),
			'null'             => array( null ),
		);
	}

	public function test_params_default_is_empty() {
		$this->assertSame( array(), Validator::params( null ) );
	}

	public function test_params_empty_object() {
		$this->assertSame( array(), Validator::params( '{}' ) );
		$this->assertSame( array(), Validator::params( " {}\n" ) );
	}

	public function test_params_nested() {
		$params = Validator::params(
			'{"startDate":"2026-01-01","dimensions":["date"],"metrics":[{"name":"totalUsers"}],"limit":1}'
		);
		$this->assertSame(
			array(
				'startDate'  => '2026-01-01',
				'dimensions' => array( 'date' ),
				'metrics'    => array( array( 'name' => 'totalUsers' ) ),
				'limit'      => 1,
			),
			$params
		);
	}

	/** @dataProvider non_object_params */
	public function test_params_rejects_non_objects( $json, $expected_message ) {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $expected_message );
		Validator::params( $json );
	}

	public function non_object_params() {
		return array(
			'empty array'   => array( '[]', 'got an array' ),
			'list'          => array( '[1,2]', 'got an array' ),
			'string'        => array( '"x"', 'got a string' ),
			'number'        => array( '1', 'got a number' ),
			'bool'          => array( 'true', 'got a boolean' ),
			'null'          => array( 'null', 'got null' ),
			'invalid'       => array( '{startDate:1}', 'not valid JSON: Syntax error' ),
			'trailing junk' => array( '{} x', 'not valid JSON' ),
			'empty string'  => array( '', 'is empty' ),
			'whitespace'    => array( "  \n", 'is empty' ),
		);
	}

	/** @dataProvider reserved_params */
	public function test_params_rejects_route_overrides( $json ) {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'would override' );
		Validator::params( $json );
	}

	public function reserved_params() {
		return array(
			array( '{"slug":"other"}' ),
			array( '{"datapoint":"other"}' ),
			array( '{"limit":1,"slug":"other","datapoint":"x"}' ),
		);
	}

	public function test_params_nested_reserved_keys_are_allowed() {
		$this->assertSame( array( 'x' => array( 'slug' => 'y' ) ), Validator::params( '{"x":{"slug":"y"}}' ) );
	}

	public function test_format() {
		$this->assertSame( 'json', Validator::format( null ) );
		$this->assertSame( 'json', Validator::format( 'json' ) );
		$this->assertSame( 'compact', Validator::format( 'compact' ) );
	}

	public function test_invalid_format() {
		$this->expectException( InvalidArgumentException::class );
		Validator::format( 'table' );
	}
}
