/* RainLoop Webmail (c) RainLoop Team | Licensed under MIT */
import gulp from 'gulp';
import concat from 'gulp-concat';
import gap from 'gulp-append-prepend';
import rename from 'gulp-rename';
import replace from 'gulp-replace';
import terser from 'gulp-terser';
import eol from 'gulp-eol';
import eslint from 'gulp-eslint-new';
import cache from 'gulp-cached';
import expect from 'gulp-expect-file';
import size from 'gulp-size';

import { config } from './config.js';
import { remove } from './common.js';
import { rollupJS } from './rollup.js';

const jsClean = () => remove(config.paths.staticJS + '/**/*.{js,map}');

const prepend = () => gap.prependText(config.head.agpl + '\n');

// boot
const jsBoot = () => {
	return gulp
		.src('dev/boot.js')
		.pipe(gulp.dest(config.paths.staticJS));
};

// ServiceWorker
const jsServiceWorker = () => {
	return gulp
		.src('dev/serviceworker.js')
		.pipe(gulp.dest(config.paths.staticJS));
};

// OpenPGP
const jsOpenPGP = () => {
	return gulp
		.src('vendors/openpgp-5/dist/openpgp.js')
		.pipe(gulp.dest(config.paths.staticJS));
};

// libs
const jsLibs = () => {
	const src = config.paths.js.libs.src;
	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(concat(config.paths.js.libs.name, { separator: '\n\n' }))
		.pipe(eol('\n', true))
		.pipe(replace(/sourceMappingURL=[a-z0-9.\-_]{1,20}\.map/gi, ''))
		.pipe(gulp.dest(config.paths.staticJS));
};

// sieve
const jsSieve = async () =>
	(await rollupJS(config.paths.js.sieve.name))
//		.pipe(sourcemaps.write('.'))
		.pipe(prepend())
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticJS));

// app
const jsApp = async () =>
	(await rollupJS(config.paths.js.app.name))
//		.pipe(sourcemaps.write('.'))
		.pipe(prepend())
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticJS));

const jsAdmin = async () =>
	(await rollupJS(config.paths.js.admin.name))
//		.pipe(sourcemaps.write('.'))
		.pipe(prepend())
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticJS));

const jsMin = () =>
	gulp
		.src(config.paths.staticJS + '*.js')
		.pipe(
			size({
				showFiles: true,
				showTotal: false
			})
		)
		.pipe(replace(/"snappymail\/v\/([^/]+)\/static\/js\/"/g, '"snappymail/v/$1/static/js/min/"'))
		.pipe(rename({ suffix: '.min' }))
		.pipe(
			terser({
				output: {
					comments: false
				},
				keep_classnames: true, // Required for AbstractModel and AbstractCollectionModel
				compress:{
					ecma: 6,
					drop_console: true
/*
					,hoist_props: false
					,keep_fargs: false
					,toplevel: true
					,unsafe_arrows: true // Issue with knockoutjs
					,unsafe_methods: true
					,unsafe_proto: true
*/
				}
//				,mangle: {reserved:['SendMessage']}
			})
		)
		.pipe(eol('\n', true))
		.pipe(
			size({
				showFiles: true,
				showTotal: false
			})
		)
		.pipe(gulp.dest(config.paths.staticMinJS));

export const jsLint = () =>
	gulp
		.src(config.paths.globjs)
		.pipe(cache('eslint'))
		.pipe(eslint())
		.pipe(eslint.format())
		.pipe(eslint.failAfterError());

export const js = gulp.series(
	jsClean,
	jsLint,
	gulp.parallel(jsBoot, jsServiceWorker, jsOpenPGP, jsLibs, jsSieve, jsApp, jsAdmin),
	jsMin
);
