<?php

namespace Automattic\WooCommerce\Tests\Caching;

use Automattic\WooCommerce\Caching\CacheEngine;
use Automattic\WooCommerce\Utilities\ArrayUtil;

class InMemoryObjectCacheEngine implements CacheEngine
{
	public $cache = [];
	public $caching_succeeds = true;
	public $last_expiration;

	public function get_cached_object(string $key) {
		return ArrayUtil::get_value_or_default($this->cache, $key, null);
	}

	public function cache_object(string $key, $object, int $expiration): bool {
		if(!$this->caching_succeeds) {
			return false;
		}
		$this->cache[$key] = $object;
		$this->last_expiration = $expiration;
		return true;
	}

	public function delete_cached_object(string $key): bool
	{
		if(array_key_exists($key, $this->cache)) {
			unset($this->cache[$key]);
			return true;
		}

		return false;
	}

	public function is_cached($key): bool
	{
		return array_key_exists($key, $this->cache);
	}
}
