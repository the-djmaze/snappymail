(rl => {
	if (rl) {
		addEventListener('rl-view-model', e => {
			if ('SettingsContacts' === e.detail.viewModelTemplateID) {
				const container = e.detail.viewModelDom.querySelector('.form-horizontal');
				if (!container) {
					return;
				}

				rl.pluginRemoteRequest((iError, oData) => {
					if (iError) {
						return;
					}

					const mainDivElement = Element.fromHTML('<div class="control-group">'
						+ '<label>' + rl.i18n("NEXTCLOUD/ADDRESS_BOOK") + '</label>'
						+ '</div>');

					const selectElement = Element.fromHTML('<select></select>');

					const addressbooks = JSON.parse(oData.Result.addressbooks);
					addressbooks.forEach(addressbook => {
						if (addressbook.selected) {
							selectElement.append(Element.fromHTML('<option value="' + addressbook.uri + '" selected="selected">' + addressbook.name + '</option>'));
						} else {
							selectElement.append(Element.fromHTML('<option value="' + addressbook.uri + '">' + addressbook.name + '</option>'));
						}
					});

					selectElement.onchange = function() {
						rl.pluginRemoteRequest(() => { }, 'NextcloudUpdateAddressBook', {
							uri: selectElement.value
						});
					}

					mainDivElement.append(selectElement);
					container.append(mainDivElement);
				}, "NextcloudGetAddressBooks");
			}
		});
	}
})(window.rl);
