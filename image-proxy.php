<?php
// Debugging!
// error_reporting( E_ALL );
// ini_set( 'display_errors', 'On' );

// Paths
$parent_dir = realpath( __DIR__ . DIRECTORY_SEPARATOR . '..' );
$photon_dir = $parent_dir . DIRECTORY_SEPARATOR . 'photon';
define( 'PHOTON_DIR',  $photon_dir );

// Setup Photon Configuration
define( 'PHOTON__ALLOW_QUERY_STRINGS', 1 );

require PHOTON_DIR . '/plugin.php';
if ( file_exists( PHOTON_DIR . '/../config.php' ) ) {
	require PHOTON_DIR . '/../config.php';
} else if ( file_exists( PHOTON_DIR . '/config.php' ) ) {
	require PHOTON_DIR . '/config.php';
}
// Explicit Configuration
$allowed_functions = apply_filters( 'allowed_functions', array(
//	'q'           => RESERVED
//	'zoom'        => global resolution multiplier (argument filter)
//	'quality'     => sets the quality of JPEG images during processing
//	'strip        => strips JPEG images of exif, icc or all "extra" data (params: info,color,all)
	'h'           => 'set_height',      // done
	'w'           => 'set_width',       // done
	'crop'        => 'crop',            // done
	'resize'      => 'resize_and_crop', // done
	'fit'         => 'fit_in_box',      // done
	'lb'          => 'letterbox',       // done
	'ulb'         => 'unletterbox',     // compat
	'filter'      => 'filter',          // compat
	'brightness'  => 'brightness',      // compat
	'contrast'    => 'contrast',        // compat
	'colorize'    => 'colorize',        // compat
	'smooth'      => 'smooth',          // compat
) );

unset( $allowed_functions['q'] );

$allowed_types = apply_filters( 'allowed_types', array(
	'gif',
	'jpg',
	'jpeg',
	'png',
) );

$disallowed_file_headers = apply_filters( 'disallowed_file_headers', array(
	'8BPS',
) );

$remote_image_max_size = apply_filters( 'remote_image_max_size', 55 * 1024 * 1024 );

/* Array of domains exceptions
 * Keys are domain name
 * Values are bitmasks with the following options:
 * PHOTON__ALLOW_QUERY_STRINGS: Append the string found in the 'q' query string parameter as the query string of the remote URL
 */
$origin_domain_exceptions = apply_filters( 'origin_domain_exceptions', array() );

// Set an external fallback domain to fetch an image from if it is not found locally
$external_fallback_domain = apply_filters( 'external_fallback_domain', '' );
$external_fallback_domain_scheme = 'http';
$parsed_external_fallback_domain = parse_url( $external_fallback_domain );
if ( ! empty( $parsed_external_fallback_domain['host'] ) ) {
	$external_fallback_domain = $parsed_external_fallback_domain['host'];
}
if ( ! empty( $parsed_external_fallback_domain['scheme'] ) ) {
	$external_fallback_domain_scheme = $parsed_external_fallback_domain['scheme'];
}
define( 'EXTERNAL_FALLBACK_DOMAIN', $external_fallback_domain );
define( 'EXTERNAL_FALLBACK_DOMAIN_SCHEME', $external_fallback_domain );

define( 'JPG_MAX_QUALITY', 89 );
define( 'PNG_MAX_QUALITY', 80 );
define( 'WEBP_MAX_QUALITY', 80 );

// The 'w' and 'h' parameter are processed distinctly
define( 'ALLOW_DIMS_CHAINING', true );

// Strip all meta data from WebP images by default
define( 'CWEBP_DEFAULT_META_STRIP', 'all' );

// You can override this by defining it in config.php
if ( ! defined( 'UPSCALE_MAX_PIXELS' ) )
	define( 'UPSCALE_MAX_PIXELS', 2000 );

// Allow smaller upscales for GIFs, compared to the other image types
if ( ! defined( 'UPSCALE_MAX_PIXELS_GIF' ) )
	define( 'UPSCALE_MAX_PIXELS_GIF', 1000 );

