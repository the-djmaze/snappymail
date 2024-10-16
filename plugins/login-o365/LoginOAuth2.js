(rl => {
	const client_id = rl.pluginSettingsGet('login-o365', 'client_id'),
		// https://learn.microsoft.com/en-us/entra/identity-platform/reply-url#query-parameter-support-in-redirect-uris
		query = rl.pluginSettingsGet('login-o365', 'personal') ? '' : '?',
		tenant = rl.pluginSettingsGet('login-o365', 'tenant'),
		login = () => {
			document.location = 'https://login.microsoftonline.com/'+tenant+'/oauth2/v2.0/authorize?' + (new URLSearchParams({
				response_type: 'code',
				client_id: client_id,
				redirect_uri: document.location.href.replace(/\/$/, '') + '/' + query + 'LoginO365',
				scope: [
					// Associate personal info
					'openid',
					'offline_access',
					'email',
					'profile',
					// Access IMAP and SMTP through OAUTH
					'https://graph.microsoft.com/IMAP.AccessAsUser.All',
//					'https://graph.microsoft.com/Mail.ReadWrite'
					'https://graph.microsoft.com/Mail.Send'
/*					// Legacy:
					'https://outlook.office.com/SMTP.Send',
					'https://outlook.office.com/IMAP.AccessAsUser.All'
*/
				].join(' '),
				state: 'o365', // + rl.settings.app('token') + localStorage.getItem('smctoken')
				// Force authorize screen, so we always get a refresh_token
				access_type: 'offline',
				prompt: 'consent'
			}));
		};

	if (client_id) {
		addEventListener('sm-user-login', e => {
			if (event.detail.get('Email').includes('@hotmail.com')) {
				e.preventDefault();
				login();
			}
		});

		addEventListener('rl-view-model', e => {
			if ('Login' === e.detail.viewModelTemplateID) {
				const
					container = e.detail.viewModelDom.querySelector('#plugin-Login-BottomControlGroup'),
					btn = Element.fromHTML('<button type="button">Outlook</button>'),
					div = Element.fromHTML('<div class="controls"></div>');
				btn.onclick = login;
				div.append(btn);
				container && container.append(div);
			}
		});
	}

})(window.rl);
