<?php

/**
 * Tests for BatchRowUpdate and its components
 *
 * @group db
 */
class BatchRowUpdateTest extends MediaWikiTestCase {

	public function testWriterBasicFunctionality() {
		$db = $this->mockDb();
		$writer = new BatchRowWriter( $db, 'echo_event' );

		$updates = array(
			self::mockUpdate( array( 'something' => 'changed' ) ),
			self::mockUpdate( array( 'otherthing' => 'changed' ) ),
			self::mockUpdate( array( 'and' => 'something', 'else' => 'changed' ) ),
		);

		$db->expects( $this->exactly( count( $updates ) ) )
			->method( 'update' );

		$writer->write( $updates );
	}

	protected static function mockUpdate( array $changes ) {
		static $i = 0;
		return array(
			'primaryKey' => array( 'event_id' => $i++ ),
			'changes' => $changes,
		);
	}

	public function testReaderBasicIterate() {
		$db = $this->mockDb();
		$batchSize = 2;
		$reader = new BatchRowIterator( $db, 'some_table', 'id_field', $batchSize );

		$response = $this->genSelectResult( $batchSize, /*numRows*/ 5, function() {
			static $i = 0;
			return array( 'id_field' => ++$i );
		} );
		$db->expects( $this->exactly( count( $response ) ) )
			->method( 'select' )
			->will( $this->consecutivelyReturnFromSelect( $response ) );

		$pos = 0;
		foreach ( $reader as $rows ) {
			$this->assertEquals( $response[$pos], $rows, "Testing row in position $pos" );
			$pos++;
		}
		// -1 is because the final array() marks the end and isnt included
		$this->assertEquals( count( $response ) - 1, $pos );
	}

	public static function provider_readerGetPrimaryKey() {
		$row = array(
			'id_field' => 42,
			'some_col' => 'dvorak',
			'other_col' => 'samurai',
		);
		return array(

			array(
				'Must return single column pk when requested',
				array( 'id_field' => 42 ),
				$row
			),

			array(
				'Must return multiple column pks when requested',
				array( 'id_field' => 42, 'other_col' => 'samurai' ),
				$row
			),

		);
	}

	/**
	 * @dataProvider provider_readerGetPrimaryKey
	 */
	public function testReaderGetPrimaryKey( $message, array $expected, array $row ) {
		$reader = new BatchRowIterator( $this->mockDb(), 'some_table', array_keys( $expected ), 8675309 );
		$this->assertEquals( $expected, $reader->extractPrimaryKeys( (object) $row ), $message );
	}

	public static function provider_readerSetFetchColumns() {
		return array(

			array(
				'Must merge primary keys into select conditions',
				// Expected column select
				array( 'foo', 'bar' ),
				// primary keys
				array( 'foo' ),
				// setFetchColumn
				array( 'bar' )
			),

			array(
				'Must not merge primary keys into the all columns selector',
				// Expected column select
				array( '*' ),
				// primary keys
				array( 'foo' ),
				// setFetchColumn
				array( '*' ),
			),

			array(
				'Must not duplicate primary keys into column selector',
				// Expected column select.
				// TODO: figure out how to only assert the array_values portion and not the keys
				array( 0 => 'foo', 1 => 'bar', 3 => 'baz' ),
				// primary keys
				array( 'foo', 'bar', ),
				// setFetchColumn
				array( 'bar', 'baz' ),
			),
		);
	}

	/**
	 * @dataProvider provider_readerSetFetchColumns
	 */
	public function testReaderSetFetchColumns(
		$message, array $columns, array $primaryKeys, array $fetchColumns
	) {
		$db = $this->mockDb();
		$db->expects( $this->once() )
			->method( 'select' )
			// only testing second parameter of DatabaseBase::select
			->with( 'some_table', $columns )
			->will( $this->returnValue( new ArrayIterator( array() ) ) );

		$reader = new BatchRowIterator( $db, 'some_table', $primaryKeys, 22 );
		$reader->setFetchColumns( $fetchColumns );
		// triggers first database select
		$reader->rewind();
	}

