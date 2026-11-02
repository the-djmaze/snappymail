import { pString, pInt, b64EncodeJSONSafe } from 'Common/Utils';

import {
	getFolderFromCacheList
} from 'Common/Cache';

import { SettingsGet } from 'Common/Globals';
import { SUB_QUERY_PREFIX } from 'Common/Links';

import { AppUserStore } from 'Stores/User/App';
import { SettingsUserStore } from 'Stores/User/Settings';

import { AbstractFetchRemote } from 'Remote/AbstractFetch';

class RemoteUserFetch extends AbstractFetchRemote {

	/**
	 * @param {?Function} fCallback
	 * @param {string} sFolderFullName
	 * @param {number} iUid
	 * @returns {boolean}
	 */
	message(fCallback, sFolderFullName, iUid) {
		sFolderFullName = pString(sFolderFullName);
		iUid = pInt(iUid);

		if (0 < iUid) {
			if (getFolderFromCacheList(sFolderFullName)) {
				this.abort('Message').request('Message',
					fCallback,
					{},
					null,
					'Message/' +
						SUB_QUERY_PREFIX +
						'/' +
						b64EncodeJSONSafe([
							sFolderFullName,
							iUid,
							AppUserStore.threadsAllowed() && SettingsUserStore.useThreads() ? 1 : 0,
							SettingsGet('accountHash')
						])
				);

				return true;
			} else {
				console.warn(`Remote.message: no such folder ${JSON.stringify(sFolderFullName)}, not fetching uid ${iUid}`);
			}
		}

		return false;
	}

	/**
	 * @param {?Function} fCallback
	 * @param {Object} oData
	 */
	saveSettings(fCallback, oData) {
		this.request('SettingsUpdate', fCallback, oData);
	}

	/**
	 * @param {string} key
	 * @param {?scalar} value
	 * @param {?Function} fCallback
	 */
	saveSetting(key, value, fCallback) {
		this.saveSettings(fCallback, {
			[key]: value
		});
	}
}

export default new RemoteUserFetch();
