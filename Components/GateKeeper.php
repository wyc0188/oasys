<?php
/**
 * This file is part of the DreamFactory Oasys (Open Authentication SYStem)
 *
 * DreamFactory Oasys (Open Authentication SYStem) <http://dreamfactorysoftware.github.io>
 * Copyright 2013 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace DreamFactory\Oasys\Components;

use DreamFactory\Oasys\Enums\ProviderConfigTypes;
use DreamFactory\Oasys\Interfaces\ProviderLike;
use DreamFactory\Oasys\Interfaces\ProviderConfigLike;
use DreamFactory\Oasys\Interfaces\StorageProviderLike;
use DreamFactory\Oasys\OasysException;
use DreamFactory\Oasys\Stores\FileSystem;
use DreamFactory\Oasys\Stores\Session;
use Kisma\Core\Interfaces;
use Kisma\Core\Seed;
use Kisma\Core\Utility\Curl;
use Kisma\Core\Utility\Inflector;
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility;

/**
 * GateKeeper
 */
class GateKeeper extends Seed
{
	//*************************************************************************
	//* Constants
	//*************************************************************************

	/**
	 * @var string
	 */
	const DEFAULT_PROVIDER_NAMESPACE = 'DreamFactory\\Oasys\\Providers';
	/**
	 * @var string
	 */
	const DEFAULT_CLASS_NAMESPACE = 'DreamFactory\\Oasys\\Providers';
	/**
	 * @var string The prefix to provider IDs that want to use the generic
	 */
	const GENERIC_PROVIDER_PATTERN = 'generic:';

	//*************************************************************************
	//* Members
	//*************************************************************************

	/**
	 * @var StorageProviderLike
	 */
	protected $_store = null;
	/**
	 * @var array Oasys configuration options
	 */
	protected $_options = array();
	/**
	 * @var ProviderLike[]
	 */
	protected static $_providerCache = array();
	/**
	 * @var array A namespace => path mapping of provider classes
	 */
	protected static $_providerPaths = array();
	/**
	 * @var array
	 */
	protected static $_classMap = array();

	//*************************************************************************
	//* Methods
	//*************************************************************************

	/**
	 * @param array $settings
	 *
	 * @throws
	 * @throws \InvalidArgumentException
	 * @throws OasysException
	 * @return \DreamFactory\Oasys\Components\GateKeeper
	 */
	public function __construct( $settings = array() )
	{
		//	Set the default Providers path.
		if ( empty( static::$_providerPaths ) )
		{
			static::$_providerPaths = array(static::DEFAULT_PROVIDER_NAMESPACE => dirname( __DIR__ ) . '/Providers');
		}

		if ( is_string( $settings ) && is_file( $settings ) && is_readable( $settings ) )
		{
			$settings = file_get_contents( $settings );
		}

		parent::__construct( $settings );

		//	No store provided, make one...
		if ( empty( $this->_store ) )
		{
			$this->_store = ( 'cli' == PHP_SAPI ? new FileSystem( $this->getId() ) : new Session() );
		}

		//	No redirect URI, make one...
		if ( null === $this->getGlobal( 'redirect_uri' ) )
		{
			$this->setGlobal( 'redirect_uri', Curl::currentUrl() );
		}

		//	Render any stored errors
		if ( null !== ( $_error = $this->_store->get( 'error', null, true ) ) )
		{
			if ( isset( $_error['exception'] ) )
			{
				throw $_error['exception'];
			}

			if ( isset( $_error['code'] ) && isset( $_error['message'] ) )
			{
				throw new OasysException( Option::get( $_error, 'message' ), Option::get( $_error, 'code', 500 ) );
			}
		}

		$this->_mapProviders();
	}

	/**
	 * Read store into config...
	 */
	public function __wakeup()
	{
		parent::__wakeup(); // TODO: Change the autogenerated stub
	}

	/**
	 * Your destructor has been chosen!
	 */
	public function __destruct()
	{
		//	Save off the store
		if ( !empty( $this->_store ) && !empty( static::$_providerCache ) )
		{
			foreach ( static::$_providerCache as $_id => $_provider )
			{
				foreach ( $_provider->getConfig()->toArray() as $_key => $_value )
				{
					$this->_store->set( $_id . '.' . $_key, $_value );
				}
			}

			//	Save!
			$this->_store->sync();
		}

		parent::__destruct();
	}

