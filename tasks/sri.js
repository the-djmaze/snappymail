/* Tachyon Webmail (c) Tachyon | Licensed under AGPL v3 */
const fs = require('fs');
const crypto = require('crypto');
const path = require('path');

const { config } = require('./config');

function sriHash(filePath) {
	const data = fs.readFileSync(filePath);
	const hash = crypto.createHash('sha384').update(data).digest('base64');
	return 'sha384-' + hash;
}

function sri(done) {
	const jsMinDir = config.paths.staticMinJS;
	const cssDir = config.paths.staticCSS;

	const files = [
		['libs.min.js',  jsMinDir + 'libs.min.js'],
		['app.min.js',   jsMinDir + 'app.min.js'],
		['admin.min.js', jsMinDir + 'admin.min.js'],
		['app.min.css',  cssDir + 'app.min.css'],
		['admin.min.css',cssDir + 'admin.min.css'],
	];

	const hashes = {};
	for (const [key, filePath] of files) {
		if (fs.existsSync(filePath)) {
			hashes[key] = sriHash(filePath);
		}
	}

	const outPath = path.join('snappymail/v/' + config.devVersion, 'static/sri.json');
	fs.writeFileSync(outPath, JSON.stringify(hashes, null, '\t') + '\n');
	console.log('[sri] wrote ' + outPath);
	done();
}

exports.sri = sri;
