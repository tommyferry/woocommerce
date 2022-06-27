<?php

namespace Automattic\WooCommerce\Tests\Caching;

use Automattic\WooCommerce\Caching\CacheException;
use Automattic\WooCommerce\Caching\ObjectCache;
use Automattic\WooCommerce\Caching\CacheEngine;
use Automattic\WooCommerce\Caching\WpCacheEngine;

class ObjectCacheTest extends \WC_Unit_Test_Case {

	private $sut;

	private $cache_engine;

	public function setUp(): void {
		$cache_engine = new InMemoryObjectCacheEngine();
		$this->cache_engine = $cache_engine;

		$container = wc_get_container();
		$container->replace(WpCacheEngine::class, $cache_engine);
		$this->reset_container_resolutions();

		$this->sut = new class extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}

			private $random_string_index = 0;

			protected function get_random_string(): string {
				$this->random_string_index++;
				return 'random_' . $this->random_string_index;
			}
		};
	}

	public function tearDown(): void
	{
		delete_option('wp_object_cache_key_prefix_the_type');
		remove_all_filters('wc_object_cache_get_engine');
		remove_all_filters('woocommerce_after_serializing_the_type_for_caching');
		remove_all_actions('woocommerce_after_removing_the_type_from_cache');
		remove_all_actions('woocommerce_after_flushing_the_type_cache');

		parent::tearDown();
	}

	public function test_get_object_type() {
		$this->assertEquals('the_type', $this->sut->get_object_type());
	}

	public function test_class_with_invalid_get_object_type() {
		$this->expectException(CacheException::class);
		$class_name = InvalidObjectCacheClass::class;
		$message = "Class $class_name returns an empty value for get_object_type";
		$this->expectExceptionMessage($message);

		new InvalidObjectCacheClass();
	}

	public function test_try_set_null_object() {
		$this->expectException(CacheException::class);
		$this->expectExceptionMessage("Can't cache a null value");

		$this->sut->set('the_id', null);
	}

	public function test_try_set_non_object_or_array_object() {
		$this->expectException(CacheException::class);
		$this->expectExceptionMessage("Can't cache a non-object, non-array value");

		$this->sut->set('the_id', 1234);
	}

	public function test_try_set_non_int_or_string_key() {
		$this->expectException(CacheException::class);
		$this->expectExceptionMessage("Object id must be an int, a string, or null for 'set'");

		$this->sut->set([1,2], ['foo']);
	}

	/**
	 * @testWith [0]
	 *           [-2]
	 *           [99999]
	 *
	 * @param int $expiration
	 * @return void
	 */
	public function test_try_set_invalid_expiration_value(int $expiration) {
		$this->expectException(CacheException::class);
		$this->expectExceptionMessage("Invalid expiration value, must be ObjectCache::DEFAULT_EXPIRATION or a value between 1 and ObjectCache::MAX_EXPIRATION");

		$this->sut->set('the_id', ['foo'], $expiration);
	}

	public function try_set_when_cache_engine_fails() {
		$this->cache_engine->caching_succeeds = false;

		$result = $this->sut->set('the_id', ['foo']);
		$this->assertFalse($result);
	}

	public function test_set_new_object_with_id_caches_with_expected_key() {
		$object = ['foo'];
		$result = $this->sut->set('the_id', $object);

		$this->assertTrue($result);

		$expected_prefix = 'woocommerce_object_cache|the_type|random_1|';
		$this->assertEquals($expected_prefix, get_option('wp_object_cache_key_prefix_the_type'));

		$key = $expected_prefix . 'the_id';
		$expected_cached = ['data' => $object];
		$this->assertEquals($expected_cached, $this->cache_engine->cache[$key]);
	}

	public function test_setting_two_objects_result_in_same_prefix() {
		$object_1 = ['foo'];
		$this->sut->set('the_id_1', $object_1);
		$object_2 = [1,2,3,4];
		$this->sut->set(9999, $object_2);

		$prefix = 'woocommerce_object_cache|the_type|random_1|';

		$key_1 = $prefix . 'the_id_1';
		$expected_cached = ['data' => $object_1];
		$this->assertEquals($expected_cached, $this->cache_engine->cache[$key_1]);
		$key_2 = $prefix . '9999';
		$expected_cached = ['data' => $object_2];
		$this->assertEquals($expected_cached, $this->cache_engine->cache[$key_2]);
	}

	public function test_set_with_default_expiration() {
		$this->sut->set('the_id', ['foo']);
		$this->assertEquals($this->sut->get_default_expiration_value(), $this->cache_engine->last_expiration);
	}

	public function test_set_with_explicit_expiration() {
		$this->sut->set('the_id', ['foo'], 1234);
		$this->assertEquals(1234, $this->cache_engine->last_expiration);
	}

	public function test_set_null_id_without_id_retrieval_implementation() {
		$this->expectException(CacheException::class);
		$this->expectExceptionMessage("Null id supplied and the cache class doesn't implement get_object_id");

		$this->sut->set(null, ['foo']);
	}

	public function test_set_null_id_with_id_retrieval_implementation() {
		$sut = new class extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}

			protected function get_object_id( $object ) {
				return $object['id'] + 1;
			}

			protected function get_random_string(): string {
				return 'random';
			}
		};

		$sut->set(null, ['id' => 1234]);

		$this->assertEquals('woocommerce_object_cache|the_type|random|1235', array_keys($this->cache_engine->cache)[0]);
	}

	public function test_set_with_custom_serialization() {
		$object = ['foo'];

		$sut = new class extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}

			protected function serialize( $object ): array {
				return ['the_data' => $object];
			}
		};

		$sut->set(1234, $object);

		$cached = array_values($this->cache_engine->cache)[0];
		$expected = ['the_data' => $object];
		$this->assertEquals($expected, $cached);
	}

	/**
	 * @testWith [1]
	 *           [2]
	 *
	 * @return void
	 */
	public function test_set_with_custom_serialization_that_returns_errors(int $errors_count) {
		$exception = null;
		$errors = 1===$errors_count ? ['Foo failed'] : ['Foo failed', 'Bar failed'];
		$object = ['foo'];

		$sut = new class($errors) extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}

			private $errors;

			public function __construct($errors) {
				$this->errors = $errors;
			}

			protected function validate( $object ): array {
				return $this->errors;
			}
		};

		try {
			$sut->set(1234, $object);
		} catch(CacheException $thrown) {
			$exception = $thrown;
		}

		$expected_message = 'Object validation/serialization failed';
		if(1 === $errors_count) {
			$expected_message .= ': Foo failed';
		}
		$this->assertEquals($expected_message, $exception->getMessage());
		$this->assertEquals($errors, $exception->get_errors());
	}

	/**
	 * @testWith [null]
	 *           [[1,2]]
	 *
	 * @param $key
	 * @return void
	 */
	public function test_try_get_non_int_or_string_key($key) {
		$this->expectException(CacheException::class);
		$this->expectExceptionMessage("Object id must be an int or a string for 'get'");

		$this->sut->get($key);
	}

	/**
	 * @testWith [0]
	 *           [-2]
	 *           [99999]
	 *
	 * @param int $expiration
	 * @return void
	 */
	public function test_try_get_with_invalid_expiration_value(int $expiration) {
		$this->expectException(CacheException::class);
		$this->expectExceptionMessage("Invalid expiration value, must be ObjectCache::DEFAULT_EXPIRATION or a value between 1 and ObjectCache::MAX_EXPIRATION");

		$this->sut->get('the_id', null, $expiration);
	}

	public function test_try_getting_previously_cached_object() {
		$object = ['foo'];
		$this->sut->set('the_id', $object);

		$result = $this->sut->get('the_id');
		$this->assertEquals($object, $result);
	}

	public function test_try_getting_not_cached_object() {
		$result = $this->sut->get('NOT_CACHED');
		$this->assertNull($result);
	}

	public function test_try_getting_not_cached_object_with_callback() {
		$callback = function($id) {return ['id' => $id];};

		$result = $this->sut->get('the_id', $callback);

		$expected = ['id' => 'the_id'];
		$this->assertEquals($expected, $result);
		$this->assertEquals(['data' => $expected], array_values($this->cache_engine->cache)[0]);
		$this->assertEquals($this->sut->get_default_expiration_value(), $this->cache_engine->last_expiration);
	}

	public function test_try_getting_not_cached_object_with_callback_and_explicit_expiration() {
		$expiration = 1234;

		$callback = function($id) {return ['id' => $id];};

		$result = $this->sut->get('the_id', $callback, $expiration);

		$expected = ['id' => 'the_id'];
		$this->assertEquals($expected, $result);
		$this->assertEquals(['data' => $expected], array_values($this->cache_engine->cache)[0]);
		$this->assertEquals($expiration, $this->cache_engine->last_expiration);
	}

	public function test_try_getting_not_cached_object_get_from_datastore_implemented() {
		$sut = new class extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}

			protected function get_from_datastore( $id ) {
				return ['id' => $id];
			}
		};

		$result = $sut->get('the_id');

		$expected = ['id' => 'the_id'];
		$this->assertEquals($expected, $result);
		$this->assertEquals(['data' => $expected], array_values($this->cache_engine->cache)[0]);
		$this->assertEquals($this->sut->get_default_expiration_value(), $this->cache_engine->last_expiration);
	}

	public function test_try_getting_not_cached_object_get_from_datastore_implemented_and_explicit_expiration() {
		$expiration = 1234;

		$sut = new class extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}

			protected function get_from_datastore( $id ) {
				return ['id' => $id];
			}
		};

		$result = $sut->get('the_id', null, $expiration);

		$expected = ['id' => 'the_id'];
		$this->assertEquals($expected, $result);
		$this->assertEquals(['data' => $expected], array_values($this->cache_engine->cache)[0]);
		$this->assertEquals($expiration, $this->cache_engine->last_expiration);
	}

	public function test_custom_deserialization() {
		$sut = new class extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}

			protected function deserialize( array $serialized ) {
				$object = $serialized[ 'data' ];
				$object[] = 3;
				return $object;
			}
		};

		$sut->set('the_id', [1,2]);

		$result = $sut->get('the_id');
		$expected = [1,2,3];
		$this->assertEquals($expected, $result);
	}

	public function test_remove() {
		$this->sut->set('the_id_1', ['foo']);
		$this->sut->set('the_id_2', ['bar']);

		$result_1 = $this->sut->remove('the_id_1');
		$result_2 = $this->sut->remove('the_id_X');

		$this->assertTrue($result_1);
		$this->assertFalse($result_2);

		$this->assertFalse($this->sut->is_cached('the_id_1'));
		$this->assertTrue($this->sut->is_cached('the_id_2'));
	}

	public function test_flush() {
		$this->sut->set('the_id', ['foo']);

		$this->sut->flush();
		$this->assertFalse(get_option('wp_object_cache_key_prefix_the_type'));

		$this->sut->set('the_id_2', ['bar']);

		$expected_new_prefix = 'woocommerce_object_cache|the_type|random_2|';
		$this->assertEquals($expected_new_prefix, get_option('wp_object_cache_key_prefix_the_type'));
		$this->assertFalse($this->sut->is_cached('the_id'));
		$this->assertTrue($this->sut->is_cached('the_id_2'));
	}

	public function test_custom_cache_engine_via_protected_method() {
		$engine = new InMemoryObjectCacheEngine();

		$sut = new class($engine) extends ObjectCache {
			public function get_object_type(): string {
				return 'the_type';
			}

			private $engine;

			public function __construct($engine) {
				$this->engine = $engine;
				parent::__construct();
			}

			protected function get_cache_engine_instance(): CacheEngine {
				return $this->engine;
			}
		};

		$object = ['foo'];
		$sut->set('the_id', $object);

		$expected_cached = ['data' => $object];
		$this->assertEquals($expected_cached, array_values($engine->cache)[0]);
	}

	public function test_custom_cache_engine_via_hook() {
		$engine = new InMemoryObjectCacheEngine();
		$engine_passed_to_filter = null;
		$cache_passed_to_filter = null;

		add_filter('wc_object_cache_get_engine', function($old_engine, $cache) use($engine, &$engine_passed_to_filter, &$cache_passed_to_filter) {
			$engine_passed_to_filter = $old_engine;
			$cache_passed_to_filter = $cache;
			return $engine;
		}, 2, 10);

		$object = ['foo'];
		$this->sut->set('the_id', $object);

		$expected_cached = ['data' => $object];
		$this->assertEquals($expected_cached, array_values($engine->cache)[0]);

		$this->assertEquals($engine_passed_to_filter,  wc_get_container()->get(WpCacheEngine::class));
		$this->assertEquals($cache_passed_to_filter, $this->sut);
	}

	public function test_modifying_serialized_object_via_filter() {
		$object_passed_to_filter = null;
		$id_passed_to_filter = null;

		add_filter( 'woocommerce_after_serializing_the_type_for_caching', function($data, $object, $id) use (&$object_passed_to_filter, &$id_passed_to_filter) {
			$object_passed_to_filter = $object;
			$id_passed_to_filter = $id;

			$data['foo'] = 'bar';
			return $data;
		}, 10, 3 );

		$object = ['fizz'];
		$this->sut->set('the_id', $object);

		$expected_cached = ['data' => $object, 'foo' => 'bar'];
		$this->assertEquals($expected_cached, array_values($this->cache_engine->cache)[0]);

		$this->assertEquals($object, $object_passed_to_filter);
		$this->assertEquals('the_id', $id_passed_to_filter);
	}

	public function test_modifying_deserialized_object_via_filter() {
		$object_passed_to_filter = null;
		$id_passed_to_filter = null;
		$data_passed_to_filter = null;

		$original_object = ['foo'];
		$replacement_object = ['bar'];

		add_filter( 'woocommerce_after_deserializing_the_type_from_cache', function($object, $data, $id) use(&$object_passed_to_filter, &$id_passed_to_filter, &$data_passed_to_filter, $replacement_object) {
			$object_passed_to_filter = $object;
			$id_passed_to_filter = $id;
			$data_passed_to_filter = $data;

			return $replacement_object;
		}, 10, 3);

		$this->sut->set('the_id', $original_object);
		$retrieved_object = $this->sut->get('the_id');

		$this->assertEquals($replacement_object, $retrieved_object);

		$this->assertEquals($original_object, $object_passed_to_filter);
		$this->assertEquals(['data' => $original_object], $data_passed_to_filter);
		$this->assertEquals('the_id', $id_passed_to_filter);
	}

	/**
	 * @testWith [true]
	 *           [false]
	 *
	 * @param $operation_succeeds
	 * @return void
	 */
	public function test_action_triggered_on_object_removed_from_cache($operation_succeeds) {
		$id_passed_to_action = null;
		$result_passed_to_action = null;

		add_action( 'woocommerce_after_removing_the_type_from_cache', function($id, $result) use (&$id_passed_to_action, &$result_passed_to_action) {
			$id_passed_to_action = $id;
			$result_passed_to_action = $result;
		}, 10, 2 );

		$this->sut->set('the_id', ['foo']);
		$this->sut->remove($operation_succeeds ? 'the_id' : 'INVALID_ID');

		$this->assertEquals($operation_succeeds ? 'the_id' : 'INVALID_ID', $id_passed_to_action);
		$this->assertEquals($operation_succeeds, $result_passed_to_action);
	}

	public function test_action_triggered_on_cache_flushed() {
		$action_triggered = false;

		add_action( "woocommerce_after_flushing_the_type_cache", function() use(&$action_triggered) {
			$action_triggered = true;
		}, 10, 1 );

		$this->sut->flush();

		$this->assertTrue($action_triggered);
	}
}
