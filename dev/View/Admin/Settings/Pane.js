import Remote from 'Remote/Admin/Fetch';

import { AbstractViewRight } from 'Knoin/AbstractViews';

import { toggleLeftPanel } from 'Common/Globals';

import { ThemeStore, setLightMode, setDarkMode, setSystemMode } from 'Stores/Theme';

export class PaneSettingsAdminView extends AbstractViewRight {
	constructor() {
		super('AdminPane');
		this.toggleLeftPanel = toggleLeftPanel;
		this.hasColorSchemeMode = ThemeStore.hasColorSchemeMode;
		this.colorSchemeMode = ThemeStore.colorSchemeMode;
	}

	logoutClick() {
		Remote.request('AdminLogout', () => rl.logoutReload());
	}

	lightModeClick() {
		setLightMode();
	}

	darkModeClick() {
		setDarkMode();
	}

	systemModeClick() {
		setSystemMode();
	}
}
