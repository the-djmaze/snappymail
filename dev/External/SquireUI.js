/* eslint max-len: 0 */

(doc => {

const
	removeElements = 'HEAD,LINK,META,NOSCRIPT,SCRIPT,TEMPLATE,TITLE',
	allowedElements = 'A,B,BLOCKQUOTE,BR,DIV,FONT,H1,H2,H3,H4,H5,H6,HR,IMG,LI,OL,P,SPAN,STRONG,TABLE,TD,TH,TR,U,UL',
	allowedAttributes = 'abbr,align,background,bgcolor,border,cellpadding,cellspacing,class,color,colspan,dir,face,frame,height,href,hspace,id,lang,rowspan,rules,scope,size,src,style,target,type,usemap,valign,vspace,width'.split(','),

	i18n = (str, def) => rl.i18n(str) || def,

	ctrlKey = shortcuts.getMetaKey() + ' + ',

	createElement = name => doc.createElement(name),

	tpl = createElement('template'),

	trimLines = html => html.trim().replace(/^(<div>\s*<br\s*\/?>\s*<\/div>)+/, '').trim(),
	htmlToPlain = html => rl.Utils.htmlToPlain(html).trim(),
	plainToHtml = text => rl.Utils.plainToHtml(text),

	forEachObjectValue = (obj, fn) => Object.values(obj).forEach(fn),

	getFragmentOfChildren = parent => {
		let frag = doc.createDocumentFragment();
		frag.append(...parent.childNodes);
		return frag;
	},

	SquireDefaultConfig = {
/*
		addLinks: true // allow_smart_html_links
*/
		sanitizeToDOMFragment: (html, isPaste/*, squire*/) => {
			tpl.innerHTML = (html||'')
				.replace(/<\/?(BODY|HTML)[^>]*>/gi,'')
				.replace(/<!--[^>]+-->/g,'')
				.replace(/<span[^>]*>\s*<\/span>/gi,'')
				.trim();
			tpl.querySelectorAll('a:empty,span:empty').forEach(el => el.remove());
			if (isPaste) {
				tpl.querySelectorAll(removeElements).forEach(el => el.remove());
				tpl.querySelectorAll('*').forEach(el => {
					if (!el.matches(allowedElements)) {
						el.replaceWith(getFragmentOfChildren(el));
					} else if (el.hasAttributes()) {
						[...el.attributes].forEach(attr => {
							let name = attr.name.toLowerCase();
							if (!allowedAttributes.includes(name)) {
								el.removeAttribute(name);
							}
						});
					}
				});
			}
			return tpl.content;
		}
	};

class SquireUI
{
	constructor(container) {
		const
			clr = createElement('input'),
			doClr = name => input => {
				// https://github.com/the-djmaze/snappymail/issues/826
				clr.style.left = (input.offsetLeft + input.parentNode.offsetLeft) + 'px';
				clr.style.width = input.offsetWidth + 'px';

				clr.value = '';
				clr.onchange = () => squire.setStyle({[name]:clr.value});
				// Chrome 110+ https://github.com/the-djmaze/snappymail/issues/1199
//				clr.oninput = () => squire.setStyle({[name]:clr.value});
				setTimeout(()=>clr.click(),1);
			},

			actions = {
				mode: {
					plain: {
//						html: '〈〉',
//						cmd: () => this.setMode('plain' == this.mode ? 'wysiwyg' : 'plain'),
						select: [
							[i18n('SETTINGS_GENERAL/EDITOR_HTML'),'wysiwyg'],
							[i18n('SETTINGS_GENERAL/EDITOR_PLAIN'),'plain']
						],
						cmd: s => this.setMode('plain' == s.value ? 'plain' : 'wysiwyg')
					}
				},
				font: {
					fontFamily: {
						select: {
							'sans-serif': {
								Arial: "'Nimbus Sans L', 'Liberation sans', 'Arial Unicode MS', Arial, Helvetica, Garuda, Utkal, FreeSans, sans-serif",
								Tahoma: "'Luxi Sans', Tahoma, Loma, Geneva, Meera, sans-serif",
								Trebuchet: "'DejaVu Sans Condensed', Trebuchet, 'Trebuchet MS', sans-serif",
								Lucida: "'Lucida Sans Unicode', 'Lucida Sans', 'DejaVu Sans', 'Bitstream Vera Sans', 'DejaVu LGC Sans', sans-serif",
								Verdana: "'DejaVu Sans', Verdana, Geneva, 'Bitstream Vera Sans', 'DejaVu LGC Sans', sans-serif"
							},
							monospace: {
								Courier: "'Liberation Mono', 'Courier New', FreeMono, Courier, monospace",
								Lucida: "'DejaVu Sans Mono', 'DejaVu LGC Sans Mono', 'Bitstream Vera Sans Mono', 'Lucida Console', Monaco, monospace"
							},
							sans: {
								Times: "'Nimbus Roman No9 L', 'Times New Roman', Times, FreeSerif, serif",
								Palatino: "'Bitstream Charter', 'Palatino Linotype', Palatino, Palladio, 'URW Palladio L', 'Book Antiqua', Times, serif",
								Georgia: "'URW Palladio L', Georgia, Times, serif"
							}
						},
						cmd: s => squire.setStyle({ fontFamily: s.value })
					},
					fontSize: {
						select: ['11px','13px','16px','20px','24px','30px'],
						defaultValueIndex: 2,
						cmd: s => squire.setStyle({ fontSize: s.value })
						// TODO: maybe consider using https://developer.mozilla.org/en-US/docs/Web/CSS/font-size#values
						// example:
						// select: ['xx-small', 'x-small',' small',' medium', 'large', 'x-large', 'xx-large', 'xxx-large'],
						// defaultValueIndex: 3,
					},
// 					dir: {
// 						select: [
// 							[i18n('EDITOR/DIR_LTR', 'LTR'),'ltr'],
// 							[i18n('EDITOR/DIR_RTL', 'RTL'),'rtl'],
// 							[i18n('EDITOR/DIR_AUTO', 'Auto'),'auto'],
// 							['',''],
// 						],
// 						cmd: s => {
// 							squire.setAttribute('dir', s.value || null);
// //							squire.setStyle({ 'unicode-bidi': 'plaintext' });
// 						}
// 					}
				},
				colors: {
					textColor: {
						html: 'A<sub>▾</sub>',
						cmd: doClr('color')
					},
					backgroundColor: {
						html: '🎨', /* ▧ */
						cmd: doClr('backgroundColor')
					},
				},
				inline: {
					bold: {
						html: 'B',
						cmd: () => this.doAction('bold'),
						key: 'B',
						matches: 'B,STRONG'
					},
					italic: {
						html: 'I',
						cmd: () => this.doAction('italic'),
						key: 'I',
						matches: 'I'
					},
					underline: {
						html: '<u>U</u>',
						cmd: () => this.doAction('underline'),
						key: 'U',
						matches: 'U'
					},
					strike: {
						html: '<s>S</s>',
						cmd: () => this.doAction('strikethrough'),
						key: 'Shift + 7',
						matches: 'S'
					},
					sub: {
						html: 'Xₙ',
						cmd: () => this.doAction('subscript'),
						key: 'Shift + 5',
						matches: 'SUB'
					},
					sup: {
						html: 'Xⁿ',
						cmd: () => this.doAction('superscript'),
						key: 'Shift + 6',
						matches: 'SUP'
					}
				},
				block: {
					quote: {
						html: '"',
						cmd: () => this.squire.hasFormat('blockquote') ?  this.doAction('decreaseQuoteLevel') : this.doAction('increaseQuoteLevel'),
						matches: 'BLOCKQUOTE'
					},
					indentDecrease: {
						html: `<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 128.000000 128.000000" style="shape-rendering:geometricPrecision; text-rendering:geometricPrecision; image-rendering:optimizeQuality; fill-rule:evenodd; clip-rule:evenodd" xmlns:xlink="http://www.w3.org/1999/xlink">
								<g><path style="opacity:0.991"  d="M 1.5,-0.5 C 42.8333,-0.5 84.1667,-0.5 125.5,-0.5C 125.833,0.5 126.5,1.16667 127.5,1.5C 127.5,3.5 127.5,5.5 127.5,7.5C 126.711,7.78281 126.044,8.28281 125.5,9C 84.1667,9.66667 42.8333,9.66667 1.5,9C 0.955796,8.28281 0.28913,7.78281 -0.5,7.5C -0.5,5.5 -0.5,3.5 -0.5,1.5C 0.5,1.16667 1.16667,0.5 1.5,-0.5 Z"/></g>
								<g><path style="opacity:0.899"  d="M 127.5,30.5 C 127.5,32.8333 127.5,35.1667 127.5,37.5C 126.527,37.9867 125.527,38.4867 124.5,39C 99.8333,39.6667 75.1667,39.6667 50.5,39C 48.0904,38.2421 47.0904,36.5754 47.5,34C 47.0904,31.4246 48.0904,29.7579 50.5,29C 75.1667,28.3333 99.8333,28.3333 124.5,29C 125.527,29.5133 126.527,30.0133 127.5,30.5 Z"/></g>
								<g><path style="opacity:0.882"  d="M -0.5,68.5 C -0.5,64.8333 -0.5,61.1667 -0.5,57.5C 5.56592,49.9283 12.2326,42.7616 19.5,36C 26.8636,33.2717 29.6969,35.7717 28,43.5C 21.3761,49.4483 15.5427,55.9483 10.5,63C 15.2081,69.7175 20.7081,75.8842 27,81.5C 30.3922,89.602 27.8922,92.602 19.5,90.5C 12.3877,83.3965 5.72108,76.0632 -0.5,68.5 Z"/></g>
								<g><path style="opacity:0.985"  d="M 127.5,60.5 C 127.5,62.5 127.5,64.5 127.5,66.5C 126.711,66.7828 126.044,67.2828 125.5,68C 100.167,68.6667 74.8333,68.6667 49.5,68C 46.8333,65 46.8333,62 49.5,59C 74.8333,58.3333 100.167,58.3333 125.5,59C 126.044,59.7172 126.711,60.2172 127.5,60.5 Z"/></g>
								<g><path style="opacity:0.899"  d="M 127.5,89.5 C 127.5,91.8333 127.5,94.1667 127.5,96.5C 126.527,96.9867 125.527,97.4867 124.5,98C 99.8333,98.6667 75.1667,98.6667 50.5,98C 48.0904,97.2421 47.0904,95.5754 47.5,93C 47.0904,90.4246 48.0904,88.7579 50.5,88C 75.1667,87.3333 99.8333,87.3333 124.5,88C 125.527,88.5133 126.527,89.0133 127.5,89.5 Z"/></g>
								<g><path style="opacity:0.991"  d="M 127.5,119.5 C 127.5,121.5 127.5,123.5 127.5,125.5C 126.5,125.833 125.833,126.5 125.5,127.5C 84.1667,127.5 42.8333,127.5 1.5,127.5C 1.16667,126.5 0.5,125.833 -0.5,125.5C -0.5,123.5 -0.5,121.5 -0.5,119.5C 0.28913,119.217 0.955796,118.717 1.5,118C 42.8333,117.333 84.1667,117.333 125.5,118C 126.044,118.717 126.711,119.217 127.5,119.5 Z"/></g>
								</svg>`,
						cmd: () => this.doAction('decreaseQuoteLevel'),
						key: ']'
					},
					indentIncrease: {
						html: `<svg version="1.0" xmlns="http://www.w3.org/2000/svg"
								 viewBox="0 0 128.000000 128.000000"
								 preserveAspectRatio="xMidYMid meet">
								
								<g transform="translate(0.000000,128.000000) scale(0.100000,-0.100000)"
								 stroke="none">
								<path d="M17 1262 c-22 -24 -21 -45 1 -65 17 -15 75 -17 624 -17 573 0 606 1
								621 18 22 24 21 45 -1 65 -17 15 -75 17 -624 17 -573 0 -606 -1 -621 -18z"/>
								<path d="M510 977 c-18 -9 -25 -21 -25 -42 0 -53 15 -55 395 -55 380 0 395 2
								395 55 0 53 -15 55 -395 55 -258 0 -351 -3 -370 -13z"/>
								<path d="M16 904 c-9 -8 -16 -23 -16 -32 0 -9 43 -59 95 -113 64 -65 95 -104
								95 -119 0 -15 -31 -54 -95 -119 -93 -95 -109 -123 -83 -149 30 -30 62 -13 155
								81 50 50 98 106 107 124 38 76 13 133 -112 256 -91 89 -116 101 -146 71z"/>
								<path d="M497 672 c-22 -24 -21 -45 1 -65 17 -15 58 -17 384 -17 341 0 366 1
								381 18 22 24 21 45 -1 65 -17 15 -58 17 -384 17 -341 0 -366 -1 -381 -18z"/>
								<path d="M510 387 c-18 -9 -25 -21 -25 -42 0 -53 15 -55 395 -55 380 0 395 2
								395 55 0 53 -15 55 -395 55 -258 0 -351 -3 -370 -13z"/>
								<path d="M17 82 c-22 -24 -21 -45 1 -65 17 -15 75 -17 624 -17 573 0 606 1
								621 18 22 24 21 45 -1 65 -17 15 -75 17 -624 17 -573 0 -606 -1 -621 -18z"/>
								</g>
								</svg>`,
						cmd: () => this.doAction('increaseQuoteLevel'),
						key: '['
					},
					ol: {
						html: `<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 128.000000 128.000000" style="shape-rendering:geometricPrecision; text-rendering:geometricPrecision; image-rendering:optimizeQuality; fill-rule:evenodd; clip-rule:evenodd" xmlns:xlink="http://www.w3.org/1999/xlink">
								<g><path style="opacity:0.842"  d="M 7.5,8.5 C 9.72241,8.17798 11.5557,8.84464 13,10.5C 13.6667,18.8333 13.6667,27.1667 13,35.5C 11.33,37.6983 9.32996,38.0317 7,36.5C 6.82991,30.8042 6.32991,25.1376 5.5,19.5C 4.11947,19.0004 3.4528,18.0004 3.5,16.5C 4.41812,13.5943 5.75145,10.9276 7.5,8.5 Z"/></g>
								<g><path style="opacity:0.921"  d="M 127.5,11.5 C 127.5,13.8333 127.5,16.1667 127.5,18.5C 126.373,19.1217 125.373,19.955 124.5,21C 95.5,21.6667 66.5,21.6667 37.5,21C 33.5,17 33.5,13 37.5,9C 66.5,8.33333 95.5,8.33333 124.5,9C 125.373,10.045 126.373,10.8783 127.5,11.5 Z"/></g>
								<g><path style="opacity:0.823"  d="M -0.5,77.5 C -0.5,74.5 -0.5,71.5 -0.5,68.5C 2.78778,65.0762 6.28778,61.7429 10,58.5C 10.1667,58.1667 10.3333,57.8333 10.5,57.5C 9.31116,56.5713 7.97782,56.238 6.5,56.5C 5.73781,60.548 3.40448,61.548 -0.5,59.5C -0.5,57.8333 -0.5,56.1667 -0.5,54.5C 4.22049,47.5397 9.72049,46.873 16,52.5C 18.0858,56.6665 17.7525,60.6665 15,64.5C 12.4066,66.5486 9.90659,68.7152 7.5,71C 10.5,71.3333 13.5,71.6667 16.5,72C 17.7596,73.8138 17.7596,75.6471 16.5,77.5C 10.8342,78.7858 5.16752,78.7858 -0.5,77.5 Z"/></g>
								<g><path style="opacity:0.863"  d="M 127.5,59.5 C 127.5,62.1667 127.5,64.8333 127.5,67.5C 126.118,68.8005 124.452,69.6339 122.5,70C 95.1667,70.6667 67.8333,70.6667 40.5,70C 34.7685,68.5317 33.1018,65.0317 35.5,59.5C 36.9122,58.1936 38.5789,57.3603 40.5,57C 67.8333,56.3333 95.1667,56.3333 122.5,57C 124.452,57.3661 126.118,58.1995 127.5,59.5 Z"/></g>
								<g><path style="opacity:0.823"  d="M -0.5,114.5 C -0.5,112.167 -0.5,109.833 -0.5,107.5C 0.583168,107.461 1.58317,107.127 2.5,106.5C 4.76804,107.719 6.60137,109.385 8,111.5C 10.1875,110.941 10.8542,109.607 10,107.5C 6.07777,106.816 4.57777,104.482 5.5,100.5C 3.3792,100.675 1.3792,100.341 -0.5,99.5C -0.5,97.8333 -0.5,96.1667 -0.5,94.5C 2.49184,89.743 6.82517,88.243 12.5,90C 15.6664,91.44 17.3331,93.94 17.5,97.5C 17.135,102.778 16.9683,108.111 17,113.5C 14.1034,118.155 9.93669,119.655 4.5,118C 2.67694,117.003 1.01027,115.836 -0.5,114.5 Z M 5.5,100.5 C 6.23411,99.2921 6.56745,97.9587 6.5,96.5C 7.5,96.5 8.5,96.5 9.5,96.5C 9.51234,99.142 8.17901,100.475 5.5,100.5 Z"/></g>
								<g><path style="opacity:0.921"  d="M 127.5,108.5 C 127.5,110.833 127.5,113.167 127.5,115.5C 126.373,116.122 125.373,116.955 124.5,118C 95.5,118.667 66.5,118.667 37.5,118C 33.5,114 33.5,110 37.5,106C 66.5,105.333 95.5,105.333 124.5,106C 125.373,107.045 126.373,107.878 127.5,108.5 Z"/></g>
								</svg>`,
						cmd: () => this.doList('ol'),
						key: 'Shift + 8',
						matches: 'OL'
					},
					ul: {
						html: `<svg version="1.0" xmlns="http://www.w3.org/2000/svg"
								 viewBox="0 0 128.000000 128.000000"
								 preserveAspectRatio="xMidYMid meet">
								
								<g transform="translate(0.000000,128.000000) scale(0.100000,-0.100000)"
								 stroke="none">
								<path d="M32 1100 c-46 -43 -37 -107 19 -139 51 -29 119 14 119 76 0 70 -87
								110 -138 63z"/>
								<path d="M316 1084 c-22 -21 -20 -67 2 -87 17 -15 64 -17 475 -17 l456 0 15
								22 c21 30 20 61 -2 81 -17 15 -64 17 -475 17 -401 0 -457 -2 -471 -16z"/>
								<path d="M63 722 c-13 -2 -32 -15 -43 -29 -45 -57 -7 -135 65 -135 30 0 45 6
								62 27 55 64 2 150 -84 137z"/>
								<path d="M317 682 c-22 -24 -22 -65 1 -86 17 -15 61 -16 478 -14 l458 3 16 28
								c13 24 13 30 0 55 l-16 27 -460 3 c-436 2 -462 1 -477 -16z"/>
								<path d="M29 301 c-31 -31 -36 -59 -17 -94 17 -33 39 -46 76 -47 87 -1 113
								117 35 158 -35 19 -63 14 -94 -17z"/>
								<path d="M317 282 c-21 -23 -22 -66 -1 -86 14 -14 67 -16 477 -14 l461 3 16
								28 c13 24 13 30 0 55 l-16 27 -460 3 c-436 2 -462 1 -477 -16z"/>
								</g>
								</svg>`,
						cmd: () => this.doList('ul'),
						key: 'Shift + 9',
						matches: 'UL'
					}
				},
				alighnment: {
					leftAlign: {
						html: `<svg version="1.0" xmlns="http://www.w3.org/2000/svg"
								 viewBox="0 0 128.000000 128.000000"
								 preserveAspectRatio="xMidYMid meet">
								
								<g transform="translate(0.000000,128.000000) scale(0.100000,-0.100000)"
								 stroke="none">
								<path d="M122 1158 c-15 -15 -15 -61 0 -76 17 -17 1019 -17 1036 0 7 7 12 24
								12 38 0 14 -5 31 -12 38 -17 17 -1019 17 -1036 0z"/>
								<path d="M122 838 c-15 -15 -15 -61 0 -76 17 -17 699 -17 716 0 15 15 15 61 0
								76 -17 17 -699 17 -716 0z"/>
								<path d="M122 518 c-15 -15 -15 -61 0 -76 17 -17 1019 -17 1036 0 7 7 12 24
								12 38 0 14 -5 31 -12 38 -17 17 -1019 17 -1036 0z"/>
								<path d="M122 198 c-15 -15 -15 -61 0 -76 17 -17 699 -17 716 0 15 15 15 61 0
								76 -17 17 -699 17 -716 0z"/>
								</g>
								</svg>`,
						cmd: () => this.squire.setTextAlignment('left'),
						// matches: ''
					},
					centerAlign: {
						html: `<svg version="1.0" xmlns="http://www.w3.org/2000/svg"
								 viewBox="0 0 128.000000 128.000000"
								 preserveAspectRatio="xMidYMid meet">
								
								<g transform="translate(0.000000,128.000000) scale(0.100000,-0.100000)"
								 stroke="none">
								<path d="M128 1109 c-26 -15 -23 -64 4 -83 20 -14 85 -16 508 -16 423 0 488 2
								508 16 28 20 30 69 3 84 -27 14 -999 13 -1023 -1z"/>
								<path d="M292 824 c-28 -20 -30 -69 -3 -84 13 -6 142 -10 351 -10 209 0 338 4
								351 10 27 15 25 64 -3 84 -34 24 -662 24 -696 0z"/>
								<path d="M122 538 c-20 -20 -14 -65 10 -82 20 -14 85 -16 508 -16 423 0 488 2
								508 16 28 20 30 69 3 84 -30 15 -1013 14 -1029 -2z"/>
								<path d="M292 254 c-28 -20 -30 -69 -3 -84 27 -14 675 -14 702 0 27 15 25 64
								-3 84 -34 24 -662 24 -696 0z"/>
								</g>
								</svg>`,
						cmd: () => this.squire.setTextAlignment('center'),
						// matches: ''
					},
					rightAlign: {
						html: `<svg version="1.0" xmlns="http://www.w3.org/2000/svg"
								 viewBox="0 0 128.000000 128.000000"
								 preserveAspectRatio="xMidYMid meet">
								
								<g transform="translate(0.000000,128.000000) scale(0.100000,-0.100000)"
								 stroke="none">
								<path d="M122 1158 c-15 -15 -15 -61 0 -76 17 -17 1019 -17 1036 0 7 7 12 24
								12 38 0 14 -5 31 -12 38 -17 17 -1019 17 -1036 0z"/>
								<path d="M442 838 c-7 -7 -12 -24 -12 -38 0 -14 5 -31 12 -38 17 -17 699 -17
								716 0 15 15 15 61 0 76 -17 17 -699 17 -716 0z"/>
								<path d="M122 518 c-15 -15 -15 -61 0 -76 17 -17 1019 -17 1036 0 7 7 12 24
								12 38 0 14 -5 31 -12 38 -17 17 -1019 17 -1036 0z"/>
								<path d="M442 198 c-7 -7 -12 -24 -12 -38 0 -14 5 -31 12 -38 17 -17 699 -17
								716 0 15 15 15 61 0 76 -17 17 -699 17 -716 0z"/>
								</g>
								</svg>`,
						cmd: () => this.squire.setTextAlignment('right'),
						// matches: ''
					},
					justifyAlign: {
						html: `<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 128.000000 128.000000" style="shape-rendering:geometricPrecision; text-rendering:geometricPrecision; image-rendering:optimizeQuality; fill-rule:evenodd; clip-rule:evenodd" xmlns:xlink="http://www.w3.org/1999/xlink">
								<g><path style="opacity:0.881"  d="M 13.5,9.5 C 46.835,9.33335 80.1683,9.50002 113.5,10C 118.833,13.6667 118.833,17.3333 113.5,21C 80.1667,21.6667 46.8333,21.6667 13.5,21C 8.24751,17.1732 8.24751,13.3399 13.5,9.5 Z"/></g>
								<g><path style="opacity:0.882"  d="M 13.5,41.5 C 46.835,41.3333 80.1683,41.5 113.5,42C 118.833,45.6667 118.833,49.3333 113.5,53C 80.1667,53.6667 46.8333,53.6667 13.5,53C 8.24751,49.1732 8.24751,45.3399 13.5,41.5 Z"/></g>
								<g><path style="opacity:0.882"  d="M 13.5,73.5 C 46.835,73.3333 80.1683,73.5 113.5,74C 118.833,77.6667 118.833,81.3333 113.5,85C 80.1667,85.6667 46.8333,85.6667 13.5,85C 8.24751,81.1732 8.24751,77.3399 13.5,73.5 Z"/></g>
								<g><path style="opacity:0.882"  d="M 13.5,105.5 C 46.835,105.333 80.1683,105.5 113.5,106C 118.833,109.667 118.833,113.333 113.5,117C 80.1667,117.667 46.8333,117.667 13.5,117C 8.24751,113.173 8.24751,109.34 13.5,105.5 Z"/></g>
								</svg>`,
						cmd: () => this.squire.setTextAlignment('justify'),
						// matches: ''
					},
				},
				dir: {
					dir_ltr: {
						html: `<svg version="1.0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128.000000 128.000000"
								 preserveAspectRatio="xMidYMid meet">
								
								<g transform="translate(0.000000,128.000000) scale(0.100000,-0.100000)"
								 stroke="none">
								<path d="M452 1109 c-47 -14 -109 -79 -123 -131 -23 -88 12 -182 87 -228 26
								-17 57 -24 110 -28 l73 -5 3 -130 c3 -111 6 -131 20 -141 13 -8 23 -8 36 0 15
								10 17 38 20 303 l2 291 40 0 40 0 2 -291 c3 -265 5 -293 21 -303 12 -8 22 -8
								35 0 15 10 17 38 20 302 l2 291 52 3 c58 3 81 25 62 56 -10 15 -34 17 -240 19
								-126 1 -244 -3 -262 -8z m148 -190 l0 -121 -65 4 c-58 3 -69 8 -98 36 -27 28
								-32 40 -32 81 0 39 5 55 25 76 33 36 54 44 118 44 l52 1 0 -121z"/>
								<path d="M812 348 c-16 -16 -15 -45 1 -55 6 -4 -98 -10 -232 -13 -218 -4 -245
								-7 -255 -23 -8 -12 -8 -22 0 -35 10 -15 35 -17 253 -20 238 -2 241 -3 229 -22
								-15 -24 -1 -53 27 -58 21 -5 125 94 125 118 0 20 -101 120 -121 120 -8 0 -20
								-5 -27 -12z"/>
								</g>
								</svg>`,
						cmd: () => this.squire.setTextDirection('ltr')
					},
					dir_rtl: {
						html: `<svg version="1.0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128.000000 128.000000"
								 preserveAspectRatio="xMidYMid meet">
								
								<g transform="translate(0.000000,128.000000) scale(0.100000,-0.100000)"
								 stroke="none">
								<path d="M452 1109 c-47 -14 -109 -79 -123 -131 -23 -88 12 -182 87 -228 26
								-17 57 -24 110 -28 l73 -5 3 -130 c3 -111 6 -131 20 -141 13 -8 23 -8 36 0 15
								10 17 38 20 303 l2 291 40 0 40 0 2 -291 c3 -265 5 -293 21 -303 12 -8 22 -8
								35 0 15 10 17 38 20 302 l2 291 52 3 c58 3 81 25 62 56 -10 15 -34 17 -240 19
								-126 1 -244 -3 -262 -8z m148 -190 l0 -121 -65 4 c-58 3 -69 8 -98 36 -27 28
								-32 40 -32 81 0 39 5 55 25 76 33 36 54 44 118 44 l52 1 0 -121z"/>
								<path d="M373 308 c-29 -30 -53 -60 -53 -69 0 -23 105 -121 125 -117 28 5 42
								34 27 58 -12 19 -9 20 229 22 218 3 243 5 253 20 8 13 8 23 0 35 -10 16 -35
								18 -253 21 -238 2 -241 3 -229 22 10 15 10 25 2 38 -21 32 -47 25 -101 -30z"/>
								</g>
								</svg>`,
						cmd: () => this.squire.setTextDirection('rtl')
					}
				},
				targets: {
					link: {
						html: '🔗',
						cmd: () => {
							let node = this.squire.getSelection(),
								url = prompt("Link", node?.href || "https://");
							if (url != null) {
								url.length ? this.squire.makeLink(url) : (node && this.squire.removeLink());
							}
						},
						matches: 'A'
					},
					imageUrl: {
						html: '🖼️',
						cmd: () => {
							let node = this.squire.getSelection(),
								src = prompt("Image", node?.src || "https://");
							src?.length ? this.squire.insertImage(src) : (node && this.squire.detach(node));
						},
						matches: 'IMG'
					},
					imageUpload: {
						html: '📂️',
						cmd: () => browseImage.click(),
						matches: 'IMG'
					}
				},
/*
				table: {
					// TODO
				},
*/
				changes: {
					undo: {
						html: '↶',
						cmd: () => this.squire.undo(),
						key: 'Z'
					},
					redo: {
						html: '↷',
						cmd: () => this.squire.redo(),
						key: 'Y'
					},
					source: {
						html: '👁',
						cmd: btn => {
							this.setMode('source' == this.mode ? 'wysiwyg' : 'source');
							btn.classList.toggle('active', 'source' == this.mode);
						}
					}
				},

				clear: {
					removeStyle: {
						html: '⎚',
						cmd: () => this.squire.setStyle()
					}
				}
			},

			plain = createElement('textarea'),
			wysiwyg = createElement('div'),
			toolbar = createElement('div'),
			browseImage = createElement('input'),
			squire = new Squire(wysiwyg, SquireDefaultConfig);

		clr.type = 'color';
		toolbar.append(clr);
		// Chrome https://github.com/the-djmaze/snappymail/issues/1199
		let clrid = 'squire-colors',
			colorlist = doc.getElementById(clrid),
			add = hex => colorlist.append(new Option(hex));
		if (!colorlist) {
			colorlist = createElement('datalist');
			colorlist.id = clrid;
			// Color blind safe Tableau 10 by Maureen Stone
			add('#4E79A7');
			add('#F28E2B');
			add('#E15759');
			add('#76B7B2');
			add('#59A14F');
			add('#EDC948');
			add('#B07AA1');
			add('#FF9DA7');
			add('#9C755F');
			add('#BAB0AC');
			doc.body.append(colorlist);
		}
		clr.setAttribute('list', clrid);

		browseImage.type = 'file';
		browseImage.accept = 'image/*';
		browseImage.style.display = 'none';
		browseImage.onchange = () => {
			if (browseImage.files.length) {
				let reader = new FileReader();
				reader.readAsDataURL(browseImage.files[0]);
				reader.onloadend = () => reader.result && this.squire.insertImage(reader.result);
			}
		}

		plain.className = 'squire-plain';
		wysiwyg.className = 'squire-wysiwyg';
		wysiwyg.dir = 'auto';
		this.mode = ''; // 'plain' | 'wysiwyg'
		this.container = container;
		this.squire = squire;
		this.plain = plain;
		this.wysiwyg = wysiwyg;

		dispatchEvent(new CustomEvent('squire-toolbar', {detail:{squire:this,actions:actions}}));

		toolbar.className = 'squire-toolbar btn-toolbar';
		let group, action/*, touchTap*/;
		for (group in actions) {
			let toolgroup = createElement('div');
			toolgroup.className = 'btn-group';
			toolgroup.id = 'squire-toolgroup-'+group;
			for (action in actions[group]) {
				let cfg = actions[group][action], input, ev = 'click';
				if (cfg.input) {
					input = createElement('input');
					input.type = cfg.input;
					ev = 'change';
				} else if (cfg.select) {
					input = createElement('select');
					input.className = 'btn';
					if (Array.isArray(cfg.select)) {
						cfg.select.forEach(value => {
							value = Array.isArray(value) ? value : [value, value];
							var option = new Option(value[0], value[1]);
							option.style[action] = value[1];
							input.append(option);
						});
					} else {
						Object.entries(cfg.select).forEach(([label, options]) => {
							let group = createElement('optgroup');
							group.label = label;
							Object.entries(options).forEach(([text, value]) => {
								var option = new Option(text, value);
								option.style[action] = value;
								group.append(option);
							});
							input.append(group);
						});
					}
					ev = 'input';
				} else {
					input = createElement('button');
					input.type = 'button';
					input.className = 'btn';
					input.innerHTML = cfg.html;
					input.action_cmd = cfg.cmd;
/*
					input.addEventListener('pointerdown', () => touchTap = input, {passive:true});
					input.addEventListener('pointermove', () => touchTap = null, {passive:true});
					input.addEventListener('pointercancel', () => touchTap = null);
					input.addEventListener('pointerup', e => {
						if (touchTap === input) {
							e.preventDefault();
							cfg.cmd(input);
						}
						touchTap = null;
					});
*/
				}
				input.addEventListener(ev, () => cfg.cmd(input));
				cfg.hint = i18n('EDITOR/' + action.toUpperCase());
				if (cfg.hint) {
					input.title = cfg.key ? cfg.hint + ' (' + ctrlKey + cfg.key + ')' : cfg.hint;
				} else if (cfg.key) {
					input.title = ctrlKey + cfg.key;
				}
				input.dataset.action = action;
				input.tabIndex = -1;
				cfg.input = input;
				toolgroup.append(input);
			}
			toolgroup.children.length && toolbar.append(toolgroup);
		}

		this.modeSelect = actions.mode.plain.input;

		let changes = actions.changes;
		changes.undo.input.disabled = changes.redo.input.disabled = true;
		squire.addEventListener('undoStateChange', e => {
			changes.undo.input.disabled = !e.detail.canUndo;
			changes.redo.input.disabled = !e.detail.canRedo;
		});

		actions.font.fontSize.input.selectedIndex = actions.font.fontSize.defaultValueIndex;

//		squire.addEventListener('focus', () => shortcuts.off());
//		squire.addEventListener('blur', () => shortcuts.on());

		container.append(toolbar, wysiwyg, plain);

		/**
		 * @param {string} fontName
		 * @return {string}
		 */
		const normalizeFontName = (fontName) => fontName.trim().replace(/(^["']*|["']*$)/g, '').trim().toLowerCase();

		/** @type {string[]} - lower cased array of available font families*/
		const fontFamiliesLowerCase = Object.values(actions.font.fontFamily.input.options).map(option => option.value.toLowerCase());

		/**
		 * A theme might have CSS like div.squire-wysiwyg[contenteditable="true"] {
		 * font-family: 'Times New Roman', Times, serif; }
		 * so let's find the best match squire.getRoot()'s font
		 * it will also help to properly handle generic font names like 'sans-serif'
		 * @type {number}
		 */
		let defaultFontFamilyIndex = 0;
		const squireRootFonts = getComputedStyle(squire.getRoot()).fontFamily.split(',').map(normalizeFontName);
		fontFamiliesLowerCase.some((family, index) => {
			const matchFound = family.split(',').some(availableFontName => {
				const normalizedFontName = normalizeFontName(availableFontName);
				return squireRootFonts.some(squireFontName => squireFontName === normalizedFontName);
			});
			if (matchFound) {
				defaultFontFamilyIndex = index;
			}
			return matchFound;
		});

		/**
		 * Instead of comparing whole 'font-family' strings,
		 * we are going to look for individual font names, because we might be
		 * editing a Draft started in another email client for example
		 *
		 * @type {Object.<string,number>}
		 */
		const fontNamesMap = {};
		/**
		 * @param {string} fontFamily
		 * @param {number} index
		 */
		const processFontFamilyString = (fontFamily, index) => {
			fontFamily.split(',').forEach(fontName => {
				const key = normalizeFontName(fontName);
				if (fontNamesMap[key] === undefined) {
					fontNamesMap[key] = index;
				}
			});
		};
		// first deal with the default font family
		processFontFamilyString(fontFamiliesLowerCase[defaultFontFamilyIndex], defaultFontFamilyIndex);
		// and now with the rest of the font families
		fontFamiliesLowerCase.forEach((fontFamily, index) => {
			if (index !== defaultFontFamilyIndex) {
				processFontFamilyString(fontFamily, index);
			}
		});

		// -----

		squire.addEventListener('pathChange', () => {

			const squireRoot = squire.getRoot();
			let range = squire.getSelection(),
				collapsed = range.collapsed,
				elm = collapsed ? range.endContainer : range?.commonAncestorContainer;
			if (elm && !(elm instanceof Element)) {
				elm = elm.parentElement;
			}
			forEachObjectValue(actions, entries => {
				forEachObjectValue(entries, cfg => {
					// Check if selection has a matching parent or contains a matching element
					cfg.matches && cfg.input.classList.toggle('active', !!(elm && (
						(!collapsed && [...elm.querySelectorAll(cfg.matches)].some(node => range.intersectsNode(node)))
						 || elm.closestWithin(cfg.matches, squireRoot)
					)));
				});
			});

			if (elm) {
				// try to find font-family and/or font-size and set "select" elements' values

				let sizeSelectedIndex = actions.font.fontSize.defaultValueIndex;
				let familySelectedIndex = defaultFontFamilyIndex;

				let familyFound = false;
				let sizeFound = false;
				do {
					if (!familyFound && elm.style.fontFamily) {
						familyFound = true;
						familySelectedIndex = -1; // show empty select if we don't know the font
						const fontNames = elm.style.fontFamily.split(',');
						for (let i = 0; i < fontNames.length; i++) {
							const index = fontNamesMap[normalizeFontName(fontNames[i])];
							if (index !== undefined) {
								familySelectedIndex = index;
								break;
							}
						}
					}

					if (!sizeFound && elm.style.fontSize) {
						sizeFound = true;
						// -1 is ok because it will just show a black <select>
						sizeSelectedIndex = actions.font.fontSize.select.indexOf(elm.style.fontSize);
					}

					elm = elm.parentElement;
				} while ((!familyFound || !sizeFound) && elm && elm !== squireRoot);

				actions.font.fontFamily.input.selectedIndex = familySelectedIndex;
				actions.font.fontSize.input.selectedIndex = sizeSelectedIndex;
			}
		});
/*
		squire.addEventListener('cursor', e => {
			console.dir({cursor:e.detail.range});
		});
		squire.addEventListener('select', e => {
			console.dir({select:e.detail.range});
		});
*/
	}

	doAction(name) {
		this.squire[name]();
		this.squire.focus();
	}

	doList(type) {
		let fn = {ul:'makeUnorderedList',ol:'makeOrderedList'};
		this.squire.hasFormat(type) ?  this.squire.removeList() : this.squire[fn[type]]()
	}
/*
	testPresenceinSelection(format, validation) {
		return validation.test(this.squire.getPath()) || this.squire.hasFormat(format);
	}
*/
	setMode(mode) {
		if (this.mode != mode) {
			let cl = this.container.classList, source = 'source' == this.mode;
			cl.remove('squire-mode-'+this.mode);
			if ('plain' == mode) {
				this.plain.value = htmlToPlain(source ? this.plain.value : this.squire.getHTML(), true);
			} else if ('source' == mode) {
				this.plain.value = this.squire.getHTML();
			} else {
				this.setData(source ? this.plain.value : plainToHtml(this.plain.value, true));
				mode = 'wysiwyg';
			}
			this.mode = mode; // 'wysiwyg' or 'plain'
			cl.add('squire-mode-'+mode);
			this.onModeChange?.();
			setTimeout(()=>this.focus(),1);
		}
		this.modeSelect.selectedIndex = 'plain' == this.mode ? 1 : 0;
	}

	on(type, fn) {
		if ('mode' == type) {
			this.onModeChange = fn;
		} else {
			this.squire.addEventListener(type, fn);
			this.plain.addEventListener(type, fn);
		}
	}

	execCommand(cmd, cfg) {
		if ('insertSignature' == cmd) {
			cfg = Object.assign({
				clearCache: false,
				isHtml: false,
				insertBefore: false,
				signature: ''
			}, cfg);

			if (cfg.clearCache) {
				this._prev_txt_sig = null;
			} else try {
				const signature = cfg.isHtml ? htmlToPlain(cfg.signature) : cfg.signature;
				if ('plain' === this.mode) {
					let
						text = this.plain.value,
						prevSignature = this._prev_txt_sig;
					if (prevSignature) {
						text = text.replace(prevSignature, '').trim();
					}
					this.plain.value = cfg.insertBefore ? '\n\n' + signature + '\n\n' + text : text + '\n\n' +  signature;
				} else {
					const squire = this.squire,
						root = squire.getRoot(),
						br = createElement('br'),
						div = createElement('div');
					div.className = 'rl-signature';
					div.innerHTML = cfg.isHtml ? cfg.signature : plainToHtml(cfg.signature);
					root.querySelectorAll('div.rl-signature').forEach(node => node.remove());
					cfg.insertBefore ? root.prepend(div) : root.append(div);
					// Move cursor above signature
					div.before(br);
					div.before(br.cloneNode());
//					squire._docWasChanged();
				}
				this._prev_txt_sig = signature;
			} catch (e) {
				console.error(e);
			}
		}
	}

	getData() {
		return 'source' == this.mode ? this.plain.value : trimLines(this.squire.getHTML());
	}

	setData(html) {
//		this.plain.value = html;
		const squire = this.squire;
		squire.setHTML(trimLines(html));
		const node = squire.getRoot(),
			range = squire.getSelection();
		range.setStart(node, 0);
		range.setEnd(node, 0);
		squire.setSelection( range );
	}

	getPlainData() {
		return this.plain.value;
	}

	setPlainData(text) {
		this.plain.value = text;
	}

	blur() {
		this.squire.blur();
	}

	focus() {
		if ('plain' == this.mode) {
			this.plain.focus();
			this.plain.setSelectionRange(0, 0);
		} else {
			this.squire.focus();
		}
	}
}

this.SquireUI = SquireUI;

})(document);
