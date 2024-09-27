<?php
/*
 * Plugin name: Simple WP Crossposting – JetEngine
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Provides better compatibility with JetEngine.
 * Version: 1.0
 * Plugin URI: https://rudrastyh.com/support/jet-engine-compatibility
 */

class Rudr_SWC_JE {

	function __construct() {

		add_filter( 'rudr_swc_pre_crosspost_post_data', array( $this, 'process_fields' ), 25, 3 );
		add_filter( 'rudr_swc_pre_crosspost_term_data', array( $this, 'process_fields' ), 10, 3 );

	}

	function get_field( $meta_key, $context, $post_type_or_taxonomy_name ) {
		// that's going to be our field
		$field = array();
		// the best way is to get all the fields for this specific context
		$fields = jet_engine()->meta_boxes->get_fields_for_context(
			$context, // post_type or taxonomy
			$post_type_or_taxonomy_name
		);
//echo '<pre>';print_r( $fields );exit;
		// nothing found
		if( ! $fields ) {
			return $field;
		}
		// find the field in the array of fields
		foreach( $fields as $this_field ) {
			if( $meta_key === $this_field[ 'name' ] ) {
				$field = $this_field;
				break;
			}
		}

		return $field;

	}


	public function process_fields( $data, $blog ) {

		// if no meta fields do nothing
		if( ! isset( $data[ 'meta' ] ) || ! $data[ 'meta' ] || ! is_array( $data[ 'meta' ] ) ) {
			return $data;
		}
		// if no jet engine installed
		if( ! function_exists( 'jet_engine' ) ) {
			return $data;
		}
		// just in case
		if( empty( $data[ 'id' ] ) ) {
			return $data;
		}

		// we can not just use acf_get_field( $meta_key ) because it won't work for nested repeater fields
		if( 'rudr_swc_pre_crosspost_term_data' == current_filter() ) {
			$context = 'taxonomy';
			$post_type_or_taxonomy_name = get_term( $object_id )->taxonomy;
		} else {
			$context = 'post_type';
			$post_type_or_taxonomy_name = get_post_type( $object_id );
		}

		foreach( $data[ 'meta' ] as $meta_key => $meta_value ) {
			$field = $this->get_field( $meta_key, $context, $post_type_or_taxonomy_name );

			// not a jet engine field specifically
			if( empty( $field ) ) {
				continue;
			}

			$data[ 'meta' ][ $meta_key ] = $this->process_field_by_type( $meta_value, $field, $blog );
		}
//echo '<pre>';print_r( $data );exit;
		return $data;

	}

	public function process_field_by_type( $meta_value, $field, $blog ) {

		switch( $field[ 'type' ] ) {
			case 'media': {
				$meta_value = $this->process_media_field( $meta_value, $field, $blog );
				break;
			}
			case 'gallery' : {
				$meta_value = $this->process_gallery_field( $meta_value, $field, $blog );
				break;
			}
			case 'posts' : {
				$meta_value = $this->process_posts_field( $meta_value, $field, $blog );
				break;
			}
		}

		return $meta_value;

	}

	// media
	private function process_media_field( $meta_value, $field, $blog ) {
		// if store it as URL, do nothing is ok
		// id, url, both
		if( 'url' === $field[ 'value_format' ] ) {
			return $meta_value;
		}
//echo '<pre>';print_r( $meta_value );exit;
		$meta_value = maybe_unserialize( $meta_value );
		// at this moment we can have:
		// ID
		// Array( 'id' =>, 'url' => )
		if( 'both' === $field[ 'value_format' ] ) {
			$id = $meta_value[ 'id' ];
		} else {
			$id = $meta_value;
		}
		// let's do the image crossposting

		$upload = Rudr_Simple_WP_Crosspost::maybe_crosspost_image( $id, $blog );
		if( 'both' === $field[ 'value_format' ] ) {
			if( isset( $upload[ 'id' ] ) && $upload[ 'id' ] ) {
				$meta_value = array(
					'id' => $upload[ 'id' ],
					'url' => $upload[ 'url' ],
				);
			} else {
				$meta_value = array();
			}
		} else {
			if( isset( $upload[ 'id' ] ) && $upload[ 'id' ] ) {
				$meta_value = $upload[ 'id' ];
			} else {
				$meta_value = 0;
			}
		}
		// no serialization whatsoever
//echo '<pre>';print_r( $meta_value );exit;
		return $meta_value;
	}

	// gallery
	private function process_gallery_field( $meta_value, $field, $blog ) {
		// if store it as URL, do nothing is ok
		// id, url, both
		if( 'url' === $field[ 'value_format' ] ) {
			return $meta_value;
		}
//echo '<pre>';print_r( $meta_value );exit;
		$meta_value = maybe_unserialize( $meta_value );
		if( 'both' === $field[ 'value_format' ] ) {
			$ids = array_column( $meta_value, 'id' );
		} else {
			$ids = array_map( 'trim', explode( ',', $meta_value ) );
		}
//echo '<pre>';print_r( $ids );exit;
		// let's do the image crossposting

		$meta_value = array_filter( array_map( function( $id ) use ( $blog ) {
			$upload = Rudr_Simple_WP_Crosspost::maybe_crosspost_image( $id, $blog );
			if( isset( $upload[ 'id' ] ) && $upload[ 'id' ] ) {
				return array(
					'id' => $upload[ 'id' ],
					'url' => $upload[ 'url' ],
				);
			}
			return false; // will be removed with array_filter()
		}, $ids ) );

		$meta_value = 'both' === $field[ 'value_format' ] ? $meta_value : join( ',', array_column( $meta_value, 'id' ) );

		return $meta_value;
	}

	// posts
	private function process_posts_field( $meta_value, $field, $blog ) {

		$blog_id = Rudr_Simple_WP_Crosspost::get_blog_id( $blog );

		$meta_value = maybe_unserialize( $meta_value );
		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );

		$crossposted_ids = array();
		foreach( $ids as $id ) {
			$post_type = get_post_type( $id );
			if( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				// no need to check connection type, this method does that
				if( $product && ( $new_id = Rudr_Simple_Woo_Crosspost::is_crossposted_product( $product, $blog ) ) ) {
					$crossposted_ids[] = $new_id;
				}
			} else {
				if( $new_id = Rudr_Simple_WP_Crosspost::is_crossposted( $id, $blog_id ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}

		return is_array( $meta_value ) ? $crossposted_ids : ( $crossposted_ids ? reset( $crossposted_ids ) : 0 );

	}


}


new Rudr_SWC_JE;
