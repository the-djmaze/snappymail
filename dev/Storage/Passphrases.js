import { AskPopupView } from 'View/Popup/Ask';
import { SettingsUserStore } from 'Stores/User/Settings';

export const Passphrases = new WeakMap();

Passphrases.ask = async (key, sAskDesc, btnText) =>
	Passphrases.has(key)
		? {password:Passphrases.handle(key)/*, remember:false*/}
		: await AskPopupView.password(sAskDesc, btnText, 5);

const timeouts = {};
// get/set accessor to control deletion after N minutes of inactivity
Passphrases.handle = (key, pass) => {
	const timeout = SettingsUserStore.keyPassForget();
	if (timeout && !timeouts[key]) {
		timeouts[key] = (()=>Passphrases.delete(key)).debounce(timeout * 60 * 1000);
	}
	pass && Passphrases.set(key, pass);
	timeout && timeouts[key]();
	return Passphrases.get(key);
};
