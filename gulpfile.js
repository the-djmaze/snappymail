/* RainLoop Webmail (c) RainLoop Team | Licensed under MIT */
import gulp from 'gulp';

import { cleanStatic } from './tasks/common.js';
import { js, jsLint } from './tasks/js.js';
import { css, cssLint } from './tasks/css.js';
import { vendors } from './tasks/vendors.js';
import { sri } from './tasks/sri.js';

export const clean = gulp.series(cleanStatic);

export const lint = gulp.parallel(jsLint, cssLint);

export const buildState1 = gulp.parallel(js, css, vendors);
export const buildState2 = gulp.series(clean, buildState1, sri);

export const build = gulp.parallel(lint, buildState2);

export default build;
