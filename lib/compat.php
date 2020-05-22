<?php
/**
 * Temporary compatibility shims for features present in Gutenberg, pending
 * upstream commit to the WordPress core source repository. Functions here
 * exist only as long as necessary for corresponding WordPress support, and
 * each should be associated with a Trac ticket.
 *
 * @package gutenberg
 */

if ( ! function_exists( 'register_block_type_from_metadata' ) ) {
	/**
	 * Registers a block type from metadata stored in the `block.json` file.
	 *
	 * @since 7.9.0
	 *
	 * @param string $path Path to the folder where the `block.json` file is located.
	 * @param array  $args {
	 *     Optional. Array of block type arguments. Any arguments may be defined, however the
	 *     ones described below are supported by default. Default empty array.
	 *
	 *     @type callable $render_callback Callback used to render blocks of this block type.
	 * }
	 * @return WP_Block_Type|false The registered block type on success, or false on failure.
	 */
	function register_block_type_from_metadata( $path, $args = array() ) {
		$metadata_file = trailingslashit( $path ) . 'block.json';
		if ( ! file_exists( $metadata_file ) ) {
			return false;
		}

		$metadata = json_decode( file_get_contents( $metadata_file ), true );
		if ( ! is_array( $metadata ) || ! $metadata['name'] ) {
			return false;
		}

		$block_dir           = dirname( $metadata_file );
		$block_name          = $metadata['name'];
		$asset_handle_prefix = str_replace( '/', '-', $block_name );

		if ( isset( $metadata['editorScript'] ) ) {
			$editor_script            = $metadata['editorScript'];
			$editor_script_handle     = "$asset_handle_prefix-editor-script";
			$editor_script_asset_path = realpath(
				$block_dir . '/' . substr_replace( $editor_script, '.asset.php', -3 )
			);
			if ( ! file_exists( $editor_script_asset_path ) ) {
				throw new Error(
					"The asset file for the \"editorScript\" defined in \"$block_name\" block definition is missing."
				);
			}
			$editor_script_asset  = require( $editor_script_asset_path );
			wp_register_script(
				$editor_script_handle,
				plugins_url( $editor_script, $metadata_file ),
				$editor_script_asset['dependencies'],
				$editor_script_asset['version']
			);
			unset( $metadata['editorScript'] );
			$metadata['editor_script'] = $editor_script_handle;
		}

		if ( isset( $metadata['script'] ) ) {
			$script            = $metadata['script'];
			$script_handle     = "$asset_handle_prefix-script";
			$script_asset_path = realpath(
				$block_dir . '/' . substr_replace( $script, '.asset.php', -3 )
			);
			if ( ! file_exists( $script_asset_path ) ) {
				throw new Error(
					"The asset file for the \"script\" defined in \"$block_name\" block definition is missing."
				);
			}
			$script_asset  = require( $script_asset_path );
			wp_register_script(
				$script_handle,
				plugins_url( $script, $metadata_file ),
				$script_asset['dependencies'],
				$script_asset['version']
			);
			$metadata['script'] = $script_handle;
		}

		if ( isset( $metadata['editorStyle'] ) ) {
			$editor_style        = $metadata['editorStyle'];
			$editor_style_handle = "$asset_handle_prefix-editor-style";
			wp_register_style(
				$editor_style_handle,
				plugins_url( $editor_style, $metadata_file ),
				array(),
				filemtime( realpath( "$block_dir/$editor_style" ) )
			);
			unset( $metadata['editorStyle'] );
			$metadata['editor_style'] = $editor_style_handle;
		}

		if ( isset( $metadata['style'] ) ) {
			$style        = $metadata['style'];
			$style_handle = "$asset_handle_prefix-style";
			wp_register_style(
				$style_handle,
				plugins_url( $style, $metadata_file ),
				array(),
				filemtime( realpath( "$block_dir/$style" ) )
			);
			$metadata['style'] = $style_handle;
		}

		return register_block_type(
			$block_name,
			array_merge(
				$metadata,
				$args
			)
		);
	}
}

/**
 * Extends block editor settings to determine whether to use drop cap feature.
 *
 * @param array $settings Default editor settings.
 *
 * @return array Filtered editor settings.
 */
function gutenberg_extend_settings_drop_cap( $settings ) {
	$settings['__experimentalDisableDropCap'] = false;
	return $settings;
}
add_filter( 'block_editor_settings', 'gutenberg_extend_settings_drop_cap' );


/**
 * Extends block editor settings to include a list of image dimensions per size.
 *
 * This can be removed when plugin support requires WordPress 5.4.0+.
 *
 * @see https://core.trac.wordpress.org/ticket/49389
 * @see https://core.trac.wordpress.org/changeset/47240
 *
 * @param array $settings Default editor settings.
 *
 * @return array Filtered editor settings.
 */
function gutenberg_extend_settings_image_dimensions( $settings ) {
	/*
	 * Only filter settings if:
	 * 1. `imageDimensions` is not already assigned, in which case it can be
	 *    assumed to have been set from WordPress 5.4.0+ default settings.
	 * 2. `imageSizes` is an array. Plugins may run `block_editor_settings`
	 *    directly and not provide all properties of the settings array.
	 */
	if ( ! isset( $settings['imageDimensions'] ) && ! empty( $settings['imageSizes'] ) ) {
		$image_dimensions = array();
		$all_sizes        = wp_get_registered_image_subsizes();
		foreach ( $settings['imageSizes'] as $size ) {
			$key = $size['slug'];
			if ( isset( $all_sizes[ $key ] ) ) {
				$image_dimensions[ $key ] = $all_sizes[ $key ];
			}
		}
		$settings['imageDimensions'] = $image_dimensions;
	}

	return $settings;
}
add_filter( 'block_editor_settings', 'gutenberg_extend_settings_image_dimensions' );

