/* SnappyMail Webmail (c) SnappyMail Team | Licensed under AGPL v3 */
import rollup2 from 'gulp-rollup-2';
import includePaths from 'rollup-plugin-includepaths';
import externalGlobals from 'rollup-plugin-external-globals';

import { config } from './config.js';

let includePathOptions = {
	include: {},
	paths: ['dev'],
	external: [],
	extensions: ['.js']
};

export const rollupJS = (inputFile) =>
	rollup2.src({
		external: ['ko'],
		input: 'dev/' + inputFile,
		output: [
			{file: config.paths.staticJS + inputFile, format: 'iife'}
		],
		plugins: [
			includePaths(includePathOptions),
			externalGlobals({
				ko: 'ko'
			})
		]
	});
