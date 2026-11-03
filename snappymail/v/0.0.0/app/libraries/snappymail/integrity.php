<?php

namespace SnappyMail;

abstract class Integrity
{

	/**
	 * Called by https://webmail.tld/?/Test
	 */
	public static function test(): void
	{
		// The integrity test sends HEAD requests to a URL built from the request's
		// Host header and prints back every response header. Open to anyone, that
		// lets a client choose the target (nginx ignores the port when matching
		// server_name, so Host: <this host>:<port> reaches it) and read the result.
		// It is an admin diagnostic; an admin's own browser sends the real Host.
		if (!\RainLoop\Api::Actions()->IsAdminLoggined(false)) {
			\MailSo\Base\Http::StatusHeader(404);
			return '';
		}

		$result = static::phpVersion();
		if ($result) {
			echo '<p style="color: red">' . $result . '</p>';
			return;
		}

		$result = static::phpExtensions();
		if ($result) {
			echo '<p>The following PHP extensions are not available in your PHP configuration!</p>';
			echo '<ul><li>' . \implode('</li>li><li>', $result) . '</li></ul>';
		}

/*
		echo '<div>'.APP_VERSION_ROOT_PATH.'static directory permissions: ' . substr(sprintf('%o', fileperms(APP_VERSION_ROOT_PATH . 'static')), -4) . '</div>';
		echo '<div>'.APP_VERSION_ROOT_PATH.'themes directory permissions: ' . substr(sprintf('%o', fileperms(APP_VERSION_ROOT_PATH . 'themes')), -4) . '</div>';
*/

		$uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
			. \RainLoop\Utils::WebVersionPath();
		$HTTP = \SnappyMail\HTTP\Request::factory();
		$files = [
//			'static/css/app.css',
//			'static/js/libs.js',
//			'static/js/app.js',
//			'static/js/openpgp.js',
			'static/css/app.min.css',
			'static/js/min/libs.min.js',
			'static/js/min/app.min.js',
			'static/js/min/openpgp.min.js'
		];
		foreach ($files as $file) {
			echo "<h2>{$uri}{$file}</h2>";
			$response = $HTTP->doRequest('HEAD', $uri . $file);
			echo '<details><summary>Status: ' . $response->status . '</summary><pre>' . \print_r($response->headers, 1) . '</pre></details>';
			$size = \filesize(APP_VERSION_ROOT_PATH.$file);
			if ($size == intval($response->getHeader('content-length'))) {
				echo '<div>content-length matches size ' . $size . '</div>';
			} else {
				echo '<div style="color: red">content-length mismatch, should be: ' . $size . '</div>';
			}
/*
			echo "<h3>encoding</h3>";
			$response = $HTTP->doRequest('GET', $uri . $file, null, ['Accept-Encoding' => 'gzip, deflate, br']);
			echo '<details><summary>Status: ' . $response->status . '</summary><pre>' . \print_r($response->headers, 1) . '</pre></details>';
*/
			echo "<h3>gzip encoded</h3>";
			$response = $HTTP->doRequest('HEAD', $uri . $file . '.gz');
//			$response = $HTTP->doRequest('HEAD', $uri . $file, null, ['Accept-Encoding: gzip']);
			echo '<details><summary>Status: ' . $response->status . '</summary><pre>' . \print_r($response->headers, 1) . '</pre></details>';
			$size = \filesize(APP_VERSION_ROOT_PATH.$file . '.gz');
			if ($size == intval($response->getHeader('content-length'))) {
				echo '<div>content-length matches size ' . $size . '</div>';
			} else {
				echo '<div style="color: red">content-length mismatch, should be: ' . $size . '</div>';
			}
			if ('gzip' == $response->getHeader('content-encoding')) {
				echo '<div>content-encoding matches</div>';
			} else {
				echo '<div style="color: red">content-encoding mismatch, should be: gzip</div>';
			}

			echo "<h3>brotli encoded</h3>";
			$response = $HTTP->doRequest('HEAD', $uri . $file, null, ['Accept-Encoding: br']);
//			$response = $HTTP->doRequest('HEAD', $uri . $file . '.br');
			echo '<details><summary>Status: ' . $response->status . '</summary><pre>' . \print_r($response->headers, 1) . '</pre></details>';
			$size = \filesize(APP_VERSION_ROOT_PATH.$file . '.br');
			if ($size == intval($response->getHeader('content-length'))) {
				echo '<div>content-length matches size ' . $size . '</div>';
			} else {
				echo '<div style="color: red">content-length mismatch, should be: ' . $size . '</div>';
			}
			if ('br' == $response->getHeader('content-encoding')) {
				echo '<div>content-encoding matches</div>';
			} else {
				echo '<div style="color: red">content-encoding mismatch, should be: br</div>';
			}
		}
	}

	public static function phpVersion(): ?string
	{
		if (PHP_VERSION_ID < 80200) {
			return 'Your PHP version ('.PHP_VERSION.') is lower than the minimal required 8.2.0!';
		}
		return null;
	}

	public static function phpExtensions(): array
	{
		$aRequirements = array(
			'openssl'  => extension_loaded('openssl'),
			'mbstring' => extension_loaded('mbstring'),
			'Zlib'     => extension_loaded('zlib'),
			// enabled by default:
			'json'     => function_exists('json_decode'),
			'libxml'   => function_exists('libxml_use_internal_errors'),
			'dom'      => class_exists('DOMDocument'),
			// https://github.com/the-djmaze/snappymail/issues/1392
			'fileinfo' => extension_loaded('fileinfo')
			// https://github.com/the-djmaze/snappymail/issues/392
		//	'phar'     => class_exists('PharData')
		);

		$aMissing = [];
		if (in_array(false, $aRequirements)) {
			foreach ($aRequirements as $sKey => $bValue) {
				if (!$bValue) {
					$aMissing[] = $sKey;
				}
			}
		}
		return $aMissing;
	}

	// https://developer.mozilla.org/en-US/docs/Web/Security/Defenses/Subresource_Integrity
	private static ?array $aSri = null;
	public static function loadSriHashes(): array
	{
		if (!\is_array(static::$aSri)) {
			$sSriPath = APP_VERSION_ROOT_PATH . 'static/sri.json';
			static::$aSri = \is_readable($sSriPath) ? \json_decode(\file_get_contents($sSriPath), true) : [];
			if (!static::$aSri) {
				static::$aSri = [
					'libs.min.js'   => \hash_file('sha384', APP_VERSION_ROOT_PATH . 'static/js/min/libs.min.js'),
					'admin.min.js'  => \hash_file('sha384', APP_VERSION_ROOT_PATH . 'static/js/min/admin.min.js'),
					'app.min.js'    => \hash_file('sha384', APP_VERSION_ROOT_PATH . 'static/js/min/app.min.js'),
					'admin.min.css' => \hash_file('sha384', APP_VERSION_ROOT_PATH . 'static/css/admin.min.css'),
					'app.min.css'   => \hash_file('sha384', APP_VERSION_ROOT_PATH . 'static/css/app.min.css'),
				];
			}
		}
		return static::$aSri;
	}
}