/**
 * Adds a polyfill for the WHATWG URL in environments which do not support it.
 * The intention in how this action is handled is under the assumption that this
 * code would eventually be placed at `wp_default_packages_vendor`, which is
 * called as a result of `wp_default_packages` via the `wp_default_scripts`.
 *
 * This can be removed when plugin support requires WordPress 5.4.0+.
 *
 * The script registration occurs in `gutenberg_register_vendor_scripts`, which
 * should be removed in coordination with this function.
 *
 * @see gutenberg_register_vendor_scripts
 * @see https://core.trac.wordpress.org/ticket/49360
 * @see https://developer.mozilla.org/en-US/docs/Web/API/URL/URL
 * @see https://developer.wordpress.org/reference/functions/wp_default_packages_vendor/
 *
 * @since 7.3.0
 *
 * @param WP_Scripts $scripts WP_Scripts object.
 */
function gutenberg_add_url_polyfill( $scripts ) {
	did_action( 'init' ) && $scripts->add_inline_script(
		'wp-polyfill',
		wp_get_script_polyfill(
			$scripts,
			array(
				'window.URL && window.URL.prototype && window.URLSearchParams' => 'wp-polyfill-url',
			)
		)
	);
}
add_action( 'wp_default_scripts', 'gutenberg_add_url_polyfill', 20 );

/**
 * Adds a polyfill for DOMRect in environments which do not support it.
 *
 * This can be removed when plugin support requires WordPress 5.4.0+.
 *
 * The script registration occurs in `gutenberg_register_vendor_scripts`, which
 * should be removed in coordination with this function.
 *
 * @see gutenberg_register_vendor_scripts
 * @see gutenberg_add_url_polyfill
 * @see https://core.trac.wordpress.org/ticket/49360
 * @see https://developer.mozilla.org/en-US/docs/Web/API/DOMRect
 * @see https://developer.wordpress.org/reference/functions/wp_default_packages_vendor/
 *
 * @since 7.5.0
 *
 * @param WP_Scripts $scripts WP_Scripts object.
 */
function gutenberg_add_dom_rect_polyfill( $scripts ) {
	did_action( 'init' ) && $scripts->add_inline_script(
		'wp-polyfill',
		wp_get_script_polyfill(
			$scripts,
			array(
				'window.DOMRect' => 'wp-polyfill-dom-rect',
			)
		)
	);
}
add_action( 'wp_default_scripts', 'gutenberg_add_dom_rect_polyfill', 20 );

/**
 * Sets the current post for usage in template blocks.
 *
 * @return WP_Post|null The post if any, or null otherwise.
 */
function gutenberg_get_post_from_context() {
	// TODO: Without this temporary fix, an infinite loop can occur where
	// posts with post content blocks render themselves recursively.
	if ( is_admin() || defined( 'REST_REQUEST' ) ) {
		return null;
	}

	_deprecated_function( __FUNCTION__, '8.1.0' );

	if ( ! in_the_loop() ) {
		rewind_posts();
		the_post();
	}
	return get_post();
}

/**
 * Shim that hooks into `pre_render_block` so as to override `render_block` with
 * a function that assigns block context.
 *
 * This can be removed when plugin support requires WordPress 5.5.0+.
 *
 * @see https://core.trac.wordpress.org/ticket/49927
 *
 * @param string|null $pre_render   The pre-rendered content. Defaults to null.
 * @param array       $parsed_block The parsed block being rendered.
 *
 * @return string String of rendered HTML.
 */
function gutenberg_render_block_with_assigned_block_context( $pre_render, $parsed_block ) {
	global $post, $wp_query;

	/*
	 * If a non-null value is provided, a filter has run at an earlier priority
	 * and has already handled custom rendering and should take precedence.
	 */
	if ( null !== $pre_render ) {
		return $pre_render;
	}

	$source_block = $parsed_block;

	/** This filter is documented in src/wp-includes/blocks.php */
	$parsed_block = apply_filters( 'render_block_data', $parsed_block, $source_block );

	$context = array(
		'postId'   => $post->ID,

		/*
		 * The `postType` context is largely unnecessary server-side, since the
		 * ID is usually sufficient on its own. That being said, since a block's
		 * manifest is expected to be shared between the server and the client,
		 * it should be included to consistently fulfill the expectation.
		 */
		'postType' => $post->post_type,

		'query'    => array( 'categoryIds' => array() ),
	);

	if ( isset( $wp_query->tax_query->queried_terms['category']['terms'] ) ) {
		foreach ( $wp_query->tax_query->queried_terms['category']['terms'] as $category_id ) {
			$context['query']['categoryIds'][] = $category_id;
		}
	}

	/**
	 * Filters the default context provided to a rendered block.
	 *
	 * @param array $context      Default context.
	 * @param array $parsed_block Block being rendered, filtered by `render_block_data`.
	 */
	$context = apply_filters( 'render_block_context', $context, $parsed_block );

	$block = new WP_Block( $parsed_block, $context );

	return $block->render();
}
add_filter( 'pre_render_block', 'gutenberg_render_block_with_assigned_block_context', 9, 2 );
