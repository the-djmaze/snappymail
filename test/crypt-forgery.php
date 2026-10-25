<?php

// Run with: php test/crypt-forgery.php

// Crypt logs through Tachyon\Util\Log and reads its cipher from Tachyon\Api at
// load time, both of which need the whole application. Stand-ins are declared
// before the autoloader could load the real classes; Config returns defaults.
namespace Tachyon\Util {
	abstract class Log
	{
		public static function __callStatic(string $name, array $arguments) : void {}
	}
}

namespace Tachyon {
	abstract class Api
	{
		public static function Config() : object
		{
			return new class {
				public function Get(string $section, string $name, $default = null) { return $default; }
			};
		}
	}
}

namespace {
	define('APP_SALT', 'test-salt-'.bin2hex(random_bytes(8)));
	define('APP_VERSION', 'test');
	$_COOKIE['smctoken'] = base64_encode(random_bytes(16));
	spl_autoload_register(static function (string $class): void {
		$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
		$file = str_starts_with($class, 'Tachyon\\Util\\')
			? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
			: $base.str_replace('\\', '/', $class).'.php';
		if (is_file($file)) {
			require_once $file;
		}
	});

	function check(bool $condition, string $message): void
	{
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}

	$Crypt = 'Tachyon\\Util\\Crypt';

	check(is_callable('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt'), 'test needs sodium');

	// Forge a token without knowing APP_SALT: the attacker picks the 16 byte
	// salt, which under the old derivation was the whole XXTEA key.
	$salt = random_bytes(16);
	$forged = \MailSo\Base\Xxtea::encrypt(json_encode(['e' => 'victim@example.com', 'admin' => true]), $salt);
	foreach (['xxtea', 'XXTEA', 'Xxtea'] as $algo) {
		check(null === $Crypt::Decrypt([$algo, $salt, $forged], 'server-key'), "forged {$algo} token accepted");
	}

	// Unknown algorithm names are refused rather than dispatched.
	check(null === $Crypt::Decrypt(['jsonDecode', $salt, $forged], 'server-key'), 'arbitrary method name dispatched');

	// Normal tokens still round-trip, and only with the right key.
	$data = ['e' => 'user@example.com', 'n' => 42];
	check($data === $Crypt::Decrypt($Crypt::Encrypt($data, 'server-key'), 'server-key'), 'sodium round trip broke');
	check($data === $Crypt::DecryptUrlSafe($Crypt::EncryptUrlSafe($data, 'server-key'), 'server-key'), 'url-safe round trip broke');
	check(null === $Crypt::Decrypt($Crypt::Encrypt($data, 'server-key'), 'other-key'), 'decrypted with the wrong key');

	// The xxtea primitives themselves now depend on the server secret.
	$salt = random_bytes(16);
	$cipher = $Crypt::XxteaEncrypt('{"a":1}', $salt, 'server-key');
	check('{"a":1}' === $Crypt::XxteaDecrypt($cipher, $salt, 'server-key'), 'xxtea round trip broke');
	check('{"a":1}' !== $Crypt::XxteaDecrypt($cipher, $salt, 'other-key'), 'xxtea key does not depend on the secret');
	check('{"a":1}' !== \MailSo\Base\Xxtea::decrypt($cipher, $salt), 'xxtea still keyed by the salt alone');

	echo "crypt-forgery: ok\n";
}
