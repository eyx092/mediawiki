<?php
/**
 * Handler for DjVu images.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Media
 */
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

/**
 * Handler for DjVu images
 *
 * @ingroup Media
 */
class DjVuHandler extends ImageHandler {
	private const EXPENSIVE_SIZE_LIMIT = 10485760; // 10MiB

	// Constants for getHandlerState
	private const STATE_DJVU_IMAGE = 'djvuImage';
	private const STATE_TEXT_TREE = 'djvuTextTree';
	private const STATE_META_TREE = 'djvuMetaTree';

	/**
	 * @return bool
	 */
	public function isEnabled() {
		global $wgDjvuRenderer, $wgDjvuDump, $wgDjvuToXML;
		if ( !$wgDjvuRenderer || ( !$wgDjvuDump && !$wgDjvuToXML ) ) {
			wfDebug( "DjVu is disabled, please set \$wgDjvuRenderer and \$wgDjvuDump" );

			return false;
		} else {
			return true;
		}
	}

	/**
	 * @param File $file
	 * @return bool
	 */
	public function mustRender( $file ) {
		return true;
	}

	/**
	 * True if creating thumbnails from the file is large or otherwise resource-intensive.
	 * @param File $file
	 * @return bool
	 */
	public function isExpensiveToThumbnail( $file ) {
		return $file->getSize() > static::EXPENSIVE_SIZE_LIMIT;
	}

	/**
	 * @param File $file
	 * @return bool
	 */
	public function isMultiPage( $file ) {
		return true;
	}

	/**
	 * @return array
	 */
	public function getParamMap() {
		return [
			'img_width' => 'width',
			'img_page' => 'page',
		];
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @return bool
	 */
	public function validateParam( $name, $value ) {
		if ( $name === 'page' && trim( $value ) !== (string)intval( $value ) ) {
			// Extra junk on the end of page, probably actually a caption
			// e.g. [[File:Foo.djvu|thumb|Page 3 of the document shows foo]]
			return false;
		}
		if ( in_array( $name, [ 'width', 'height', 'page' ] ) ) {
			if ( $value <= 0 ) {
				return false;
			} else {
				return true;
			}
		} else {
			return false;
		}
	}

	/**
	 * @param array $params
	 * @return bool|string
	 */
	public function makeParamString( $params ) {
		$page = $params['page'] ?? 1;
		if ( !isset( $params['width'] ) ) {
			return false;
		}

		return "page{$page}-{$params['width']}px";
	}

	/**
	 * @param string $str
	 * @return array|bool
	 */
	public function parseParamString( $str ) {
		$m = false;
		if ( preg_match( '/^page(\d+)-(\d+)px$/', $str, $m ) ) {
			return [ 'width' => $m[2], 'page' => $m[1] ];
		} else {
			return false;
		}
	}

	/**
	 * @param array $params
	 * @return array
	 */
	protected function getScriptParams( $params ) {
		return [
			'width' => $params['width'],
			'page' => $params['page'],
		];
	}

	/**
	 * @param File $image
	 * @param string $dstPath
	 * @param string $dstUrl
	 * @param array $params
	 * @param int $flags
	 * @return MediaTransformError|ThumbnailImage|TransformParameterError
	 */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		global $wgDjvuRenderer, $wgDjvuPostProcessor;

		if ( !$this->normaliseParams( $image, $params ) ) {
			return new TransformParameterError( $params );
		}
		$width = $params['width'];
		$height = $params['height'];
		$page = $params['page'];

		if ( $flags & self::TRANSFORM_LATER ) {
			$params = [
				'width' => $width,
				'height' => $height,
				'page' => $page
			];

			return new ThumbnailImage( $image, $dstUrl, $dstPath, $params );
		}

		if ( !wfMkdirParents( dirname( $dstPath ), null, __METHOD__ ) ) {
			return new MediaTransformError(
				'thumbnail_error',
				$width,
				$height,
				wfMessage( 'thumbnail_dest_directory' )
			);
		}

		// Get local copy source for shell scripts
		// Thumbnail extraction is very inefficient for large files.
		// Provide a way to pool count limit the number of downloaders.
		if ( $image->getSize() >= 1e7 ) { // 10MB
			$work = new PoolCounterWorkViaCallback( 'GetLocalFileCopy', sha1( $image->getName() ),
				[
					'doWork' => static function () use ( $image ) {
						return $image->getLocalRefPath();
					}
				]
			);
			$srcPath = $work->execute();
		} else {
			$srcPath = $image->getLocalRefPath();
		}

		if ( $srcPath === false ) { // Failed to get local copy
			wfDebugLog( 'thumbnail',
				sprintf( 'Thumbnail failed on %s: could not get local copy of "%s"',
					wfHostname(), $image->getName() ) );

			return new MediaTransformError( 'thumbnail_error',
				$params['width'], $params['height'],
				wfMessage( 'filemissing' )
			);
		}

		# Use a subshell (brackets) to aggregate stderr from both pipeline commands
		# before redirecting it to the overall stdout. This works in both Linux and Windows XP.
		$cmd = '(' . Shell::escape(
			$wgDjvuRenderer,
			"-format=ppm",
			"-page={$page}",
			"-size={$params['physicalWidth']}x{$params['physicalHeight']}",
			$srcPath );
		if ( $wgDjvuPostProcessor ) {
			$cmd .= " | {$wgDjvuPostProcessor}";
		}
		$cmd .= ' > ' . Shell::escape( $dstPath ) . ') 2>&1';
		wfDebug( __METHOD__ . ": $cmd" );
		$retval = '';
		$err = wfShellExec( $cmd, $retval );

		$removed = $this->removeBadFile( $dstPath, $retval );
		if ( $retval != 0 || $removed ) {
			$this->logErrorForExternalProcess( $retval, $err, $cmd );
			return new MediaTransformError( 'thumbnail_error', $width, $height, $err );
		} else {
			$params = [
				'width' => $width,
				'height' => $height,
				'page' => $page
			];

			return new ThumbnailImage( $image, $dstUrl, $dstPath, $params );
		}
	}