// Implicit configuration
if ( file_exists( '/usr/local/bin/optipng' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'OPTIPNG', '/usr/local/bin/optipng' );
else
	define( 'OPTIPNG', false );

if ( file_exists( '/usr/local/bin/pngquant' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'PNGQUANT', '/usr/local/bin/pngquant' );
else
	define( 'PNGQUANT', false );

/*
We don't have a special CDN set-up to handle conditionally serving
WEBP images to those agents that support it. For now we set CWEBP to false

if ( file_exists( '/usr/local/bin/cwebp' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'CWEBP', '/usr/local/bin/cwebp' );
else
*/
	define( 'CWEBP', false );

if ( file_exists( '/usr/local/bin/jpegoptim' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'JPEGOPTIM', '/usr/local/bin/jpegoptim' );
else
	define( 'JPEGOPTIM', false );

require PHOTON_DIR . '/class-image-processor.php';

// Helper Functions

/**
 * Send an error message to the client
 *
 * @param  string $code    HTTP status code of the error message
 * @param  string $message Error message to be displayed
 */
function httpdie( $code = '404 Not Found', $message = 'Error: 404 Not Found' ) {
	$numerical_error_code = preg_replace( '/[^\\d]/', '', $code );
	do_action( 'bump_stats', "http_error-$numerical_error_code" );
	header( 'HTTP/1.1 ' . $code );
	die( $message );
}

/**
 * Make an HTTP request for an external image to download and process
 *
 * @param  string  $url             URL of the image to fetch
 * @param  integer $timeout         Maximum time the request is allowed to take in seconds
 * @param  integer $connect_timeout Maximum time in seconds that you allow the connection phase to the server to take
 * @param  integer $max_redirs      Maximum number of redirects to follow
 * @return boolean                  Whether fetching the image was sucessful or not
 */
function fetch_raw_data( $url, $timeout = 10, $connect_timeout = 3, $max_redirs = 3 ) {
	// reset image data since we redirect recursively
	$GLOBALS['raw_data'] = '';
	$GLOBALS['raw_data_size'] = 0;

	$parsed = parse_url( apply_filters( 'url', $url ) );
	$required = array( 'scheme', 'host', 'path' );

	if ( ! $parsed || count( array_intersect_key( array_flip( $required ), $parsed ) ) !== count( $required ) ) {
		do_action( 'bump_stats', 'invalid_url' );
		return false;
	}

	$ip   = gethostbyname( $parsed['host'] );
	$port = getservbyname( $parsed['scheme'], 'tcp' );
	$url  = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];

	if ( PHOTON__ALLOW_QUERY_STRINGS && isset( $parsed['query'] ) ) {
		$host = strtolower( $parsed['host'] );
		if ( array_key_exists( $host, $GLOBALS['origin_domain_exceptions'] ) ) {
			if ( $GLOBALS['origin_domain_exceptions'][$host] ) {
				$url .= '?' . $parsed['query'];
			}
		}
	}

	// https://bugs.php.net/bug.php?id=64948
	if ( ! filter_var( str_replace( '_', '-', $url ), FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_PATH_REQUIRED ) ) {
		do_action( 'bump_stats', 'invalid_url' );
		return false;
	}

	$allowed_ip_types = array( 'flags' => FILTER_FLAG_IPV4, );
	if ( apply_filters( 'allow_ipv6', false ) ) {
		$allowed_ip_types['flags'] |= FILTER_FLAG_IPV6;
	}

	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, $allowed_ip_types ) ) {
		do_action( 'bump_stats', 'invalid_ip' );
		return false;
	}

	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) && ! apply_filters( 'allow_private_ips', false ) ) {
		do_action( 'bump_stats', 'private_ip' );
		return false;
	}

	if ( isset( $parsed['port'] ) && $parsed['port'] !== $port ) {
		do_action( 'bump_stats', 'invalid_port' );
		return false;
	}

	$ch = curl_init( $url );

	curl_setopt_array( $ch, array(
		CURLOPT_USERAGENT            => 'Photon/1.0',
		CURLOPT_TIMEOUT              => $timeout,
		CURLOPT_CONNECTTIMEOUT       => $connect_timeout,
		CURLOPT_PROTOCOLS            => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		CURLOPT_SSL_VERIFYPEER       => apply_filters( 'ssl_verify_peer', false ),
		CURLOPT_SSL_VERIFYHOST       => apply_filters( 'ssl_verify_host', false ),
		CURLOPT_FOLLOWLOCATION       => false,
		CURLOPT_DNS_USE_GLOBAL_CACHE => false,
		CURLOPT_RESOLVE              => array( $parsed['host'] . ':' . $port . ':' . $ip ),
		CURLOPT_HEADERFUNCTION       => function( $ch, $header ) {
			if ( preg_match( '/^Content-Length:\s*(\d+)$/i', rtrim( $header ), $matches ) ) {
				if ( $matches[1] > $GLOBALS['remote_image_max_size'] ) {
					httpdie( '400 Bad Request', 'You can only process images up to ' . $GLOBALS['remote_image_max_size'] . ' bytes.' );
				}
			}

			return strlen( $header );
		},
		CURLOPT_WRITEFUNCTION        => function( $ch, $data ) {
			$bytes = strlen( $data );
			$GLOBALS['raw_data'] .= $data;
			$GLOBALS['raw_data_size'] += $bytes;

			if ( $GLOBALS['raw_data_size'] > $GLOBALS['remote_image_max_size'] ) {
				httpdie( '400 Bad Request', 'You can only process images up to ' . $GLOBALS['remote_image_max_size'] . ' bytes.' );
			}

			return $bytes;
		},
	) );

	if ( ! curl_exec( $ch ) ) {
		do_action( 'bump_stats', 'invalid_request' );
		return false;
	}

	$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	if ( 200 == $status ) {
		return true;
	}

	// handle redirects
	if ( $status >= 300 && $status <= 399 ) {
		if ( $max_redirs > 0 ) {
			return fetch_raw_data( curl_getinfo( $ch, CURLINFO_REDIRECT_URL ), $timeout, $connect_timeout, $max_redirs - 1 );
		}
		do_action( 'bump_stats', 'max_redirects_exceeded' );
		httpdie( '400 Bad Request', 'Too many redirects' );
	}

	// handle all other errors
	switch( $status ) {
		case 401:
		case 403:
			httpdie( '403 Forbidden', 'We cannot complete this request, remote data could not be fetched' );
			break;
		case 404:
		case 410:
			httpdie( '404 File Not Found', 'We cannot complete this request, remote data could not be fetched' );
			break;
		case 429:
			httpdie( '429 Too Many Requests', 'We cannot complete this request, remote data could not be fetched' );
			break;
		case 451:
			httpdie( '451 Unavailable For Legal Reasons', 'We cannot complete this request, remote data could not be fetched' );
			break;
		default:
			do_action( 'bump_stats', 'http_error-400-' . $status );
			httpdie( '400 Bad Request', 'We cannot complete this request, remote server returned an unexpected status code (' . $status . ')' );
	}
}

