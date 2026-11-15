/* RainLoop Webmail (c) RainLoop Team | Licensed under MIT */
import gulp from 'gulp';

import { config } from './config.js';
import { remove } from './common.js';

// fontastic
const fontasticFontsClear = () => remove('snappymail/v/' + config.devVersion + '/static/css/fonts/snappymail.*');

const fontasticFontsCopy = () =>
	gulp
		.src('vendors/fontastic/fonts/snappymail.*', { encoding: false })
		.pipe(gulp.dest('snappymail/v/' + config.devVersion + '/static/css/fonts'));

const fontastic = gulp.series(fontasticFontsClear, fontasticFontsCopy);

export const vendors = gulp.parallel(fontastic);
