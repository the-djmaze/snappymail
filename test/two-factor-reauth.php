<?php

// Run with: php test/two-factor-reauth.php

// Enable SnappyMail Api and include index file
$_ENV['SNAPPYMAIL_INCLUDE_AS_API'] = true;
require __DIR__ . '/../index.php';

require dirname(__DIR__).'/plugins/two-factor-auth/index.php';
require_once dirname(__DIR__).'/plugins/two-factor-auth/providers/interface.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

// Storage, provider and logger replaced; the gate itself is the real code.
class TestTwoFactor extends TwoFactorAuthPlugin
{
	public array $info = [];
	public array $spent = [];
	protected function getTwoFactorInfo(\RainLoop\Model\MainAccount $oAccount, bool $bRemoveSecret = false) : array { return $this->info; }
	protected function TwoFactorAuthProvider(\RainLoop\Model\MainAccount $oAccount) : ?\TwoFactorAuthInterface
	{
		return new class implements \TwoFactorAuthInterface {
			public function Label() : string { return 'test'; }
			public function VerifyCode(string $sSecret, string $sCode) : bool { return 'SECRET' === $sSecret && '123456' === $sCode; }
			public function CreateSecret() : string { return 'SECRET'; }
		};
	}
	protected function Logger() : \MailSo\Log\Logger { return new \MailSo\Log\Logger(false); }
	protected function removeBackupCodeFromTwoFactorInfo(\RainLoop\Model\MainAccount $oAccount, string $sCode) : bool { $this->spent[] = $sCode; return true; }
}

$plugin = (new ReflectionClass(TestTwoFactor::class))->newInstanceWithoutConstructor();
$actions = (new ReflectionClass(\RainLoop\Actions::class))->newInstanceWithoutConstructor();
$manager = (new ReflectionClass(\RainLoop\Plugins\Manager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\RainLoop\Plugins\Manager::class, 'oActions'))->setValue($manager, $actions);
(new ReflectionProperty(\RainLoop\Plugins\AbstractPlugin::class, 'oPluginManager'))->setValue($plugin, $manager);
$account = (new ReflectionClass(\RainLoop\Model\MainAccount::class))->newInstanceWithoutConstructor();
$gate = (new ReflectionMethod(TwoFactorAuthPlugin::class, 'requireCurrentCode'));

$params = new ReflectionProperty(\RainLoop\Actions::class, 'aCurrentActionParams');
$allowed = function (array $values) use ($plugin, $actions, $account, $gate, $params) : bool {
	$params->setValue($actions, $values);
	try {
		$gate->invoke($plugin, $account);
		return true;
	} catch (\RainLoop\Exceptions\ClientException $e) {
		return false;
	}
};

// Not set up, or set up but not enabled: nothing to protect yet.
$plugin->info = ['IsSet' => false, 'Enable' => false, 'Secret' => ''];
check($allowed([]), 'blocked although 2FA is not set up');
$plugin->info = ['IsSet' => true, 'Enable' => false, 'Secret' => 'SECRET', 'BackupCodes' => '111111111'];
check($allowed([]), 'blocked although 2FA is not enabled');

// Enabled: a valid TOTP or backup code is required.
$plugin->info = ['IsSet' => true, 'Enable' => true, 'Secret' => 'SECRET', 'BackupCodes' => '111111111 222222222'];
check(!$allowed([]), 'change allowed with no code while enabled');
check(!$allowed(['Code' => '000000']), 'change allowed with a wrong code');
check(!$allowed(['Code' => '33333333']), 'change allowed with an unknown backup code');
check($allowed(['Code' => '123456']), 'valid TOTP code refused');
check($allowed(['Code' => '222222222']), 'valid backup code refused');
check(['222222222'] === $plugin->spent, 'used backup code was not spent');

// Backup codes come from random_int now, not rand().
$source = file_get_contents(dirname(__DIR__).'/plugins/two-factor-auth/index.php');
check(!preg_match('/\\\\rand\(/', $source) && str_contains($source, '\\random_int('), 'backup codes not from random_int()');

echo "two-factor-reauth: ok\n";
