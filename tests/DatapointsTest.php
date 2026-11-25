<?php

namespace SiteKitData\Tests;

use PHPUnit\Framework\TestCase;
use SiteKitData\Datapoints;

require_once dirname( __DIR__ ) . '/src/Datapoints.php';

final class DatapointsTest extends TestCase {

	public function test_keeps_only_get_datapoints_sorted() {
		$shareable = new class() {
			public function is_shareable() {
				return true;
			}
		};

		$rows = Datapoints::from_definitions(
			array(
				'GET:sites'           => array( 'service' => 'searchconsole' ),
				'POST:site'           => array( 'service' => 'searchconsole' ),
				'GET:searchanalytics' => array(
					'service'   => 'searchconsole',
					'shareable' => true,
				),
				'GET:object-def'      => $shareable,
				'GET:bad_name'        => array(),
				'searchanalytics'     => array(),
				0                     => array(),
			)
		);

		$this->assertSame(
			array(
				array(
					'datapoint' => 'object-def',
					'shareable' => true,
				),
				array(
					'datapoint' => 'searchanalytics',
					'shareable' => true,
				),
				array(
					'datapoint' => 'sites',
					'shareable' => false,
				),
			),
			$rows
		);
	}

	public function test_empty() {
		$this->assertSame( array(), Datapoints::from_definitions( array() ) );
	}
}