	/**
	 * Cache an instance of DjVuImage in a MediaHandlerState object, return
	 * that instance
	 *
	 * @param MediaHandlerState $state
	 * @param string $path
	 * @return DjVuImage
	 */
	private function getDjVuImage( $state, $path ) {
		$deja = $state->getHandlerState( self::STATE_DJVU_IMAGE );
		if ( !$deja ) {
			$deja = new DjVuImage( $path );
			$state->setHandlerState( self::STATE_DJVU_IMAGE, $deja );
		}
		return $deja;
	}

	/**
	 * Get metadata, unserializing it if necessary.
	 *
	 * @param File $file The DjVu file in question
	 * @return string XML metadata as a string.
	 * @throws MWException
	 */
	private function getUnserializedMetadata( File $file ) {
		$metadata = $file->getMetadata();
		if ( substr( $metadata, 0, 3 ) === '<?xml' ) {
			// Old style. Not serialized but instead just a raw string of XML.
			return $metadata;
		}

		Wikimedia\suppressWarnings();
		$unser = unserialize( $metadata );
		Wikimedia\restoreWarnings();
		if ( is_array( $unser ) ) {
			if ( isset( $unser['error'] ) ) {
				return false;
			} elseif ( isset( $unser['xml'] ) ) {
				return $unser['xml'];
			} else {
				// Should never ever reach here.
				throw new MWException( "Error unserializing DjVu metadata." );
			}
		}

		// unserialize failed. Guess it wasn't really serialized after all,
		return $metadata;
	}

	/**
	 * Cache a document tree for the DjVu XML metadata
	 * @param File $image
	 * @param bool $gettext DOCUMENT (Default: false)
	 * @return bool|SimpleXMLElement
	 */
	public function getMetaTree( $image, $gettext = false ) {
		if ( $gettext && $image->getHandlerState( self::STATE_TEXT_TREE ) ) {
			return $image->getHandlerState( self::STATE_TEXT_TREE );
		}
		if ( !$gettext && $image->getHandlerState( self::STATE_META_TREE ) ) {
			return $image->getHandlerState( self::STATE_META_TREE );
		}

		$metadata = $this->getUnserializedMetadata( $image );
		if ( !$this->isMetadataValid( $image, $metadata ) ) {
			wfDebug( "DjVu XML metadata is invalid or missing, should have been fixed in upgradeRow" );

			return false;
		}

		$trees = $this->extractTreesFromMetadata( $metadata );
		$image->setHandlerState( self::STATE_TEXT_TREE, $trees['TextTree'] );
		$image->setHandlerState( self::STATE_META_TREE, $trees['MetaTree'] );

		if ( $gettext ) {
			return $trees['TextTree'];
		} else {
			return $trees['MetaTree'];
		}
	}

