#!/usr/bin/php
<?php
define('ROOT_DIR', dirname(__DIR__));
chdir(ROOT_DIR);

$options = getopt('', ['readme','source','knockout','openpgp','squire']);
$return_var = '';

function execCmd($cmd)
{
	passthru($cmd, $return_var);
	if ($return_var) {
		exit("`{$cmd}` failed with error code {$return_var}\n");
	}
}

if (isset($options['knockout'])) {
	chdir(ROOT_DIR . '/vendors/knockout');
	if ($grunt = trim(`which grunt`)) {
		execCmd($grunt, $return_var);
	} else {
		exit('grunt not installed');
	}

	$file = ROOT_DIR . '/vendors/knockout/build/output/knockout-latest.js';
	$data = file_get_contents($file);
/*
	Strip start: (()=>{'use strict';var aa="function"==typeof Object.defineProperties?Object.defineProperty:function(C,F,E){if(C==Array.prototype||C==Object.prototype)return C;C[F]=E.value;return C};function ba(C){C=["object"==typeof globalThis&&globalThis,C,"object"==typeof window&&window,"object"==typeof self&&self,"object"==typeof global&&global];for(var F=0;F<C.length;++F){var E=C[F];if(E&&E.Math==Math)return E}throw Error("Cannot find global object");}var ca=ba(this);
function Z(C,F){if(F)a:{var E=ca;C=C.split(".");for(var J=0;J<C.length-1;J++){var S=C[J];if(!(S in E))break a;E=E[S]}C=C[C.length-1];J=E[C];F=F(J);F!=J&&null!=F&&aa(E,C,{configurable:!0,writable:!0,value:F})}}Z("Object.entries",function(C){return C?C:function(F){var E=[],J;for(J in F)Object.prototype.hasOwnProperty.call(F,J)&&E.push([J,F[J]]);return E}});
Z("Array.prototype.includes",function(C){return C?C:function(F,E){var J=this;J instanceof String&&(J=String(J));var S=J.length;E=E||0;for(0>E&&(E=Math.max(E+S,0));E<S;E++){var V=J[E];if(V===F||Object.is(V,F))return!0}return!1}});
	Strip end: })();
*/

	$p1 = strpos($data, '(()=>');
	// ECMASCRIPT_202
	$data = substr($data, 0, $p1) . substr($data, $p1 + 6, -6) . "\n";
	// ECMASCRIPT_2018
//	$data = substr($data, 0, $p1) . substr($data, $p1 + 19, -6) . "\n";
	// ECMASCRIPT6
//	$data = substr($data, 0, $p1) . substr($data, strpos($data, 'return!0}return!1}});') + 22, -6) . "\n";

	file_put_contents($file, $data);
	exit;
}

if (isset($options['openpgp'])) {
	chdir(ROOT_DIR . '/vendors/openpgp-5');
	execCmd('git pull', $return_var);
	execCmd('./build.sh', $return_var);
	exit;
}

if (isset($options['squire'])) {
	chdir(ROOT_DIR . '/vendors/squire2');
//	execCmd('git pull', $return_var);
//	execCmd('git checkout snappymail', $return_var);
	execCmd('./build.sh', $return_var);
	exit;
}

if (isset($options['source'])) {
	#cd ROOT_DIR . '/vendors/openpgp-2.6.2/';
	#rollup -c
	#npm cache clean --force

	if ($gulp = trim(`which gulp`)) {
		execCmd($gulp, $return_var);
	} else {
		exit('gulp not installed, run as root: npm install --global gulp-cli');
	}

	#rollup -c

	$cmddir = escapeshellcmd(ROOT_DIR) . '/snappymail/v/0.0.0/static';

	if ($gzip = trim(`which gzip`)) {
		echo "\x1b[33;1m === Gzip *.js and *.css === \x1b[0m\n";
//		execCmd("{$gzip} -k --best {$cmddir}/js/*.js");
		execCmd("{$gzip} -k --best {$cmddir}/js/min/*.js");
		execCmd("{$gzip} -k --best {$cmddir}/css/admin*.css");
		execCmd("{$gzip} -k --best {$cmddir}/css/app*.css");
//		unlink($cmddir . '/js/boot.js.gz');
//		unlink($cmddir . '/js/min/boot.min.js.gz');
	}

	if ($brotli = trim(`which brotli`)) {
		echo "\x1b[33;1m === Brotli *.js and *.css === \x1b[0m\n";
//		execCmd("{$brotli} -k --best {$cmddir}/js/*.js");
		execCmd("{$brotli} -k --best {$cmddir}/js/min/*.js");
		execCmd("{$brotli} -k --best {$cmddir}/css/admin*.css");
		execCmd("{$brotli} -k --best {$cmddir}/css/app*.css");
//		unlink($cmddir . '/js/boot.js.br');
//		unlink($cmddir . '/js/min/boot.min.js.br');
	}
}

