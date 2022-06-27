<?php

namespace Automattic\WooCommerce\Tests\Caching;

use Automattic\WooCommerce\Caching\ObjectCache;

class InvalidObjectCacheClass extends ObjectCache
{

	public function get_object_type(): string
	{
		return '';
	}
}
