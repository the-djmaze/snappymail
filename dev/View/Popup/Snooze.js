import { addObservablesTo } from 'External/ko';

import { getNotification, i18n } from 'Common/Translator';
import { Settings } from 'Common/Globals';

import { MessageUserStore } from 'Stores/User/Message';
import { MessagelistUserStore } from 'Stores/User/Messagelist';
import { FolderUserStore } from 'Stores/User/Folder';

import Remote from 'Remote/User/Fetch';

import { AbstractViewPopup } from 'Knoin/AbstractViews';

import { loadFolders } from 'Model/FolderCollection';

const
	pad2 = value => String(value).padStart(2, '0'),
	isSnoozedMessage = message => message?.flags().some(flag => /^snoozed_[0-9]+$/i.test(flag)),
	localDateTime = date => date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate())
			+ 'T' + pad2(date.getHours()) + ':' + pad2(date.getMinutes());

export class SnoozePopupView extends AbstractViewPopup {
	constructor() {
		super('Snooze');

		addObservablesTo(this, {
			until: '',
			minUntil: '',
			saving: false
		});

		this.message = null;
	}

	onShow(message) {
		this.message = message;
		const snoozedUntil = message?.snoozedUntil();
		if (snoozedUntil) {
			this.until(localDateTime(new Date(snoozedUntil * 1000)));
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
			alert(i18n('SNOOZE/INVALID_DATE'));
			return false;
		}

		this.saving(true);
		Remote.post('MessageSnooze', null, {
			folder: message.folder,
			uid: message.uid,
			until
		}).then(data => {
			const snoozedFolder = data?.Result?.folder;
			if (snoozedFolder) {
				Settings.set('SnoozeFolder', snoozedFolder);
				FolderUserStore.snoozedFolder(snoozedFolder);
			}
			this.close();
			if (isSnoozedMessage(message) || message.folder === FolderUserStore.snoozedFolder()) {
				message.flags().filter(flag => /^snoozed_[0-9]+$/.test(flag))
					.forEach(flag => message.flags.remove(flag));
				message.flags.push('snoozed_' + until);
			} else {
				MessageUserStore.message(null);
			}
			loadFolders(() => MessagelistUserStore.reload(true, true));
		}, error => {
			this.saving(false);
			alert(getNotification(error.code) + (error.message ? '\n' + error.message : ''));
		});
		return false;
	}
}
