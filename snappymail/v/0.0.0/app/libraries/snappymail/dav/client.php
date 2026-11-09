<?php
/**
 * Based on Sabre\DAV\Client
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */

namespace SnappyMail\DAV;

use SnappyMail\SensitiveString;

class Client
{
	public string $urlPath;

	protected ?SensitiveString $sPreemptiveAuth = null;

	const
		NS_DAV  = 'urn:DAV',
		NS_CARDDAV = 'urn:ietf:params:xml:ns:carddav';

	protected string $baseUri;

	protected $HTTP;

	/**
	 * Constructor
	 *
	 * Settings are provided through the 'settings' argument. The following
	 * settings are supported:
	 *
	 *   * baseUri
	 *   * userName (optional)
	 *   * password (optional)
	 *   * proxy (optional)
	 */
	function __construct(
		#[\SensitiveParameter]
		array $settings
	)
	{
		if (!isset($settings['baseUri'])) {
			throw new \ValueError('A baseUri must be provided');
		}
		$this->baseUri = $settings['baseUri'];

		$this->HTTP = \SnappyMail\HTTP\Request::factory(/*'socket'*/);
		$this->HTTP->proxy = $settings['proxy'] ?? null;
		if (!empty($settings['userName']) && !empty($settings['password'])) {
			$this->HTTP->setAuth(3, $settings['userName'], $settings['password']);
			/**
			 * Also send Basic up front. Allowing both Basic and Digest makes cURL
			 * probe unauthenticated first and wait for a 401 to pick a scheme, and
			 * a server that answers anything else to an anonymous request (Nextcloud
			 * behind nginx returns 404) never gets authenticated at all. Digest is
			 * still available, because a server wanting it replies 401 to this and
			 * cURL then negotiates normally.
			 */
			$this->sPreemptiveAuth = new SensitiveString('Authorization: Basic '
				. \base64_encode($settings['userName'] . ':' . $settings['password'])
			);
		} else {
			\SnappyMail\Log::warning('DAV', 'No user credentials set');
		}
		$this->HTTP->max_response_kb = 0;
		$this->HTTP->timeout = 15; // timeout in seconds.
		$this->HTTP->max_redirects = 0;
	}

	/**
	 * Enable/disable SSL peer verification
	 */
	public function setVerifyPeer(bool $value): void
	{
		$this->HTTP->verify_peer = $value;
	}

	/**
	 * Set a custom CA bundle file for SSL peer verification
	 */
	public function setCABundleFile(string $file): void
	{
		$this->HTTP->setCABundleFile($file);
	}

	/**
	 * Apply the [ssl] section, so DAV verifies certificates the same way IMAP,
	 * SMTP and Sieve already do through MailSo\Net\SSLContext. Without this a
	 * private CA or a deliberately relaxed install behaves differently for
	 * calendars and address books than for mail on the same server.
	 *
	 * Setting a bundle turns peer verification on whatever verify_certificate
	 * says, since both transports compute it as verify_peer || ca_bundle.
	 * allow_self_signed has no curl equivalent, so point cafile at the
	 * certificate itself for a self-signed server.
	 */
	public function applySslConfig(): void
	{
		$oConfig = \RainLoop\Api::Config();
		$this->setVerifyPeer(!!$oConfig->Get('ssl', 'verify_certificate', true));
		// cafile first, then capath. ca_bundle carries either and each transport
		// works out which one it was handed.
		$sBundle = \trim((string) $oConfig->Get('ssl', 'cafile', ''))
			?: \trim((string) $oConfig->Get('ssl', 'capath', ''));
		if ('' !== $sBundle) {
			$this->setCABundleFile($sBundle);
		}
	}

