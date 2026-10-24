<?php

// Run with: php test/smime-verify.php
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

$dir = sys_get_temp_dir().'/smime-verify-test-'.getmypid();
mkdir($dir);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'signer@example.com'], $key), null, $key, 1);
openssl_x509_export_to_file($cert, "{$dir}/cert.pem");
openssl_pkey_export_to_file($key, "{$dir}/key.pem");

$content = "Content-Type: text/plain\r\n\r\nPay 100 EUR to account A.\r\n";
file_put_contents("{$dir}/in.txt", $content);

$smime = new \Tachyon\Util\SMime\OpenSSL($dir);

// Detached (multipart/signed) and opaque (application/pkcs7-mime) signatures.
foreach (['detached' => PKCS7_DETACHED, 'opaque' => 0] as $kind => $flags) {
	check(openssl_pkcs7_sign("{$dir}/in.txt", "{$dir}/signed.eml", "file://{$dir}/cert.pem", "file://{$dir}/key.pem", [], $flags),
		"{$kind}: signing failed: ".openssl_error_string());
	$signed = file_get_contents("{$dir}/signed.eml");
	$opaque = 'opaque' === $kind;

	$result = $smime->verify($signed, null, $opaque);
	check(true === $result['success'], "{$kind}: intact message not reported as validly signed");
	if ($opaque) {
		check(str_contains($result['body'], 'Pay 100 EUR to account A.'), "{$kind}: content not extracted");
	}

	// Alter the signed content. Detached: the clear text part. Opaque: a byte of
	// the base64 payload, chosen inside the encapsulated content.
	if ($opaque) {
		[$head, $b64] = explode("\n\n", str_replace("\r\n", "\n", $signed), 2);
		$der = base64_decode($b64);
		$pos = strpos($der, 'account A');
		check(false !== $pos, "{$kind}: test could not find the content to alter");
		$der[$pos + 8] = 'B';
		$tampered = $head."\n\n".chunk_split(base64_encode($der), 64, "\n");
	} else {
		$tampered = str_replace('account A', 'account B', $signed);
	}
	check($tampered !== $signed, "{$kind}: test failed to alter the message");

	$result = $smime->verify($tampered, null, $opaque);
	check(false === $result['success'], "{$kind}: ALTERED message reported as validly signed");
	if ($opaque) {
		check(str_contains($result['body'], 'account B'), "{$kind}: altered content no longer readable");
	}
}

array_map('unlink', glob("{$dir}/*"));
rmdir($dir);
echo "smime-verify: ok\n";
