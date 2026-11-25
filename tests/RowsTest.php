<?php

namespace SiteKitData\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SiteKitData\Rows;

require_once dirname( __DIR__ ) . '/src/Rows.php';

final class RowsTest extends TestCase {

	private function rows() {
		return array(
			array(
				'slug'   => 'search-console',
				'active' => true,
				'order'  => 10,
			),
			array(
				'slug'   => 'adsense',
				'active' => false,
				'order'  => 2,
			),
			array(
				'slug'   => 'Analytics-4',
				'active' => true,
				'order'  => 3,
			),
			array(
				'slug'   => 'tagmanager',
				'active' => false,
			),
		);
	}

	private function slugs( array $rows ) {
		return array_column( $rows, 'slug' );
	}

	public function test_strings_case_insensitive() {
		$this->assertSame(
			array( 'adsense', 'Analytics-4', 'search-console', 'tagmanager' ),
			$this->slugs( Rows::sort( $this->rows(), 'slug' ) )
		);
		$this->assertSame(
			array( 'tagmanager', 'search-console', 'Analytics-4', 'adsense' ),
			$this->slugs( Rows::sort( $this->rows(), 'slug', 'DESC' ) )
		);
	}

	public function test_numbers_numerically_missing_last() {
		$this->assertSame(
			array( 'adsense', 'Analytics-4', 'search-console', 'tagmanager' ),
			$this->slugs( Rows::sort( $this->rows(), 'order' ) )
		);
		$this->assertSame(
			array( 'search-console', 'Analytics-4', 'adsense', 'tagmanager' ),
			$this->slugs( Rows::sort( $this->rows(), 'order', 'desc' ) )
		);
	}

	public function test_booleans_stable() {
		$this->assertSame(
			array( 'adsense', 'tagmanager', 'search-console', 'Analytics-4' ),
			$this->slugs( Rows::sort( $this->rows(), 'active' ) )
		);
		$this->assertSame(
			array( 'search-console', 'Analytics-4', 'adsense', 'tagmanager' ),
			$this->slugs( Rows::sort( $this->rows(), 'active', 'desc' ) )
		);
	}

	public function test_reindexes_keyed_rows() {
		$rows = array(
			'b' => array( 'slug' => 'b' ),
			'a' => array( 'slug' => 'a' ),
		);
		$this->assertSame( array( array( 'slug' => 'a' ), array( 'slug' => 'b' ) ), Rows::sort( $rows, 'slug' ) );
	}

	public function test_empty() {
		$this->assertSame( array(), Rows::sort( array(), 'anything' ) );
	}

	public function test_unknown_field() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid orderby field "nope". Available fields: slug, active, order.' );
		Rows::sort( $this->rows(), 'nope' );
	}

	public function test_invalid_order() {
		$this->expectException( InvalidArgumentException::class );
		Rows::sort( $this->rows(), 'slug', 'sideways' );
	}
}
