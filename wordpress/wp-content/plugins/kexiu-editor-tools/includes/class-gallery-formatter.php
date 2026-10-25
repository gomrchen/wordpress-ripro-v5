<?php
/**
 * 截图画廊整理逻辑
 */

defined( 'ABSPATH' ) || exit;

class Kexiu_Gallery_Formatter {

	const GALLERY_CLASS       = 'kexiu-screenshots';
	const GALLERY_WIDE_CLASS  = 'kexiu-screenshots-wide';
	const PREVIEW_HEADING     = '界面预览';

	/**
	 * @param string  $content Post content.
	 * @param integer $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function format( $content, $post_id ) {
		$candidate_ids = $this->collect_candidate_ids( $content, $post_id );

		if ( empty( $candidate_ids ) ) {
			return new WP_Error( 'no_images', '未找到可整理的截图。' );
		}

		$vertical_ids   = [];
		$horizontal_ids = [];
		foreach ( $candidate_ids as $id ) {
			if ( $this->is_landscape( $id ) ) {
				$horizontal_ids[] = $id;
			} else {
				$vertical_ids[] = $id;
			}
		}

		$poster_block = $this->extract_poster_block( $content );
		$body         = $this->strip_gallery_and_candidates( $content, $candidate_ids );

		$gallery_html = $this->build_gallery_section( $vertical_ids, $horizontal_ids, $post_id );
		$new_content  = $this->insert_gallery( $body, $gallery_html );

		if ( $poster_block ) {
			$new_content = $this->ensure_poster_at_end( $new_content, $poster_block );
		}

		$new_content = trim( $new_content );

		return [
			'content'    => $new_content,
			'count'      => count( $candidate_ids ),
			'ids'        => $candidate_ids,
			'vertical'   => count( $vertical_ids ),
			'horizontal' => count( $horizontal_ids ),
		];
	}

	/**
	 * @param string  $content Post content.
	 * @param integer $post_id Post ID.
	 * @return int[]
	 */
	private function collect_candidate_ids( $content, $post_id ) {
		$ids = [];

		foreach ( [ self::GALLERY_CLASS, self::GALLERY_WIDE_CLASS ] as $gallery_class ) {
			if ( preg_match( '/class="[^"]*\b' . preg_quote( $gallery_class, '/' ) . '\b[^"]*"/', $content ) ) {
				$gallery_html = $this->extract_gallery_html( $content, $gallery_class );
				if ( $gallery_html && preg_match_all( '/wp-image-(\d+)/', $gallery_html, $matches ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
				}
			}
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$ids = array_filter( $ids, function ( $id ) use ( $post_id ) {
			return $this->is_gallery_candidate( $id, $post_id );
		} );

		usort( $ids, function ( $a, $b ) {
			$pa = get_post( $a );
			$pb = get_post( $b );
			if ( ! $pa || ! $pb ) {
				return $a <=> $b;
			}
			return strcmp( $pa->post_date, $pb->post_date ) ?: ( $a <=> $b );
		} );

		return array_values( $ids );
	}

	/**
	 * @param integer $attachment_id Attachment ID.
	 * @param integer $post_id       Post ID.
	 */
	private function is_gallery_candidate( $attachment_id, $post_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		if ( (int) get_post_thumbnail_id( $post_id ) === (int) $attachment_id ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		$base = $file ? wp_basename( $file ) : $attachment->post_title;

		if ( preg_match( '/cover|poster|favicon|logo|qr/i', $base ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param integer $attachment_id Attachment ID.
	 */
	private function is_landscape( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$w    = (int) ( $meta['width'] ?? 0 );
		$h    = (int) ( $meta['height'] ?? 0 );

		return $w > 0 && $h > 0 && $w > $h * 1.05;
	}

	private function extract_gallery_html( $content, $gallery_class = self::GALLERY_CLASS ) {
		if ( preg_match(
			'/(<!-- wp:gallery -->[\s\S]*?<figure[^>]*\b' . preg_quote( $gallery_class, '/' ) . '\b[\s\S]*?<\/figure>\s*<!-- \/wp:gallery -->|<figure[^>]*\b' . preg_quote( $gallery_class, '/' ) . '\b[\s\S]*?<\/figure>)/',
			$content,
			$match
		) ) {
			return $match[1];
		}
		return '';
	}

	private function extract_poster_block( $content ) {
		if ( ! preg_match(
			'/(<!-- wp:heading[^>]*-->[\s\S]*?<h2[^>]*>宣传海报<\/h2>[\s\S]*?<!-- \/wp:image -->|<h2[^>]*>宣传海报<\/h2>[\s\S]*?(?:<!-- \/wp:image -->|<\/figure>))/u',
			$content,
			$match
		) ) {
			return '';
		}

		return trim( $match[1] );
	}

	/**
	 * @param string $content Post content.
	 * @param int[]  $ids     Attachment IDs to strip.
	 */
	private function strip_gallery_and_candidates( $content, $ids ) {
		$content = preg_replace(
			'/<!-- wp:gallery -->[\s\S]*?<!-- \/wp:gallery -->/u',
			'',
			$content
		);

		$content = preg_replace(
			'/<figure[^>]*\b(?:' . self::GALLERY_CLASS . '|' . self::GALLERY_WIDE_CLASS . ')\b[\s\S]*?<\/figure>/u',
			'',
			$content
		);

		foreach ( $ids as $id ) {
			$content = preg_replace(
				'/<!-- wp:image[^>]*-->[\s\S]*?wp-image-' . $id . '[\s\S]*?<!-- \/wp:image -->/u',
				'',
				$content
			);
			$content = preg_replace(
				'/<figure[^>]*>[\s\S]*?wp-image-' . $id . '[\s\S]*?<\/figure>/u',
				'',
				$content
			);
			$content = preg_replace(
				'/<p[^>]*>\s*(?:<a[^>]*>\s*)?<img[^>]*wp-image-' . $id . '[^>]*>(?:\s*<\/a>)?\s*<\/p>/u',
				'',
				$content
			);
			$content = preg_replace(
				'/<img[^>]*wp-image-' . $id . '[^>]*>/u',
				'',
				$content
			);
		}

		if ( $poster = $this->extract_poster_block( $content ) ) {
			$content = str_replace( $poster, '', $content );
		}

		$content = preg_replace( "/\n{3,}/", "\n\n", $content );

		return trim( $content );
	}

	/**
	 * @param int[]   $vertical_ids   Portrait attachment IDs.
	 * @param int[]   $horizontal_ids Landscape attachment IDs.
	 * @param integer $post_id        Post ID.
	 */
	private function build_gallery_section( $vertical_ids, $horizontal_ids, $post_id ) {
		$html = '';

		if ( ! empty( $vertical_ids ) ) {
			$html .= $this->build_gallery_html( $vertical_ids, $post_id, self::GALLERY_CLASS, '界面预览' );
		}

		if ( ! empty( $horizontal_ids ) ) {
			if ( $html ) {
				$html .= "\n\n";
			}
			$html .= $this->build_gallery_html( $horizontal_ids, $post_id, self::GALLERY_WIDE_CLASS, '界面预览' );
		}

		return $html;
	}

	/**
	 * @param int[]   $ids           Attachment IDs.
	 * @param integer $post_id       Post ID.
	 * @param string  $gallery_class Gallery CSS class.
	 * @param string  $alt_suffix    Alt text suffix.
	 */
	private function build_gallery_html( $ids, $post_id, $gallery_class, $alt_suffix ) {
		$alt = get_the_title( $post_id ) . ' ' . $alt_suffix;

		$figures = '';
		foreach ( $ids as $aid ) {
			$ver   = get_post_meta( $aid, '_ew_attachment_version', true );
			$full  = wp_get_attachment_url( $aid );
			$thumb = wp_get_attachment_image_url( $aid, 'medium' );

			if ( $ver ) {
				$full  = remove_query_arg( 'v', $full ) . '?v=' . $ver;
				$thumb = remove_query_arg( 'v', $thumb ) . '?v=' . $ver;
			}

			$figures .= sprintf(
				'<figure class="wp-block-image"><a href="%s"><img class="wp-image-%d" src="%s" alt="%s" /></a></figure>' . "\n",
				esc_url( $full ),
				$aid,
				esc_url( $thumb ),
				esc_attr( $alt )
			);
		}

		return "<!-- wp:gallery -->\n<figure class=\"wp-block-gallery has-nested-images columns-2 is-cropped {$gallery_class}\">\n"
			. $figures
			. "</figure>\n<!-- /wp:gallery -->";
	}

	private function insert_gallery( $content, $gallery_html ) {
		$heading_block = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">" . self::PREVIEW_HEADING . "</h2>\n<!-- /wp:heading -->\n\n";

		if ( preg_match(
			'/(<!-- wp:heading[^>]*-->[\s\S]*?<h2[^>]*>' . preg_quote( self::PREVIEW_HEADING, '/' ) . '<\/h2>[\s\S]*?<!-- \/wp:heading -->|<h2[^>]*>' . preg_quote( self::PREVIEW_HEADING, '/' ) . '<\/h2>)/u',
			$content,
			$match,
			PREG_OFFSET_CAPTURE
		) ) {
			$insert_at = $match[0][1] + strlen( $match[0][0] );
			return substr( $content, 0, $insert_at ) . "\n\n" . $gallery_html . substr( $content, $insert_at );
		}

		return rtrim( $content ) . "\n\n" . $heading_block . $gallery_html;
	}

	private function ensure_poster_at_end( $content, $poster_block ) {
		if ( false !== strpos( $content, '宣传海报' ) ) {
			return $content;
		}

		return rtrim( $content ) . "\n\n" . $poster_block;
	}
}