function read_raw_data_from_disk( $file_path = '' ) {
	$parsed = substr( parse_url( $file_path, PHP_URL_PATH ), 1 );
	if ( ! is_readable( $parsed ) ) {
		return false;
	}
	$fh = fopen( $parsed, 'r' );
	$data = fread( $fh, filesize( $parsed ) );
	$GLOBALS['raw_data'] .= $data;
	$GLOBALS['raw_data_size'] += strlen( $data );
	fclose( $fh );
	header( 'X-Photon-Local: true' );
	return true;
}


// Kick things off!
$raw_data = '';
$raw_data_size = 0;

$url = 'scheme://host' . $_SERVER['REQUEST_URI'];

if ( ! read_raw_data_from_disk( $url ) && ! empty( EXTERNAL_FALLBACK_DOMAIN ) ) {
	$url = sprintf( '%s://%s%s',
		EXTERNAL_FALLBACK_DOMAIN_SCHEME,
		substr( parse_url( 'scheme://host' . '/' . EXTERNAL_FALLBACK_DOMAIN . '/' . $_SERVER['REQUEST_URI'], PHP_URL_PATH ), 1 ), // see https://bugs.php.net/bug.php?id=71112 (and #66813)
		isset( $_GET['q'] ) ? '?' . $_GET['q'] : ''
	);
	fetch_raw_data( $url );
	header( 'X-Photon-Remote: true' );
}

// Looks like we don't have any image data to process
if ( empty( $raw_data ) ) {
	httpdie();
}

foreach ( $disallowed_file_headers as $file_header ) {
	if ( substr( $raw_data, 0, strlen( $file_header ) ) == $file_header )
		httpdie( '400 Bad Request', 'Error 0002. The type of image you are trying to process is not allowed.' );
}

$img_proc = new Image_Processor();
if ( ! $img_proc )
	httpdie( '500 Internal Server Error', 'Error 0003. Unable to load the image.' );

$img_proc->use_client_hints    = false;
$img_proc->send_nosniff_header = true;
$img_proc->norm_color_profile  = false;
$img_proc->send_bytes_saved    = true;
$img_proc->send_etag_header    = true;
$img_proc->canonical_url       = $url;
$img_proc->image_max_age       = 63115200;
$img_proc->image_data          = $raw_data;

if ( ! $img_proc->load_image() )
	httpdie( '400 Bad Request', 'Error 0004. Unable to load the image.' );

if ( ! in_array( $img_proc->image_format, $allowed_types ) )
	httpdie( '400 Bad Request', 'Error 0005. The type of image you are trying to process is not allowed.' );

$original_mime_type = $img_proc->mime_type;
$img_proc->process_image();

// Update the stats of the processed functions
foreach ( $img_proc->processed as $function_name ) {
	do_action( 'bump_stats', $function_name );
}

switch ( $original_mime_type ) {
	case 'image/png':
		do_action( 'bump_stats', 'image_png' . ( 'image/webp' == $img_proc->mime_type ? '_as_webp' : '' ) );
		do_action( 'bump_stats', 'png_bytes_saved', $img_proc->bytes_saved );
		break;
	case 'image/gif':
		do_action( 'bump_stats', 'image_gif' );
		break;
	default:
		do_action( 'bump_stats', 'image_jpeg' . ( 'image/webp' == $img_proc->mime_type ? '_as_webp' : '' ) );
		do_action( 'bump_stats', 'jpg_bytes_saved', $img_proc->bytes_saved );
}
