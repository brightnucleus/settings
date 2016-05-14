<?php
/**
 * Bright Nucleus Settings Component.
 *
 * @package   BrightNucleus\Settings
 * @author    Alain Schlesser <alain.schlesser@gmail.com>
 * @license   MIT
 * @link      http://www.brightnucleus.com/
 * @copyright 2016 Alain Schlesser, Bright Nucleus
 */

namespace BrightNucleus\Settings;

use BrightNucleus\Config\ConfigInterface;
use BrightNucleus\Config\ConfigTrait;
use BrightNucleus\Dependency\DependencyManager;
use BrightNucleus\Exception\DomainException;
use BrightNucleus\Exception\InvalidArgumentException;
use BrightNucleus\Invoker\FunctionInvokerTrait;

/**
 * Settings screen in the admin dashboard.
 *
 * @since   0.1.0
 *
 * @package BrightNucleus\Settings
 * @author  Alain Schlesser <alain.schlesser@gmail.com>
 */
class Settings {

	use FunctionInvokerTrait;
	use ConfigTrait;

	/**
	 * Hooks to the settings pages that have been registered.
	 *
	 * @since 0.1.0
	 *
	 * @var array
	 */
	protected $page_hooks = array();

	/**
	 * Dependency Manager that manages enqueueing of dependencies.
	 *
	 * @since 0.1.3
	 *
	 * @var DependencyManager
	 */
	protected $dependency_manager;

	/**
	 * Instantiate Settings object.
	 *
	 * @since 0.1.0
	 *
	 * @param ConfigInterface   $config             Config object that contains
	 *                                              Settings configuration.
	 * @param DependencyManager $dependency_manager Dependency manager that
	 *                                              handles enqueueing.
	 */
	public function __construct(
		ConfigInterface $config,
		DependencyManager $dependency_manager = null
	) {
		$this->processConfig( $config );
		$this->dependency_manager = $dependency_manager;
	}

	/**
	 * Register necessary hooks.
	 *
	 * @since 0.1.0
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );
		add_action( 'admin_init', [ $this, 'init_settings' ] );
	}

	/**
	 * Add the pages from the configuration settings to the WordPress admin
	 * backend.
	 *
	 * @since 0.1.0
	 */
	public function add_pages() {
		$pages = [ 'menu_page', 'submenu_page' ];
		foreach ( $pages as $page ) {
			if ( $this->hasConfigKey( "${page}s" ) ) {
				$pages = $this->getConfigKey( "${page}s" );
				array_walk( $pages, [ $this, 'add_page' ], "add_${page}" );
			}
		}
	}

	/**
	 * Initialize the settings page.
	 *
	 * @since 0.1.0
	 */
	public function init_settings() {
		if ( $this->hasConfigKey( 'settings' ) ) {
			$settings = $this->getConfigKey( 'settings' );
			array_walk(
				$settings,
				[ $this, 'add_setting' ]
			);
		}
	}

	/**
	 * Add a single page to the WordPress admin backend.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $data              Arguments for page creation function.
	 * @param string $key               Current page name.
	 * @param string $function          Page creation function to be used. Must
	 *                                  be either
	 *                                  'add_menu_page' or 'add_submenu_page'.
	 * @throws InvalidArgumentException If the page addition function could not
	 *                                  be invoked.
	 * @throws DomainException If the page view file does not exist.
	 *
	 */
	protected function add_page( $data, $key, $function ) {
		// Skip page creation if it already exists. This allows reuse of 1 page
		// for several plugins.
		if ( empty( $GLOBALS['admin_page_hooks'][ $data['menu_slug'] ] ) ) {
			$data['function']   = function () use ( $data ) {
				if ( array_key_exists( 'view', $data ) ) {
					if ( ! file_exists( $data['view'] ) ) {
						throw new DomainException( sprintf(
							_( 'Invalid settings page view: %1$s' ),
							$data['view']
						) );
					}
					include( $data['view'] );
				}
				if ( array_key_exists( 'dependencies', $data ) ) {
					array_walk(
						$data['dependencies'],
						[ $this, 'enqueue_dependency' ]
					);
				}
			};
			$page_hook          = $this->invokeFunction( $function, $data );
			$this->page_hooks[] = $page_hook;
		}
	}

	/**
	 * Add option groups.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $setting_data Arguments for the register_setting WP
	 *                             function.
	 * @param string $setting_name Name of the option group.
	 */
	protected function add_setting( $setting_data, $setting_name ) {
		register_setting( $setting_data['option_group'], $setting_name,
			$setting_data['sanitize_callback'] );

		// Prepare array to pass to array_walk as third parameter.
		$args['setting_name'] = $setting_name;
		$args['page']         = $setting_data['option_group'];
		array_walk( $setting_data['sections'], [
			$this,
			'add_section',
		], $args );
	}

	/**
	 * Add options section.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $section_data Arguments for the add_settings_section WP
	 *                             function.
	 * @param string $section_name Name of the option section.
	 * @param string $args         Additional arguments to pass on.
	 * @throws DomainException If the section view file does not exist.
	 */
	protected function add_section( $section_data, $section_name, $args ) {
		add_settings_section(
			$section_name,
			$section_data['title'],
			function () use ( $section_data ) {
				if ( array_key_exists( 'view', $section_data ) ) {
					if ( ! file_exists( $section_data['view'] ) ) {
						throw new DomainException( sprintf(
							_( 'Invalid settings section view: %1$s' ),
							$section_data['view']
						) );
					}
					include( $section_data['view'] );
				}
			},
			$args['page']
		);

		// Extend array to pass to array_walk as third parameter.
		$args['section'] = $section_name;
		array_walk( $section_data['fields'], [ $this, 'add_field' ], $args );
	}

	/**
	 * Add options field.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $field_data Arguments for the add_settings_field WP
	 *                           function.
	 * @param string $field_name Name of the settings field.
	 * @param array  $args       Contains both page and section name.
	 * @throws DomainException If the field view file does not exist.
	 */
	protected function add_field( $field_data, $field_name, $args ) {
		add_settings_field(
			$field_name,
			$field_data['title'],
			function () use ( $field_data, $args ) {
				// Fetch $options to pass into view.
				$options = get_option( $args['setting_name'] );
				if ( array_key_exists( 'view', $field_data ) ) {
					if ( ! file_exists( $field_data['view'] ) ) {
						throw new DomainException( sprintf(
							_( 'Invalid settings field view: %1$s' ),
							$field_data['view']
						) );
					}
					include( $field_data['view'] );
				}
			},
			$args['page'],
			$args['section']
		);
	}

	/**
	 * Enqueue dependencies of a page.
	 *
	 * @since 0.1.3
	 *
	 * @param string $handle Handle of a dependency to enqueue.
	 */
	protected function enqueue_dependency( $handle ) {
		if ( null === $this->dependency_manager ) {
			return;
		}
		$this->dependency_manager->enqueue_handle( $handle );
	}
}
