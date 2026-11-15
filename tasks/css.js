/* RainLoop Webmail (c) RainLoop Team | Licensed under MIT */
import gulp from 'gulp';

import concat from 'gulp-concat';
import rename from 'gulp-rename';
import replace from 'gulp-replace';
import eol from 'gulp-eol';
import filter from 'gulp-filter';
import expect from 'gulp-expect-file';
import gcmq from 'gulp-group-css-media-queries';
import less from 'gulp-less';

import { config } from './config.js';
import { remove } from './common.js';

import cleanCss from 'gulp-clean-css';
const cssClean = () => remove(config.paths.staticCSS + '/*.css');

const cssBootBuild = () => {
	const
		src = config.paths.css.boot.src;
	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(concat(config.paths.css.boot.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssMainBuild = () => {
	const
		lessFilter = filter('**/*.less', { restore: true }),
		src = config.paths.css.main.src.concat([config.paths.less.main.src]);

	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(lessFilter)
		.pipe(
			less({
				'paths': config.paths.less.main.options.paths
			})
		)
		.pipe(lessFilter.restore)
		.pipe(concat(config.paths.css.main.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssAdminBuild = () => {
	const
		lessFilter = filter('**/*.less', { restore: true }),
		src = config.paths.css.admin.src;

	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(lessFilter)
		.pipe(
			less({
				'paths': config.paths.less.main.options.paths
			})
		)
		.pipe(lessFilter.restore)
		.pipe(concat(config.paths.css.admin.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssBootMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.boot.name)
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssMainMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.main.name)
		.pipe(gcmq())
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssAdminMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.admin.name)
		.pipe(gcmq())
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

export const cssBuild = gulp.parallel(cssBootBuild, cssMainBuild, cssAdminBuild);
const cssMin = gulp.parallel(cssBootMin, cssMainMin, cssAdminMin);

export const cssLint = (done) => done();

const cssState1 = gulp.series(cssLint);
const cssState2 = gulp.series(cssClean, cssBuild, cssMin);

export const css = gulp.parallel(cssState1, cssState2);