	/**
	 * Create a provider and return it
	 *
	 * @param string                   $providerId
	 * @param array|ProviderConfigLike $config
	 * @param bool                     $createIfNotFound If false and provider not already created, NULL is returned
	 *
	 * @throws \InvalidArgumentException
	 * @return ProviderLike
	 */
	public function getProvider( $providerId, $config = null, $createIfNotFound = true )
	{
		$_providerId = $_mapKey = $providerId;
		$_type = null;
		$_generic = false;

		if ( false === strpos( $_providerId, static::GENERIC_PROVIDER_PATTERN, 0 ) )
		{
			$_providerId = $this->_cleanProviderId( $providerId );
		}
		else
		{
			$_parts = explode( ':', $_providerId );

			if ( empty( $_parts ) || 3 != sizeof( $_parts ) )
			{
				throw new \InvalidArgumentException( 'Invalid provider ID specified. Use predefined or generic "generic:providerId:type" format.' );
			}

			$_providerId = $this->_cleanProviderId( $_parts[1] );
			$_type = str_ireplace( 'oauth', 'OAuth', ProviderConfigTypes::nameOf( $_parts[2] ) );
			$_mapKey = 'generic' . $_type;
			$_generic = ':' . $_providerId;
		}

		//	Cached?
		if ( null === ( $_provider = Option::get( static::$_providerCache, $_mapKey . ( $_generic ? : null ) ) ) )
		{
			//	Get the class mapping...
			if ( null === ( $_map = Option::get( static::$_classMap, $_mapKey ) ) )
			{
				throw new \InvalidArgumentException( 'The provider "' . $_providerId . '" has no associated mapping. Cannot create.' );
			}

			if ( true !== $createIfNotFound && null === $config )
			{
				return null;
			}

			if ( null !== $config && !( $config instanceof ProviderConfigLike ) && !is_array( $config ) && !is_object( $config ) )
			{
				throw new \InvalidArgumentException( 'The $config specified my be null, an array, or an instance of ProviderConfigLike.' );
			}

			/** @noinspection PhpIncludeInspection */
			require $_map['path'];

			if ( empty( $config ) )
			{
				$config = array();
			}

			//	Fill the config with the store values if any
			if ( null !== $_type && null == Option::get( $config, 'type' ) )
			{
				Option::set( $config, 'type', $_type );
			}

			$_className = $_map['namespace'] . '\\' . $_map['class_name'];
			$_mirror = new \ReflectionClass( $_className );

			//	Load any stored configuration
			$config = $this->_loadConfigFromStore( $_providerId, $config );

			//	Instantiate!
			$_provider = $_mirror->newInstanceArgs(
				array(
					 $this,
					 $_providerId,
					 $config
				)
			);

			//	Keep a copy...
			Option::set(
				static::$_providerCache,
				$_mapKey . ( $_generic ? : null ),
				$_provider
			);
		}

		return $_provider;
	}

	/**
	 * @param string $providerId
	 * @param array  $parameters
	 *
	 * @return mixed
	 */
	public function authenticate( $providerId, $parameters = array() )
	{
		return $this->getProvider( $providerId )->authenticate( $parameters );
	}

	/**
	 * Return true if current user is connected with a given provider
	 */
	public function authorized( $providerId )
	{
		return $this->getProvider( $providerId )->authorized();
	}

	/**
	 * Return a list of authenticated providers
	 */
	public function connectedProviders()
	{
		$_response = array();

		foreach ( $this->_options['providers'] as $_providerId => $_config )
		{
			if ( $this->connected( $_providerId ) )
			{
				$_response[] = $_providerId;
			}
		}

		return $_response;
	}

	/**
	 * Return all available providers and their status
	 */
	public function getProviders()
	{
		$_response = array();

		foreach ( $this->_options['providers'] as $_providerId => $_config )
		{
			$_response[$_providerId] = array(
				'connected' => $this->connected( $_providerId )
			);
		}

		return $_response;
	}

	/**
	 * Deauthorize a single provider
	 */
	public function unlinkProvider( $providerId )
	{
		$this->getProvider( $providerId )->deauthorize();
	}