if (isset($options['readme'])) {

	$basedir = escapeshellcmd(ROOT_DIR) . '/snappymail/v/0.0.0';

	$readme = file_get_contents(ROOT_DIR . '/README.md');

	$total = filesize("{$basedir}/static/js/serviceworker.js");
	foreach (['admin','app','boot','libs','sieve'] as $name) {
		$size = filesize("{$basedir}/static/js/{$name}.js");
		$total += $size;
		$readme = preg_replace("/(\\|{$name}\\.js\\s+\\|[\\s0-9.]+\\|)[\\s0-9.]+\\|/Ds",
			'$1' . str_pad(number_format($size, 0, '', '.'), 9, ' ', STR_PAD_LEFT) . '	|',
			$readme
		);
	}
	$readme = preg_replace("/(\\|TOTAL\\s+\\|8[\\s0-9.]+\\|)[\\s0-9.]+\\|/Ds",
		'$1' . str_pad(number_format($total, 0, '', '.'), 9, ' ', STR_PAD_LEFT) . '	|',
		$readme
	);

	$total = [];
	$total_br = [];
	$total_gz = [];
	foreach (['admin','app','boot','libs','sieve'] as $name) {
		$size = filesize("{$basedir}/static/js/min/{$name}.min.js");
		$size_br = filesize("{$basedir}/static/js/min/{$name}.min.js.br");
		$size_gz = filesize("{$basedir}/static/js/min/{$name}.min.js.gz");
		$total[$name] = $size;
		$total_br[$name] = $size_br;
		$total_gz[$name] = $size_gz;
		$readme = preg_replace(
			"/(\\|{$name}\\.min\\.js\\s+\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+\\|/Ds",
			'$1|'
			. str_pad(number_format($size, 0, '', '.'), 9, ' ', STR_PAD_LEFT) . '	'
			. '$2|'
			. str_pad(number_format($size_gz, 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
			. '$3|'
			. str_pad(number_format($size_br, 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
			. '|'
			,
			$readme
		);
	}
	$readme = preg_replace(
		"/(TOTAL user\+sieve\\s+\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+\\|/Ds",
		'$1|'
		. str_pad(number_format(array_sum($total) - $total['admin'], 0, '', '.'), 9, ' ', STR_PAD_LEFT) . '	'
		. '$2|'
		. str_pad(number_format(array_sum($total_gz) - $total_gz['admin'], 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
		. '$3|'
		. str_pad(number_format(array_sum($total_br) - $total_br['admin'], 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
		. '|'
		,
		$readme
	);

	//$smaller = 1217635 / (array_sum($total) - $total['admin']);
	$smaller = 358761 / (array_sum($total_gz) - $total_gz['admin']);
	//$smaller = 299485 / (array_sum($total_br) - $total_br['admin']);
	$readme = preg_replace(
		'/(For a user it is around) [0-9]+/',
		'$1 ' . round(100 - 100 / $smaller),
		$readme
	);

	unset($total['sieve']);
	unset($total_br['sieve']);
	unset($total_gz['sieve']);
	$readme = preg_replace(
		"/(TOTAL user\\s+\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+\\|/Ds",
		'$1|'
		. str_pad(number_format(array_sum($total) - $total['admin'], 0, '', '.'), 9, ' ', STR_PAD_LEFT) . '	'
		. '$2|'
		. str_pad(number_format(array_sum($total_gz) - $total_gz['admin'], 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
		. '$3|'
		. str_pad(number_format(array_sum($total_br) - $total_br['admin'], 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
		. '|'
		,
		$readme
	);
	$readme = preg_replace(
		"/(TOTAL admin\\s+\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+\\|/Ds",
		'$1|'
		. str_pad(number_format(array_sum($total) - $total['app'], 0, '', '.'), 9, ' ', STR_PAD_LEFT) . '	'
		. '$2|'
		. str_pad(number_format(array_sum($total_gz) - $total_gz['app'], 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
		. '$3|'
		. str_pad(number_format(array_sum($total_br) - $total_br['app'], 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
		. '|'
		,
		$readme
	);

	foreach (['admin','app'] as $name) {
		$readme = preg_replace(
			"/(\\|{$name}\\.css\\s+\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+\\|[\\s0-9.]+\\|/Ds",
			'$1|'
			. str_pad(number_format(filesize("{$basedir}/static/css/{$name}.css"), 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
			. '$2|'
			. str_pad(number_format(filesize("{$basedir}/static/css/{$name}.css.gz"), 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	|'
			. str_pad(number_format(filesize("{$basedir}/static/css/{$name}.css.br"), 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
			. '|'
			,
			$readme
		);
		$readme = preg_replace(
			"/(\\|{$name}\\.min\\.css\\s+\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+\\|[\\s0-9.]+\\|/Ds",
			'$1|'
			. str_pad(number_format(filesize("{$basedir}/static/css/{$name}.min.css"), 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
			. '$2|'
			. str_pad(number_format(filesize("{$basedir}/static/css/{$name}.min.css.gz"), 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	|'
			. str_pad(number_format(filesize("{$basedir}/static/css/{$name}.min.css.br"), 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
			. '|'
			,
			$readme
		);
	}


	$sizes = [
		filesize("{$basedir}/static/js/min/openpgp.min.js"),
		filesize("{$basedir}/static/js/min/openpgp.min.js.gz"),
		filesize("{$basedir}/static/js/min/openpgp.min.js.br")
	];
	//|openpgp.min.js 	|  330.742	|  540.542	|102.388	| 167.397	| 84.241  	| 137.502	|
	$readme = preg_replace(
		"/(openpgp\.min\.js\\s+\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+(\\|[\\s0-9.]+)\\|[\\s0-9.]+\\|/Ds",
		'$1|'
		. str_pad(number_format($sizes[0], 0, '', '.'), 9, ' ', STR_PAD_LEFT) . '	'
		. '$2| '
		. str_pad(number_format($sizes[1], 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
		. '$3|  '
		. str_pad(number_format($sizes[2], 0, '', '.'), 7, ' ', STR_PAD_LEFT) . '	'
		. '|'
		,
		$readme
	);

	//file://

	file_put_contents(ROOT_DIR . '/README.md', $readme);

	$data = file_put_contents(
		"{$basedir}/app/templates/Views/Admin/AdminSettingsAbout.html",
		preg_replace(
			'/20[0-9][0-9] ©/',
			date('Y').' ©',
			file_get_contents("{$basedir}/app/templates/Views/Admin/AdminSettingsAbout.html")
		)
	);
}
