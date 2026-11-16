/**
 * Conversation view, inside the message view: the whole thread on one page.
 *
 * Above the open message, a "Conversation · N messages" bar. A click expands
 * the thread: **earlier** messages are placed above the body, **later** ones
 * below it — the thread reads top to bottom, in order, and the open message
 * keeps its native view (actions, attachments, images). Every other message
 * is collapsed; a click loads its body.
 *
 * - The bar only exists when it has something to say: a lone message, an
 *   error or a draft draw nothing.
 * - A body only goes through the core sanitizer (`rl.Utils.cleanHtml`, the
 *   one the native view uses). It is the only `innerHTML` of the plugin;
 *   everything else is written with `textContent`. Remote images stay
 *   blocked: open the message to see them.
 * - Nothing before login: `rl-view-model` also fires on the login screen.
 *   The template filter and `loggedIn()` — strictly `true`, read again when
 *   acting — are what protect.
 * - A late reply draws nothing: if the view moved to another message while
 *   the request was running, the plugin stays silent. The timeout is armed
 *   before the request, so a callback that never comes freezes nothing.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Convergent Cloud Computing
 */

(rl => {

if (!rl) {
	return;
}

const
	ID_BEFORE = 'conversation-before',
	ID_AFTER = 'conversation-after',
	TEMPLATE = 'MailMessageView',
	TIMEOUT = 15000,
	CACHE_TTL = 5 * 60 * 1000,
	PREF_KEY = 'conversation.expanded',
	cache = new Map(),

	loggedIn = () => {
		try {
			return true === (window.rl && rl.settings && rl.settings.get('Auth'));
		} catch (e) {
			return false;
		}
	},

	L = (key, fallback) => {
		try {
			const s = rl.i18n('CONVERSATION/' + key);
			return (s && s !== 'CONVERSATION/' + key) ? s : fallback;
		} catch (e) {
			return fallback;
		}
	},

	/** The "always expanded" choice: a convenience of this browser, nothing more. */
	expandedPref = value => {
		try {
			if (undefined === value) {
				return '1' === window.localStorage.getItem(PREF_KEY);
			}
			value ? window.localStorage.setItem(PREF_KEY, '1') : window.localStorage.removeItem(PREF_KEY);
		} catch (e) {
			// storage refused (private browsing): stay collapsed
		}
		return !!value;
	},

	/** A folder name in the route, hashed as the core does (`FolderModel.fullNameHash`). */
	folderHash = name => String(name).replace(/[^a-z0-9._-]+/giu, part =>
		btoa(unescape(encodeURIComponent(JSON.stringify(part))))
			.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')),

	link = m => '#/mailbox/' + folderHash(m.folder) + '/m' + m.uid,

	/** The core `b64EncodeJSONSafe` (`Common/Utils.js`). */
	b64Json = data => btoa(encodeURIComponent(JSON.stringify(data))
		.replace(/%([0-9A-F]{2})/g, (x, p) => String.fromCharCode(parseInt(p, 16))))
		.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),

	base = () => {
		try {
			return String(window.location && window.location.pathname || '/').replace(/\/+$/, '') + '/';
		} catch (e) {
			return '/';
		}
	},

	readableDate = raw => {
		const d = new Date(raw);
		if (!raw || isNaN(d.getTime())) {
			return '';
		}
		try {
			return d.toLocaleString(document.documentElement && document.documentElement.lang || undefined,
				{ day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
		} catch (e) {
			return d.toISOString().slice(0, 16).replace('T', ' ');
		}
	},

	nameOf = m => (m.from && (m.from.name || m.from.address)) || L('UNKNOWN', '(unknown sender)'),

	/**
	 * The attachments of a message, in the shape `cleanHtml()` expects for
	 * inline images (`findByCid`). The preview URL is the one the core builds
	 * (`AttachmentModel.linkPreview`).
	 */
	attachments = (list, folder, uid) => {
		const raw = Array.isArray(list) ? list
			: (list && Array.isArray(list['@Collection']) ? list['@Collection'] : []);
		const out = raw.filter(a => a && 'object' === typeof a).map(a => ({
			fileName: String(a.fileName || ''),
			mimeType: String(a.mimeType || ''),
			cId: String(a.cId || ''),
			contentLocation: String(a.contentLocation || ''),
			inline: !!a.isInline,
			download: b64Json({
				folder: a.folder || folder, uid: a.uid || uid,
				mimeIndex: String(a.mimeIndex || ''), mimeType: String(a.mimeType || ''),
				fileName: String(a.fileName || ''),
				accountHash: rl.settings && rl.settings.get('accountHash')
			}),
			contentId() { return this.cId.replace(/^<+|>+$/g, ''); },
			linkPreview() { return base() + '?/Raw/&q[]=/0/View/&q[]=/' + this.download; },
			isInline(v) { undefined !== v && (this.inline = !!v); return this.inline; },
			isLinked() { return false; }
		}));
		out.findByCid = cid => {
			const c = String(cid || '').replace(/^<+|>+$/g, '');
			return out.find(a => c && a.contentId() === c) || null;
		};
		return out;
	},

	/** The core `Message` action as is: the body comes the same way as in the native view. */
	loadMessage = m => new Promise(resolve => {
		const timer = setTimeout(() => resolve(null), TIMEOUT + 1000);
		const done = r => { clearTimeout(timer); resolve(r); };
		try {
			rl.fetchJSON(base() + '?/Json/&q[]=/0/', {}, { Action: 'Message', folder: m.folder, uid: m.uid })
				.then(data => done(data && data.Result && 'object' === typeof data.Result ? data.Result : null))
				.catch(() => done(null));
		} catch (e) {
			done(null);
		}
	}),

	ask = (action, params) => new Promise(resolve => {
		const timer = setTimeout(() => resolve(null), TIMEOUT + 1000);
		const done = r => { clearTimeout(timer); resolve(r); };
		try {
			rl.pluginRemoteRequest((iError, oData) => {
				done((!iError && oData && oData.Result) ? oData.Result : null);
			}, action, params, TIMEOUT);
		} catch (e) {
			done(null);
		}
	}),

	element = (tag, className, text) => {
		const e = document.createElement(tag);
		className && (e.className = className);
		undefined !== text && (e.textContent = text);
		return e;
	},

	/** Writes the body of an expanded message. The only `innerHTML`, and it comes from `cleanHtml`. */
	writeBody = (zone, r, m, rank) => {
		zone.textContent = '';
		if (!r) {
			zone.append(element('div', 'conversation-note', L('FAILED', 'This message could not be loaded.')));
			return;
		}
		if (r.encrypted) {
			zone.append(element('div', 'conversation-note', L('ENCRYPTED', 'Encrypted message: open it to read it.')));
			return;
		}
		const body = element('div', 'conversation-body');
		body.id = 'rl-conv-' + rank;
		let remote = false;
		const files = attachments(r.attachments, m.folder, m.uid);
		if (r.html && rl.Utils && rl.Utils.cleanHtml) {
			const clean = rl.Utils.cleanHtml(String(r.html), files, '#' + body.id);
			remote = !!(clean && clean.hasExternals);
			body.innerHTML = clean && 'string' === typeof clean.html ? clean.html : '';
		} else if (r.plain && rl.Utils && rl.Utils.plainToHtml) {
			body.innerHTML = rl.Utils.plainToHtml(String(r.plain));
		} else {
			body.textContent = L('EMPTY', '(no text)');
		}
		zone.append(body);

		const foot = element('div', 'conversation-foot');
		if (remote) {
			foot.append(element('span', 'conversation-note', L('IMAGES', 'Remote images blocked.') + ' '));
		}
		const shown = files.filter(a => !a.inline && a.fileName);
		if (shown.length) {
			foot.append(element('span', 'conversation-files',
				'📎 ' + shown.map(a => a.fileName).join(', ') + ' '));
		}
		const open = element('a', 'conversation-open', L('OPEN', 'Open this message'));
		open.setAttribute('href', link(m));
		foot.append(open);
		zone.append(foot);
	},

	/** One collapsed message of the thread. Returns an element, inserts nothing. */
	article = (m, rank) => {
		const a = element('article', 'conversation-message' + (m.seen ? '' : ' conversation-unseen'));
		const head = element('button', 'conversation-head');
		head.type = 'button';
		head.setAttribute('aria-expanded', 'false');
		head.append(
			element('span', 'conversation-from', nameOf(m)),
			element('span', 'conversation-way', m.sent ? L('SENT', 'sent') : ''),
			element('span', 'conversation-subject', m.subject || L('NO_SUBJECT', '(no subject)')),
			element('span', 'conversation-date', readableDate(m.date)));
		const zone = element('div', 'conversation-zone');
		zone.hidden = true;
		let loaded = false;
		head.addEventListener('click', () => {
			const open = 'true' === head.getAttribute('aria-expanded');
			head.setAttribute('aria-expanded', open ? 'false' : 'true');
			zone.hidden = open;
			if (!open && !loaded) {
				loaded = true;
				zone.textContent = L('LOADING', 'Loading…');
				loadMessage(m).then(r => {
					if (!loggedIn()) {
						zone.textContent = '';
						return;
					}
					writeBody(zone, r, m, rank);
					r || (loaded = false);
				});
			}
		});
		a.append(head, zone);
		return a;
	},

	remove = () => {
		[ID_BEFORE, ID_AFTER].forEach(id => {
			const e = document.getElementById(id);
			e && e.remove();
		});
	},

	/** The only place that writes into the view. */
	place = r => {
		remove();
		const messages = r && Array.isArray(r.messages) ? r.messages : [];
		const i = messages.findIndex(m => m && m.current);
		if (messages.length < 2 || i < 0) {
			return;
		}
		const bodyText = document.querySelector('.messageItem .bodyText') || document.querySelector('.bodyText');
		if (!bodyText || !bodyText.parentNode) {
			return;
		}
		const before = messages.slice(0, i), after = messages.slice(i + 1);

		const top = element('section', 'conversation');
		top.id = ID_BEFORE;
		top.setAttribute('aria-label', L('TITLE', 'Conversation'));
		const bar = element('div', 'conversation-bar');
		const title = element('span', 'conversation-title',
			L('TITLE', 'Conversation') + ' · ' + messages.length + ' ' + L('MESSAGES', 'messages')
			+ (r.partial || r.truncated ? ' ' + L('PARTIAL', '(at least)') : ''));
		const toggle = element('button', 'conversation-toggle');
		toggle.type = 'button';
		bar.append(title, toggle);

		const list = element('div', 'conversation-list');
		before.forEach((m, k) => list.append(article(m, k)));
		// The open message keeps its native view, without a header in the thread:
		// a marker shows where it sits, or it would read as the end of the message above.
		const current = messages[i];
		const here = element('div', 'conversation-here');
		here.append(
			element('span', 'conversation-here-label', L('HERE', 'Message shown below')),
			element('span', 'conversation-from', nameOf(current)),
			element('span', 'conversation-date', readableDate(current.date)));
		list.append(here);
		top.append(bar, list);

		const bottom = element('section', 'conversation conversation-later');
		bottom.id = ID_AFTER;
		after.forEach((m, k) => bottom.append(article(m, i + 1 + k)));

		const set = expanded => {
			list.hidden = !expanded;
			bottom.hidden = !expanded || !after.length;
			toggle.textContent = expanded ? L('HIDE', 'Hide conversation') : L('SHOW', 'Show conversation');
			toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		};
		toggle.addEventListener('click', () => set(expandedPref('true' !== toggle.getAttribute('aria-expanded'))));
		set(expandedPref());

		bodyText.parentNode.insertBefore(top, bodyText);
		bodyText.after(bottom);
	},

	keyOf = message => message ? message.folder + '\u0000' + message.uid : '',

	paint = async view => {
		remove();
		if (!loggedIn() || !rl.pluginRemoteRequest) {
			return;
		}
		const message = view.message && view.message();
		if (!message || !message.folder || !(message.uid > 0)) {
			return;
		}
		const key = keyOf(message);
		const kept = cache.get(key);
		let r;
		if (kept && Date.now() - kept.t < CACHE_TTL) {
			r = kept.r;
		} else {
			r = await ask('ConversationList', { Folder: String(message.folder), Uid: String(message.uid) });
			r && cache.set(key, { t: Date.now(), r });
		}
		const now = view.message && view.message();
		if (keyOf(now) !== key || !loggedIn()) {
			return;
		}
		place(r);
	};

/**
 * `rl-view-model`, not `.create`: on `.create` the template is not applied
 * yet and `.bodyText` does not exist.
 */
addEventListener('rl-view-model', e => {
	if (TEMPLATE !== (e.detail && e.detail.viewModelTemplateID)) {
		return;
	}
	// The view is shared with other plugins: nothing may escape from here.
	try {
		const view = e.detail;
		if (view.message && view.message.subscribe) {
			view.message.subscribe(() => {
				paint(view).catch(err => console.error('[conversation]', err));
			});
		}
		paint(view).catch(err => console.error('[conversation]', err));
	} catch (err) {
		console.error('[conversation] stopped after an error:', err);
	}
});

// Exposed for tests.
window.__conversation = { place, article, writeBody, attachments, folderHash, link, b64Json, loggedIn, cache, expandedPref };

})(window.rl);
