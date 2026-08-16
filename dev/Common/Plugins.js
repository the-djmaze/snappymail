import ko from 'ko';
import { settingsAddViewModel } from 'Screen/AbstractSettings';
import { SettingsGet } from 'Common/Globals';
import { AbstractViewPopup } from 'Knoin/AbstractViews';

const USER_VIEW_MODELS_HOOKS = [],
	ADMIN_VIEW_MODELS_HOOKS = [];

/**
 * @param {Function} callback
 * @param {string} action
 * @param {Object=} parameters
 * @param {?number=} timeout
 */
rl.pluginRemoteRequest = (callback, action, parameters, timeout) => {
	rl.app.Remote.request('Plugin' + action, callback, parameters, timeout);
};

/**
 * Navigation entries contributed by plugins, rendered in the folder list
 * toolbar beside Compose and Contacts.
 * @type {Array}
 */
export const pluginNavEntries = ko.observableArray();

/**
 * Register a navigation entry for a screen a plugin provides.
 *
 * Without this a plugin that adds a screen has to reach into the folder list
 * template itself, which several already do, each in its own way.
 *
 * @param {Object} entry
 * @param {string} entry.icon  character or glyph shown on the button
 * @param {string} entry.title tooltip
 * @param {string=} entry.hash location hash to navigate to
 * @param {Function=} entry.click called instead of setting the hash
 */
rl.addNavEntry = entry => {
	if (entry && entry.icon) {
		pluginNavEntries.push({
			icon: entry.icon,
			title: entry.title || '',
			click: entry.click || (() => { hasher.setHash(entry.hash || ''); })
		});
	}
};

/**
 * @param {Function} SettingsViewModelClass
 * @param {string} labelName
 * @param {string} template
 * @param {string} route
 */
rl.addSettingsViewModel = (SettingsViewModelClass, template, labelName, route) => {
	USER_VIEW_MODELS_HOOKS.push([SettingsViewModelClass, template, labelName, route]);
};

/**
 * @param {Function} SettingsViewModelClass
 * @param {string} labelName
 * @param {string} template
 * @param {string} route
 */
rl.addSettingsViewModelForAdmin = (SettingsViewModelClass, template, labelName, route) => {
	ADMIN_VIEW_MODELS_HOOKS.push([SettingsViewModelClass, template, labelName, route]);
};

/**
 * @param {boolean} admin
 */
export function runSettingsViewModelHooks(admin) {
	(admin ? ADMIN_VIEW_MODELS_HOOKS : USER_VIEW_MODELS_HOOKS).forEach(view =>
		settingsAddViewModel(...view)
	);
}

/**
 * @param {string} pluginSection
 * @param {string} name
 * @returns {?}
 */
rl.pluginSettingsGet = (pluginSection, name) =>
	SettingsGet('Plugins')?.[pluginSection]?.[name];

rl.pluginPopupView = AbstractViewPopup;
