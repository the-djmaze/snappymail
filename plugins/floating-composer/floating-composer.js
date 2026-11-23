(rl => {
	if (!rl) {
		return;
	}

	const
		// Small screens keep the normal full size composer
		desktop = matchMedia('(min-width: 1000px) and (min-height: 500px)'),
		el = (tag, className, text) => {
			const node = document.createElement(tag);
			node.className = className;
			node.textContent = text || '';
			return node;
		};

	// SnappyMail closes every popup when the route changes (a mail is opened,
	// another folder or page is selected). This event is dispatched right
	// before it does that, in the same tick.
	let routing = false;
	addEventListener('sm-show-screen', () => {
		routing = true;
		setTimeout(() => routing = false, 0);
	});

	addEventListener('rl-view-model', event => {
		const vm = event.detail;
		if ('PopupsCompose' !== vm?.viewModelTemplateID) {
			return;
		}

		const
			dom = vm.viewModelDom,
			showModal = dom.showModal,
			tryToClose = vm.tryToClose,
			beforeShow = vm.beforeShow,
			onShow = vm.onShow,

			bar = el('div', 'fc-titlebar'),
			title = el('span', 'fc-title'),
			btnCollapse = el('a', 'fc-btn fc-collapse'),
			btnSize = el('a', 'fc-btn fc-size'),

			updateTitle = () =>
				title.textContent = vm.subject?.() || rl.i18n('FOLDER_LIST/BUTTON_NEW_MESSAGE'),

			setFloating = value => {
				floating = value;
				dom.classList.toggle('fc-floating', value);
				dom.classList.remove('fc-collapsed');
				btnSize.textContent = value ? '⤢' : '⤡';
			},

			open = () => floating ? dom.show() : showModal.call(dom);

		let floating = false,
			wasOpen = false;

		bar.append(title, btnCollapse, btnSize);
		dom.prepend(bar);
		vm.subject?.subscribe(updateTitle);

		// Opened without showModal() the dialog is not modal:
		// no backdrop, and the page behind it is not made inert.
		dom.showModal = () => {
			// dom.show is missing in the browsers that get SnappyMail's dialog polyfill
			const enabled = desktop.matches && !!dom.show;
			dom.classList.toggle('fc-enabled', enabled);
			setFloating(enabled);
			btnCollapse.title = rl.i18n('COMPOSE/BUTTON_MINIMIZE');
			updateTitle();
			open();
		};

		// Keep the floating composer open when the user browses the mailbox.
		// Escape and the buttons still close it.
		vm.tryToClose = () => (routing && floating && vm.modalVisible()) ? false : tryToClose();

		// "New message", reply, forward or a mailto: link while the composer is
		// already open: it could not happen with the modal window, and it would
		// replace what is being written. Flag the composer as "in edit" so that
		// SnappyMail merges the recipients or asks before it discards the text.
		vm.beforeShow = (...args) => {
			wasOpen = vm.modalVisible();
			return beforeShow?.apply(vm, args);
		};
		vm.onShow = (...args) => {
			if (wasOpen) {
				dom.classList.remove('fc-collapsed');
				vm.isEmptyForm() || vm.constructor.inEdit?.(true);
			}
			return onShow.apply(vm, args);
		};

		bar.addEventListener('click', event => {
			if (event.target === btnSize) {
				// Switch between the small window and the normal modal one
				dom.close();
				setFloating(!floating);
				open();
			} else if (floating) {
				dom.classList.toggle('fc-collapsed');
			}
		});
	});

})(window.rl);