	/**
	 * Performs an actual HTTP request, and returns the result.
	 *
	 * If the specified url is relative, it will be expanded based on the base url.
	 */
	public function request(string $method, string $url = '', ?string $body = null, array $headers = array()): \SnappyMail\HTTP\Response
	{
		if (!\preg_match('@^(https?:)?//@', $url)) {
			// If the url starts with a slash, we must calculate the url based off
			// the root of the base url.
			if (\str_starts_with($url, '/')) {
				$parts = \parse_url($this->baseUri);
				$url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port'])?':' . $parts['port']:'') . $url;
			} else {
				$url = $this->baseUri . $url;
			}
		}
		\SnappyMail\Log::debug('DAV', "{$method} {$url}" . ($body ? "\n\t" . \str_replace("\n", "\n\t", $body) : ''));
		if (\strlen($this->sPreemptiveAuth) && !isset($headers['Authorization'])) {
			$headers['Authorization'] = $this->sPreemptiveAuth->getValue();
		}
		$response = $this->HTTP->doRequest($method, $url, $body, $headers);
		// Like: RewriteRule ^\.well-known/carddav /nextcloud/remote.php/dav [R=301,L]
		// Apache answers 302 without R=301, and Stalwart answers the well-known
		// paths with 307. All four keep the method and body, which a PROPFIND
		// needs. 303 means "GET this instead", so it is not followed.
		if (\in_array($response->status, array(301, 302, 307, 308), true)) {
			$location = $response->getRedirectLocation();
			\SnappyMail\Log::info('DAV', "{$response->status} Redirect {$url} to {$location}");
			$url = \preg_replace('@^(https?:)?//[^/]+[/$]@', '/', $location);
			$parts = \parse_url($this->baseUri);
			$url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port'])?':' . $parts['port']:'') . $url;
			$response = $this->HTTP->doRequest($method, $url, $body, $headers);
		}
		if (300 <= $response->status) {
			throw new \SnappyMail\HTTP\Exception("{$method} {$url}", $response->status, $response);
		}
		\SnappyMail\Log::debug('DAV', "{$response->status}: {$response->body}");

		return $response;
	}

	/**
	 * Does a PROPFIND request
	 *
	 * The list of requested properties must be specified as an array, in clark
	 * notation.
	 *
	 * The returned array will contain a list of filenames as keys, and
	 * properties as values.
	 *
	 * The properties array will contain the list of properties. Only properties
	 * that are actually returned from the server (without error) will be
	 * returned, anything else is discarded.
	 *
	 * Depth should be either 0 or 1. A depth of 1 will cause a request to be
	 * made to the server to also return all child resources.
	 */
	public function propFind(string $url, array $properties, int $depth = 0): array
	{
		$body = '<?xml version="1.0"?>' . "\n" . '<d:propfind xmlns:d="DAV:"><d:prop>';

		foreach ($properties as $property) {
			if (!\preg_match('/^{([^}]*)}(.*)$/', $property, $match)) {
				throw new \ValueError('\'' . $property . '\' is not a valid clark-notation formatted string');
			}
			if ('DAV:' === $match[1]) {
				$body .= "<d:{$match[2]}/>";
			} else {
				$body .= "<x:{$match[2]} xmlns:x=\"{$match[1]}\"/>";
			}
		}

		$body .= '</d:prop></d:propfind>';

		$response = $this->request('PROPFIND', $url, $body, array(
			"Depth: {$depth}",
			'Content-Type: application/xml'
		));

		$result = static::parseMultiStatus($response->body);

		if (0 === $depth) {
			\reset($result);
			return \current($result)[200] ?? array();
		}

		return \array_map(function($statusList){
			return $statusList[200] ?? array();
		}, $result);
	}

	/**
	 * Sends a REPORT and returns the href => properties map of its multistatus.
	 *
	 * calendar-multiget and sync-collection both answer this way, so the body is
	 * the caller's to build and the parsing is shared with propFind().
	 */
	public function report(string $url, string $body, int $depth = 1): array
	{
		$response = $this->request('REPORT', $url, $body, array(
			"Depth: {$depth}",
			'Content-Type: application/xml; charset=utf-8'
		));

		return \array_map(function($statusList){
			return $statusList[200] ?? array();
		}, static::parseMultiStatus($response->body));
	}

