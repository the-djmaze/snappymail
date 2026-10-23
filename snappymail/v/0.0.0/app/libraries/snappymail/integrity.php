<?php

namespace SnappyMail;

abstract class Integrity
{

	/**
	 * Called by https://webmail.tld/?/Test
	 */
	public static function test()
	{
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
			echo "<h2>{$file}</h2>";
			$response = $HTTP->doRequest('HEAD', $uri . $file);
//			$response_enc = $HTTP->doRequest('GET', $uri . $file, null, ['Accept-Encoding: gzip, deflate, br']);
			$response_gz = $HTTP->doRequest('GET', $uri . $file, null, ['Accept-Encoding: gzip']);
			$response_br = $HTTP->doRequest('GET', $uri . $file, null, ['Accept-Encoding: br']);
			echo '<table><thead>
				<tr><th>type</th><th>Status</th><th>Encoding</th><th>Type</th><th>Length</th></tr>
			</thead><tbody>
				<tr><td></td><td>' . $response->status . '</td>
					<td>' . $response->getHeader('content-encoding') . '</td>
					<td>' . $response->getHeader('content-type') . '</td>
					<td>' . ($response->getHeader('content-length') ?: \strlen($response->body)) . ' ('.\filesize(APP_VERSION_ROOT_PATH.$file).')</td>
				</tr>
				<tr><td>gzip</td>
					<td>' . $response_gz->status . '</td>
					<td>' . $response_gz->getHeader('content-encoding') . '</td>
					<td>' . $response_gz->getHeader('content-type') . '</td>
					<td>' . ($response_gz->getHeader('content-length') ?: \strlen($response_gz->body)) . ' ('.\filesize(APP_VERSION_ROOT_PATH.$file . '.gz').')</td>
				</tr>
				<tr><td>brotli</td><td>' . $response_br->status . '</td>
					<td>' . $response_br->getHeader('content-encoding') . '</td>
					<td>' . $response_br->getHeader('content-type') . '</td>
					<td>' . ($response_br->getHeader('content-length') ?: \strlen($response_br->body)) . ' ('.\filesize(APP_VERSION_ROOT_PATH.$file . '.br').')</td>
				</tr>
			</tbody></table>';
		}
	}

	public static function phpVersion()
	{
		if (PHP_VERSION_ID < 70400) {
			return 'Your PHP version ('.PHP_VERSION.') is lower than the minimal required 7.4.0!';
		}
	}

	public static function phpExtensions()
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

}
