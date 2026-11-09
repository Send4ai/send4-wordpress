<?php
/**
 * What the log keeps of a refused phone number.
 *
 * @package Send4
 */

/**
 * Enough to see what visitors are typing, never enough to call one of them.
 */
class Send4_Phone_Guard_Shape_Test extends Send4_Test_Case {

	/**
	 * Typed value, then the shape the log may keep.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function shapeProvider() {
		return array(
			'national, trunk prefix' => array( '8 612 34567', '86••••••• (9 digits)' ),
			'national, punctuated'   => array( '(0600) 12-345', '06••••••• (9 digits)' ),
			'unassigned code'        => array( '+999 612 34567', '+999•••••••• (11 digits)' ),
			'too short with a plus'  => array( '+370 61', '+3•••• (5 digits)' ),
			'four digits'            => array( '1234', '•••• (4 digits)' ),
			'00 and no real code'    => array( '00999 612345', '009•••••••• (11 digits)' ),
			'an array value'         => array( array( '8 612', '34567' ), '86••••••• (9 digits)' ),
		);
	}

	/**
	 * @dataProvider shapeProvider
	 *
	 * @param mixed  $typed    What the visitor typed.
	 * @param string $expected The shape.
	 * @return void
	 */
	public function test_the_shape_keeps_the_opening_digits_and_the_length( $typed, $expected ) {
		$this->assertSame( $expected, Send4_Phone_Guard::shape( $typed ) );
	}

	public function test_at_least_four_digits_are_always_masked() {
		foreach ( $this->shapeProvider() as $case ) {
			$this->assertGreaterThanOrEqual( 4, substr_count( Send4_Phone_Guard::shape( $case[0] ), '•' ) );
		}
	}

	public function test_the_log_line_says_why_and_shows_the_shape_not_the_number() {
		Send4_Settings::update_form(
			'contact-form-7:1',
			array(
				'enabled'        => true,
				'mapping'        => array( 'phone' => 'your-phone' ),
				'validate_phone' => true,
			)
		);

		( new Send4_Phone_Guard() )->reject( 'contact-form-7:1', '8 612 34567' );

		$entry = Send4_Logger::entries()[0];

		$this->assertSame(
			'Phone check refused 86••••••• (9 digits): no country code. The visitor was asked to fix the number.',
			$entry['message']
		);
		$this->assertSame( '86••••••• (9 digits)', $entry['context']['shape'] );
		$this->assertStringNotContainsString( '612', wp_json_encode( $entry, JSON_UNESCAPED_UNICODE ) );
		$this->assertStringNotContainsString( '34567', wp_json_encode( $entry, JSON_UNESCAPED_UNICODE ) );
	}
}