	/**
	 * Parses a WebDAV multistatus body into href => status => properties.
	 */
	protected static function parseMultiStatus(string $sBody): array
	{
		/**
		 * Parse the WebDAV multistatus response body
		 */
		$responseXML = \simplexml_load_string(
			/**
			 * Convert all instances of the DAV: namespace to urn:DAV
			 *
			 * This is unfortunately needed, because the DAV: namespace violates the xml namespaces
			 * spec, and causes the DOM to throw errors
			 *
			 * This is used to map the DAV: namespace to urn:DAV. This is needed, because the DAV:
			 * namespace is actually a violation of the XML namespaces specification, and will cause errors
			 */
			\preg_replace("/xmlns(:[A-Za-z0-9_]*)?=(\"|\')DAV:(\\2)/", "xmlns\\1=\\2urn:DAV\\2", $sBody),
			null, LIBXML_NOBLANKS | LIBXML_NOCDATA);

		if (false === $responseXML) {
			throw new \UnexpectedValueException("The passed data is not valid XML\n{$sBody}");
		}

		$ns = \array_search('urn:DAV', $responseXML->getNamespaces(true)) ?: 'd';
//		$ns_card = \array_search('urn:ietf:params:xml:ns:carddav', $responseXML->getNamespaces(true));

		$result = array();

		$responseXML->registerXPathNamespace($ns, 'urn:DAV');
		foreach ($responseXML->xpath("{$ns}:response") as $response) {
			$properties = array();
			$response->registerXPathNamespace($ns, 'urn:DAV');
			foreach ($response->xpath("{$ns}:propstat") as $propStat) {
				// Parse all WebDAV properties
				$propList = array();
				$propStat->registerXPathNamespace($ns, 'urn:DAV');
				foreach ($propStat->xpath("{$ns}:prop") as $prop) {
					foreach ($prop->xpath("*") as $element) {
						$propertyName = self::toClarkNotation($element);
						$propList[$propertyName] = [];
						if ('{DAV:}resourcetype' === $propertyName) {
							foreach ($element->xpath("*") as $resourcetype) {
								$propList[$propertyName][] = self::toClarkNotation($resourcetype);
							}
						} else if ('{DAV:}current-user-privilege-set' === $propertyName) {
							/**
							 * Each granted right is a <privilege> wrapping an empty element
							 * naming it, so the name is one level further down than usual.
							 * Reading it like an ordinary property collapses the whole set to
							 * a single empty '{DAV:}privilege' and loses every right.
							 */
							foreach ($element->xpath("*") as $privilege) {
								foreach ($privilege->xpath("*") as $granted) {
									$propList[$propertyName][] = self::toClarkNotation($granted);
								}
							}
						} else {
							foreach ($element->xpath("*") as $child) {
								$propList[$propertyName][self::toClarkNotation($child)] = (string) $child;
							}
							if (!$propList[$propertyName]) {
								$propList[$propertyName] = (string) $element;
							}
						}
					}
				}
				list($httpVersion, $statusCode, $message) = \explode(' ', $propStat->children('urn:DAV')->status, 3);
				$properties[$statusCode] = $propList;
			}

			$result[(string) $response->children('urn:DAV')->href] = $properties;
		}

		return $result;
	}

	/**
	 * Returns the 'clark notation' for an element.
	 *
	 * For example, and element encoded as:
	 * <b:myelem xmlns:b="http://www.example.org/" />
	 * will be returned as:
	 * {http://www.example.org}myelem
	 *
	 * This format is used throughout the SabreDAV sourcecode.
	 * Elements encoded with the urn:DAV namespace will
	 * be returned as if they were in the DAV: namespace. This is to avoid
	 * compatibility problems.
	 */
	public static function toClarkNotation(\SimpleXMLElement $element): string
	{
		// Mapping back to the real namespace, in case it was dav
		// Mapping to clark notation
		$ns = \array_values($element->getNamespaces())[0];
		return '{' . ('urn:DAV' == $ns ? 'DAV:' : $ns) . '}' . $element->getName();
	}
}