	/**
	 * Deauthorize all linked providers
	 */
	public function unlinkProviders()
	{
		foreach ( $this->connectedProviders() as $_providerId )
		{
			$this->getProvider( $_providerId )->deauthorize();
		}
	}

	/**
	 * @param string                   $providerId
	 * @param ProviderConfigLike|array $config
	 *
	 * @return array
	 */
	protected function _loadConfigFromStore( $providerId, $config )
	{
		$_check = $providerId . '.';
		$_checkLength = strlen( $_check );
		$_defaults = array();

		$_storedConfig = $this->_store->get();

		foreach ( $_storedConfig as $_key => $_value )
		{
			if ( $_check == substr( $_key, 0, $_checkLength ) )
			{
				$_key = substr( $_key, $_checkLength );
				Option::set( $_defaults, $_key, $_value );
			}
		}

		$config = array_merge(
			$_defaults,
			$config
		);

		unset( $_defaults, $_storedConfig );

		return $config;
	}

	/**
	 * @param string $providerId A provider name/ID or provider class name. Facebook => facebook, GitHub => github
	 *
	 * @return string
	 */
	protected function _cleanProviderId( $providerId )
	{
		return Inflector::neutralize(
			strtolower(
				str_ireplace(
					array(
						 'Provider.php',
						 '.php'
					),
					null,
					$providerId
				)
			)
		);
	}

	/**
	 * Makes a hash of providers and their associated classes
	 */
	protected function _mapProviders()
	{
		$_classMap = array();

		foreach ( static::$_providerPaths as $_namespace => $_path )
		{
			$_classes = glob( $_path . '/*.php' );

			foreach ( $_classes as $_class )
			{
				$_className = str_ireplace( '.php', null, basename( $_class ) );
				$_providerId = Inflector::neutralize( $_className );
				$_classMap[$_providerId] = array(
					'class_name' => $_className,
					'path'       => $_class,
					'namespace'  => $_namespace
				);

				unset( $_className, $_providerId, $_class );
			}
		}

		//	Merge in the found classes
		static::$_classMap = array_merge(
			static::$_classMap,
			$_classMap
		);
	}

	/**
	 * @param \DreamFactory\Oasys\Interfaces\StorageProviderLike $store
	 *
	 * @return GateKeeper
	 */
	public function setStore( $store )
	{
		$this->_store = $store;

		return $this;
	}

	/**
	 * @return \DreamFactory\Oasys\Interfaces\StorageProviderLike
	 */
	public function getStore()
	{
		return $this->_store;
	}

	/**
	 * @param array $options
	 *
	 * @return GateKeeper
	 */
	public function setOptions( $options )
	{
		$this->_options = $options;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getOptions()
	{
		return $this->_options;
	}

	/**
	 * Convenience shortcut to the GateKeeper's goody bag
	 *
	 * @param string $key
	 * @param mixed  $defaultValue
	 * @param bool   $burnAfterReading
	 *
	 * @throws OasysException
	 * @return mixed
	 */
	public function getGlobal( $key, $defaultValue = null, $burnAfterReading = false )
	{
		return Option::get( $this->_options, $key, $defaultValue, $burnAfterReading );
	}

	/**
	 * Convenience shortcut to the GateKeeper's goodie bag
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param bool   $overwrite
	 *
	 * @throws OasysException
	 * @return mixed|void
	 */
	public function setGlobal( $key, $value = null, $overwrite = true )
	{
		return Option::set( $this->_options, $key, $value, $overwrite );
	}

	/**
	 * @param array $providerPaths
	 *
	 * @return GateKeeper
	 */
	public function setProviderPaths( $providerPaths )
	{
		static::$_providerPaths = $providerPaths;
		$this->_mapProviders();

		return $this;
	}

	/**
	 * @param string $path
	 *
	 * @return $this
	 */
	public function addProviderPath( $path )
	{
		static::$_providerPaths[] = $path;
		$this->_mapProviders();

		return $this;
	}

	/**
	 * @return array
	 */
	public function getProviderPaths()
	{
		return static::$_providerPaths;
	}

	/**
	 * @param array $classMap
	 *
	 * @return $this
	 */
	public function setClassMap( $classMap )
	{
		static::$_classMap = $classMap;

		return $this;
	}

	/**
	 * @return array
	 */
	public static function getClassMap()
	{
		return static::$_classMap;
	}
}