	public static function provider_readerSelectConditions() {
		return array(

			array(
				"With single primary key must generate id > 'value'",
				// Expected second iteration
				array( "( id_field > '3' )" ),
				// Primary key(s)
				'id_field',
			),

			array(
				'With multiple primary keys the first conditions ' .
					'must use >= and the final condition must use >',
				// Expected second iteration
				array( "( id_field = '3' AND foo > '103' ) OR ( id_field > '3' )" ),
				// Primary key(s)
				array( 'id_field', 'foo' ),
			),

		);
	}

	/**
	 * Slightly hackish to use reflection, but asserting different parameters
	 * to consecutive calls of DatabaseBase::select in phpunit is error prone
	 *
	 * @dataProvider provider_readerSelectConditions
	 */
	public function testReaderSelectConditionsMultiplePrimaryKeys(
		$message, $expectedSecondIteration, $primaryKeys, $batchSize = 3
	) {
		$results = $this->genSelectResult( $batchSize, $batchSize * 3, function() {
			static $i = 0, $j = 100, $k = 1000;
			return array( 'id_field' => ++$i, 'foo' => ++$j, 'bar' => ++$k );
		} );
		$db = $this->mockDbConsecutiveSelect( $results );

		$conditions = array( 'bar' => 42, 'baz' => 'hai' );
		$reader = new BatchRowIterator( $db, 'some_table', $primaryKeys, $batchSize );
		$reader->addConditions( $conditions );

		$buildConditions = new ReflectionMethod( $reader, 'buildConditions' );
		$buildConditions->setAccessible( true );

		// On first iteration only the passed conditions must be used
		$this->assertEquals( $conditions, $buildConditions->invoke( $reader ),
			'First iteration must return only the conditions passed in addConditions' );
		$reader->rewind();

		// Second iteration must use the maximum primary key of last set
		$this->assertEquals(
			$conditions + $expectedSecondIteration,
			$buildConditions->invoke( $reader ),
			$message
		);
	}

	protected function mockDbConsecutiveSelect( array $retvals ) {
		$db = $this->mockDb();
		$db->expects( $this->any() )
			->method( 'select' )
			->will( $this->consecutivelyReturnFromSelect( $retvals ) );
		$db->expects( $this->any() )
			->method( 'addQuotes' )
			->will( $this->returnCallback( function( $value ) {
				return "'$value'"; // not real quoting: doesn't matter in test
			} ) );

		return $db;
	}

	protected function consecutivelyReturnFromSelect( array $results ) {
		$retvals = array();
		foreach ( $results as $rows ) {
			// The DatabaseBase::select method returns iterators, so we do too.
			$retvals[] = $this->returnValue( new ArrayIterator( $rows ) );
		}

		return call_user_func_array( array( $this, 'onConsecutiveCalls' ), $retvals );
	}


	protected function genSelectResult( $batchSize, $numRows, $rowGenerator ) {
		$res = array();
		for ( $i = 0; $i < $numRows; $i += $batchSize ) {
			$rows = array();
			for ( $j = 0; $j < $batchSize && $i + $j < $numRows; $j++ ) {
				$rows [] = (object) call_user_func( $rowGenerator );
			}
			$res[] = $rows;
		}
		$res[] = array(); // termination condition requires empty result for last row
		return $res;
	}

	protected function mockDb() {
		// Cant mock from DatabaseType or DatabaseBase, they dont
		// have the full gamut of methods
		// FIXME: the constructor normally sets mAtomicLevels and mSrvCache
		$databaseMysql = $this->getMockBuilder( 'DatabaseMysql' )
			->disableOriginalConstructor()
			->getMock();
		$databaseMysql->expects( $this->any() )
			->method( 'isOpen' )
			->will( $this->returnValue( true ) );
		$databaseMysql->expects( $this->any() )
			->method( 'getApproximateLagStatus' )
			->will( $this->returnValue( array( 'lag' => 0, 'since' => 0 ) ) );
		return $databaseMysql;
	}
}
