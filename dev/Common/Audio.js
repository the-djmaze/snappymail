import * as Links from 'Common/Links';
import { doc, SettingsGet, fireEvent, addEventsListener } from 'Common/Globals';
import { addObservablesTo } from 'External/ko';

let notificator = null,

	audioCtx = window.AudioContext || window.webkitAudioContext,

	createNewObject = () => {
		const player = new Audio;
		if (player.canPlayType && player.pause && player.play) {
			player.preload = 'none';
			player.loop = false;
			player.autoplay = false;
			player.muted = false;
			return player;
		}
		return null;
	},

	player = createNewObject(),

	canPlay = type => !!player?.canPlayType(type).replace('no', ''),

	play = (url, name) => {
		if (player) {
			player.src = url;
			player.play()
			.then(() => {
				fireEvent('audio.start', (name || '').trim().replace(/\.([a-z0-9]{3})$/, '') || 'audio');
			}).catch(e => {
				console.error(e);
			});
		}
	},

	// The AudioContext is not allowed to start.
	// It must be resumed (or created) after a user gesture on the page. https://goo.gl/7K7WLu
	// Setup listeners to attempt an unlock
	unlockEvents = [
		'click','dblclick',
		'contextmenu',
		'auxclick',
		'mousedown','mouseup',
		'pointerup',
		'touchstart','touchend',
		'keydown','keyup'
	],
	unlock = () => {
		console.log('AudioContext ' + audioCtx.state);
		if ('running' !== audioCtx.state) {
			audioCtx.resume();
			unlockEvents.forEach(type => doc.removeEventListener(type, unlock, true));
		}
	};

if (audioCtx) {
	audioCtx = new audioCtx;
	// 'running' === audioCtx.state
	audioCtx.onstatechange = unlock;
	// 'suspended' === audioCtx.state
	unlockEvents.forEach(type => doc.addEventListener(type, unlock, true));
} else {
	console.warn('AudioContext unsupported');
}

/**
 * Browsers can't play without user interaction
 */

export const SMAudio = new class {
	constructor() {
		this.supported = !!player;
		this.supportedMp3 = canPlay('audio/mpeg;');
		this.supportedWav = canPlay('audio/wav; codecs="1"');
		this.supportedOgg = canPlay('audio/ogg; codecs="vorbis"');
		if (player) {
			const stopFn = () => this.pause();
			addEventsListener(player, ['ended','error'], stopFn);
			addEventListener('audio.api.stop', stopFn);
		}

		addObservablesTo(this, {
			notifications: false
		});
	}

	paused() {
		return !player || player.paused;
	}

	stop() {
		this.pause();
	}

	pause() {
		player?.pause();
		fireEvent('audio.stop');
	}

	playMp3(url, name) {
		this.supportedMp3 && play(url, name);
	}

	playOgg(url, name) {
		this.supportedOgg && play(url, name);
	}

	playWav(url, name) {
		this.supportedWav && play(url, name);
	}

	/**
	 * Used with SoundNotification setting
	 */
	playNotification(force) {
		if (force || this.notifications()) {
			if ('running' == audioCtx.state && (this.supportedMp3 || this.supportedOgg)) {
				if (notificator) {
					notificator.stop();
				}
//				SettingsGet('NotificationSound').startsWith('custom@')
				let url = Links.staticLink('sounds/'
					+ SettingsGet('NotificationSound')
					+ (this.supportedOgg ? '.mp3' : '.ogg'));
				fetch(url)
				.then(response => response.arrayBuffer())
				.then(array => audioCtx.decodeAudioData(array))
				.then(buffer => {
					notificator = audioCtx.createBufferSource();
					notificator.buffer = buffer;
					notificator.connect(audioCtx.destination);
					notificator.start();
				});
			} else {
				console.log('No audio: ' + audioCtx.state);
			}
		}
	}
};
