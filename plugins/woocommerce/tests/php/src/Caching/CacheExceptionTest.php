<?php

namespace Automattic\WooCommerce\Tests\Caching;

use Automattic\WooCommerce\Caching\CacheException;
use Automattic\WooCommerce\Caching\ObjectCache;

class CacheExceptionTest extends \WC_Unit_Test_Case {

	private $thrower;

	public function setUp(): void {
		$this->thrower = new class extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}
		};
	}

	public function test_to_string_with_id() {
		$exception = new CacheException('Something failed', $this->thrower, 1234);

		$expected = "CacheException: [the_type, id: 1234]: Something failed";
		$this->assertEquals($expected, $exception->__toString());
		$this->assertEquals($expected, (string)$exception);
	}

	public function test_to_string_without_id() {
		$exception = new CacheException('Something failed', $this->thrower);

		$expected = "CacheException: [the_type]: Something failed";
		$this->assertEquals($expected, $exception->__toString());
		$this->assertEquals($expected, (string)$exception);
	}

	public function test_not_passing_errors() {
		$exception = new CacheException('Something failed', $this->thrower);
		$this->assertEquals([], $exception->get_errors());
	}

	public function test_passing_all_parameters() {
		$message = 'Something failed';
		$id = 1234;
		$errors = ['foo', 'bar'];
		$code = 5678;
		$previous = new \Exception();

		$exception = new CacheException($message, $this->thrower, 1234, $errors, $code, $previous);

		$this->assertEquals($message, $exception->getMessage());
		$this->assertEquals($this->thrower, $exception->get_thrower());
		$this->assertEquals($id, $exception->get_cached_id());
		$this->assertEquals($errors, $exception->get_errors());
		$this->assertEquals($previous, $exception->getPrevious());
	}
}