	/**
	 * Extracts metadata and text trees from metadata XML in string form
	 * @param string $metadata XML metadata as a string
	 * @return array
	 */
	protected function extractTreesFromMetadata( $metadata ) {
		Wikimedia\suppressWarnings();
		try {
			// Set to false rather than null to avoid further attempts
			$metaTree = false;
			$textTree = false;
			$tree = new SimpleXMLElement( $metadata, LIBXML_PARSEHUGE );
			if ( $tree->getName() == 'mw-djvu' ) {
				/** @var SimpleXMLElement $b */
				foreach ( $tree->children() as $b ) {
					if ( $b->getName() == 'DjVuTxt' ) {
						// @todo File::djvuTextTree and File::dejaMetaTree are declared
						// dynamically. Add a public File::$data to facilitate this?
						$textTree = $b;
					} elseif ( $b->getName() == 'DjVuXML' ) {
						$metaTree = $b;
					}
				}
			} else {
				$metaTree = $tree;
			}
		} catch ( Exception $e ) {
			wfDebug( "Bogus multipage XML metadata" );
		}
		Wikimedia\restoreWarnings();

		return [ 'MetaTree' => $metaTree, 'TextTree' => $textTree ];
	}

	public function getImageSize( $image, $path ) {
		$state = $image instanceof MediaHandlerState ? $image : new TrivialMediaHandlerState;
		return $this->getDjVuImage( $state, $path )->getImageSize();
	}

	public function getThumbType( $ext, $mime, $params = null ) {
		global $wgDjvuOutputExtension;
		static $mime;
		if ( !isset( $mime ) ) {
			$magic = MediaWiki\MediaWikiServices::getInstance()->getMimeAnalyzer();
			$mime = $magic->getMimeTypeFromExtensionOrNull( $wgDjvuOutputExtension );
		}

		return [ $wgDjvuOutputExtension, $mime ];
	}

	public function getMetadata( $image, $path ) {
		wfDebug( "Getting DjVu metadata for $path" );

		$state = $image instanceof MediaHandlerState ? $image : new TrivialMediaHandlerState;
		$xml = $this->getDjVuImage( $state, $path )->retrieveMetaData();
		if ( $xml === false ) {
			// Special value so that we don't repetitively try and decode a broken file.
			return serialize( [ 'error' => 'Error extracting metadata' ] );
		} else {
			return serialize( [ 'xml' => $xml ] );
		}
	}

	public function getMetadataType( $image ) {
		return 'djvuxml';
	}

	public function isMetadataValid( $image, $metadata ) {
		return !empty( $metadata ) && $metadata != serialize( [] );
	}

	public function pageCount( File $image ) {
		$info = $this->getDimensionInfo( $image );

		return $info ? $info['pageCount'] : false;
	}

	public function getPageDimensions( File $image, $page ) {
		$index = $page - 1; // MW starts pages at 1

		$info = $this->getDimensionInfo( $image );
		if ( $info && isset( $info['dimensionsByPage'][$index] ) ) {
			return $info['dimensionsByPage'][$index];
		}

		return false;
	}

	protected function getDimensionInfo( File $file ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		return $cache->getWithSetCallback(
			$cache->makeKey( 'file-djvu', 'dimensions', $file->getSha1() ),
			$cache::TTL_INDEFINITE,
			function () use ( $file ) {
				$tree = $this->getMetaTree( $file );
				return $this->getDimensionInfoFromMetaTree( $tree );
			},
			[ 'pcTTL' => $cache::TTL_INDEFINITE ]
		);
	}

	/**
	 * Given an XML metadata tree, returns dimension information about the document
	 * @param bool|SimpleXMLElement $metatree The file's XML metadata tree
	 * @return bool|array
	 */
	protected function getDimensionInfoFromMetaTree( $metatree ) {
		if ( !$metatree ) {
			return false;
		}

		$dimsByPage = [];
		$count = count( $metatree->xpath( '//OBJECT' ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$o = $metatree->BODY[0]->OBJECT[$i];
			if ( $o ) {
				$dimsByPage[$i] = [
					'width' => (int)$o['width'],
					'height' => (int)$o['height'],
				];
			} else {
				$dimsByPage[$i] = false;
			}
		}

		return [ 'pageCount' => $count, 'dimensionsByPage' => $dimsByPage ];
	}

	/**
	 * @param File $image
	 * @param int $page Page number to get information for
	 * @return bool|string Page text or false when no text found.
	 */
	public function getPageText( File $image, $page ) {
		$tree = $this->getMetaTree( $image, true );
		if ( !$tree ) {
			return false;
		}

		$o = $tree->BODY[0]->PAGE[$page - 1];
		return $o ? (string)$o['value'] : false;
	}
}
