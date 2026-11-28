(rl => {
	if (rl) {
		addEventListener('rl-view-model', e => {
			if ('MailFolderList' === e.detail.viewModelTemplateID) {
				const container = e.detail.viewModelDom.querySelector('.buttonContacts');
				if (container) {
					container.remove();
				}
			}
		});


		addEventListener('rl-view-model', e => {
			if ('SystemDropDown' === e.detail.viewModelTemplateID) {
				const container = e.detail.viewModelDom.querySelector('.dropdown-menu');
				if (!container) {
					return;
				}

				for (i = 0; i < container.children.length; i++) {
					const element = container.children[i];
					const attr = element.getAttribute("data-bind");
					if (attr && attr.includes("visible: allowContacts")) {
						element.remove();
						break;
					}
				}
			}
		});

		addEventListener('rl-view-model', e => {
			if ('PopupsCompose' === e.detail.viewModelTemplateID) {
				const container = e.detail.viewModelDom.querySelector('.pull-right');
				if(!container) {
					return;
				}

				for (i = 0; i < container.children.length; i++) {
					const element = container.children[i];
					const attr = element.getAttribute("data-bind");
					if (attr && attr.includes("visible: allowContacts")) {
						element.remove();
						break;
					}
				}
			}
		});
	}
})(window.rl);
