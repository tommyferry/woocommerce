<?php

namespace Automattic\WooCommerce\Internal\DependencyManagement\ServiceProviders;

use Automattic\WooCommerce\Caching\WpCacheEngine;
use Automattic\WooCommerce\Internal\DependencyManagement\AbstractServiceProvider;

class ObjectCacheServiceProvider extends AbstractServiceProvider {

	/**
	 * The classes/interfaces that are serviced by this service provider.
	 *
	 * @var array
	 */
	protected $provides = array(
		WpCacheEngine::class,
	);

	/**
	 * Register the classes.
	 */
	public function register() {
		$this->share( WpCacheEngine::class );
	}
}
