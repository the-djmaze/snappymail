((rl) => {
	const

	pluginSection = 'snooze',

	isSnoozeFlag = flag => /^snoozed_[0-9]+$/i.test(flag),
	isReturnedFlag = flag => 'snoozed' === flag.toLowerCase(),
	isSeenFlag = flag => '\\seen' === flag.toLowerCase(),

	untilFromFlags = flags => {
		const flag = (flags || []).find(isSnoozeFlag);
		return flag ? parseInt(flag.slice(8), 10) : 0;
	},

	pad2 = value => String(value).padStart(2, '0'),

	dateTimeText = timestamp => {
		const date = new Date(timestamp * 1000);
		return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
			+ ' ' + pad2(date.getHours()) + ':' + pad2(date.getMinutes());
	},

	localDateTime = date => date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate())
		+ 'T' + pad2(date.getHours()) + ':' + pad2(date.getMinutes()),

	// SnappyMail's Knockout build has no ko.pureComputed(), see dev/External/ko.js
	koComputable = fn => ko.computed(fn, { pure: true }),

	configuredFolder = () => rl.pluginSettingsGet(pluginSection, 'SnoozeFolder') || '',

	runtimeFolder = ko.observable(null);

let savedFolder = null,
	folderHooked = false,
	markedFolder = null;

const
	setSnoozeFolder = folder => {
		folder = folder || '';
		savedFolder = folder;
		runtimeFolder(folder);
	},

	// Settings are available before this script loads, but keep the lazy
	// fallback in case the plugin settings are not there yet.
	snoozeFolder = () => {
		if (null === runtimeFolder()) {
			setSnoozeFolder(configuredFolder());
		}
		return runtimeFolder();
	},

	/**
	 * The configured snooze folder, or the folder named "Snoozed".
	 * @returns {?Object}
	 */
	findSnoozedFolderModel = () => {
		const configured = snoozeFolder();
		let found = null;
		const walk = folders => (folders || []).some(folder => {
			if ((configured && folder.fullName === configured)
				|| 'snoozed' === (folder.name() || '').toLowerCase()) {
				found = folder;
				return true;
			}
			return walk(folder.subFolders());
		});
		walk(rl.app.folderList());
		return found;
	},

	hasSnoozedFolder = () => !!findSnoozedFolderModel(),

	/**
	 * Treat the snooze folder as a system folder, like the core snooze
	 * implementation did with FolderType.Snoozed (7).
	 * @param {Object} folder
	 */
	markSnoozedFolder = folder => {
		// Never convert an existing system folder (inbox, sent, ...).
		if (0 !== folder.type() && 7 !== folder.type()) {
			return;
		}
		if (!folder.snoozedMarked) {
			folder.snoozedMarked = true;
			folder.snoozedOriginalIcon = folder.icon;
			folder.snoozedOriginalNameInfo = folder.nameInfo;
			folder.icon = koComputable(() => '💤');
			folder.nameInfo = koComputable(() => '');
		}
		7 !== folder.type() && folder.type(7);
	},

	unmarkSnoozedFolder = folder => {
		if (folder.snoozedMarked) {
			folder.snoozedMarked = false;
			folder.icon = folder.snoozedOriginalIcon;
			folder.nameInfo = folder.snoozedOriginalNameInfo;
			7 === folder.type() && folder.type(0);
		}
	},

	applySnoozedFolder = () => {
		const folder = findSnoozedFolderModel();
		if (folder !== markedFolder) {
			markedFolder && unmarkSnoozedFolder(markedFolder);
			markedFolder = folder;
		}
		folder && markSnoozedFolder(folder);
	},

	/**
	 * Reload the folder tree, so a newly created "Snoozed" folder shows up.
	 * This is what loadFolders() does internally.
	 */
	reloadFolders = callback => {
		const folders = rl.app.folderList(),
			Collection = folders?.constructor;
		if (!Collection?.reviveFromJson) {
			callback?.();
			return;
		}
		rl.app.Remote.abort('Folders').post('Folders').then(
			data => {
				Collection.reviveFromJson(data?.Result)?.storeIt();
				callback?.();
			},
			error => {
				console.error('Snooze: failed to reload folders', error);
				callback?.();
			}
		);
	},

	folderOptions = () => {
		const current = snoozeFolder(),
			detected = findSnoozedFolderModel(),
			result = [{
				id: '',
				name: rl.i18n('SNOOZE/FOLDER_AUTO'),
				disabled: false
			}],
			walk = folders => (folders || []).forEach(folder => {
				result.push({
					id: folder.fullName,
					name: '\u00A0\u00A0\u00A0'.repeat(folder.deep) + folder.name(),
					disabled: folder.fullName !== current && folder !== detected
						&& (0 !== folder.type() || !folder.selectable())
				});
				walk(folder.subFolders());
			});
		walk(rl.app.folderList());
		return result;
	},

	processSnoozes = () => {
		if (!rl.settings.get('Auth') || !hasSnoozedFolder()) {
			return;
		}
		rl.pluginRemoteRequest(
			(iError, data) => {
				if (iError) {
					console.error('Snooze: processing failed', iError);
				} else if (0 < (data?.Result?.moved || 0)) {
					rl.app.messageList.reload(true, true);
				}
			},
			'SnoozeProcess'
		);
	};

	/* ------------------------------------------------------------------ */
	/* Knockout bindings / template helpers                               */
	/* ------------------------------------------------------------------ */

	ko.bindingHandlers.snoozed = {
		update(element, valueAccessor) {
			const until = untilFromFlags(ko.unwrap(valueAccessor()));
			element.hidden = !until;
			element.textContent = until ? '💤 ' + dateTimeText(until) : '';
			until ? element.setAttribute('title', dateTimeText(until)) : element.removeAttribute('title');
		}
	};

	ko.bindingHandlers.snoozedRow = {
		update(element, valueAccessor) {
			const flags = ko.unwrap(valueAccessor()) || [];
			element.classList.toggle(
				'snoozed-returned',
				flags.some(isReturnedFlag) && !flags.some(isSeenFlag)
			);
		}
	};

	ko.bindingHandlers.snoozedNotice = {
		update(element, valueAccessor) {
			const message = ko.unwrap(valueAccessor()),
				flags = message?.flags?.() || [],
				until = untilFromFlags(flags),
				returned = !until && flags.some(isReturnedFlag) && !flags.some(isSeenFlag);
			element.hidden = !until && !returned;
			element.classList.toggle('snoozedReturned', returned);
			element.textContent = until
				? '💤 ' + rl.i18n('SNOOZE/UNTIL') + ': ' + dateTimeText(until)
				: (returned ? '💤 ' + rl.i18n('SNOOZE/RETURNED') : '');
		}
	};

	const inject = (templateId, callback) => {
		const template = document.getElementById(templateId);
		template?.content && callback(template.content);
	};

	/* ------------------------------------------------------------------ */
	/* Message list: show snooze date and mark returned messages          */
	/* ------------------------------------------------------------------ */

	inject('MailMessageList', content => {
		content.querySelectorAll('.messageListItem').forEach(row => {
			if (row.querySelector('.snoozedDate')) {
				return;
			}
			row.setAttribute('data-bind', row.getAttribute('data-bind') + ', snoozedRow: flags');
			row.querySelector('.subjectParent')?.after(
				Element.fromHTML('<div class="snoozedDate" data-bind="snoozed: flags"></div>')
			);
		});
	});

	/* ------------------------------------------------------------------ */
	/* Message view: toolbar button + notice                              */
	/* ------------------------------------------------------------------ */

	inject('MailMessageView', content => {
		if (content.querySelector('.snoozeButton')) {
			return;
		}

		const button = Element.fromHTML(
			'<div class="btn-group" data-i18n="[title]SNOOZE/BUTTON">'
			+ '<a class="btn fontastic snoozeButton" data-bind="command: snoozeCommand">💤</a>'
			+ '</div>'
		);
		const deleteButton = content.querySelector('.top-toolbar [data-i18n="[title]GLOBAL/DELETE"]');
		(deleteButton?.closest('.btn-group')
			|| content.querySelector('.top-toolbar > .btn-group'))?.after(button);

		content.querySelector('.b-message > #messageItem')?.before(
			Element.fromHTML('<div class="snoozedNotice" data-bind="snoozedNotice: message"></div>')
		);
	});

	/* ------------------------------------------------------------------ */
	/* Folder settings: choose the snoozed folder                         */
	/* ------------------------------------------------------------------ */

	inject('PopupsFolderSystem', content => {
		if (content.querySelector('.snoozedFolderSelect')) {
			return;
		}
		const group = Element.fromHTML(
			'<div class="control-group">'
			+ '<label data-icon="💤" data-i18n="SNOOZE/FOLDER"></label>'
			+ '<select class="snoozedFolderSelect" data-bind="options: snoozedFolderSelectList, value: snoozedFolder,'
			+ ' optionsText: \'name\', optionsValue: \'id\', optionsAfterRender: $root.defaultOptionsAfterRender"></select>'
			+ '</div>'
		);
		content.querySelector('.form-horizontal')?.append(group);
	});

	/* ------------------------------------------------------------------ */
	/* Snooze popup                                                       */
	/* ------------------------------------------------------------------ */

	let messageView = null;

	class SnoozePopupView extends rl.pluginPopupView {
		constructor() {
			super('Snooze');

			this.addObservables({
				until: '',
				minUntil: '',
				saving: false
			});

			this.message = null;
		}

		onShow(message) {
			this.message = message;
			const until = untilFromFlags(message?.flags?.());
			if (until) {
				this.until(localDateTime(new Date(until * 1000)));
			} else {
				const tomorrow = new Date();
				tomorrow.setDate(tomorrow.getDate() + 1);
				tomorrow.setHours(9, 0, 0, 0);
				this.until(localDateTime(tomorrow));
			}
			this.minUntil(localDateTime(new Date(Math.ceil(Date.now() / 60000) * 60000)));
			this.saving(false);
		}

		snoozeTomorrow() {
			const date = new Date();
			date.setDate(date.getDate() + 1);
			date.setHours(8, 0, 0, 0);
			this.until(localDateTime(date));
		}

		snoozeNextWeek() {
			const date = new Date(),
				daysUntilMonday = (8 - date.getDay()) % 7 || 7;
			date.setDate(date.getDate() + daysUntilMonday);
			date.setHours(8, 0, 0, 0);
			this.until(localDateTime(date));
		}

		snoozeLater() {
			this.until(localDateTime(new Date(Date.now() + 5 * 60 * 60 * 1000)));
		}

		submitForm(form) {
			if (!form.reportValidity() || this.saving()) {
				return false;
			}

			const message = this.message,
				until = Math.floor(new Date(this.until()).getTime() / 1000);
			if (!message || !Number.isFinite(until) || until <= Date.now() / 1000) {
				alert(rl.i18n('SNOOZE/INVALID_DATE'));
				return false;
			}

			this.saving(true);
			rl.pluginRemoteRequest(
				(iError, data) => {
					this.saving(false);
					if (iError) {
						console.error('Snooze: failed to snooze message', data);
						alert(data?.messageAdditional || data?.message || rl.i18n('SNOOZE/ERROR'));
						return;
					}

					const folder = data?.Result?.folder;
					folder && setSnoozeFolder(folder);
					this.close();
					if (message.folder === folder) {
						// Re-snoozed while staying in the same folder.
						message.flags().filter(isSnoozeFlag).forEach(flag => message.flags.remove(flag));
						message.flags.push('snoozed_' + until);
					} else {
						messageView?.closeMessage?.();
					}

					const reload = () => rl.app.messageList.reload(true, true);
					data?.Result?.created ? reloadFolders(reload) : reload();
				},
				'SnoozeMessage',
				{
					folder: message.folder,
					uid: message.uid,
					until: until
				}
			);
			return false;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Extend existing view models                                        */
	/* ------------------------------------------------------------------ */

	addEventListener('rl-view-model.create', event => {
		const view = event.detail;

		switch (view.viewModelTemplateID) {
			case 'MailFolderList': {
				// Mark before the template renders, so the icon is correct.
				applySnoozedFolder();
				const systemFolders = view.systemFolders;
				view.systemFolders = koComputable(() => {
					const list = systemFolders() || [],
						folder = findSnoozedFolderModel();
					return folder && !list.includes(folder) ? list.concat([folder]) : list;
				});
				break;
			}

			case 'MailMessageView':
				messageView = view;
				view.canSnooze = koComputable(() =>
					view.messageVisible()
					&& !rl.app.messageList.isDraftFolder()
					&& !rl.app.messageList.isSentFolder()
				);
				view.snoozeCommand = () => {
					const message = view.message();
					message && SnoozePopupView.showModal([message]);
				};
				ko.decorateCommands(view, {
					snoozeCommand: () => view.canSnooze()
				});
				break;

			case 'PopupsFolderSystem':
				if (!view.snoozedFolder) {
					view.snoozedFolderSelectList = koComputable(folderOptions);
					view.snoozedFolder = runtimeFolder;
					snoozeFolder();
					if (!folderHooked) {
						folderHooked = true;
						runtimeFolder.subscribe((folder => {
							if (folder === savedFolder) {
								return;
							}
							setSnoozeFolder(folder);
							rl.pluginRemoteRequest(
								(iError) => iError && console.error('Snooze: failed to save folder', iError),
								'SnoozeSetFolder',
								{ folder: folder }
							);
						}).debounce(500));
					}
				}
				break;

			// no default
		}
	});

	/* Show the snooze folder with the system folders at the top left. */
	if (rl.app?.folderList) {
		setSnoozeFolder(configuredFolder());
		rl.app.folderList.subscribe(applySnoozedFolder);
		runtimeFolder.subscribe(applySnoozedFolder);
		applySnoozedFolder();

		/* Process due messages while the app stays open. */
		setInterval(processSnoozes, 60000);
	}
})(rl);
